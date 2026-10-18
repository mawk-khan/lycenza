<?php

namespace App\Domain\Inventory\Application;

use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7A (ADR 0076 §11.7, §33): the ONE place stock is read or targeted
 * under the actor's CampusScope (`inventory.stock.*` are Campus-adopted).
 * A stock row belongs to a Location; a Location's Campus decides.
 *
 * - A Location WITHOUT a Campus is a School-wide store: whole-School scope
 *   only (never listed, read or moved by a Campus-scoped actor).
 * - Balances: the Location is in scope. Movements: EVERY Location the
 *   movement touches is in scope (a transfer to or from an out-of-scope
 *   Location is not shown, and its other end is never disclosed).
 * - One Location outside the scope is NOT FOUND (404).
 * - All predicates are SQL, applied before rows are returned.
 *
 * The Item catalogue (InventoryItem) is School-wide reference data; the
 * stock pages' Item lookup is the declared shared lookup
 * (CampusScopeCatalogue::DOMAINS['inventory']).
 */
final class InventoryStockAccess
{
    /** @return Builder<InventoryLocation> */
    public function locations(CampusScope $scope): Builder
    {
        return $scope->restrict(InventoryLocation::query());
    }

    public function location(CampusScope $scope, string $id): InventoryLocation
    {
        $location = Str::isUuid($id) ? $this->locations($scope)->find($id) : null;

        return $location ?? throw (new ModelNotFoundException)->setModel(InventoryLocation::class, [$id]);
    }

    /** @return Builder<InventoryStockBalance> */
    public function balances(CampusScope $scope): Builder
    {
        $query = InventoryStockBalance::query();

        return $scope->isWholeSchool() ? $query : $query->whereIn('inventory_location_id', $this->locations($scope)->select('id'));
    }

    /** @return Builder<StockMovement> */
    public function movements(CampusScope $scope): Builder
    {
        $query = StockMovement::query();
        if ($scope->isWholeSchool()) {
            return $query;
        }

        $inScope = $this->locations($scope)->select('id');

        return $query
            ->where(fn ($q) => $q->whereNull('from_location_id')->orWhereIn('from_location_id', $inScope))
            ->where(fn ($q) => $q->whereNull('to_location_id')->orWhereIn('to_location_id', $this->locations($scope)->select('id')));
    }

    /** @return Builder<InventoryLocation> active Locations matching $term (code or name), in scope */
    public function searchLocations(CampusScope $scope, string $term): Builder
    {
        return $this->locations($scope)
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%")));
    }
}
