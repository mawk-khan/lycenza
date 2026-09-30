<?php

namespace App\Domain\LMS\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.5C (ADR 0063 sections 34, 36) -- the owned (Tier 2) Learning Content
 * entry point:
 *
 *   lms.content.teacher
 *   AND a verified ActingEmployee (today, School-local)
 *   AND the row's owner/audience rule (TeacherLearningContentScope)
 *   AND TeachingAssignment coverage today (TeachingOwnership).
 *
 * `scope()` and `visible()` are fresh reads; every write goes through
 * `guard()`, which holds identity and ownership in the write transaction.
 * The role key is never read. Tier 1 (`lms.content.view/.manage`) is not
 * handled here and needs none of these facts.
 */
class TeacherLearningContentAccess
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'lms.content.teacher';

    public function __construct(
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
        private readonly LmsResourceOwnershipReader $resources,
    ) {}

    public function scope(User $actor, School $school): TeacherLearningContentScope
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $acting = $this->identities->resolve($actor, $school);

        return new TeacherLearningContentScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    /**
     * The row, if this teacher may read it today; otherwise the same 404 as
     * an unknown id (another teacher's unpublished row, an unpublished
     * Offering-wide row, an untaught class, another School).
     */
    public function visible(User $actor, School $school, string $learningContentId, ?TeacherLearningContentScope $scope = null): LearningContent
    {
        $scope ??= $this->scope($actor, $school);
        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContentId);

        if (! $scope->canRead($this->resources->forLearningContent($school, $content->id), $content->status)) {
            throw (new ModelNotFoundException)->setModel(LearningContent::class, [$learningContentId]);
        }

        return $content;
    }

    public function guard(User $actor): TeacherLearningContentGuard
    {
        return new TeacherLearningContentGuard($actor, $this->identities, $this->ownership, $this->resources);
    }
}
