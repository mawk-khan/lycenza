<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Recipe (CanteenItemInventoryRequirement) mutation -- kept as its own
 * small service rather than folded into CanteenItem CRUD because of the
 * ONE real invariant it must uphold: every mutation locks the owning
 * `canteen_items` row (`lockForUpdate()`) BEFORE touching its
 * `canteen_item_inventory_requirements` children, in the SAME order
 * `CanteenOrderService::fulfill()` itself locks CanteenItem rows before
 * reading their current recipe (see that service's own docblock) --
 * this is what makes "recipe-mutation-vs-fulfillment race" produce a
 * clean, non-torn read: whichever of a concurrent recipe-edit or
 * fulfillment acquires the CanteenItem row lock first fully completes
 * before the other proceeds.
 */
class CanteenRecipeService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function setRequirement(School $school, CanteenItem $canteenItem, InventoryItem $inventoryItem, string $quantityRequired, ?User $actor = null): CanteenItemInventoryRequirement
    {
        return DB::transaction(function () use ($school, $canteenItem, $inventoryItem, $quantityRequired, $actor) {
            CanteenItem::query()->where('id', $canteenItem->id)->lockForUpdate()->firstOrFail();

            $requirement = CanteenItemInventoryRequirement::query()->updateOrCreate(
                ['school_id' => $school->id, 'canteen_item_id' => $canteenItem->id, 'inventory_item_id' => $inventoryItem->id],
                ['quantity_required' => $quantityRequired],
            );

            $this->audit->school($school, 'canteen.recipe.requirement_set', actor: $actor, subject: $requirement, metadata: [
                'canteenItemId' => $canteenItem->id,
                'inventoryItemId' => $inventoryItem->id,
                'quantityRequired' => $quantityRequired,
            ]);

            return $requirement;
        });
    }

    public function removeRequirement(School $school, CanteenItem $canteenItem, CanteenItemInventoryRequirement $requirement, ?User $actor = null): void
    {
        DB::transaction(function () use ($school, $canteenItem, $requirement, $actor) {
            CanteenItem::query()->where('id', $canteenItem->id)->lockForUpdate()->firstOrFail();

            $requirement->delete();

            $this->audit->school($school, 'canteen.recipe.requirement_removed', actor: $actor, metadata: [
                'canteenItemId' => $canteenItem->id,
                'inventoryItemId' => $requirement->inventory_item_id,
            ]);
        });
    }
}
