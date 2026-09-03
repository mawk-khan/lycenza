<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0035) -- one immutable statutory snapshot
     * per `payroll_run_results` row, recording which rule version(s)
     * were used and every calculated base/contribution/tax figure.
     * Frozen the instant the parent run reaches `approved` or later,
     * mirroring `payroll_run_results`'s own freeze-trigger shape
     * exactly (same parent-status lookup, same BEFORE INSERT/UPDATE/
     * DELETE guard) -- a historical statutory result never
     * recalculates itself against a later rule version.
     *
     * Every rule-version reference is a plain FK to the relevant
     * platform reference table (no composite/School pin needed --
     * those tables carry no `school_id`). `is_pf_excluded_employee`
     * is stored explicitly (never re-derived later) so a historical
     * record remains self-explanatory even after
     * `employee_pf_status` facts change going forward.
     */
    public function up(): void
    {
        Schema::create('payroll_statutory_calculation_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_result_id');

            $table->uuid('pf_rule_version_id')->nullable();
            $table->uuid('esi_rule_version_id')->nullable();
            $table->uuid('professional_tax_rule_version_id')->nullable();
            $table->uuid('lwf_rule_version_id')->nullable();
            $table->uuid('income_tax_rule_version_id')->nullable();

            $table->boolean('is_pf_excluded_employee')->default(false);
            $table->decimal('pf_uncapped_statutory_wage', 12, 2)->nullable();
            $table->decimal('pf_contribution_base', 12, 2)->nullable();
            $table->decimal('employee_pf_mandatory', 12, 2)->nullable();
            $table->decimal('employee_pf_voluntary', 12, 2)->nullable();
            $table->decimal('employer_pf_total', 12, 2)->nullable();
            $table->decimal('employer_eps', 12, 2)->nullable();
            $table->decimal('employer_epf', 12, 2)->nullable();
            $table->decimal('pf_edli', 12, 2)->nullable();
            $table->decimal('pf_admin_charge', 12, 2)->nullable();

            $table->boolean('esi_is_covered')->default(false);
            $table->decimal('esi_statutory_wage', 12, 2)->nullable();
            $table->decimal('employee_esi', 12, 2)->nullable();
            $table->decimal('employer_esi', 12, 2)->nullable();

            $table->decimal('professional_tax', 12, 2)->nullable();

            $table->boolean('lwf_charged')->default(false);
            $table->decimal('employee_lwf', 12, 2)->nullable();
            $table->decimal('employer_lwf', 12, 2)->nullable();

            $table->decimal('tds_monthly_deduction', 12, 2)->nullable();
            $table->decimal('tds_residual_compliance_exception', 12, 2)->nullable();

            $table->timestamps();

            $table->unique('payroll_run_result_id');
            $table->index('school_id');

            $table->foreign(['payroll_run_result_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_run_results')
                ->cascadeOnDelete();
            $table->foreign('pf_rule_version_id')->references('id')->on('payroll_pf_rule_versions')->restrictOnDelete();
            $table->foreign('esi_rule_version_id')->references('id')->on('payroll_esi_rule_versions')->restrictOnDelete();
            $table->foreign('professional_tax_rule_version_id')->references('id')->on('payroll_professional_tax_rule_versions')->restrictOnDelete();
            $table->foreign('lwf_rule_version_id')->references('id')->on('payroll_lwf_rule_versions')->restrictOnDelete();
            $table->foreign('income_tax_rule_version_id')->references('id')->on('payroll_income_tax_rule_versions')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_statutory_results_freeze_after_approval() RETURNS trigger AS $$
            DECLARE
                parent_run_status text;
                target_result_id uuid;
            BEGIN
                target_result_id := COALESCE(NEW.payroll_run_result_id, OLD.payroll_run_result_id);

                SELECT pr.status INTO parent_run_status
                FROM payroll_run_results prr
                JOIN payroll_runs pr ON pr.id = prr.payroll_run_id
                WHERE prr.id = target_result_id;

                IF parent_run_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_statutory_calculation_results: parent run result (%) is % -- statutory results are frozen.', target_result_id, parent_run_status;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_statutory_results_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON payroll_statutory_calculation_results
                FOR EACH ROW
                EXECUTE FUNCTION payroll_statutory_results_freeze_after_approval();
        SQL);

        TenantRls::enable('payroll_statutory_calculation_results');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_statutory_calculation_results');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_statutory_results_freeze ON payroll_statutory_calculation_results');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_statutory_results_freeze_after_approval()');
        Schema::dropIfExists('payroll_statutory_calculation_results');
    }
};
