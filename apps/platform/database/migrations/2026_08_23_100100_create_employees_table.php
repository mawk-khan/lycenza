<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A.1 -- the Employee core aggregate (docs/modules/HR.md,
     * ADR 0028). Employee != Employment != Assignment (those are later
     * checkpoints, 8A.4+) -- this table carries only identity-level
     * fields: School ownership, optional User linkage, the
     * concurrency-safe school-scoped `employee_number`, a display name,
     * and the Employee record's own existence state (`record_status`
     * -- distinct from Employment's own lifecycle status, which lives
     * on `employment_records` starting 8A.4, per docs/modules/HR.md's
     * state responsibility matrix; `record_status` here only answers
     * "is this Employee row itself active or archived," never
     * "is this person currently employed").
     *
     * `user_id` is nullable (an Employee may exist with no application
     * account, per docs/modules/HR.md 2.1) and `unique(school_id,
     * user_id)` rather than a global `unique(user_id)` -- a User is a
     * central/global identity (App\Models\User has no school_id) and
     * may legitimately be linked to a separate Employee record at a
     * different School, mirroring how `school_memberships` is already
     * scoped per (user_id, school_id) rather than globally per user.
     * PostgreSQL treats each NULL as distinct under a multi-column
     * unique index, so any number of Employees in the same School may
     * have `user_id = null` simultaneously -- only an actual duplicate
     * (school_id, non-null user_id) pair is rejected.
     *
     * `record_status`'s allowed values (`active`/`archived`) are
     * documented here, not database-CHECK-constrained -- matching the
     * established convention for this kind of status column
     * (`academic_years.status`, `grade_levels.status`: enforced at the
     * application layer, not a Postgres CHECK) rather than introducing
     * a new enforcement style for one column.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('employee_number');
            $table->string('full_name');
            $table->string('record_status')->default('active'); // active|archived
            $table->timestamps();

            $table->unique(['school_id', 'employee_number']);
            $table->unique(['school_id', 'user_id']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        TenantRls::enable('employees');
    }

    public function down(): void
    {
        TenantRls::disable('employees');
        Schema::dropIfExists('employees');
    }
};
