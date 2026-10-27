<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Application\Exceptions\AttendanceDateInFutureException;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Application\StudentEnrollmentRosterReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * SIS 6.1: Attendance operator safety.
 *
 * - ATT-09: no register for a future School-local date -- the Take page
 *   offers none (and says why), and the submission service refuses a forged
 *   one with nothing written, decided by the School's own date.
 * - The register page names the class as "Grade · Section"; each row links
 *   to the Student's history; Registers rows open their register or Take.
 * - ATT-07 exposure: a current in-scope Student with no record yet has an
 *   empty history, never a 404; anyone else stays the unknown 404.
 * - ADR 0078: a placement cancelled before it started is never on a roster.
 */
class AttendanceOperatorSafetyTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private function as(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function sessions(School $school): int
    {
        return $this->inSchool($school, fn () => AttendanceSession::query()->count());
    }

    private function submit(array $w, string $date, array $statuses): AttendanceSession
    {
        $service = app(AttendanceSubmissionService::class);

        return $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(
            fn () => $service->submit($w['school'], $w['entry']->id, $date, $this->registerPayload($statuses), $w['actor']),
        )));
    }

    #[Test]
    public function a_future_date_offers_no_register_and_a_forged_one_writes_nothing(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $today = SchoolTimezone::today($w['school']);
        $future = CarbonImmutable::parse($today)->next(CarbonInterface::MONDAY)->toDateString();

        $this->as($w)->get("/app/attendance/take?attendance_date={$future}&timetable_entry_id={$w['entry']->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('futureDate', true)->where('today', $today)
                ->has('classes', 0)->where('selectedTimetableEntryId', null)->has('roster', 0));
        $this->as($w)->get('/app/attendance/take?attendance_date='.self::MONDAY)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('futureDate', false)->has('classes', 1));

        $before = $this->sessions($w['school']);
        $this->as($w)->post('/app/attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => $future, 'records' => $this->registerPayload([$a->id => 'present']),
        ])->assertSessionHasErrors(['timetable_entry_id' => (new AttendanceDateInFutureException($future))->getMessage()]);
        $this->assertSame($before, $this->sessions($w['school']));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()));
        $this->assertStringNotContainsString($future, (new AttendanceDateInFutureException($future))->getMessage(), 'a plain sentence, no raw value');
    }

    #[Test]
    public function the_school_local_date_decides_never_the_utc_date(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');

        // West of UTC: 02:00 UTC on Monday 8 June is still Sunday 7 June in New York.
        $w['school']->forceFill(['timezone' => 'America/New_York'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-06-08 02:00:00', 'UTC'));
        $this->assertThrows(fn () => $this->submit($w, '2026-06-08', [$a->id => 'present']), AttendanceDateInFutureException::class);
        $this->assertSame(0, $this->sessions($w['school']));

        // East of UTC: 20:00 UTC on Sunday 7 June is already Monday 8 June in Kolkata.
        $w['school']->forceFill(['timezone' => 'Asia/Kolkata'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-06-07 20:00:00', 'UTC'));
        $this->submit($w, '2026-06-08', [$a->id => 'present']);
        $this->assertSame(1, $this->sessions($w['school']));
    }

    #[Test]
    public function the_register_registers_and_student_pages_link_up(): void
    {
        $w = $this->attendanceWorld();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        // Registers lists the classes that were on the timetable that day.
        $this->inSchool($w['school'], fn () => TimetableEntry::query()->whereKey($w['entry']->id)->update(['created_at' => '2026-06-01 00:00:00']));
        $day = self::MONDAY;
        $session = $this->submit($w, $day, [$a->id => 'absent']);

        $this->as($w)->get("/app/attendance/{$session->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.classLabel', "{$w['grade']->name} · {$w['section']->name}")
                ->where('session.records.0.studentId', $a->student_id));

        $this->as($w)->get("/app/attendance/registers?date={$day}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canTake', true)->where('rows.data.0.sessionId', $session->id));
        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);
        $this->as($w, $viewer)->get("/app/attendance/registers?date={$day}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canTake', false)->where('rows.data.0.sessionId', $session->id));

        $staff = $this->createUserWithCapabilities($w['school'], ['students.view', 'attendance.view']);
        $this->as($w, $staff)->get("/app/students/{$a->student_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewAttendance', true));
        $noAttendance = $this->createUserWithCapabilities($w['school'], ['students.view']);
        $this->as($w, $noAttendance)->get("/app/students/{$a->student_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewAttendance', false));
    }

    #[Test]
    public function a_current_student_with_no_record_has_an_empty_history_and_others_stay_404(): void
    {
        $w = $this->attendanceWorld();
        $fresh = $this->enrollStudent($w['section'], '7', '2026-06-01');
        $viewer = $this->createUserWithCapabilities($w['school'], ['attendance.view']);
        $audits = fn () => $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'attendance.student_history_viewed')->count());

        $this->as($w, $viewer)->get("/app/attendance/students/{$fresh->student_id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('student.id', $fresh->student_id)->has('rows.data', 0));
        $this->assertSame(1, $audits(), 'the empty read is audited like any other');

        $leaver = $this->createStudentFor($w['school'], ['status' => 'inactive']);
        $other = $this->attendanceWorld();
        $foreign = $this->enrollStudent($other['section'], '1', '2026-06-01');
        foreach ([$leaver->id, $foreign->student_id, 'not-a-uuid'] as $id) {
            $this->as($w, $viewer)->get("/app/attendance/students/{$id}")->assertNotFound();
        }
        $this->as($w, $this->createUserWithCapabilities($w['school'], ['students.view']))->get("/app/attendance/students/{$fresh->student_id}")->assertForbidden();
    }

    #[Test]
    public function a_placement_cancelled_before_it_started_is_never_on_a_roster(): void
    {
        $w = $this->attendanceWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $start = CarbonImmutable::parse(SchoolTimezone::today($w['school']))->next(CarbonInterface::MONDAY)->toDateString();
        $staying = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $cancelled = app(StudentEnrollmentService::class)->cancelBeforeStart(
            $this->enrollStudent($w['section'], '2', $start), 'did_not_join', $admin, new FreshMfaProof($admin->id, CarbonImmutable::now()),
        );

        foreach ([self::MONDAY, $start, CarbonImmutable::parse($start)->addWeek()->toDateString()] as $date) {
            $members = $this->inSchool($w['school'], fn () => app(StudentEnrollmentRosterReadService::class)
                ->membersAsOf($w['school']->id, $w['year']->id, $w['campus']->id, $w['grade']->id, $w['section']->id, $date));
            $ids = $members->map(fn ($m) => $m->toArray()['studentEnrollmentId'])->all();
            $this->assertNotContains($cancelled->id, $ids, "never on the {$date} roster");
            $this->assertContains($staying->id, $ids);
        }
    }
}
