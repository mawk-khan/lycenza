<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036) -- state reference data. Slabs live
     * in the child `payroll_professional_tax_rule_slabs` table (typed
     * rows, never a JSON array) -- see that migration.
     */
    public function up(): void
    {
        Schema::create('payroll_professional_tax_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('jurisdiction');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->string('legal_reference');
            $table->timestamps();

            $table->unique(['jurisdiction', 'effective_from']);
        });

        DB::statement("ALTER TABLE payroll_professional_tax_rule_versions ADD CONSTRAINT payroll_pt_rule_versions_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE payroll_professional_tax_rule_versions ADD CONSTRAINT payroll_pt_rule_versions_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_professional_tax_rule_versions');
    }
};
