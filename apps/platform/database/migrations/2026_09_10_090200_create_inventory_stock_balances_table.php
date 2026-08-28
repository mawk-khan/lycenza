<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10E -- the AUTHORITATIVE current-quantity resource for one
     * (school_id, inventory_item_id, inventory_location_id)
     * (docs/modules/INVENTORY.md "Stock truth architecture"). This is
     * the ONLY place current stock quantity is stored -- InventoryItem
     * and InventoryLocation carry no quantity/total column of their
     * own (rule: no mutable occupancy-style duplication).
     *
     * Written EXCLUSIVELY by
     * App\Domain\Inventory\Application\InventoryStockService, always
     * in the same transaction as the StockMovement row that caused the
     * change -- this is what keeps a stored balance from becoming a
     * second, independently-writable source of truth: it is
     * mathematically reconstructible from `stock_movements` at any
     * time (proven directly by the reconciliation test), and nothing
     * else is permitted to write it.
     *
     * `quantity_on_hand >= 0` is the FINAL structural backstop for the
     * negative-stock invariant -- the service's own row lock +
     * post-lock check is the primary mechanism, this CHECK constraint
     * is what makes the invariant true even against a future bug or a
     * raw out-of-band write, exactly the same "backstop, not primary
     * mechanism" role Hostel's partial unique indexes play relative to
     * HostelResidencyService's own locking.
     *
     * `unique(school_id, inventory_item_id, inventory_location_id)` is
     * both the stock-identity uniqueness rule AND the conflict target
     * for the concurrency-safe `INSERT ... ON CONFLICT DO NOTHING`
     * balance-row-creation primitive (docs/modules/INVENTORY.md
     * "Balance creation primitive") -- two concurrent first-ever
     * receipts to the same Item x Location race this constraint, never
     * a naive firstOrCreate()+lockForUpdate() (a row that does not yet
     * exist cannot be locked).
     */
    public function up(): void
    {
        Schema::create('inventory_stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('inventory_item_id');
            $table->uuid('inventory_location_id');
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'inventory_item_id', 'inventory_location_id'], 'inventory_stock_balances_item_location_unique');
            $table->index(['school_id', 'inventory_item_id']);
            $table->index(['school_id', 'inventory_location_id']);

            $table->foreign(['inventory_item_id', 'school_id'])
                ->references(['id', 'school_id'])->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['inventory_location_id', 'school_id'])
                ->references(['id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE inventory_stock_balances ADD CONSTRAINT inventory_stock_balances_quantity_non_negative_check CHECK (quantity_on_hand >= 0)');

        TenantRls::enable('inventory_stock_balances');
    }

    public function down(): void
    {
        TenantRls::disable('inventory_stock_balances');
        Schema::dropIfExists('inventory_stock_balances');
    }
};
