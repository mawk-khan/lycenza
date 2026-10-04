<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * E21-RH.1: the runtime role can no longer rewrite or destroy posted LWF
 * annual charges directly. Proven on the real `school_os_app` connection,
 * against a real posted payroll run with a statutory LWF charge. The only
 * sanctioned removal stays the owner-executed payroll retention function
 * (PayrollEvidenceRetentionTest), which this change does not touch.
 */
class PayrollLwfRuntimePrivilegeTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    /** The database's refusal message for $statement, run as the runtime role in a savepoint ('' when it succeeds). */
    private function refusal(School $school, callable $statement): string
    {
        try {
            $this->inSchool($school, fn () => DB::transaction($statement));

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    #[Test]
    public function the_runtime_role_cannot_delete_or_update_a_posted_lwf_charge_and_the_row_survives(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->statutorySetup($school, $setup);
        $this->paidEmployee($school, $setup);
        $this->postedRun($school, '2015-08-01', '2015-08-31', statutory: true);
        $before = $this->inSchool($school, fn () => (array) DB::table('payroll_lwf_annual_charges')->where('school_id', $school->id)->first());
        $this->assertNotSame([], $before, 'the fixture posted one LWF charge');

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');
        $this->assertStringContainsString('permission denied for table payroll_lwf_annual_charges', $this->refusal($school, fn () => DB::table('payroll_lwf_annual_charges')->where('school_id', $school->id)->delete()));
        $this->assertStringContainsString('permission denied for table payroll_lwf_annual_charges', $this->refusal($school, fn () => DB::table('payroll_lwf_annual_charges')->where('school_id', $school->id)->update(['annual_cycle_year' => 2000])));
        $this->assertStringContainsString('permission denied for table payroll_lwf_annual_charges', $this->refusal($school, fn () => DB::statement('TRUNCATE payroll_lwf_annual_charges')));

        $this->assertSame($before, $this->inSchool($school, fn () => (array) DB::table('payroll_lwf_annual_charges')->where('id', $before['id'])->first()), 'the charge is unchanged');
        $this->assertSame(1, $this->rowsWhere($school, 'payroll_lwf_annual_charges', 'employment_record_id', (string) $before['employment_record_id']));
    }

    #[Test]
    public function the_runtime_role_keeps_insert_and_select_and_the_owner_keeps_maintenance_access(): void
    {
        $runtime = DB::selectOne("select has_table_privilege('school_os_app', 'payroll_lwf_annual_charges', 'SELECT') s, has_table_privilege('school_os_app', 'payroll_lwf_annual_charges', 'INSERT') i,
            has_table_privilege('school_os_app', 'payroll_lwf_annual_charges', 'UPDATE') u, has_table_privilege('school_os_app', 'payroll_lwf_annual_charges', 'DELETE') d,
            has_table_privilege('school_os_app', 'payroll_lwf_annual_charges', 'TRUNCATE') t");
        $this->assertSame([true, true, false, false, false], [(bool) $runtime->s, (bool) $runtime->i, (bool) $runtime->u, (bool) $runtime->d, (bool) $runtime->t]);

        $owner = DB::selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'public.payroll_lwf_annual_charges'::regclass")->o;
        $this->assertNotSame('school_os_app', $owner);
        $maintenance = DB::selectOne('select has_table_privilege(?, ?, ?) and has_table_privilege(?, ?, ?) as ok', [$owner, 'payroll_lwf_annual_charges', 'DELETE', $owner, 'payroll_lwf_annual_charges', 'UPDATE']);
        $this->assertTrue((bool) $maintenance->ok, 'the owner (the retention definer) keeps its access');
    }

    #[Test]
    public function the_verifier_denies_both_runtime_delete_and_update_on_the_table(): void
    {
        $this->assertContains('payroll_lwf_annual_charges', DatabaseRoleVerifier::NO_RUNTIME_DELETE);
        $this->assertContains('payroll_lwf_annual_charges', DatabaseRoleVerifier::NO_RUNTIME_UPDATE);
        $check = collect(app(DatabaseRoleVerifier::class)->verify())->firstWhere('code', 'runtime_destructive_privileges_restricted');
        $this->assertSame(CheckResult::PASS, $check->status);
    }
}
