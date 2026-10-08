<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\Attendance\Application\TeacherAttendanceAccess;
use App\Domain\CurriculumDelivery\Application\TeacherDeliveryAccess;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\LMS\Application\TeacherLearningContentAccess;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentBeyondEmploymentException;
use App\Domain\TeachingAssignments\Application\Exceptions\EmployeeNotAssignableException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * S7 (ADR 0068 §27.11; ADR 0063 §47): ending an employment ends the future
 * teaching ownership it granted -- required (TeachingAssignment) and elective
 * (TCH-E) alike -- in the same transaction, so a later rehire of the SAME
 * Employee row never resurrects an old assignment. Ownership stays true up to
 * and including the employment's last day (the inclusive HR interval); an
 * assignment that had not started is voided (ends the day before it began),
 * never deleted; history before the end is untouched; a new assignment is
 * required after rehire. Assignments can also no longer be created to run past
 * the covering employment's end.
 */
class EmploymentEndTeachingOwnershipTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    private const string END = '2026-09-20';

    private const string REHIRED = '2026-10-01';

    private function hr(array $w): User
    {
        return $w['hr'] ??= $this->createUserWithCapabilities($w['school'], ['hr.employees.view', 'hr.employees.manage', 'hr.employees.assignments.manage']);
    }

    private function employment(array $w, Employee $employee): EmploymentRecord
    {
        return $this->inMarksSchool($w['school'], fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->whereNull('ends_on')->firstOrFail());
    }

    private function endEmployment(array &$w, Employee $employee, ?User $actor = null): void
    {
        $actor ??= $w['hr'] = $this->hr($w);
        app(EmploymentService::class)->end($this->employment($w, $employee), self::END, $actor);
    }

    private function rehire(array &$w, Employee $employee): void
    {
        $w['hr'] = $this->hr($w);
        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => self::REHIRED, 'status' => 'active'], $w['hr']);
    }

    private function ownsRequired(array $w, Employee $employee, string $date, string $section = 'a1'): bool
    {
        return DB::transaction(fn () => app(TeachingOwnership::class)->hold($w['school'], $employee->id, $w[$section]->id, $w['required']->id, $date));
    }

    private function ownsElective(array $w, Employee $employee, string $date): bool
    {
        return DB::transaction(fn () => app(TeachingOwnership::class)->holdElective($w['school'], $employee->id, $w['elective']->id, $date));
    }

    #[Test]
    public function ending_employment_ends_required_and_elective_ownership_and_rehire_does_not_resurrect_it(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $required = $this->ownSection($w, $employee, 'a1');
        $elective = $this->ownElective($w, $employee);

        $this->endEmployment($w, $employee);

        // Historical ownership up to and including the last employed day stays true; after it, none.
        foreach (['2026-06-01', '2026-09-15', self::END] as $date) {
            $this->assertTrue($this->ownsRequired($w, $employee, $date), $date);
            $this->assertTrue($this->ownsElective($w, $employee, $date), $date);
        }
        $this->assertFalse($this->ownsRequired($w, $employee, '2026-09-21'));
        $this->assertFalse($this->ownsElective($w, $employee, '2026-09-21'));
        $ended = $this->inMarksSchool($w['school'], fn () => [TeachingAssignment::query()->findOrFail($required->id), ElectiveTeachingAssignment::query()->findOrFail($elective->id)]);
        foreach ($ended as $row) {
            $this->assertSame([self::END, 'employment_ended', $w['hr']->id], [$row->ends_on->toDateString(), $row->end_reason, $row->ended_by_user_id]);
            $this->assertSame('2026-06-01', $row->starts_on->toDateString(), 'the start is never rewritten');
        }

        // Rehired (the same Employee row): eligible again, owning nothing until a NEW assignment.
        $this->rehire($w, $employee);
        $this->assertFalse($this->ownsRequired($w, $employee, '2026-10-05'));
        $this->assertFalse($this->ownsElective($w, $employee, '2026-10-05'));
        $this->ownSection($w, $employee, 'a1', self::REHIRED);
        $this->ownElective($w, $employee, null, self::REHIRED);
        $this->assertTrue($this->ownsRequired($w, $employee, '2026-10-05'));
        $this->assertTrue($this->ownsElective($w, $employee, '2026-10-05'));
        $this->assertFalse($this->ownsRequired($w, $employee, '2026-09-25'), 'the gap between the employments stays unowned');
    }

    #[Test]
    public function already_ended_rows_beyond_the_end_are_shortened_earlier_ones_untouched_and_unstarted_ones_voided(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $before = $this->ownSection($w, $employee, 'a1', '2026-06-01', '2026-08-31');
        app(TeachingAssignmentService::class)->end($w['school'], $before->id, '2026-08-15', 'completed', $w['assigner']);
        $scheduledEnd = $this->ownSection($w, $employee, 'a2', '2026-06-01', '2026-12-31');
        app(TeachingAssignmentService::class)->end($w['school'], $scheduledEnd->id, '2026-11-30', 'reassigned', $w['assigner']);
        $future = $this->ownSection($w, $employee, 'a1', '2026-10-05');
        $futureElective = $this->ownElective($w, $employee, null, '2026-10-05');

        $this->endEmployment($w, $employee);

        [$before, $scheduledEnd, $future] = $this->inMarksSchool($w['school'], fn () => array_map(fn ($a) => TeachingAssignment::query()->findOrFail($a->id), [$before, $scheduledEnd, $future]));
        $this->assertSame(['2026-08-15', 'completed'], [$before->ends_on->toDateString(), $before->end_reason], 'ended before the boundary: unchanged');
        $this->assertSame([self::END, 'employment_ended'], [$scheduledEnd->ends_on->toDateString(), $scheduledEnd->end_reason], 'an end scheduled past the boundary is brought back to it');
        $this->assertSame(['2026-10-05', '2026-10-04', 'employment_ended'], [$future->starts_on->toDateString(), $future->ends_on->toDateString(), $future->end_reason],
            'not yet started: voided (ends the day before it began), kept as a row');

        $voidedElective = $this->inMarksSchool($w['school'], fn () => ElectiveTeachingAssignment::query()->findOrFail($futureElective->id));
        $this->assertSame(['2026-10-04', 'employment_ended'], [$voidedElective->ends_on->toDateString(), $voidedElective->end_reason]);
        $this->assertSame('past', TeachingAssignmentReadService::present($future, '2026-10-01')['state'], 'a voided row is never shown as upcoming');
        $this->assertSame('past', TeachingAssignmentReadService::presentElective($voidedElective, '2026-10-01')['state']);

        $this->rehire($w, $employee);
        $this->assertFalse($this->ownsRequired($w, $employee, '2026-10-06'), 'the voided assignment never covers a date');
        $this->assertFalse($this->ownsRequired($w, $employee, '2026-10-06', 'a2'));
        $this->assertFalse($this->ownsElective($w, $employee, '2026-10-06'));

        // A voided row overlaps nothing: the same keys can be assigned again across its dates.
        $this->ownSection($w, $employee, 'a1', self::REHIRED);
        $this->ownElective($w, $employee, null, self::REHIRED);
        $this->assertTrue($this->ownsRequired($w, $employee, '2026-10-06'));
        $this->assertTrue($this->ownsElective($w, $employee, '2026-10-06'));
    }

    #[Test]
    public function only_the_leaving_employee_is_affected_and_each_ending_is_audited(): void
    {
        $w = $this->teacherMarksWorld();
        [, $leaver] = $this->markTeacher($w);
        [, $coTeacher] = $this->markTeacher($w);
        $own = $this->ownSection($w, $leaver, 'a1');
        $this->ownSection($w, $coTeacher, 'a1');
        $this->ownElective($w, $leaver);
        $other = $this->teacherMarksWorld();
        [, $otherEmployee] = $this->markTeacher($other);
        $this->ownSection($other, $otherEmployee, 'a1');

        $this->endEmployment($w, $leaver);

        $this->assertTrue($this->ownsRequired($w, $coTeacher, '2026-10-05'), 'the co-teacher keeps teaching');
        $this->assertTrue($this->ownsRequired($other, $otherEmployee, '2026-10-05'), 'another School is untouched');
        $events = $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->whereIn('event_type', ['teaching_assignment.ended', 'elective_teaching_assignment.ended'])->get());
        $this->assertCount(2, $events);
        $required = $events->firstWhere('event_type', 'teaching_assignment.ended');
        $this->assertSame([$own->id, $leaver->id, null, self::END, 'employment_ended'], [
            $required->metadata['teachingAssignmentId'], $required->metadata['employeeId'], $required->metadata['previousEndsOn'], $required->metadata['endsOn'], $required->metadata['endReason'],
        ]);
        $this->assertSame($w['hr']->id, $required->actor_user_id);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count()));
    }

    #[Test]
    public function an_unauthorised_end_is_refused_as_before_and_ownership_cannot_be_created_past_the_employment(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $stranger = $this->createUserWithCapabilities($w['school'], ['hr.employees.view']);
        $this->assertThrows(fn () => $this->endEmployment($w, $employee, $stranger), AuthorizationException::class);
        $this->assertTrue($this->ownsRequired($w, $employee, '2026-10-05'), 'nothing ended');

        // An employment with a known last day (a fixed-term record): covered on the start date, but no assignment
        // may outlast it -- open-ended or past its end.
        [, $fixedTerm] = $this->markTeacher($w, employment: ['ends_on' => self::END]);
        $this->assertThrows(fn () => $this->ownSection($w, $fixedTerm, 'a2', '2026-09-01'), AssignmentBeyondEmploymentException::class);
        $this->assertThrows(fn () => $this->ownSection($w, $fixedTerm, 'a2', '2026-09-01', '2026-09-30'), AssignmentBeyondEmploymentException::class);
        $this->assertThrows(fn () => $this->ownElective($w, $fixedTerm, null, '2026-09-01'), AssignmentBeyondEmploymentException::class);
        $this->assertThrows(fn () => $this->ownElective($w, $fixedTerm, null, '2026-09-01', '2026-09-21'), AssignmentBeyondEmploymentException::class);
        $this->ownSection($w, $fixedTerm, 'a2', '2026-09-01', self::END); // within the employment: fine
        $this->ownElective($w, $fixedTerm, null, '2026-09-01', self::END);
        $this->assertTrue($this->ownsRequired($w, $fixedTerm, self::END, 'a2'));
        $this->assertFalse($this->ownsRequired($w, $fixedTerm, '2026-09-21', 'a2'));

        // After an end, the ended employment plans nothing more (TCH.2, unchanged).
        $this->endEmployment($w, $employee);
        $this->assertThrows(fn () => $this->ownSection($w, $employee, 'a2', '2026-09-01', self::END), EmployeeNotAssignableException::class);
    }

    #[Test]
    public function every_teacher_surface_loses_the_resurrected_authority(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '40')]); // RES.4 before the end: allowed

        $this->endEmployment($w, $employee);
        $this->rehire($w, $employee);
        $today = now()->toDateString();

        // Attendance and LMS (ownership today), Curriculum Delivery (ownership on the delivery date) -- all gone.
        $this->assertFalse(app(TeacherAttendanceAccess::class)->scope($teacher, $w['school'])->ownsOn($w['a1']->id, $w['required']->id, $today));
        $this->assertTrue(app(TeacherAttendanceAccess::class)->scope($teacher, $w['school'])->ownsOn($w['a1']->id, $w['required']->id, '2026-09-15'), 'history stays');
        $this->assertSame([], app(TeacherLearningContentAccess::class)->scope($teacher, $w['school'])->taughtOfferings());
        $this->assertThrows(fn () => $this->inMarksSchool($w['school'], fn () => DB::transaction(fn () => app(TeacherDeliveryAccess::class)->guard($teacher)->beforeStart($w['school'], $w['a1'], $w['required'], $today))));

        // RES.4: the paper (15 Sept) was owned then, so the historical mark is readable and correctable while the paper is open --
        // the paper-date rule (RES-L2 unresolved) is unchanged; a paper after the end is not.
        $this->assertSame([$student->id], $this->rowIds($this->teacherRead($w, $teacher)));
        $later = $this->createExaminationPaper($this->createExamination($w['year'], ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-30', 'status' => 'active']), $w['required'], ['scheduled_on' => '2026-09-25', 'max_marks' => '20.00', 'starts_at' => '13:00', 'ends_at' => '14:00']);
        $this->assertThrows(fn () => $this->teacherRead($w, $teacher, $later));
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '5')], $later));

        // A new assignment restores authority for its own dates only.
        $this->ownSection($w, $employee, 'a1', self::REHIRED);
        $this->assertTrue(app(TeacherAttendanceAccess::class)->scope($teacher, $w['school'])->ownsOn($w['a1']->id, $w['required']->id, $today));
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '5')], $later), TeacherStudentMarkPaperNotFoundException::class, null);
    }
}
