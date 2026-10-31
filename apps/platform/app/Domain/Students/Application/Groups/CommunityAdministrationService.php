<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * SIS 11.8 (OD-SIS-5A Club, OD-SIS-5B Activity): Club and Activity
 * administration -- whole-School `students.communities.manage`, through the
 * shared StudentGroupCatalogue. Never a House (HouseAdministrationService
 * keeps the House keys), never another type.
 *
 * `hub()` decides the Student Communities hub: it opens when the viewer may
 * see Houses (`students.houses.view`) or Clubs and Activities
 * (`students.communities.view`) in any scope, and lists only those areas.
 */
final class CommunityAdministrationService
{
    public const string VIEW = 'students.communities.view';

    public const string MANAGE = 'students.communities.manage';

    public const string ASSIGN = 'students.communities.assign';

    public function __construct(
        private readonly StudentGroupCatalogue $catalogue,
        private readonly StudentAccess $students,
    ) {}

    /**
     * Clubs or Activities with their CURRENT member count as the viewer may
     * see it (a Campus scope counts only its own Students).
     *
     * @return Collection<int, array{group: StudentGroup, members: int}>
     */
    public function list(School $school, StudentGroupType $type, CampusScope $scope): Collection
    {
        return $this->catalogue->list($school, $this->community($type), $scope);
    }

    /** @return EloquentCollection<int, StudentGroup> */
    public function active(School $school, StudentGroupType $type): EloquentCollection
    {
        return $this->catalogue->active($school, $this->community($type));
    }

    /** @param  array<string, mixed>  $input */
    public function create(School $school, User $actor, StudentGroupType $type, array $input): StudentGroup
    {
        return $this->catalogue->create($school, $actor, $this->community($type), self::MANAGE, $input);
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, User $actor, StudentGroupType $type, string $groupId, array $input): StudentGroup
    {
        return $this->catalogue->update($school, $actor, $this->community($type), self::MANAGE, $groupId, $input);
    }

    public function setStatus(School $school, User $actor, StudentGroupType $type, string $groupId, string $status, int $expectedVersion): StudentGroup
    {
        return $this->catalogue->setStatus($school, $actor, $this->community($type), self::MANAGE, $groupId, $status, $expectedVersion);
    }

    /**
     * The hub areas this viewer may open (HOUSE, CLUB, ACTIVITY), with the
     * scope each list will use; refused when there are none.
     *
     * @return array<string, CampusScope>
     */
    public function hub(School $school, User $actor): array
    {
        $houses = $this->students->scopeOf($actor, HouseAdministrationService::VIEW, $school);
        $communities = $this->students->scopeOf($actor, self::VIEW, $school);
        $areas = array_filter([
            StudentGroupType::HOUSE->value => $houses,
            StudentGroupType::CLUB->value => $communities,
            StudentGroupType::ACTIVITY->value => $communities,
        ], fn (CampusScope $s) => ! $s->isNone());
        $this->authorizeAnyArea($areas);

        return $areas;
    }

    /**
     * Refuses a viewer who may open no community area.
     *
     * @param  array<string, CampusScope>  $areas
     */
    private function authorizeAnyArea(array $areas): void
    {
        if ($areas === []) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function community(StudentGroupType $type): StudentGroupType
    {
        if (! $type->isCommunity()) {
            throw new InvalidArgumentException('Clubs and Activities only.');
        }

        return $type;
    }
}
