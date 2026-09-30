<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\LMS\Application\Exceptions\LearningContentNotOwnedException;
use App\Domain\LMS\Application\Exceptions\LearningContentOutsideTeachingAssignmentException;
use App\Domain\LMS\Application\Exceptions\LmsAudienceSectionNotTaughtException;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.5C (ADR 0063 sections 20, 34.4-34.6, 36) -- the Tier 2 write guard,
 * run by LearningContentService (and, through LmsParentResourceAuthorization,
 * by Documents) INSIDE the write transaction, before any Learning Content
 * or Document row lock.
 *
 * Lock order: School -> membership -> User -> Employee -> EmploymentRecord
 * (ActingEmployeeResolver::hold) -> the TeachingAssignment of each audience
 * Section in ASCENDING Section id (TeachingOwnership::hold, FOR SHARE) ->
 * the resource row. The Section order is sorted here, never taken from the
 * client, so two multi-Section writes cannot take the same assignments in
 * opposite orders.
 *
 * - create: every requested Section must be taught today (422 otherwise);
 *   the owner is the ActingEmployee -- never client input;
 * - write: the row must be visible (404 otherwise), owned by this Employee
 *   (403) and every audience Section taught today (422).
 */
final class TeacherLearningContentGuard implements LearningContentWriteGuard
{
    use AuthorizesCapability;

    private ?ActingEmployee $acting = null;

    public function __construct(
        private readonly User $actor,
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
        private readonly LmsResourceOwnershipReader $resources,
    ) {}

    public function beforeCreate(School $school, SubjectOffering $offering, array $sectionIds): SectionAudience
    {
        $acting = $this->holdActor($school);

        // An Offering the teacher teaches no Section of is not disclosed.
        if ($this->scope($school, $acting)->taughtSections($offering->id) === []) {
            throw (new ModelNotFoundException)->setModel(SubjectOffering::class, [$offering->id]);
        }

        $sections = array_values(array_unique($sectionIds));
        sort($sections);
        $this->holdEvery($school, $acting, $offering->id, $sections, new LmsAudienceSectionNotTaughtException);

        return new SectionAudience($acting->employeeId, $sections);
    }

    public function beforeWrite(School $school, LearningContent $content): void
    {
        $acting = $this->holdActor($school);
        // Owner and audience are immutable (database-enforced), so this
        // unlocked read cannot go stale.
        $ownership = $this->resources->forLearningContent($school, $content->id);
        $scope = $this->scope($school, $acting);

        if (! $scope->canRead($ownership, $content->status)) {
            throw (new ModelNotFoundException)->setModel(LearningContent::class, [$content->id]);
        }

        if (! $scope->isOwner($ownership)) {
            throw new LearningContentNotOwnedException;
        }

        $sections = $ownership->audienceSectionIds;
        sort($sections);
        $this->holdEvery($school, $acting, $ownership->subjectOfferingId, $sections, new LearningContentOutsideTeachingAssignmentException);
    }

    /** @param  list<string>  $sortedSectionIds */
    private function holdEvery(School $school, ActingEmployee $acting, string $offeringId, array $sortedSectionIds, \Throwable $refusal): void
    {
        foreach ($sortedSectionIds as $sectionId) {
            if (! $this->ownership->hold($school, $acting->employeeId, $sectionId, $offeringId, $acting->asOf)) {
                throw $refusal;
            }
        }
    }

    private function holdActor(School $school): ActingEmployee
    {
        $this->authorizeCapabilityFor($this->actor, TeacherLearningContentAccess::CAPABILITY, $school);

        return $this->acting ??= $this->identities->hold($this->actor, $school);
    }

    private function scope(School $school, ActingEmployee $acting): TeacherLearningContentScope
    {
        return new TeacherLearningContentScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }
}
