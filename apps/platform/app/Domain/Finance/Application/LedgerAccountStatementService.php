<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\Exceptions\LedgerHistoryExpiredException;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * ORG.9B (ADR 0076 §68), 9B-3: one ledger account's statement for a range of
 * School-local POSTING dates -- opening balance, every posted line, a running
 * balance, closing balance. Read-only; `finance.ledger.view`.
 *
 * - Dates: a journal entry's `posted_at` (the ledger's one accounting
 *   timestamp), bounded by School-local days: from the start of `$from` to
 *   before the start of the day after `$to` (never 23:59:59).
 * - Every journal entry is posted (there is no draft entry); reversals are
 *   their own posted entries and appear as such.
 * - Opening balance = the cumulative baseline of the latest CLOSED period
 *   ending before `$from` + every line of a later (or unmapped) entry posted
 *   before `$from`, read through `LedgerBalanceReader` (the one place that
 *   sums ledger lines), so a period close never changes it. Nothing is ever
 *   written: this is not an opening-balance entry.
 * - Running balance = opening + the ordered lines, ordered by
 *   (posted_at, journal entry id, line id) -- ids are UUIDv7, so the order
 *   is total and stable -- computed in SQL over the WHOLE range before a page
 *   is cut, so every page carries the correct balance brought forward.
 * - Amounts are net debit (debit - credit); the page shows them on the
 *   account type's normal side.
 * - A range starting on or before the end of the latest period whose detail
 *   retention expired is refused: its lines are no longer retained.
 * - One `ledger_account.statement_viewed` audit per read (account id, range,
 *   result count), like the other ledger reads.
 */
class LedgerAccountStatementService
{
    use AuthorizesCapability;

    public const int PER_PAGE = 50;

    public function __construct(
        private readonly TenantContext $context,
        private readonly FinancialPeriodService $periods,
        private readonly LedgerBalanceReader $balances,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws LedgerAccountNotFoundException
     * @throws LedgerHistoryExpiredException
     */
    public function statement(School $school, User $actor, string $ledgerAccountId, string $from, string $to, int $page = 1): LedgerAccountStatement
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.view', $school);

        $anchor = $this->periods->expiryAnchor($school);
        if ($anchor !== null && $from <= $anchor->endsOn) {
            throw new LedgerHistoryExpiredException(CarbonImmutable::parse($anchor->endsOn)->addDay()->toDateString());
        }
        $base = $this->periods->latestClosedEndingOnOrBefore($school, CarbonImmutable::parse($from)->subDay()->toDateString());
        $fromUtc = SchoolTimezone::startOfDayUtc($school, $from);
        $beforeUtc = SchoolTimezone::startOfNextDayUtc($school, $to);

        return $this->context->withSchool($school, function () use ($school, $actor, $ledgerAccountId, $from, $to, $page, $base, $fromUtc, $beforeUtc) {
            $account = Str::isUuid($ledgerAccountId)
                ? LedgerAccount::query()->where('school_id', $school->id)->find($ledgerAccountId)
                : null;
            if ($account === null) {
                throw new LedgerAccountNotFoundException($ledgerAccountId);
            }

            $opening = $this->balances->accountBalanceBefore($school, $account->id, $fromUtc, $base);
            $page = max(1, $page);
            $range = $this->balances->accountLinesBetween($school, $account->id, $fromUtc, $beforeUtc, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $total = $range['count'];

            $lines = [];
            foreach ($range['lines'] as $row) {
                $lines[] = new LedgerAccountStatementLine(
                    journalEntryId: (string) $row->entry_id,
                    postedAt: CarbonImmutable::parse((string) $row->posted_at, 'UTC'),
                    description: (string) $row->description,
                    isReversal: $row->reversal_of_journal_entry_id !== null,
                    debit: (string) $row->debit,
                    credit: (string) $row->credit,
                    balance: bcadd($opening, (string) $row->movement, 2),
                );
            }

            $this->audit->school($school, 'ledger_account.statement_viewed', actor: $actor, metadata: [
                'ledgerAccountId' => $account->id,
                'from' => $from,
                'to' => $to,
                'resultCount' => count($lines),
            ]);

            return new LedgerAccountStatement(
                account: LedgerAccountSummary::fromModel($account),
                from: $from,
                to: $to,
                opening: $opening,
                totalDebit: $range['debit'],
                totalCredit: $range['credit'],
                closing: bcadd($opening, bcsub($range['debit'], $range['credit'], 2), 2),
                lines: $lines,
                total: $total,
                page: $page,
                perPage: self::PER_PAGE,
            );
        });
    }
}
