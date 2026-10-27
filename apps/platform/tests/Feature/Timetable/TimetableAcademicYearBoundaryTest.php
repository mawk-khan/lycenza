<?php

namespace Tests\Feature\Timetable;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Timetable\Application\Exceptions\RoomAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\TeacherAlreadyScheduledException;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * LC-2 (Local Product Completion M2; owner decision 2026-10-17): timetable
 * collisions are per School AND academic year. Next year's timetable can be
 * prepared with the same teachers and rooms while this year's stays active;
 * a same-year double-booking is still impossible (the database indexes are
 * the guarantee). Today's register lists show the active year only. A class
 * or Period can be corrected, and a deactivated one found and reactivated,
 * in the browser.
 */
class TimetableAcademicYearBoundaryTest extends TestCase
{
    use CreatesTimetableFixtures;

    private const MONDAY = '2026-11-02';

    /**
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, [
            'timetable.periods.view', 'timetable.periods.manage', 'timetable.schedule.view', 'timetable.schedule.manage',
            'attendance.view', 'attendance.manage',
        ]);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $current = $this->createAcademicYear($school, ['code' => 'LC2-CUR', 'name' => '2026-27', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $next = $this->createAcademicYear($school, ['code' => 'LC2-NXT', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);

        return [
            'school' => $school,
            'actor' => $actor,
            'campus' => $campus,
            'grade' => $grade,
            'current' => $current,
            'next' => $next,
            'currentSection' => $this->createSection($current, $campus, $grade, ['name' => 'A', 'code' => 'A', 'status' => 'active']),
            'nextSection' => $this->createSection($next, $campus, $grade, ['name' => 'A', 'code' => 'A', 'status' => 'active']),
            'currentOffering' => $this->createSubjectOffering($current, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']),
            'nextOffering' => $this->createSubjectOffering($next, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']),
            'teacher' => $this->createEmployee($school, ['record_status' => 'active']),
            'otherTeacher' => $this->createEmployee($school, ['record_status' => 'active']),
            'room' => $this->createRoom($campus, ['status' => 'active']),
            'p1' => $this->createTimetablePeriod($school, ['code' => 'P1', 'start_time' => '09:00:00', 'end_time' => '09:45:00']),
            'p2' => $this->createTimetablePeriod($school, ['code' => 'P2', 'start_time' => '10:00:00', 'end_time' => '10:45:00']),
        ];
    }

    private function signedIn(User $user, School $school): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $this;
    }

    private function inSchool(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function refusal(): string
    {
        $errors = session('errors');
        $messages = $errors instanceof ViewErrorBag
            ? $errors->getBag('default')->get('refusal')
            : ($errors['default']['messages']['refusal'] ?? []);
        $this->assertNotEmpty($messages, 'a refusal is shown');

        return (string) $messages[0];
    }

    #[Test]
    public function the_slot_indexes_are_keyed_by_academic_year(): void
    {
        $defs = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'timetable_entries' AND indexname LIKE '%slot_unique'"))
            ->pluck('indexdef', 'indexname');

        $this->assertStringContainsString('(school_id, academic_year_id, teacher_id, day_of_week, period_id)', $defs['timetable_entries_teacher_slot_unique']);
        $this->assertStringContainsString('(school_id, academic_year_id, room_id, day_of_week, period_id)', $defs['timetable_entries_room_slot_unique']);
        $this->assertStringContainsString('(school_id, section_id, day_of_week, period_id)', $defs['timetable_entries_section_slot_unique']);
        foreach ($defs as $def) {
            $this->assertStringContainsString("(status)::text = 'active'::text", $def, 'still only active classes collide');
        }
        $this->assertStringContainsString('room_id IS NOT NULL', $defs['timetable_entries_room_slot_unique']);
    }

    #[Test]
    public function a_teacher_and_a_room_may_hold_the_same_slot_in_another_year_but_never_twice_in_one_year(): void
    {
        $w = $this->world();
        $service = app(TimetableScheduleService::class);

        $service->create($w['school'], $w['currentOffering'], $w['currentSection'], $w['teacher'], $w['room'], $w['p1'], 1, $w['actor']);

        // Next year, same teacher, same room, same Monday Period 1: allowed.
        $next = $service->create($w['school'], $w['nextOffering'], $w['nextSection'], $w['teacher'], $w['room'], $w['p1'], 1, $w['actor']);
        $this->assertSame($w['next']->id, $next->academic_year_id);

        // Within next year, the same teacher or room twice: refused.
        $otherNextSection = $this->createSection($w['next'], $w['campus'], $w['grade'], ['name' => 'B', 'code' => 'B', 'status' => 'active']);
        try {
            $service->create($w['school'], $w['nextOffering'], $otherNextSection, $w['teacher'], null, $w['p1'], 1, $w['actor']);
            $this->fail('same-year teacher double-booking must be refused');
        } catch (TeacherAlreadyScheduledException) {
        }
        try {
            $service->create($w['school'], $w['nextOffering'], $otherNextSection, $w['otherTeacher'], $w['room'], $w['p1'], 1, $w['actor']);
            $this->fail('same-year room double-booking must be refused');
        } catch (RoomAlreadyScheduledException) {
        }

        $this->assertSame(2, $this->inSchool($w['school'], fn () => TimetableEntry::query()->where('status', 'active')->count()));
        // Preparing next year's timetable activates nothing.
        $this->assertSame('draft', $this->inSchool($w['school'], fn () => $w['next']->fresh()->status));
        $this->assertSame('active', $this->inSchool($w['school'], fn () => $w['current']->fresh()->status));
    }

    #[Test]
    public function todays_registers_list_only_the_active_year_and_a_crafted_other_year_register_is_refused(): void
    {
        // SIS 6.1 (ATT-09): Take lists no class for a future date, so MONDAY is today here.
        $this->travelTo(CarbonImmutable::parse(self::MONDAY.' 12:00:00', 'UTC'));
        $w = $this->world();
        $current = $this->createTimetableEntry($w['currentOffering'], $w['currentSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);
        $next = $this->createTimetableEntry($w['nextOffering'], $w['nextSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);

        $this->signedIn($w['actor'], $w['school'])
            ->get('/app/attendance/take?attendance_date='.self::MONDAY)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Attendance/Take')
                ->has('classes', 1)
                ->where('classes.0.timetableEntryId', $current->id));

        $this->post('/app/attendance', [
            'timetable_entry_id' => $next->id,
            'attendance_date' => self::MONDAY,
            'records' => [['student_enrollment_id' => (string) fake()->uuid(), 'status' => 'present']],
        ])->assertRedirect();
        $this->assertNotNull(session('errors'), 'a register for next year is refused, never written');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceSession::query()->count()));
    }

    #[Test]
    public function a_class_is_edited_within_its_own_year(): void
    {
        $w = $this->world();
        $current = $this->createTimetableEntry($w['currentOffering'], $w['currentSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);
        $blocker = $this->createTimetableEntry($w['currentOffering'], $this->createSection($w['current'], $w['campus'], $w['grade'], ['name' => 'B', 'code' => 'B', 'status' => 'active']), $w['otherTeacher'], $w['p2'], ['day_of_week' => 1]);
        $this->createTimetableEntry($w['nextOffering'], $w['nextSection'], $w['otherTeacher'], $w['p1'], ['day_of_week' => 2]);

        $this->signedIn($w['actor'], $w['school'])->get("/app/timetable-schedule/{$current->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/Timetable/Schedule/Edit')->where('entry.id', $current->id));

        // Moving it onto a slot its new teacher already teaches THIS year: refused, unchanged.
        $this->from("/app/timetable-schedule/{$current->id}/edit")
            ->patch("/app/timetable-schedule/{$current->id}", ['teacher_id' => $w['otherTeacher']->id, 'period_id' => $w['p2']->id, 'day_of_week' => 1])
            ->assertRedirect("/app/timetable-schedule/{$current->id}/edit");
        $this->assertStringContainsString('teacher', strtolower($this->refusal()));
        $this->assertSame($w['p1']->id, $this->inSchool($w['school'], fn () => $current->fresh()->period_id));

        // Onto a slot that teacher holds only NEXT year (Tuesday P1): allowed.
        $this->patch("/app/timetable-schedule/{$current->id}", ['teacher_id' => $w['otherTeacher']->id, 'period_id' => $w['p1']->id, 'day_of_week' => 2, 'room_id' => $w['room']->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->inSchool($w['school'], function () use ($current, $w): void {
            $fresh = $current->fresh();
            $this->assertSame([$w['otherTeacher']->id, 2, $w['room']->id], [$fresh->teacher_id, $fresh->day_of_week, $fresh->room_id]);
            $this->assertSame($w['currentOffering']->id, $fresh->subject_offering_id, 'the class itself never changes');
        });
        $this->assertNotNull($blocker->id);
    }

    #[Test]
    public function a_deactivated_class_stays_findable_and_reactivation_rechecks_its_year(): void
    {
        $w = $this->world();
        $entry = $this->createTimetableEntry($w['currentOffering'], $w['currentSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);

        $this->signedIn($w['actor'], $w['school'])->post("/app/timetable-schedule/{$entry->id}/deactivate")->assertRedirect();
        $this->get('/app/timetable-schedule?status=inactive')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 1)->where('entries.data.0.id', $entry->id)
                ->where('filters.academicYearId', $w['current']->id));
        $this->get('/app/timetable-schedule')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));

        $this->post("/app/timetable-schedule/{$entry->id}/activate")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $this->inSchool($w['school'], fn () => $entry->fresh()->status));

        // Deactivate, let another class take the teacher's slot, then try again: refused, stays inactive.
        $this->post("/app/timetable-schedule/{$entry->id}/deactivate");
        $other = $this->createSection($w['current'], $w['campus'], $w['grade'], ['name' => 'B', 'code' => 'B', 'status' => 'active']);
        $this->createTimetableEntry($w['currentOffering'], $other, $w['teacher'], $w['p1'], ['day_of_week' => 1]);
        $this->from('/app/timetable-schedule?status=inactive')->post("/app/timetable-schedule/{$entry->id}/activate")
            ->assertRedirect('/app/timetable-schedule?status=inactive');
        $this->assertStringContainsString('teacher', strtolower($this->refusal()));
        $this->assertSame('inactive', $this->inSchool($w['school'], fn () => $entry->fresh()->status));

        // The year filter shows next year's timetable on its own.
        $this->createTimetableEntry($w['nextOffering'], $w['nextSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);
        $this->get("/app/timetable-schedule?academic_year_id={$w['next']->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 1));
    }

    #[Test]
    public function a_period_is_edited_found_when_inactive_and_its_times_are_protected_while_in_use(): void
    {
        $w = $this->world();
        $this->createTimetableEntry($w['currentOffering'], $w['currentSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);

        $this->signedIn($w['actor'], $w['school'])->get("/app/timetable-periods/{$w['p1']->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/Timetable/Periods/Edit'));

        // A rename is fine while classes use it.
        $this->patch("/app/timetable-periods/{$w['p1']->id}", ['code' => 'P1', 'name' => 'First period', 'start_time' => '09:00:00', 'end_time' => '09:45:00'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('First period', $this->inSchool($w['school'], fn () => $w['p1']->fresh()->name));

        // A time change is refused while an active class uses it.
        $this->from("/app/timetable-periods/{$w['p1']->id}/edit")
            ->patch("/app/timetable-periods/{$w['p1']->id}", ['code' => 'P1', 'name' => 'First period', 'start_time' => '08:30:00', 'end_time' => '09:15:00'])
            ->assertRedirect("/app/timetable-periods/{$w['p1']->id}/edit");
        $this->refusal();
        $this->assertSame('09:00:00', $this->inSchool($w['school'], fn () => $w['p1']->fresh()->start_time));

        // An unused Period's times can change; it can be deactivated, found and reactivated.
        $this->patch("/app/timetable-periods/{$w['p2']->id}", ['code' => 'P2', 'name' => 'Second', 'start_time' => '10:15:00', 'end_time' => '11:00:00'])
            ->assertSessionHasNoErrors();
        $this->patch("/app/timetable-periods/{$w['p2']->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->get('/app/timetable-periods?status=inactive')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('periods.data', 1)->where('periods.data.0.id', $w['p2']->id));
        $this->patch("/app/timetable-periods/{$w['p2']->id}", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->inSchool($w['school'], fn () => TimetablePeriod::query()->findOrFail($w['p2']->id)->status));
    }

    #[Test]
    public function editing_needs_the_manage_capability_and_another_schools_class_is_not_found(): void
    {
        $w = $this->world();
        $entry = $this->createTimetableEntry($w['currentOffering'], $w['currentSection'], $w['teacher'], $w['p1'], ['day_of_week' => 1]);
        $viewer = $this->createUserWithCapabilities($w['school'], ['timetable.schedule.view', 'timetable.periods.view']);

        $this->signedIn($viewer, $w['school']);
        $this->get("/app/timetable-schedule/{$entry->id}/edit")->assertForbidden();
        $this->patch("/app/timetable-schedule/{$entry->id}", ['teacher_id' => $w['teacher']->id, 'period_id' => $w['p2']->id, 'day_of_week' => 1])->assertForbidden();
        $this->get("/app/timetable-periods/{$w['p1']->id}/edit")->assertForbidden();
        $this->patch("/app/timetable-periods/{$w['p1']->id}", ['code' => 'PX', 'name' => 'X', 'start_time' => '07:00:00', 'end_time' => '07:30:00'])->assertForbidden();
        $this->assertSame($w['p1']->id, $this->inSchool($w['school'], fn () => $entry->fresh()->period_id));

        $other = $this->createSchool();
        $foreignAdmin = $this->fullTimetableActor($other);
        $this->signedIn($foreignAdmin, $other);
        $this->get("/app/timetable-schedule/{$entry->id}/edit")->assertNotFound();
        $this->patch("/app/timetable-schedule/{$entry->id}", ['teacher_id' => $w['teacher']->id, 'period_id' => $w['p2']->id, 'day_of_week' => 1])->assertNotFound();
        $this->post("/app/timetable-schedule/{$entry->id}/activate")->assertNotFound();
        $this->get("/app/timetable-periods/{$w['p1']->id}/edit")->assertNotFound();
    }
}
