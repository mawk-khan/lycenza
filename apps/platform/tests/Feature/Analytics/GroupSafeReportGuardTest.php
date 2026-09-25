<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Application\AnalyticsReadModel;
use App\Domain\Analytics\Application\AnalyticsReadModelRegistry;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;
use App\Domain\Analytics\Application\Group\GroupSafeReport;
use App\Domain\Analytics\Application\Group\GroupSafeReportDeclaration;
use App\Domain\Analytics\Application\Group\GroupSafeReportGate;
use App\Domain\Analytics\Application\Group\GroupSafeReportRegistry;
use App\Domain\Analytics\Application\ReadModelDeclaration;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Models\School;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0N.11 structural guards for ADR 0048. Behaviour is proven in
 * Platform\Groups\GroupCurriculumCoverageReportTest,
 * Platform\Groups\GroupReportConcurrencyTest and
 * Postgres\GroupReportRlsIsolationTest; these fail on the shape of a
 * change that would widen the one cross-School read path.
 */
class GroupSafeReportGuardTest extends TestCase
{
    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    #[Test]
    public function curriculum_coverage_is_the_only_group_safe_report_and_it_counts_no_people(): void
    {
        $this->assertSame([CurriculumCoverageReadModel::KEY => CurriculumCoverageReadModel::class], GroupSafeReportRegistry::REPORTS,
            'Adding a Group-safe report is an ADR 0048 amendment.');

        $declaration = app(CurriculumCoverageReadModel::class)->groupDeclaration();
        $this->assertSame('curriculum.coverage', $declaration->key);
        $this->assertFalse($declaration->countsPeople);
        $this->assertFalse(app(CurriculumCoverageReadModel::class)->declaration()->countsPeople);
        $this->assertSame(ClassificationTier::Confidential, $declaration->tier);
        $this->assertSame([], $declaration->dimensions);
        $this->assertSame(['planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent', 'offerings', 'offeringsWithoutSyllabus'], $declaration->metrics);
        $this->assertSame('active_only', $declaration->academicYearSelection);
        $this->assertSame('sum_counts_recompute_percent', $declaration->aggregation);

        $this->expectException(InvalidArgumentException::class);
        new GroupSafeReportDeclaration('people.count', 'Analytics', ['Students'], [], ['students'], ClassificationTier::Sensitive, true, 'sum', [], 'active_only', 'none');
    }

    #[Test]
    public function ordinary_analytics_registration_never_makes_a_report_group_safe(): void
    {
        $fake = new class implements AnalyticsReadModel
        {
            public function declaration(): ReadModelDeclaration
            {
                return new ReadModelDeclaration('fake.report', ClassificationTier::Confidential, false, ['Fake'], []);
            }

            public function compute(School $school, array $filters): array
            {
                return [];
            }
        };

        $this->assertTrue((new AnalyticsReadModelRegistry([$fake::class]))->isRegistered($fake));
        $this->assertNull(app(GroupSafeReportRegistry::class)->find('fake.report'));
        $this->assertNull(app(GroupSafeReportRegistry::class)->find('curriculum.coverage.v2'));

        $this->expectException(AnalyticsReportUnavailableException::class);
        app(GroupSafeReportGate::class)->report('fake.report');
    }

    #[Test]
    public function every_group_safe_report_is_also_a_registered_non_person_read_model(): void
    {
        foreach (GroupSafeReportRegistry::REPORTS as $class) {
            $this->assertTrue(is_subclass_of($class, GroupSafeReport::class), $class);
            $this->assertContains($class, AnalyticsReadModelRegistry::READ_MODELS, $class);
            $this->assertFalse(app($class)->declaration()->countsPeople, $class);
        }
    }

    #[Test]
    public function the_group_coverage_path_has_no_person_dependency(): void
    {
        foreach ([
            app_path('Domain/Analytics/Application/ReadModels/CurriculumCoverageReadModel.php'),
            app_path('Domain/CurriculumDelivery/Application/CurriculumCoverageReadService.php'),
            ...$this->phpFiles(app_path('Domain/Analytics/Application/Group')),
            ...$this->phpFiles(app_path('Domain/Platform/Application/Groups/Reporting')),
        ] as $file) {
            $code = $this->code($file);
            foreach (['Domain\\Students', 'Domain\\Guardians', 'Domain\\HR', 'Domain\\Payroll', 'Domain\\Examinations', 'Domain\\Attendance', 'StudentMark', 'Employee', 'Guardian', 'teacher_', 'student_id'] as $person) {
                $this->assertStringNotContainsString($person, $code, basename($file)." must not depend on {$person}");
            }
        }
    }

    #[Test]
    public function the_group_layer_never_touches_report_sources_and_analytics_never_reads_group_tables(): void
    {
        foreach ($this->phpFiles(app_path('Domain/Platform/Application/Groups/Reporting')) as $file) {
            $code = $this->code($file);
            foreach (['CurriculumCoverageReadService', 'Infrastructure\\', '->groupSafeSummary(', '->compute(', 'AnalyticsReadGate', 'AnalyticsReadModelRegistry', 'syllabus_units', 'curriculum_deliveries', 'academic_years'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file)." must not use {$forbidden}");
            }
        }

        foreach ($this->phpFiles(app_path('Domain/Analytics')) as $file) {
            $code = $this->code($file);
            foreach (['school_group', 'SchoolGroup', 'GroupRoleAssignment', 'Domain\\Platform'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file)." must not read {$forbidden}");
            }
        }

        // The execution authority is minted only by the Group report service.
        foreach ($this->phpFiles(app_path()) as $file) {
            if (str_contains($this->code($file), 'new GroupReportAuthority(')) {
                $this->assertStringEndsWith('Groups/Reporting/GroupCurriculumCoverageReportService.php', $file);
            }
        }

        // Only the Group-safe gate calls a report's Group summary.
        foreach ($this->phpFiles(app_path()) as $file) {
            if (str_contains($this->code($file), '->groupSafeSummary(')) {
                $this->assertStringEndsWith('Analytics/Application/Group/GroupSafeReportGate.php', $file);
            }
        }
    }

    #[Test]
    public function the_group_capability_belongs_to_group_admin_only_and_no_platform_or_school_role(): void
    {
        $holders = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('capability_key', 'group.reporting.view')->pluck('roles.key')->all();
        $this->assertSame(['group_admin'], $holders);

        $this->assertSame('group', DB::table('capabilities')->where('key', 'group.reporting.view')->value('namespace'));
        $this->assertSame(0, DB::table('capabilities')->where('key', 'like', 'analytics.platform%')->orWhere('key', 'like', 'platform.cross_school%')->count(),
            'No platform-wide Analytics or cross-School platform capability exists.');
    }

    #[Test]
    public function the_report_surface_is_one_read_only_web_page_with_no_export_or_persistence(): void
    {
        $matches = [];
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (str_contains($uri, 'reports/curriculum-coverage')) {
                $matches[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$uri;
            }

            if (str_starts_with($uri, 'app/groups')) {
                foreach (['export', 'download', 'csv', 'xlsx', 'pdf'] as $never) {
                    $this->assertStringNotContainsString($never, $uri);
                }
            }

            if (str_starts_with($uri, 'api/')) {
                $this->assertStringNotContainsString('GroupReport', (string) $route->getActionName());
            }
        }
        $this->assertSame(['GET app/groups/{schoolGroup}/reports/curriculum-coverage'], $matches);

        foreach ([...$this->phpFiles(app_path('Domain/Analytics/Application/Group')), ...$this->phpFiles(app_path('Domain/Platform/Application/Groups/Reporting'))] as $file) {
            $code = $this->code($file);
            foreach (['Cache::', 'cache(', 'remember(', 'TenantCache', 'Storage::', '->insert(', '::create(', '->save(', 'pgsql_admin', 'BYPASSRLS', 'withoutGlobalScope', 'dispatch(', 'Mail::'] as $never) {
                $this->assertStringNotContainsString($never, $code, basename($file)." must not use {$never} (no persistence, cache, export, bypass or side effect)");
            }
        }

        $this->assertSame([], DB::connection('pgsql_admin')->select("select tablename from pg_tables where schemaname = 'public' and (tablename like '%group_report%' or tablename like '%report_snapshot%')"));
    }

    #[Test]
    public function group_reporting_opens_no_compliance_automation_or_ai_path(): void
    {
        foreach ([...$this->phpFiles(app_path('Domain/Analytics/Application/Group')), ...$this->phpFiles(app_path('Domain/Platform/Application/Groups/Reporting'))] as $file) {
            $code = $this->code($file);
            foreach (['Domain\\Compliance', 'Domain\\Automation', 'Support\\Ai', 'AiGatewayClient', 'AiContextTokenService', 'AuditLogReview', 'SchoolAuditEventReader'] as $never) {
                $this->assertStringNotContainsString($never, $code, basename($file)." must not reach {$never}");
            }
        }

        foreach ([app_path('Domain/Compliance'), app_path('Domain/Automation'), app_path('Support/Ai'), app_path('Http/Controllers/Api/Internal')] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $code = $this->code($file);
                $this->assertStringNotContainsString('group.reporting.view', $code, $file);
                $this->assertStringNotContainsString('GroupSafe', $code, $file);
            }
        }
    }
}
