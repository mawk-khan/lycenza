<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
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

        // A monthly credit is for a WHOLE month the employment must span (HR.10, ADR 0065 §29; no proration,
        // OD-HR-17). HR.R1-C2 (LVA-2): a credited month is unearned when the month does not END within the
        // employment -- the month containing a mid-month last day included, not only months starting after it.
        // The posting time never decides (an accrual is identified by its month). A credit stops being outstanding
        // only once the adjustments correcting it net to a debit covering its units (`source_id`, the R-HR10-1
        // correction). HR.R1-C9: they net through the ledger's one sign convention, across the whole chain, because a
        // correction names the entry it corrects: a credit undoing the earlier debit names that debit, not the
        // accrual. Only provenance counts: an unrelated debit, however equal, names nothing here. The database
        // keeps a named entry inside its account, and adjustments are never edited, so the chain only grows.
        $signed = LeaveLedgerEntry::signedUnitsSql('c');
        $creditsAfter = DB::table('leave_ledger_entries as l')->where('l.school_id', $school->id)
            ->where('l.employment_record_id', $subject->employmentRecordId)->where('l.kind', 'accrual')
            ->whereRaw("(l.accrual_period + interval '1 month' - interval '1 day')::date > ?::date", [$subject->lastDay])
            ->whereRaw("COALESCE((WITH RECURSIVE correcting(id, signed) AS (
                    SELECT c.id, {$signed} FROM leave_ledger_entries c
                     WHERE c.school_id = l.school_id AND c.employment_record_id = l.employment_record_id AND c.kind = 'adjustment' AND c.source_id = l.id
                    UNION
                    SELECT c.id, {$signed} FROM correcting p JOIN leave_ledger_entries c ON c.source_id = p.id
                     WHERE c.school_id = l.school_id AND c.employment_record_id = l.employment_record_id AND c.kind = 'adjustment'
                ) SELECT sum(signed) FROM correcting), 0) > -l.units")
            ->count();

        return [
            new SeparationObligation('leave_pending_requests', 'Leave', 'Leave requests awaiting a decision',
                $pending > 0 ? SeparationObligation::OPEN : SeparationObligation::CLEAR, $pending,
                $pending > 0 ? 'Decide, or let the requester withdraw, each pending request through Leave. A separation never decides them.' : null),
            new SeparationObligation('leave_approved_after_last_day', 'Leave', 'Approved leave after the last day',
                $approvedAfter > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $approvedAfter,
                $approvedAfter > 0 ? 'Approved leave runs past the last day. Review it through Leave; it is never cancelled automatically.' : null),
            new SeparationObligation('leave_accrual_after_last_day', 'Leave', 'Monthly leave credited for a month not fully worked',
                $creditsAfter > 0 ? SeparationObligation::ATTENTION : SeparationObligation::CLEAR, $creditsAfter,
                $creditsAfter > 0 ? 'Monthly credits exist for months the employment did not fully span (R-HR10-1). Correct each with a leave adjustment that names it; nothing is reversed automatically.' : null),
        ];
    }
}
