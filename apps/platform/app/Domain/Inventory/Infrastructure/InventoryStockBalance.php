<?php

namespace App\Domain\Inventory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\InventoryStockBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AUTHORITATIVE current quantity for one (school_id,
 * inventory_item_id, inventory_location_id)
 * (docs/modules/INVENTORY.md "Stock truth architecture"). Written
 * EXCLUSIVELY by App\Domain\Inventory\Application\InventoryStockService
 * -- no controller, no other model, and no other service may mutate
 * `quantity_on_hand` directly. Mathematically reconstructible from
 * `stock_movements` at any time (proven by the reconciliation test),
 * which is what keeps this from being a second, ungoverned source of
 * truth.
 *
 * `quantity_on_hand` uses Laravel's `decimal:3` cast (backed by
 * brick/math's exact BigDecimal internally, never a PHP float) --
 * matches this repository's existing "never float for exact
 * quantities" discipline (App\Support\Money\Money).
 *
 * @property string $id
 * @property string $school_id
 * @property string $inventory_item_id
 * @property string $inventory_location_id
 * @property string $quantity_on_hand
 */
class InventoryStockBalance extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'inventory_stock_balances';

    protected $fillable = ['school_id', 'inventory_item_id', 'inventory_location_id', 'quantity_on_hand'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
        ];
    }

    protected static function newFactory(): InventoryStockBalanceFactory
    {
        return InventoryStockBalanceFactory::new();
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
