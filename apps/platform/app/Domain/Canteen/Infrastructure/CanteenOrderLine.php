<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CanteenItem line within a CanteenOrder. `unit_price`/`line_total`
 * are SNAPSHOTS taken at placement time -- a later menu price change
 * never rewrites a historical Order's amounts. Immutable in
 * APPLICATION semantics: no update/delete route exists anywhere for
 * this table, and App\Domain\Canteen\Application\CanteenOrderService::place()
 * is the only writer, and only ever inserts.
 *
 * @property string $id
 * @property string $school_id
 * @property string $order_id
 * @property string $canteen_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property string $currency
 */
class CanteenOrderLine extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'canteen_order_lines';

    protected $fillable = ['school_id', 'order_id', 'canteen_item_id', 'quantity', 'unit_price', 'line_total', 'currency'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CanteenOrderLineFactory
    {
        return CanteenOrderLineFactory::new();
    }

    /** @return BelongsTo<CanteenOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CanteenOrder::class, 'order_id');
    }

    /** @return BelongsTo<CanteenItem, $this> */
    public function canteenItem(): BelongsTo
    {
        return $this->belongsTo(CanteenItem::class);
    }
}
