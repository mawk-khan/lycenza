<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0034, docs/modules/PAYROLL.md "Compensation
     * model") -- semantic component identity ONLY (Basic, HRA,
     * PF-Employee, ...). No monetary value or rate lives here --
     * that is deliberately structure-level (`salary_structure_components`)
     * or Employee-level (`compensation_assignment_values`), never
     * here, per this checkpoint's resolution of "a shared structure
     * must not force every Employee to the same salary."
     *
     * `type` is `earning`|`deduction` only for 9.1 -- `statutory` is
     * reserved for Checkpoint 9.6 (the `[LEGAL REVIEW REQUIRED]` gate,
     * ADR 0028/DATA-CLASSIFICATION.md) and is added later via a small,
     * additive CHECK-widening migration, never guessed at now (rule 2).
     *
     * `liability_ledger_account_id` is this component's Finance
     * posting destination for `type='deduction'` components that will
     * participate in a posted payroll run -- a composite FK against
     * Finance's own `(id, school_id)` (rule 70), same-School only,
     * never an account-name lookup, never a hardcoded UUID. Nullable:
     * an `earning` component never needs one (earnings post in
     * aggregate to the single configured salary-expense account,
     * ADR 0034 "Accounting model"); a `deduction` component needs one
     * only once it is actually used in a posted run (checked at
     * posting time by the Application layer, not here).
     *
     * `status` (active|inactive, default active, never deleted) matches
     * the reference-entity lifecycle convention already established for
     * GradeLevel/Department/Position/etc. (CLAUDE.md rule 73) -- a
     * future salary_structure_components row may hold a real reference
     * to any of these.
     */
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type'); // earning|deduction -- statutory reserved for 9.6
            $table->uuid('liability_ledger_account_id')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');

            $table->foreign(['liability_ledger_account_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE salary_components ADD CONSTRAINT salary_components_type_check CHECK (type IN ('earning', 'deduction'))");
        DB::statement("ALTER TABLE salary_components ADD CONSTRAINT salary_components_status_check CHECK (status IN ('active', 'inactive'))");

        TenantRls::enable('salary_components');
    }

    public function down(): void
    {
        TenantRls::disable('salary_components');
        Schema::dropIfExists('salary_components');
    }
};
