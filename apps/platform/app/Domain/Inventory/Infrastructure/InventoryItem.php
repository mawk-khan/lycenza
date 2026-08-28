<?php

namespace App\Domain\Inventory\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A School-wide catalogue definition of what is stocked
 * (docs/modules/INVENTORY.md "InventoryItem model"). Carries no
 * quantity/total of its own -- current stock exists only in
 * InventoryStockBalance.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string $unit_of_measure each|box|packet|kg|litre
 * @property string $status active|inactive
 */
class InventoryItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    protected $table = 'inventory_items';

    protected $fillable = ['school_id', 'code', 'name', 'unit_of_measure', 'status'];

    /**
     * The one and only source of truth for whether this Item's
     * quantities may carry a fractional part -- derived from
     * `unit_of_measure` rather than a separate stored column, so the
     * rule can never disagree with the unit itself (checkpoint brief
     * section 8).
     */
    private const FRACTIONAL_UNITS = ['kg', 'litre'];

    protected static function newFactory(): InventoryItemFactory
    {
        return InventoryItemFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function allowsFractionalQuantity(): bool
    {
        return in_array($this->unit_of_measure, self::FRACTIONAL_UNITS, true);
    }
}
