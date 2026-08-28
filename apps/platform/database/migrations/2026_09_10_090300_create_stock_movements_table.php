<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10E -- the AUTHORITATIVE, immutable, append-only Inventory
     * stock ledger (docs/modules/INVENTORY.md "Movement history
     * role"). No `updated_at` -- a posted movement is never mutated
     * after insert (no application code path exists to update or
     * delete a row here; see INVENTORY.md "Movement immutability
     * limits" for why this is enforced at the application-surface
     * layer, not a database trigger, in this checkpoint).
     *
     * Deliberately NO `external_reference`/notes column -- no current
     * product requirement was found to justify one; adding a bounded
     * field with no real use today would be exactly the kind of
     * premature field root CLAUDE.md warns against. Deliberately NO
     * cost/valuation/journal_entry_id column (Finance boundary) -- see
     * `id`'s own docblock note below for the future seam this leaves.
     *
     * `from_location_id`/`to_location_id` are BOTH nullable composite
     * FKs against `inventory_locations(id, school_id)`, RESTRICT --
     * receipt sets only `to_location_id`, issue sets only
     * `from_location_id`, transfer sets both. The three CHECK
     * constraints below make this shape a database-level guarantee,
     * never left to controller validation alone (checkpoint brief
     * section 12) -- a receipt can never accidentally have a
     * `from_location_id`, an issue can never lack one, and a transfer
     * can never reference the same Location on both sides
     * (`stock_movements_transfer_locations_differ_check`).
     *
     * This table's `id` is the stable seam a FUTURE Inventory-costing
     * checkpoint may reference (a satellite valuation table's
     * `stock_movement_id` FK, mirroring exactly how Fees'
     * `charges.journal_entry_id` references Finance) -- not built
     * here, not referenced FROM here.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('inventory_item_id');
            $table->string('movement_type', 16); // receipt|issue|transfer
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'inventory_item_id']);
            $table->index(['from_location_id']);
            $table->index(['to_location_id']);
            $table->index(['movement_type']);
            $table->index(['occurred_at']);

            $table->foreign(['inventory_item_id', 'school_id'])
                ->references(['id', 'school_id'])->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['from_location_id', 'school_id'])
                ->references(['id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();

            $table->foreign(['to_location_id', 'school_id'])
                ->references(['id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type IN ('receipt', 'issue', 'transfer'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_positive_check CHECK (quantity > 0)');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK ('.
            "(movement_type = 'receipt' AND from_location_id IS NULL AND to_location_id IS NOT NULL) OR ".
            "(movement_type = 'issue' AND from_location_id IS NOT NULL AND to_location_id IS NULL) OR ".
            "(movement_type = 'transfer' AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL)".
            ')'
        );
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_transfer_locations_differ_check CHECK ('.
            "movement_type <> 'transfer' OR from_location_id <> to_location_id".
            ')'
        );

        TenantRls::enable('stock_movements');
    }

    public function down(): void
    {
        TenantRls::disable('stock_movements');
        Schema::dropIfExists('stock_movements');
    }
};
