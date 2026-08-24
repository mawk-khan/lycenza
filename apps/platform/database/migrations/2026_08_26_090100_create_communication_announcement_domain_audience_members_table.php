<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5B.1 -- the AUTHORED domain-audience selection for the
     * three new audience_type values, parallel to (and deliberately
     * NOT reusing) `communication_announcement_audience_members`
     * (which is `individual`-audience-type-specific and structurally
     * keyed to `school_membership_id`). Explicit nullable TYPED columns
     * (`student_id`/`guardian_id`), never a single generic polymorphic
     * column -- see docs/communication-hub/
     * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §7's
     * documented tradeoff (root CLAUDE.md rule 70's composite-FK
     * pattern only works against one concrete parent table per
     * column).
     *
     * Reused for BOTH `student`/`guardian` (literal targets) AND
     * `guardians_of_students` (input is Student ids; `student_id` is
     * populated the same way -- the announcement's own `audience_type`
     * is what tells a resolver whether a `student_id` row here means
     * "target this Student" or "target the Guardians of this Student")
     * -- one authored-selection table serves all three, since which
     * resolver reads it is already disambiguated by audience_type,
     * exactly like `communication_announcement_audience_members`
     * already serves only `individual`.
     *
     * `num_nonnulls(student_id, guardian_id) = 1` is the database-level
     * "exactly one typed target per row" guarantee -- never an
     * application-level-only check that a direct write could bypass
     * (root CLAUDE.md rule 25's same discipline applied here).
     */
    public function up(): void
    {
        Schema::create('communication_announcement_domain_audience_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->uuid('student_id')->nullable();
            $table->uuid('guardian_id')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'student_id'], 'caddam_announcement_student_unique');
            $table->unique(['announcement_id', 'guardian_id'], 'caddam_announcement_guardian_unique');
            $table->index('school_id');

            $table->foreign(['announcement_id', 'school_id'], 'caddam_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();

            $table->foreign(['student_id', 'school_id'], 'caddam_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['guardian_id', 'school_id'], 'caddam_guardian_school_foreign')
                ->references(['id', 'school_id'])->on('guardians')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_announcement_domain_audience_members ADD CONSTRAINT caddam_exactly_one_target_check '.
            'CHECK (num_nonnulls(student_id, guardian_id) = 1)'
        );

        TenantRls::enable('communication_announcement_domain_audience_members');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcement_domain_audience_members');
        Schema::dropIfExists('communication_announcement_domain_audience_members');
    }
};
