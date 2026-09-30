<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3 (ADR 0062 §14, §15, §22) at the raw PostgreSQL layer: forced RLS,
 * isolation and fail-closed missing context, the separation-of-duties and
 * category CHECKs, the concession lifecycle trigger, adjustment insert
 * validation and immutability, the Payments-owned capacity guard for both
 * adjustments and allocations, the charge-cancel guard, and no DELETE --
 * each proven with SQL that bypasses the Application layer.
 */
class FeeConcessionRlsIsolationTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private const TABLES = ['fee_concessions', 'fee_adjustments'];

    private function setSchool(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function assertRejectedBy(string $fragment, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage(), $message);
        }
    }

    /** A world with one approved targeted concession (200.00) and its adjustment. */
    private function postedWorld(): array
    {
        $w = $this->concessionWorld();
        $w['concession'] = $this->requestTargeted($w, '200.00');
        $this->concessions()->approve($w['school'], $w['concession']->id, $w['checker']);
        $w['adjustment'] = $this->adjustmentsOf($w)->sole();

        return $w;
    }

    private function concessionRow(array $w, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'student_id' => $w['student']->id,
            'academic_year_id' => $w['year']->id, 'category' => 'concession', 'scope' => 'targeted', 'charge_id' => $w['charge']->id,
            'kind' => 'fixed', 'fixed_amount' => '10.00', 'currency' => 'INR', 'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(), 'requested_by_user_id' => $w['maker']->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    /** Posts a genuine Dr expense / Cr receivable entry so a raw adjustment insert has a real journal entry. */
    private function journalEntry(array $w, string $amount): string
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => DB::transaction(fn () => app(LedgerService::class)->post($w['school'], new PostJournalEntryData(
            currency: 'INR', description: 'raw', lines: [
                new JournalLineData($w['expense']->id, JournalSide::Debit, Money::of($amount, 'INR')),
                new JournalLineData($w['receivable']->id, JournalSide::Credit, Money::of($amount, 'INR')),
            ],
        ))->journalEntryId));
    }

    private function adjustmentRow(array $w, string $concessionId, string $amount, array $overrides = []): array
    {
        $journalEntryId = $this->journalEntry($w, $amount);
        // withSchool() restored the (empty) previous context; re-set the raw GUC.
        $this->setSchool($w['school']->id);

        return array_merge([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, 'charge_id' => $w['charge']->id,
            'fee_concession_id' => $concessionId, 'category' => 'concession', 'amount' => $amount, 'currency' => 'INR',
            'debit_ledger_account_id' => $w['expense']->id, 'credit_ledger_account_id' => $w['receivable']->id,
            'journal_entry_id' => $journalEntryId, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    #[Test]
    public function the_new_tables_have_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity, "{$table} must have RLS enabled and forced");
        }
    }

    #[Test]
    public function rows_are_isolated_by_school_and_missing_context_fails_closed(): void
    {
        $a = $this->postedWorld();
        $b = $this->concessionWorld();

        $this->setSchool($a['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }

        $this->setSchool($b['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} of School A must be invisible to School B");
        }
        $this->assertSame(0, DB::table('fee_concessions')->where('id', $a['concession']->id)->update(['updated_at' => now()]));

        $this->setSchool(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty without tenant context");
        }

        app(TenantContext::class)->clearAll();
        $this->assertSame(0, FeeConcession::query()->count());
        $this->assertSame(0, FeeAdjustment::query()->count());
    }

    #[Test]
    public function a_concession_cannot_reference_another_schools_charge_or_student(): void
    {
        $a = $this->concessionWorld();
        $b = $this->concessionWorld();
        $this->setSchool($a['school']->id);

        $this->assertRejectedBy('needs an uncancelled charge', fn () => DB::table('fee_concessions')->insert($this->concessionRow($a, ['charge_id' => $b['charge']->id])), 'Another School\'s charge.');
        $this->assertRejectedBy('fee_concessions_student_fk', fn () => DB::table('fee_concessions')->insert($this->concessionRow($a, [
            'scope' => 'standing', 'charge_id' => null, 'student_id' => $b['student']->id, 'valid_from' => '2026-06-01', 'valid_to' => '2026-12-31',
        ])), 'Another School\'s Student.');
    }

    #[Test]
    public function category_shape_and_separation_of_duties_are_database_checks(): void
    {
        $w = $this->concessionWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('fee_concessions_category_check', fn () => DB::table('fee_concessions')->insert($this->concessionRow($w, ['category' => 'sibling'])), 'Closed categories.');
        $this->assertRejectedBy('fee_concessions_scope_shape_check', fn () => DB::table('fee_concessions')->insert($this->concessionRow($w, ['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00'])), 'Targeted is fixed only.');
        $this->assertRejectedBy('fee_concessions_value_shape_check', fn () => DB::table('fee_concessions')->insert($this->concessionRow($w, [
            'scope' => 'standing', 'charge_id' => null, 'kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '100.01', 'valid_from' => '2026-06-01', 'valid_to' => '2026-12-31',
        ])), 'Percentage at most 100.');
        $this->assertRejectedBy('always requested as pending', fn () => DB::table('fee_concessions')->insert($this->concessionRow($w, [
            'status' => 'approved', 'decided_by_user_id' => $w['checker']->id, 'decided_at' => now(),
        ])), 'Never inserted approved.');

        $row = $this->concessionRow($w);
        DB::table('fee_concessions')->insert($row);
        $this->assertRejectedBy('fee_concessions_sod_check', fn () => DB::table('fee_concessions')->where('id', $row['id'])->update([
            'status' => 'approved', 'decided_by_user_id' => $w['maker']->id, 'decided_at' => now(),
        ]), 'The requester can never be the decider, even by raw SQL.');
    }

    #[Test]
    public function the_lifecycle_and_request_immutability_are_trigger_enforced(): void
    {
        $w = $this->postedWorld();
        $this->setSchool($w['school']->id);
        $id = $w['concession']->id;

        $this->assertRejectedBy('request is immutable', fn () => DB::table('fee_concessions')->where('id', $id)->update(['fixed_amount' => '999.00']), 'Request content never changes.');
        $this->assertRejectedBy('illegal fee concession transition', fn () => DB::table('fee_concessions')->where('id', $id)->update(['status' => 'pending', 'decided_by_user_id' => null, 'decided_at' => null]), 'No un-approval.');
        $this->assertRejectedBy('illegal fee concession transition', fn () => DB::table('fee_concessions')->where('id', $id)->update([
            'status' => 'revoked', 'revoked_by_user_id' => $w['checker']->id, 'revoked_at' => now(),
        ]), 'A targeted concession is never revoked.');
    }

    #[Test]
    public function an_adjustment_must_match_an_approved_concession_and_credit_the_receivable(): void
    {
        $w = $this->concessionWorld();
        $pending = $this->requestTargeted($w, '10.00');
        $other = $this->concessionWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('needs an approved fee concession', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $pending->id, '10.00')), 'A pending concession has no effect.');

        $approved = $this->requestTargeted($w, '10.00');
        $this->inSchool($w['school'], fn () => DB::table('fee_concessions')->where('id', $approved->id)->update(['status' => 'approved', 'decided_by_user_id' => $w['checker']->id, 'decided_at' => now()]));
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('must credit its charge', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $approved->id, '10.00', ['credit_ledger_account_id' => $w['settlement']->id])), 'The credit is the charge receivable.');
        $this->assertRejectedBy('must be an active expense account', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $approved->id, '10.00', ['debit_ledger_account_id' => $w['revenue']->id])), 'The debit is an expense account.');
        $this->assertRejectedBy('must match its charge', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $approved->id, '10.00', ['category' => 'waiver'])), 'The category is the concession\'s.');
        // BEFORE triggers run before CHECKs: an off-catalogue category meets
        // the category match first; fee_adjustments_category_check backs it.
        $this->assertRejectedBy('must match its charge', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $approved->id, '10.00', ['category' => 'rte'])), 'Closed categories.');
        $this->assertRejectedBy('must be an uncancelled charge of the same School', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $approved->id, '10.00', ['charge_id' => $other['charge']->id])), 'Another School\'s charge (invisible under RLS; the composite FK backs it).');
    }

    #[Test]
    public function the_payments_owned_capacity_guard_refuses_a_raw_adjustment_beyond_the_outstanding(): void
    {
        $w = $this->postedWorld();
        $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '700.00']], '700.00');
        $second = $this->requestTargeted($w, '100.00');
        $this->inSchool($w['school'], fn () => DB::table('fee_concessions')->where('id', $second->id)->update(['status' => 'approved', 'decided_by_user_id' => $w['checker']->id, 'decided_at' => now()]));
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('would exceed its outstanding amount', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $second->id, '100.01')), '1000 - 700 paid - 200 adjusted leaves 100.00.');
        DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $second->id, '100.00'));

        $third = $this->requestTargeted($w, '0.01');
        $this->inSchool($w['school'], fn () => DB::table('fee_concessions')->where('id', $third->id)->update(['status' => 'approved', 'decided_by_user_id' => $w['checker']->id, 'decided_at' => now()]));
        $this->setSchool($w['school']->id);
        $this->assertRejectedBy('is fully settled', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $third->id, '0.01')), 'Nothing left.');
    }

    #[Test]
    public function adjustments_are_immutable_except_one_true_cancellation_and_block_charge_cancel(): void
    {
        $w = $this->postedWorld();
        $this->setSchool($w['school']->id);
        $id = $w['adjustment']->id;

        $this->assertRejectedBy('posted fields are immutable', fn () => DB::table('fee_adjustments')->where('id', $id)->update(['amount' => '1.00']), 'Amount is immutable.');
        $this->assertRejectedBy('must reference the reversal of its own journal entry', fn () => DB::table('fee_adjustments')->where('id', $id)->update([
            'cancelled_at' => now(), 'cancellation_journal_entry_id' => $w['charge']->journal_entry_id,
        ]), 'A cancellation must be a true reversal.');
        $this->assertRejectedBy('has active fee adjustments; cancel them first', fn () => DB::table('charges')->where('id', $w['charge']->id)->update([
            'cancelled_at' => now(), 'cancellation_journal_entry_id' => $w['charge']->journal_entry_id,
        ]), 'A charge with a live adjustment cannot be cancelled.');
        $this->assertRejectedBy('fee_adjustments_one_live_per_concession_charge', fn () => DB::table('fee_adjustments')->insert($this->adjustmentRow($w, $w['concession']->id, '1.00')), 'One live adjustment per concession and charge.');
    }

    #[Test]
    public function the_runtime_role_cannot_delete_concessions_or_adjustments(): void
    {
        $w = $this->postedWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_concessions')->where('id', $w['concession']->id)->delete(), 'Concessions are never deleted.');
        $this->assertRejectedBy('permission denied', fn () => DB::table('fee_adjustments')->where('id', $w['adjustment']->id)->delete(), 'Adjustments are never deleted.');
    }
}
