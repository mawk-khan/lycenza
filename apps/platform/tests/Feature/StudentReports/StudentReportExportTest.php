<?php

namespace Tests\Feature\StudentReports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\StudentReports\Export\CsvCell;
use App\Support\StudentReports\Export\StudentReportExportColumn;
use App\Support\StudentReports\StudentReportColumn;
use App\Support\StudentReports\StudentReportRegistry;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.4 (D-11): the governed SIS export and print view. A registered
 * report only; its own authority AND the dataset export capability (whole
 * School, School Administrator by default); the screen's filters, sort and
 * scope; declared columns only; formula-safe CSV; one audit event without
 * row values; no other School's data.
 */
class StudentReportExportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private AcademicYear $year;

    private GradeLevel $g5;

    /** @var array<string, Section> */
    private array $sections = [];

    private int $roll = 0;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'M']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->sections['A'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A', 'capacity' => 2]);
        $this->sections['E'] = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E']);

        $this->place('Asha', 'Rao', 'S-1', 'A');
        $this->place('Bilal', 'Khan', 'S-2', 'A');
        $this->place('Chen', 'Li', 'S-3', 'E');
    }

    private function place(string $first, string $last, string $number, string $section): Student
    {
        $student = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => $last, 'student_number' => $number, 'date_of_birth' => '2015-03-04']);
        app(StudentEnrollmentService::class)->enroll($student, $this->sections[$section], (string) ++$this->roll, '2026-06-01', $this->admin);

        return $student;
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null, ?User $user = null): User
    {
        $user ??= $this->createUser();
        $membership = DB::table('school_memberships')->where('user_id', $user->id)->where('school_id', $this->school->id)->exists()
            ? SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $this->school->id)->firstOrFail()
            : $this->createMembership($user, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    private function as(User $user): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }

    /** @param  array<string, mixed>  $body */
    private function export(string $report, array $body = [], ?User $as = null): TestResponse
    {
        return $this->as($as ?? $this->admin)->postJson("/app/students/reports/{$report}/export", ['format' => 'csv', ...$body]);
    }

    /** @return list<list<string>> */
    private function csv(TestResponse $response): array
    {
        $content = $response->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'UTF-8 BOM');

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($content, 3)), fn ($l) => $l !== '')));
    }

    #[Test]
    public function the_export_capabilities_exist_and_only_the_school_administrator_holds_them(): void
    {
        foreach (['students.export', 'admissions.export'] as $key) {
            $this->assertSame('school', DB::table('capabilities')->where('key', $key)->value('namespace'));
            $roles = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
                ->where('capability_key', $key)->where('roles.is_system', true)->pluck('roles.key')->all();
            $this->assertSame(['school_admin'], $roles, "{$key}: School Administrator only");
        }
    }

    #[Test]
    public function the_directory_downloads_the_screen_s_filtered_sorted_rows_with_safe_columns(): void
    {
        $response = $this->export('students.directory', ['sort' => 'student_number']);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertSame('attachment; filename=student-directory-'.SchoolTimezone::today($this->school).'.csv', $response->headers->get('Content-Disposition'));
        $response->assertHeader('Cache-Control', 'no-store, private');

        $rows = $this->csv($response);
        $this->assertSame(['Student number', 'Student name', 'Status', 'Academic year', 'Campus', 'Grade', 'Section', 'Roll number'], $rows[0]);
        $this->assertSame([['S-1', 'Asha Rao', 'Active', '2026-27', 'Main', 'Grade 5', 'A', '1'], ['S-2', 'Bilal Khan', 'Active', '2026-27', 'Main', 'Grade 5', 'A', '2'], ['S-3', 'Chen Li', 'Active', '2026-27', 'Early Years', 'Grade 5', 'E', '3']], array_slice($rows, 1));

        $this->assertSame([['S-3']], array_map(fn ($r) => [$r[0]], array_slice($this->csv($this->export('students.directory', ['campus_id' => $this->early->id])), 1)), 'the screen\'s filters');
        $this->assertSame(['S-2'], array_column(array_slice($this->csv($this->export('students.directory', ['name' => 'khan'])), 1), 0));
        $this->assertStringNotContainsString('2015-03-04', $this->export('students.directory')->streamedContent(), 'never a date of birth');
    }

    #[Test]
    public function formula_shaped_values_are_neutralised(): void
    {
        foreach (['=HYPERLINK(1)' => "'=HYPERLINK(1)", '+SUM(1)' => "'+SUM(1)", '-2+3' => "'-2+3", '@cmd' => "'@cmd", " \t=x" => "' \t=x", "\r=y" => "'\r=y", '-7' => '-7', '12' => '12', 'Normal' => 'Normal', '' => ''] as $in => $out) {
            $this->assertSame($out, CsvCell::safe($in), json_encode($in));
        }

        $this->createStudent($this->school, ['first_name' => '=HYPERLINK("x")', 'last_name' => 'Test', 'student_number' => '+S-9']);
        $row = collect($this->csv($this->export('students.directory', ['name' => 'Test'])))->last();
        $this->assertSame(["'+S-9", "'=HYPERLINK(\"x\") Test"], [$row[0], $row[1]]);
    }

    #[Test]
    public function viewing_never_implies_exporting(): void
    {
        $viewer = $this->holder(['students.view', 'enrollments.view']);
        $this->as($viewer)->get('/app/students')->assertOk()->assertInertia(fn ($p) => $p->where('canExport', false));
        $this->export('students.directory', [], $viewer)->assertForbidden();
        $this->as($viewer)->get('/app/students/reports/students.directory/print')->assertForbidden();

        // The export capability without the report's own authority opens nothing either.
        $exporter = $this->holder(['students.export']);
        $this->export('students.directory', [], $exporter)->assertForbidden();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $this->export('students.directory', [], $teacher)->assertForbidden();

        $this->as($this->admin)->get('/app/students')->assertInertia(fn ($p) => $p->where('canExport', true));
    }

    #[Test]
    public function a_campus_scoped_view_exports_only_its_campus_and_a_forged_campus_matches_nothing(): void
    {
        $user = $this->holder(['students.export']);
        $this->holder(['students.view', 'enrollments.view'], $this->early->id, $user);

        $this->assertSame(['S-3'], array_column(array_slice($this->csv($this->export('students.directory', [], $user)), 1), 0));
        $this->assertSame([], array_slice($this->csv($this->export('students.directory', ['campus_id' => $this->main->id], $user)), 1));
    }

    #[Test]
    public function only_registered_exportable_reports_formats_and_declared_columns_are_accepted(): void
    {
        $this->export('students.unknown')->assertNotFound();
        $this->export('students.siblings')->assertNotFound();
        $this->export('students.placements')->assertNotFound();
        $this->as($this->admin)->get('/app/students/reports/students.siblings/print')->assertNotFound();

        $this->export('students.directory', ['format' => 'xlsx'])->assertUnprocessable()->assertJsonValidationErrors('format');
        foreach (['date_of_birth', 'guardian_email', 'id', 'student_id', 'nonsense'] as $column) {
            $this->export('students.directory', ['columns' => ['student_number', $column]])->assertUnprocessable()->assertJsonValidationErrors('columns');
        }
        $this->export('students.directory', ['sort' => 'students.date_of_birth'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->export('students.directory', ['campus_id' => 'not-a-uuid'])->assertUnprocessable();

        $rows = $this->csv($this->export('students.directory', ['columns' => ['student_name', 'student_number']]));
        $this->assertSame(['Student name', 'Student number'], $rows[0]);

        // A column outside the viewer's reach is refused, never silently included.
        $noPlacement = $this->holder(['students.view', 'students.export']);
        $this->export('students.directory', ['columns' => ['student_number', 'section']], $noPlacement)->assertUnprocessable()->assertJsonValidationErrors('columns');
        $this->assertSame(['Student number', 'Student name', 'Status'], $this->csv($this->export('students.directory', [], $noPlacement))[0]);

        // No export column can be a prohibited or identifying key.
        foreach (['date_of_birth', 'guardian_phone', 'student_id', 'gender'] as $key) {
            $this->assertThrows(fn () => new StudentReportExportColumn($key, 'x'), \InvalidArgumentException::class);
        }
        foreach (StudentReportRegistry::EXPORTERS as $key => $class) {
            foreach (app($class)->exportDefinition()->columns as $column) {
                $this->assertNotContains($column->key, StudentReportColumn::PROHIBITED, $key);
            }
        }
    }

    #[Test]
    public function every_export_is_audited_without_row_values(): void
    {
        $this->export('students.directory', ['name' => 'Asha', 'campus_id' => $this->main->id])->streamedContent();

        $event = app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'student_report.exported')->where('actor_user_id', $this->admin->id)->get()
            ->firstWhere(fn (SchoolAuditEvent $e) => ($e->metadata['filters']['campus_id'] ?? null) === $this->main->id));
        $this->assertNotNull($event);
        $this->assertEquals(['report' => 'students.directory', 'format' => 'csv', 'rowCount' => 1, 'filters' => ['name' => '(search used)', 'campus_id' => $this->main->id]],
            array_intersect_key($event->metadata, array_flip(['report', 'format', 'rowCount', 'filters'])));
        $this->assertContains('student_name', $event->metadata['columns']);
        $json = json_encode($event->metadata);
        foreach (['Asha', 'Rao', 'S-1'] as $value) {
            $this->assertStringNotContainsString($value, (string) $json);
        }
    }

    #[Test]
    public function the_other_pilots_export_their_own_screen(): void
    {
        $chen = app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->where('student_number', 'S-3')->firstOrFail());
        app(StudentService::class)->changeStatus($chen, 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));

        $lifecycle = $this->csv($this->export('students.lifecycle'));
        $this->assertSame(['Recorded on', 'Student number', 'Student name', 'Action', 'Reason', 'Placement'], $lifecycle[0]);
        $this->assertSame([SchoolTimezone::today($this->school), 'S-3', 'Chen Li', 'Marked inactive', 'Left the School', ''], $lifecycle[1]);

        $movements = $this->csv($this->export('students.movements', ['sort' => 'date_asc']));
        $this->assertSame(['Date', 'Student number', 'Student name', 'Category', 'Movement', 'From', 'To', 'Reason'], $movements[0]);
        $this->assertSame(['2026-06-01', 'S-1', 'Asha Rao', 'Entry', 'First enrollment', '', 'Grade 5 · A · Main · 2026-27', ''], $movements[1]);

        $capacity = $this->csv($this->export('students.section_capacity', ['status' => 'at_capacity']));
        $this->assertSame(['Academic year', 'Campus', 'Section', 'Advisory capacity', 'Students', 'Seats', 'Counted on'], $capacity[0]);
        $this->assertSame(['2026-27', 'Main', 'Grade 5 · A', '2', '2', 'At advisory capacity'], array_slice($capacity[1], 0, 6));

        $applicant = $this->createApplicant($this->school, ['first_name' => 'Zara', 'last_name' => 'New', 'date_of_birth' => '2016-01-01']);
        $this->createAdmissionApplication($applicant, $this->year, $this->main, $this->g5, ['status' => 'submitted']);
        $admissions = $this->csv($this->export('admissions.applications'));
        $this->assertSame(['Applicant', 'Status', 'Academic year', 'Campus', 'Grade', 'Created on', 'Converted to a Student'], $admissions[0]);
        $this->assertSame(['Zara New', 'Submitted', '2026-27', 'Main', 'Grade 5'], array_slice($admissions[1], 0, 5));
        $this->assertSame('No', $admissions[1][6]);
        $this->assertStringNotContainsString('2016-01-01', implode(',', $admissions[1]));

        // Admissions is its own dataset: students.export alone does not open it.
        $studentsExporter = $this->holder(['admissions.view', 'students.export']);
        $this->export('admissions.applications', [], $studentsExporter)->assertForbidden();
    }

    #[Test]
    public function another_school_never_reaches_an_export(): void
    {
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $year = $this->createAcademicYear($other, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $otherCampus = $this->createCampus($other, ['name' => 'Main', 'code' => 'M']);
        $section = $this->createSection($year, $otherCampus, $this->createGradeLevel($other, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);
        app(StudentEnrollmentService::class)->enroll($this->createStudent($other, ['first_name' => 'Asha', 'last_name' => 'Rao', 'student_number' => 'S-1']), $section, '1', '2026-06-01', $otherAdmin);

        $this->assertCount(4, $this->csv($this->export('students.directory')), 'header + this School\'s three');
        $this->assertSame([], array_slice($this->csv($this->export('students.directory', ['campus_id' => $otherCampus->id])), 1));
    }

    #[Test]
    public function the_print_view_has_the_same_authority_rows_and_columns(): void
    {
        $page = $this->as($this->admin)->get('/app/students/reports/students.section_capacity/print?campus_id='.$this->main->id)->assertOk()->viewData('page');
        $this->assertSame('App/Students/Reports/Print', $page['component']);
        $props = $page['props'];
        $this->assertSame(['Section capacity', $this->school->name, ['Campus: Main']], [$props['title'], $props['school'], $props['filters']]);
        $this->assertSame(['Academic year', 'Campus', 'Section', 'Advisory capacity', 'Students', 'Seats', 'Counted on'], $props['columns']);
        $this->assertSame([['2026-27', 'Main', 'Grade 5 · A', 2, 2, 'At advisory capacity']], array_map(fn ($r) => array_slice($r, 0, 6), $props['rows']));

        $this->assertTrue(app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'student_report.exported')->get()
            ->contains(fn (SchoolAuditEvent $e) => $e->metadata['format'] === 'print' && $e->metadata['report'] === 'students.section_capacity')));

        $this->as($this->holder(['enrollments.view']))->get('/app/students/reports/students.section_capacity/print')->assertForbidden();
    }
}
