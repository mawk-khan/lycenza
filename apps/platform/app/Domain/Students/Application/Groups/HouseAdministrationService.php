<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Infrastructure\StudentGroup;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * SIS 11.7 (OD-SIS-4): House administration -- whole-School
 * `students.houses.manage`. A House is School-wide (no Campus), has a unique
 * name and code within the School, and is deactivated, never deleted.
 * Deactivation is refused while it has current members (never orphaned
 * Students). Corrections carry the House version the form saw.
 *
 * SIS 11.8: the writes live in the shared StudentGroupCatalogue (one writer
 * for Houses, Clubs and Activities); this service fixes the type to HOUSE and
 * the capability to the House keys.
 */
final class HouseAdministrationService
{
    public const string MANAGE = 'students.houses.manage';

    public const string VIEW = 'students.houses.view';

    public function __construct(private readonly StudentGroupCatalogue $catalogue) {}

    /**
     * Houses with their CURRENT member count as the viewer may see it (a
     * Campus scope counts only its own Students).
     *
     * @return Collection<int, array{group: StudentGroup, members: int}>
     */
    public function list(School $school, CampusScope $scope): Collection
    {
        return $this->catalogue->list($school, StudentGroupType::HOUSE, $scope);
    }

    /**
     * The active Houses (names only), for the assignment form.
     *
     * @return EloquentCollection<int, StudentGroup>
     */
    public function activeHouses(School $school): EloquentCollection
    {
        return $this->catalogue->active($school, StudentGroupType::HOUSE);
    }

    /** @param  array<string, mixed>  $input */
    public function create(School $school, User $actor, array $input): StudentGroup
    {
        return $this->catalogue->create($school, $actor, StudentGroupType::HOUSE, self::MANAGE, $input);
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, User $actor, string $houseId, array $input): StudentGroup
    {
        return $this->catalogue->update($school, $actor, StudentGroupType::HOUSE, self::MANAGE, $houseId, $input);
    }

    /** Activate or deactivate; deactivation is refused while the House has current members. */
    public function setStatus(School $school, User $actor, string $houseId, string $status, int $expectedVersion): StudentGroup
    {
        return $this->catalogue->setStatus($school, $actor, StudentGroupType::HOUSE, self::MANAGE, $houseId, $status, $expectedVersion);
    }
}
