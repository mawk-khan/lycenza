<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRX.4 (ADR 0065 §25.4): a requester's own withdrawal or cancellation is
 * recorded truthfully with decision path `self`.
 *
 * The database allows `self` only for `withdrawn` / `cancelled`, and only when
 * the decider's Employee IS the requester's Employee. Both are derived by
 * `leave_decisions_guard()` (from the request and from the deciding User's
 * Employee link), never by the caller, so raw SQL cannot forge a `self`
 * decision for someone else's request. Approving or rejecting on any path
 * stays refused for one's own request (`leave_decisions_no_self_decision`).
 *
 * No new table and no new column: one CHECK constraint is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leave_decisions DROP CONSTRAINT leave_decisions_shape_check');
        DB::statement('ALTER TABLE leave_decisions ADD CONSTRAINT leave_decisions_shape_check CHECK ('
            ."decision IN ('approved', 'rejected', 'withdrawn', 'cancelled') AND path IN ('manager', 'administrative', 'self') "
            ."AND (path <> 'manager' OR (decision IN ('approved', 'rejected') AND decider_employee_id IS NOT NULL)) "
            ."AND (path <> 'self' OR (decision IN ('withdrawn', 'cancelled') AND decider_employee_id IS NOT NULL AND decider_employee_id = requester_employee_id)) "
            ."AND ((decision = 'approved' AND reason_code IS NULL) "
            ."  OR (decision = 'rejected' AND reason_code IN ('staffing_need', 'policy_not_met', 'duplicate_request', 'entered_in_error', 'other')) "
            ."  OR (decision IN ('withdrawn', 'cancelled') AND reason_code IN ('plans_changed', 'entered_in_error', 'administrative_correction', 'other'))))");
    }

    public function down(): void
    {
        if (DB::table('leave_decisions')->where('path', 'self')->exists()) {
            throw new RuntimeException('Refusing to roll back: leave_decisions holds self-service decisions (HRX.4) that the HRX.2 constraint cannot represent.');
        }

        DB::statement('ALTER TABLE leave_decisions DROP CONSTRAINT leave_decisions_shape_check');
        DB::statement('ALTER TABLE leave_decisions ADD CONSTRAINT leave_decisions_shape_check CHECK ('
            ."decision IN ('approved', 'rejected', 'withdrawn', 'cancelled') AND path IN ('manager', 'administrative') "
            ."AND (path <> 'manager' OR (decision IN ('approved', 'rejected') AND decider_employee_id IS NOT NULL)) "
            ."AND ((decision = 'approved' AND reason_code IS NULL) "
            ."  OR (decision = 'rejected' AND reason_code IN ('staffing_need', 'policy_not_met', 'duplicate_request', 'entered_in_error', 'other')) "
            ."  OR (decision IN ('withdrawn', 'cancelled') AND reason_code IN ('plans_changed', 'entered_in_error', 'administrative_correction', 'other'))))");
    }
};
