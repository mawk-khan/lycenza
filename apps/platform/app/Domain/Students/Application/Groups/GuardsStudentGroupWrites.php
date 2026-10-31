<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * SIS 11.7 / 11.8: the steps every Student community membership write shares
 * (StudentHouseService, StudentCommunityService): the Student is reached only
 * by a whole-School `students.view` reader (else NOT FOUND); the Student row
 * is locked FOR NO KEY UPDATE (rule 101) and the write's capability is then
 * re-read fresh under it; an end closes the day before the School-local today.
 *
 * Expects `$this->context`, `$this->students` and `$this->capabilities`.
 */
trait GuardsStudentGroupWrites
{
    /** The Student when the actor sees them whole-School, else NOT FOUND. */
    private function visibleStudent(School $school, User $actor, string $studentId): Student
    {
        $scope = $this->students->scopeOf($actor, 'students.view', $school);
        if (! $scope->isWholeSchool()) {
            throw (new ModelNotFoundException)->setModel(Student::class, [$studentId]);
        }

        return $this->context->withSchool($school, fn () => $this->students->student($school, $scope, $studentId));
    }

    private function lockStudentRow(School $school, User $actor, string $studentId, string $capability): Student
    {
        $locked = Student::query()->where('school_id', $school->id)->whereKey($studentId)->lock('for no key update')->firstOrFail();
        if (! $this->capabilities->holdsInSchoolNow($actor, $capability, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $locked;
    }

    private function closeResponsibility(StudentGroupResponsibility $row, User $actor, string $today): void
    {
        $row->forceFill(['ends_on' => $this->dayBefore($today), 'ended_by_user_id' => $actor->id, 'version' => $row->version + 1])->save();
    }

    private function dayBefore(string $today): string
    {
        return CarbonImmutable::parse($today)->subDay()->toDateString();
    }
}
