<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- the link table proving which `stock_movements` rows
     * were caused by which `canteen_orders` fulfillment. Deliberately
     * carries ONLY `canteen_order_id`/`stock_movement_id` -- no
     * `inventory_item_id`/`quantity`/`location_id` duplication:
     * `StockMovement` already owns those facts (this table would
     * otherwise become a second, independently-driftable source of
     * truth for data `stock_movements` already holds).
     *
     * `unique(school_id, stock_movement_id)` -- one Movement claimed by
     * exactly one Order; `App\Domain\Canteen\Application\CanteenOrderService::fulfill()`
     * is the ONLY writer, and only ever inserts rows built from the
     * `StockMovement` objects `InventoryStockService::issueMany()`
     * itself just returned -- never from caller-supplied input.
     *
     * Immutability is APPLICATION-level only (no update/delete route
     * ever exists for this table), matching `canteen_order_lines`' own
     * precedent and rationale.
     */
    public function up(): void
    {
        Schema::create('canteen_order_stock_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('canteen_order_id');
            $table->uuid('stock_movement_id');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'stock_movement_id'], 'canteen_order_stock_consumptions_movement_unique');
            $table->index(['school_id', 'canteen_order_id']);

            $table->foreign(['canteen_order_id', 'school_id'], 'canteen_order_stock_consumptions_order_fk')
                ->references(['id', 'school_id'])->on('canteen_orders')
                ->restrictOnDelete();

            $table->foreign(['stock_movement_id', 'school_id'], 'canteen_order_stock_consumptions_movement_fk')
                ->references(['id', 'school_id'])->on('stock_movements')
                ->restrictOnDelete();
        });

        TenantRls::enable('canteen_order_stock_consumptions');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_order_stock_consumptions');
        Schema::dropIfExists('canteen_order_stock_consumptions');
    }
};
