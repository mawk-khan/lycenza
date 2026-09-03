<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C -- one row per EmploymentRecord per fiscal year
     * (India's fiscal year starts 1 April), holding the regime
     * election and the declared-income inputs
     * `TdsMonthlyDeductionService`/`IncomeTaxSlabCalculator` need.
     * `regime_switch_policy_reference` is explicitly labeled SCHOOL
     * POLICY (a School-approved deadline/process for switching), never
     * itself a statutory rule (ADR 0036 correction addendum's own
     * distinction).
     */
    public function up(): void
    {
        Schema::create('employee_tax_profile', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->date('fiscal_year_start');
            $table->string('regime'); // old|new
            $table->string('regime_switch_policy_reference')->nullable();
            $table->decimal('previous_employer_income', 12, 2)->default(0);
            $table->decimal('previous_employer_tds', 12, 2)->default(0);
            $table->decimal('declared_other_income', 12, 2)->default(0);
            $table->decimal('declared_deductions', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'employment_record_id', 'fiscal_year_start']);
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE employee_tax_profile ADD CONSTRAINT employee_tax_profile_regime_check CHECK (regime IN ('old', 'new'))");

        TenantRls::enable('employee_tax_profile');
    }

    public function down(): void
    {
        TenantRls::disable('employee_tax_profile');
        Schema::dropIfExists('employee_tax_profile');
    }
};
