<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- one CanteenItem line within a CanteenOrder.
     * `unit_price`/`line_total` are SNAPSHOTS taken at placement time
     * (`App\Domain\Canteen\Application\CanteenOrderService::place()`)
     * from the CanteenItem's price at that moment -- a later menu price
     * change never rewrites a historical Order's amounts.
     *
     * `canteen_order_lines_line_total_arithmetic_check` is a real
     * PostgreSQL CHECK proving `line_total = unit_price * quantity`
     * using exact NUMERIC multiplication -- no float involved at the
     * database level, NUMERIC arithmetic is exact.
     *
     * Immutability is APPLICATION-level only (no update/delete route is
     * ever built for this table) -- deliberately NOT a database trigger
     * or `TenantRls::makeAppendOnly()`, matching `stock_movements`' own
     * precedent (`StockMovementArchitectureGuardTest`'s docblock) of
     * avoiding a new raw-SQL trigger-function framework where an
     * application-surface guarantee (no route exists) is sufficient.
     */
    public function up(): void
    {
        Schema::create('canteen_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('order_id');
            $table->uuid('canteen_item_id');
            $table->integer('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'order_id']);

            $table->foreign(['order_id', 'school_id'], 'canteen_order_lines_order_fk')
                ->references(['id', 'school_id'])->on('canteen_orders')
                ->restrictOnDelete();

            $table->foreign(['canteen_item_id', 'school_id'], 'canteen_order_lines_item_fk')
                ->references(['id', 'school_id'])->on('canteen_items')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE canteen_order_lines ADD CONSTRAINT canteen_order_lines_quantity_positive_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE canteen_order_lines ADD CONSTRAINT canteen_order_lines_unit_price_non_negative_check CHECK (unit_price >= 0)');
        DB::statement('ALTER TABLE canteen_order_lines ADD CONSTRAINT canteen_order_lines_line_total_non_negative_check CHECK (line_total >= 0)');
        DB::statement("ALTER TABLE canteen_order_lines ADD CONSTRAINT canteen_order_lines_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE canteen_order_lines ADD CONSTRAINT canteen_order_lines_line_total_arithmetic_check CHECK (line_total = unit_price * quantity)');

        TenantRls::enable('canteen_order_lines');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_order_lines');
        Schema::dropIfExists('canteen_order_lines');
    }
};
