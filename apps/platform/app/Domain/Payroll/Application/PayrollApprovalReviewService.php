<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunCalculation;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * OD-HR-1 / OD-HR-2 (owner decisions 2026-11-05): the purpose-limited payroll
 * APPROVAL REVIEW -- what a checker must see to approve a run, read from
 * Payroll's own results, and nothing else:
 *
 *   - the run, its period and its LATEST calculation (the evidence version
 *     the approval binds to: `calculationId`);
 *   - run totals (gross, deductions, net) and totals per salary component,
 *     beside the previous approved run's, and whether gross - deductions =
 *     net holds over the whole run;
 *   - paged: every entry whose gross, deductions or net differs from the
 *     previous approved run (joiners and leavers included -- an employment,
 *     so a rehire is a new entry), every manual override and correction
 *     delta with its amount, reason and author, and every compensation
 *     assignment recorded since the previous approved run with its author;
 *   - what blocks the decision (backend codes) and what only warns.
 *
 * Never an HR profile, address, family, bank, statutory identifier, document
 * or unchanged salary register (the full results stay on the run page for
 * sensitive viewers); never a recalculation (amounts are the stored
 * results); never a count of people beyond the run's own entries.
 *
 * Authority: whole-School `payroll.runs.approve` AND
 * `payroll.compensation.sensitive.view` (the controller adds current MFA,
 * `mfa-page`). Every view is audited (`payroll.run.review_viewed`, ids only).
 */
final class PayrollApprovalReviewService
{
    public const string APPROVE = 'payroll.runs.approve';

    public const string SENSITIVE_VIEW = 'payroll.compensation.sensitive.view';

    public const int PER_PAGE = 25;

    public const array SECTIONS = ['changes', 'adjustments', 'compensation'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly EmploymentRoster $roster,
        private readonly AuditRecorder $audit,
        private readonly PayrollCompensationProvenance $provenance,
        private readonly PayrollSelfBeneficiaries $beneficiaries,
        private readonly CorrectionTargetGuard $correctionTarget,
    ) {}

    public function mayReview(User $actor, School $school): bool
    {
        return $this->capabilities->canInSchool($actor, self::APPROVE, $school)
            && $this->capabilities->canInSchool($actor, self::SENSITIVE_VIEW, $school);
    }

    /**
     * The review packet. $pages: section => page (1-based).
     *
     * @param  array<string, int>  $pages
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function packet(School $school, string $runId, User $actor, array $pages = []): array
    {
        if (! $this->mayReview($actor, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $this->context->withSchool($school, function () use ($school, $runId, $actor, $pages): array {
            $run = PayrollRun::query()->where('school_id', $school->id)->whereKey($runId)->first() ?? throw new ModelNotFoundException('No query results for the payroll run.');
            $period = PayrollPeriod::query()->where('school_id', $school->id)->find($run->payroll_period_id);
            $latest = PayrollRunCalculation::query()->where('school_id', $school->id)->where('payroll_run_id', $run->id)->orderByDesc('ordinal')->first();
            $previous = $this->previousRun($run);
            $names = User::query()->whereIn('id', array_filter([$run->prepared_by_user_id, $run->approved_by_user_id, $latest?->calculated_by_user_id]))->pluck('name', 'id');

            $packet = [
                'run' => [
                    'id' => $run->id,
                    'kind' => $run->run_kind,
                    'status' => $run->status,
                    'periodMonth' => $period?->period_month?->format('Y-m'),
                    'periodStartsOn' => $period?->starts_on?->toDateString(),
                    'periodEndsOn' => $period?->ends_on?->toDateString(),
                    'preparedBy' => $names[$run->prepared_by_user_id] ?? null,
                    'approvedBy' => $run->approved_by_user_id === null ? null : ($names[$run->approved_by_user_id] ?? null),
                    'approvedAt' => $run->approved_at?->toIso8601String(),
                    // The calculation an earlier approval was bound to (NULL before OD-HR-1: never recorded).
                    'approvedCalculationId' => $run->approved_calculation_id,
                    // HR.R1-C1 (PAY-6): 'bound' = approved against a recorded calculation; 'legacy' = approved before
                    // that evidence existed (nothing backfilled); NULL = not approved.
                    'approvalEvidence' => in_array($run->status, ['approved', 'posted'], true) ? ($run->approved_calculation_id === null ? 'legacy' : 'bound') : null,
                ],
                'calculation' => $latest === null ? null : [
                    'id' => $latest->id,
                    // HR.R1-C3: what the approval names, so a reversal since this review is detected.
                    'attributionVersion' => $this->provenance->attributionVersion($run, $latest->id),
                    'ordinal' => $latest->ordinal,
                    'calculatedAt' => $latest->created_at->toIso8601String(),
                    'calculatedBy' => $names[$latest->calculated_by_user_id] ?? null,
                    'complete' => (bool) $latest->complete,
                    'adjustmentCount' => $latest->adjustment_count,
                    // HR.R1-C1: FALSE only for a calculation made before compensation provenance existed.
                    'compensationSourcesRecorded' => (bool) $latest->compensation_sources_recorded,
                ],
                'previousRun' => $previous === null ? null : [
                    'id' => $previous->id,
                    'periodMonth' => PayrollPeriod::query()->where('school_id', $school->id)->find($previous->payroll_period_id)?->period_month?->format('Y-m'),
                    'status' => $previous->status,
                ],
                'totals' => $this->totals($school, $run->id),
                'previousTotals' => $previous === null ? null : $this->totals($school, $previous->id),
                'components' => $this->componentTotals($school, $run->id),
                ...$this->decision($school, $run, $latest, $previous, $actor),
                'changes' => $this->changes($school, $run, $previous, $pages['changes'] ?? 1),
                'adjustments' => $this->adjustments($school, $run, $pages['adjustments'] ?? 1),
                'compensation' => $this->compensationChanges($school, $run, $latest, $pages['compensation'] ?? 1),
            ];

            $this->audit->school($school, 'payroll.run.review_viewed', actor: $actor, subject: $run, metadata: [
                'calculationId' => $latest?->id,
                'previousRunId' => $previous?->id,
            ]);

            return $packet;
        });
    }

    /**
     * The run this one is compared with: for a correction run, the run it
     * corrects; otherwise the latest approved or posted REGULAR run of the
     * School for an EARLIER period (HR.R1-C1, PAY-1: a correction run holds
     * deltas only, so it is never a regular run's baseline).
     */
    public function previousRun(PayrollRun $run): ?PayrollRun
    {
        if ($run->corrects_payroll_run_id !== null) {
            return PayrollRun::query()->where('school_id', $run->school_id)->find($run->corrects_payroll_run_id);
        }
        $period = PayrollPeriod::query()->where('school_id', $run->school_id)->find($run->payroll_period_id);
        if ($period === null) {
            return null;
        }

        return PayrollRun::query()->select('payroll_runs.*')
            ->join('payroll_periods as p', fn ($j) => $j->on('p.id', '=', 'payroll_runs.payroll_period_id')->on('p.school_id', '=', 'payroll_runs.school_id'))
            ->where('payroll_runs.school_id', $run->school_id)->where('payroll_runs.id', '<>', $run->id)
            ->where('payroll_runs.run_kind', 'regular')
            ->whereIn('payroll_runs.status', ['approved', 'posted'])->where('p.ends_on', '<', $period->starts_on->toDateString())
            ->orderByDesc('p.ends_on')->orderByDesc('payroll_runs.approved_at')->orderByDesc('payroll_runs.id')->first();
    }

    /**
     * HR.R1-C1 (PAY-5): `reconciles` means every result's gross and deductions
     * equal its own stored lines (earnings and deductions, signed by effect) and
     * gross - deductions = net -- `payroll_run_results_reconcile`, the same SQL
     * the approval guard enforces -- not merely the per-row CHECK.
     *
     * @return array{gross: string, deductions: string, net: string, entries: int, reconciles: bool}
     */
    private function totals(School $school, string $runId): array
    {
        $t = DB::table('payroll_run_results')->where('school_id', $school->id)->where('payroll_run_id', $runId)
            ->selectRaw('coalesce(sum(gross_amount), 0)::text AS gross, coalesce(sum(total_deductions), 0)::text AS deductions, coalesce(sum(net_amount), 0)::text AS net, count(*) AS entries')
            ->first();
        $reconciles = (bool) DB::selectOne('SELECT payroll_run_results_reconcile(?, ?) AS ok', [$school->id, $runId])->ok;

        return ['gross' => self::money($t->gross), 'deductions' => self::money($t->deductions), 'net' => self::money($t->net), 'entries' => (int) $t->entries, 'reconciles' => $reconciles];
    }

    /** @return list<array{code: string, name: string, type: string, effect: string, amount: string, currency: string}> */
    private function componentTotals(School $school, string $runId): array
    {
        return DB::table('payroll_run_result_lines as l')
            ->join('payroll_run_results as r', fn ($j) => $j->on('r.id', '=', 'l.payroll_run_result_id')->on('r.school_id', '=', 'l.school_id'))
            ->join('salary_components as c', fn ($j) => $j->on('c.id', '=', 'l.salary_component_id')->on('c.school_id', '=', 'l.school_id'))
            ->where('l.school_id', $school->id)->where('r.payroll_run_id', $runId)
            ->groupBy('c.code', 'c.name', 'c.type', 'l.effect', 'l.currency')
            ->orderBy('l.effect')->orderBy('c.code')
            ->selectRaw('c.code, c.name, c.type, l.effect, l.currency, sum(l.amount)::text AS amount')
            ->get()->map(fn ($c) => ['code' => (string) $c->code, 'name' => (string) $c->name, 'type' => (string) $c->type, 'effect' => (string) $c->effect, 'amount' => self::money($c->amount), 'currency' => (string) $c->currency])->all();
    }

    /**
     * What blocks the decision (codes owned here, never the client) and what
     * only warns. Approval re-checks all of it under the run lock.
     *
     * @return array{approvable: bool, blocking: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}
     */
    private function decision(School $school, PayrollRun $run, ?PayrollRunCalculation $latest, ?PayrollRun $previous, User $actor): array
    {
        $blocking = [];
        $warnings = [];
        // HR.R1-C8 (PAY-17): shown here only; the refusal is CorrectionTargetGuard and the database.
        if ($run->isCorrection() && $this->correctionTarget->reversed($run->school_id, (string) $run->corrects_payroll_run_id)) {
            $blocking[] = ['code' => 'PAYROLL_CORRECTION_TARGET_ALREADY_REVERSED', 'message' => 'The run this corrects has been reversed, so this correction can never be approved or posted.'];
        }
        if ($run->status !== 'calculated') {
            $blocking[] = ['code' => 'PAYROLL_RUN_NOT_AWAITING_APPROVAL', 'message' => "This run is {$run->status}; only a calculated run can be approved."];
        }
        $adjustments = DB::table('payroll_adjustments')->where('school_id', $school->id)->where('payroll_run_id', $run->id)->count();
        if ($latest === null || ! $latest->complete || $latest->adjustment_count < $adjustments) {
            $blocking[] = ['code' => 'PAYROLL_RUN_RECALCULATION_REQUIRED', 'message' => 'The run needs a complete recalculation of every adjustment before approval.'];
        } elseif (! $latest->compensation_sources_recorded) {
            // HR.R1-C1: calculated before compensation provenance existed -- nothing is backfilled.
            $blocking[] = ['code' => 'PAYROLL_RUN_RECALCULATION_REQUIRED', 'message' => 'This calculation did not record the compensation it used; recalculate the run before approval.'];
        } elseif (! $this->provenance->isFresh($run, $latest->id)) {
            // HR.R1-C1 (PAY-2): the same SQL the approval itself checks under the compensation lock.
            $blocking[] = ['code' => 'PAYROLL_RUN_RECALCULATION_REQUIRED', 'message' => 'Compensation for this period changed after the calculation; recalculate the run before approval.'];
        }
        // Shown here only; the decision itself is MakerCheckerPolicy's (PayrollRunService::approve()).
        if (app(PayrollRunService::class)->participants($run)->participationOf($actor->id) !== null) {
            $blocking[] = ['code' => 'PAYROLL_SELF_APPROVAL', 'message' => 'You prepared, calculated, adjusted or changed compensation for this run; another person must approve it.'];
        }
        // HR.R1-C6 (OD-HR-24): shown here only; the decision itself is PayrollRunService::approve() and the database.
        if ($latest !== null && $latest->compensation_sources_recorded && $run->run_kind === 'regular') {
            $beneficiary = $this->beneficiaries->of($run, $latest->id, $actor->id);
            if ($beneficiary->conflicted) {
                $blocking[] = ['code' => 'PAYROLL_SELF_BENEFICIARY_CONFLICT', 'message' => 'This run pays a compensation change of your own for the first time, so someone whose own pay it does not change must approve it.'];
            }
            // HR.R1-C8: a correction that could change who may decide is never silent. It changes no state.
            if ($beneficiary->correctedEmployees > 0) {
                $warnings[] = ['code' => 'PAYROLL_EMPLOYEE_LINK_CORRECTED', 'message' => "{$beneficiary->correctedEmployees} Employee(s) with a compensation change in this run had an account link corrected as an error. Check the correction is right before you rely on it."];
            }
            if ($beneficiary->unlinkedEmployees > 0) {
                $warnings[] = ['code' => 'PAYROLL_EMPLOYEES_WITHOUT_ACCOUNT', 'message' => "{$beneficiary->unlinkedEmployees} Employee(s) with a compensation change in this run have no linked account, so the system cannot tell whether you are one of them. Your approval records this."];
            }
        }
        $totals = $this->totals($school, $run->id);
        if (! $totals['reconciles']) {
            $blocking[] = ['code' => 'PAYROLL_TOTALS_DO_NOT_RECONCILE', 'message' => 'Gross minus deductions does not equal net over the run.'];
        }
        if ($latest !== null && $latest->compensation_sources_recorded && $run->run_kind === 'regular'
            && $this->provenance->firstPaid($run, $latest->id)->whereNull('f.assigned_by_user_id')->exists()) {
            $warnings[] = ['code' => 'PAYROLL_COMPENSATION_AUTHOR_NOT_RECORDED', 'message' => 'Some compensation changes were recorded before authors were recorded; their author is not known.'];
        }
        if ($previous !== null && DB::table('payroll_run_postings')->where('school_id', $school->id)->where('payroll_run_id', $previous->id)->where('posting_kind', 'reversal')->exists()) {
            // HR.R1-C3: PAY-1's baseline stays the previous approved run; the checker is told it no longer stands.
            $warnings[] = ['code' => 'PAYROLL_PREVIOUS_RUN_REVERSED', 'message' => 'The run compared with here was reversed, so these differences are measured against figures that no longer stand.'];
        }
        if ($previous === null) {
            $warnings[] = ['code' => 'PAYROLL_NO_PREVIOUS_RUN', 'message' => 'There is no earlier approved run to compare with, so every entry is listed as new.'];
        }

        return ['approvable' => $blocking === [], 'blocking' => $blocking, 'warnings' => $warnings];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function changes(School $school, PayrollRun $run, ?PayrollRun $previous, int $page): LengthAwarePaginator
    {
        $current = DB::table('payroll_run_results')->where('school_id', $school->id)->where('payroll_run_id', $run->id);
        $before = DB::table('payroll_run_results')->where('school_id', $school->id)->where('payroll_run_id', $previous->id ?? '00000000-0000-0000-0000-000000000000');
        $query = DB::query()->fromSub($current, 'c')
            ->joinSub($before, 'p', 'p.employment_record_id', '=', 'c.employment_record_id', 'full outer')
            ->where(fn ($w) => $w->whereNull('c.id')->orWhereNull('p.id')
                ->orWhereColumn('c.gross_amount', '<>', 'p.gross_amount')->orWhereColumn('c.total_deductions', '<>', 'p.total_deductions')->orWhereColumn('c.net_amount', '<>', 'p.net_amount'))
            ->selectRaw("coalesce(c.employment_record_id, p.employment_record_id) AS employment_record_id,
                CASE WHEN p.id IS NULL THEN 'new' WHEN c.id IS NULL THEN 'left' ELSE 'changed' END AS kind,
                p.gross_amount::text AS previous_gross, p.total_deductions::text AS previous_deductions, p.net_amount::text AS previous_net,
                c.gross_amount::text AS gross, c.total_deductions::text AS deductions, c.net_amount::text AS net");
        $paginator = DB::query()->fromSub($query, 'x')->orderBy('x.kind')->orderBy('x.employment_record_id')->paginate(self::PER_PAGE, ['x.*'], 'changes_page', max(1, $page));
        $labels = $this->labels($school, $paginator->getCollection()->pluck('employment_record_id')->all());

        /** @var LengthAwarePaginator<int, array<string, mixed>> $presented */
        $presented = $paginator->through(fn ($r) => [
            'key' => (string) $r->employment_record_id,
            'employee' => $labels[strtolower((string) $r->employment_record_id)] ?? null,
            'kind' => (string) $r->kind,
            'previous' => $r->previous_net === null ? null : ['gross' => self::money($r->previous_gross), 'deductions' => self::money($r->previous_deductions), 'net' => self::money($r->previous_net)],
            'current' => $r->net === null ? null : ['gross' => self::money($r->gross), 'deductions' => self::money($r->deductions), 'net' => self::money($r->net)],
        ]);

        return $presented;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function adjustments(School $school, PayrollRun $run, int $page): LengthAwarePaginator
    {
        $paginator = DB::table('payroll_adjustments as a')
            ->join('salary_components as c', fn ($j) => $j->on('c.id', '=', 'a.salary_component_id')->on('c.school_id', '=', 'a.school_id'))
            ->where('a.school_id', $school->id)->where('a.payroll_run_id', $run->id)
            ->orderBy('a.created_at')->orderBy('a.id')
            ->paginate(self::PER_PAGE, ['a.id', 'a.employment_record_id', 'a.mode', 'a.effect', 'a.amount', 'a.reason', 'a.actor_user_id', 'a.created_at', 'c.code', 'c.name'], 'adjustments_page', max(1, $page));
        $rows = $paginator->getCollection();
        $labels = $this->labels($school, $rows->pluck('employment_record_id')->all());
        $names = User::query()->whereIn('id', $rows->pluck('actor_user_id')->unique()->values())->pluck('name', 'id');

        /** @var LengthAwarePaginator<int, array<string, mixed>> $presented */
        $presented = $paginator->through(fn ($a) => [
            'key' => (string) $a->id,
            'employee' => $labels[strtolower((string) $a->employment_record_id)] ?? null,
            'mode' => (string) $a->mode,
            'component' => "{$a->code} — {$a->name}",
            'effect' => $a->effect,
            'amount' => self::money((string) $a->amount),
            'reason' => (string) $a->reason,
            'author' => $names[$a->actor_user_id] ?? null,
            'recordedAt' => (string) $a->created_at,
        ]);

        return $presented;
    }

    /**
     * HR.R1-C1 (PAY-2): the compensation changes this run pays FIRST, from its
     * latest calculation's recorded sources (PayrollCompensationProvenance) --
     * exactly the set whose authors the decision refuses. Empty when the
     * calculation recorded none (a correction run, or a calculation made
     * before provenance existed, which blocks approval).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function compensationChanges(School $school, PayrollRun $run, ?PayrollRunCalculation $latest, int $page): LengthAwarePaginator
    {
        $query = ($latest === null || ! $latest->compensation_sources_recorded || $run->run_kind !== 'regular')
            ? DB::table('employee_compensation_assignments as a')->whereRaw('false')->select('a.*')
            : $this->provenance->firstPaid($run, $latest->id)
                ->join('employee_compensation_assignments as a', fn ($j) => $j->on('a.id', '=', 'f.compensation_assignment_id')->where('a.school_id', '=', $school->id));
        $paginator = $query
            ->join('salary_structures as s', fn ($j) => $j->on('s.id', '=', 'a.salary_structure_id')->on('s.school_id', '=', 'a.school_id'))
            ->orderBy('a.created_at')->orderBy('a.id')
            ->paginate(self::PER_PAGE, ['a.id', 'a.employment_record_id', 'a.effective_from', 'a.effective_to', 'a.assigned_by_user_id', 'a.created_at', 's.code', 's.name', 's.version'], 'compensation_page', max(1, $page));
        $rows = $paginator->getCollection();
        $labels = $this->labels($school, $rows->pluck('employment_record_id')->all());
        $names = User::query()->whereIn('id', $rows->pluck('assigned_by_user_id')->filter()->unique()->values())->pluck('name', 'id');

        /** @var LengthAwarePaginator<int, array<string, mixed>> $presented */
        $presented = $paginator->through(fn ($a) => [
            'key' => (string) $a->id,
            'employee' => $labels[strtolower((string) $a->employment_record_id)] ?? null,
            'structure' => "{$a->code} v{$a->version} — {$a->name}",
            'effectiveFrom' => (string) $a->effective_from,
            'effectiveTo' => $a->effective_to === null ? null : (string) $a->effective_to,
            'recordedAt' => (string) $a->created_at,
            'author' => $a->assigned_by_user_id === null ? null : ($names[$a->assigned_by_user_id] ?? null),
        ]);

        return $presented;
    }

    /**
     * @param  list<mixed>  $employmentIds
     * @return array<string, array{name: string, number: string}>
     */
    private function labels(School $school, array $employmentIds): array
    {
        $ids = array_values(array_unique(array_map('strval', array_filter($employmentIds))));

        return collect($this->roster->labels($school, $ids))
            ->mapWithKeys(fn (array $l) => [strtolower($l['employmentRecordId']) => ['name' => (string) $l['fullName'], 'number' => (string) $l['employeeNumber']]])->all();
    }

    /** Two-decimal string from PostgreSQL numeric text, never a float. */
    private static function money(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
