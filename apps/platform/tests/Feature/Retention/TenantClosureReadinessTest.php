<?php

namespace Tests\Feature\Retention;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Retention\TenantClosureReadiness;
use App\Support\Retention\TenantRetentionCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2F (E21-D11): closure readiness is read-only and never reports a
 * School purge-ready while anything retained, unresolved, blocked, held or
 * unclassified remains. Today it is never purge-ready at all: no tenant
 * purge exists or is authorized.
 */
class TenantClosureReadinessTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function report(School $school): array
    {
        return app(TenantClosureReadiness::class)->report($school);
    }

    /** @return array<string, array<string, mixed>> */
    private function byCategory(array $report): array
    {
        return array_column($report['categories'], null, 'category');
    }

    #[Test]
    public function the_catalog_classifies_exactly_the_live_tenant_tables(): void
    {
        $live = array_column(DB::select(
            "SELECT c.table_name FROM information_schema.columns c
               JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
              WHERE c.table_schema = 'public' AND c.column_name = 'school_id' ORDER BY 1",
        ), 'table_name');
        $catalog = array_keys(TenantRetentionCatalog::tables());
        sort($catalog);

        $this->assertSame($live, $catalog, 'a tenant table changed: classify it in TenantRetentionCatalog');
        $this->assertCount(count($catalog), array_unique($catalog), 'each table in exactly one category');
    }

    #[Test]
    public function an_open_school_with_finance_unresolved_and_running_categories_is_never_purge_ready(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $this->createGuardian($school);
        $this->assessCharge($school, $student, $this->createAcademicYear($school), $this->createLedgerAccount($school, ['type' => 'asset']), $this->createLedgerAccount($school, ['type' => 'income']), '100.00');

        $report = $this->report($school);
        $categories = $this->byCategory($report);

        $this->assertFalse($report['purge_ready']);
        foreach (['school_not_closed', 'd8_finance_retention_not_enabled', 'retention_periods_running', 'final_ratification_pending', 'no_tenant_purge_authorized'] as $gate) {
            $this->assertContains($gate, $report['gates']);
        }
        $this->assertSame('retained', $categories['finance_ledger']['outcome'], 'E21.3A2: D8 is an adopted, implemented period');
        $this->assertSame(TenantRetentionCatalog::TENANT_LIFETIME, $categories['finance_period_evidence']['outcome']);
        $this->assertSame('retained', $categories['guardians']['outcome'], 'E21.3C: G1 is implemented; a new unlinked Guardian runs its clock from creation');
        $this->assertNotContains('retention_mechanism_pending', $report['gates'], 'no E21.3D/E row exists in this School');
        $this->assertSame('retained', $categories['student_core']['outcome']);
        $this->assertSame('empty', $categories['hr_evidence']['outcome']);
    }

    #[Test]
    public function an_empty_closed_school_still_waits_for_ratification_and_an_authorized_purge(): void
    {
        $school = $this->createSchool(['status' => 'suspended']);
        DB::table('schools')->where('id', $school->id)->update(['closed_at' => now(), 'closure_reason' => 'ceased_operations']);

        $report = $this->report($school->fresh());

        $this->assertTrue($report['closed']);
        $this->assertFalse($report['purge_ready']);
        $this->assertSame(TenantClosureReadiness::PERMANENT_GATES, $report['gates']);
    }

    #[Test]
    public function a_hold_is_reported_and_an_adopted_category_knows_its_earliest_day(): void
    {
        $school = $this->createSchool();
        config(['retention.hold_school_ids' => [$school->id], 'retention.audit_years' => 7]);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'occurred_at' => '2026-03-15 10:00:00',
            'event_type' => 'test.event', 'metadata' => '{}',
        ]));

        $report = $this->report($school);

        $this->assertTrue($report['held']);
        $this->assertContains('legal_hold', $report['gates']);
        $this->assertSame('2033-03-16', $this->byCategory($report)['audit']['not_before']);
    }

    #[Test]
    public function an_unclassified_tenant_table_fails_closed(): void
    {
        $school = $this->createSchool();
        $admin = DB::connection('pgsql_admin');
        $admin->statement('CREATE TABLE retention_unclassified_probe (id uuid PRIMARY KEY, school_id uuid)');

        try {
            $report = $this->report($school);
            $this->assertContains('unclassified_tables', $report['gates']);
            $this->assertSame(['retention_unclassified_probe'], $report['unclassified']);
        } finally {
            $admin->statement('DROP TABLE IF EXISTS retention_unclassified_probe');
        }
    }

    #[Test]
    public function the_status_command_is_read_only(): void
    {
        $school = $this->createSchool();
        $this->createStudent($school);
        $before = DB::table('students')->count();

        $this->artisan('platform:school-closure-status', ['school' => $school->id])
            ->expectsOutputToContain('purge-ready: NO')
            ->expectsOutputToContain('final_ratification_pending')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('students')->count());
        $this->assertTrue(DB::table('schools')->where('id', $school->id)->exists());
    }

    #[Test]
    public function e21_3b_and_e21_3c_categories_are_retained_and_an_unmarked_guardian_is_unresolved(): void
    {
        // E21.3B + E21.3C implemented every Student-linked, Admissions and
        // Guardian mechanism: those rows are `retained` (periods running).
        // A Guardian without a relationship and without a trustworthy
        // marker is `unresolved` and keeps the School from purge.
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($year, $campus, $grade), ['status' => 'withdrawn', 'starts_on' => $year->starts_on, 'ends_on' => $year->starts_on]);
        $enrollmentId = app(TenantContext::class)->withSchool($school, fn () => DB::table('student_enrollments')->where('student_id', $student->id)->value('id'));
        $applicant = $this->createApplicant($school);
        $this->createAdmissionApplication($applicant, $year, $campus, $grade, ['status' => 'converted', 'converted_student_id' => $student->id, 'converted_student_enrollment_id' => $enrollmentId, 'converted_at' => now()]);
        $this->createAdmissionApplication($this->createApplicant($school), $year, $campus, $grade, ['status' => 'rejected']);
        $this->createLibraryLoan($this->createLibraryCopy($this->createLibraryTitle($school)), $student, ['status' => 'returned', 'checked_in_at' => now()]);
        $consent = fn (string $column, string $id) => app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_domain_consent_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, $column => $id, 'channel' => 'email', 'status' => 'granted',
            'recorded_at' => now(), 'recorded_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $consent('student_id', $student->id);
        $consent('guardian_id', $this->createGuardian($school)->id);

        $report = $this->report($school);
        $categories = $this->byCategory($report);
        foreach (['admissions', 'communication_consent', 'student_operational_modules', 'guardians'] as $category) {
            $this->assertSame('retained', $categories[$category]['outcome'], $category);
        }
        $this->assertNotContains('retention_mechanism_pending', $report['gates']);
        $this->assertNotContains('retention_trigger_unresolved', $report['gates']);

        app(TenantContext::class)->withSchool($school, fn () => Guardian::factory()->create(['school_id' => $school->id, 'no_relationship_since' => null]));
        $report = $this->report($school);
        $this->assertSame('unresolved', $this->byCategory($report)['guardians']['outcome']);
        $this->assertContains('retention_trigger_unresolved', $report['gates']);
        $this->assertFalse($report['purge_ready']);
    }

    #[Test]
    public function every_mechanism_is_implemented_and_no_technical_blocker_remains(): void
    {
        // E21.3E: no category waits for a mechanism checkpoint any more.
        // E21.3F: the D8 x D9 payroll ledger, the last technical blocker, is
        // adopted (payroll evidence expiry, emptied runs released to D8).
        $tables = TenantRetentionCatalog::tables();
        $this->assertSame([], TenantRetentionCatalog::PENDING_ROWS);
        foreach (array_keys(TenantRetentionCatalog::UNRESOLVED_ROWS) as $table) {
            $this->assertArrayHasKey($table, $tables, "{$table} is not a classified tenant table");
            $this->assertSame(TenantRetentionCatalog::ADOPTED, TenantRetentionCatalog::CATEGORIES[$tables[$table]][0], $table);
        }
        foreach (TenantRetentionCatalog::CATEGORIES as $category => [$status]) {
            // HRX.6: Leave and Staff Attendance evidence, the last pending mechanisms, are implemented.
            $this->assertNotSame(TenantRetentionCatalog::MECHANISM_PENDING, $status, "{$category} still waits for a mechanism");
            $this->assertNotSame(TenantRetentionCatalog::TECHNICAL_BLOCKER, $status, "{$category} is still a technical blocker");
        }
        foreach (['leave_evidence', 'staff_attendance_evidence'] as $category) {
            $this->assertStringContainsString('HRX.6, implemented', TenantRetentionCatalog::CATEGORIES[$category][1]);
        }
        foreach (['guardians', 'admissions', 'communication_consent', 'identity_subject_links', 'processing_authorizations', 'student_operational_modules', 'academic_operations', 'communications', 'api_credentials', 'operational_logs', 'payroll_ledger'] as $category) {
            $this->assertSame(TenantRetentionCatalog::ADOPTED, TenantRetentionCatalog::CATEGORIES[$category][0], $category);
        }
        // Tenant lifetime by decision, never age-pruned: memberships, membership preferences, Inventory, notifications, academic configuration, payroll periods.
        foreach (['identity', 'communication_configuration', 'inventory_history', 'notifications', 'academic_configuration', 'payroll_calendar', 'leave_configuration'] as $category) {
            $this->assertSame(TenantRetentionCatalog::TENANT_LIFETIME, TenantRetentionCatalog::CATEGORIES[$category][0], $category);
        }
        $this->assertStringContainsString('D9 x D8 (E21.3F, implemented)', TenantRetentionCatalog::CATEGORIES['payroll_ledger'][1]);
        $this->assertSame('payroll_calendar', $tables['payroll_periods']);
        $this->assertSame(['final_ratification_pending', 'no_tenant_purge_authorized'], TenantClosureReadiness::PERMANENT_GATES);
    }

    #[Test]
    public function every_category_has_a_decision_and_every_pending_mechanism_names_its_checkpoint(): void
    {
        // E21.2G: no category is left without a project decision. A decided
        // period without a mechanism names the follow-up checkpoint that ships it.
        foreach (TenantRetentionCatalog::CATEGORIES as $category => [$status, $decision]) {
            // RES.2 (ADR 0068 §12): StudentMark is the ONE deliberate exception -- legal item RES-L8 has no answer,
            // so no period may be invented; readiness reports it as `policy_unresolved` (fail closed).
            if ($category === 'student_marks') {
                $this->assertSame(TenantRetentionCatalog::POLICY_UNRESOLVED, $status, 'StudentMark has no adopted period until RES-L8');
                $this->assertStringContainsString('RES-L8', $decision);

                continue;
            }
            $this->assertNotSame(TenantRetentionCatalog::POLICY_UNRESOLVED, $status, "{$category} has no decision");
            $this->assertNotSame('', trim($decision), "{$category} has no decision text");
            if ($status === TenantRetentionCatalog::MECHANISM_PENDING) {
                $this->fail("{$category}: HRX.6 implemented the last pending mechanism (Leave and Staff Attendance evidence)");
            }
            if ($status === TenantRetentionCatalog::TECHNICAL_BLOCKER) {
                $this->fail("{$category}: E21.3F resolved the last technical blocker (the D8 x D9 payroll ledger)");
            }
        }
    }
}
