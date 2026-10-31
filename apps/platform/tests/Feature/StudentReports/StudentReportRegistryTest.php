<?php

namespace Tests\Feature\StudentReports;

use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Guardians\Application\Reports\SiblingReport;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\Reports\SectionCapacityReport;
use App\Domain\Students\Application\Reports\StudentLifecycleReport;
use App\Domain\Students\Application\StudentDirectoryOrder;
use App\Domain\Students\Application\StudentLifecycleReason;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Navigation\StaffNavigation;
use App\Support\StudentReports\StudentReport;
use App\Support\StudentReports\StudentReportColumn;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Support\Authorization\Coverage;
use Tests\Support\Authorization\SchoolRouteCoverage;
use Tests\TestCase;

/**
 * SIS 11.1: the closed SIS report catalogue. Every report is code-owned,
 * names its owner and its authority (existing School capabilities only,
 * never a broad `reports.view`), declares closed filters and sorts, carries
 * no prohibited or exportable column, and -- for an existing dedicated page
 * -- matches what that page actually does.
 */
class StudentReportRegistryTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array<string, StudentReportDefinition> */
    private function definitions(): array
    {
        return app(StudentReportRegistry::class)->definitions();
    }

    #[Test]
    public function every_report_is_unique_owned_and_authorized_by_existing_school_capabilities(): void
    {
        $definitions = $this->definitions();
        $this->assertSame(['students.directory', 'students.siblings', 'students.placements', 'students.movements', 'students.section_capacity', 'students.lifecycle', 'admissions.applications'], array_keys($definitions), 'the closed catalogue, in hub order');

        $capabilities = DB::table('capabilities')->pluck('namespace', 'key')->all();
        foreach ($definitions as $key => $definition) {
            $this->assertSame($key, $definition->key);
            $this->assertNotSame('', trim($definition->owner), $key);
            $this->assertNotSame([], $definition->reference, "{$key} maps to the SIS reference map");
            $required = [...$definition->authority, ...array_merge(...array_values(array_map(fn (StudentReportColumn $c) => $c->requires, $definition->columns)))];
            foreach ($required as $capability) {
                $this->assertSame('school', $capabilities[$capability] ?? null, "{$key} needs an existing School capability, not {$capability}");
                $this->assertStringNotContainsString('report', $capability, 'never a broad reports capability');
            }
            foreach ($definition->columns as $column) {
                $this->assertFalse($column->exportable, "{$key}.{$column->key}: no export before the governed export contract (D-11)");
            }
        }
    }

    #[Test]
    public function prohibited_columns_unknown_filters_and_open_ended_definitions_are_refused(): void
    {
        foreach (['date_of_birth', 'guardian_phone', 'value_hmac', 'id', 'acted_by_user_id'] as $key) {
            try {
                new StudentReportColumn($key, 'x');
                $this->fail("{$key} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new StudentReportColumn('placement', 'Placement', 'placement', StudentReportColumn::RESTRICTED);
    }

    #[Test]
    public function a_definition_with_an_unknown_filter_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StudentReportDefinition('students.x', 'X', 'X', 'students', 'Students', ['students.view'], StudentReportDefinition::WHOLE_SCHOOL,
            StudentReportDefinition::RENDERED, ['raw_sql'], [new StudentReportColumn('student', 'Student', 'student')], ['a' => 'A'], 25, ['SR-03']);
    }

    #[Test]
    public function sorts_are_closed_maps_pinned_to_the_read_model(): void
    {
        $definitions = $this->definitions();
        $this->assertSame(array_keys(StudentDirectoryOrder::SORTS), array_keys($definitions['students.directory']->sorts));
        $this->assertSame(array_keys(StudentMovementRegister::SORTS), array_keys($definitions['students.movements']->sorts));
        $this->assertSame(array_keys(StudentLifecycleReport::SORTS), array_keys($definitions['students.lifecycle']->sorts));
        $this->assertSame(array_keys(SiblingReport::SORTS), array_keys($definitions['students.siblings']->sorts));
        $this->assertSame(array_keys(SectionCapacityReport::SORTS), array_keys($definitions['students.section_capacity']->sorts));
        $this->assertSame(array_keys(AdmissionApplicationReadService::SORTS), array_keys($definitions['admissions.applications']->sorts));
        foreach (StudentReportRegistry::RENDERED as $class) {
            $this->assertTrue(is_subclass_of($class, StudentReport::class));
            $this->assertTrue(app($class)->definition()->isRendered(), $class);
        }
    }

    #[Test]
    public function a_dedicated_page_entry_declares_exactly_the_filters_that_page_reads(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $this->actingAs($admin)->post("/app/schools/{$school->id}/activate");

        foreach ($this->definitions() as $definition) {
            if ($definition->isRendered()) {
                continue;
            }
            $props = $this->get($definition->url())->assertOk()->viewData('page')['props'];
            $read = array_values(array_diff(array_keys($props['filters']), ['sort']));
            sort($read);
            $declared = $definition->filters;
            sort($declared);
            $this->assertSame($declared, $read, "{$definition->key}: the catalogue matches {$definition->route}");
        }
    }

    #[Test]
    public function each_report_route_honours_its_scope_policy(): void
    {
        $coverage = collect(SchoolRouteCoverage::entries())->keyBy(fn (Coverage $c) => $c->route);

        foreach ($this->definitions() as $definition) {
            if ($definition->isRendered()) {
                // The shared page: StudentReportAccess decides per report (C3).
                $this->assertSame(Coverage::SERVICE, $coverage[StudentReportDefinition::RENDERED]->class);

                continue;
            }
            if ($definition->scope === StudentReportDefinition::CAMPUS_AWARE) {
                $entry = $coverage[$definition->route] ?? null;
                $this->assertSame(Coverage::CAMPUS, $entry?->class, "{$definition->key}: a Campus-aware page is a C6 Campus route");
                $this->assertSame($definition->authority, $entry->capabilities);
            } else {
                $this->assertSame($definition->authority[0], CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS[$definition->route]['capability'] ?? null, "{$definition->key}: a whole-School page is a declared whole-School operation");
            }
        }
    }

    #[Test]
    public function the_reports_surface_is_closed_routes_and_one_navigation_entry(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with((string) $r->getName(), 'app.students.reports.'));
        // SIS 11.4 (D-11): the governed export is the one POST (audited, CSRF); print is a read.
        $this->assertSame(['app.students.reports.export' => ['POST'], 'app.students.reports.index' => ['GET', 'HEAD'], 'app.students.reports.print' => ['GET', 'HEAD'], 'app.students.reports.show' => ['GET', 'HEAD']],
            $routes->mapWithKeys(fn (Route $r) => [$r->getName() => $r->methods()])->sortKeys()->all(), 'no other write route');

        // The entry shows for a capability that opens some report, and every
        // report is reachable through one of its keys. All of them are
        // Campus-adopted, so a Campus holder of a Campus-aware report sees it.
        $any = collect(StaffNavigation::entries())->firstWhere('key', 'student-reports')->any;
        $authorities = array_merge(...array_values(array_map(fn (StudentReportDefinition $d) => $d->authority, $this->definitions())));
        $this->assertSame([], array_values(array_diff($any, $authorities)));
        foreach ($this->definitions() as $definition) {
            $this->assertNotSame([], array_intersect($definition->authority, $any), "{$definition->key} is reachable from the menu entry");
        }
        $this->assertSame($any, CampusScopeCatalogue::adoptedAmong($any));
    }

    #[Test]
    public function lifecycle_reason_labels_match_the_shared_frontend_map_and_cover_every_code(): void
    {
        preg_match_all("/^\s+([a-z_]+): '([^']+)',$/m", (string) file_get_contents(resource_path('js/lifecycleReasons.ts')), $m);
        $this->assertSame(StudentLifecycleReason::LABELS, array_combine($m[1], $m[2]));

        $codes = array_values(array_unique(array_merge(...array_values(StudentLifecycleReason::CODES))));
        sort($codes);
        $labelled = array_keys(StudentLifecycleReason::LABELS);
        sort($labelled);
        $this->assertSame($codes, $labelled);
        $this->assertSame(array_keys(StudentLifecycleReason::CODES), array_keys(StudentLifecycleReport::ACTION_LABELS));
    }

    #[Test]
    public function the_lifecycle_read_model_never_selects_the_actor_or_personal_data(): void
    {
        $source = (string) file_get_contents(app_path('Domain/Students/Application/Reports/StudentLifecycleReport.php'));
        $select = substr($source, (int) strpos($source, '->select(['), 600);
        foreach (['acted_by_user_id', 'date_of_birth', 'guardian', 'metadata', 'users'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $select);
        }
    }
}
