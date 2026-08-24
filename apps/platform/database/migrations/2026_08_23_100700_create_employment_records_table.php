<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.4 -- one legal/organizational engagement of an Employee
     * with the School (docs/modules/HR.md canonical terminology:
     * `Employment`). Named `employment_records`, not `hr_employment_records`
     * -- unlike `Department`, "Employment" has no existing collision risk
     * anywhere else in the codebase, so the `hr_` prefix isn't needed
     * (matches HR.md's own naming throughout "Temporal data strategy"/
     * "Rehire strategy").
     *
     * Rehire support: an Employee may have MULTIPLE `employment_records`
     * rows over time (Employment #1 ends, Employment #2 begins later) --
     * this table is deliberately 1:N from Employee, never overwritten in
     * place (docs/modules/HR.md "Rehire strategy": "Employee row is
     * never duplicated... Each EmploymentRecord is independently
     * timestamped").
     *
     * `starts_on` required, inclusive. `ends_on` nullable, inclusive
     * when present; NULL means current/ongoing employment (HR.md
     * "Temporal data strategy" -- the one deliberate semantic
     * difference from `academic_years`, which is always fully bounded).
     * The CHECK constraint uses `<=`, not the strict `<`
     * `academic_years_date_range_check` uses -- a single-day engagement
     * (e.g. a same-day termination) is a legitimate real-world case for
     * employment in a way it isn't for an academic year.
     *
     * `probation_ends_on` -- HR.md's "Employee lifecycle" state
     * matrix explicitly assigns this field to `employment_records`
     * ("Probation is better modeled as a boolean/date pair on the
     * active Employment... once 8A.4 needs it") -- this is that
     * checkpoint.
     *
     * `status` candidate values are HR.md's own exact list from the
     * same state matrix: draft|pre_joining|active|notice_period|
     * separated|terminated|retired|deceased -- a plain, application-
     * validated string, matching every other status column in this
     * codebase (never a Postgres enum, never a PHP enum).
     *
     * `employment_type` (permanent|probationary|fixed_term|part_time|
     * temporary|contract|consultant) is likewise a plain,
     * application-validated string -- these are stable, universal HR
     * concepts a School does not need to customize per-School the way
     * Department/Position names are, so this is NOT modeled as
     * School-configurable reference data (no new table introduced for
     * it).
     *
     * Overlap policy: an Employee must not have two overlapping
     * `employment_records` at the same School. This is NOT database-
     * constrained here (no `daterange`/`EXCLUDE USING gist` -- no such
     * extension/pattern exists anywhere in this codebase, and
     * introducing one for a low-write-rate, human-initiated action
     * would be exactly the premature complexity `academic_years`'
     * own migration already reasoned against for an analogous case).
     * It is enforced by App\Domain\HR\Application\EmploymentService::create(),
     * which locks the Employee row (`lockForUpdate()`) before checking
     * existing records for overlap and inserting, inside one
     * transaction -- the same lock-then-check-then-write pattern
     * App\Domain\HR\Application\EmployeeNumberAllocator and
     * AcademicYearService already established.
     *
     * `unique(id, school_id)` exists so App\Domain\HR\Infrastructure\EmployeeAssignment
     * (created later in this same migration set) can composite-FK to
     * a specific Employment tenant-safely.
     */
    public function up(): void
    {
        Schema::create('employment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('employment_type'); // permanent|probationary|fixed_term|part_time|temporary|contract|consultant
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('probation_ends_on')->nullable();
            $table->string('status'); // draft|pre_joining|active|notice_period|separated|terminated|retired|deceased
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index('employee_id');
            $table->index(['employee_id', 'ends_on']);

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE employment_records ADD CONSTRAINT employment_records_date_range_check CHECK (ends_on IS NULL OR starts_on <= ends_on)');

        TenantRls::enable('employment_records');
    }

    public function down(): void
    {
        TenantRls::disable('employment_records');
        Schema::dropIfExists('employment_records');
    }
};
