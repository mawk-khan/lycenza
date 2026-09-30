<?php

namespace App\Domain\LMS\Application;

use App\Domain\LMS\Application\Exceptions\LearningContentNotOwnedException;
use App\Domain\LMS\Application\Exceptions\LearningContentOutsideTeachingAssignmentException;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use InvalidArgumentException;

/**
 * TCH.5B/TCH.5C (ADR 0063 sections 34.9, 35, 36) -- the LMS-owned answer to
 * "may this actor read / write the LMS resource that owns this Document?".
 *
 * Documents asks here instead of naming an LMS capability itself, so the
 * LMS resource-authorization decision lives in LMS. The direction is the
 * existing Documents -> LMS one; LMS never depends on Documents, and
 * Documents never sees an owner Employee, an audience or a
 * TeachingAssignment.
 *
 * Tier 1 first: an actor holding `lms.content.view/.manage` (or
 * `lms.assignments.view/.manage`) is decided exactly as before.
 *
 * Tier 2, LEARNING CONTENT ONLY (TCH.5C): an actor without the Tier 1
 * capability but holding `lms.content.teacher` gets the parent row's
 * teacher rule --
 * - read: the row is visible to them (TeacherLearningContentScope), else
 *   the same 404 as an unknown row;
 * - write: fresh check first (authorizeWrite, before Documents stores any
 *   bytes), then holdWrite() inside the Documents transaction, which runs
 *   the Learning Content write guard (ActingEmployee and every audience
 *   Section's TeachingAssignment held, owner-only).
 *
 * Assignments stay Tier 1 only: no `lms.assignments.teacher` exists.
 */
class LmsParentResourceAuthorization
{
    use AuthorizesCapability;

    public const LEARNING_CONTENT = 'learning_content';

    public const ASSIGNMENT = 'assignment';

    private const READ = [self::LEARNING_CONTENT => 'lms.content.view', self::ASSIGNMENT => 'lms.assignments.view'];

    private const WRITE = [self::LEARNING_CONTENT => 'lms.content.manage', self::ASSIGNMENT => 'lms.assignments.manage'];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly TeacherLearningContentAccess $teacherContent,
        private readonly LmsResourceOwnershipReader $resources,
    ) {}

    public function authorizeRead(User $actor, School $school, string $parentType, string $parentId): void
    {
        if ($this->usesTeacherPath($actor, $school, $parentType, self::READ)) {
            $this->teacherContent->visible($actor, $school, $parentId);

            return;
        }

        $this->authorizeCapabilityFor($actor, self::READ[$parentType] ?? $this->unknown($parentType), $school);
    }

    /**
     * Fresh check, before Documents does any storage I/O -- so a refused
     * teacher never leaves orphan bytes. Not authoritative: holdWrite() is.
     */
    public function authorizeWrite(User $actor, School $school, string $parentType, string $parentId): void
    {
        if ($this->usesTeacherPath($actor, $school, $parentType, self::WRITE)) {
            $scope = $this->teacherContent->scope($actor, $school);
            $content = $this->teacherContent->visible($actor, $school, $parentId, $scope);
            $ownership = $this->resources->forLearningContent($school, $content->id);

            if (! $scope->isOwner($ownership)) {
                throw new LearningContentNotOwnedException;
            }
            if (! $scope->teachesEvery($ownership)) {
                throw new LearningContentOutsideTeachingAssignmentException;
            }

            return;
        }

        $this->authorizeCapabilityFor($actor, self::WRITE[$parentType] ?? $this->unknown($parentType), $school);
    }

    /**
     * The authoritative write check, run by Documents INSIDE its write
     * transaction. Tier 1 re-checks the capability; the teacher path runs
     * the Learning Content write guard, holding identity and every audience
     * Section's ownership (FOR SHARE) until the Document row commits.
     */
    public function holdWrite(User $actor, School $school, string $parentType, string $parentId): void
    {
        if ($this->usesTeacherPath($actor, $school, $parentType, self::WRITE)) {
            $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($parentId);
            $this->teacherContent->guard($actor)->beforeWrite($school, $content);

            return;
        }

        $this->authorizeCapabilityFor($actor, self::WRITE[$parentType] ?? $this->unknown($parentType), $school);
    }

    /** @param  array<string, string>  $tierOne */
    private function usesTeacherPath(User $actor, School $school, string $parentType, array $tierOne): bool
    {
        return $parentType === self::LEARNING_CONTENT
            && ! $this->capabilities->canInSchool($actor, $tierOne[$parentType], $school)
            && $this->capabilities->canInSchool($actor, TeacherLearningContentAccess::CAPABILITY, $school);
    }

    private function unknown(string $parentType): never
    {
        throw new InvalidArgumentException("Not an LMS parent resource type: {$parentType}");
    }
}
