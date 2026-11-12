<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C29 (C14-N5, owner decisions C29-A/B/C, HR with Security, 2026-11-12): a verification names the
 * revision it verified, and nothing else can make a qualification or certification verified or rejected.
 *
 * Before this, Verify and Reject named nothing. A decision landing after an edit attested content the verifier
 * never saw, and an edit wiped `verified_at`, so nothing recorded which content had been verified.
 * Reproduced on `5be4f9ad` (`HrCredentialVerificationRaceTest`).
 *
 * - **C29-A.** Each credential row carries a `revision` that only the database moves: any actual change to a
 *   verifiable field (every column but the verification projection, the archive marks and timestamps, so a new
 *   column counts) bumps it, and the change must reset verification. A decision names the revision the
 *   verifier saw; any other revision is refused. A credential reads verified or rejected only while
 *   `verification_revision` equals its `revision`.
 * - **C29-B.** `employee_credential_verifications` keeps every decision: credential, revision, decision,
 *   decider, time. The runtime role inserts only, and nothing updates a row. The insert is the only thing that
 *   sets the projection (`trg_employee_credential_verifications_apply`), and the credential guard refuses a
 *   projection the latest decision does not state. It records which revision was decided, never a copy of
 *   the Restricted fields.
 * - **C29-C.** A credential verified or rejected before this keeps its status with `verification_revision`
 *   NULL: "before version recording". No decision row and no revision is fabricated for it. Nothing can write
 *   that state again.
 *
 * Who decided stays an application boundary (rule 102): the database records the named decider and cannot prove
 * who pressed the button. No MFA or independence rule is added (C29 scope).
 *
 * Retention: a decision leaves with its credential (FK cascade), so it follows `hr_ancillary` (D9, 2 y).
 */
return new class extends Migration
{
    private const CREDENTIALS = ['employee_qualifications' => 'qualification', 'employee_certifications' => 'certification'];

    public function up(): void
    {
        foreach (array_keys(self::CREDENTIALS) as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->bigInteger('revision')->default(1);
                $t->bigInteger('verification_revision')->nullable();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_id_employee_school_unique UNIQUE (id, employee_id, school_id)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_status_check CHECK (verification_status IN ('unverified', 'verified', 'rejected'))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_revision_check CHECK (revision >= 1)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_verification_revision_check CHECK (
                (verification_status = 'unverified' AND verification_revision IS NULL)
                OR (verification_status <> 'unverified' AND (verification_revision IS NULL OR verification_revision = revision)))");
        }

        Schema::create('employee_credential_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->string('credential_kind', 15);
            $table->uuid('qualification_id')->nullable();
            $table->uuid('certification_id')->nullable();
            $table->bigInteger('revision');
            $table->string('decision', 10);
            $table->foreignUuid('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at', 6);

            $table->index(['school_id', 'qualification_id'], 'employee_credential_verifications_qualification_idx');
            $table->index(['school_id', 'certification_id'], 'employee_credential_verifications_certification_idx');
            $table->index(['decided_by_user_id'], 'employee_credential_verifications_decided_by_idx');

            // The decision names its own Employee's credential in its own School, and leaves with it.
            $table->foreign(['qualification_id', 'employee_id', 'school_id'], 'employee_credential_verifications_qualification_fk')
                ->references(['id', 'employee_id', 'school_id'])->on('employee_qualifications')->cascadeOnDelete();
            $table->foreign(['certification_id', 'employee_id', 'school_id'], 'employee_credential_verifications_certification_fk')
                ->references(['id', 'employee_id', 'school_id'])->on('employee_certifications')->cascadeOnDelete();
        });

        // The order of decisions on one credential; the latest is what the credential must state. The guard assigns it
        // after the credential's row lock, discarding any value a writer supplied (OVERRIDING SYSTEM VALUE included).
        DB::statement('ALTER TABLE employee_credential_verifications ADD COLUMN sequence bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('ALTER TABLE employee_credential_verifications ADD CONSTRAINT employee_credential_verifications_sequence_unique UNIQUE (sequence)');
        DB::statement("ALTER TABLE employee_credential_verifications ADD CONSTRAINT employee_credential_verifications_kind_check CHECK (
            (credential_kind = 'qualification' AND qualification_id IS NOT NULL AND certification_id IS NULL)
            OR (credential_kind = 'certification' AND certification_id IS NOT NULL AND qualification_id IS NULL))");
        DB::statement("ALTER TABLE employee_credential_verifications ADD CONSTRAINT employee_credential_verifications_decision_check CHECK (decision IN ('verified', 'rejected'))");
        DB::statement('ALTER TABLE employee_credential_verifications ADD CONSTRAINT employee_credential_verifications_revision_check CHECK (revision >= 1)');

        TenantRls::enable('employee_credential_verifications');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON employee_credential_verifications FROM school_os_app');

        DB::unprepared(<<<'SQL'
            -- C29-A: the revision is the database's, every change to a verifiable field moves it and resets
            -- verification, and only the latest recorded decision on this revision sets verified or rejected.
            CREATE FUNCTION public.hr_credential_revision_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_projection constant text[] := ARRAY['revision', 'verification_status', 'verified_at', 'verification_revision',
                                                      'updated_at', 'created_at', 'archived_at', 'archived_by_user_id', 'archive_reason'];
                v_decision record;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.revision IS DISTINCT FROM 1 OR NEW.verification_status IS DISTINCT FROM 'unverified'
                       OR NEW.verification_revision IS NOT NULL OR NEW.verified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'hr_credential_revision_guard: a % row is recorded unverified at revision 1', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.revision IS DISTINCT FROM OLD.revision THEN
                    RAISE EXCEPTION 'hr_credential_revision_guard: the revision of a % row is the database''s', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;

                IF (pg_catalog.to_jsonb(NEW) - v_projection) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - v_projection) THEN
                    NEW.revision := OLD.revision + 1;
                    IF NEW.verification_status IS DISTINCT FROM 'unverified' OR NEW.verification_revision IS NOT NULL OR NEW.verified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'hr_credential_revision_guard: an edit to a % row resets its verification', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF (NEW.verification_status, NEW.verified_at, NEW.verification_revision)
                   IS NOT DISTINCT FROM (OLD.verification_status, OLD.verified_at, OLD.verification_revision) THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'employee_qualifications' THEN
                    SELECT d.revision, d.decision, d.decided_at INTO v_decision FROM public.employee_credential_verifications d
                     WHERE d.school_id = NEW.school_id AND d.qualification_id = NEW.id ORDER BY d.sequence DESC LIMIT 1;
                ELSIF TG_TABLE_NAME = 'employee_certifications' THEN
                    SELECT d.revision, d.decision, d.decided_at INTO v_decision FROM public.employee_credential_verifications d
                     WHERE d.school_id = NEW.school_id AND d.certification_id = NEW.id ORDER BY d.sequence DESC LIMIT 1;
                ELSE
                    RAISE EXCEPTION 'hr_credential_revision_guard: % is not a credential table', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.verification_status = 'unverified' THEN
                    IF NEW.verified_at IS NOT NULL OR NEW.verification_revision IS NOT NULL THEN
                        RAISE EXCEPTION 'hr_credential_revision_guard: an unverified % row names no verification', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                    END IF;
                    -- Only an edit resets a decided revision: the projection always states the latest decision on it.
                    IF FOUND AND v_decision.revision = NEW.revision THEN
                        RAISE EXCEPTION 'hr_credential_revision_guard: a decided revision of a % row keeps its decision until an edit', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NOT FOUND OR v_decision.revision IS DISTINCT FROM NEW.revision OR NEW.verification_revision IS DISTINCT FROM NEW.revision
                   OR v_decision.decision IS DISTINCT FROM NEW.verification_status
                   OR NEW.verified_at IS DISTINCT FROM (CASE WHEN v_decision.decision = 'verified' THEN (v_decision.decided_at AT TIME ZONE 'UTC')::timestamp(0) END) THEN
                    RAISE EXCEPTION 'hr_credential_revision_guard: only the latest recorded decision on this revision sets a % row verified or rejected', TG_TABLE_NAME USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_qualifications_revision_guard
                BEFORE INSERT OR UPDATE ON employee_qualifications
                FOR EACH ROW EXECUTE FUNCTION public.hr_credential_revision_guard();
            CREATE TRIGGER trg_employee_certifications_revision_guard
                BEFORE INSERT OR UPDATE ON employee_certifications
                FOR EACH ROW EXECUTE FUNCTION public.hr_credential_revision_guard();

            -- C29-B: a decision names its Employee's live credential at its current revision, once.
            CREATE FUNCTION public.employee_credential_verifications_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_credential record;
                v_latest record;
            BEGIN
                -- The database's clock, never a writer's.
                NEW.decided_at := pg_catalog.clock_timestamp();
                IF NEW.credential_kind = 'qualification' THEN
                    SELECT q.employee_id, q.revision, q.verification_status, q.verification_revision, q.archived_at INTO v_credential
                      FROM public.employee_qualifications q WHERE q.school_id = NEW.school_id AND q.id = NEW.qualification_id FOR UPDATE;
                ELSIF NEW.credential_kind = 'certification' THEN
                    SELECT c.employee_id, c.revision, c.verification_status, c.verification_revision, c.archived_at INTO v_credential
                      FROM public.employee_certifications c WHERE c.school_id = NEW.school_id AND c.id = NEW.certification_id FOR UPDATE;
                ELSE
                    RAISE EXCEPTION 'employee_credential_verifications_guard: a decision names a qualification or a certification' USING ERRCODE = 'check_violation';
                END IF;
                IF NOT FOUND OR v_credential.employee_id IS DISTINCT FROM NEW.employee_id THEN
                    RAISE EXCEPTION 'employee_credential_verifications_guard: a decision names a credential of its Employee' USING ERRCODE = 'check_violation';
                END IF;
                IF v_credential.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'employee_credential_verifications_guard: an archived credential is final evidence' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.revision IS DISTINCT FROM v_credential.revision THEN
                    RAISE EXCEPTION 'employee_credential_verifications_guard: a decision binds the current revision' USING ERRCODE = 'check_violation';
                END IF;
                -- The latest decision, including one inserted earlier by this same statement (visible to a BEFORE ROW
                -- trigger), not only the projection, which the apply trigger updates at the end of the statement.
                IF NEW.credential_kind = 'qualification' THEN
                    SELECT d.revision, d.decision INTO v_latest FROM public.employee_credential_verifications d
                     WHERE d.school_id = NEW.school_id AND d.qualification_id = NEW.qualification_id ORDER BY d.sequence DESC LIMIT 1;
                ELSE
                    SELECT d.revision, d.decision INTO v_latest FROM public.employee_credential_verifications d
                     WHERE d.school_id = NEW.school_id AND d.certification_id = NEW.certification_id ORDER BY d.sequence DESC LIMIT 1;
                END IF;
                IF (v_credential.verification_status = NEW.decision AND v_credential.verification_revision = NEW.revision)
                   OR (v_latest.revision = NEW.revision AND v_latest.decision = NEW.decision) THEN
                    RAISE EXCEPTION 'employee_credential_verifications_guard: this revision already carries this decision' USING ERRCODE = 'check_violation';
                END IF;
                -- The order is the database's, taken under the credential's lock, whatever the writer supplied.
                NEW.sequence := pg_catalog.nextval(pg_catalog.pg_get_serial_sequence('public.employee_credential_verifications', 'sequence'));
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_credential_verifications_guard
                BEFORE INSERT ON employee_credential_verifications
                FOR EACH ROW EXECUTE FUNCTION public.employee_credential_verifications_guard();

            -- The only thing that sets a credential verified or rejected. It cannot run without the decision that fires it.
            CREATE FUNCTION public.employee_credential_verifications_apply() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_verified_at timestamp(0) := CASE WHEN NEW.decision = 'verified' THEN (NEW.decided_at AT TIME ZONE 'UTC')::timestamp(0) END;
            BEGIN
                IF NEW.credential_kind = 'qualification' THEN
                    UPDATE public.employee_qualifications
                       SET verification_status = NEW.decision, verification_revision = NEW.revision, verified_at = v_verified_at
                     WHERE school_id = NEW.school_id AND id = NEW.qualification_id AND revision = NEW.revision;
                ELSE
                    UPDATE public.employee_certifications
                       SET verification_status = NEW.decision, verification_revision = NEW.revision, verified_at = v_verified_at
                     WHERE school_id = NEW.school_id AND id = NEW.certification_id AND revision = NEW.revision;
                END IF;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'employee_credential_verifications_apply: nothing to decide' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER trg_employee_credential_verifications_apply
                AFTER INSERT ON employee_credential_verifications
                FOR EACH ROW EXECUTE FUNCTION public.employee_credential_verifications_apply();

            CREATE TRIGGER trg_employee_credential_verifications_immutable
                BEFORE UPDATE ON employee_credential_verifications
                FOR EACH ROW EXECUTE FUNCTION public.hr_history_row_immutable();

            REVOKE ALL ON FUNCTION public.hr_credential_revision_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_credential_verifications_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_credential_verifications_apply() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP are atomic and see every School.
        DB::statement('ALTER TABLE employee_credential_verifications NO FORCE ROW LEVEL SECURITY');
        if (DB::table('employee_credential_verifications')->exists()) {
            throw new RuntimeException('Refusing to roll back: credential verification decisions are recorded (HR.R1-C29). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employee_credential_verifications_immutable ON employee_credential_verifications;
            DROP TRIGGER trg_employee_credential_verifications_apply ON employee_credential_verifications;
            DROP TRIGGER trg_employee_credential_verifications_guard ON employee_credential_verifications;
            DROP TRIGGER trg_employee_certifications_revision_guard ON employee_certifications;
            DROP TRIGGER trg_employee_qualifications_revision_guard ON employee_qualifications;
            DROP FUNCTION public.employee_credential_verifications_apply();
            DROP FUNCTION public.employee_credential_verifications_guard();
            DROP FUNCTION public.hr_credential_revision_guard();
            SQL);
        TenantRls::disable('employee_credential_verifications');
        Schema::drop('employee_credential_verifications');

        foreach (array_keys(self::CREDENTIALS) as $table) {
            foreach (['verification_revision_check', 'revision_check', 'verification_status_check', 'id_employee_school_unique'] as $constraint) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$constraint}");
            }
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['revision', 'verification_revision']);
            });
        }
    }
};
