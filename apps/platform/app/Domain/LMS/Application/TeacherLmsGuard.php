<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\LMS\Application\Exceptions\LmsAudienceSectionNotTaughtException;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * TCH.5C/TCH.5D (ADR 0063 sections 20, 34.4-34.6, 36, 37) -- the Tier 2 write
 * guard shared by Learning Content and Assignments, run by the resource's
 * service (and, through LmsParentResourceAuthorization, by Documents) INSIDE
 * the write transaction, before any resource or Document row lock.
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
abstract class TeacherLmsGuard
{
    use AuthorizesCapability;

    private ?ActingEmployee $acting = null;

    public function __construct(
        private readonly User $actor,
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
        protected readonly LmsResourceOwnershipReader $resources,
    ) {}

    /** The kind's owned-scope capability. */
    abstract protected function capability(): string;

    /** @param  list<OwnedTeachingPeriod>  $periods */
    abstract protected function newScope(string $employeeId, string $asOf, array $periods): TeacherLmsScope;

    abstract protected function ownershipOf(School $school, Model $resource): LmsResourceOwnership;

    abstract protected function notOwned(): Throwable;

    abstract protected function outsideTeachingAssignment(): Throwable;

    /** @param  list<string>  $sectionIds */
    protected function audienceFor(School $school, SubjectOffering $offering, array $sectionIds): SectionAudience
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

    protected function holdWrite(School $school, Model $resource): void
    {
        $acting = $this->holdActor($school);
        // Owner and audience are immutable (database-enforced), so this
        // unlocked read cannot go stale.
        $ownership = $this->ownershipOf($school, $resource);
        $scope = $this->scope($school, $acting);

        if (! $scope->canRead($ownership, (string) $resource->getAttribute('status'))) {
            throw (new ModelNotFoundException)->setModel($resource::class, [$resource->getKey()]);
        }

        if (! $scope->isOwner($ownership)) {
            throw $this->notOwned();
        }

        $sections = $ownership->audienceSectionIds;
        sort($sections);
        $this->holdEvery($school, $acting, $ownership->subjectOfferingId, $sections, $this->outsideTeachingAssignment());
    }

    /** @param  list<string>  $sortedSectionIds */
    private function holdEvery(School $school, ActingEmployee $acting, string $offeringId, array $sortedSectionIds, Throwable $refusal): void
    {
        foreach ($sortedSectionIds as $sectionId) {
            if (! $this->ownership->hold($school, $acting->employeeId, $sectionId, $offeringId, $acting->asOf)) {
                throw $refusal;
            }
        }
    }

    private function holdActor(School $school): ActingEmployee
    {
        $this->authorizeCapabilityFor($this->actor, $this->capability(), $school);

        return $this->acting ??= $this->identities->hold($this->actor, $school);
    }

    private function scope(School $school, ActingEmployee $acting): TeacherLmsScope
    {
        return $this->newScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }
}
