<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * S7 (ADR 0063 §47): ending an employment ends its teaching ownership under
 * real concurrency -- two genuinely separate OS processes against real
 * PostgreSQL, the holder's work held uncommitted until the contender is
 * observed blocked on it (ForcesConcurrentOverlap). Committed synthetic
 * fixtures, purged afterwards.
 *
 * One order everywhere: EmploymentRecord (HR) -> teaching assignment rows.
 * - X1: a teacher's use-time authorization vs the end -- the end first refuses
 *   the authorization; an authorization first makes the end wait, then the
 *   end still ends the assignment.
 * - X2 / X3: a required / elective create vs the end -- a create first makes
 *   the end wait and is then ended by it; an end first refuses the create.
 * - X4: a rehire vs the stale assignment -- the teacher, eligible again, owns
 *   nothing until a new assignment; a create behind the rehire waits, then
 *   succeeds.
 * - X5: teacher StudentMark entry (RES.4) vs the end -- an entry first makes
 *   the end wait and its mark stands; the end first refuses the entry.
 */
class EmploymentEndTeachingOwnershipConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTeacherStudentMarkFixtures, ForcesConcurrentOverlap;

    private const string END = '2026-09-20';

    private const string REHIRED = '2026-10-01';

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/employment-end-op.php', ...$args];
    }

    /** @return list<string> */
    private function markOp(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    private function hr(array &$w): User
    {
        return $w['hr'] ??= $this->createUserWithCapabilities($w['school'], ['hr.employees.view', 'hr.employees.manage', 'hr.employees.assignments.manage']);
    }

    /** @return list<string> */
    private function endEmployment(array &$w, Employee $employee): array
    {
        return $this->op('end-employment', $w['school']->id, $this->hr($w)->id, $employee->id, self::END);
    }

    /** @return list<string> */
    private function authorize(array $w, User $teacher, string $date): array
    {
        return $this->op('teacher-delivery', $w['school']->id, $teacher->id, $w['a1']->id, $w['required']->id, $date);
    }

    /** @return array{0: array<string, mixed>, 1: User, 2: Employee} */
    private function world(): array
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->hr($w);

        return [$w, $teacher, $employee];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function endOf(array $w, TeachingAssignment|ElectiveTeachingAssignment|string $assignment, bool $elective = false): array
    {
        $model = $elective ? ElectiveTeachingAssignment::class : TeachingAssignment::class;
        $id = is_string($assignment) ? $assignment : $assignment->id;
        $row = $this->inMarksSchool($w['school'], fn () => $model::query()->findOrFail($id));

        return [$row->ends_on?->toDateString(), $row->end_reason];
    }

    #[Test]
    public function x1_an_authorization_behind_the_end_is_refused(): void
    {
        [$w, $teacher, $employee] = $this->world();
        $assignment = $this->ownSection($w, $employee, 'a1');
        $today = now()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->endEmployment($w, $employee), $this->authorize($w, $teacher, $today));

        $this->assertSame('employment-ended', $holder);
        $this->assertSame('refused:ActingEmployeeUnavailableException', $contender, 'the authorization waited on the EmploymentRecord and re-read it ended');
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, $assignment));
    }

    #[Test]
    public function x1_an_end_behind_an_authorization_waits_and_still_ends_the_assignment(): void
    {
        [$w, $teacher, $employee] = $this->world();
        $assignment = $this->ownSection($w, $employee, 'a1');
        $today = now()->toDateString();

        [$holder, $contender] = $this->raceWithHeldHolder($this->authorize($w, $teacher, $today), $this->endEmployment($w, $employee));

        $this->assertSame('authorized', $holder, 'decided while employed and owning');
        $this->assertSame('employment-ended', $contender);
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, $assignment));
    }

    #[Test]
    public function x2_a_required_create_held_first_is_ended_by_the_waiting_employment_end(): void
    {
        [$w, , $employee] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('create-required', $w['school']->id, $w['assigner']->id, $employee->id, $w['a2']->id, $w['required']->id, '2026-06-01'),
            $this->endEmployment($w, $employee),
        );

        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('employment-ended', $contender, 'the end waited on the create\'s EmploymentRecord share');
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, substr($holder, 8)), 'the end saw the committed row and ended it');

        // The other order: the create waits on the end and finds no covering employment.
        [, $other] = $this->markTeacher($w);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->endEmployment($w, $other),
            $this->op('create-required', $w['school']->id, $w['assigner']->id, $other->id, $w['a2']->id, $w['required']->id, '2026-06-01'),
        );
        $this->assertSame(['employment-ended', 'rejected:TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE'], [$holder, $contender]);
    }

    #[Test]
    public function x3_an_elective_create_held_first_is_ended_by_the_waiting_employment_end(): void
    {
        [$w, , $employee] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('create-elective', $w['school']->id, $w['assigner']->id, $employee->id, $w['elective']->id, '2026-06-01'),
            $this->endEmployment($w, $employee),
        );

        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('employment-ended', $contender);
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, substr($holder, 8), elective: true));

        [, $other] = $this->markTeacher($w);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->endEmployment($w, $other),
            $this->op('create-elective', $w['school']->id, $w['assigner']->id, $other->id, $w['elective']->id, '2026-06-01'),
        );
        $this->assertSame(['employment-ended', 'rejected:TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE'], [$holder, $contender]);
    }

    #[Test]
    public function x4_a_rehire_never_revives_the_stale_assignment(): void
    {
        [$w, $teacher, $employee] = $this->world();
        $stale = $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);
        $hr = $this->hr($w);
        $this->assertSame('employment-ended', $this->runOp($this->endEmployment($w, $employee)));
        $today = now()->toDateString();

        // The authorization waits on the rehire's Employee lock, then finds an eligible teacher who owns nothing.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('rehire', $w['school']->id, $hr->id, $employee->id, self::REHIRED),
            $this->authorize($w, $teacher, $today),
        );
        $this->assertSame(['rehired', 'refused:DeliveryOutsideTeachingAssignmentException'], [$holder, $contender], 'eligible again, but the old assignment ended at the employment end');
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, $stale));

        // Authority returns only through a new assignment.
        $this->ownSection($w, $employee, 'a1', self::REHIRED);
        $this->assertSame('authorized', $this->runOp($this->authorize($w, $teacher, $today)));
    }

    #[Test]
    public function x4_a_create_behind_a_rehire_waits_then_assigns_the_new_employment(): void
    {
        [$w, , $employee] = $this->world();
        $this->ownSection($w, $employee, 'a1');
        $hr = $this->hr($w);
        $this->assertSame('employment-ended', $this->runOp($this->endEmployment($w, $employee)));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('rehire', $w['school']->id, $hr->id, $employee->id, self::REHIRED),
            $this->op('create-required', $w['school']->id, $w['assigner']->id, $employee->id, $w['a1']->id, $w['required']->id, self::REHIRED),
        );
        $this->assertSame('rehired', $holder);
        $this->assertStringStartsWith('created:', $contender, 'the create waited on the Employee, then saw the new employment');
        $this->assertSame([null, null], $this->endOf($w, substr($contender, 8)));
    }

    #[Test]
    public function x5_a_teacher_mark_held_first_stands_and_the_end_waits(): void
    {
        [$w, $teacher, $employee] = $this->world();
        $assignment = $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->markOp('teacher-record', $w['school']->id, $w['paper']->id, $teacher->id, $student->id, 'present', '41', '-'),
            $this->endEmployment($w, $employee),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('employment-ended', $contender, 'the end waited for the mark that relied on the employment');
        $this->assertSame('41.00', (string) $this->markOf($w, $student)->value);
        $this->assertSame([self::END, 'employment_ended'], $this->endOf($w, $assignment));
    }

    #[Test]
    public function x5_a_teacher_mark_behind_the_end_is_refused(): void
    {
        [$w, $teacher, $employee] = $this->world();
        $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->endEmployment($w, $employee),
            $this->markOp('teacher-record', $w['school']->id, $w['paper']->id, $teacher->id, $student->id, 'present', '41', '-'),
        );

        $this->assertSame('employment-ended', $holder);
        $this->assertStringStartsWith('error:App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException', $contender, 'the entry waited on the EmploymentRecord and re-read it ended');
        $this->assertNull($this->markOf($w, $student));
    }

    /** One operation, unraced, in its own process (it commits). */
    private function runOp(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        return trim($process->getOutput());
    }
}
