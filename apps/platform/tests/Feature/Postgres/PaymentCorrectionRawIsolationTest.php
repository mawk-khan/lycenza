<?php

namespace Tests\Feature\Postgres;

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (ADR 0031 amendment §3): `payment_corrections` and
 * `payment_correction_lines` at the raw-SQL level, as the runtime role
 * (rule 28): RLS enabled and forced, no context sees nothing, another
 * School sees nothing, no UPDATE or DELETE, composite School foreign keys,
 * and every correction function runs as the caller (never a definer).
 */
class PaymentCorrectionRawIsolationTest extends TestCase
{
    use CreatesReceiptFixtures;

    private const array TABLES = ['payment_corrections', 'payment_correction_lines'];

    /** A School with one recorded reallocation; returns the world and the correction id. */
    private function corrected(): array
    {
        $w = $this->concessionWorld();
        $w['b'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Second charge');
        $w['payment'] = $this->pay($w, '500.00')->paymentId;
        $w['correction'] = app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $w['payment'], kind: PaymentCorrectionData::REALLOCATION, reason: 'Applied to the wrong charge',
            idempotencyKey: (string) Str::uuid(), chargeId: $w['charge']->id, toChargeId: $w['b']->id, amount: Money::of('200.00', 'INR'),
        ), $w['recorder'])['correctionId'];

        return $w;
    }

    private function context(?string $schoolId): void
    {
        if ($schoolId === null) {
            DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

            return;
        }
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function rows(string $table): int
    {
        return (int) DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
    }

    #[Test]
    public function both_tables_force_rls_and_the_runtime_role_cannot_update_or_delete_them(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$table]);
            $this->assertTrue($row->relrowsecurity, $table);
            $this->assertTrue($row->relforcerowsecurity, $table);
            foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertFalse((bool) DB::connection('pgsql_admin')->selectOne('select has_table_privilege(?, ?, ?) as p', ['school_os_app', $table, $privilege])->p, "{$table} {$privilege}");
            }
        }
    }

    #[Test]
    public function no_context_and_another_schools_context_see_nothing(): void
    {
        $a = $this->corrected();
        $b = $this->concessionWorld();

        $this->context($a['school']->id);
        $this->assertSame(1, $this->rows('payment_corrections'));
        $this->assertSame(2, $this->rows('payment_correction_lines'));

        $this->context(null);
        $this->assertSame(0, $this->rows('payment_corrections'), 'Missing tenant context fails closed.');
        $this->assertSame(0, $this->rows('payment_correction_lines'));

        $this->context($b['school']->id);
        $this->assertSame(0, $this->rows('payment_corrections'));
        $this->assertSame(0, $this->rows('payment_correction_lines'));
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select payments_effective_allocated(?::uuid) as n', [$a['b']->id])->n, "Another School's charge reads as nothing through the helper too.");
    }

    #[Test]
    public function posted_corrections_cannot_be_changed_or_removed_by_the_runtime_role(): void
    {
        $a = $this->corrected();
        $this->context($a['school']->id);

        foreach ([
            fn () => DB::connection('pgsql')->update("update payment_corrections set reason = 'rewritten reason' where id = ?", [$a['correction']]),
            fn () => DB::connection('pgsql')->delete('delete from payment_corrections where id = ?', [$a['correction']]),
            fn () => DB::connection('pgsql')->update("update payment_correction_lines set amount = '1.00' where payment_correction_id = ?", [$a['correction']]),
            fn () => DB::connection('pgsql')->delete('delete from payment_correction_lines where payment_correction_id = ?', [$a['correction']]),
        ] as $i => $write) {
            try {
                DB::connection('pgsql')->transaction($write);
                $this->fail("write {$i} succeeded");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode(), "write {$i}: permission denied");
            }
        }
        $this->assertSame('Applied to the wrong charge', DB::connection('pgsql')->selectOne('select reason from payment_corrections where id = ?', [$a['correction']])->reason);
    }

    #[Test]
    public function a_line_can_never_name_another_schools_charge_or_payment(): void
    {
        $a = $this->corrected();
        $b = $this->concessionWorld();
        $this->context($a['school']->id);

        foreach (['charge_id' => $b['charge']->id, 'payment_id' => $this->pay($b, '100.00')->paymentId] as $column => $foreign) {
            $this->context($a['school']->id);
            try {
                DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->insert(
                    'insert into payment_correction_lines (id, school_id, payment_correction_id, payment_id, charge_id, direction, amount, currency, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
                    [(string) Str::uuid(), $a['school']->id, $a['correction'], $column === 'payment_id' ? $foreign : $a['payment'], $column === 'charge_id' ? $foreign : $a['charge']->id, 'apply', '1.00', 'INR'],
                ));
                $this->fail("a line with another School's {$column} was written");
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23503', 'P0001', '23514'], "{$column}: refused by the database ({$e->getMessage()})");
            }
        }
        $this->assertSame(2, $this->rows('payment_correction_lines'));
    }

    #[Test]
    public function every_correction_function_runs_as_the_caller_and_the_helpers_pin_search_path(): void
    {
        $functions = collect(DB::connection('pgsql_admin')->select(
            "select proname, prosecdef, coalesce(array_to_string(proconfig, ','), '') as config from pg_proc
             where pronamespace = 'public'::regnamespace and proname in (
                'payments_effective_allocated', 'payments_effective_payment_allocated',
                'payments_validate_correction_header', 'payments_validate_correction_line',
                'payments_lock_and_validate_charge_allocation', 'payments_lock_and_validate_charge_adjustment',
                'payments_reject_charge_cancellation_with_allocations', 'payments_check_correction_balanced')",
        ))->keyBy('proname');

        $this->assertCount(8, $functions, 'every named function exists');
        foreach ($functions as $name => $function) {
            $this->assertFalse($function->prosecdef, "{$name} must be SECURITY INVOKER (stays under the caller's RLS)");
        }
        foreach (['payments_effective_allocated', 'payments_effective_payment_allocated'] as $helper) {
            $this->assertStringContainsString('search_path=pg_catalog', str_replace(' ', '', $functions[$helper]->config), $helper);
        }
    }
}
