<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RES.3 (ADR 0068 §7, §19, §21; RES-L0 2026-10-07): the per-paper marks
     * lock and append-only, maker/checker post-lock corrections. Development
     * only (production: RES-L1).
     *
     * - `examination_paper_mark_states`: one per paper, `open` -> `locked`,
     *   one-way (no unlock); a locked row never changes again. Separate from
     *   `examination_papers.status`.
     * - `student_mark_corrections`: one request per post-lock change -- the
     *   mark, the version it corrects, the previous and proposed status and
     *   value (R3 shape), a closed reason code (no free text), the requester
     *   and the authorization qualifying at request time; then one terminal
     *   decision (`approved` / `rejected`) by a DIFFERENT person (CHECK), with
     *   the authorization qualifying at approval. One pending request per mark.
     *   Request fields never change; a decided request never changes.
     * - `student_marks` gains two guards: once its paper is locked, a mark may
     *   not be created, and may change only to exactly what a pending
     *   correction proposes for its current version -- and that correction must
     *   be approved by commit (deferred). So no post-lock write bypasses
     *   maker/checker, even a raw one. The value history
     *   (`student_mark_revisions`) still records every change.
     * - Retention: anchored, delete-guarded, runtime DELETE revoked;
     *   catalogued with StudentMark as `policy_unresolved` (RES-L8).
     */
    public function up(): void
    {
        Schema::create('examination_paper_mark_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('examination_paper_id');
            $table->string('state');
            $table->foreignUuid('locked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'examination_paper_id'], 'examination_paper_mark_states_one_per_paper');
            $table->foreign(['examination_paper_id', 'school_id'], 'examination_paper_mark_states_paper_fk')
                ->references(['id', 'school_id'])->on('examination_papers')->restrictOnDelete();
        });

        Schema::create('student_mark_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_mark_id');
            $table->uuid('examination_paper_id');
            $table->uuid('student_id');
            $table->unsignedInteger('base_version');
            $table->string('previous_status');
            $table->decimal('previous_value', 6, 2)->nullable();
            $table->string('proposed_status');
            $table->decimal('proposed_value', 6, 2)->nullable();
            $table->string('reason_code');
            $table->string('status')->default('pending');
            $table->string('processing_purpose')->default('academic_records');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->uuid('request_processing_authorization_id');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('decision_processing_authorization_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->foreign(['student_mark_id', 'school_id'], 'student_mark_corrections_mark_fk')
                ->references(['id', 'school_id'])->on('student_marks')->restrictOnDelete();
            $table->foreign(['examination_paper_id', 'school_id'], 'student_mark_corrections_paper_fk')
                ->references(['id', 'school_id'])->on('examination_papers')->restrictOnDelete();
            $table->foreign(['student_id', 'school_id'], 'student_mark_corrections_student_fk')
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            // The registry's own context key: the same Student's `academic_records` grant, at request and at approval.
            $table->foreign(['request_processing_authorization_id', 'school_id', 'student_id', 'processing_purpose'], 'student_mark_corrections_request_authorization_fk')
                ->references(['id', 'school_id', 'student_id', 'purpose'])->on('student_processing_authorizations')->restrictOnDelete();
            $table->foreign(['decision_processing_authorization_id', 'school_id', 'student_id', 'processing_purpose'], 'student_mark_corrections_decision_authorization_fk')
                ->references(['id', 'school_id', 'student_id', 'purpose'])->on('student_processing_authorizations')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE examination_paper_mark_states
                ADD CONSTRAINT examination_paper_mark_states_state_check CHECK (state IN ('open', 'locked')),
                ADD CONSTRAINT examination_paper_mark_states_lock_shape_check CHECK ((state = 'locked') = (locked_by_user_id IS NOT NULL AND locked_at IS NOT NULL));

            ALTER TABLE student_mark_corrections
                ADD CONSTRAINT student_mark_corrections_status_check CHECK (status IN ('pending', 'approved', 'rejected')),
                ADD CONSTRAINT student_mark_corrections_reason_check CHECK (reason_code IN ('entry_error', 'totalling_error', 'status_error')),
                ADD CONSTRAINT student_mark_corrections_previous_shape_check CHECK (previous_status IN ('present', 'absent', 'exempt') AND (previous_status = 'present') = (previous_value IS NOT NULL)),
                ADD CONSTRAINT student_mark_corrections_proposed_shape_check CHECK (proposed_status IN ('present', 'absent', 'exempt') AND (proposed_status = 'present') = (proposed_value IS NOT NULL) AND (proposed_value IS NULL OR proposed_value >= 0)),
                ADD CONSTRAINT student_mark_corrections_changes_something_check CHECK (proposed_status IS DISTINCT FROM previous_status OR proposed_value IS DISTINCT FROM previous_value),
                ADD CONSTRAINT student_mark_corrections_purpose_check CHECK (processing_purpose = 'academic_records'),
                ADD CONSTRAINT student_mark_corrections_decision_shape_check CHECK (
                    (status = 'pending') = (decided_by_user_id IS NULL AND decided_at IS NULL)
                    AND (status = 'approved') = (decision_processing_authorization_id IS NOT NULL)),
                ADD CONSTRAINT student_mark_corrections_maker_checker_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id);
            CREATE UNIQUE INDEX student_mark_corrections_one_pending_per_mark ON student_mark_corrections (school_id, student_mark_id) WHERE status = 'pending';

            -- open -> locked, once; a locked state never changes; identity is immutable.
            CREATE FUNCTION examination_paper_mark_states_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.examination_paper_id IS DISTINCT FROM OLD.examination_paper_id THEN
                    RAISE EXCEPTION 'examination_paper_mark_states: the paper never changes' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.state = 'locked' THEN
                    RAISE EXCEPTION 'examination_paper_mark_states: a locked paper''s marks state never changes (no unlock)' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION examination_paper_mark_states_guard() FROM PUBLIC;
            CREATE TRIGGER examination_paper_mark_states_guard_trigger BEFORE UPDATE ON examination_paper_mark_states
                FOR EACH ROW EXECUTE FUNCTION examination_paper_mark_states_guard();

            -- A request matches its mark exactly; a decision is terminal, by someone else, and an approval is applied.
            CREATE FUNCTION student_mark_corrections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_mark record;
                v_max numeric;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' THEN
                        RAISE EXCEPTION 'student_mark_corrections: a correction starts pending' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT m.examination_paper_id, m.student_id, m.version, m.status, m.value INTO v_mark
                      FROM public.student_marks m WHERE m.id = NEW.student_mark_id AND m.school_id = NEW.school_id;
                    IF NOT FOUND OR v_mark.examination_paper_id IS DISTINCT FROM NEW.examination_paper_id OR v_mark.student_id IS DISTINCT FROM NEW.student_id
                       OR v_mark.version IS DISTINCT FROM NEW.base_version OR v_mark.status IS DISTINCT FROM NEW.previous_status
                       OR v_mark.value IS DISTINCT FROM NEW.previous_value THEN
                        RAISE EXCEPTION 'student_mark_corrections: the request does not match its mark''s current version' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM public.examination_paper_mark_states s
                                    WHERE s.examination_paper_id = NEW.examination_paper_id AND s.school_id = NEW.school_id AND s.state = 'locked') THEN
                        RAISE EXCEPTION 'student_mark_corrections: corrections are only for a locked paper' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT max_marks INTO v_max FROM public.examination_papers WHERE id = NEW.examination_paper_id AND school_id = NEW.school_id;
                    IF NEW.proposed_value IS NOT NULL AND NEW.proposed_value > v_max THEN
                        RAISE EXCEPTION 'student_mark_corrections: the proposed value exceeds the paper''s maximum marks' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'student_mark_corrections: a decided correction never changes' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.student_mark_id IS DISTINCT FROM OLD.student_mark_id
                   OR NEW.examination_paper_id IS DISTINCT FROM OLD.examination_paper_id OR NEW.student_id IS DISTINCT FROM OLD.student_id
                   OR NEW.base_version IS DISTINCT FROM OLD.base_version OR NEW.previous_status IS DISTINCT FROM OLD.previous_status
                   OR NEW.previous_value IS DISTINCT FROM OLD.previous_value OR NEW.proposed_status IS DISTINCT FROM OLD.proposed_status
                   OR NEW.proposed_value IS DISTINCT FROM OLD.proposed_value OR NEW.reason_code IS DISTINCT FROM OLD.reason_code
                   OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
                   OR NEW.request_processing_authorization_id IS DISTINCT FROM OLD.request_processing_authorization_id THEN
                    RAISE EXCEPTION 'student_mark_corrections: a request''s fields never change' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'approved' AND NOT EXISTS (
                    SELECT 1 FROM public.student_marks m
                     WHERE m.id = NEW.student_mark_id AND m.school_id = NEW.school_id AND m.version = NEW.base_version + 1
                       AND m.status = NEW.proposed_status AND m.value IS NOT DISTINCT FROM NEW.proposed_value
                ) THEN
                    RAISE EXCEPTION 'student_mark_corrections: an approval must apply exactly this correction to the mark' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_mark_corrections_guard() FROM PUBLIC;
            CREATE TRIGGER student_mark_corrections_guard_trigger BEFORE INSERT OR UPDATE ON student_mark_corrections
                FOR EACH ROW EXECUTE FUNCTION student_mark_corrections_guard();

            -- A locked paper's marks: no new mark; a change only to exactly what a pending correction of this version proposes.
            CREATE FUNCTION student_marks_lock_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.examination_paper_mark_states s
                                WHERE s.examination_paper_id = NEW.examination_paper_id AND s.school_id = NEW.school_id AND s.state = 'locked') THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION 'student_marks: the paper''s marks are locked' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.student_mark_corrections c
                     WHERE c.student_mark_id = NEW.id AND c.school_id = NEW.school_id AND c.status = 'pending'
                       AND c.base_version = OLD.version AND c.proposed_status = NEW.status AND c.proposed_value IS NOT DISTINCT FROM NEW.value
                ) THEN
                    RAISE EXCEPTION 'student_marks: a locked mark changes only through a pending correction' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_marks_lock_guard() FROM PUBLIC;
            CREATE TRIGGER student_marks_lock_guard_trigger BEFORE INSERT OR UPDATE ON student_marks
                FOR EACH ROW EXECUTE FUNCTION student_marks_lock_guard();

            -- ... and that correction is approved by commit (a pending one cannot leave a changed mark behind).
            CREATE FUNCTION student_marks_locked_change_approved() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM public.examination_paper_mark_states s
                            WHERE s.examination_paper_id = NEW.examination_paper_id AND s.school_id = NEW.school_id AND s.state = 'locked')
                   AND NOT EXISTS (
                    SELECT 1 FROM public.student_mark_corrections c
                     WHERE c.student_mark_id = NEW.id AND c.school_id = NEW.school_id AND c.status = 'approved' AND c.base_version = NEW.version - 1
                ) THEN
                    RAISE EXCEPTION 'student_marks: a locked mark''s change was not approved in the same transaction' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
            REVOKE ALL ON FUNCTION student_marks_locked_change_approved() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER student_marks_locked_change_approved AFTER UPDATE ON student_marks
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION student_marks_locked_change_approved();

            -- E21-RH.7 (ADR 0066 §15): database-recorded anchors and the retention delete guards.
            ALTER TABLE examination_paper_mark_states ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON examination_paper_mark_states FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('examination_paper_id', 'state');
            CREATE TRIGGER trg_retention_guard_examination_paper_mark_states AFTER DELETE ON examination_paper_mark_states
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            ALTER TABLE student_mark_corrections ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON student_mark_corrections FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('student_mark_id', 'examination_paper_id', 'student_id', 'status', 'request_processing_authorization_id', 'decision_processing_authorization_id');
            CREATE TRIGGER trg_retention_guard_student_mark_corrections AFTER DELETE ON student_mark_corrections
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('examination_paper_mark_states');
        TenantRls::revokeDelete('examination_paper_mark_states');
        TenantRls::enable('student_mark_corrections');
        TenantRls::revokeDelete('student_mark_corrections');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS student_marks_locked_change_approved ON student_marks;
            DROP FUNCTION IF EXISTS student_marks_locked_change_approved();
            DROP TRIGGER IF EXISTS student_marks_lock_guard_trigger ON student_marks;
            DROP FUNCTION IF EXISTS student_marks_lock_guard();
            SQL);
        TenantRls::disable('student_mark_corrections');
        Schema::dropIfExists('student_mark_corrections');
        TenantRls::disable('examination_paper_mark_states');
        Schema::dropIfExists('examination_paper_mark_states');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS student_mark_corrections_guard();
            DROP FUNCTION IF EXISTS examination_paper_mark_states_guard();
            SQL);
    }
};
