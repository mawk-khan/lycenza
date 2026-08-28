<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenItemInventoryRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipe line: how much of one InventoryItem one unit of a
 * CanteenItem consumes. Evaluated AT FULFILLMENT time by
 * App\Domain\Canteen\Application\CanteenOrderService::fulfill() --
 * never snapshotted at Order placement. Persistence + relationships
 * only.
 *
 * @property string $id
 * @property string $school_id
 * @property string $canteen_item_id
 * @property string $inventory_item_id
 * @property string $quantity_required
 */
class CanteenItemInventoryRequirement extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'canteen_item_inventory_requirements';

    protected $fillable = ['school_id', 'canteen_item_id', 'inventory_item_id', 'quantity_required'];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:3',
        ];
    }

    protected static function newFactory(): CanteenItemInventoryRequirementFactory
    {
        return CanteenItemInventoryRequirementFactory::new();
    }

    /** @return BelongsTo<CanteenItem, $this> */
    public function canteenItem(): BelongsTo
    {
        return $this->belongsTo(CanteenItem::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
