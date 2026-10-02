<?php

namespace App\Domain\Finance\Application;

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
}
