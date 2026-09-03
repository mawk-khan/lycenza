<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0035) -- one typed row per PT slab
     * boundary, ordered by `upper_bound` ascending; a null
     * `upper_bound` is the catch-all top band (never a magic sentinel
     * number).
     */
    public function up(): void
    {
        Schema::create('payroll_professional_tax_rule_slabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rule_version_id');
            $table->decimal('upper_bound', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['rule_version_id', 'sort_order']);
            $table->foreign('rule_version_id')->references('id')->on('payroll_professional_tax_rule_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_professional_tax_rule_slabs');
    }
};
