<?php

namespace Tests\Feature\Attendance;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Reports\AttendanceCorrectionReport;
use App\Domain\Attendance\Application\Reports\AttendanceRegisterHistoryReport;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\StudentReports\StudentReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * SIS 11.10: the governed Attendance reports -- register history (class,
 * subject/period, recorded by) and corrections -- plus the Student history
 * filters. Authoritative records only, Campus scope in SQL, Teachers never
 * reach them, another School never shows, closed filters, bounded queries,
 * no rate or percentage, browser-only.
 */
class AttendanceReportsTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private User $admin;

    /** @var array<string, array<string, mixed>> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 10:00 School time (Asia/Kolkata) = 04:30 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-08-31T04:30:00Z'));
        [$this->school, $this->admin, $this->at] = $this->world('North Campus', 'South Campus');
        $this->a = $this->at['a']['campus'];
        $this->b = $this->at['b']['campus'];
    }

    /** @return array{0: School, 1: User, 2: array<string, array<string, mixed>>} */
    private function world(string $first, string $second): array
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $year = $this->createAcademicYear($school, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $grade = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $admin = $this->fullAttendanceActor($school);
        $at = [];
        foreach (['a' => $first, 'b' => $second] as $key => $name) {
            $campus = $this->createCampus($school, ['name' => $name]);
            $subject = $this->createSubject($school, ['name' => $key === 'a' ? 'Mathematics' : 'Science', 'code' => $key === 'a' ? 'MATH' : 'SCI']);
            $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'name' => 'A', 'code' => 'S'.strtoupper($key)]);
            $teacher = $this->createEmployedEmployee($school, ['record_status' => 'active']);
            $entry = $this->createTimetableEntry($offering, $section, $teacher, $period, ['day_of_week' => 1, 'status' => 'active']);
            $enrollment = $this->enrollStudent($section, '1', '2026-06-01');
            $session = $this->submit($school, $entry->id, self::MONDAY, [$enrollment->id => 'absent'], $admin);
            $record = $this->inSchool($school, fn () => AttendanceRecord::query()->where('attendance_session_id', $session->id)->firstOrFail());
            $at[$key] = compact('campus', 'subject', 'offering', 'section', 'entry', 'enrollment', 'session', 'record', 'grade', 'year');
        }

        return [$school, $admin, $at];
    }

    /** @param  array<string, string>  $marks */
    private function submit(School $school, string $entryId, string $date, array $marks, User $actor): mixed
    {
        return $this->inSchool($school, fn () => app(AttendanceSubmissionService::class)->guarded(fn () => DB::transaction(fn () => app(AttendanceSubmissionService::class)
            ->submit($school, $entryId, $date, $this->registerPayload($marks), $actor))));
    }

    private function as(User $user, ?School $school = null): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    private function role(string $key, ?string $campusId = null, ?School $school = null): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school ?? $this->school), $key, $campusId);

        return $user;
    }

    /** @return array<string, mixed> */
    private function report(string $key, array $query = [], ?User $as = null, ?School $school = null): array
    {
        return $this->as($as ?? $this->admin, $school)->get("/app/students/reports/{$key}?".http_build_query($query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> "date|subject|campus|corrected" */
    private function registers(array $query = [], ?User $as = null): array
    {
        return array_map(fn (array $r) => "{$r['date']}|{$r['subject']}|{$r['class']['campus']}|{$r['corrected']}", $this->report('attendance.registers', $query, $as)['rows']['data']);
    }

    private function correctA(): void
    {
        $this->as($this->admin)->post("/app/attendance/records/{$this->at['a']['record']->id}/correct", ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'late_information'])->assertRedirect();
    }

    #[Test]
    public function the_attendance_reports_are_governed_rendered_and_browser_only(): void
    {
        $this->assertContains(AttendanceRegisterHistoryReport::class, StudentReportRegistry::RENDERED);
        $this->assertContains(AttendanceCorrectionReport::class, StudentReportRegistry::RENDERED);
        $definitions = app(StudentReportRegistry::class)->definitions();
        foreach (['attendance.registers', 'attendance.corrections', 'attendance.register_status', 'attendance.exceptions'] as $key) {
            $this->assertSame(['attendance', 'Attendance', ['attendance.view']], [$definitions[$key]->category, $definitions[$key]->owner, $definitions[$key]->authority]);
            $this->assertArrayNotHasKey($key, StudentReportRegistry::EXPORTERS, 'browser-only (SIS 11.10 §45)');
        }
        foreach (['attendance.registers', 'attendance.corrections'] as $key) {
            $this->as($this->admin)->post("/app/students/reports/{$key}/export", ['format' => 'csv', 'columns' => ['date']])->assertNotFound();
            $this->as($this->admin)->get("/app/students/reports/{$key}/print?columns[]=date")->assertNotFound();
        }
        $hub = collect($this->as($this->admin)->get('/app/students/reports')->viewData('page')['props']['categories'])->firstWhere('key', 'attendance');
        $this->assertSame(['attendance.register_status', 'attendance.exceptions', 'attendance.registers', 'attendance.corrections'], array_column($hub['reports'], 'key'));
    }

    #[Test]
    public function register_history_shows_each_submitted_register_with_who_recorded_it(): void
    {
        $row = collect($this->report('attendance.registers')['rows']['data'])->firstWhere('subject', 'Mathematics');
        $this->assertSame(self::MONDAY, $row['date']);
        $this->assertSame('09:00–10:00', $row['period']);
        $this->assertSame(['academicYear' => $this->at['a']['year']->name, 'campus' => 'North Campus', 'gradeLevel' => 'Grade 5', 'section' => 'A'], $row['class']);
        $this->assertSame($this->admin->name, $row['recorded_by'], 'the submitting operator, by name (OD-SIS-10A)');
        $this->assertSame('31 Aug 2026, 10:00', $row['recorded_at'], 'the School-local instant, never UTC');
        $this->assertSame(['label' => 'Open register', 'url' => '/app/attendance/'.$this->at['a']['session']->id], $row['register']);
        $this->assertSame(['key', 'date', 'period', 'class', 'subject', 'recorded_by', 'recorded_at', 'corrected', 'register'], array_keys($row), 'no count of Students, rate, percentage or internal id');

        $this->correctA();
        $this->assertSame([self::MONDAY.'|Mathematics|North Campus|Yes', self::MONDAY.'|Science|South Campus|No'], $this->registers(['sort' => 'subject']));
        $this->assertSame([self::MONDAY.'|Mathematics|North Campus|Yes'], $this->registers(['corrected' => 'yes']));
        $this->assertSame([self::MONDAY.'|Science|South Campus|No'], $this->registers(['corrected' => 'no']));
        $this->assertSame([self::MONDAY.'|Science|South Campus|No'], $this->registers(['subject_id' => $this->at['b']['subject']->id]));
        $this->assertSame([self::MONDAY.'|Mathematics|North Campus|Yes'], $this->registers(['section_id' => $this->at['a']['section']->id]));
        $this->assertCount(2, $this->registers(['recorded_by' => $this->admin->id, 'academic_year_id' => $this->at['a']['year']->id, 'from' => self::MONDAY, 'to' => self::MONDAY]));
        $this->assertSame([], $this->registers(['from' => '2026-09-01']), 'School-local dates compare as dates');
        $this->assertSame([], $this->registers(['to' => '2026-08-30']));
    }

    #[Test]
    public function register_history_spans_dates_and_paginates_without_growing_queries(): void
    {
        $count = function (): int {
            $this->as($this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/students/reports/attendance.registers')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $small = $count();
        foreach (['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24'] as $monday) {
            foreach (['a', 'b'] as $key) {
                $this->submit($this->school, $this->at[$key]['entry']->id, $monday, [$this->at[$key]['enrollment']->id => 'present'], $this->admin);
            }
        }
        $this->assertLessThanOrEqual($small, $count(), 'no N+1 across 10 registers on 5 dates');
        $this->assertCount(4, $this->registers(['from' => '2026-08-17', 'to' => '2026-08-24']));
        $this->assertSame(['2026-08-03', '2026-08-03'], array_slice(array_map(fn ($r) => explode('|', $r)[0], $this->registers(['sort' => 'oldest'])), 0, 2));
    }

    #[Test]
    public function corrections_are_listed_from_the_append_only_rows_with_reason_and_corrector(): void
    {
        $this->assertSame([], $this->report('attendance.corrections')['rows']['data']);
        $this->correctA();

        $rows = $this->report('attendance.corrections')['rows']['data'];
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Absent → Present', $row['change']);
        $this->assertSame('Later information', $row['reason']);
        $this->assertSame($this->admin->name, $row['corrected_by']);
        $this->assertSame([self::MONDAY, 'Mathematics', '/app/attendance/'.$this->at['a']['session']->id], [$row['date'], $row['subject'], $row['register']['url']]);
        $this->assertSame('/app/attendance/students/'.$this->at['a']['enrollment']->student_id, $row['student']['url'], 'drill-down to the audited Student history');
        $this->assertSame(['key', 'student', 'date', 'period', 'class', 'subject', 'change', 'reason', 'corrected_by', 'corrected_at', 'register'], array_keys($row));

        $this->assertCount(1, $this->report('attendance.corrections', ['reason' => 'late_information'])['rows']['data']);
        $this->assertCount(0, $this->report('attendance.corrections', ['reason' => 'entered_in_error'])['rows']['data']);
        $this->assertCount(1, $this->report('attendance.corrections', ['q' => $row['student']['studentNumber']])['rows']['data']);
        $this->assertCount(0, $this->report('attendance.corrections', ['campus_id' => $this->b->id])['rows']['data']);
        $this->as($this->admin)->get('/app/students/reports/attendance.corrections?reason=sick_note')->assertSessionHasErrors('reason');
    }

    #[Test]
    public function a_campus_reader_sees_only_their_campus_and_a_forged_filter_never_widens_it(): void
    {
        $this->correctA();
        $north = $this->role('principal', $this->a->id);

        $this->assertSame([self::MONDAY.'|Mathematics|North Campus|Yes'], $this->registers([], $north));
        $this->assertSame([], $this->registers(['campus_id' => $this->b->id], $north), 'a foreign Campus filter matches nothing');
        $this->assertSame([], $this->registers(['section_id' => $this->at['b']['section']->id], $north));
        $options = collect($this->report('attendance.registers', [], $north)['filters'])->keyBy('key');
        $this->assertSame(['North Campus'], array_column($options['campus_id']['options'], 'label'));
        $this->assertSame(['Mathematics'], array_column($options['subject_id']['options'], 'label'), 'options are cut to the viewer');
        $this->assertCount(1, $this->report('attendance.corrections', [], $north)['rows']['data']);

        $south = $this->role('principal', $this->b->id);
        $this->assertSame([], $this->report('attendance.corrections', [], $south)['rows']['data'], 'another Campus\'s corrections never show');
        $this->assertSame([self::MONDAY.'|Science|South Campus|No'], $this->registers([], $south));
    }

    #[Test]
    public function teachers_and_non_attendance_roles_never_reach_the_reports(): void
    {
        foreach (['teacher', 'librarian'] as $key) {
            $user = $this->role($key);
            foreach (['attendance.registers', 'attendance.corrections'] as $report) {
                $this->as($user)->get("/app/students/reports/{$report}")->assertForbidden();
            }
            $this->as($user)->get('/app/attendance/registers')->assertForbidden();
            $this->as($user)->get('/app/attendance/students/'.$this->at['a']['enrollment']->student_id)->assertForbidden();
        }

        // A recorded-by choice outside the viewer's registers is refused, not widened.
        $stranger = $this->createUser();
        $this->as($this->admin)->get('/app/students/reports/attendance.registers?recorded_by='.$stranger->id)->assertSessionHasErrors('recorded_by');
        $this->as($this->admin)->get('/app/students/reports/attendance.registers?section_id=not-a-uuid')->assertSessionHasErrors('section_id');
        $this->as($this->admin)->get('/app/students/reports/attendance.registers?from=2026-08-31&to=2026-08-01')->assertSessionHasErrors('to');
    }

    #[Test]
    public function a_teacher_who_owns_a_register_gains_no_report_or_other_class(): void
    {
        // A real owner: Teacher role, linked active Employee with an open
        // employment, and a current teaching assignment for class A.
        $user = $this->role('teacher');
        $employee = $this->createEmployee($this->school, ['user_id' => $user->id]);
        $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active']);
        $assigner = $this->role('school_admin');
        $this->inSchool($this->school, fn () => app(TeachingAssignmentService::class)->create(
            $this->school, $employee->id, $this->at['a']['section']->id, $this->at['a']['offering']->id, '2026-06-01', null, $assigner));

        // Ownership of class A is not office authority: no report, no register
        // status, no Student history -- for the owned class or any other.
        foreach (['attendance.registers', 'attendance.corrections'] as $report) {
            $this->as($user)->get("/app/students/reports/{$report}")->assertForbidden();
            $this->as($user)->get("/app/students/reports/{$report}?section_id=".$this->at['a']['section']->id)->assertForbidden();
        }
        $this->as($user)->get('/app/students/reports')->assertForbidden();
        $this->as($user)->get('/app/attendance/registers')->assertForbidden();
        $this->as($user)->get('/app/attendance/exceptions')->assertForbidden();
        foreach (['a', 'b'] as $key) {
            $this->as($user)->get('/app/attendance/'.$this->at[$key]['session']->id)->assertForbidden();
            $this->as($user)->get('/app/attendance/students/'.$this->at[$key]['enrollment']->student_id)->assertForbidden();
        }
    }

    #[Test]
    public function a_closed_year_stays_reportable_and_is_never_forced_to_the_current_one(): void
    {
        $this->correctA();
        $year = $this->at['a']['year'];
        $this->inSchool($this->school, fn () => app(AcademicYearService::class)->close($year->fresh(), $this->admin));

        $options = collect($this->report('attendance.registers')['filters'])->keyBy('key');
        $this->assertSame([$year->name.' (closed)'], array_column($options['academic_year_id']['options'], 'label'));
        $this->assertCount(2, $this->registers(['academic_year_id' => $year->id]), 'a closed year is read, not hidden');
        $this->assertCount(1, $this->report('attendance.corrections', ['academic_year_id' => $year->id])['rows']['data']);
        $history = $this->as($this->admin)->get('/app/attendance/students/'.$this->at['a']['enrollment']->student_id.'?from=2026-08-31&to=2026-08-31')->assertOk()->viewData('page')['props'];
        $this->assertSame(['present'], array_column($history['rows']['data'], 'status'), 'the corrected record, read in its closed year');
    }

    #[Test]
    public function another_school_with_the_same_shapes_never_shows(): void
    {
        $this->correctA();
        [$other, $otherAdmin, $otherAt] = $this->world('North Campus', 'South Campus');

        $this->assertCount(2, $this->registers());
        $this->assertSame([], $this->registers(['section_id' => $otherAt['a']['section']->id]), 'a foreign Section id matches nothing');
        $this->assertSame([], $this->registers(['subject_id' => $otherAt['a']['subject']->id]));
        $theirs = $this->report('attendance.registers', [], $otherAdmin, $other)['rows']['data'];
        $this->assertCount(2, $theirs);
        $this->assertSame(['No', 'No'], array_column($theirs, 'corrected'), 'our correction never leaks into their rows');
        $this->assertSame([], $this->report('attendance.corrections', [], $otherAdmin, $other)['rows']['data']);
        $this->assertSame([$otherAdmin->name], array_column(collect($this->report('attendance.registers', [], $otherAdmin, $other)['filters'])->firstWhere('key', 'recorded_by')['options'], 'label'));
    }

    #[Test]
    public function the_report_read_models_carry_no_rate_tally_teacher_filter_or_write(): void
    {
        foreach (['AttendanceRegisterHistoryReport', 'AttendanceCorrectionReport'] as $class) {
            $code = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(app_path("Domain/Attendance/Application/Reports/{$class}.php")));
            $this->assertDoesNotMatchRegularExpression('/percent|\brate\b|avg\(|count\(|sum\(/i', $code, "{$class}: no rate, average or tally (ADR 0040)");
            $this->assertDoesNotMatchRegularExpression('/teacher_id|employees/', $code, "{$class}: never a teacher column or HR table");
            $this->assertDoesNotMatchRegularExpression('/->(insert|update|delete|save|forceFill)\(/', $code, "{$class}: read-only");
            $this->assertStringContainsString('$this->access->', $code, "{$class}: Campus scope only through AttendanceAdministrationAccess");
            foreach (['gender', 'date_of_birth', 'religion', 'caste', 'guardian'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$class}: no demographic or contact data");
            }
        }
    }

    #[Test]
    public function the_student_history_narrows_by_status_and_subject(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $student = $this->at['a']['enrollment']->student_id;
        $this->submit($this->school, $this->at['a']['entry']->id, '2026-08-24', [$this->at['a']['enrollment']->id => 'present'], $this->admin);
        $history = fn (array $query) => $this->as($this->admin)->get("/app/attendance/students/{$student}?".http_build_query($query))->assertOk()->viewData('page')['props'];

        $this->assertSame(['absent', 'present'], array_column($history([])['rows']['data'], 'status'));
        $this->assertSame(['absent'], array_column($history(['status' => 'absent'])['rows']['data'], 'status'));
        $this->assertSame([], $history(['subject_id' => $this->at['b']['subject']->id])['rows']['data']);
        $this->assertCount(2, $history(['subject_id' => $this->at['a']['subject']->id])['rows']['data']);
        $this->as($this->admin)->get("/app/attendance/students/{$student}?status=sick")->assertSessionHasErrors('status');
        $this->as($this->admin)->get("/app/attendance/students/{$student}?subject_id=x")->assertSessionHasErrors('subject_id');
    }
}
