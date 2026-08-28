<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One canteen purchase by one Student. `inventory_location_id` is a
 * SNAPSHOT taken at placement time from the Outlet's own Location --
 * never re-derived from the Outlet at fulfillment. Persistence +
 * relationships only -- App\Domain\Canteen\Application\CanteenOrderService
 * is the ONLY sanctioned write path (place/fulfill/cancel).
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $outlet_id
 * @property string $inventory_location_id
 * @property string $status pending|fulfilled|cancelled
 * @property string $total_amount
 * @property string $currency
 * @property string|null $charge_id
 * @property Carbon $placed_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $cancelled_at
 */
class CanteenOrder extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'canteen_orders';

    protected $fillable = [
        'school_id', 'student_id', 'outlet_id', 'inventory_location_id',
        'status', 'total_amount', 'currency', 'charge_id',
        'placed_at', 'fulfilled_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CanteenOrderFactory
    {
        return CanteenOrderFactory::new();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<CanteenOutlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(CanteenOutlet::class, 'outlet_id');
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    /** @return BelongsTo<Charge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    /** @return HasMany<CanteenOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CanteenOrderLine::class, 'order_id');
    }

    /** @return HasMany<CanteenOrderStockConsumption, $this> */
    public function stockConsumptions(): HasMany
    {
        return $this->hasMany(CanteenOrderStockConsumption::class, 'canteen_order_id');
    }
}
