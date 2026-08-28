<?php

namespace App\Domain\Canteen\Infrastructure;

use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CanteenOutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical/logical canteen counter, backed by exactly one
 * InventoryLocation it issues stock from at fulfillment time
 * (see the `create_canteen_outlets_table` migration for the
 * campus-consistency structural enforcement). Persistence +
 * relationships only -- App\Domain\Canteen\Application\CanteenOutletService
 * owns lifecycle mutation.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $inventory_location_id
 * @property string $code
 * @property string $name
 * @property string $status active|inactive
 */
class CanteenOutlet extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'canteen_outlets';

    protected $fillable = ['school_id', 'campus_id', 'inventory_location_id', 'code', 'name', 'status'];

    protected static function newFactory(): CanteenOutletFactory
    {
        return CanteenOutletFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }
}
