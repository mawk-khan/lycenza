<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TCH.2 (ADR 0063 sections 7, 9, 10, 19): the authoritative, dated
 * teaching-ownership fact -- "this Employee owns this Section + required
 * SubjectOffering teaching context from starts_on to ends_on". It
 * authorizes nothing by itself: a future owned-resource check combines it
 * with a verified ActingEmployee AND an owned-scope capability.
 *
 * Structure (CLAUDE.md rules 17, 18, 70):
 * - School-owned, forced RLS.
 * - `(employee_id, school_id)` -> employees, and the Section and the
 *   SubjectOffering each through their 5-column context key
 *   (`sections_context_unique` / `subject_offerings_context_unique`) over
 *   the SAME stored academic_year_id/campus_id/grade_level_id. A Section
 *   and an Offering of different Schools, years, campuses or grades can
 *   therefore never be paired, even by raw SQL. Every parent is RESTRICT:
 *   historical ownership survives, parents are deactivated, never deleted.
 * - Dates are School-local and inclusive; `ends_on` NULL is open-ended.
 *
 * History (ADR 0063 section 9): rows are ended, never deleted -- the
 * runtime role has no DELETE (a School's own cascade is unaffected), and
 * `trg_teaching_assignments_history` freezes the identity (Employee,
 * Section, Offering, context, starts_on, creator). The one permitted
 * change is a single end: ended_at/ended_by_user_id/end_reason set
 * together, ends_on set or shortened, never before starts_on. An ended
 * row is immutable.
 *
 * Overlap (ADR 0063 section 10): deliberately NO partial unique "one open
 * row" index (`... WHERE ended_at IS NULL`) -- it would block a
 * future-dated replacement. TeachingAssignmentService serializes each
 * assignment key (School, Employee, Section, Offering) on a
 * transaction-scoped advisory lock and rejects an overlapping period
 * under it, the EmploymentService lock-then-check pattern. No exact-
 * duplicate unique index either: an exact duplicate IS an overlap, which
 * that check already refuses, and a unique index on the period would not
 * stop the far more common partial overlap -- it would add a constraint
 * for appearance only.
 */
return new class extends Migration
{
    private const END_REASONS = "'completed', 'reassigned', 'employment_ended'";

    public function up(): void
    {
        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->uuid('academic_year_id');
            $table->uuid('campus_id');
            $table->uuid('grade_level_id');
            $table->uuid('section_id');
            $table->uuid('subject_offering_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'employee_id', 'section_id', 'subject_offering_id', 'starts_on'], 'teaching_assignments_key_idx');
            $table->index(['school_id', 'section_id', 'subject_offering_id'], 'teaching_assignments_context_idx');
            $table->index(['academic_year_id']);
            $table->index(['employee_id']);

            $table->foreign(['employee_id', 'school_id'], 'teaching_assignments_employee_fk')
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();

            $table->foreign(
                ['section_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'teaching_assignments_section_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('sections')
                ->restrictOnDelete();

            $table->foreign(
                ['subject_offering_id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'],
                'teaching_assignments_subject_offering_fk',
            )
                ->references(['id', 'school_id', 'academic_year_id', 'campus_id', 'grade_level_id'])
                ->on('subject_offerings')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE teaching_assignments ADD CONSTRAINT teaching_assignments_date_range_check '
            .'CHECK (ends_on IS NULL OR starts_on <= ends_on)');

        DB::statement('ALTER TABLE teaching_assignments ADD CONSTRAINT teaching_assignments_end_shape_check '
            .'CHECK ((ended_at IS NULL) = (ended_by_user_id IS NULL) AND (ended_at IS NULL) = (end_reason IS NULL) '
            .'AND (ended_at IS NULL OR ends_on IS NOT NULL) '
            .'AND (end_reason IS NULL OR end_reason IN ('.self::END_REASONS.')))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION teaching_assignments_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.ended_at IS NOT NULL THEN
                        RAISE EXCEPTION 'teaching_assignments: an assignment cannot be created already ended';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.ended_at IS NOT NULL THEN
                    RAISE EXCEPTION 'teaching_assignments: an ended assignment is immutable';
                END IF;
                IF NEW.ended_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.employee_id IS DISTINCT FROM OLD.employee_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
                    OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id
                    OR NEW.section_id IS DISTINCT FROM OLD.section_id
                    OR NEW.subject_offering_id IS DISTINCT FROM OLD.subject_offering_id
                    OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'teaching_assignments: the only permitted change is ending the assignment';
                END IF;
                IF OLD.ends_on IS NOT NULL AND NEW.ends_on > OLD.ends_on THEN
                    RAISE EXCEPTION 'teaching_assignments: ending may shorten an assignment, never extend it';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_teaching_assignments_history
                BEFORE INSERT OR UPDATE ON teaching_assignments
                FOR EACH ROW EXECUTE FUNCTION teaching_assignments_history_guard();
            SQL);

        TenantRls::enable('teaching_assignments');
        TenantRls::revokeDelete('teaching_assignments');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_teaching_assignments_history ON teaching_assignments;
            DROP FUNCTION IF EXISTS teaching_assignments_history_guard();
            SQL);

        TenantRls::disable('teaching_assignments');
        Schema::dropIfExists('teaching_assignments');
    }
};
