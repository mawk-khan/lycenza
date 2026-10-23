<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.3A2 (ADR 0064 §15): THE authoritative ledger-account balance read.
 * Balance = the latest closed period's cumulative baseline + the lines of
 * every later (or not yet mapped) journal entry. It never needs the detail
 * of a closed period, so expiring that detail cannot change it.
 *
 * Before E21.3A2 no production code summed account balances at all; this
 * reader is now the only one. The all-history sum exists only inside the
 * balance verifier (`LedgerPeriodBalances::mismatches`), as the comparison
 * side. Read-only, no capability check (the caller authorizes).
 */
class LedgerBalanceReader
{
    /**
     * One statement, one snapshot. Bindings: school, school.
     * Columns: ledger_account_id, currency, debit_total, credit_total.
     */
    public const CARRY_FORWARD_SQL = "SELECT x.ledger_account_id, x.currency, sum(x.d)::numeric(20,2) AS debit_total, sum(x.c)::numeric(20,2) AS credit_total FROM (
            SELECT b.ledger_account_id, b.currency, b.debit_total AS d, b.credit_total AS c
              FROM financial_period_account_balances b
             WHERE b.financial_period_id = (SELECT id FROM financial_periods WHERE school_id = ? AND status = 'closed' ORDER BY starts_on DESC LIMIT 1)
            UNION ALL
            SELECT l.ledger_account_id, l.currency, coalesce(l.debit_amount, 0), coalesce(l.credit_amount, 0)
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
              LEFT JOIN financial_periods p ON p.id = e.financial_period_id AND p.school_id = e.school_id
             WHERE l.school_id = ?
               AND (p.id IS NULL OR p.starts_on > coalesce((SELECT max(starts_on) FROM financial_periods WHERE school_id = l.school_id AND status = 'closed'), '-infinity'::date))
        ) x GROUP BY x.ledger_account_id, x.currency";

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, array{debit: string, credit: string}> keyed "ledger_account_id|currency"
     */
    public function balances(School $school): array
    {
        return $this->context->withSchool($school, function () use ($school) {
            $balances = [];
            foreach (DB::select(self::CARRY_FORWARD_SQL.' ORDER BY 1, 2', [$school->id, $school->id]) as $row) {
                $balances["{$row->ledger_account_id}|{$row->currency}"] = ['debit' => (string) $row->debit_total, 'credit' => (string) $row->credit_total];
            }

            return $balances;
        });
    }

    /**
     * ORG.9B (ADR 0076 §68): one account's net-debit balance at an instant
     * (`$beforeUtc`, exclusive) by the SAME carry-forward definition as
     * balances(): the cumulative baseline of `$base` (the latest closed
     * period ending before that day, chosen by the caller) + every line of a
     * later or unmapped entry posted before the instant.
     */
    public function accountBalanceBefore(School $school, string $ledgerAccountId, string $beforeUtc, ?FinancialPeriodSummary $base): string
    {
        return $this->context->withSchool($school, function () use ($school, $ledgerAccountId, $beforeUtc, $base) {
            $balance = '0.00';
            if ($base !== null) {
                $row = DB::selectOne('SELECT coalesce(sum(debit_total), 0)::numeric(20,2) AS d, coalesce(sum(credit_total), 0)::numeric(20,2) AS c FROM financial_period_account_balances WHERE school_id = ? AND financial_period_id = ? AND ledger_account_id = ?', [$school->id, $base->id, $ledgerAccountId]);
                $balance = bcsub((string) $row->d, (string) $row->c, 2);
            }
            $later = DB::selectOne(
                'SELECT coalesce(sum(coalesce(l.debit_amount, 0) - coalesce(l.credit_amount, 0)), 0)::numeric(20,2) AS net
                   FROM journal_lines l
                   JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
                   LEFT JOIN financial_periods p ON p.id = e.financial_period_id AND p.school_id = e.school_id
                  WHERE l.school_id = ? AND l.ledger_account_id = ? AND e.posted_at < ?'
                    .($base === null ? '' : ' AND (p.id IS NULL OR p.starts_on > ?::date)'),
                $base === null ? [$school->id, $ledgerAccountId, $beforeUtc] : [$school->id, $ledgerAccountId, $beforeUtc, $base->startsOn],
            );

            return bcadd($balance, (string) $later->net, 2);
        });
    }

    /**
     * ORG.9B: an account's posted lines in [`$fromUtc`, `$beforeUtc`) -- the
     * range's line count and debit/credit totals, and one page of lines in
     * (posted_at, entry id, line id) order, each with its net-debit movement
     * from the start of the range. The window runs over the WHOLE range
     * before the page is cut, so a page never restarts the running balance.
     *
     * @return array{count: int, debit: string, credit: string, lines: list<object{line_id: string, entry_id: string, posted_at: string, description: string, reversal_of_journal_entry_id: ?string, debit: string, credit: string, movement: string}>}
     */
    public function accountLinesBetween(School $school, string $ledgerAccountId, string $fromUtc, string $beforeUtc, int $limit, int $offset): array
    {
        return $this->context->withSchool($school, function () use ($school, $ledgerAccountId, $fromUtc, $beforeUtc, $limit, $offset) {
            $range = DB::selectOne(
                'SELECT count(*) AS n, coalesce(sum(coalesce(l.debit_amount, 0)), 0)::numeric(20,2) AS d, coalesce(sum(coalesce(l.credit_amount, 0)), 0)::numeric(20,2) AS c
                   FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
                  WHERE l.school_id = ? AND l.ledger_account_id = ? AND e.posted_at >= ? AND e.posted_at < ?',
                [$school->id, $ledgerAccountId, $fromUtc, $beforeUtc],
            );
            $lines = DB::select(
                'SELECT * FROM (
                    SELECT l.id AS line_id, e.id AS entry_id, e.posted_at, e.description, e.reversal_of_journal_entry_id,
                           coalesce(l.debit_amount, 0)::numeric(20,2) AS debit, coalesce(l.credit_amount, 0)::numeric(20,2) AS credit,
                           sum(coalesce(l.debit_amount, 0) - coalesce(l.credit_amount, 0)) OVER (ORDER BY e.posted_at, e.id, l.id ROWS UNBOUNDED PRECEDING)::numeric(20,2) AS movement
                      FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
                     WHERE l.school_id = ? AND l.ledger_account_id = ? AND e.posted_at >= ? AND e.posted_at < ?
                 ) ordered ORDER BY posted_at, entry_id, line_id LIMIT ? OFFSET ?',
                [$school->id, $ledgerAccountId, $fromUtc, $beforeUtc, $limit, $offset],
            );

            return ['count' => (int) $range->n, 'debit' => (string) $range->d, 'credit' => (string) $range->c, 'lines' => $lines];
        });
    }
}
