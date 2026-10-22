<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8G (ADR 0076 §65, closes G-8 / OD-8.3): a Charge's cancellation records
 * WHY (`cancellation_reason_code`, the closed `ChargeCancellationReason`
 * list) and WHO (`cancelled_by_user_id`, history RESTRICT, rule 97).
 *
 * `charges_cancellation_evidence` (BEFORE UPDATE):
 * - a new cancellation names its reason code and its actor, the actor is a
 *   member of the School, and the actor is not a Guardian of the Charge's
 *   Student (`fees_concession_decider_is_beneficiary_guardian`, the
 *   ORG.8A-R1 predicate: any account link, active or revoked, to a Guardian
 *   currently related to the Student) -- `charges_cancellation_beneficiary_guardian_guard`;
 *   the actor's membership is `active` (structural; their capability is the
 *   service's current-authority check);
 * - the reason and actor are immutable once recorded, and never set on a
 *   live Charge (`charges_cancellation_shape_check`).
 *
 * The database proves the recorded actor is a School member who is not a
 * linked Guardian; it cannot prove that person really acted (the
 * actor-context boundary, §59.6) -- the service supplies the real actor
 * under the Charge lock. Cancellations recorded before ORG.8G keep NULLs
 * (development data, never rewritten). Rollback refuses while any
 * cancellation records its reason (provenance would be lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE charges ADD COLUMN cancellation_reason_code varchar(32);
            ALTER TABLE charges ADD COLUMN cancelled_by_user_id uuid REFERENCES users(id) ON DELETE RESTRICT;
            ALTER TABLE charges ADD CONSTRAINT charges_cancellation_reason_code_check CHECK (
                cancellation_reason_code IS NULL OR cancellation_reason_code IN (
                    'charged_in_error', 'duplicate_charge', 'wrong_student_or_amount', 'service_not_provided', 'other',
                    'assessment_error', 'duplicate_assessment', 'wrong_placement',
                    'late_fee_in_error', 'payment_was_on_time', 'source_voided'
                )
            );
            ALTER TABLE charges ADD CONSTRAINT charges_cancellation_shape_check CHECK (
                cancelled_at IS NOT NULL OR (cancellation_reason_code IS NULL AND cancelled_by_user_id IS NULL)
            );

            CREATE FUNCTION public.charges_require_cancellation_evidence() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF OLD.cancelled_at IS NOT NULL THEN
                    IF NEW.cancellation_reason_code IS DISTINCT FROM OLD.cancellation_reason_code
                        OR NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id THEN
                        RAISE EXCEPTION 'charges_cancellation_evidence: charge % cancellation reason and actor are final', OLD.id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.cancelled_at IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.cancellation_reason_code IS NULL OR NEW.cancelled_by_user_id IS NULL THEN
                    RAISE EXCEPTION 'charges_cancellation_evidence: charge % is cancelled without its reason and actor', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.school_memberships m WHERE m.school_id = NEW.school_id AND m.user_id = NEW.cancelled_by_user_id AND m.status = 'active'
                ) THEN
                    RAISE EXCEPTION 'charges_cancellation_evidence: charge % is cancelled by someone who is not an active member of its School', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF public.fees_concession_decider_is_beneficiary_guardian(NEW.school_id, NEW.cancelled_by_user_id, NEW.student_id) THEN
                    RAISE EXCEPTION 'charges_cancellation_beneficiary_guardian_guard: charge % must be cancelled by someone else', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER charges_cancellation_evidence BEFORE UPDATE ON charges
                FOR EACH ROW EXECUTE FUNCTION public.charges_require_cancellation_evidence();

            REVOKE ALL ON FUNCTION public.charges_require_cancellation_evidence() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('charges')->whereNotNull('cancellation_reason_code')->exists()) {
            throw new RuntimeException('Refusing to roll back: charges records ORG.8G cancellation reasons.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS charges_cancellation_evidence ON charges;
            DROP FUNCTION IF EXISTS public.charges_require_cancellation_evidence();
            ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_cancellation_shape_check;
            ALTER TABLE charges DROP CONSTRAINT IF EXISTS charges_cancellation_reason_code_check;
            ALTER TABLE charges DROP COLUMN IF EXISTS cancelled_by_user_id;
            ALTER TABLE charges DROP COLUMN IF EXISTS cancellation_reason_code;
            SQL);
    }
};
