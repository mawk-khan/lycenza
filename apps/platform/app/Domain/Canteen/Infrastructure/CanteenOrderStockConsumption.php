<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenOrderStockConsumptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link proving which stock_movements rows were caused by which
 * canteen_orders fulfillment. Deliberately carries ONLY the two
 * foreign keys -- InventoryItem/quantity/Location facts live on
 * StockMovement itself, never duplicated here. Immutable in
 * APPLICATION semantics: no update/delete route exists anywhere for
 * this table, and App\Domain\Canteen\Application\CanteenOrderService::fulfill()
 * is the only writer, always built from the StockMovement objects
 * InventoryStockService::issueMany() itself just returned.
 *
 * @property string $id
 * @property string $school_id
 * @property string $canteen_order_id
 * @property string $stock_movement_id
 */
class CanteenOrderStockConsumption extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'canteen_order_stock_consumptions';

    protected $fillable = ['school_id', 'canteen_order_id', 'stock_movement_id'];

    protected static function newFactory(): CanteenOrderStockConsumptionFactory
    {
        return CanteenOrderStockConsumptionFactory::new();
    }

    /** @return BelongsTo<CanteenOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CanteenOrder::class, 'canteen_order_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
