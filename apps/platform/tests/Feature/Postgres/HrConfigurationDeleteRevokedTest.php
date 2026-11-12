<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\EmployeeNumberAllocator;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsRuntimeDeleteRevoked;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C26 (WO-11, C12-N2, R1-01 point 4, `docs/hr/HR-R1-C25-R1-01-DECISION.md` §9): the runtime role
 * holds no DELETE on the four HR configuration tables.
 *
 * - The refusal is the privilege, not RLS or a foreign key: the row belongs to the School in context and
 *   nothing references it, and the error is "permission denied".
 * - The permitted writes stay: the same role still updates the row.
 * - `platform:verify-database` fails when DELETE comes back, granted directly or to PUBLIC. Every break is
 *   committed through the owner connection on the isolated test database and restored in `finally`.
 */
class HrConfigurationDeleteRevokedTest extends TestCase
{
    use AssertsRuntimeDeleteRevoked, CreatesTenancyFixtures;

    private const TABLES = ['hr_departments', 'positions', 'employee_categories', 'hr_employee_number_counters'];

    /** @return array<string, string> table => the id of one row of the School */
    private function oneRowEach(School $school): array
    {
        app(TenantContext::class)->withSchool($school, fn () => app(EmployeeNumberAllocator::class)->allocate($school));

        return [
            'hr_departments' => (string) $this->createDepartment($school)->id,
            'positions' => (string) $this->createPosition($school)->id,
            'employee_categories' => (string) $this->createEmployeeCategory($school)->id,
            'hr_employee_number_counters' => (string) app(TenantContext::class)->withSchool(
                $school,
                fn () => DB::table('hr_employee_number_counters')->where('school_id', $school->id)->value('id'),
            ),
        ];
    }

    private function checkStatus(): string
    {
        return collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')['runtime_destructive_privileges_restricted']->status;
    }

    #[Test]
    public function the_runtime_role_cannot_delete_its_own_schools_configuration_and_still_updates_it(): void
    {
        $this->assertSame('school_os_app', DB::connection('pgsql')->selectOne('select current_user as u')->u);
        $school = $this->createSchool();
        $other = $this->createSchool();
        $rows = $this->oneRowEach($school);
        $otherRows = $this->oneRowEach($other);
        $this->assertSame(self::TABLES, array_keys($rows));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
        foreach ($rows as $table => $id) {
            $this->assertSame(1, DB::connection('pgsql')->selectOne("select count(*) as n from {$table} where id = ?", [$id])->n, "{$table}: the row is visible in its School");

            $this->assertRuntimeDeleteRevoked("delete from {$table} where id = ?", [$id]);

            $this->assertSame(1, DB::connection('pgsql')->selectOne("select count(*) as n from {$table} where id = ?", [$id])->n, "{$table}: the row survives");
            $this->assertSame(1, DB::connection('pgsql')->update("update {$table} set updated_at = now() where id = ?", [$id]), "{$table}: UPDATE stays");
            $this->assertSame(0, DB::connection('pgsql')->update("update {$table} set updated_at = now() where id = ?", [$otherRows[$table]]), "{$table}: another School's row stays out of reach");
        }
    }

    #[Test]
    public function the_verifier_passes_on_the_real_schema(): void
    {
        $this->assertSame(CheckResult::PASS, $this->checkStatus());
        $this->assertSame(self::TABLES, array_values(array_intersect(DatabaseRoleVerifier::NO_RUNTIME_DELETE, self::TABLES)));
    }

    #[Test]
    public function a_returning_delete_grant_fails_the_verifier(): void
    {
        $admin = DB::connection('pgsql_admin');
        $regressions = [];
        foreach (self::TABLES as $table) {
            $regressions[] = ["GRANT DELETE ON {$table} TO school_os_app", "REVOKE DELETE ON {$table} FROM school_os_app"];
        }
        // An effective privilege, whatever its source: PUBLIC reaches every role.
        $regressions[] = ['GRANT DELETE ON hr_departments TO PUBLIC', 'REVOKE DELETE ON hr_departments FROM PUBLIC'];

        foreach ($regressions as [$break, $restore]) {
            $admin->unprepared($break);
            try {
                $this->assertSame(CheckResult::FAIL, $this->checkStatus(), "detects: {$break}");
            } finally {
                $admin->unprepared($restore);
            }
            $this->assertSame(CheckResult::PASS, $this->checkStatus(), "after: {$restore}");
        }
    }

    #[Test]
    public function the_family_allowlist_fails_on_any_privilege_drift(): void
    {
        // HR.R1-C26 (review M3): the allowlist sees what the denylist never listed, in both directions.
        $status = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code')['hr_family_runtime_privileges'];
        $this->assertSame(CheckResult::PASS, $status()->status);

        $admin = DB::connection('pgsql_admin');
        $regressions = [
            // A revoke made in a migration but never pinned by the denylist (Leave configuration).
            ['GRANT DELETE ON leave_types TO school_os_app', 'REVOKE DELETE ON leave_types FROM school_os_app', 'leave_types:SIUD'],
            // Append-only history gaining UPDATE.
            ['GRANT UPDATE ON leave_ledger_entries TO school_os_app', 'REVOKE UPDATE ON leave_ledger_entries FROM school_os_app', 'leave_ledger_entries:SIU'],
            ['GRANT TRUNCATE ON employees TO school_os_app', 'REVOKE TRUNCATE ON employees FROM school_os_app', 'employees:SIUT'],
            // The link history gaining a write.
            ['GRANT INSERT ON employee_account_links TO school_os_app', 'REVOKE INSERT ON employee_account_links FROM school_os_app', 'employee_account_links:SI'],
            // A single column is enough (review LOW-1), and so is REFERENCES.
            ['GRANT UPDATE (voided_at) ON employee_account_links TO school_os_app', 'REVOKE UPDATE (voided_at) ON employee_account_links FROM school_os_app', 'employee_account_links:SU'],
            ['GRANT REFERENCES ON employees TO school_os_app', 'REVOKE REFERENCES ON employees FROM school_os_app', 'employees:SIUR'],
            // Losing a privilege a named operation needs is drift too.
            ['REVOKE UPDATE ON staff_attendance_records FROM school_os_app', 'GRANT UPDATE ON staff_attendance_records TO school_os_app', 'staff_attendance_records:SI'],
        ];

        foreach ($regressions as [$break, $restore, $detail]) {
            $admin->unprepared($break);
            try {
                $result = $status();
                $this->assertSame(CheckResult::FAIL, $result->status, "detects: {$break}");
                $this->assertStringContainsString($detail, $result->note, $break);
            } finally {
                $admin->unprepared($restore);
            }
            $this->assertSame(CheckResult::PASS, $status()->status, "after: {$restore}");
        }
    }
}
