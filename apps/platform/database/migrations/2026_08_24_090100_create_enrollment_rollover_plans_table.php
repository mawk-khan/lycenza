<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B.7A: the durable header of a reviewed, bulk cross-
     * Academic-Year Enrollment rollover -- see
     * docs/modules/STUDENT-ENROLLMENT.md ("Academic-Year Rollover &
     * Promotion — Architecture Decision (Phase 1B.7)") for the full
     * decision record this schema implements. This migration creates
     * ONLY the durable representation; no dry-run/eligibility engine,
     * no execution engine, and no Enrollment mutation exist yet
     * (1B.7B/1B.7C).
     *
     * `status` lifecycle (plain string, matching every other lifecycle
     * column in this codebase -- no PHP backed enum, no DB CHECK
     * enumerating values, exactly like AcademicYear/Section/GradeLevel):
     *   draft -> validated -> executing -> completed | completed_with_errors
     *   draft | validated -> cancelled
     * `configuration_version`/`validated_configuration_version` are the
     * explicit staleness-detection mechanism the architecture decision
     * calls for: any future mapping/item edit increments
     * `configuration_version`; a future dry-run records the version it
     * validated into `validated_configuration_version`. Execution may
     * only proceed when they are equal -- an edit after the last
     * validation is then structurally undeniable, never inferred from
     * ambiguous timestamps. Neither is populated by anything in THIS
     * checkpoint (no plan-editing/dry-run service exists yet).
     *
     * Source/target AcademicYear are both required and must differ
     * (CHECK below) and must both belong to the SAME School as the
     * plan (composite FKs below) -- never derived automatically
     * (calendar arithmetic, "latest created year", etc., all
     * explicitly rejected by the architecture decision).
     *
     * At most one OPEN plan (`draft`/`validated`/`executing`) may exist
     * per (School, source year, target year) -- a PostgreSQL partial
     * unique index, the same `academic_years_one_active_per_school`-
     * style pattern already established in this codebase, so a second
     * concurrently-open plan for the identical year pair is rejected by
     * the database itself, not an application check-then-insert.
     * Terminal plans (`completed`/`completed_with_errors`/`cancelled`)
     * are explicitly exempt -- historical plans are retained, never
     * deleted, and a School may legitimately re-attempt a cancelled
     * plan's year pair.
     */
    public function up(): void
    {
        Schema::create('enrollment_rollover_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('source_academic_year_id');
            $table->uuid('target_academic_year_id');
            $table->string('status')->default('draft'); // draft|validated|executing|completed|completed_with_errors|cancelled
            $table->unsignedInteger('configuration_version')->default(1);
            $table->unsignedInteger('validated_configuration_version')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from mapping/item tables
            $table->index(['school_id', 'status']);
            $table->index(['source_academic_year_id']);
            $table->index(['target_academic_year_id']);

            $table->foreign(['source_academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['target_academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE enrollment_rollover_plans ADD CONSTRAINT enrollment_rollover_plans_source_target_differ_check '.
            'CHECK (source_academic_year_id <> target_academic_year_id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX enrollment_rollover_plans_one_open_per_year_pair '.
            'ON enrollment_rollover_plans (school_id, source_academic_year_id, target_academic_year_id) '.
            "WHERE status IN ('draft', 'validated', 'executing')"
        );

        TenantRls::enable('enrollment_rollover_plans');
    }

    public function down(): void
    {
        TenantRls::disable('enrollment_rollover_plans');
        Schema::dropIfExists('enrollment_rollover_plans');
    }
};
