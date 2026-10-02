<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Application\LedgerBalanceReader;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * E21.3A (ADR 0064 §5): ledger-account balances carried across a close.
 * A baseline row holds an account's CUMULATIVE debit and credit totals
 * through the closed period: the previous baseline plus that period's
 * lines. Account balance = latest closed baseline + lines of later periods
 * (entries not yet mapped to a period count as later; a close refuses
 * while one is dated inside the period).
 *
 * Every method runs inside the caller's School TenantContext.
 */
class LedgerPeriodBalances
{
    /** Writes the account baseline of $period (still open) and returns its digest. */
    public function writeBaseline(School $school, FinancialPeriodSummary $period, ?FinancialPeriodSummary $previous): string
    {
        $rows = DB::select(
            'SELECT x.ledger_account_id, x.currency, sum(x.d) AS debit_total, sum(x.c) AS credit_total FROM (
                SELECT b.ledger_account_id, b.currency, b.debit_total AS d, b.credit_total AS c
                  FROM financial_period_account_balances b WHERE b.school_id = ? AND b.financial_period_id = ?
                UNION ALL
                SELECT l.ledger_account_id, l.currency, coalesce(l.debit_amount, 0), coalesce(l.credit_amount, 0)
                  FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
                 WHERE e.school_id = ? AND e.financial_period_id = ?
             ) x GROUP BY x.ledger_account_id, x.currency ORDER BY x.ledger_account_id, x.currency',
            [$school->id, $previous?->id, $school->id, $period->id],
        );

        $insert = [];
        $canonical = [];
        foreach ($rows as $row) {
            $insert[] = [
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'financial_period_id' => $period->id,
                'ledger_account_id' => $row->ledger_account_id,
                'currency' => $row->currency,
                'debit_total' => $row->debit_total,
                'credit_total' => $row->credit_total,
                'created_at' => now(),
            ];
            $canonical[] = "{$row->ledger_account_id}|{$row->currency}|{$row->debit_total}|{$row->credit_total}";
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('financial_period_account_balances')->insert($chunk);
        }

        return hash('sha256', implode("\n", $canonical));
    }

    public function baselineCount(School $school, string $periodId): int
    {
        return DB::table('financial_period_account_balances')
            ->where('school_id', $school->id)
            ->where('financial_period_id', $periodId)
            ->count();
    }

    /**
     * Dual-read in ONE statement (one snapshot), per account and currency:
     * - OLD, the reconstruction from the retained history: every line, or,
     *   once detail has been expired (E21.3A2), the expiry anchor's
     *   baseline + every line after it (detail at or before the anchor may
     *   be gone; the anchor's baseline was verified when it closed);
     * - NEW, the production read (`LedgerBalanceReader`): the latest closed
     *   baseline + later lines.
     *
     * @return list<string> "account:<id>:<currency>" for each difference
     */
    public function mismatches(School $school): array
    {
        $anchor = DB::selectOne(
            'SELECT p.id, p.starts_on FROM financial_periods p
              WHERE p.school_id = ? AND EXISTS (SELECT 1 FROM financial_period_expiries x WHERE x.school_id = p.school_id AND x.financial_period_id = p.id)
              ORDER BY p.starts_on DESC LIMIT 1',
            [$school->id],
        );

        $rows = DB::select(
            'WITH old AS (
                SELECT ledger_account_id, currency, sum(d) AS d, sum(c) AS c FROM (
                    SELECT b.ledger_account_id, b.currency, b.debit_total AS d, b.credit_total AS c
                      FROM financial_period_account_balances b WHERE b.school_id = ? AND b.financial_period_id = ?
                    UNION ALL
                    SELECT l.ledger_account_id, l.currency, coalesce(l.debit_amount, 0), coalesce(l.credit_amount, 0)
                      FROM journal_lines l
                      JOIN journal_entries e ON e.id = l.journal_entry_id AND e.school_id = l.school_id
                      LEFT JOIN financial_periods p ON p.id = e.financial_period_id AND p.school_id = e.school_id
                     WHERE l.school_id = ? AND (?::date IS NULL OR p.id IS NULL OR p.starts_on > ?::date)
                ) o GROUP BY ledger_account_id, currency
             ), new AS ('.LedgerBalanceReader::CARRY_FORWARD_SQL.')
             SELECT coalesce(o.ledger_account_id, n.ledger_account_id) AS account, coalesce(o.currency, n.currency) AS currency
               FROM old o FULL JOIN new n ON n.ledger_account_id = o.ledger_account_id AND n.currency = o.currency
              WHERE o.d IS DISTINCT FROM n.debit_total OR o.c IS DISTINCT FROM n.credit_total
              ORDER BY 1, 2',
            [$school->id, $anchor?->id, $school->id, $anchor?->starts_on, $anchor?->starts_on, $school->id, $school->id],
        );

        return array_map(fn ($row) => "account:{$row->account}:{$row->currency}", $rows);
    }
}
