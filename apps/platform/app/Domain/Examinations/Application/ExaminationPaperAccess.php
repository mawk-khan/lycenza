<?php

namespace App\Domain\Examinations\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-1 (ADR 0076 §11.7, §37): the ONE place the Examination Paper
 * surface reads or targets papers -- and the Subject Offering a new paper is
 * scheduled for -- under the actor's CampusScope (`examinations.papers.view`
 * / `.manage` are Campus-adopted; nothing else in Examinations is).
 *
 * - A paper stores its Campus when created (`examination_papers.campus_id`
 *   NOT NULL, derived from and FK-consistent with its Offering); its
 *   Offering never changes.
 * - Out of scope is NOT FOUND (404), exactly like another School's paper.
 * - The parent Examination (`examinations.definitions.*`) is School-wide
 *   configuration: the paper page reads its identity and window as context
 *   only, and the Examination list stays whole-School.
 * - Marks and results are not Campus-adopted: a Campus grant confers no
 *   marks capability, so the marks surfaces behind a paper stay
 *   whole-School (and production-refused) exactly as before.
 */
final class ExaminationPaperAccess
{
    /** @return Builder<ExaminationPaper> */
    public function papers(CampusScope $scope): Builder
    {
        return $scope->restrict(ExaminationPaper::query());
    }

    public function paper(CampusScope $scope, string $id): ExaminationPaper
    {
        return $this->one($this->papers($scope), $id, ExaminationPaper::class);
    }

    /** A paper of the given Examination within the scope. */
    public function paperOf(CampusScope $scope, string $examinationId, string $id): ExaminationPaper
    {
        return $this->one($this->papers($scope)->where('examination_id', $examinationId), $id, ExaminationPaper::class);
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
