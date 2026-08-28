<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- one canteen purchase by one Student. `inventory_location_id`
     * is a SNAPSHOT, populated once at placement from
     * `outlet.inventory_location_id` -- never re-derived from the
     * Outlet at fulfillment time, so a later Outlet reconfiguration can
     * never silently change which physical Location an already-placed
     * Order will draw stock from.
     *
     * Lifecycle: pending -> fulfilled | cancelled (terminal, both). The
     * three CHECK constraints below make the (status, fulfilled_at,
     * cancelled_at, charge_id) shape a database-level guarantee, not
     * just an Application-layer convention -- same-row arithmetic-free
     * logic, so a plain CHECK suffices (no trigger needed, unlike
     * `charges`' cross-row cancellation-link validation).
     *
     * `charge_id` is nullable (pending/cancelled Orders have none) but
     * a partial unique index (`WHERE charge_id IS NOT NULL`) makes "one
     * Charge belongs to at most one Order" a structural fact once set --
     * `App\Domain\Canteen\Application\CanteenOrderService::fulfill()` is
     * the only writer of this column, and only ever sets it once.
     *
     * `TenantRls::revokeDelete()` (never `makeAppendOnly()`): a pending
     * Order legitimately transitions via UPDATE (fulfil/cancel), but
     * must never be hard-deleted while it may already reference a real
     * Charge/StockMovement history -- mirrors `charges`' exact choice.
     */
    public function up(): void
    {
        Schema::create('canteen_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('outlet_id');
            $table->uuid('inventory_location_id');
            $table->string('status')->default('pending'); // pending|fulfilled|cancelled
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3);
            $table->uuid('charge_id')->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'outlet_id']);

            $table->foreign(['student_id', 'school_id'], 'canteen_orders_student_fk')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();

            $table->foreign(['outlet_id', 'school_id'], 'canteen_orders_outlet_fk')
                ->references(['id', 'school_id'])->on('canteen_outlets')
                ->restrictOnDelete();

            $table->foreign(['inventory_location_id', 'school_id'], 'canteen_orders_location_fk')
                ->references(['id', 'school_id'])->on('inventory_locations')
                ->restrictOnDelete();

            $table->foreign(['charge_id', 'school_id'], 'canteen_orders_charge_fk')
                ->references(['id', 'school_id'])->on('charges')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_status_check CHECK (status IN ('pending', 'fulfilled', 'cancelled'))");
        DB::statement('ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_total_amount_non_negative_check CHECK (total_amount >= 0)');
        DB::statement("ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_currency_inr_only_check CHECK (currency = 'INR')");

        DB::statement(
            'ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_pending_shape_check CHECK ('.
            "status <> 'pending' OR (fulfilled_at IS NULL AND cancelled_at IS NULL AND charge_id IS NULL)".
            ')'
        );
        DB::statement(
            'ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_fulfilled_shape_check CHECK ('.
            "status <> 'fulfilled' OR (fulfilled_at IS NOT NULL AND cancelled_at IS NULL AND charge_id IS NOT NULL)".
            ')'
        );
        DB::statement(
            'ALTER TABLE canteen_orders ADD CONSTRAINT canteen_orders_cancelled_shape_check CHECK ('.
            "status <> 'cancelled' OR (cancelled_at IS NOT NULL AND fulfilled_at IS NULL AND charge_id IS NULL)".
            ')'
        );

        DB::statement('CREATE UNIQUE INDEX canteen_orders_charge_id_unique ON canteen_orders (charge_id) WHERE charge_id IS NOT NULL');

        TenantRls::enable('canteen_orders');
        TenantRls::revokeDelete('canteen_orders');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_orders');
        Schema::dropIfExists('canteen_orders');
    }
};
