<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Checkpoint 9.6C (ADR 0036) -- platform reference data, mirroring `payroll_pf_rule_versions`' exact shape and immutability discipline. */
    public function up(): void
    {
        Schema::create('payroll_esi_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->string('legal_reference');
            $table->decimal('employee_contribution_rate', 6, 4);
            $table->decimal('employer_contribution_rate', 6, 4);
            $table->decimal('wage_threshold', 12, 2);
            $table->decimal('average_daily_wage_exemption_threshold', 12, 2);
            $table->timestamps();

            $table->unique('effective_from');
        });

        DB::statement("ALTER TABLE payroll_esi_rule_versions ADD CONSTRAINT payroll_esi_rule_versions_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE payroll_esi_rule_versions ADD CONSTRAINT payroll_esi_rule_versions_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_esi_rule_versions');
    }
};
