<?php

namespace App\Domain\Timetable\Application;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Timetable\Application\Exceptions\TimetableException;
use App\Domain\Timetable\Application\Exceptions\TimetableSlotUnavailableException;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-1 (ADR 0076 §11.7, §37): the ONE place a Timetable entry -- and the
 * Campus-bound parents a scheduler picks for one (Subject Offering, Section,
 * Room) -- is read or targeted under the actor's CampusScope
 * (`timetable.schedule.*` are Campus-adopted).
 *
 * - An entry's Campus is its Offering's (TimetableScheduleService::
 *   deriveContext()); the Section and Room are bound to the same Campus by
 *   composite foreign keys (rule 70, ORG.7D-0 R1). `campus_id` is NOT NULL,
 *   so there is no School-wide entry.
 * - Lists: the Campus predicate is in the SQL (CampusScope::restrict()).
 * - One entry, Offering, Section or Room outside the scope is NOT FOUND
 *   (404), exactly like another School's -- its existence is never
 *   disclosed. A request-supplied id never widens the scope.
 * - A Campus-scoped actor's slot conflict is one generic refusal, whether
 *   the other class is in scope or not (concealSlotConflict()).
 * - School-wide lookups the form needs (active teachers by name, the bell
 *   schedule) are declared in CampusScopeCatalogue::DOMAINS['timetable'].
 *
 * The whole-School path is unchanged. CampusScopeAdoptionArchitectureTest
 * keeps the Timetable schedule controllers from querying these models
 * anywhere else.
 */
final class TimetableScheduleAccess
{
    /** @return Builder<TimetableEntry> */
    public function entries(CampusScope $scope): Builder
    {
        return $scope->restrict(TimetableEntry::query());
    }

    public function entry(CampusScope $scope, string $id): TimetableEntry
    {
        return $this->one($this->entries($scope), $id, TimetableEntry::class);
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

    /** @return Builder<Room> */
    public function rooms(CampusScope $scope): Builder
    {
        return $scope->restrict(Room::query());
    }

    public function room(CampusScope $scope, string $id): Room
    {
        return $this->one($this->rooms($scope), $id, Room::class);
    }

    /**
     * ORG.7D-1 (ADR 0076 §37, D1-12): teacher, Section and Room slots stay
     * unique School-wide. A Campus-scoped actor's collision is always the
     * same generic refusal -- whether the other class is on a Campus they
     * can see or not -- so it never names, or contrasts, another Campus,
     * class, teacher or Room. Whole-School actors keep the specific message.
     */
    public function concealSlotConflict(CampusScope $scope, TimetableException $e): TimetableException
    {
        return $scope->isWholeSchool() ? $e : new TimetableSlotUnavailableException;
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
