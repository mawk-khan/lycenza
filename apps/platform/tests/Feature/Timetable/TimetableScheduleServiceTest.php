<?php

namespace Tests\Feature\Timetable;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\Exceptions\RequiredSubjectOfferingOnlyException;
use App\Domain\Timetable\Application\Exceptions\RoomAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\RoomNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\SectionAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\SectionNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\SubjectOfferingNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\TeacherAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\TeacherNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodNotAvailableException;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation): TimetableScheduleService --
 * parent-eligibility validation, the three double-booking guards
 * (teacher/Section/Room), deactivate/reactivate, update, parent-status
 * drift, and audit. Real two-process teacher-conflict concurrency lives
 * in TimetableEntryConcurrencyTest.php.
 *
 * NOTE on `->fresh()`: every `App\Domain\Timetable\Application\*`
 * service method mutates the EXACT model instance a caller passed in
 * (via Eloquent's own `update()`/`refresh()` on that same object, never
 * a freshly re-fetched clone) -- so a caller-held reference already
 * reflects the post-call state without needing its own `->fresh()`
 * call. This matters here specifically because `timetable_periods`/
 * `timetable_entries` are RLS-protected: a bare `->fresh()` (or any
 * other ad hoc re-query) issued OUTSIDE an
 * `App\Support\Tenancy\TenantContext::withSchool()` wrapper resolves to
 * zero rows once the service call that last held that context has
 * already returned and restored the ambient (unset) context -- exactly
 * the same reason no existing test in this codebase calls `->fresh()`
 * on a tenant-owned model without such a wrapper.
 */
class TimetableScheduleServiceTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function service(): TimetableScheduleService
    {
        return app(TimetableScheduleService::class);
    }

    private function auditCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, offering: SubjectOffering, section: Section, teacher: Employee, room: Room, period: TimetablePeriod}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $room = $this->createRoom($campus, ['status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        return compact('school', 'campus', 'year', 'grade', 'offering', 'section', 'teacher', 'room', 'period');
    }

    // --- Parent eligibility ---------------------------------------------

    #[Test]
    public function a_valid_entry_can_be_created(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);

        $this->assertInstanceOf(TimetableEntry::class, $entry);
        $this->assertSame($school->id, $entry->school_id);
        $this->assertSame($offering->academic_year_id, $entry->academic_year_id);
        $this->assertSame($offering->campus_id, $entry->campus_id);
        $this->assertSame($offering->grade_level_id, $entry->grade_level_id);
        $this->assertSame($offering->id, $entry->subject_offering_id);
        $this->assertSame($section->id, $entry->section_id);
        $this->assertSame($teacher->id, $entry->teacher_id);
        $this->assertSame($room->id, $entry->room_id);
        $this->assertSame($period->id, $entry->period_id);
        $this->assertSame(1, $entry->day_of_week);
        $this->assertSame('active', $entry->status);
    }

    #[Test]
    public function null_room_is_accepted(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->assertNull($entry->room_id);
    }

    #[Test]
    public function an_elective_offering_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['is_required' => false]));

        $this->expectException(RequiredSubjectOfferingOnlyException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    #[Test]
    public function an_inactive_offering_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));

        $this->expectException(SubjectOfferingNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    #[Test]
    public function an_inactive_section_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $section->update(['status' => 'inactive']));

        $this->expectException(SectionNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    #[Test]
    public function an_archived_employee_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $teacher->update(['record_status' => 'archived']));

        $this->expectException(TeacherNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    #[Test]
    public function an_inactive_room_is_rejected_when_provided(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $room->update(['status' => 'inactive']));

        $this->expectException(RoomNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    #[Test]
    public function an_inactive_period_is_rejected(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        app(TenantContext::class)->withSchool($school, fn () => $period->update(['status' => 'inactive']));

        $this->expectException(TimetablePeriodNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);
    }

    // --- Double-booking guards -------------------------------------------

    #[Test]
    public function a_teacher_conflict_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $subject2 = $this->createSubject($school);
        $offering2 = $this->createSubjectOffering($year, $campus, $grade, $subject2, ['is_required' => true, 'status' => 'active']);
        $section2 = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'B']);

        $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->expectException(TeacherAlreadyScheduledException::class);

        // Same teacher, same day+Period, DIFFERENT Section/Offering.
        $this->service()->create($school, $offering2, $section2, $teacher, null, $period, 1, $actor);
    }

    #[Test]
    public function a_section_conflict_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $subject2 = $this->createSubject($school);
        $offering2 = $this->createSubjectOffering($year, $campus, $grade, $subject2, ['is_required' => true, 'status' => 'active']);
        $teacher2 = $this->createEmployee($school, ['record_status' => 'active']);

        $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->expectException(SectionAlreadyScheduledException::class);

        // Same Section, same day+Period, DIFFERENT teacher/Offering.
        $this->service()->create($school, $offering2, $section, $teacher2, null, $period, 1, $actor);
    }

    #[Test]
    public function a_room_conflict_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'room' => $room, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $subject2 = $this->createSubject($school);
        $offering2 = $this->createSubjectOffering($year, $campus, $grade, $subject2, ['is_required' => true, 'status' => 'active']);
        $section2 = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'B']);
        $teacher2 = $this->createEmployee($school, ['record_status' => 'active']);

        $this->service()->create($school, $offering, $section, $teacher, $room, $period, 1, $actor);

        $this->expectException(RoomAlreadyScheduledException::class);

        // Same Room, same day+Period, everything else different.
        $this->service()->create($school, $offering2, $section2, $teacher2, $room, $period, 1, $actor);
    }

    #[Test]
    public function deactivating_an_entry_frees_its_slot(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $first = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($first, $actor);

        $second = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->assertSame('active', $second->status);
        $this->assertSame('inactive', $first->status);
    }

    #[Test]
    public function reactivation_redetects_a_conflict_that_arose_while_inactive(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $first = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($first, $actor);

        $section2 = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'B']);
        // A DIFFERENT entry now occupies the same teacher/day/Period slot.
        $this->service()->create($school, $offering, $section2, $teacher, null, $period, 1, $actor);

        $this->expectException(TeacherAlreadyScheduledException::class);

        $this->service()->activate($first, $actor);
    }

    #[Test]
    public function an_update_that_would_create_a_conflict_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $section2 = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'B']);
        $teacher2 = $this->createEmployee($school, ['record_status' => 'active']);

        $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $entryB = $this->service()->create($school, $offering, $section2, $teacher2, null, $period, 1, $actor);

        $this->expectException(TeacherAlreadyScheduledException::class);

        // Retargeting entry B onto the SAME teacher/day/Period as entry A.
        $this->service()->update($entryB, $offering, $section2, $teacher, null, $period, 1, $actor);
    }

    // --- Parent-status drift ---------------------------------------------

    #[Test]
    public function an_existing_entry_remains_readable_after_its_offering_is_deactivated_but_a_new_entry_using_it_is_blocked(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));

        // No cascade -- the existing entry, re-read fresh from the
        // database (not just the caller's own in-memory copy), is
        // untouched and still active.
        $reread = app(TenantContext::class)->withSchool($school, fn () => TimetableEntry::query()->find($entry->id));
        $this->assertSame('active', $reread->status);

        $this->expectException(SubjectOfferingNotAvailableException::class);

        $this->service()->create($school, $offering, $section, $teacher, null, $period, 2, $actor);
    }

    #[Test]
    public function reactivating_an_entry_is_blocked_once_its_offering_has_been_deactivated(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));

        $this->expectException(SubjectOfferingNotAvailableException::class);

        $this->service()->activate($entry, $actor);
    }

    #[Test]
    public function reactivating_an_entry_is_blocked_once_its_teacher_has_been_archived(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);

        app(TenantContext::class)->withSchool($school, fn () => $teacher->update(['record_status' => 'archived']));

        $this->assertSame('inactive', $entry->status, 'no cascade -- the entry itself is untouched');

        $this->expectException(TeacherNotAvailableException::class);

        $this->service()->activate($entry, $actor);
    }

    // --- Audit -------------------------------------------------------------

    #[Test]
    public function create_is_audited(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->assertSame(1, $this->auditCount($school, 'timetable.entry.created'));
    }

    #[Test]
    public function deactivate_and_activate_are_audited(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->deactivate($entry, $actor);
        $this->service()->activate($entry, $actor);

        $this->assertSame(1, $this->auditCount($school, 'timetable.entry.deactivated'));
        $this->assertSame(1, $this->auditCount($school, 'timetable.entry.activated'));
    }

    #[Test]
    public function update_is_audited(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildContext();
        $actor = $this->fullTimetableActor($school);

        $entry = $this->service()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);
        $this->service()->update($entry, $offering, $section, $teacher, null, $period, 2, $actor);

        $this->assertSame(1, $this->auditCount($school, 'timetable.entry.updated'));
        $this->assertSame(2, $entry->day_of_week);
    }
}
