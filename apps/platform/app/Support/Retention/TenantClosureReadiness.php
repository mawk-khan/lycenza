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
 * - it is a technical blocker (the D8 x D9 payroll ledger:
 *   `d8_d9_payroll_ledger_retained`);
 * - Finance (D8, E21.3A2) adds its own prerequisites:
 *   - `d8_finance_period_mapping_incomplete`: unmapped entries;
 *   - `d8_finance_periods_unclosed`: an ended year still open;
 *   - `d8_finance_verification_failed`: the dual-read check differs;
 *   - `d8_finance_retention_not_enabled`: the expiry is switched off;
 * - its period is adopted but its mechanism is still pending (E21.3B-E);
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
                foreach ($tables as $table) {
                    if (in_array($table, $live, true) && DB::selectOne('SELECT EXISTS (SELECT 1 FROM "'.str_replace('"', '""', $table).'" WHERE school_id = ?) AS hit', [$school->id])->hit) {
                        $present++;
                    }
                }

                $rows[] = [
                    'category' => $category,
                    'status' => $status,
                    'decision' => $decision,
                    'tables_with_rows' => $present,
                    'outcome' => $present === 0 ? 'empty' : match ($status) {
                        TenantRetentionCatalog::ADOPTED => 'retained',
                        default => $status,
                    },
                    'not_before' => $present > 0 && $status === TenantRetentionCatalog::ADOPTED ? $this->notBefore($school, $category) : null,
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

    /** The earliest day the category could be empty, when it can be known (else null). */
    private function notBefore(School $school, string $category): ?string
    {
        $years = fn (string $key): ?int => RetentionPeriod::years(config("retention.{$key}"));
        $after = fn (?string $date, ?int $period): ?string => $date === null || $period === null ? null
            : CarbonImmutable::parse($date)->addYearsNoOverflow($period)->addDay()->toDateString();

        return match ($category) {
            'audit' => $after(DB::table('school_audit_events')->where('school_id', $school->id)->max('occurred_at'), $years('audit_years')),
            'student_core' => ($s = $this->students->latestExit($school))['pending'] === 0 ? $after($s['latest'], $years('student_core_years')) : null,
            'hr_evidence' => ($e = $this->employees->latestSeparation($school))['pending'] === 0 ? $after($e['latest'], $years('employee_evidence_years')) : null,
            default => null,
        };
    }
}
