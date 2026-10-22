<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.8C (ADR 0076 §61): a human Payment correction (reallocation, reversal,
 * recorded refund) is no longer executed directly. It is requested here and
 * executed, on approval, by `PaymentCorrectionService` in the same transaction.
 *
 * `payment_correction_requests` is Payments-owned and typed: the exact terms
 * the correction service needs (the same shape `payment_corrections` holds),
 * the requester, the reason, and `prior_correction_count` -- how many
 * corrections the Payment had when it was requested (a Payment's allocation
 * state changes only through corrections, so this is its generation).
 *
 * Database rules, for every role:
 *   - a request is inserted pending; its terms, requester, Payment, generation
 *     and key never change; pending -> approved | rejected | withdrawn only,
 *     and a decision is final (`payment_correction_requests_guard`);
 *   - the requester never decides (`payment_correction_requests_sod_check`);
 *   - an approval names the correction it executed -- same Payment, kind,
 *     amount, and the request id as its idempotency key -- and that
 *     correction is the Payment's (generation + 1)th: anything executed in
 *     between made the request stale (`payment_correction_requests_approval_guard`);
 *   - a decision (approve or reject) is refused when the RECORDED decider is
 *     a Guardian of any Student the Payment or the request touches (any
 *     account link, active or revoked, to a Guardian currently related to
 *     that Student), reusing ORG.8A-R1's structural predicate
 *     (`payment_correction_requests_beneficiary_guardian_guard`).
 * SECURITY INVOKER, pinned search_path; decisions run under their School's
 * RLS context. No runtime DELETE; RLS forced. Only the requester withdraws
 * (`payment_correction_requests_withdrawer_check` on the recorded
 * withdrawer). Request rows leave with their Payment or Charges (cascade)
 * under Finance retention -- an unexecuted request is history of that
 * Payment/Charge only; an approved request keeps its correction (RESTRICT),
 * so a corrected Payment is never expired.
 *
 * Rolling back drops the table and its functions (refused while it holds rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_correction_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('payment_id');
            $table->string('kind', 16);
            $table->uuid('charge_id')->nullable();
            $table->uuid('to_charge_id')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->uuid('settlement_ledger_account_id')->nullable();
            $table->string('refund_method', 32)->nullable();
            $table->string('refund_reference', 64)->nullable();
            $table->date('refunded_on')->nullable();
            $table->text('reason');
            $table->unsignedInteger('prior_correction_count');
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            // Who withdrew: only ever the requester (CHECK below).
            $table->foreignUuid('withdrawn_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('payment_correction_id')->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'idempotency_key'], 'payment_correction_requests_idempotency_unique');
            $table->unique(['payment_correction_id'], 'payment_correction_requests_one_per_correction');
            $table->index(['school_id', 'status', 'created_at'], 'payment_correction_requests_queue_idx');
            $table->index(['school_id', 'payment_id'], 'payment_correction_requests_payment_idx');

            $table->foreign(['payment_id', 'school_id'], 'payment_correction_requests_payment_fk')
                ->references(['id', 'school_id'])->on('payments')->cascadeOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'payment_correction_requests_charge_fk')
                ->references(['id', 'school_id'])->on('charges')->cascadeOnDelete();
            $table->foreign(['to_charge_id', 'school_id'], 'payment_correction_requests_to_charge_fk')
                ->references(['id', 'school_id'])->on('charges')->cascadeOnDelete();
            $table->foreign(['settlement_ledger_account_id', 'school_id', 'currency'], 'payment_correction_requests_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign(['payment_correction_id', 'school_id'], 'payment_correction_requests_correction_fk')
                ->references(['id', 'school_id'])->on('payment_corrections')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_correction_requests
                ADD CONSTRAINT payment_correction_requests_currency_inr_only_check CHECK (currency = 'INR'),
                ADD CONSTRAINT payment_correction_requests_reason_check CHECK (char_length(btrim(reason)) BETWEEN 5 AND 500),
                ADD CONSTRAINT payment_correction_requests_sod_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id),
                ADD CONSTRAINT payment_correction_requests_withdrawer_check CHECK (withdrawn_by_user_id IS NULL OR withdrawn_by_user_id = requested_by_user_id),
                ADD CONSTRAINT payment_correction_requests_kind_shape_check CHECK (
                    (kind = 'reallocation' AND charge_id IS NOT NULL AND to_charge_id IS NOT NULL AND charge_id <> to_charge_id
                        AND amount > 0 AND settlement_ledger_account_id IS NULL AND refund_method IS NULL AND refund_reference IS NULL AND refunded_on IS NULL)
                    OR (kind = 'reversal' AND charge_id IS NULL AND to_charge_id IS NULL AND amount IS NULL
                        AND settlement_ledger_account_id IS NULL AND refund_method IS NULL AND refund_reference IS NULL AND refunded_on IS NULL)
                    OR (kind = 'refund' AND charge_id IS NOT NULL AND to_charge_id IS NULL AND amount > 0
                        AND settlement_ledger_account_id IS NOT NULL AND refund_method IN ('cash', 'bank_transfer', 'cheque') AND refunded_on IS NOT NULL)
                ),
                ADD CONSTRAINT payment_correction_requests_status_shape_check CHECK (
                    (status = 'pending' AND decided_by_user_id IS NULL AND decided_at IS NULL AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL AND payment_correction_id IS NULL)
                    OR (status = 'approved' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL AND payment_correction_id IS NOT NULL)
                    OR (status = 'rejected' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL AND payment_correction_id IS NULL)
                    OR (status = 'withdrawn' AND decided_by_user_id IS NULL AND decided_at IS NULL AND withdrawn_at IS NOT NULL AND withdrawn_by_user_id IS NOT NULL AND payment_correction_id IS NULL)
                );

            CREATE FUNCTION public.payment_correction_requests_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' THEN
                        RAISE EXCEPTION 'payment_correction_requests: a correction is always requested as pending' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF (NEW.id, NEW.school_id, NEW.payment_id, NEW.kind, NEW.charge_id, NEW.to_charge_id, NEW.amount, NEW.currency,
                    NEW.settlement_ledger_account_id, NEW.refund_method, NEW.refund_reference, NEW.refunded_on, NEW.reason,
                    NEW.prior_correction_count, NEW.requested_by_user_id, NEW.idempotency_key, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.school_id, OLD.payment_id, OLD.kind, OLD.charge_id, OLD.to_charge_id, OLD.amount, OLD.currency,
                    OLD.settlement_ledger_account_id, OLD.refund_method, OLD.refund_reference, OLD.refunded_on, OLD.reason,
                    OLD.prior_correction_count, OLD.requested_by_user_id, OLD.idempotency_key, OLD.created_at) THEN
                    RAISE EXCEPTION 'payment_correction_requests: request % is immutable', OLD.id USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'pending' AND ROW(NEW.status, NEW.decided_by_user_id, NEW.decided_at, NEW.withdrawn_at, NEW.withdrawn_by_user_id, NEW.payment_correction_id)
                   IS DISTINCT FROM ROW(OLD.status, OLD.decided_by_user_id, OLD.decided_at, OLD.withdrawn_at, OLD.withdrawn_by_user_id, OLD.payment_correction_id) THEN
                    RAISE EXCEPTION 'payment_correction_requests: request % is % and final', OLD.id, OLD.status USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_correction_requests_guard BEFORE INSERT OR UPDATE ON payment_correction_requests
                FOR EACH ROW EXECUTE FUNCTION public.payment_correction_requests_guard();

            CREATE FUNCTION public.payment_correction_requests_bind_approval() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.payment_corrections c
                     WHERE c.id = NEW.payment_correction_id AND c.school_id = NEW.school_id AND c.payment_id = NEW.payment_id
                       AND c.kind = NEW.kind AND c.idempotency_key = NEW.id
                       AND (NEW.amount IS NULL OR c.amount = NEW.amount)
                ) THEN
                    RAISE EXCEPTION 'payment_correction_requests_approval_guard: request % names no correction it executed', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF (SELECT count(*) FROM public.payment_corrections c WHERE c.school_id = NEW.school_id AND c.payment_id = NEW.payment_id) <> NEW.prior_correction_count + 1 THEN
                    RAISE EXCEPTION 'payment_correction_requests_approval_guard: request % is stale -- the payment changed after it was requested', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_correction_requests_approval_guard
                BEFORE UPDATE OF status, payment_correction_id ON payment_correction_requests
                FOR EACH ROW WHEN (OLD.status = 'pending' AND NEW.status = 'approved')
                EXECUTE FUNCTION public.payment_correction_requests_bind_approval();

            -- Every Student whose Charges the Payment (allocations, earlier corrections) or the request touches.
            CREATE FUNCTION public.payment_correction_request_student_ids(p_school uuid, p_request uuid) RETURNS SETOF uuid
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT DISTINCT ch.student_id
                  FROM public.payment_correction_requests r
                  JOIN public.charges ch ON ch.school_id = r.school_id AND ch.id IN (
                        SELECT a.charge_id FROM public.payment_allocations a WHERE a.school_id = r.school_id AND a.payment_id = r.payment_id
                        UNION SELECT l.charge_id FROM public.payment_correction_lines l WHERE l.school_id = r.school_id AND l.payment_id = r.payment_id
                        UNION SELECT r.charge_id WHERE r.charge_id IS NOT NULL
                        UNION SELECT r.to_charge_id WHERE r.to_charge_id IS NOT NULL)
                 WHERE r.school_id = p_school AND r.id = p_request
            $$;

            CREATE FUNCTION public.payment_correction_requests_refuse_beneficiary_guardian() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.payment_correction_request_student_ids(NEW.school_id, NEW.id) s(student_id)
                     WHERE public.fees_concession_decider_is_beneficiary_guardian(NEW.school_id, NEW.decided_by_user_id, s.student_id)
                ) THEN
                    RAISE EXCEPTION 'payment_correction_requests_beneficiary_guardian_guard: payment correction request % must be decided by someone else', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_correction_requests_beneficiary_guardian_guard
                BEFORE UPDATE OF status, decided_by_user_id ON payment_correction_requests
                FOR EACH ROW WHEN (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
                EXECUTE FUNCTION public.payment_correction_requests_refuse_beneficiary_guardian();

            REVOKE ALL ON FUNCTION public.payment_correction_requests_guard() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payment_correction_requests_bind_approval() FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payment_correction_request_student_ids(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payment_correction_requests_refuse_beneficiary_guardian() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payment_correction_request_student_ids(uuid, uuid) TO school_os_app;
            SQL);

        TenantRls::enable('payment_correction_requests');
        TenantRls::revokeDelete('payment_correction_requests');
    }

    public function down(): void
    {
        if (DB::table('payment_correction_requests')->exists()) {
            throw new RuntimeException('Refusing to roll back: payment_correction_requests holds approval history (ORG.8C).');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_correction_requests_beneficiary_guardian_guard ON payment_correction_requests;
            DROP TRIGGER IF EXISTS payment_correction_requests_approval_guard ON payment_correction_requests;
            DROP TRIGGER IF EXISTS payment_correction_requests_guard ON payment_correction_requests;
            DROP FUNCTION IF EXISTS public.payment_correction_requests_refuse_beneficiary_guardian();
            DROP FUNCTION IF EXISTS public.payment_correction_request_student_ids(uuid, uuid);
            DROP FUNCTION IF EXISTS public.payment_correction_requests_bind_approval();
            DROP FUNCTION IF EXISTS public.payment_correction_requests_guard();
            SQL);
        TenantRls::disable('payment_correction_requests');
        Schema::dropIfExists('payment_correction_requests');
    }
};
