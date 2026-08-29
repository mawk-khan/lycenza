<?php

namespace Tests\Feature\Timetable;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\Exceptions\InvalidTimetablePeriodException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodOverlapException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodReferencedException;
use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation): TimetablePeriodService -- creation,
 * overlap validation, the deactivate/time-change referenced-entry
 * guard, and audit. Real two-process overlap concurrency lives in
 * TimetablePeriodConcurrencyTest.php.
 */
class TimetablePeriodServiceTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function service(): TimetablePeriodService
    {
        return app(TimetablePeriodService::class);
    }

    private function scheduleService(): TimetableScheduleService
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

    #[Test]
    public function a_valid_period_can_be_created(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $period = $this->service()->create($school, [
            'code' => 'p1',
            'name' => 'Period 1',
            'start_time' => '09:00:00',
            'end_time' => '09:45:00',
        ], $actor);

        $this->assertInstanceOf(TimetablePeriod::class, $period);
        $this->assertSame($school->id, $period->school_id);
        $this->assertSame('P1', $period->code, 'code must be normalized to uppercase');
        $this->assertSame('09:00:00', $period->start_time);
        $this->assertSame('09:45:00', $period->end_time);
        $this->assertSame('active', $period->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $period->id);
    }

    #[Test]
    public function start_time_equal_to_end_time_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $this->expectException(InvalidTimetablePeriodException::class);

        $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:00:00',
        ], $actor);
    }

    #[Test]
    public function start_time_after_end_time_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $this->expectException(InvalidTimetablePeriodException::class);

        $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '10:00:00', 'end_time' => '09:00:00',
        ], $actor);
    }

    #[Test]
    public function adjacent_periods_are_allowed(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $first = $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ], $actor);
        $second = $this->service()->create($school, [
            'code' => 'P2', 'name' => 'Period 2', 'start_time' => '10:00:00', 'end_time' => '11:00:00',
        ], $actor);

        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function overlapping_active_periods_are_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ], $actor);

        $this->expectException(TimetablePeriodOverlapException::class);

        $this->service()->create($school, [
            'code' => 'P2', 'name' => 'Period 2', 'start_time' => '09:30:00', 'end_time' => '10:30:00',
        ], $actor);
    }

    #[Test]
    public function inactive_periods_may_overlap(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $first = $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ], $actor);
        $this->service()->deactivate($first, $actor);

        $second = $this->service()->create($school, [
            'code' => 'P2', 'name' => 'Period 2', 'start_time' => '09:30:00', 'end_time' => '10:30:00',
        ], $actor);

        $this->assertSame('active', $second->status);
    }

    #[Test]
    public function reactivation_rechecks_overlap_against_currently_active_periods(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $first = $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '10:00:00',
        ], $actor);
        $this->service()->deactivate($first, $actor);

        // A DIFFERENT active Period now occupies an overlapping range.
        $this->service()->create($school, [
            'code' => 'P2', 'name' => 'Period 2', 'start_time' => '09:30:00', 'end_time' => '10:30:00',
        ], $actor);

        $this->expectException(TimetablePeriodOverlapException::class);

        $this->service()->activate($first, $actor);
    }

    #[Test]
    public function deactivation_is_blocked_while_an_active_timetable_entry_references_it(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildScheduleContext();
        $actor = $this->fullTimetableActor($school);

        $this->scheduleService()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->expectException(TimetablePeriodReferencedException::class);

        $this->service()->deactivate($period, $actor);
    }

    #[Test]
    public function start_or_end_time_change_is_blocked_while_an_active_timetable_entry_references_it(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildScheduleContext();
        $actor = $this->fullTimetableActor($school);

        $this->scheduleService()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $this->expectException(TimetablePeriodReferencedException::class);

        $this->service()->update($period, ['start_time' => '09:15:00'], $actor);
    }

    #[Test]
    public function a_non_temporal_field_update_is_allowed_regardless_of_active_references(): void
    {
        ['school' => $school, 'offering' => $offering, 'section' => $section, 'teacher' => $teacher, 'period' => $period] = $this->buildScheduleContext();
        $actor = $this->fullTimetableActor($school);

        $this->scheduleService()->create($school, $offering, $section, $teacher, null, $period, 1, $actor);

        $updated = $this->service()->update($period, ['name' => 'Renamed Period', 'code' => 'RENAMED'], $actor);

        $this->assertSame('Renamed Period', $updated->name);
        $this->assertSame('RENAMED', $updated->code);
    }

    #[Test]
    public function code_is_normalized_and_case_insensitively_unique_per_school(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $this->service()->create($school, [
            'code' => 'p1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);

        $this->expectException(QueryException::class);

        // Different literal casing of the SAME normalized code, for the
        // SAME School -- must collide.
        app(TenantContext::class)->withSchool(
            $school,
            fn () => TimetablePeriod::query()->create([
                'school_id' => $school->id,
                'code' => 'P1',
                'name' => 'Duplicate',
                'start_time' => '11:00:00',
                'end_time' => '11:45:00',
                'status' => 'active',
            ]),
        );
    }

    #[Test]
    public function a_different_school_may_reuse_the_same_period_code(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullTimetableActor($schoolA);
        $actorB = $this->fullTimetableActor($schoolB);

        $periodA = $this->service()->create($schoolA, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actorA);
        $periodB = $this->service()->create($schoolB, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actorB);

        $this->assertSame('P1', $periodA->code);
        $this->assertSame('P1', $periodB->code);
    }

    #[Test]
    public function create_is_audited(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);

        $this->assertSame(1, $this->auditCount($school, 'timetable.period.created'));
    }

    #[Test]
    public function deactivate_and_activate_are_audited(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullTimetableActor($school);

        $period = $this->service()->create($school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '09:00:00', 'end_time' => '09:45:00',
        ], $actor);

        $this->service()->deactivate($period, $actor);
        $this->service()->activate($period, $actor);

        $this->assertSame(1, $this->auditCount($school, 'timetable.period.deactivated'));
        $this->assertSame(1, $this->auditCount($school, 'timetable.period.activated'));
    }

    /**
     * @return array{school: School, offering: SubjectOffering, section: Section, teacher: Employee, period: TimetablePeriod}
     */
    private function buildScheduleContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        return compact('school', 'offering', 'section', 'teacher', 'period');
    }
}
