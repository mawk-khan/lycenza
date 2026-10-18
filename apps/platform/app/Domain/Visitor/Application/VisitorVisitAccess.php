<?php

namespace App\Domain\Visitor\Application;

use App\Domain\Visitor\Application\Exceptions\ConcurrentCheckInConflictException;
use App\Domain\Visitor\Application\Exceptions\VisitorAlreadyCheckedInException;
use App\Domain\Visitor\Application\Exceptions\VisitorCheckInUnavailableException;
use App\Domain\Visitor\Application\Exceptions\VisitorException;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\Campus;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7A (ADR 0076 §11.7, §33): the ONE place a Visit is read or targeted
 * under the actor's CampusScope (`visitor.visits.*` are Campus-adopted).
 * Every Visit has a Campus, so there is no School-wide (NULL-Campus) Visit.
 *
 * - Lists: the Campus predicate is in the SQL (CampusScope::restrict()).
 * - One Visit outside the scope is NOT FOUND (404), exactly like a Visit of
 *   another School -- its existence is never disclosed.
 * - A new Visit's Campus must be within the scope; a Campus-scoped actor's
 *   scope contains only ACTIVE Campuses, so an inactive Campus takes no new
 *   Visit from them. The whole-School path is unchanged.
 *
 * VisitorCampusScopeArchitectureTest keeps the Visitor controllers from
 * querying VisitorVisit or resolving a Campus anywhere else.
 */
final class VisitorVisitAccess
{
    /** @return Builder<VisitorVisit> */
    public function visits(CampusScope $scope): Builder
    {
        return $scope->restrict(VisitorVisit::query());
    }

    public function visit(CampusScope $scope, string $id): VisitorVisit
    {
        $visit = Str::isUuid($id) ? $this->visits($scope)->find($id) : null;

        return $visit ?? throw (new ModelNotFoundException)->setModel(VisitorVisit::class, [$id]);
    }

    public function campusForNewVisit(CampusScope $scope, string $campusId): Campus
    {
        $campus = Str::isUuid($campusId) && $scope->covers($campusId) ? Campus::query()->find($campusId) : null;

        return $campus ?? throw (new ModelNotFoundException)->setModel(Campus::class, [$campusId]);
    }

    /**
     * ORG.7B (ADR 0076 §34): a check-in conflict seen by a Campus-scoped
     * actor is always the same generic refusal -- whether the active Visit is
     * in their scope or not -- so the answer never contrasts an in-scope
     * conflict with an out-of-scope one. Whole-School actors keep the
     * specific message.
     */
    public function concealCheckInConflict(CampusScope $scope, VisitorAlreadyCheckedInException|ConcurrentCheckInConflictException $e): VisitorException
    {
        return $scope->isWholeSchool() ? $e : new VisitorCheckInUnavailableException;
    }

    /** @return list<array{id: string, name: string}> the Campuses offered for a new Visit */
    public function campusesForNewVisit(CampusScope $scope): array
    {
        return $scope->restrict(Campus::query(), 'id')->orderBy('name')->get()
            ->map(fn (Campus $c): array => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }
}
