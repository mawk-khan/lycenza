<?php

namespace Tests\Feature\Postgres;

use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
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
        'leave_requests', 'leave_request_days', 'leave_decisions', 'leave_year_closes', 'leave_year_close_items', 'leave_year_close_reconciliations',
        // HR.9 (ADR 0065 §28)
        'leave_eligibility_rules', 'leave_eligibility_runs',
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
    public function hr9_eligibility_rules_and_runs_are_isolated_and_bound_to_their_own_school(): void
    {
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();
        $category = $this->createEmployeeCategory($a['school']);
        $policy = app(LeavePolicyService::class)->create($a['school'], $a['type']->id, [
            'name' => 'Ruled', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
        ], $a['policy']->id, $a['admin'], [new LeaveEligibilityCriteria(null, $category->id, null)]);
        app(LeaveEligibilityService::class)->executeRun($a['school'], $a['type']->id, '2026-06-01', $a['admin']);

        $this->context(null);
        foreach (['leave_eligibility_rules', 'leave_eligibility_runs'] as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table}")->c, "{$table}: no context, no rows");
        }
        $this->context($b['school']->id);
        $this->assertSame(0, (int) DB::selectOne('select count(*) as c from leave_eligibility_rules where leave_policy_id = ?', [$policy->id])->c);

        // From School B: a rule on A's version, or B's version naming A's Category, is refused structurally.
        $rule = fn (string $policyId, string $typeId, ?string $categoryId) => $this->refused(fn () => DB::connection('pgsql')->insert(
            'insert into leave_eligibility_rules (id, school_id, leave_policy_id, leave_type_id, employee_category_id, created_by_user_id, created_at) values (?, ?, ?, ?, ?, ?, now())',
            [(string) Str::uuid(), $b['school']->id, $policyId, $typeId, $categoryId, $b['admin']->id],
        ));
        // The guard cannot even see A's version from B (refused before the key check).
        $this->assertMatchesRegularExpression('/leave_eligibility_policy_inactive|leave_eligibility_rules_policy_fk/', $rule($policy->id, $a['type']->id, null));
        // B's own fresh version declaring one rule, naming A's Category: the composite key refuses it.
        $this->assertStringContainsString('leave_eligibility_rules_category_fk', $this->refused(function () use ($b, $category) {
            $version = (string) Str::uuid();
            DB::connection('pgsql')->insert(
                'insert into leave_policies (id, school_id, leave_type_id, name, annual_allocation_units, carry_forward_allowed, status, created_by_user_id, eligibility_rule_count, created_at, updated_at) values (?, ?, ?, ?, 0, false, ?, ?, 1, now(), now())',
                [$version, $b['school']->id, $b['type']->id, 'Raw', 'active', $b['admin']->id],
            );
            DB::connection('pgsql')->insert(
                'insert into leave_eligibility_rules (id, school_id, leave_policy_id, leave_type_id, employee_category_id, created_by_user_id, created_at) values (?, ?, ?, ?, ?, ?, now())',
                [(string) Str::uuid(), $b['school']->id, $version, $b['type']->id, $category->id, $b['admin']->id],
            );
        }));
        $this->assertStringContainsString('row-level security', $this->refused(fn () => DB::connection('pgsql')->insert(
            'insert into leave_eligibility_runs (id, school_id, leave_type_id, as_of, executed_by_user_id, assigned_count, skipped_count, executed_at) values (?, ?, ?, ?, ?, 0, 0, now())',
            [(string) Str::uuid(), $a['school']->id, $a['type']->id, '2026-06-01', $b['admin']->id],
        )), 'a row for another School is refused by the policy');
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

    #[Test]
    public function request_decision_and_day_evidence_is_isolated_by_rls_and_same_school_foreign_keys(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $a = $this->leaveWorld();
        $b = $this->leaveWorld();
        $this->workingWeek($a['school'], $a['admin']);
        $this->allocate($a);
        $request = $this->submitLeave($a, '2026-10-12', '2026-10-12');
        app(LeaveRequestService::class)->approve($a['school'], $request->id, $a['admin']);

        $this->context(null);
        foreach (['leave_requests', 'leave_request_days', 'leave_decisions'] as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table}")->c, "{$table}: no context, no rows");
        }
        $this->context($b['school']->id);
        foreach (['leave_requests', 'leave_request_days', 'leave_decisions'] as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table}")->c, "{$table}: School B sees none of A's rows");
        }
        $this->assertSame(0, DB::update('update leave_requests set status = ? where id = ?', ['cancelled', $request->id]), 'School B cannot touch A\'s request');

        // A School B request naming School A's employment fails the composite foreign key (the trigger cannot even resolve the Employee).
        $this->assertNotSame('', $this->refused(fn () => DB::insert(
            "insert into leave_requests (id, school_id, employment_record_id, employee_id, leave_type_id, starts_on, start_portion, ends_on, end_portion, submitted_units, status, submitted_by_user_id, created_at, updated_at) values (?, ?, ?, ?, ?, '2026-10-13', 'full', '2026-10-13', 'full', 2, 'submitted', ?, now(), now())",
            [(string) Str::uuid7(), $b['school']->id, $a['employment']->id, $a['employment']->employee_id, $b['type']->id, $b['admin']->id],
        )));
        // A School B day row pointing at School A's request: A's request is invisible to the trigger under RLS, and the composite FK refuses it too.
        $this->assertMatchesRegularExpression('/leave_request_evidence_invalid|leave_request_days_request_fk/', $this->refused(fn () => DB::insert(
            "insert into leave_request_days (id, school_id, leave_request_id, leave_date, portion, units, leave_year_id) values (?, ?, ?, '2026-10-12', 'full', 2, ?)",
            [(string) Str::uuid7(), $b['school']->id, $request->id, $b['year']->id],
        )));

        $this->context($a['school']->id);
        $this->assertSame([1, 1, 1], [(int) DB::selectOne('select count(*) as c from leave_requests')->c, (int) DB::selectOne('select count(*) as c from leave_request_days')->c, (int) DB::selectOne('select count(*) as c from leave_decisions')->c]);
        $this->context(null);
    }
}
