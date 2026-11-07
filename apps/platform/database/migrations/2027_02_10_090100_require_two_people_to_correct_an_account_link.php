<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.R1-C8 (OD-HR-25 as amended by the owner, 2026-11-06): correcting an account link
 * takes two linked staff, and nothing is effective until the second one approves.
 *
 * C7 let one person with a fresh code mark a link erroneous. Reproduced at c4ea0307: a
 * never-linked second HR account cleared its owner's link, and that person then
 * approved their own pay change. The system holds no record of natural persons and
 * cannot tell two accounts are one human. The strongest evidence it has is a link to
 * an Employee, so that is what qualifies someone here.
 *
 * - A requester and a reviewer each need whole-School `hr.employees.manage`, a current
 *   link to a different active Employee, and no link of any kind to the affected one.
 *   They differ, and share no uncorrected link to any Employee. A never-linked account
 *   takes no part at all. Two people colluding, or a fabricated Employee, stay possible:
 *   this is separation of duties, never proof of two humans.
 * - `employee_account_link_void()` is gone. The only thing that voids a link is the
 *   trigger on an approval here, so a decision and its effect are one statement, and
 *   nothing voids a link without an approved row.
 * - A request changes nothing. Until approval the link keeps counting.
 * - An approval binds the link's history (`evidence_version`). A relink or another
 *   correction makes it stale, and it can then only be refused.
 * - Who acted stays an application boundary (rule 102): the database checks the named
 *   decider qualifies, and cannot prove who pressed the button or that an MFA code was
 *   given. The fresh code is enforced in the service.
 *
 * Retention: `hr_evidence`, leaving with the Employee. No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_account_link_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('account_link_id');
            $table->string('reason', 20);
            $table->string('evidence_version', 64);
            $table->string('status', 10)->default('requested');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at', 6)->useCurrent();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', 6)->nullable();

            $table->unique(['id', 'school_id'], 'employee_account_link_corrections_id_school_unique');
            $table->index(['school_id', 'employee_id'], 'employee_account_link_corrections_employee_idx');
            $table->index(['account_link_id'], 'employee_account_link_corrections_link_idx');
            $table->index(['requested_by_user_id'], 'employee_account_link_corrections_requested_by_idx');
            $table->index(['decided_by_user_id'], 'employee_account_link_corrections_decided_by_idx');

            $table->foreign(['employee_id', 'school_id'], 'employee_account_link_corrections_employee_fk')
                ->references(['id', 'school_id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['account_link_id', 'school_id'], 'employee_account_link_corrections_link_fk')
                ->references(['id', 'school_id'])->on('employee_account_links')->cascadeOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX employee_account_link_corrections_one_open ON employee_account_link_corrections (school_id, account_link_id) WHERE status = 'requested'");
        DB::statement("ALTER TABLE employee_account_link_corrections ADD CONSTRAINT employee_account_link_corrections_reason_check CHECK (reason IN ('wrong_person', 'setup_error'))");
        DB::statement("ALTER TABLE employee_account_link_corrections ADD CONSTRAINT employee_account_link_corrections_status_check CHECK (
            (status = 'requested' AND decided_by_user_id IS NULL AND decided_at IS NULL)
            OR (status IN ('approved', 'refused') AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement('ALTER TABLE employee_account_link_corrections ADD CONSTRAINT employee_account_link_corrections_sod_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id)');

        TenantRls::enable('employee_account_link_corrections');
        DB::statement('REVOKE DELETE, TRUNCATE ON employee_account_link_corrections FROM school_os_app');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid);

            -- What an approval binds: which links this Employee has, and which stand. Ending one changes nothing.
            CREATE FUNCTION public.employee_account_link_correction_version(p_school uuid, p_link uuid) RETURNS text
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT encode(sha256(convert_to(p_link::text || '|' || string_agg(l.id::text || ':' || (l.voided_at IS NULL)::text, ',' ORDER BY l.id), 'UTF8')), 'hex')
                  FROM public.employee_account_links l
                 WHERE l.school_id = p_school
                   AND l.employee_id = (SELECT t.employee_id FROM public.employee_account_links t WHERE t.school_id = p_school AND t.id = p_link)
            $$;

            -- Whole-School HR authority, a current link to another active Employee, and no link of any kind to this one.
            CREATE FUNCTION public.employee_account_link_correction_qualified(p_school uuid, p_employee uuid, p_user uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (
                           SELECT 1 FROM public.school_memberships m
                             JOIN public.users u ON u.id = m.user_id AND NOT u.is_disabled
                             JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL AND a.campus_id IS NULL
                             JOIN public.role_capabilities rc ON rc.role_id = a.role_id AND rc.capability_key = 'hr.employees.manage'
                            WHERE m.school_id = p_school AND m.user_id = p_user AND m.status = 'active')
                   AND EXISTS (
                           SELECT 1 FROM public.employees e
                             JOIN public.employment_records r ON r.school_id = e.school_id AND r.employee_id = e.id
                            WHERE e.school_id = p_school AND e.user_id = p_user AND e.id <> p_employee AND e.record_status = 'active'
                              AND r.status IN ('active', 'notice_period'))
                   AND NOT EXISTS (
                           SELECT 1 FROM public.employee_account_links l
                            WHERE l.school_id = p_school AND l.employee_id = p_employee AND l.user_id = p_user)
            $$;

            CREATE FUNCTION public.employee_account_link_corrections_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_link public.employee_account_links%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status IS DISTINCT FROM 'requested' THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: a correction is born requested' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT * INTO v_link FROM public.employee_account_links WHERE school_id = NEW.school_id AND id = NEW.account_link_id;
                    IF NOT FOUND OR v_link.employee_id IS DISTINCT FROM NEW.employee_id OR v_link.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: a correction names an uncorrected link of its Employee' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT public.employee_account_link_correction_qualified(NEW.school_id, NEW.employee_id, NEW.requested_by_user_id) THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: the requester is linked staff, independent of this Employee' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.evidence_version IS DISTINCT FROM public.employee_account_link_correction_version(NEW.school_id, NEW.account_link_id) THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: a correction binds the current history' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status IS DISTINCT FROM 'requested' THEN
                    RAISE EXCEPTION 'employee_account_link_corrections_guard: a decision is final' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.id, NEW.school_id, NEW.employee_id, NEW.account_link_id, NEW.reason, NEW.evidence_version, NEW.requested_by_user_id, NEW.requested_at)
                   IS DISTINCT FROM (OLD.id, OLD.school_id, OLD.employee_id, OLD.account_link_id, OLD.reason, OLD.evidence_version, OLD.requested_by_user_id, OLD.requested_at) THEN
                    RAISE EXCEPTION 'employee_account_link_corrections_guard: the terms of a request are frozen' USING ERRCODE = 'check_violation';
                END IF;
                -- A decision changes what Payroll reads, so it is ordered against Payroll decisions like any link change.
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('payroll.compensation:' || NEW.school_id::text, 0));

                IF NOT public.employee_account_link_correction_qualified(NEW.school_id, NEW.employee_id, NEW.decided_by_user_id) THEN
                    RAISE EXCEPTION 'employee_account_link_corrections_guard: the reviewer is linked staff, independent of this Employee' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (SELECT 1 FROM public.employee_account_links a
                             JOIN public.employee_account_links b ON b.school_id = a.school_id AND b.employee_id = a.employee_id
                            WHERE a.school_id = NEW.school_id AND a.user_id = NEW.requested_by_user_id AND b.user_id = NEW.decided_by_user_id
                              AND a.voided_at IS NULL AND b.voided_at IS NULL) THEN
                    RAISE EXCEPTION 'employee_account_link_corrections_guard: the requester and reviewer are known to be one person' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'approved' THEN
                    IF NEW.evidence_version IS DISTINCT FROM public.employee_account_link_correction_version(NEW.school_id, NEW.account_link_id) THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: an approval binds the current history' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM public.employee_account_links l WHERE l.school_id = NEW.school_id AND l.id = NEW.account_link_id AND l.ended_at IS NOT NULL AND l.voided_at IS NULL) THEN
                        RAISE EXCEPTION 'employee_account_link_corrections_guard: an approval needs an ended, uncorrected link' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_employee_account_link_corrections_guard
                BEFORE INSERT OR UPDATE ON employee_account_link_corrections
                FOR EACH ROW EXECUTE FUNCTION public.employee_account_link_corrections_guard();

            -- The only thing that voids a link. It cannot run without the approval that fires it.
            CREATE FUNCTION public.employee_account_link_corrections_apply() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                UPDATE public.employee_account_links
                   SET voided_at = NEW.decided_at, voided_by_user_id = NEW.decided_by_user_id, void_reason = NEW.reason
                 WHERE school_id = NEW.school_id AND id = NEW.account_link_id AND ended_at IS NOT NULL AND voided_at IS NULL;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'employee_account_link_corrections_apply: nothing to correct' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER trg_employee_account_link_corrections_apply
                AFTER UPDATE ON employee_account_link_corrections
                FOR EACH ROW WHEN (NEW.status = 'approved' AND OLD.status = 'requested')
                EXECUTE FUNCTION public.employee_account_link_corrections_apply();

            REVOKE ALL ON FUNCTION public.employee_account_link_correction_version(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_account_link_correction_qualified(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_account_link_corrections_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.employee_account_link_corrections_apply() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_correction_version(uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_correction_qualified(uuid, uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_corrections_guard() TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check and the DROP are atomic and see every School.
        DB::statement('ALTER TABLE employee_account_link_corrections NO FORCE ROW LEVEL SECURITY');
        if (DB::table('employee_account_link_corrections')->exists()) {
            throw new RuntimeException('Refusing to roll back: account link correction requests are recorded (HR.R1-C8). Fix forward.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER trg_employee_account_link_corrections_apply ON employee_account_link_corrections;
            DROP TRIGGER trg_employee_account_link_corrections_guard ON employee_account_link_corrections;
            DROP FUNCTION public.employee_account_link_corrections_apply();
            DROP FUNCTION public.employee_account_link_corrections_guard();
            DROP FUNCTION public.employee_account_link_correction_qualified(uuid, uuid, uuid);
            DROP FUNCTION public.employee_account_link_correction_version(uuid, uuid);

            CREATE FUNCTION public.employee_account_link_void(p_school uuid, p_link uuid, p_reason text, p_actor uuid) RETURNS void
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_employee uuid;
            BEGIN
                IF p_school::text IS DISTINCT FROM current_setting('app.current_school_id', true) THEN
                    RAISE EXCEPTION 'employee_account_link_void: not this School' USING ERRCODE = 'insufficient_privilege';
                END IF;
                -- A correction changes what a decision reads, so it is ordered against decisions like any link change.
                PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('payroll.compensation:' || p_school::text, 0));
                -- Who acted stays an application boundary, but a forged call must at least name someone who could have.
                IF NOT EXISTS (
                    SELECT 1 FROM public.school_memberships m
                      JOIN public.users u ON u.id = m.user_id AND NOT u.is_disabled
                      JOIN public.membership_role_assignments a ON a.school_membership_id = m.id AND a.revoked_at IS NULL AND a.campus_id IS NULL
                      JOIN public.role_capabilities rc ON rc.role_id = a.role_id AND rc.capability_key = 'hr.employees.manage'
                     WHERE m.school_id = p_school AND m.user_id = p_actor AND m.status = 'active'
                ) THEN
                    RAISE EXCEPTION 'employee_account_link_void: the actor holds no whole-School HR authority here' USING ERRCODE = 'insufficient_privilege';
                END IF;
                SELECT employee_id INTO v_employee FROM public.employee_account_links WHERE school_id = p_school AND id = p_link;
                IF EXISTS (SELECT 1 FROM public.employee_account_links l
                            WHERE l.school_id = p_school AND l.employee_id = v_employee AND l.user_id = p_actor) THEN
                    RAISE EXCEPTION 'employee_account_link_void: nobody corrects a link of an Employee they are linked to' USING ERRCODE = 'check_violation';
                END IF;
                UPDATE public.employee_account_links SET voided_at = clock_timestamp(), voided_by_user_id = p_actor, void_reason = p_reason
                 WHERE school_id = p_school AND id = p_link AND ended_at IS NOT NULL AND voided_at IS NULL AND user_id IS DISTINCT FROM p_actor;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'employee_account_link_void: only an ended, uncorrected link of someone else' USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            REVOKE ALL ON FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.employee_account_link_void(uuid, uuid, text, uuid) TO school_os_app;
            SQL);

        TenantRls::disable('employee_account_link_corrections');
        Schema::dropIfExists('employee_account_link_corrections');
    }
};
