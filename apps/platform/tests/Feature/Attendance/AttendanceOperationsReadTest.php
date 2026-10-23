<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-05: register status for a School-local date
 * (submitted / pending / not submitted -- never "missing": Lycenza records no
 * Student holidays) and one Student's records over a range, both under
 * `attendance.view` and the actor's CampusScope, read-only.
 */
class AttendanceOperationsReadTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, array<string, mixed>> */
    private array $at = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Everything exists on the Monday itself (10:00 school time = 04:30 UTC):
        // a class created after a day was not on that day's timetable.
        $this->travelTo(CarbonImmutable::parse('2026-08-31T04:30:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $grade = $this->createGradeLevel($this->school);
        $first = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $second = $this->createTimetablePeriod($this->school, ['start_time' => '10:00:00', 'end_time' => '11:00:00']);
        $this->admin = $this->fullAttendanceActor($this->school);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'S'.strtoupper($key)]);
            $teacher = $this->createEmployee($this->school, ['record_status' => 'active']);
            $entry = $this->createTimetableEntry($offering, $section, $teacher, $first, ['day_of_week' => 1, 'status' => 'active']);
            $later = $this->createTimetableEntry($offering, $section, $teacher, $second, ['day_of_week' => 1, 'status' => 'active']);
            $enrollment = $this->enrollStudent($section, '1', '2026-06-01');
            $session = $this->inSchool($this->school, fn () => app(AttendanceSubmissionService::class)->guarded(fn () => DB::transaction(fn () => app(AttendanceSubmissionService::class)
                ->submit($this->school, $entry->id, self::MONDAY, $this->registerPayload([$enrollment->id => 'absent']), $this->admin))));
            $record = $this->inSchool($this->school, fn () => AttendanceRecord::query()->where('attendance_session_id', $session->id)->firstOrFail());
            $this->at[$key] = compact('campus', 'offering', 'section', 'teacher', 'entry', 'later', 'enrollment', 'session', 'record', 'grade');
        }
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function principal(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'principal', $campusId);
        }

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    /** @return array<string, mixed> */
    private function registers(User $user, string $query = ''): array
    {
        $this->signIn($user);

        return $this->get('/app/attendance/registers'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, string> status by timetable entry id */
    private function statuses(array $props): array
    {
        return collect($props['rows']['data'])->pluck('status', 'timetableEntryId')->all();
    }

    private function entry(string $key, string $which = 'entry'): string
    {
        return (string) $this->at[$key][$which]->id;
    }

    #[Test]
    public function a_past_day_shows_each_timetabled_class_as_submitted_or_not_submitted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $props = $this->registers($this->admin, 'date='.self::MONDAY);

        $this->assertTrue($props['applicable']);
        $this->assertEquals([
            $this->entry('a') => 'submitted', $this->entry('a', 'later') => 'not_submitted',
            $this->entry('b') => 'submitted', $this->entry('b', 'later') => 'not_submitted',
        ], $this->statuses($props));
        $this->assertSame(['submitted' => 2, 'pending' => 0, 'not_submitted' => 2], $props['counts'], 'Registers, never people.');
        $this->assertSame([], $this->statuses($this->registers($this->admin, 'date=2026-09-01')), 'A Tuesday: no class meets.');
    }

    #[Test]
    public function today_a_period_that_has_not_ended_is_pending_never_not_submitted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31T04:15:00Z')); // 09:45 school time
        $this->assertEquals([
            $this->entry('a') => 'submitted', $this->entry('a', 'later') => 'pending',
            $this->entry('b') => 'submitted', $this->entry('b', 'later') => 'pending',
        ], $this->statuses($this->registers($this->admin)), 'The 10:00-11:00 classes have not started.');
        $this->travelTo(CarbonImmutable::parse('2026-08-31T05:29:59Z')); // 10:59:59
        $this->assertSame('pending', $this->statuses($this->registers($this->admin))[$this->entry('a', 'later')]);
        $this->travelTo(CarbonImmutable::parse('2026-08-31T05:30:00Z')); // 11:00:00
        $this->assertSame('not_submitted', $this->statuses($this->registers($this->admin))[$this->entry('a', 'later')]);
    }

    #[Test]
    public function a_future_day_is_refused_and_a_day_outside_the_active_year_has_no_register_work(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $this->signIn($this->admin);
        $this->get('/app/attendance/registers?date=2026-09-03')->assertSessionHasErrors('date');
        $this->get('/app/attendance/registers?date=31-08-2026')->assertSessionHasErrors('date');
        $outside = $this->registers($this->admin, 'date=2026-05-25'); // a Monday before the year
        $this->assertFalse($outside['applicable']);
        $this->assertSame([], $outside['rows']['data']);
    }

    #[Test]
    public function a_class_created_after_the_day_was_not_on_its_timetable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07T04:30:00Z')); // the next Monday
        $newer = $this->createTimetableEntry($this->at['a']['offering'], $this->at['a']['section'], $this->at['a']['teacher'], $this->createTimetablePeriod($this->school, ['start_time' => '12:00:00', 'end_time' => '13:00:00']), ['day_of_week' => 1, 'status' => 'active']);
        $this->travelTo(CarbonImmutable::parse('2026-09-08T06:00:00Z'));

        $this->assertArrayNotHasKey((string) $newer->id, $this->statuses($this->registers($this->admin, 'date='.self::MONDAY)));
        $this->assertSame('not_submitted', $this->statuses($this->registers($this->admin, 'date=2026-09-07'))[(string) $newer->id]);
    }

    #[Test]
    public function a_campus_reader_sees_and_counts_only_its_campus(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $north = $this->registers($this->principal([$this->a->id]), 'date='.self::MONDAY);
        $this->assertEquals([$this->entry('a') => 'submitted', $this->entry('a', 'later') => 'not_submitted'], $this->statuses($north));
        $this->assertSame(['submitted' => 1, 'pending' => 0, 'not_submitted' => 1], $north['counts'], 'No South register leaks into the counts.');
        $this->assertSame([(string) $this->at['a']['section']->id], array_column($north['options']['sections'], 'id'));

        $foreign = $this->registers($this->principal([$this->a->id]), 'date='.self::MONDAY.'&section_id='.$this->at['b']['section']->id);
        $this->assertSame([], $foreign['rows']['data'], 'A filter on another Campus\'s Section only narrows.');
        $this->assertSame(['submitted' => 0, 'pending' => 0, 'not_submitted' => 0], $foreign['counts']);
    }

    #[Test]
    public function filters_narrow_by_grade_section_and_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $base = 'date='.self::MONDAY;
        $this->assertEquals([$this->entry('b') => 'submitted', $this->entry('b', 'later') => 'not_submitted'], $this->statuses($this->registers($this->admin, $base.'&section_id='.$this->at['b']['section']->id)));
        $this->assertCount(4, $this->statuses($this->registers($this->admin, $base.'&teacher_id='.$this->at['a']['teacher']->id)), 'No teacher filter: Attendance never filters by a teacher column (an unknown parameter is ignored).');
        $this->assertCount(4, $this->statuses($this->registers($this->admin, $base.'&grade_level_id='.$this->at['a']['grade']->id)));
        $onlyMissing = $this->registers($this->admin, $base.'&status=not_submitted');
        $this->assertEqualsCanonicalizing([$this->entry('a', 'later'), $this->entry('b', 'later')], array_keys($this->statuses($onlyMissing)));
        $this->assertSame(['submitted' => 2, 'pending' => 0, 'not_submitted' => 2], $onlyMissing['counts'], 'The counts describe the day under the other filters.');
        $this->signIn($this->admin);
        $this->get('/app/attendance/registers?status=missing')->assertSessionHasErrors('status');
    }

    #[Test]
    public function only_attendance_view_opens_it_and_another_school_never_appears(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $teacherOnly = $this->createUserWithCapabilities($this->school, ['attendance.teacher']);
        $this->signIn($teacherOnly);
        $this->get('/app/attendance/registers')->assertForbidden();
        $this->get('/app/attendance/students/'.$this->at['a']['enrollment']->student_id)->assertForbidden();

        $other = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createAcademicYear($other, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $otherActor = $this->fullAttendanceActor($other);
        $this->actingAs($otherActor)->post("/app/schools/{$other->id}/activate");
        $props = $this->get('/app/attendance/registers?date='.self::MONDAY)->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['rows']['data'], 'Another School\'s timetable never appears.');
        $this->assertSame(['submitted' => 0, 'pending' => 0, 'not_submitted' => 0], $props['counts']);
        $this->get('/app/attendance/students/'.$this->at['a']['enrollment']->student_id)->assertNotFound();
    }

    #[Test]
    public function one_students_history_shows_records_and_corrections_with_their_closed_reason(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $this->signIn($this->admin);
        $this->post("/app/attendance/records/{$this->at['a']['record']->id}/correct", ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'late_information'])->assertRedirect();
        $student = $this->at['a']['enrollment']->student_id;

        $props = $this->get("/app/attendance/students/{$student}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['from' => '2026-08-04', 'to' => '2026-09-02'], $props['filters'], 'Default: the last 30 School-local days.');
        $row = $props['rows']['data'][0];
        $this->assertSame([self::MONDAY, 'present', true, 'absent', 'late_information'], [$row['date'], $row['status'], $row['corrected'], $row['originalStatus'], $row['correctionReason']]);
        $this->assertSame(['id', 'sessionId', 'date', 'classLabel', 'subjectName', 'periodName', 'periodStartTime', 'status', 'corrected', 'correctionReason', 'originalStatus', 'correctedAt'], array_keys($row), 'No free text, no health detail, no percentage.');
        $this->assertArrayNotHasKey('rate', $props);

        $this->assertSame([], $this->get("/app/attendance/students/{$student}?from=2026-09-01&to=2026-09-02")->assertOk()->viewData('page')['props']['rows']['data']);
        $this->get("/app/attendance/students/{$student}?from=2026-09-02&to=2026-08-01")->assertSessionHasErrors('from');
        $this->get("/app/attendance/students/{$student}?from=2025-08-01&to=2026-09-02")->assertSessionHasErrors('from');
        $this->get("/app/attendance/students/{$student}?to=2026-09-03")->assertSessionHasErrors('to');
    }

    #[Test]
    public function a_campus_reader_reaches_a_student_only_through_an_in_scope_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $studentA = $this->at['a']['enrollment']->student_id;

        $this->signIn($this->principal([$this->b->id]));
        $this->get("/app/attendance/students/{$studentA}")->assertNotFound();
        $this->get('/app/attendance/students/'.fake()->uuid())->assertNotFound();
        $this->get('/app/attendance/students/not-a-uuid')->assertNotFound();

        $this->signIn($this->principal([$this->a->id]));
        $this->assertCount(1, $this->get("/app/attendance/students/{$studentA}")->assertOk()->viewData('page')['props']['rows']['data']);
    }

    #[Test]
    public function a_history_read_is_audited_once_and_writes_nothing_else(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T06:00:00Z'));
        $before = $this->inSchool($this->school, fn () => [AttendanceSession::query()->count(), AttendanceRecord::query()->count()]);
        $this->registers($this->admin, 'date='.self::MONDAY);
        $this->get('/app/attendance/students/'.$this->at['b']['enrollment']->student_id.'?from=2026-08-01&to=2026-09-02')->assertOk();

        $this->assertSame($before, $this->inSchool($this->school, fn () => [AttendanceSession::query()->count(), AttendanceRecord::query()->count()]));
        $event = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'attendance.student_history_viewed')->sole());
        $this->assertSame((string) $this->at['b']['enrollment']->student_id, (string) $event->subject_id);
        $this->assertEqualsCanonicalizing(['from' => '2026-08-01', 'to' => '2026-09-02', 'resultCount' => 1], $event->metadata);
    }
}
