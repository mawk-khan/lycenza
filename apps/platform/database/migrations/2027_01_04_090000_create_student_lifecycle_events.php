<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.8E (ADR 0076 §63, owner decision 8E-D1): the structured, immutable
 * record of every high-impact Student lifecycle action -- deactivating or
 * reactivating a Student, and withdrawing or cancelling an Enrollment. One
 * row per action, written by StudentService / StudentEnrollmentService in the
 * action's own transaction, with a code-owned reason (CHECKed per action) and
 * the acting User. Append-only for the runtime role; FORCE RLS; same-School
 * references. School and User are RESTRICT (rule 97); the Student and
 * Enrollment keys cascade, because the event is part of the Student core
 * record and leaves with it under D7 retention (declared handled there).
 *
 * `student_enrollments_lifecycle_evidence` (BEFORE UPDATE OF status): an
 * Enrollment becomes `withdrawn` or `cancelled` only with its event row,
 * written in the same transaction (`occurred_at` = the transaction's
 * timestamp). Student status changes keep the event row as the service's
 * record; the database does not require it there (a raw runtime UPDATE of
 * `students.status` is the recorded-actor trust boundary, §63).
 *
 * There is no free-text reason: the codes are closed (no `other`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_lifecycle_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('student_id');
            $table->uuid('student_enrollment_id')->nullable();
            $table->string('action', 32);
            $table->string('reason_code', 32);
            $table->foreignUuid('acted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at', 6)->useCurrent(); // microseconds: it must equal now() exactly

            $table->index(['school_id', 'student_id', 'occurred_at'], 'student_lifecycle_events_student_idx');
            // The event is part of the Student core record (D7): it leaves with its Student / Enrollment
            // under retention (the purges declare it handled), never on its own.
            $table->foreign(['student_id', 'school_id'], 'student_lifecycle_events_student_fk')
                ->references(['id', 'school_id'])->on('students')->cascadeOnDelete();
            $table->foreign(['student_enrollment_id', 'school_id'], 'student_lifecycle_events_enrollment_fk')
                ->references(['id', 'school_id'])->on('student_enrollments')->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE student_lifecycle_events ADD CONSTRAINT student_lifecycle_events_reason_check CHECK (
                (action = 'student_deactivated' AND student_enrollment_id IS NULL
                    AND reason_code IN ('left_school', 'transferred_out', 'completed_studies', 'duplicate_record', 'entered_in_error'))
                OR (action = 'student_reactivated' AND student_enrollment_id IS NULL
                    AND reason_code IN ('returned_to_school', 'deactivated_in_error'))
                OR (action = 'enrollment_withdrawn' AND student_enrollment_id IS NOT NULL
                    AND reason_code IN ('left_school', 'transferred_out', 'guardian_request'))
                OR (action = 'enrollment_cancelled' AND student_enrollment_id IS NOT NULL
                    AND reason_code IN ('did_not_join', 'duplicate_placement', 'entered_in_error'))
            );

            CREATE FUNCTION public.student_enrollments_require_lifecycle_evidence() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.student_lifecycle_events e
                     WHERE e.school_id = NEW.school_id AND e.student_enrollment_id = NEW.id AND e.student_id = NEW.student_id
                       AND e.action = CASE NEW.status WHEN 'withdrawn' THEN 'enrollment_withdrawn' ELSE 'enrollment_cancelled' END
                       AND e.occurred_at = now()
                ) THEN
                    RAISE EXCEPTION 'student_enrollments_lifecycle_evidence: enrollment % is % without its lifecycle event', NEW.id, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER student_enrollments_lifecycle_evidence BEFORE UPDATE OF status ON student_enrollments
                FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('withdrawn', 'cancelled'))
                EXECUTE FUNCTION public.student_enrollments_require_lifecycle_evidence();

            REVOKE ALL ON FUNCTION public.student_enrollments_require_lifecycle_evidence() FROM PUBLIC;
            SQL);

        TenantRls::enable('student_lifecycle_events');
        TenantRls::makeAppendOnly('student_lifecycle_events');
    }

    public function down(): void
    {
        if (DB::table('student_lifecycle_events')->exists()) {
            throw new RuntimeException('Refusing to roll back: student_lifecycle_events holds lifecycle history (ORG.8E).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS student_enrollments_lifecycle_evidence ON student_enrollments;
            DROP FUNCTION IF EXISTS public.student_enrollments_require_lifecycle_evidence();
            SQL);
        TenantRls::disable('student_lifecycle_events');
        Schema::dropIfExists('student_lifecycle_events');
    }
};
