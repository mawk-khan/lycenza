<?php

namespace App\Domain\Inventory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The AUTHORITATIVE, immutable, append-only historical record of every
 * stock-affecting event (docs/modules/INVENTORY.md "Movement history
 * role"). No update/delete application path exists anywhere in this
 * module -- App\Domain\Inventory\Application\InventoryStockService is
 * the only writer, and it only ever inserts.
 *
 * No `updated_at` -- see this table's migration docblock.
 *
 * @property string $id
 * @property string $school_id
 * @property string $inventory_item_id
 * @property string $movement_type receipt|issue|transfer
 * @property string|null $from_location_id
 * @property string|null $to_location_id
 * @property string $quantity
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class StockMovement extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'stock_movements';

    protected $fillable = [
        'school_id', 'inventory_item_id', 'movement_type',
        'from_location_id', 'to_location_id', 'quantity', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }
}
