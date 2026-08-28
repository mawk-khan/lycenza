<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10E -- the Inventory catalogue reference entity
     * (docs/modules/INVENTORY.md "InventoryItem model"). School-wide
     * (no Campus) -- a catalogue definition ("A4 paper," "whiteboard
     * marker") is not itself located anywhere; WHERE it is held is
     * `inventory_stock_balances`' concern.
     *
     * `unit_of_measure` is a small bounded set, never a free string
     * and never a separate UnitOfMeasure reference table -- no
     * conversion engine exists or is planned. Deliberately NO
     * `allows_fractional_quantity` column: whether an Item's quantity
     * may carry a fractional part is fully determined by
     * `unit_of_measure` itself (each/box/packet are always whole
     * units; kg/litre may be fractional) -- App\Domain\Inventory\
     * Infrastructure\InventoryItem::allowsFractionalQuantity() derives
     * this from the unit, so there is exactly one source of truth for
     * the rule, never two columns that could disagree.
     *
     * Deliberately NO cost/valuation/price column of any kind (Finance
     * boundary, docs/modules/INVENTORY.md "Finance boundary") and NO
     * description/notes field (no product requirement for one).
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('unit_of_measure', 16);
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_unit_of_measure_check '.
            "CHECK (unit_of_measure IN ('each', 'box', 'packet', 'kg', 'litre'))"
        );

        TenantRls::enable('inventory_items');
    }

    public function down(): void
    {
        TenantRls::disable('inventory_items');
        Schema::dropIfExists('inventory_items');
    }
};
