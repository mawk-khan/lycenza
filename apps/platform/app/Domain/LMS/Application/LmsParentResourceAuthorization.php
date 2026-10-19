<?php

namespace App\Domain\LMS\Application;

use App\Domain\LMS\Application\Exceptions\AssignmentNotOwnedException;
use App\Domain\LMS\Application\Exceptions\AssignmentOutsideTeachingAssignmentException;
use App\Domain\LMS\Application\Exceptions\LearningContentNotOwnedException;
use App\Domain\LMS\Application\Exceptions\LearningContentOutsideTeachingAssignmentException;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
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
 * Tier 2 (Learning Content since TCH.5C, Assignments since TCH.5D): an
 * actor without the parent kind's Tier 1 capability but holding its
 * owned-scope capability (`lms.content.teacher` / `lms.assignments.teacher`)
 * gets the parent row's teacher rule --
 * - read: the row is visible to them (TeacherLearningContentScope), else
 *   the same 404 as an unknown row;
 * - write: fresh check first (authorizeWrite, before Documents stores any
 *   bytes), then holdWrite() inside the Documents transaction, which runs
 *   the parent's write guard (ActingEmployee and every audience Section's
 *   TeachingAssignment held, owner-only).
 *
 * There is no Submission parent: Submission is cancelled (ADR 0039).
 *
 * ORG.7D-1 (ADR 0076 §37): the Tier 1 keys are Campus-adopted, so Tier 1 is
 * decided in the actor's CampusScope -- whole School as before, or a
 * Campus set covering the parent's Campus (its Offering's,
 * LmsAdministrationAccess::campusOf()). A Campus-scoped holder whose scope
 * does not cover the parent gets the same not-found as an unknown parent
 * (unless the teacher path applies); an actor holding the key nowhere is
 * refused by the `campus-capability` Gate exactly as before.
 */
class LmsParentResourceAuthorization
{
    public const LEARNING_CONTENT = 'learning_content';

    public const ASSIGNMENT = 'assignment';

    private const READ = [self::LEARNING_CONTENT => 'lms.content.view', self::ASSIGNMENT => 'lms.assignments.view'];

    private const WRITE = [self::LEARNING_CONTENT => 'lms.content.manage', self::ASSIGNMENT => 'lms.assignments.manage'];

    /** The owned-scope (Tier 2) capability of each parent kind. */
    private const TEACHER = [self::LEARNING_CONTENT => TeacherLearningContentAccess::CAPABILITY, self::ASSIGNMENT => TeacherAssignmentAccess::CAPABILITY];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly TeacherLearningContentAccess $teacherContent,
        private readonly TeacherAssignmentAccess $teacherAssignments,
        private readonly LmsResourceOwnershipReader $resources,
        private readonly CampusScopeResolver $scopes,
        private readonly LmsAdministrationAccess $access,
    ) {}

    public function authorizeRead(User $actor, School $school, string $parentType, string $parentId): void
    {
        $scope = $this->tierOneScope($actor, $school, self::READ, $parentType);
        if ($this->campusCovers($scope, $parentType, $parentId)) {
            return;
        }

        if ($this->usesTeacherPath($actor, $school, $parentType)) {
            $parentType === self::LEARNING_CONTENT
                ? $this->teacherContent->visible($actor, $school, $parentId)
                : $this->teacherAssignments->visible($actor, $school, $parentId);

            return;
        }

        $this->refuseTierOne($actor, $school, self::READ[$parentType], $scope, $parentType, $parentId);
    }

    /**
     * Fresh check, before Documents does any storage I/O -- so a refused
     * teacher never leaves orphan bytes. Not authoritative: holdWrite() is.
     */
    public function authorizeWrite(User $actor, School $school, string $parentType, string $parentId): void
    {
        $scope = $this->tierOneScope($actor, $school, self::WRITE, $parentType);
        if ($this->campusCovers($scope, $parentType, $parentId)) {
            return;
        }

        if ($this->usesTeacherPath($actor, $school, $parentType)) {
            if ($parentType === self::LEARNING_CONTENT) {
                $scope = $this->teacherContent->scope($actor, $school);
                $ownership = $this->resources->forLearningContent($school, $this->teacherContent->visible($actor, $school, $parentId, $scope)->id);
                $this->assertWritable($scope, $ownership, new LearningContentNotOwnedException, new LearningContentOutsideTeachingAssignmentException);
            } else {
                $scope = $this->teacherAssignments->scope($actor, $school);
                $ownership = $this->resources->forAssignment($school, $this->teacherAssignments->visible($actor, $school, $parentId, $scope)->id);
                $this->assertWritable($scope, $ownership, new AssignmentNotOwnedException, new AssignmentOutsideTeachingAssignmentException);
            }

            return;
        }

        $this->refuseTierOne($actor, $school, self::WRITE[$parentType], $scope, $parentType, $parentId);
    }

    /**
     * The authoritative write check, run by Documents INSIDE its write
     * transaction. Tier 1 re-checks the capability; the teacher path runs
     * the parent's write guard, holding identity and every audience
     * Section's ownership (FOR SHARE) until the Document row commits.
     */
    public function holdWrite(User $actor, School $school, string $parentType, string $parentId): void
    {
        $scope = $this->tierOneScope($actor, $school, self::WRITE, $parentType);
        if ($this->campusCovers($scope, $parentType, $parentId)) {
            return;
        }

        if ($this->usesTeacherPath($actor, $school, $parentType)) {
            if ($parentType === self::LEARNING_CONTENT) {
                $this->teacherContent->guard($actor)->beforeWrite($school, LearningContent::query()->where('school_id', $school->id)->findOrFail($parentId));
            } else {
                $this->teacherAssignments->guard($actor)->beforeWrite($school, Assignment::query()->where('school_id', $school->id)->findOrFail($parentId));
            }

            return;
        }

        $this->refuseTierOne($actor, $school, self::WRITE[$parentType], $scope, $parentType, $parentId);
    }

    /**
     * ORG.7D-1: where the actor holds the parent kind's Tier 1 key -- the
     * whole School, some Campuses, or nowhere.
     *
     * @param  array<string, string>  $tierOne
     */
    private function tierOneScope(User $actor, School $school, array $tierOne, string $parentType): CampusScope
    {
        return $this->scopes->campusScope($actor, $tierOne[$parentType] ?? $this->unknown($parentType), $school);
    }

    /** ORG.7D-1: Tier 1 decides "allowed" -- whole School, or a Campus scope covering the parent's Campus. */
    public function campusCovers(CampusScope $scope, string $parentType, string $parentId): bool
    {
        return $scope->isWholeSchool() || (! $scope->isNone() && $scope->covers($this->access->campusOf($parentType, $parentId)));
    }

    /** Teacher (Tier 2) path: the owned-scope key, when Tier 1 did not allow. */
    private function usesTeacherPath(User $actor, School $school, string $parentType): bool
    {
        return isset(self::TEACHER[$parentType])
            && $this->capabilities->canInSchool($actor, self::TEACHER[$parentType], $school);
    }

    /**
     * ORG.7D-1: Tier 1 held only in other Campuses is the same not-found as
     * an unknown parent; held nowhere, the Gate refuses (403) as before.
     */
    private function refuseTierOne(User $actor, School $school, string $capability, CampusScope $scope, string $parentType, string $parentId): never
    {
        if (! $scope->isNone()) {
            throw (new ModelNotFoundException)->setModel($parentType === self::ASSIGNMENT ? Assignment::class : LearningContent::class, [$parentId]);
        }

        Gate::forUser($actor)->authorize('campus-capability', [$capability, $school]);

        // The Gate refuses whenever the scope is none; this is unreachable.
        throw (new ModelNotFoundException)->setModel(LearningContent::class, [$parentId]);
    }

    private function assertWritable(TeacherLmsScope $scope, LmsResourceOwnership $ownership, \Throwable $notOwned, \Throwable $outside): void
    {
        if (! $scope->isOwner($ownership)) {
            throw $notOwned;
        }
        if (! $scope->teachesEvery($ownership)) {
            throw $outside;
        }
    }

    private function unknown(string $parentType): never
    {
        throw new InvalidArgumentException("Not an LMS parent resource type: {$parentType}");
    }
}
