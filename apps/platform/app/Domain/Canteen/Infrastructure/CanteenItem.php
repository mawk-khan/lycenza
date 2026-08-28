<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A School-wide canteen menu item. `price` is the CURRENT sellable
 * price -- an Order line snapshots it at placement time; a later price
 * change never rewrites a historical Order's amounts. Persistence +
 * relationships only.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string $price
 * @property string $currency
 * @property string $status active|inactive
 */
class CanteenItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'canteen_items';

    protected $fillable = ['school_id', 'code', 'name', 'price', 'currency', 'status'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CanteenItemFactory
    {
        return CanteenItemFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<CanteenItemInventoryRequirement, $this> */
    public function inventoryRequirements(): HasMany
    {
        return $this->hasMany(CanteenItemInventoryRequirement::class);
    }
}
