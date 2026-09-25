<?php

namespace Tests\Feature\Postgres;

use App\Domain\CurriculumDelivery\Application\Coverage\CurriculumCoverageCounts;
use App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService;
use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0N.11 (ADR 0048 section 4): the Group report is N ordinary
 * single-School reads under forced RLS on the runtime role -- proven with
 * raw SQL run INSIDE each School's observation: it sees that School's rows
 * only, the session variable names exactly that School, and nothing is
 * visible or set once the report has finished.
 */
class GroupReportRlsIsolationTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures, GroupTestHelpers;

    #[Test]
    public function each_observation_sees_only_its_own_school_and_nothing_remains_afterwards(): void
    {
        $a = $this->deliveryWorld()['school'];
        $b = $this->deliveryWorld()['school'];
        $group = $this->createGroup([$a, $b]);
        $admin = $this->groupAdmin($group);

        $observations = [];
        $this->app->instance(CurriculumCoverageReadService::class, new class(app(TenantContext::class), $observations) extends CurriculumCoverageReadService
        {
            /** @param  array<int, array<string, mixed>>  $observations */
            public function __construct(TenantContext $context, private array &$observations)
            {
                parent::__construct($context);
            }

            public function coverageForAcademicYear(School $school, string $academicYearId): CurriculumCoverageCounts
            {
                $this->observations[] = [
                    'school' => $school->id,
                    'guc' => DB::selectOne("select current_setting('app.current_school_id', true) as v")->v,
                    'units' => DB::table('syllabus_units')->distinct()->orderBy('school_id')->pluck('school_id')->all(),
                    'deliveries' => DB::table('curriculum_deliveries')->distinct()->pluck('school_id')->all(),
                    'years' => DB::table('academic_years')->distinct()->pluck('school_id')->all(),
                    'role' => DB::selectOne('select current_user as u')->u,
                ];

                return parent::coverageForAcademicYear($school, $academicYearId);
            }
        });

        $this->actingAs($admin)->withSession(['mfa_verified_at' => now()->toIso8601String()])
            ->get("/app/groups/{$group->id}/reports/curriculum-coverage")->assertOk();

        $this->assertCount(2, $observations);
        foreach ($observations as $o) {
            $this->assertSame($o['school'], $o['guc'], 'The RLS session variable names exactly the observed School.');
            $this->assertSame([$o['school']], $o['units']);
            $this->assertContains($o['deliveries'], [[], [$o['school']]]);
            $this->assertSame([$o['school']], $o['years']);
            $this->assertSame('school_os_app', $o['role'], 'Reads run as the runtime role -- never the admin connection.');
        }
        $this->assertNotSame($observations[0]['school'], $observations[1]['school']);

        // Afterwards: no School set, and RLS shows no tenant rows at all.
        $this->assertNull(app(TenantContext::class)->schoolId());
        $this->assertSame('', (string) DB::selectOne("select current_setting('app.current_school_id', true) as v")->v);
        $this->assertSame(0, DB::table('syllabus_units')->count());
        $this->assertSame(1, DB::table('platform_audit_events')->where('event_type', GroupCurriculumCoverageReportService::VIEWED)->count());
    }

    #[Test]
    public function the_runtime_role_has_no_bypass_and_the_report_tables_keep_forced_rls(): void
    {
        $admin = DB::connection('pgsql_admin');
        $role = $admin->selectOne("select rolsuper, rolbypassrls from pg_roles where rolname = 'school_os_app'");
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolbypassrls);

        foreach (['syllabus_units', 'curriculum_deliveries', 'academic_years', 'subject_offerings', 'sections'] as $table) {
            $rls = $admin->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = \'public\'::regnamespace', [$table]);
            $this->assertTrue((bool) $rls->relrowsecurity, $table);
            $this->assertTrue((bool) $rls->relforcerowsecurity, $table);
        }
    }
}
