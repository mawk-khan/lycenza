<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10F -- the School-wide canteen menu catalogue. `price` is
     * the CURRENT sellable price -- an Order line snapshots it at
     * placement time (`canteen_order_lines.unit_price`), so a later
     * price change never rewrites a historical Order's amounts.
     * `currency` is pinned to INR only, matching every other Phase 0G+
     * monetary column in this repository.
     *
     * Case-insensitive code uniqueness mirrors `ledger_accounts_school_id_code_ci_unique`'s
     * exact expression-index pattern.
     */
    public function up(): void
    {
        Schema::create('canteen_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->decimal('price', 14, 2);
            $table->char('currency', 3);
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
        });

        DB::statement('ALTER TABLE canteen_items ADD CONSTRAINT canteen_items_price_non_negative_check CHECK (price >= 0)');
        DB::statement("ALTER TABLE canteen_items ADD CONSTRAINT canteen_items_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement("ALTER TABLE canteen_items ADD CONSTRAINT canteen_items_status_check CHECK (status IN ('active', 'inactive'))");

        DB::statement(
            'CREATE UNIQUE INDEX canteen_items_school_id_code_ci_unique '.
            'ON canteen_items (school_id, upper(code))'
        );

        TenantRls::enable('canteen_items');
    }

    public function down(): void
    {
        TenantRls::disable('canteen_items');
        Schema::dropIfExists('canteen_items');
    }
};
