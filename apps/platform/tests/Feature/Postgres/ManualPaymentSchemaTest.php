<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 3, rule 28):
 * the manual-payment schema invariants proven at the raw-SQL level under
 * the unprivileged `school_os_app` runtime role -- the source shape, the
 * closed method catalog, the reference format, the idempotency claim,
 * immutability, RLS, and the Payment-journal reversal guard.
 */
class ManualPaymentSchemaTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * A committed manual Payment plus the ids a raw insert needs.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');
        $recorder = $this->createPaymentRecorder($school);
        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', reference: 'R-1');
        $journal = $this->postBalancedJournalEntry($school, $settlement, $revenue, '5.00');

        return compact('school', 'settlement', 'charge', 'recorder', 'result', 'journal');
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $columns
     */
    private function rawInsert(array $world, array $columns): void
    {
        $row = array_merge([
            'id' => (string) Str::uuid7(),
            'school_id' => $world['school']->id,
            'amount' => '5.00',
            'currency' => 'INR',
            'settlement_ledger_account_id' => $world['settlement']->id,
            'journal_entry_id' => $world['journal']->id,
            'settled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $columns);

        $this->setSchool($world['school']->id);
        DB::connection('pgsql')->table('payments')->insert($row);
    }

    private function assertRawInsertRefused(array $world, array $columns, string $constraint): void
    {
        DB::connection('pgsql')->beginTransaction();
        try {
            $this->rawInsert($world, $columns);
            $this->fail("Expected {$constraint} to refuse the insert.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        } finally {
            DB::connection('pgsql')->rollBack();
        }
    }

    #[Test]
    public function a_manual_row_can_never_carry_provider_evidence_and_a_provider_row_never_manual_fields(): void
    {
        $world = $this->world();
        $manual = ['source' => 'manual', 'method' => 'cash', 'recorded_by_user_id' => $world['recorder']->id, 'idempotency_key' => (string) Str::uuid()];

        $this->assertRawInsertRefused($world, [...$manual, 'provider' => 'manual', 'provider_payment_reference' => 'x'], 'payments_source_shape_check');
        $this->assertRawInsertRefused($world, [...$manual, 'recorded_by_user_id' => null], 'payments_source_shape_check');
        $this->assertRawInsertRefused($world, [...$manual, 'idempotency_key' => null], 'payments_source_shape_check');
        $this->assertRawInsertRefused($world, [...$manual, 'method' => null], 'payments_source_shape_check');
        // The default source is 'provider': an insert without provider
        // evidence can never pass as provider-derived.
        $this->assertRawInsertRefused($world, ['source' => 'provider'], 'payments_source_shape_check');
        $this->assertRawInsertRefused($world, [], 'payments_source_shape_check');
        $this->assertRawInsertRefused($world, ['source' => 'offline', 'method' => 'cash'], 'payments_source_check');
    }

    #[Test]
    public function the_method_catalog_is_closed_in_the_database(): void
    {
        $world = $this->world();

        foreach (['card', 'upi', 'wallet', 'online_gateway', 'CASH'] as $method) {
            $this->assertRawInsertRefused($world, ['source' => 'manual', 'method' => $method, 'recorded_by_user_id' => $world['recorder']->id, 'idempotency_key' => (string) Str::uuid()], 'payments_method_check');
        }
    }

    #[Test]
    public function the_reference_format_is_enforced_in_the_database(): void
    {
        $world = $this->world();

        // (A 65-character value is refused earlier by varchar(64) itself.)
        foreach ([' x', 'x ', 'a;b', '-x', "a\nb", 'a"b'] as $reference) {
            $this->assertRawInsertRefused($world, ['source' => 'manual', 'method' => 'cheque', 'manual_reference' => $reference, 'recorded_by_user_id' => $world['recorder']->id, 'idempotency_key' => (string) Str::uuid()], 'payments_manual_reference_format_check');
        }
    }

    #[Test]
    public function one_idempotency_key_claims_at_most_one_payment_per_school(): void
    {
        $world = $this->world();
        $key = $this->findPayment($world['school'], $world['result']->paymentId)->idempotency_key;

        $this->assertRawInsertRefused($world, ['source' => 'manual', 'method' => 'cash', 'recorded_by_user_id' => $world['recorder']->id, 'idempotency_key' => $key], 'payments_manual_idempotency_unique');
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_a_manual_payment(): void
    {
        $world = $this->world();
        $this->setSchool($world['school']->id);

        foreach ([
            fn () => DB::connection('pgsql')->update('update payments set method = ? where id = ?', ['cheque', $world['result']->paymentId]),
            fn () => DB::connection('pgsql')->update('update payments set settled_at = now() where id = ?', [$world['result']->paymentId]),
            fn () => DB::connection('pgsql')->delete('delete from payments where id = ?', [$world['result']->paymentId]),
            fn () => DB::connection('pgsql')->update('update payment_allocations set amount = 1 where payment_id = ?', [$world['result']->paymentId]),
        ] as $write) {
            DB::connection('pgsql')->beginTransaction();
            try {
                $write();
                $this->fail('A manual Payment must be immutable for the runtime role.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            } finally {
                DB::connection('pgsql')->rollBack();
            }
        }
    }

    #[Test]
    public function school_a_cannot_see_school_bs_manual_payment_or_reference(): void
    {
        $a = $this->world();
        $b = $this->world();

        $this->setSchool($a['school']->id);

        $this->assertCount(0, DB::connection('pgsql')->select('select id from payments where id = ?', [$b['result']->paymentId]));
        $this->assertCount(0, DB::connection('pgsql')->select('select id from payment_allocations where payment_id = ?', [$b['result']->paymentId]));
        // Both Schools used the reference 'R-1'; School A sees only its own.
        $this->assertSame(
            [$a['result']->paymentId],
            array_column(DB::connection('pgsql')->select('select id from payments where manual_reference = ?', ['R-1']), 'id'),
        );
    }

    #[Test]
    public function school_a_cannot_allocate_to_school_bs_charge_at_the_raw_level(): void
    {
        $a = $this->world();
        $b = $this->world();

        DB::connection('pgsql')->beginTransaction();
        try {
            $this->setSchool($a['school']->id);
            DB::connection('pgsql')->table('payment_allocations')->insert([
                'id' => (string) Str::uuid7(),
                'school_id' => $a['school']->id,
                'payment_id' => $a['result']->paymentId,
                'charge_id' => $b['charge']->id,
                'amount' => '1.00',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A cross-School allocation must be refused.');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/payment_allocations_charge_fk|frozen/', $e->getMessage());
        } finally {
            DB::connection('pgsql')->rollBack();
        }
    }

    #[Test]
    public function the_database_refuses_reversing_a_payment_owned_journal_entry(): void
    {
        $world = $this->world();
        $journalEntryId = $this->findPayment($world['school'], $world['result']->paymentId)->journal_entry_id;

        DB::connection('pgsql')->beginTransaction();
        try {
            $this->setSchool($world['school']->id);
            DB::connection('pgsql')->table('journal_entries')->insert([
                'id' => (string) Str::uuid7(),
                'school_id' => $world['school']->id,
                'currency' => 'INR',
                'description' => 'raw reversal attempt',
                'reversal_of_journal_entry_id' => $journalEntryId,
            ]);
            $this->fail('Reversing a Payment-owned journal entry must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal_entries_payment_reversal_guard', $e->getMessage());
            $this->assertSame('23001', $e->getCode());
        } finally {
            DB::connection('pgsql')->rollBack();
        }

        // An ordinary trigger (tgenabled 'O'), not role-conditional: it
        // fires for every role that inserts into journal_entries.
        $trigger = DB::connection('pgsql_admin')->selectOne(
            "select tgenabled from pg_trigger where tgname = 'journal_entries_payment_reversal_guard'"
        );
        $this->assertSame('O', $trigger->tgenabled);
    }
}
