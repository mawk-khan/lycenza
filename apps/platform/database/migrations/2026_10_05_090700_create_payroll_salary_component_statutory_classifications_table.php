<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036 correction addendum §1.2) -- the
     * component-classification model that replaces any hardcoded
     * "Basic + DA + HRA + Special + Transport" field list. One row
     * per SalaryComponent, effective-dated (a legal reclassification
     * gets a new row with a later `effective_from`, the existing one
     * never edited once a finalized statutory calculation has used
     * it -- enforced at the Application layer, Checkpoint 9.6D).
     *
     * `pf_classification` is the PF 50%-test bucket
     * (`PfComponentClassification` -- core_wage|tested_remuneration|
     * excluded_non_remuneration|not_applicable). `esi_wage_included`
     * and `income_tax_treatment` are separate dimensions -- a
     * component can be PF-excluded but still ESI-wage or taxable
     * income, so these are never derived from `pf_classification`.
     */
    public function up(): void
    {
        Schema::create('payroll_salary_component_statutory_classifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('salary_component_id');
            $table->string('pf_classification'); // core_wage|tested_remuneration|excluded_non_remuneration|not_applicable
            $table->boolean('esi_wage_included')->default(true);
            $table->string('income_tax_treatment'); // taxable|exempt|partially_exempt
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'salary_component_id']);
            $table->foreign(['salary_component_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_components')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE payroll_salary_component_statutory_classifications ADD CONSTRAINT pscsc_pf_classification_check CHECK (pf_classification IN ('core_wage', 'tested_remuneration', 'excluded_non_remuneration', 'not_applicable'))");
        DB::statement("ALTER TABLE payroll_salary_component_statutory_classifications ADD CONSTRAINT pscsc_income_tax_treatment_check CHECK (income_tax_treatment IN ('taxable', 'exempt', 'partially_exempt'))");
        DB::statement('ALTER TABLE payroll_salary_component_statutory_classifications ADD CONSTRAINT pscsc_date_order_check CHECK (effective_to IS NULL OR effective_to > effective_from)');

        TenantRls::enable('payroll_salary_component_statutory_classifications');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_salary_component_statutory_classifications');
        Schema::dropIfExists('payroll_salary_component_statutory_classifications');
    }
};
