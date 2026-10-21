<?php

namespace App\Domain\Inventory\Application;

use App\Domain\Inventory\Application\Exceptions\LocationCampusFrozenException;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer of the Inventory Location directory (ORG.7H-1, ADR 0076
 * §54). Whole-School `inventory.directory.manage` only -- the callers
 * authorize and validate (same-School Campus); this service owns the
 * transaction, the Campus freeze and the audit.
 *
 * A Location's Campus is operational provenance: once the Location has
 * operational history (`hasOperationalHistory()`), its Campus never changes
 * -- A -> B, A -> NULL and NULL -> A are refused, every other field keeps
 * its existing edit behaviour. The Location row is locked FOR UPDATE before
 * the check, so no first stock movement, balance or Canteen Order can slip
 * in between (each takes the row FOR SHARE: InventoryStockService and the
 * `inventory_location_evidence_guard` trigger). The database trigger
 * `inventory_location_campus_freeze` is the backstop for any other writer.
 */
final class InventoryLocationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, campus_id?: ?string}  $data
     */
    public function create(School $school, array $data, ?User $actor = null): InventoryLocation
    {
        return DB::transaction(function () use ($school, $data, $actor) {
            $location = InventoryLocation::query()->create([...$data, 'school_id' => $school->id, 'status' => 'active']);

            $this->audit->school($school, 'inventory.location.created', actor: $actor, subject: $location, metadata: [
                'locationId' => $location->id,
                'code' => $location->code,
            ]);

            return $location;
        });
    }

    /**
     * @param  array<string, string|null>  $data  name, campus_id and/or status (validated by the caller)
     */
    public function update(School $school, string $locationId, array $data, ?User $actor = null): InventoryLocation
    {
        return DB::transaction(function () use ($school, $locationId, $data, $actor) {
            $location = InventoryLocation::query()->lockForUpdate()->findOrFail($locationId);

            if (array_key_exists('campus_id', $data) && $this->changesCampus($location, $data['campus_id']) && $this->hasOperationalHistory($location)) {
                throw new LocationCampusFrozenException;
            }

            $before = $location->only(array_keys($data));
            $location->update($data);

            $this->audit->school($school, 'inventory.location.updated', actor: $actor, subject: $location, metadata: [
                'locationId' => $location->id,
                'statusBefore' => $before['status'] ?? null,
                'statusAfter' => $data['status'] ?? null,
            ]);

            return $location->refresh();
        });
    }

    /**
     * Whether the Location has operational history: the database predicate
     * `inventory_location_has_operational_history()` (stock movements from or
     * to it, stock balances, Canteen Orders), the same one the freeze trigger
     * applies -- never a second definition.
     */
    public function hasOperationalHistory(InventoryLocation $location): bool
    {
        return (bool) DB::selectOne(
            'SELECT inventory_location_has_operational_history(?, ?) AS used',
            [$location->id, $location->school_id],
        )->used;
    }

    private function changesCampus(InventoryLocation $location, ?string $campusId): bool
    {
        return ($campusId === null ? null : strtolower($campusId)) !== $location->campus_id;
    }
}
