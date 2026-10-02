<?php

namespace App\Support\Retention;

use App\Domain\Finance\Application\Periods\FinancialBalanceVerifier;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Retention\FinanceRetentionEligibility;
use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D11, docs/security/E21-RETENTION-DETERMINATION.md): what still
 * stands between a School and a future, separately authorized tenant purge.
 * It is READ-ONLY: it reports and never deletes, and no tenant purge exists
 * anywhere in the repository.
 *
 * Every tenant table is read against TenantRetentionCatalog. A table the
 * catalog does not know fails closed (`unclassified_tables`). A category
 * with rows reports why it is still kept:
 * - its adopted period is running (with the earliest date when known);
 * - it is a technical blocker (`d8_d9_payroll_ledger_retained`, the D8 x D9
 *   payroll ledger until E21.3F implemented payroll evidence expiry; no
 *   category is one any more, the gate stays for a future one);
 * - Finance (D8, E21.3A2) adds its own prerequisites:
 *   - `d8_finance_period_mapping_incomplete`: unmapped entries;
 *   - `d8_finance_periods_unclosed`: an ended year still open;
 *   - `d8_finance_verification_failed`: the dual-read check differs;
 *   - `d8_finance_retention_not_enabled`: the expiry is switched off;
 * - its period is adopted but its mechanism is still pending (E21.3C-E),
 *   for the whole category or (E21.3B) only for the rows
 *   TenantRetentionCatalog::PENDING_ROWS names;
 * - (E21.3C) its rows' trigger is unknown (TenantRetentionCatalog::UNRESOLVED_ROWS:
 *   undated legacy terminal applications, unmarked Guardians without a
 *   relationship): `unresolved`, gate `retention_trigger_unresolved`;
 * - its policy is unresolved;
 * - it is tenant-lifetime configuration.
 *
 * The School is never purge-ready while any blocking gate stands. Two
 * gates stand unconditionally until a later, separately authorized
 * checkpoint:
 * - `final_ratification_pending`: final legal/compliance ratification;
 * - `no_tenant_purge_authorized`: no audited purge authorization and no
 *   purge mechanism exist.
 */
final class TenantClosureReadiness
{
    /** Gates that stand until a later, separately authorized checkpoint. */
    public const PERMANENT_GATES = ['final_ratification_pending', 'no_tenant_purge_authorized'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionHolds $holds,
        private readonly StudentRetentionEligibility $students,
        private readonly EmployeeRetentionEligibility $employees,
        private readonly FinancialPeriodService $periods,
        private readonly FinancialBalanceVerifier $verifier,
        private readonly FinanceRetentionEligibility $financeEligibility,
    ) {}

    /**
     * @return array{
     *     closed: bool, held: bool, purge_ready: bool, gates: list<string>, unclassified: list<string>,
     *     categories: list<array{category: string, status: string, decision: string, tables_with_rows: int, outcome: string, not_before: ?string}>
     * }
     */
    public function report(School $school): array
    {
        $live = array_column(DB::select(
            "SELECT c.table_name FROM information_schema.columns c
               JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace
              WHERE c.table_schema = 'public' AND c.column_name = 'school_id' ORDER BY 1",
        ), 'table_name');
        $known = TenantRetentionCatalog::tables();
        $unclassified = array_values(array_diff($live, array_keys($known)));

        $categories = $this->context->withSchool($school, function () use ($school, $live): array {
            $rows = [];
            foreach (TenantRetentionCatalog::CATEGORIES as $category => [$status, $decision, $tables]) {
                $present = 0;
                $pending = false;
                $unresolved = false;
                foreach ($tables as $table) {
                    $quoted = '"'.str_replace('"', '""', $table).'"';
                    if (in_array($table, $live, true) && DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$quoted} WHERE school_id = ?) AS hit", [$school->id])->hit) {
                        $present++;
                        /** @var array<string, string> $pendingRows */
                        $pendingRows = TenantRetentionCatalog::PENDING_ROWS;
                        $predicate = $pendingRows[$table] ?? null;
                        $pending = $pending || ($predicate !== null && DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$quoted} WHERE school_id = ? AND ({$predicate})) AS hit", [$school->id])->hit);
                        $predicate = TenantRetentionCatalog::UNRESOLVED_ROWS[$table] ?? null;
                        $unresolved = $unresolved || ($predicate !== null && DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$quoted} WHERE school_id = ? AND ({$predicate})) AS hit", [$school->id])->hit);
                    }
                }

                $rows[] = [
                    'category' => $category,
                    'status' => $status,
                    'decision' => $decision,
                    'tables_with_rows' => $present,
                    'outcome' => $present === 0 ? 'empty' : match (true) {
                        $status === TenantRetentionCatalog::ADOPTED && $pending => TenantRetentionCatalog::MECHANISM_PENDING,
                        $status === TenantRetentionCatalog::ADOPTED && $unresolved => 'unresolved',
                        $status === TenantRetentionCatalog::ADOPTED => 'retained',
                        default => $status,
                    },
                    'not_before' => $present > 0 && $status === TenantRetentionCatalog::ADOPTED && ! $pending && ! $unresolved ? $this->notBefore($school, $category) : null,
                ];
            }

            return $rows;
        });

        $closed = $school->fresh()?->isClosed() === true;
        $held = $this->holds->isHeld($school->id);
        $has = fn (string $outcome): bool => collect($categories)->contains(fn (array $c) => $c['outcome'] === $outcome);
        $gates = array_values(array_filter([
            $closed ? null : 'school_not_closed',
            $held ? 'legal_hold' : null,
            $has(TenantRetentionCatalog::TECHNICAL_BLOCKER) ? 'd8_d9_payroll_ledger_retained' : null,
            ...$this->financeGates($school),
            $has(TenantRetentionCatalog::POLICY_UNRESOLVED) ? 'policy_unresolved' : null,
            $has(TenantRetentionCatalog::MECHANISM_PENDING) ? 'retention_mechanism_pending' : null,
            $has('unresolved') ? 'retention_trigger_unresolved' : null,
            $unclassified !== [] ? 'unclassified_tables' : null,
            $has('retained') ? 'retention_periods_running' : null,
            ...self::PERMANENT_GATES,
        ]));

        // Never purge-ready: PERMANENT_GATES always stand, because no tenant
        // purge mechanism or authorization exists in this repository.
        return ['closed' => $closed, 'held' => $held, 'purge_ready' => false, 'gates' => $gates, 'unclassified' => $unclassified, 'categories' => $categories];
    }

    /**
     * E21.3A2 (D8): Finance's own prerequisites for its expiry to run at all.
     *
     * @return list<string>
     */
    private function financeGates(School $school): array
    {
        if ($this->periods->periods($school) === [] && $this->periods->unmappedEntryCount($school) === 0) {
            return []; // no Finance evidence: nothing for D8 to gate
        }
        $today = CarbonImmutable::now($school->timezone ?: 'UTC')->toDateString();
        $unclosed = array_filter($this->periods->periods($school), fn ($p) => ! $p->isClosed() && $p->endsOn < $today) !== [];
        try {
            $enabled = $this->financeEligibility->enabled() && $this->financeEligibility->years() !== null;
        } catch (\InvalidArgumentException) {
            $enabled = false;
        }

        return array_values(array_filter([
            $this->periods->unmappedEntryCount($school) > 0 ? 'd8_finance_period_mapping_incomplete' : null,
            $unclosed ? 'd8_finance_periods_unclosed' : null,
            $this->verifier->verifySnapshot($school)->passed() ? null : 'd8_finance_verification_failed',
            $enabled ? null : 'd8_finance_retention_not_enabled',
        ]));
    }

    /**
     * E21.3F: payroll evidence waits for the final separation AND for every
     * run posting (a late correction or reversal has its own clock): the
     * later of the latest separation and the latest posting date.
     */
    private function latestPayrollEvidence(School $school, ?string $latestSeparation): ?string
    {
        $dates = array_filter([
            $latestSeparation,
            DB::table('payroll_runs')->where('school_id', $school->id)->max('posted_at'),
            DB::table('payroll_run_postings')->where('school_id', $school->id)->max('created_at'),
            DB::table('payroll_statutory_run_postings')->where('school_id', $school->id)->max('created_at'),
        ]);

        return $dates === [] ? null : max(array_map(fn ($d): string => substr((string) $d, 0, 10), $dates));
    }

    /** The earliest day the category could be empty, when it can be known (else null). */
    private function notBefore(School $school, string $category): ?string
    {
        $years = fn (string $key): ?int => RetentionPeriod::years(config("retention.{$key}"));
        $after = fn (?string $date, ?int $period): ?string => $date === null || $period === null ? null
            : CarbonImmutable::parse($date)->addYearsNoOverflow($period)->addDay()->toDateString();

        return match ($category) {
            'audit' => $after(DB::table('school_audit_events')->where('school_id', $school->id)->max('occurred_at'), $years('audit_years')),
            'student_core', 'processing_authorizations' => ($s = $this->students->latestExit($school))['pending'] === 0 ? $after($s['latest'], $years('student_core_years')) : null,
            'student_operational', 'student_operational_modules' => ($s = $this->students->latestExit($school))['pending'] === 0 ? $after($s['latest'], $years('student_operational_years')) : null,
            'hr_evidence' => ($e = $this->employees->latestSeparation($school))['pending'] === 0 ? $after($e['latest'], $years('employee_evidence_years')) : null,
            'payroll_ledger' => ($e = $this->employees->latestSeparation($school))['pending'] === 0 ? $after($this->latestPayrollEvidence($school, $e['latest']), $years('employee_evidence_years')) : null,
            default => null,
        };
    }
}
