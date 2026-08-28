<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- the recipe: how much of each InventoryItem one unit
     * of a CanteenItem consumes. Evaluated AT FULFILLMENT time (never
     * snapshotted at Order placement) -- `App\Domain\Canteen\Application\CanteenOrderService::fulfill()`
     * reads the CURRENT rows for the Items involved, locking the owning
     * `canteen_items` row first (see that service's own docblock) so a
     * concurrent recipe edit and a concurrent fulfillment can never
     * produce a torn read.
     *
     * `quantity_required` matches `inventory_stock_balances.quantity_on_hand`'s
     * own `decimal(14, 3)` precision exactly -- this value is summed
     * directly into an `App\Domain\Inventory\Application\IssueRequirement`
     * quantity string via `bcmul()`/`bcadd()`, never a float.
     *
     * `unique(school_id, canteen_item_id, inventory_item_id)` -- no
     * duplicate recipe line for the same (Item, ingredient) pair.
     */
    public function up(): void
    {
        Schema::create('canteen_item_inventory_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('canteen_item_id');
            $table->uuid('inventory_item_id');
            $table->decimal('quantity_required', 14, 3);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'canteen_item_id', 'inventory_item_id'], 'canteen_item_inventory_requirements_unique');
            $table->index(['school_id', 'canteen_item_id']);

            $table->foreign(['canteen_item_id', 'school_id'], 'canteen_item_inv_req_item_fk')
                ->references(['id', 'school_id'])->on('canteen_items')
                ->restrictOnDelete();

            $table->foreign(['inventory_item_id', 'school_id'], 'canteen_item_inv_req_inv_item_fk')
                ->references(['id', 'school_id'])->on('inventory_items')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE canteen_item_inventory_requirements ADD CONSTRAINT canteen_item_inv_req_quantity_positive_check CHECK (quantity_required > 0)');

        TenantRls::enable('canteen_item_inventory_requirements');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_item_inventory_requirements');
        Schema::dropIfExists('canteen_item_inventory_requirements');
    }
};
