<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.3 -- HR's own organizational-department reference data
     * (docs/modules/HR.md "Department and Position strategy"; canonical
     * terminology table: reserved name `hr_departments`, DELIBERATELY
     * never bare `departments` and never `academic_departments` --
     * that table already exists (Phase 0D) and groups Subjects
     * academically, a different concept from staff organizational
     * ownership. Follows the exact `academic_departments` migration
     * template (School-wide reference data, `unique(school_id, code)`
     * + `unique(id, school_id)` + `TenantRls::enable()`), extended with
     * the two fields HR.md's contract adds on top: nullable `campus_id`
     * (School-wide when null, Campus-scoped when set) and nullable
     * `parent_department_id` (hierarchy, e.g. "Accounts" under
     * "Administration").
     *
     * `campus_id` composite-FKs to `campuses(id, school_id)` (rule 70
     * pattern) -- a School A Department can never reference a School B
     * Campus. `nullOnDelete()`: losing its Campus should demote a
     * Department back to School-wide, not block the Campus removal or
     * cascade-delete the Department itself (Departments have no delete
     * endpoint -- rule 73 -- so this is a defensive/structural choice,
     * not an expected runtime path).
     *
     * `parent_department_id` composite-self-FKs to
     * `hr_departments(id, school_id)` -- a School A Department can
     * never reference a School B parent. `nullOnDelete()` for the same
     * reason as `campus_id` (a removed parent should promote children
     * to top-level, not cascade-delete history). Self-parenting
     * (`parent_department_id = id`) is rejected by a PostgreSQL CHECK
     * constraint -- deterministic and database-enforced, mirroring
     * `academic_years_date_range_check`'s use of a plain CHECK for a
     * simple invariant. Indirect cycles (A -> B -> A) cannot be
     * expressed as a CHECK constraint (no recursion) -- that is
     * validated at the application layer by
     * App\Domain\HR\Application\DepartmentService::reparent() before
     * write, exactly like `academic_years`' own precedent of choosing
     * application validation over a database constraint for an
     * invariant genuinely too complex to express declaratively.
     */
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->uuid('parent_department_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index('campus_id');
            $table->index('parent_department_id');

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->nullOnDelete();

            $table->foreign(['parent_department_id', 'school_id'])
                ->references(['id', 'school_id'])->on('hr_departments')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE hr_departments ADD CONSTRAINT hr_departments_no_self_parent_check CHECK (parent_department_id IS NULL OR parent_department_id <> id)');

        TenantRls::enable('hr_departments');
    }

    public function down(): void
    {
        TenantRls::disable('hr_departments');
        Schema::dropIfExists('hr_departments');
    }
};
