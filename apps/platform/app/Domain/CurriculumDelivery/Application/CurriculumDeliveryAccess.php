<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-1 (ADR 0076 §11.7, §37): the ONE place the administrative (Tier 1)
 * Curriculum Delivery surface reads or targets deliveries -- and the
 * Offering, Section and Syllabus Unit a delivery is started for -- under the
 * actor's CampusScope (`curriculum.delivery.view`/`.manage` are
 * Campus-adopted).
 *
 * - A delivery's Campus is stored when it is started (`campus_id` NOT NULL,
 *   composite-FK-bound to its Section and Offering) and never changes.
 * - Out of scope is NOT FOUND (404), exactly like another School's record;
 *   a Syllabus Unit is found only through an in-scope Offering, so a unit
 *   of another Campus's Offering is never confirmed to exist.
 * - The curriculum definition itself (`syllabus.*`) stays whole-School
 *   configuration; the delivery page reads an in-scope Offering's units.
 *   Teacher delivery (`curriculum.delivery.teacher`) is not Campus-adopted
 *   (TeacherDeliveryAccess).
 */
final class CurriculumDeliveryAccess
{
    /** @return Builder<CurriculumDelivery> */
    public function deliveries(CampusScope $scope): Builder
    {
        return $scope->restrict(CurriculumDelivery::query(), 'curriculum_deliveries.campus_id');
    }

    public function delivery(CampusScope $scope, string $id): CurriculumDelivery
    {
        return $this->one($this->deliveries($scope), $id, CurriculumDelivery::class);
    }

    /** @return Builder<SubjectOffering> */
    public function offerings(CampusScope $scope): Builder
    {
        return $scope->restrict(SubjectOffering::query());
    }

    public function offering(CampusScope $scope, string $id): SubjectOffering
    {
        return $this->one($this->offerings($scope), $id, SubjectOffering::class);
    }

    /** @return Builder<Section> */
    public function sections(CampusScope $scope): Builder
    {
        return $scope->restrict(Section::query());
    }

    public function section(CampusScope $scope, string $id): Section
    {
        return $this->one($this->sections($scope), $id, Section::class);
    }

    /** A Syllabus Unit of an Offering within the scope (any Offering of the School for a whole-School actor). */
    public function unit(CampusScope $scope, string $id): SyllabusUnit
    {
        $query = $scope->isWholeSchool()
            ? SyllabusUnit::query()
            : SyllabusUnit::query()->whereIn('subject_offering_id', $this->offerings($scope)->select('id'));

        return $this->one($query, $id, SyllabusUnit::class);
    }

    /**
     * The Campus check the delivery write service runs inside its
     * transaction (the DeliveryWriteGuard hook; for a change, on the
     * unlocked row and again if it moved under the lock). None for a
     * whole-School actor, so that path is unchanged.
     */
    public function writeGuard(CampusScope $scope): ?DeliveryWriteGuard
    {
        return $scope->isWholeSchool() ? null : new CampusDeliveryWriteGuard($scope);
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
