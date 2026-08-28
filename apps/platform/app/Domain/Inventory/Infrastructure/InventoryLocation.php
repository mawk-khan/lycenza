<?php

namespace App\Domain\Inventory\Infrastructure;

use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\InventoryLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical stock-holding location (docs/modules/INVENTORY.md
 * "InventoryLocation model"). School-owned; Campus is OPTIONAL (a
 * central store may be School-wide, a department store may belong to
 * one Campus in a multi-campus School).
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $campus_id
 * @property string $code
 * @property string $name
 * @property string $status active|inactive
 */
class InventoryLocation extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'inventory_locations';

    protected $fillable = ['school_id', 'campus_id', 'code', 'name', 'status'];

    protected static function newFactory(): InventoryLocationFactory
    {
        return InventoryLocationFactory::new();
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
}
