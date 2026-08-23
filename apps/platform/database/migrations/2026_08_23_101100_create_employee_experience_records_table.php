<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.6 -- an Employee's 1:N Restricted-tier record of
     * professional experience OUTSIDE this School's own employment
     * (docs/modules/HR.md entity model: `EmployeeExperience (1:N --
     * Restricted tier)`). Table named `employee_experience_records`,
     * not `employee_experiences` -- deliberately disambiguated from
     * `employment_records` (this School's own employment history) so
     * the two are never confused at the schema level, matching the
     * checkpoint brief's own naming.
     *
     * **Employee != Employment != Experience**: `employment_records`
     * represents employment WITH this School tenant; this table
     * represents external/historical professional experience the
     * Employee reports as part of their HR profile. Deliberately has
     * no `employment_record_id`/`campus_id`/`department_id`/
     * `position_id` column and no composite FK to any of those tables
     * -- an external employer is never encoded as a School OS
     * EmploymentRecord.
     *
     * No verification model (unlike Qualification/Certification) --
     * the checkpoint brief explicitly cautions against assuming
     * external experience needs the same verified/unverified/rejected
     * workflow as a formally-issued credential, and no reference-check
     * contact data is collected to support one. Kept minimal.
     *
     * Temporal semantics: `starts_on` required, `ends_on` nullable
     * (NULL = this specific experience entry is ongoing -- it does NOT
     * mean "current School employment", which lives entirely on
     * `employment_records`). Deliberately NO overlap constraint --
     * concurrent external experience (part-time, consulting, study +
     * work) is legitimate and common, unlike `employment_records`'
     * own no-overlap invariant for employment at this School.
     */
    public function up(): void
    {
        Schema::create('employee_experience_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('organization');
            $table->string('job_title');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index('employee_id');
            $table->index(['employee_id', 'starts_on']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE employee_experience_records ADD CONSTRAINT employee_experience_records_date_range_check '.
            'CHECK (ends_on IS NULL OR ends_on >= starts_on)'
        );

        TenantRls::enable('employee_experience_records');
    }

    public function down(): void
    {
        TenantRls::disable('employee_experience_records');
        Schema::dropIfExists('employee_experience_records');
    }
};
