<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Exceptions\AssignmentNotOwnedException;
use App\Domain\LMS\Application\Exceptions\AssignmentOutsideTeachingAssignmentException;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\School;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * TCH.5D (ADR 0063 section 37) -- the Assignment write guard: the shared
 * TeacherLmsGuard rule under `lms.assignments.teacher`.
 */
final class TeacherAssignmentGuard extends TeacherLmsGuard implements AssignmentWriteGuard
{
    public function beforeCreate(School $school, SubjectOffering $offering, array $sectionIds): SectionAudience
    {
        return $this->audienceFor($school, $offering, $sectionIds);
    }

    public function beforeWrite(School $school, Assignment $assignment): void
    {
        $this->holdWrite($school, $assignment);
    }

    protected function capability(): string
    {
        return TeacherAssignmentAccess::CAPABILITY;
    }

    protected function newScope(string $employeeId, string $asOf, array $periods): TeacherLmsScope
    {
        return new TeacherAssignmentScope($employeeId, $asOf, $periods);
    }

    protected function ownershipOf(School $school, Model $resource): LmsResourceOwnership
    {
        return $this->resources->forAssignment($school, (string) $resource->getKey());
    }

    protected function notOwned(): Throwable
    {
        return new AssignmentNotOwnedException;
    }

    protected function outsideTeachingAssignment(): Throwable
    {
        return new AssignmentOutsideTeachingAssignmentException;
    }
}
