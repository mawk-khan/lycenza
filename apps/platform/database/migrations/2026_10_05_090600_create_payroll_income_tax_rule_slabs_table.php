<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C -- one typed row per slab boundary. `slab_kind`
     * discriminates ordinary income-tax slabs (`upper_bound` is the
     * cumulative income boundary the `rate` applies within, null =
     * top open-ended band) from surcharge slabs (`upper_bound` holds
     * the surcharge THRESHOLD income above which `rate` applies) --
     * two genuinely different shapes sharing the same
     * boundary/rate/sort_order columns, never a hardcoded flat
     * surcharge rate in application code (ADR 0036 correction
     * addendum, TDS-20).
     */
    public function up(): void
    {
        Schema::create('payroll_income_tax_rule_slabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rule_version_id');
            $table->string('slab_kind'); // income|surcharge
            $table->decimal('upper_bound', 14, 2)->nullable();
            $table->decimal('rate', 6, 4);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['rule_version_id', 'slab_kind', 'sort_order']);
            $table->foreign('rule_version_id')->references('id')->on('payroll_income_tax_rule_versions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE payroll_income_tax_rule_slabs ADD CONSTRAINT payroll_income_tax_rule_slabs_kind_check CHECK (slab_kind IN ('income', 'surcharge'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_income_tax_rule_slabs');
    }
};
