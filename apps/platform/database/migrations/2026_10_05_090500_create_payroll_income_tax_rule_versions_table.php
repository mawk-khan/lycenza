<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036 correction addendum §1.3/§1.5) --
     * Income-tax Act, 2025 regime rule versions (national reference
     * data). `regime` distinguishes old/new -- an employee's own
     * election is a separate School-owned fact
     * (`employee_tax_profile`), never stored here. Slab and surcharge
     * tables live in the child `payroll_income_tax_rule_slabs` table.
     */
    public function up(): void
    {
        Schema::create('payroll_income_tax_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('regime'); // old|new
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->string('legal_reference');
            $table->decimal('standard_deduction', 12, 2);
            $table->decimal('rebate_qualifying_income_threshold', 12, 2);
            $table->decimal('rebate_maximum', 12, 2);
            $table->decimal('cess_rate', 6, 4);
            $table->timestamps();

            $table->unique(['regime', 'effective_from']);
        });

        DB::statement("ALTER TABLE payroll_income_tax_rule_versions ADD CONSTRAINT payroll_income_tax_rule_versions_regime_check CHECK (regime IN ('old', 'new'))");
        DB::statement("ALTER TABLE payroll_income_tax_rule_versions ADD CONSTRAINT payroll_income_tax_rule_versions_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE payroll_income_tax_rule_versions ADD CONSTRAINT payroll_income_tax_rule_versions_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_income_tax_rule_versions');
    }
};
