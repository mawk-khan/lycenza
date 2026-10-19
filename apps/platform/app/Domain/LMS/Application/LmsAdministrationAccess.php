<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-1 (ADR 0076 §11.7, §37): the ONE place the administrative (Tier 1)
 * LMS surface reads or targets Learning Content and Assignments -- and the
 * Subject Offering they belong to -- under the actor's CampusScope
 * (`lms.content.*` and `lms.assignments.*` view/manage are Campus-adopted).
 *
 * - Campus source: the owning Subject Offering. Content and Assignments
 *   carry no Campus of their own; their Offering never changes after
 *   creation, and an Offering's Campus is fixed (its Section-audience rows
 *   pin the same Campus by composite foreign key). The predicate is an SQL
 *   subquery on in-scope Offerings -- never a per-row check.
 * - Out of scope is NOT FOUND (404), exactly like another School's record.
 * - No Student roster is read: Submission does not exist (ADR 0039), and
 *   audiences are Sections, never Students.
 * - Documents attached to LMS parents follow the same scope through
 *   LmsParentResourceAuthorization::campusCovers().
 * - Teacher LMS (`lms.*.teacher`) is not Campus-adopted
 *   (TeacherLearningContentAccess / TeacherAssignmentAccess).
 */
final class LmsAdministrationAccess
{
    /** @return Builder<SubjectOffering> */
    public function offerings(CampusScope $scope): Builder
    {
        return $scope->restrict(SubjectOffering::query());
    }

    public function offering(CampusScope $scope, string $id): SubjectOffering
    {
        return $this->one($this->offerings($scope), $id, SubjectOffering::class);
    }

    /** @return Builder<LearningContent> */
    public function contents(CampusScope $scope): Builder
    {
        return $this->ofOfferingsIn($scope, LearningContent::query());
    }

    public function content(CampusScope $scope, string $id): LearningContent
    {
        return $this->one($this->contents($scope), $id, LearningContent::class);
    }

    /** @return Builder<Assignment> */
    public function assignments(CampusScope $scope): Builder
    {
        return $this->ofOfferingsIn($scope, Assignment::query());
    }

    public function assignment(CampusScope $scope, string $id): Assignment
    {
        return $this->one($this->assignments($scope), $id, Assignment::class);
    }

    /** The Campus of an LMS parent (its Offering's), or null when there is no such parent. */
    public function campusOf(string $parentType, string $parentId): ?string
    {
        $parent = match ($parentType) {
            LmsParentResourceAuthorization::LEARNING_CONTENT => LearningContent::query(),
            LmsParentResourceAuthorization::ASSIGNMENT => Assignment::query(),
            default => null,
        };
        if ($parent === null || ! Str::isUuid($parentId)) {
            return null;
        }
        $offeringId = $parent->whereKey($parentId)->value('subject_offering_id');
        $campusId = is_string($offeringId) ? SubjectOffering::query()->whereKey($offeringId)->value('campus_id') : null;

        return is_string($campusId) ? $campusId : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function ofOfferingsIn(CampusScope $scope, Builder $query): Builder
    {
        return $scope->isWholeSchool() ? $query : $query->whereIn('subject_offering_id', $this->offerings($scope)->select('id'));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function one(Builder $query, string $id, string $model): Model
    {
        $found = Str::isUuid($id) ? $query->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel($model, [$id]);
    }
}
