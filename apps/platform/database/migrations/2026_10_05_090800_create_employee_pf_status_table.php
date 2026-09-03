<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0035 correction addendum §1.1) -- the four
     * independent PF facts, one row per EmploymentRecord (never one
     * per payroll cycle -- these are slow-changing membership facts,
     * updated explicitly when the fact itself changes, e.g. a new
     * higher-wage approval). `has_existing_pf_membership`,
     * `has_uan`, `has_approved_higher_wage_contribution`, and
     * `is_eps_eligible` are four independent booleans -- application
     * code must never infer one from another (rule this table exists
     * to make structurally checkable in review: there is no
     * generated/computed column deriving one from the others).
     */
    public function up(): void
    {
        Schema::create('employee_pf_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->boolean('has_existing_pf_membership')->default(false);
            $table->boolean('has_uan')->default(false);
            $table->boolean('has_approved_higher_wage_contribution')->default(false);
            $table->string('higher_wage_approval_reference')->nullable();
            $table->boolean('is_eps_eligible')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'employment_record_id']);
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE employee_pf_status ADD CONSTRAINT employee_pf_status_higher_wage_reference_check CHECK (NOT has_approved_higher_wage_contribution OR higher_wage_approval_reference IS NOT NULL)');

        TenantRls::enable('employee_pf_status');
    }

    public function down(): void
    {
        TenantRls::disable('employee_pf_status');
        Schema::dropIfExists('employee_pf_status');
    }
};
