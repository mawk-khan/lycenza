<?php

namespace Tests\Feature\Postgres;

use App\Domain\Leave\Application\LeaveLedgerService;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §14, CLAUDE.md rule 28): every Leave table is forced-RLS
 * and same-School by construction. Proven at the raw-SQL layer with the
 * runtime role (`school_os_app`), not only through Eloquent scopes.
 */
class LeaveRlsIsolationTest extends TestCase
{
    use CreatesLeaveFixtures;

    private const TABLES = [
        'leave_settings', 'leave_year_start_changes', 'leave_years', 'leave_types', 'leave_policies', 'leave_policy_assignments',
        'staff_working_weekdays', 'staff_holidays', 'leave_allocation_runs', 'leave_ledger_entries',
    ];

    private function context(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function refused(callable $statement): string
    {
        try {
            DB::connection('pgsql')->transaction($statement);

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    #[Test]
    public function every_leave_table_has_forced_rls_and_the_runtime_role_cannot_bypass_it(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        foreach (self::TABLES as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertTrue((bool) $row->relrowsecurity && (bool) $row->relforcerowsecurity, "{$table}: forced RLS");
        }
    }

    #[Test]
    public function raw_sql_sees_only_the_context_schools_leave_rows_and_none_without_context(): void
    {
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();
        app(LeaveLedgerService::class)->allocate($a['school'], $a['employment']->id, $a['type']->id, $a['year']->id, 4, $a['admin']);

        $this->context(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table}")->c, "{$table}: no context, no rows");
        }

        $this->context($b['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table} where school_id = ?", [$a['school']->id])->c, "{$table}: School B sees none of A's rows");
        }
        $this->assertSame(0, DB::update('update leave_types set name = ? where school_id = ?', ['Hijacked', $a['school']->id]), 'a cross-School update touches nothing');

        $this->context($a['school']->id);
        $this->assertSame(1, (int) DB::selectOne('select count(*) as c from leave_ledger_entries')->c);
        $this->context(null);
    }

    #[Test]
    public function a_cross_school_reference_or_a_foreign_school_id_is_refused_by_the_database(): void
    {
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();

        // In School B's context, a row naming School A is refused by the RLS write check.
        $this->context($b['school']->id);
        $this->assertNotSame('', $this->refused(fn () => DB::insert(
            'insert into leave_types (id, school_id, code, name, is_paid, tracks_balance, allows_half_day, status, created_at, updated_at) values (?, ?, ?, ?, true, true, true, ?, now(), now())',
            [(string) Str::uuid7(), $a['school']->id, 'ZZ', 'Foreign', 'active'],
        )));

        // A School B row pointing at School A's employment fails the composite foreign key.
        $message = $this->refused(fn () => DB::insert(
            'insert into leave_policy_assignments (id, school_id, employment_record_id, leave_policy_id, leave_type_id, effective_from, created_by_user_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, now(), now())',
            [(string) Str::uuid7(), $b['school']->id, $a['employment']->id, $b['policy']->id, $b['type']->id, '2027-01-01', $b['admin']->id],
        ));
        $this->assertStringContainsString('leave_policy_assignments_employment_fk', $message);

        // A ledger row of School B cannot point at School A's leave year.
        $message = $this->refused(fn () => DB::insert(
            'insert into leave_ledger_entries (id, school_id, employment_record_id, leave_type_id, tracks_balance, leave_year_id, kind, units, actor_user_id, created_at) values (?, ?, ?, ?, true, ?, ?, 2, ?, now())',
            [(string) Str::uuid7(), $b['school']->id, $b['employment']->id, $b['type']->id, $a['year']->id, 'allocation', $b['admin']->id],
        ));
        $this->assertStringContainsString('leave_ledger_entries_year_fk', $message);
        $this->context(null);
    }
}
