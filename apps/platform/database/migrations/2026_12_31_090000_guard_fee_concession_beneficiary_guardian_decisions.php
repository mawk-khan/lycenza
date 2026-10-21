<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8A-R1 (ADR 0076 §59, closes ORG.8A G-7), Rule A's database backstop: a
 * fee concession's terminal decision (approved or rejected) is refused, for
 * every role, when its RECORDED decider is a Guardian of the concession's
 * Student -- the decider's membership in the School has ever been
 * account-linked (`student_guardian_account_links`, active or revoked) to a
 * Guardian with a CURRENT `student_guardian_relationships` row to that
 * Student. The same recorded-actor pattern as `fee_concessions_sod_check`
 * (which it complements, unchanged).
 *
 * Structural ids only. SECURITY INVOKER with a pinned search_path: the
 * decision UPDATE already runs under its own School's RLS context (the
 * concession row is otherwise invisible), so the reads see that School's
 * links and relationships only. A Fees-owned trigger reading Identity's and
 * Guardians' tables is the established cross-boundary structural pattern
 * (e.g. the Payments guard on `charges`); no application dependency changes.
 *
 * Rule B (nobody administers their own Guardian relationships) is enforced in
 * `StudentGuardianRelationshipService`: a relationship row records no actor and
 * the database has no trusted actor context, so a database rule there would
 * need a spoofable actor parameter (ADR 0076 §59.6).
 *
 * Rolling back drops only the trigger and function; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.fees_concession_decider_is_beneficiary_guardian(p_school uuid, p_decider uuid, p_student uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (
                    SELECT 1
                      FROM public.school_memberships m
                      JOIN public.student_guardian_account_links l
                        ON l.school_membership_id = m.id AND l.school_id = m.school_id AND l.guardian_id IS NOT NULL
                      JOIN public.student_guardian_relationships r
                        ON r.guardian_id = l.guardian_id AND r.school_id = l.school_id
                     WHERE m.school_id = p_school AND m.user_id = p_decider AND r.student_id = p_student
                )
            $$;

            CREATE FUNCTION public.fees_refuse_beneficiary_guardian_decision() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF public.fees_concession_decider_is_beneficiary_guardian(NEW.school_id, NEW.decided_by_user_id, NEW.student_id) THEN
                    RAISE EXCEPTION 'fee_concessions_beneficiary_guardian_guard: fee concession % must be decided by someone else', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fee_concessions_beneficiary_guardian_guard
                BEFORE UPDATE OF status, decided_by_user_id ON fee_concessions
                FOR EACH ROW
                WHEN (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
                EXECUTE FUNCTION public.fees_refuse_beneficiary_guardian_decision();

            REVOKE ALL ON FUNCTION public.fees_concession_decider_is_beneficiary_guardian(uuid, uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.fees_refuse_beneficiary_guardian_decision() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.fees_concession_decider_is_beneficiary_guardian(uuid, uuid, uuid) TO school_os_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fee_concessions_beneficiary_guardian_guard ON fee_concessions;
            DROP FUNCTION IF EXISTS public.fees_refuse_beneficiary_guardian_decision();
            DROP FUNCTION IF EXISTS public.fees_concession_decider_is_beneficiary_guardian(uuid, uuid, uuid);
            SQL);
    }
};
