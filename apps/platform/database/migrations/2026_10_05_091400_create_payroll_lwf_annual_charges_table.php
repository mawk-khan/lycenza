<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6C (ADR 0036 correction addendum §1.7) --
     * structurally enforces "once per statutory annual cycle": the
     * `unique(school_id, employment_record_id, annual_cycle_year)`
     * constraint is what makes a second LWF charge attempt in the
     * same cycle a genuine database-level impossibility, not merely
     * an application-level check `LwfCalculationService`'s pure
     * `alreadyChargedThisCycle` input fact is READ from
     * (Checkpoint 9.6D/9.6F queries this table to build that fact).
     */
    public function up(): void
    {
        Schema::create('payroll_lwf_annual_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employment_record_id');
            $table->unsignedSmallInteger('annual_cycle_year');
            $table->uuid('payroll_run_result_id');
            $table->timestamps();

            $table->unique(['school_id', 'employment_record_id', 'annual_cycle_year']);

            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->restrictOnDelete();
            $table->foreign(['payroll_run_result_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_run_results')
                ->restrictOnDelete();
        });

        TenantRls::enable('payroll_lwf_annual_charges');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_lwf_annual_charges');
        Schema::dropIfExists('payroll_lwf_annual_charges');
    }
};
