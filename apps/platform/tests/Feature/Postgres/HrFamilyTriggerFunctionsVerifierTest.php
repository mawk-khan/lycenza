<?php

namespace Tests\Feature\Postgres;

use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR.R1-C28 (WO-13, C22-N1, C28-N2, C26-N1): `platform:verify-database` pins every HR-family trigger to its function
 * through `tgfoid`, each function's mode, owner, `search_path` and EXECUTE, and the runtime role's memberships.
 *
 * Every schema break is committed through the owner connection on the isolated test database and restored in
 * `finally`, then the check passes again. Roles are cluster-wide, so the membership probes run inside one
 * uncommitted owner transaction that no other session or database ever sees, and are rolled back.
 */
class HrFamilyTriggerFunctionsVerifierTest extends TestCase
{
    private function check(string $code): CheckResult
    {
        return collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')[$code];
    }

    #[Test]
    public function the_verifier_passes_on_the_real_schema(): void
    {
        $this->assertSame(CheckResult::PASS, $this->check('hr_family_trigger_functions')->status, $this->check('hr_family_trigger_functions')->note);
        $this->assertSame(CheckResult::PASS, $this->check('runtime_role_memberships_none')->status);
        $this->assertSame(109, array_sum(array_map('count', DatabaseRoleVerifier::HR_FAMILY_TRIGGERS)), 'the 103 triggers of the HR family as of HR.R1-C28, the 5 of HR.R1-C29 (C14-N5) and the 1 of HR.R1-C35 WO-20 (HRC-4)');
        $this->assertSame([], array_values(array_diff(array_keys(DatabaseRoleVerifier::HR_FAMILY_TRIGGERS), array_keys(DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES))), 'triggers are pinned on family tables only');
    }

    #[Test]
    public function each_trigger_and_function_break_fails_the_check_and_names_it(): void
    {
        $admin = DB::connection('pgsql_admin');
        $def = fn (string $trigger) => $admin->selectOne('select pg_get_triggerdef(oid) as d from pg_trigger where tgname = ?', [$trigger])->d;
        $owner = (string) $admin->selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'public.migrations'::regclass")->o;
        $freeze = $def('trg_leave_types_freeze');
        $restoreFreeze = "DROP TRIGGER IF EXISTS trg_leave_types_freeze ON leave_types; {$freeze}";
        $invokerPath = 'ALTER FUNCTION leave_types_freeze() SET search_path = pg_catalog, public, pg_temp';

        $regressions = [
            // An unexpected SECURITY DEFINER on an ordinary guard (C22-N1).
            ['ALTER FUNCTION leave_types_freeze() SECURITY DEFINER', 'ALTER FUNCTION leave_types_freeze() SECURITY INVOKER', 'trg_leave_types_freeze:mode'],
            // An approved DEFINER turned INVOKER.
            ['ALTER FUNCTION staff_attendance_locked_corrections_apply() SECURITY INVOKER', 'ALTER FUNCTION staff_attendance_locked_corrections_apply() SECURITY DEFINER', 'trg_staff_attendance_locked_corrections_apply:mode'],
            // Re-pointed to another existing function: the old one still exists.
            ['DROP TRIGGER trg_leave_types_freeze ON leave_types; '.str_replace('EXECUTE FUNCTION leave_types_freeze()', 'EXECUTE FUNCTION leave_years_guard()', $freeze), $restoreFreeze, 'trg_leave_types_freeze:binding'],
            // Same function, other timing.
            ['DROP TRIGGER trg_leave_types_freeze ON leave_types; '.str_replace('BEFORE UPDATE', 'AFTER UPDATE', $freeze), $restoreFreeze, 'trg_leave_types_freeze:definition'],
            // Same function, narrowed to one column, or never firing (final-state review).
            ['DROP TRIGGER trg_leave_types_freeze ON leave_types; '.str_replace('BEFORE UPDATE', 'BEFORE UPDATE OF name', $freeze), $restoreFreeze, 'trg_leave_types_freeze:definition'],
            ['DROP TRIGGER trg_leave_types_freeze ON leave_types; '.str_replace('FOR EACH ROW', 'FOR EACH ROW WHEN (false)', $freeze), $restoreFreeze, 'trg_leave_types_freeze:definition'],
            ['ALTER TABLE leave_types DISABLE TRIGGER trg_leave_types_freeze', 'ALTER TABLE leave_types ENABLE TRIGGER trg_leave_types_freeze', 'trg_leave_types_freeze:disabled'],
            ['DROP TRIGGER trg_leave_types_freeze ON leave_types', $freeze, 'trg_leave_types_freeze:missing'],
            ['CREATE TRIGGER trg_c28_probe BEFORE UPDATE ON leave_types FOR EACH ROW EXECUTE FUNCTION leave_types_freeze()', 'DROP TRIGGER trg_c28_probe ON leave_types', 'leave_types.trg_c28_probe:unexpected'],
            // An overload makes the name ambiguous, so a body hash by name would no longer mean the bound function.
            ['CREATE FUNCTION public.leave_types_freeze(x integer) RETURNS integer LANGUAGE sql AS $f$ SELECT x $f$', 'DROP FUNCTION public.leave_types_freeze(integer)', 'trg_leave_types_freeze:binding'],
            ['ALTER FUNCTION leave_types_freeze() OWNER TO school_os_retention', "ALTER FUNCTION leave_types_freeze() OWNER TO \"{$owner}\"", 'trg_leave_types_freeze:owner'],
            // search_path removed, or the temporary schema first again (C28-N2).
            ['ALTER FUNCTION leave_types_freeze() RESET search_path', $invokerPath, 'trg_leave_types_freeze:search_path'],
            ['ALTER FUNCTION leave_types_freeze() SET search_path = pg_temp, public', $invokerPath, 'trg_leave_types_freeze:search_path'],
            ['ALTER FUNCTION employees_record_account_link() SET search_path = public, pg_temp', 'ALTER FUNCTION employees_record_account_link() SET search_path = pg_catalog, public, pg_temp', 'trg_employees_record_account_link_insert:search_path'],
            // EXECUTE on an approved DEFINER function, to the runtime role or to PUBLIC.
            ['GRANT EXECUTE ON FUNCTION employees_record_account_link() TO school_os_app', 'REVOKE EXECUTE ON FUNCTION employees_record_account_link() FROM school_os_app', 'trg_employees_record_account_link_insert:execute'],
            ['GRANT EXECUTE ON FUNCTION retention_guard_retention_delete() TO PUBLIC', 'REVOKE EXECUTE ON FUNCTION retention_guard_retention_delete() FROM PUBLIC', 'trg_retention_guard_employees:execute'],
        ];

        foreach ($regressions as [$break, $restore, $named]) {
            $admin->unprepared($break);
            try {
                $result = $this->check('hr_family_trigger_functions');
                $this->assertSame(CheckResult::FAIL, $result->status, "detects: {$break}");
                $this->assertStringContainsString($named, $result->note, $break);
            } finally {
                $admin->unprepared($restore);
            }
            $this->assertSame(CheckResult::PASS, $this->check('hr_family_trigger_functions')->status, "after: {$restore}");
        }
        $this->assertSame($freeze, $def('trg_leave_types_freeze'), 'the trigger is restored exactly');
    }

    #[Test]
    public function a_runtime_role_membership_fails_the_check_and_leaves_nothing_behind(): void
    {
        $verifier = app(DatabaseRoleVerifier::class);
        $admin = DB::connection('pgsql_admin');
        $probes = [
            // Direct: the runtime role is granted a role, and could SET ROLE to it.
            ['CREATE ROLE c28_probe_direct NOLOGIN', 'GRANT c28_probe_direct TO school_os_app', 'member of 1'],
            // Indirect: through a role that is itself a member of another.
            ['CREATE ROLE c28_probe_outer NOLOGIN; CREATE ROLE c28_probe_inner NOLOGIN; GRANT c28_probe_outer TO c28_probe_inner', 'GRANT c28_probe_inner TO school_os_app', 'reachable 2'],
            // Reverse: another role gains the runtime role's privileges, by SET alone.
            ['CREATE ROLE c28_probe_member NOLOGIN', 'GRANT school_os_app TO c28_probe_member WITH INHERIT FALSE, SET TRUE', 'members 1'],
        ];

        foreach ($probes as [$create, $grant, $named]) {
            $admin->beginTransaction();
            try {
                $this->assertSame(CheckResult::PASS, $verifier->runtimeRoleMembershipCheck($admin)->status);
                $admin->unprepared($create);
                $admin->unprepared($grant);
                $result = $verifier->runtimeRoleMembershipCheck($admin);
                $this->assertSame(CheckResult::FAIL, $result->status, $grant);
                $this->assertStringContainsString($named, $result->note, $grant);
            } finally {
                $admin->rollBack();
            }
            $this->assertSame(CheckResult::PASS, $verifier->runtimeRoleMembershipCheck($admin)->status, "after rolling back: {$grant}");
            $this->assertSame(0, (int) $admin->selectOne("select count(*) as n from pg_roles where rolname like 'c28\\_probe%'")->n, 'no probe role survives');
        }
        $this->assertSame(CheckResult::PASS, $this->check('runtime_role_memberships_none')->status);
    }

    #[Test]
    public function only_the_schema_owner_may_hold_the_runtime_role_and_only_with_admin(): void
    {
        // PostgreSQL 16: a non-superuser with CREATEROLE that creates a role holds it WITH ADMIN, no SET, no INHERIT,
        // and cannot revoke that grant (managed PostgreSQL, final-state review). Allowed for the schema owner only:
        // ADMIN lets its holder grant itself SET, and any other holder would be a new path (delta review).
        $admin = DB::connection('pgsql_admin');
        $verifier = app(DatabaseRoleVerifier::class);
        $owner = (string) $admin->selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'public.migrations'::regclass")->o;
        foreach ([
            ["GRANT school_os_app TO \"{$owner}\" WITH ADMIN TRUE, INHERIT FALSE, SET FALSE", CheckResult::PASS],
            ['CREATE ROLE c28_probe_creator NOLOGIN; GRANT school_os_app TO c28_probe_creator WITH ADMIN TRUE, INHERIT FALSE, SET FALSE', CheckResult::FAIL],
            ["GRANT school_os_app TO \"{$owner}\" WITH ADMIN TRUE, INHERIT FALSE, SET TRUE", CheckResult::FAIL],
        ] as [$grant, $expected]) {
            $admin->beginTransaction();
            try {
                // An owner that already holds the role (a managed database) is first made not to, inside the transaction.
                $admin->unprepared("REVOKE school_os_app FROM \"{$owner}\"");
                $this->assertSame(CheckResult::PASS, $verifier->runtimeRoleMembershipCheck($admin)->status);
                $admin->unprepared($grant);
                $this->assertSame($expected, $verifier->runtimeRoleMembershipCheck($admin)->status, $grant);
            } finally {
                $admin->rollBack();
            }
        }
        $this->assertSame(CheckResult::PASS, $verifier->runtimeRoleMembershipCheck($admin)->status);
        $this->assertSame(0, (int) $admin->selectOne("select count(*) as n from pg_roles where rolname like 'c28\\_probe%'")->n);
    }
}
