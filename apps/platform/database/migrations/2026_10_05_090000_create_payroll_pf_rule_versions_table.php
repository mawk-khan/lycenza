<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036) -- PF is national law, identical for
     * every School; this is PLATFORM reference data, the same shape
     * as `education_boards` (rule 69 -- no `school_id`, no RLS). Rates
     * are typed `NUMERIC` columns, never a JSON blob (root CLAUDE.md
     * rule 2 / ADR 0036 "no generic rules engine"). A version, once
     * referenced by any `payroll_statutory_calculation_results` row,
     * is immutable -- enforced at the Application layer
     * (`StatutoryRuleVersionService`, Checkpoint 9.6D), mirroring
     * `SalaryStructure`'s freeze-once-active precedent; a legal rate
     * change is always a NEW row with a later `effective_from`, never
     * an UPDATE to this one.
     */
    public function up(): void
    {
        Schema::create('payroll_pf_rule_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active'); // active|superseded
            $table->string('legal_reference');
            $table->decimal('employee_contribution_rate', 6, 4);
            $table->decimal('employer_contribution_rate', 6, 4);
            $table->decimal('eps_rate', 6, 4);
            $table->decimal('edli_rate', 6, 4);
            $table->decimal('admin_charge_rate', 6, 4);
            $table->decimal('membership_wage_ceiling', 12, 2);
            $table->decimal('eps_wage_ceiling', 12, 2);
            $table->decimal('edli_wage_ceiling', 12, 2);
            $table->decimal('admin_charge_minimum', 12, 2);
            $table->timestamps();

            $table->unique('effective_from');
        });

        DB::statement("ALTER TABLE payroll_pf_rule_versions ADD CONSTRAINT payroll_pf_rule_versions_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE payroll_pf_rule_versions ADD CONSTRAINT payroll_pf_rule_versions_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_pf_rule_versions');
    }
};
