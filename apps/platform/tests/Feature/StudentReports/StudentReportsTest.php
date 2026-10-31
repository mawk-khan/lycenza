<?php

namespace Tests\Feature\StudentReports;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.1: the SIS Reports hub and the shared report page (the Student
 * lifecycle report). Authority per report, never broader than the data
 * capabilities; whole-School history is never opened to a Campus grant;
 * filters only narrow; another School never shows; pages are bounded and
 * the query count does not grow with the data. Every fixture history is
 * written through the real services.
 */
class StudentReportsTest extends TestCase
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
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->sections['5A'] = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A']);
        $this->sections['5E'] = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E']);
    }

    private function placements(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function current(Student $student): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->firstOrFail());
    }

    /** Placed, withdrawn (left the School), marked inactive, then active again. */
    private function leaver(string $first = 'Leaver', string $number = 'L-1', string $section = '5A'): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Test', 'student_number' => $number]);
        $this->placements()->enroll($s, $this->sections[$section], (string) ++$this->roll, '2026-06-01', $this->admin);
        $this->placements()->withdraw($this->current($s), '2026-09-15', 'left_school', $this->admin, $this->freshProof($this->admin));
        $s = app(StudentService::class)->changeStatus($s, 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($s, 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));

        return $s;
    }

    /** A placement that has not started, cancelled before it starts (ADR 0078 empty interval). */
    private function didNotJoin(): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => 'Planned', 'last_name' => 'Test', 'student_number' => 'P-1']);
        $future = $this->placements()->enroll($s, $this->sections['5E'], '5', now()->addDays(10)->toDateString(), $this->admin);
        $this->placements()->cancelBeforeStart($future, 'did_not_join', $this->admin, $this->freshProof($this->admin));

        return $s;
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    private function as(User $user, ?School $school = null): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null, ?School $school = null): array
    {
        return $this->as($as ?? $this->admin, $school)->get('/app/students/reports/students.lifecycle?'.http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "action|number|reason" per row */
    private function rows(array $query = [], ?User $as = null, ?School $school = null): array
    {
        return array_map(fn (array $r) => "{$r['action']}|{$r['student']['studentNumber']}|{$r['reason']}", $this->report($query, $as, $school)['rows']['data']);
    }

    /** @return list<string> */
    private function hub(User $as): array
    {
        $categories = $this->as($as)->get('/app/students/reports')->assertOk()->viewData('page')['props']['categories'];

        return collect($categories)->flatMap(fn (array $c) => array_column($c['reports'], 'key'))->sort()->values()->all();
    }

    #[Test]
    public function the_hub_lists_exactly_the_reports_the_viewer_may_open(): void
    {
        $this->assertSame(['admissions.applications', 'students.directory', 'students.lifecycle', 'students.movements', 'students.placements', 'students.siblings'], $this->hub($this->admin));
        $this->assertSame(['students.directory', 'students.lifecycle'], $this->hub($this->holder(['students.view'])), 'lifecycle needs students.view only; placement reports need enrollments.view');
        $this->assertSame(['students.movements', 'students.placements'], $this->hub($this->holder(['enrollments.view'])));

        // A Campus grant opens the Campus-aware reports only -- never history.
        $campus = $this->holder(['students.view', 'enrollments.view'], $this->main->id);
        $this->assertSame(['students.directory', 'students.placements'], $this->hub($campus));
        $this->as($campus)->get('/app/students/reports/students.lifecycle')->assertForbidden();
        $this->as($campus)->get('/app/students/movements')->assertForbidden();

        // No data capability, or a Teacher's ownership keys: no hub at all.
        $this->as($this->holder(['school.profile.view']))->get('/app/students/reports')->assertForbidden();
        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $this->school), 'teacher');
        $this->as($teacher)->get('/app/students/reports')->assertForbidden();
        $this->as($teacher)->get('/app/students/reports/students.lifecycle')->assertForbidden();
    }

    #[Test]
    public function unknown_and_dedicated_page_keys_are_not_found(): void
    {
        $this->as($this->admin)->get('/app/students/reports/students.unknown')->assertNotFound();
        $this->as($this->admin)->get('/app/students/reports/students.directory')->assertNotFound();
    }

    #[Test]
    public function lifecycle_rows_are_labelled_events_and_cancellation_before_start_reads_as_such(): void
    {
        $this->leaver();
        $this->didNotJoin();

        $this->assertSame([
            'Placement withdrawn|L-1|Left the School',
            'Marked inactive|L-1|Left the School',
            'Marked active again|L-1|Returned to the School',
            'Cancelled before start|P-1|Did not join',
        ], $this->rows(['sort' => 'date_asc']));

        $props = $this->report();
        $this->assertSame(['occurred_on', 'student', 'action', 'reason', 'placement'], array_column($props['columns'], 'key'));
        $cancel = collect($props['rows']['data'])->firstWhere('action', 'Cancelled before start');
        $this->assertSame(['2026-27', 'Early Years', 'Grade 5', 'E'], array_values($cancel['placement']));
        $this->assertSame(SchoolTimezone::today($this->school), $cancel['occurred_on'], 'the School-local recorded date');

        // Minimal: no actor, internal id, raw code or payload in any row.
        $json = json_encode($props['rows']['data']);
        foreach ([$this->admin->id, $this->admin->email, 'acted_by', 'reason_code', 'left_school', 'enrollment_withdrawn', 'mfa', 'metadata'] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $json);
        }
        $this->assertSame(['name', 'studentNumber', 'url'], array_keys($cancel['student']));
    }

    #[Test]
    public function placement_events_and_the_placement_column_need_whole_school_enrollments_view(): void
    {
        $this->leaver();
        $studentsOnly = $this->holder(['students.view']);

        $this->assertSame(['Marked inactive|L-1|Left the School', 'Marked active again|L-1|Returned to the School'], $this->rows(['sort' => 'date_asc'], $studentsOnly));
        $props = $this->report([], $studentsOnly);
        $this->assertNotContains('placement', array_column($props['columns'], 'key'));
        $this->assertArrayNotHasKey('placement', $props['rows']['data'][0]);
        $this->assertSame(['student_deactivated', 'student_reactivated'], array_column(collect($props['filters'])->firstWhere('key', 'action')['options'], 'value'));
        $this->assertSame([], collect($props['filters'])->firstWhere('key', 'campus_id')['options']);

        // Asking for a placement action is a validation error, never a leak.
        $this->as($studentsOnly)->get('/app/students/reports/students.lifecycle?action=enrollment_withdrawn')->assertSessionHasErrors('action');
    }

    #[Test]
    public function filters_only_narrow(): void
    {
        $this->leaver();
        $this->leaver('Other', 'L-2', '5E');
        $this->didNotJoin();

        $this->assertSame(['Placement withdrawn|L-1|Left the School', 'Placement withdrawn|L-2|Left the School'], $this->rows(['action' => 'enrollment_withdrawn', 'sort' => 'student']));
        $this->assertCount(2, $this->rows(['reason' => 'returned_to_school']));
        $this->assertSame(['Placement withdrawn|L-2|Left the School', 'Cancelled before start|P-1|Did not join'], $this->rows(['campus_id' => $this->early->id, 'sort' => 'date_asc']), 'a Campus filter keeps placement events of that Campus only');
        $this->assertSame([], $this->rows(['campus_id' => '00000000-0000-4000-8000-000000000000']), 'an unknown or foreign id matches nothing');
        $this->assertCount(3, $this->rows(['q' => 'L-2']));
        $this->assertCount(3, $this->rows(['q' => 'other test']));
        $today = SchoolTimezone::today($this->school);
        $this->assertCount(7, $this->rows(['from' => $today, 'to' => $today]));
        $this->assertSame([], $this->rows(['to' => date('Y-m-d', strtotime($today.' -1 day'))]));

        $empty = $this->report(['q' => 'nobody']);
        $this->assertTrue($empty['hasAny'], 'filters found nothing, yet records exist');
        $this->assertSame('nobody', collect($empty['values'])->get('q'));
    }

    #[Test]
    public function malformed_filters_are_validation_errors_never_server_errors(): void
    {
        foreach ([
            ['campus_id' => 'not-a-uuid'],
            ['action' => 'drop_table'],
            ['reason' => 'free text'],
            ['from' => '2026-13-40'],
            ['from' => '2026-10-10', 'to' => '2026-10-01'],
            ['sort' => 'st.date_of_birth'],
            ['q' => ['array']],
            ['page' => '-1'],
        ] as $query) {
            $response = $this->as($this->admin)->get('/app/students/reports/students.lifecycle?'.http_build_query($query));
            $this->assertSame(302, $response->getStatusCode(), json_encode($query));
            $response->assertSessionHasErrors();
        }

        // Undeclared parameters are ignored, never read.
        $this->leaver();
        $this->assertCount(3, $this->rows(['school_id' => 'x', 'columns' => 'date_of_birth', 'student_id' => 'x']));
    }

    #[Test]
    public function another_school_never_appears_counts_or_matches(): void
    {
        $this->leaver();
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $year = $this->createAcademicYear($other, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $section = $this->createSection($year, $this->createCampus($other, ['name' => 'Main', 'code' => 'MAIN']), $this->createGradeLevel($other, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);
        $twin = $this->createStudent($other, ['first_name' => 'Leaver', 'last_name' => 'Test', 'student_number' => 'L-1']);
        $placement = $this->placements()->enroll($twin, $section, '1', '2026-06-01', $otherAdmin);
        $this->placements()->withdraw($placement, '2026-09-15', 'left_school', $otherAdmin, $this->freshProof($otherAdmin));
        app(StudentService::class)->changeStatus($twin, 'inactive', 'left_school', $otherAdmin, $this->freshProof($otherAdmin));

        $props = $this->report(['q' => 'L-1']);
        $this->assertSame(3, $props['rows']['total']);
        $this->assertNotContains($twin->id, array_map(fn (array $r) => basename($r['student']['url']), $props['rows']['data']));

        // A School with no lifecycle history of its own says so, whatever another School holds.
        $this->assertSame(2, $this->report([], $otherAdmin, $other)['rows']['total']);
        $this->assertSame([], $this->rows(['campus_id' => $this->main->id], $otherAdmin, $other), "another School's Campus id matches nothing");
    }

    #[Test]
    public function pages_are_bounded_and_the_query_count_does_not_grow_with_the_data(): void
    {
        $this->leaver('Aa', 'Q-0');
        $this->countQueries(); // warms the request-independent caches (capabilities)
        $small = $this->countQueries();

        for ($i = 1; $i <= 9; $i++) {
            $this->leaver('Aa'.$i, 'Q-'.$i);
        }
        $large = $this->countQueries();

        $props = $this->report();
        $this->assertSame([30, 25, 2], [$props['rows']['total'], count($props['rows']['data']), $props['rows']['last_page']]);
        $this->assertSame($small, $large, 'one count and one page query, whatever the history size');
        $this->assertLessThanOrEqual(25, $large);
    }

    private function countQueries(): int
    {
        $this->as($this->admin);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/app/students/reports/students.lifecycle')->assertOk();
        DB::disableQueryLog();
        $log = DB::getQueryLog();
        $this->assertCount(2, array_filter($log, fn (array $q) => str_contains($q['query'], 'student_lifecycle_events')), 'the report itself: one count and one page query');

        return count($log);
    }

    #[Test]
    public function the_directory_sorts_from_a_closed_list(): void
    {
        foreach (['Zed' => 'S-1', 'Amy' => 'S-3', 'Bob' => 'S-2'] as $first => $number) {
            $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Test', 'student_number' => $number]);
        }
        $numbers = fn (array $query) => array_column($this->as($this->admin)->get('/app/students?'.http_build_query($query))->assertOk()->viewData('page')['props']['students']['data'], 'studentNumber');

        $this->assertSame(['S-3', 'S-2', 'S-1'], $numbers([]));
        $this->assertSame(['S-1', 'S-2', 'S-3'], $numbers(['sort' => 'student_number']));
        $this->as($this->admin)->get('/app/students?sort=date_of_birth')->assertSessionHasErrors('sort');
    }
}
