<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.4 (ADR 0068 §25.7): teacher StudentMark entry under real concurrency --
 * two genuinely separate OS processes against real PostgreSQL, the holder's
 * work held uncommitted until the contender is observed blocked on it
 * (ForcesConcurrentOverlap). Committed synthetic fixtures, purged afterwards.
 *
 * - X1 / X2: entry vs ending the required / elective assignment -- the
 *   ownership FOR SHARE (TeachingOwnership::holdOffering): an end that
 *   committed first refuses the entry; an entry that held first makes the end
 *   wait, and its mark stands (it was owned when it was decided).
 * - X3: entry vs ADR 0038 withdrawal -- refused once the withdrawal commits.
 * - X4: entry vs placement transfer -- the transfer waits; P3 stays stable.
 * - X5: entry vs the paper lock -- the lock first refuses the entry; an entry
 *   first makes the lock wait, then the lock completes.
 * - X6: two co-teachers on one mark -- the row lock + version guard: no lost
 *   update.
 */
class TeacherStudentMarkConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTeacherStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function teacherOp(array $w, User $teacher, string $studentId, string $value, string $expected = '-', string $paper = 'paper'): array
    {
        return $this->op('teacher-record', $w['school']->id, $w[$paper]->id, $teacher->id, $studentId, 'present', $value, $expected);
    }

    /** @return array{0: array<string, mixed>, 1: User, 2: TeachingAssignment, 3: Student} */
    private function requiredWorld(): array
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);

        return [$w, $teacher, $this->ownSection($w, $employee, 'a1'), $this->markStudent($w, 'a1')];
    }

    /** @return array{0: array<string, mixed>, 1: User, 2: ElectiveTeachingAssignment, 3: Student} */
    private function electiveWorld(): array
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $assignment = $this->ownElective($w, $employee);
        $student = $this->markStudent($w, 'a1');
        $this->elect($w, $student);

        return [$w, $teacher, $assignment, $student];
    }

    #[Test]
    public function x1_an_entry_behind_the_end_of_the_required_assignment_is_refused(): void
    {
        [$w, $teacher, $assignment, $student] = $this->requiredWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('end-assignment', $w['school']->id, $assignment->id, '2026-09-14', $w['assigner']->id),
            $this->teacherOp($w, $teacher, $student->id, '40'),
        );

        $this->assertSame('ended:2026-09-14', $holder);
        $this->assertSame('refused:STUDENT_MARK_STUDENT_NOT_FOUND', $contender, 'the entry waited on the assignment, re-read it ended before the paper date, and refused');
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function x1_an_end_behind_an_entry_waits_and_the_mark_stands(): void
    {
        [$w, $teacher, $assignment, $student] = $this->requiredWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->teacherOp($w, $teacher, $student->id, '41'),
            $this->op('end-assignment', $w['school']->id, $assignment->id, '2026-09-14', $w['assigner']->id),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('ended:2026-09-14', $contender, 'the end waited for the mark that relied on the assignment');
        $this->assertSame('41.00', (string) $this->markOf($w, $student)->value);
    }

    #[Test]
    public function x2_an_entry_behind_the_end_of_the_elective_assignment_is_refused(): void
    {
        [$w, $teacher, $assignment, $student] = $this->electiveWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('end-elective-assignment', $w['school']->id, $assignment->id, '2026-09-15', $w['assigner']->id),
            $this->teacherOp($w, $teacher, $student->id, '42', paper: 'electivePaper'),
        );

        $this->assertSame('ended:2026-09-15', $holder);
        $this->assertSame('refused:STUDENT_MARK_STUDENT_NOT_FOUND', $contender, 'the elective paper is on 2026-09-16, after the end');
        $this->assertNull($this->markOf($w, $student, $w['electivePaper']));
    }

    #[Test]
    public function x2_an_elective_end_behind_an_entry_waits_and_the_mark_stands(): void
    {
        [$w, $teacher, $assignment, $student] = $this->electiveWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->teacherOp($w, $teacher, $student->id, '43', paper: 'electivePaper'),
            $this->op('end-elective-assignment', $w['school']->id, $assignment->id, '2026-09-15', $w['assigner']->id),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('ended:2026-09-15', $contender);
        $this->assertSame('43.00', (string) $this->markOf($w, $student, $w['electivePaper'])->value);
    }

    #[Test]
    public function x3_an_entry_behind_a_withdrawal_is_refused(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1', authorised: false);
        $grant = $this->authorise($w, $student);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('withdraw-authorization', $w['school']->id, $grant->id, $w['admin']->id),
            $this->teacherOp($w, $teacher, $student->id, '44'),
        );

        $this->assertSame('withdrawn', $holder);
        $this->assertSame('refused:STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE', $contender);
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function x4_a_placement_transfer_waits_for_the_teacher_mark_that_relied_on_it(): void
    {
        [$w, $teacher, , $student] = $this->requiredWorld();
        $source = $this->placementOf($w, $student);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->teacherOp($w, $teacher, $student->id, '45'),
            $this->op('transfer-placement', $w['school']->id, $source->id, $w['a2']->id, '77', '2026-09-01'),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('transferred', $contender);
        $this->assertSame($source->id, $this->markOf($w, $student)->student_enrollment_id, 'the mark keeps the placement decided under lock');
        $this->assertSame(2, $this->inMarksSchool($w['school'], fn () => StudentEnrollment::query()->where('student_id', $student->id)->count()));
    }

    #[Test]
    public function x5_an_entry_behind_the_paper_lock_is_refused(): void
    {
        [$w, $teacher, , $student] = $this->requiredWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('lock', $w['school']->id, $w['paper']->id, $w['admin']->id),
            $this->teacherOp($w, $teacher, $student->id, '46'),
        );

        $this->assertSame('locked:locked', $holder);
        $this->assertSame('refused:STUDENT_MARK_PAPER_LOCKED', $contender);
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function x5_a_lock_behind_a_teacher_entry_waits_then_completes(): void
    {
        [$w, $teacher, , $student] = $this->requiredWorld();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->teacherOp($w, $teacher, $student->id, '47'),
            $this->op('lock', $w['school']->id, $w['paper']->id, $w['admin']->id),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('locked:locked', $contender, 'the lock waited on the paper FOR SHARE, then locked a paper that includes the mark');
        $this->assertSame('47.00', (string) $this->markOf($w, $student)->value);
        $this->assertSame('locked', $this->inMarksSchool($w['school'], fn () => ExaminationPaperMarkState::query()->where('examination_paper_id', $w['paper']->id)->value('state')));
    }

    #[Test]
    public function x6_two_co_teachers_on_one_mark_never_lose_an_update(): void
    {
        [$w, $first, , $student] = $this->requiredWorld();
        [$second, $secondEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $secondEmployee, 'a1');
        $this->teacherRecord($w, $first, [$this->entry($student, 'present', '10')]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->teacherOp($w, $first, $student->id, '20', '1'),
            $this->teacherOp($w, $second, $student->id, '30', '1'),
        );

        $this->assertSame('recorded:v2', $holder);
        $this->assertSame('refused:STUDENT_MARK_VERSION_CONFLICT', $contender);
        $this->assertSame(['20.00', 2, $first->id], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version, $this->markOf($w, $student)->recorded_by_user_id]);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMark::query()->count()));
    }
}
