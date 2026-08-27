<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 (CLAUDE.md rule 28, mandatory): proves tenant isolation for
 * `payments`/`payment_allocations`/`payment_provider_events` at the
 * raw-SQL level against real PostgreSQL, under the unprivileged
 * `school_os_app` runtime role -- independent of Eloquent's SchoolScope,
 * mirroring `Tests\Feature\Postgres\FeesRawIsolationTest`'s exact shape.
 * Also proves the cross-module `charges_payment_allocation_guard_trigger`
 * (owned by this checkpoint's migration, attached to Fees' `charges`
 * table) enforces under the real runtime role, not merely inside
 * `ChargeService`.
 */
class PaymentsRawIsolationTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function every_payments_table_has_rls_enabled_and_forced(): void
    {
        foreach (['payments', 'payment_allocations', 'payment_provider_events'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_payment_rows(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from payments')->c;
        $this->assertSame(0, (int) $count, 'payments must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_payment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $settlementB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB, '100.00');
        $result = $this->recordSettlement($schoolB, $settlementB->id, [[$chargeB, '100.00']], '100.00');

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from payments where id = ?', [$result->paymentId]);
        $this->assertCount(0, $rows, "School A must not see School B's payment");
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_payments(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->update('update payments set amount = 999.00 where id = ?', [$result->paymentId]);
    }

    #[Test]
    public function the_runtime_role_cannot_delete_a_recognized_payment(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $result = $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->delete('delete from payments where id = ?', [$result->paymentId]);
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_payment_allocations(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->update("update payment_allocations set amount = 1.00 where charge_id = '{$charge->id}'");
            $this->fail('Expected an UPDATE rejection on payment_allocations.');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->delete("delete from payment_allocations where charge_id = '{$charge->id}'");
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_a_provider_event(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->update('update payment_provider_events set amount = 1.00');
            $this->fail('Expected an UPDATE rejection on payment_provider_events.');
        } catch (QueryException) {
            // expected
        }

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->delete('delete from payment_provider_events');
    }

    #[Test]
    public function the_provider_event_unique_constraint_enforces_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00', providerEventId: 'raw-dup-event');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->insert(
            'insert into payment_provider_events '.
            '(id, school_id, provider, provider_event_id, event_type, provider_payment_reference, amount, currency, occurred_at, received_at, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'test-provider', 'raw-dup-event', 'payment.settled', 'raw-ref', 1.00, 'INR', now(), now(), now(), now())",
            [$school->id],
        );
    }

    #[Test]
    public function the_charges_payment_allocation_guard_trigger_rejects_cancellation_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $this->recordSettlement($school, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);
        DB::connection('pgsql')->update(
            'update charges set cancelled_at = now(), cancellation_journal_entry_id = gen_random_uuid() where id = ?',
            [$charge->id],
        );
    }

    #[Test]
    public function every_payments_owned_trigger_function_is_security_invoker(): void
    {
        $functions = [
            'finance_payments_set_creation_txid',
            'payments_check_payment_fully_allocated',
            'payments_lock_and_validate_charge_allocation',
            'payments_reject_post_commit_allocation_insert',
            'payments_reject_charge_cancellation_with_allocations',
        ];

        foreach ($functions as $function) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select prosecdef from pg_proc where proname = ?',
                [$function],
            );

            $this->assertNotNull($row, "function {$function} must exist");
            $this->assertFalse((bool) $row->prosecdef, "{$function} must be SECURITY INVOKER (prosecdef = false), never SECURITY DEFINER");
        }
    }
}
