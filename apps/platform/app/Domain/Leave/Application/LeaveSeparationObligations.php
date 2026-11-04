<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HR.12: Leave's answer to a separating employment, from Leave's own rows.
 * It reads only. Nothing is approved, cancelled, rejected or adjusted by a
 * separation: a pending request still needs its ordinary decision, an
 * approved request after the last day keeps its record, and an accrual
 * credited for a month after the last day (R-HR10-1, a backdated end) is
 * flagged for an ordinary adjustment that names it (ADR 0065 §29).
 */
final class LeaveSeparationObligations implements SeparationObligationSource
{
    public function owner(): string
    {
        return 'Leave';
    }

    public function obligations(School $school, SeparationSubject $subject): array
    {
        $pending = DB::table('leave_requests')->where('school_id', $school->id)
            ->where('employment_record_id', $subject->employmentRecordId)->where('status', 'submitted')->count();

        $approvedAfter = DB::table('leave_requests')->where('school_id', $school->id)
            ->where('employment_record_id', $subject->employmentRecordId)->where('status', 'approved')
            ->where('ends_on', '>', $subject->lastDay)->count();

        // A monthly credit is for a whole month the employment must span (HR.10):
        // a credited month starting after the last day was not earned.
        $creditsAfter = DB::table('leave_ledger_entries')->where('school_id', $school->id)
            ->where('employment_record_id', $subject->employmentRecordId)->where('kind', 'accrual')
            ->where('accrual_period', '>', $subject->lastDay)->count();

        return [
            new SeparationObligation('leave_pending_requests', 'Leave', 'Leave requests awaiting a decision',
                $pending > 0 ? SeparationObligation::OPEN : SeparationObligation::CLEAR, $pending,
                $pending > 0 ? 'Decide, or let the requester withdraw, each pending request through Leave. A separation never decides them.' : null),
            new SeparationObligation('leave_approved_after_last_day', 'Leave', 'Approved leave after the last day',
                $approvedAfter > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $approvedAfter,
                $approvedAfter > 0 ? 'Approved leave runs past the last day. Review it through Leave; it is never cancelled automatically.' : null),
            new SeparationObligation('leave_accrual_after_last_day', 'Leave', 'Monthly leave credited after the last day',
                $creditsAfter > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $creditsAfter,
                $creditsAfter > 0 ? 'Monthly credits exist for months after the last day (R-HR10-1). Correct each with a leave adjustment that names it; nothing is reversed automatically.' : null),
        ];
    }
}
