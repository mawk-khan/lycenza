<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8D (ADR 0076 §62): the person who SUBMITTED a leave request (the
 * Employee through self-service, or an administrator on their behalf) never
 * approves or rejects it, on either path. The service refuses first
 * (`LeaveRequestService::assertMayDecide`, the shared MakerCheckerPolicy);
 * `leave_decisions_participant_guard` repeats it on the RECORDED decider
 * (`decided_by_user_id`) against the request's recorded
 * `submitted_by_user_id` -- both structural columns, never an actor context.
 * It runs after `trg_leave_decisions_guard` (name order), which derives the
 * Employees for the existing `leave_decisions_no_self_decision` CHECK.
 *
 * Withdrawal and cancellation are not decisions and stay unaffected. Existing
 * rows are not re-checked (an INSERT trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.leave_decisions_refuse_submitter() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.decision IN ('approved', 'rejected') AND EXISTS (
                    SELECT 1 FROM public.leave_requests r
                     WHERE r.id = NEW.leave_request_id AND r.school_id = NEW.school_id
                       AND r.submitted_by_user_id = NEW.decided_by_user_id
                ) THEN
                    RAISE EXCEPTION 'leave_decisions_participant_guard: leave request % is decided by the person who submitted it', NEW.leave_request_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_leave_decisions_participant_guard BEFORE INSERT ON leave_decisions
                FOR EACH ROW EXECUTE FUNCTION public.leave_decisions_refuse_submitter();

            REVOKE ALL ON FUNCTION public.leave_decisions_refuse_submitter() FROM PUBLIC;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_decisions_participant_guard ON leave_decisions;
            DROP FUNCTION IF EXISTS public.leave_decisions_refuse_submitter();
            SQL);
    }
};
