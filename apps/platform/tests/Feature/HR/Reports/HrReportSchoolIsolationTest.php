<?php

namespace Tests\Feature\HR\Reports;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Models\School;
use App\Models\User;
use App\Support\HrReports\HrReportColumn;
use App\Support\HrReports\HrReportDefinition;
use App\Support\HrReports\HrReportRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-26 (R1-06, C38-N1, C38-D2): every HR report and export, proven School-isolated with real records
 * on BOTH sides. Each School gets the same synthetic world under its own name prefix, so every rendered report has
 * a positive control (its own School's records appear) and a negative one (the other School's never do), read each
 * way. The manifest is tied to `HrReportRegistry`: a new report fails here until it is classified. The tables these
 * reports read are proven at the raw/RLS layer by `Tests\Feature\Postgres\HrFamilyTenantIsolationTest`.
 *
 * HTTP authorization (may this viewer open the report at all) is a separate protection, covered by `HrReportsTest`
 * and rule 98's coverage guard; a 403 is never counted here as isolation evidence.
 *
 * Today is Monday 2026-10-05 (School-local, Asia/Kolkata). Every name and number is a synthetic test input.
 */
class HrReportSchoolIsolationTest extends TestCase
{
    use CreatesLeaveFixtures;

    /**
     * Every RENDERED report and the query that shows the fixture's rows. Generation runs carry no person, so their
     * marker is the run's examined count (Alpha 3, Beta 7) on 2026-10-04; every other report is marked by names.
     */
    private const RENDERED = [
        'hr.staff_details' => [],
        'hr.staff_movements' => ['from' => '2026-10-01', 'to' => '2026-10-05'],
        'hr.work_anniversaries' => ['month' => '2026-10'],
        'hr.reporting_lines' => ['as_of' => '2026-10-05'],
        'hr.leave_balances' => [],
        'hr.attendance_absentees' => ['from' => '2026-10-01', 'to' => '2026-10-05'],
        'hr.attendance_corrections' => ['from' => '2026-10-01', 'to' => '2026-10-05'],
        'hr.task_occurrences' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'hr.task_summary' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'hr.task_generation_runs' => ['from' => '2026-10-03', 'to' => '2026-10-05'],
    ];

    /** Governed entries are existing pages under their own authority; their tables are proven raw, their pages by their modules' tests. */
    private const GOVERNED = [
        'hr.staff_directory', 'hr.separation_cases', 'hr.leave_requests', 'hr.leave_monthly', 'hr.leave_accruals',
        'hr.attendance_register', 'hr.attendance_monthly', 'hr.task_monitor',
    ];

    private const EXAMINED = ['Alpha' => 3, 'Beta' => 7];

    /**
     * Export-shaped routes of the HR family and Payroll, each classified with the test that proves its School isolation.
     * Anything else matching the pattern fails: a future export never inherits R1-06's earlier "no export" reading
     * without its own isolation case. Limits: an export whose URI and name avoid these words is not seen, and only these
     * controller namespaces are scanned -- the generic Documents API (`/api/v1/.../documents/{document}/content`), which
     * also serves Employee files through HR's `EmployeeDocumentPolicy` port, is the Documents domain's and is not pinned here.
     */
    private const EXPORT_SHAPED = [
        'app.hr.employees.hr-document-records.download' => 'document download, re-authorized per tier on every request (rule 132): an_hr_document_download_never_crosses_schools',
        'app.payroll.statutory.runs.exports.show' => 'statutory export page: StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'app.payroll.statutory.runs.exports.ecr' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'app.payroll.statutory.runs.exports.esi-worksheet' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'app.payroll.statutory.runs.exports.tds-draft-statement' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'api.v1.schools.payroll-runs.statutory-exports.ecr' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'api.v1.schools.payroll-runs.statutory-exports.esi-worksheet' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
        'api.v1.schools.payroll-runs.statutory-exports.tds-draft-statement' => 'StatutoryExportsTest::wo26_every_statutory_export_answers_only_its_own_schools_run_on_web_and_api',
    ];

    private const FAMILY_CONTROLLER_NAMESPACES = [
        'App\\Http\\Controllers\\App\\HR\\', 'App\\Http\\Controllers\\App\\Leave\\', 'App\\Http\\Controllers\\App\\StaffAttendance\\',
        'App\\Http\\Controllers\\App\\Payroll\\', 'App\\Http\\Controllers\\App\\SelfService\\',
        'App\\Domain\\HR\\', 'App\\Domain\\Leave\\', 'App\\Domain\\StaffAttendance\\', 'App\\Domain\\WorkforceTasks\\', 'App\\Domain\\Payroll\\',
    ];

    private const EXPORT_PATTERN = '/export|csv|print|download|pdf|xlsx|spreadsheet/i';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    /** One School's synthetic world: two staff, a line, a transfer, leave, absence, a correction, a task and a run. */
    private function world(School $school, string $p): User
    {
        $viewer = $this->createUserWithCapabilities($school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.leave.view', 'hr.staff_attendance.view', 'hr.tasks.view',
        ]);
        $this->inSchool($school, function () use ($school, $p): void {
            $north = $this->createCampus($school, ['name' => "{$p} North"]);
            $south = $this->createCampus($school, ['name' => "{$p} South"]);
            $department = $this->createDepartment($school, ['name' => "{$p} Dept", 'status' => 'active']);
            $role = $this->createPosition($school, ['name' => "{$p} Role", 'status' => 'active']);
            $staff = function (string $name, string $number, string $starts) use ($school, $north, $department, $role): array {
                $record = $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => $name, 'employee_number' => $number]), ['starts_on' => $starts, 'ends_on' => null, 'status' => 'active', 'staff_type' => 'teaching']);

                return [$record, $this->createEmployeeAssignment($record, $role, ['campus_id' => $north->id, 'department_id' => $department->id, 'is_primary' => true, 'starts_on' => $starts, 'ends_on' => null])];
            };
            [$head] = $staff("{$p} Head", "{$p}-001", '2020-10-12');
            [$member, $memberPrimary] = $staff("{$p} Member", "{$p}-002", '2020-01-01');
            $actor = $this->fullHrActor($school);
            app(ReportingRelationshipService::class)->assign($member, $head, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $actor);
            app(PlacementChangeService::class)->change($memberPrimary, PlacementChangeKind::TRANSFER, '2026-10-03', PlacementChangeReason::STAFFING_REQUIREMENT, $role, $actor, campus: $south);

            $admin = $this->leaveAdmin($school);
            $this->workingWeek($school, $admin);
            $type = $this->leaveType($school, $admin);
            $policy = $this->leavePolicy($school, $type, $admin);
            $year = $this->leaveYear($school, $admin, '2026-06-01');
            app(LeavePolicyAssignmentService::class)->assign($school, $member->id, $policy->id, '2026-04-01', null, $admin);
            app(LeaveLedgerService::class)->allocate($school, $member->id, $type->id, $year->id, 24, $admin, $this->freshMfaProofFor($admin));

            $clerk = $this->createUserWithCapabilities($school, ['hr.staff_attendance.view', 'hr.staff_attendance.manage']);
            $clerk->forceFill(['name' => "{$p} Clerk"])->save();
            $attendance = app(StaffAttendanceService::class);
            $attendance->record($school, $head->id, '2026-10-01', 'absent', 'present', $clerk);
            $record = $attendance->record($school, $member->id, '2026-10-02', 'absent', 'absent', $clerk);
            $attendance->correct($school, $record->id, $record->version, 'present', 'absent', 'late_information', $clerk);

            $tasker = $this->createUserWithCapabilities($school, ['hr.tasks.view', 'hr.tasks.manage', 'hr.tasks.configure']);
            $category = app(TaskCatalogueService::class)->createCategory($school, 'ops', "{$p} Ops", $tasker);
            app(TaskAssignmentService::class)->assign($school, $member->id, [
                'title' => "{$p} Task", 'description' => null, 'category_id' => (string) $category->id, 'completion_mode' => 'self',
                'available_on' => '2026-10-05', 'due_on' => '2026-10-09', 'section_id' => null, 'subject_offering_id' => null,
            ], $tasker);
            DB::table('workforce_task_generation_runs')->insert([
                'id' => (string) Str::uuid(), 'school_id' => $school->id, 'occurrence_on' => '2026-10-04', 'status' => 'completed',
                'examined' => self::EXAMINED[$p], 'created' => 0, 'existing' => 0, 'not_matched' => self::EXAMINED[$p], 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $viewer;
    }

    /** @return list<array<string, mixed>> the report's rows, read as $viewer with $school active */
    private function rows(string $key, User $viewer, School $school): array
    {
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");
        $props = $this->get(route('app.hr.reports.show', ['report' => $key, ...self::RENDERED[$key]], false))->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['errors'] ?? [], "{$key}: the fixture query is valid");

        return $props['rows']['data'];
    }

    #[Test]
    public function the_isolation_manifest_classifies_every_registry_entry_exactly_once(): void
    {
        $registry = app(HrReportRegistry::class);
        $all = array_keys($registry->definitions());
        $governed = array_map(fn ($d) => $d->key, HrReportRegistry::pages());
        $rendered = array_values(array_diff($all, $governed));

        sort($rendered);
        $manifest = array_keys(self::RENDERED);
        sort($manifest);
        $this->assertSame($manifest, $rendered, 'every RENDERED report has a two-School case here, and nothing else does');
        $this->assertSame(count(HrReportRegistry::RENDERED), count($rendered));
        $pages = self::GOVERNED;
        sort($pages);
        sort($governed);
        $this->assertSame($pages, $governed, 'every governed page is classified');
    }

    #[Test]
    public function every_rendered_report_shows_its_own_schools_records_and_never_the_other_schools(): void
    {
        $alpha = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $beta = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $viewers = ['Alpha' => [$alpha, $this->world($alpha, 'Alpha')], 'Beta' => [$beta, $this->world($beta, 'Beta')]];

        foreach (array_keys(self::RENDERED) as $key) {
            foreach ([['Alpha', 'Beta'], ['Beta', 'Alpha']] as [$own, $foreign]) {
                [$school, $viewer] = $viewers[$own];
                $rows = $this->rows($key, $viewer, $school);
                $this->assertNotSame([], $rows, "{$key} in {$own}: a positive control, the School's own rows are listed");
                if ($key === 'hr.task_generation_runs') {
                    $day = array_values(array_filter($rows, fn (array $r) => $r['date'] === '2026-10-04'));
                    $this->assertCount(1, $day, "{$key} in {$own}: one row for the day, never a second School's run beside it");
                    $this->assertSame(self::EXAMINED[$own], $day[0]['examined'] ?? null, "{$key} in {$own}: its own run");
                    $this->assertNotContains(self::EXAMINED[$foreign], array_column($rows, 'examined'), "{$key} in {$own}: never {$foreign}'s run");

                    continue;
                }
                $json = (string) json_encode($rows);
                $this->assertStringContainsString($own, $json, "{$key} in {$own}: its own records appear");
                $this->assertStringNotContainsString($foreign, $json, "{$key} in {$own}: {$foreign}'s records never appear");
            }
        }
    }

    #[Test]
    public function an_id_of_another_school_in_a_report_filter_matches_nothing(): void
    {
        $alpha = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $beta = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $viewer = $this->world($alpha, 'Alpha');
        $this->world($beta, 'Beta');
        $betaYear = $this->inSchool($beta, fn () => DB::table('leave_years')->value('id'));
        $betaDepartment = $this->inSchool($beta, fn () => DB::table('hr_departments')->value('id'));
        $this->assertNotNull($betaYear);
        $this->assertNotNull($betaDepartment);

        $this->actingAs($viewer)->post("/app/schools/{$alpha->id}/activate");
        $forged = [
            'leave_year_id' => ['report' => 'hr.leave_balances', 'leave_year_id' => $betaYear],
            'department_id' => ['report' => 'hr.attendance_absentees', 'from' => '2026-10-01', 'to' => '2026-10-05', 'department_id' => $betaDepartment],
        ];
        // Positive control: the same filter with Alpha's own id narrows to Alpha's rows, so a refusal below is not just
        // a filter that always refuses.
        $ownYear = $this->inSchool($alpha, fn () => DB::table('leave_years')->value('id'));
        $own = $this->get(route('app.hr.reports.show', ['report' => 'hr.leave_balances', 'leave_year_id' => $ownYear], false))->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('leave_year_id', $own['errors'] ?? []);
        $this->assertStringContainsString('Alpha', (string) json_encode($own['rows']));
        foreach ($forged as $filter => $query) {
            $props = $this->get(route('app.hr.reports.show', $query, false))->assertOk()->viewData('page')['props'];
            // A foreign id matches nothing: either the filter is refused on the page, or it narrows to no row.
            $refused = array_key_exists($filter, $props['errors'] ?? []);
            $this->assertTrue($refused || $props['rows']['data'] === [], "{$query['report']}: School Beta's {$filter} matched rows in Alpha");
            $this->assertStringNotContainsString('Beta', (string) json_encode($props['rows']));
        }
    }

    #[Test]
    public function an_hr_document_download_never_crosses_schools(): void
    {
        Storage::fake((string) config('documents.disk'));
        $alpha = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $beta = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $alphaHr = $this->fullHrActor($alpha);
        $betaHr = $this->fullHrActor($beta);
        [$employee, $record] = $this->inSchool($alpha, function () use ($alpha, $alphaHr) {
            $employee = $this->createEmployee($alpha);

            return [$employee, app(EmployeeDocumentService::class)->register($employee, UploadedFile::fake()->createWithContent('alpha-contract.pdf', "%PDF-1.4\n%alpha\n"), EmployeeDocumentCategory::EMPLOYMENT_CONTRACT, 'restricted', [], $alphaHr)];
        });
        $url = "/app/hr/employees/{$employee->id}/hr-document-records/{$record->id}/download";

        // Positive control: Alpha's own HR reads Alpha's file.
        $this->actingAs($alphaHr)->post("/app/schools/{$alpha->id}/activate");
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=alpha-contract.pdf');
        // Beta's HR, fully authorized in Beta, naming Alpha's Employee and file: not found, and no byte of it.
        $this->actingAs($betaHr)->post("/app/schools/{$beta->id}/activate");
        $foreign = $this->get($url);
        $this->assertSame(404, $foreign->status());
        $this->assertStringNotContainsString('%alpha', (string) $foreign->getContent());
        $this->assertNull($foreign->headers->get('Content-Disposition'));
    }

    #[Test]
    public function no_hr_family_or_payroll_route_exports_unless_it_is_classified_with_its_isolation_case(): void
    {
        $found = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $controller = (string) ($route->getAction('controller') ?? '');
            $family = array_filter(self::FAMILY_CONTROLLER_NAMESPACES, fn (string $ns) => str_starts_with($controller, $ns)) !== [];
            if ($family && preg_match(self::EXPORT_PATTERN, $route->uri().' '.($route->getName() ?? ''))) {
                $found[] = (string) $route->getName();
            }
        }
        sort($found);
        $classified = array_keys(self::EXPORT_SHAPED);
        sort($classified);
        $this->assertSame($classified, $found, 'an export-shaped HR-family or Payroll route needs its own classification and School-isolation case');

        // The report catalogue itself stays browser-only: no export, print or download member.
        foreach ([HrReportRegistry::class, HrReportDefinition::class, HrReportColumn::class] as $class) {
            $reflection = new \ReflectionClass($class);
            $members = [...array_map(fn ($m) => $m->getName(), $reflection->getMethods()), ...array_keys($reflection->getConstants()),
                ...array_map(fn ($p) => $p->getName(), $reflection->getProperties())];
            foreach ($members as $member) {
                $this->assertDoesNotMatchRegularExpression(self::EXPORT_PATTERN, $member, "{$class}::{$member}");
            }
        }
    }
}
