<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.6 -- an Employee's 1:N Restricted-tier academic
     * qualification history (docs/modules/HR.md entity model:
     * `EmployeeQualification (1:N -- Restricted tier)`). Same
     * tenant-safe child-record shape 8A.2's three tables already
     * established: `employee_id` composite-FKs to `employees(id,
     * school_id)` (rule 70 pattern) with cascadeOnDelete -- a
     * Qualification has no meaning independent of its owning Employee.
     *
     * `qualification_type` (secondary|higher_secondary|diploma|
     * bachelors|masters|doctorate|professional|other) is a plain,
     * application-validated string, matching `employment_type`'s exact
     * convention -- not a Postgres enum, not a PHP enum, since these
     * are descriptive reference values, never branched on by
     * application logic.
     *
     * `institution`/`awarding_body` are plain descriptive strings, not
     * normalized into a School OS organization/reference-data table --
     * an external university/board is not a School OS tenant and has
     * no reason to become one just because an Employee studied there.
     *
     * Date semantics: `starts_on`/`completed_on` are both nullable
     * dates (not timestamps -- these are date-only academic periods).
     * `completed_on = NULL` legitimately represents an in-progress
     * qualification. No derived age/duration column is stored.
     *
     * Verification: `verification_status` (unverified|verified|
     * rejected, default 'unverified') plus `verified_at` (nullable
     * timestamp). Deliberately no `verified_by_user_id` column --
     * `AuditRecorder`'s existing `actor_user_id` capture is sufficient
     * to answer "who verified this," matching the brief's own guidance
     * that audit-actor identity is enough absent an approved
     * verifier-field contract. `verification_status` is never
     * settable through the ordinary create/update write path --
     * App\Domain\HR\Application\EmployeeQualificationService::verify()/
     * reject() are the only writers, and a material edit via update()
     * resets an already-verified/rejected record back to 'unverified'
     * (see that service's docblock).
     *
     * No `document_id`/`file_path`/evidence-attachment column of any
     * kind -- document evidence is explicitly deferred to Phase 8A.7's
     * `employee_documents` (ADR 0028), which will reference this table
     * only loosely via its own `category` reference value, never a
     * hard FK from here.
     */
    public function up(): void
    {
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('qualification_type'); // secondary|higher_secondary|diploma|bachelors|masters|doctorate|professional|other
            $table->string('qualification_name');
            $table->string('specialization')->nullable();
            $table->string('institution');
            $table->string('awarding_body')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('grade_or_result')->nullable();
            $table->string('verification_status')->default('unverified'); // unverified|verified|rejected
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('employee_id');
            $table->index(['employee_id', 'verification_status']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE employee_qualifications ADD CONSTRAINT employee_qualifications_date_range_check '.
            'CHECK (starts_on IS NULL OR completed_on IS NULL OR completed_on >= starts_on)'
        );

        TenantRls::enable('employee_qualifications');
    }

    public function down(): void
    {
        TenantRls::disable('employee_qualifications');
        Schema::dropIfExists('employee_qualifications');
    }
};
