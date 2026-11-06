<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliationReview;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C6 (PAY-18): the purpose-limited read of reconciliation obligations.
 *
 * `payroll.runs.view` sees that an obligation exists, on which run, and when
 * things happened. Who, which Employees and which structures need the sensitive
 * view with current MFA, are withheld from anyone not independent, and reading
 * them is audited. Never an amount, bank, statutory or profile field. A failed
 * read raises: it is never reported as "nothing open".
 */
final class PayrollReversalReconciliationReadService
{
    public const VIEW = 'payroll.runs.view';

    public const STATES = ['open', 'reviewed', 'all'];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly PayrollPostingIndependence $independence,
        private readonly PayrollReversalReconciliationService $reconciliations,
        private readonly PayrollSelfBeneficiaries $beneficiaries,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return LengthAwarePaginator<int|string, array<string, mixed>> */
    public function page(School $school, User $actor, string $state, int $page): LengthAwarePaginator
    {
        $this->requireView($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $state, $page): LengthAwarePaginator {
            $query = DB::table('payroll_reversal_reconciliations as c')
                ->leftJoin('payroll_reversal_reconciliation_reviews as v', fn ($j) => $j->on('v.reconciliation_id', '=', 'c.id')->on('v.school_id', '=', 'c.school_id'))
                ->join('payroll_runs as r', fn ($j) => $j->on('r.id', '=', 'c.payroll_run_id')->on('r.school_id', '=', 'c.school_id'))
                ->join('payroll_periods as p', fn ($j) => $j->on('p.id', '=', 'r.payroll_period_id')->on('p.school_id', '=', 'r.school_id'))
                ->join('payroll_run_postings as rp', fn ($j) => $j->on('rp.id', '=', 'c.reversal_posting_id')->on('rp.school_id', '=', 'c.school_id'))
                ->join('payroll_runs as o', fn ($j) => $j->on('o.id', '=', 'rp.payroll_run_id')->on('o.school_id', '=', 'rp.school_id'))
                ->join('payroll_periods as op', fn ($j) => $j->on('op.id', '=', 'o.payroll_period_id')->on('op.school_id', '=', 'o.school_id'))
                ->where('c.school_id', $school->id);
            if ($state === 'open') {
                $query->whereNull('v.id');
            } elseif ($state === 'reviewed') {
                $query->whereNotNull('v.id');
            }

            /** @var LengthAwarePaginator<int|string, array<string, mixed>> $rows */
            $rows = $query->orderByRaw('v.id IS NOT NULL')->orderByDesc('c.created_at')->orderByDesc('c.id')
                ->paginate(self::PER_PAGE, ['c.id', 'c.created_at', 'c.payroll_run_id', 'p.period_month', 'o.id as reversed_run_id', 'op.period_month as reversed_period_month', 'rp.created_at as reversed_at', 'v.created_at as reviewed_at'], 'page', max(1, $page))
                ->through(fn ($row): array => $this->summary((object) $row));

            return $rows;
        });
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => $row->id,
            'state' => $row->reviewed_at === null ? 'open' : 'reviewed',
            'openedAt' => $this->iso($row->created_at),
            'reviewedAt' => $row->reviewed_at === null ? null : $this->iso($row->reviewed_at),
            'affectedRun' => ['id' => $row->payroll_run_id, 'periodMonth' => substr((string) $row->period_month, 0, 7)],
            'reversedRun' => ['id' => $row->reversed_run_id, 'periodMonth' => substr((string) $row->reversed_period_month, 0, 7), 'reversedAt' => $this->iso($row->reversed_at)],
        ];
    }

    public function openCount(School $school, User $actor): int
    {
        $this->requireView($school, $actor);

        return $this->context->withSchool($school, fn (): int => PayrollReversalReconciliation::query()
            ->where('school_id', $school->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payroll_reversal_reconciliation_reviews as v')->whereColumn('v.reconciliation_id', 'payroll_reversal_reconciliations.id')->whereColumn('v.school_id', 'payroll_reversal_reconciliations.school_id'))
            ->count());
    }

    /** @return list<array<string, mixed>> the obligations on one run, for its page */
    public function forRun(School $school, User $actor, string $runId): array
    {
        $this->requireView($school, $actor);

        return $this->context->withSchool($school, fn (): array => PayrollReversalReconciliation::query()
            ->where('school_id', $school->id)->where('payroll_run_id', $runId)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (PayrollReversalReconciliation $c): array => [
                'id' => $c->id,
                'state' => $this->review($c) === null ? 'open' : 'reviewed',
            ])->all());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException for an id of another School
     */
    public function show(School $school, User $actor, string $reconciliationId, bool $maySeeDetails, int $page = 1): array
    {
        $this->requireView($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $actor, $reconciliationId, $maySeeDetails, $page): array {
            $obligation = PayrollReversalReconciliation::query()->where('school_id', $school->id)->whereKey($reconciliationId)->first()
                ?? throw new ModelNotFoundException('No query results for model.');
            $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($obligation->payroll_run_id);
            $reversal = PayrollRunPosting::query()->where('school_id', $school->id)->findOrFail($obligation->reversal_posting_id);
            $reversed = PayrollRun::query()->where('school_id', $school->id)->findOrFail($reversal->payroll_run_id);
            $review = $this->review($obligation);

            $conflict = $this->reconciliations->conflict($obligation, $run, $actor);
            $blocked = match (true) {
                $review !== null => 'already_reviewed',
                $conflict !== null => $conflict,
                ! $this->capabilities->canInSchool($actor, PayrollReversalReconciliationService::APPROVE, $school),
                ! $this->capabilities->canInSchool($actor, PayrollReversalReconciliationService::SENSITIVE_VIEW, $school) => 'cannot_review',
                ! $maySeeDetails => 'needs_mfa',
                default => null,
            };
            $showDetails = $maySeeDetails && $conflict === null && $this->capabilities->canInSchool($actor, PayrollReversalReconciliationService::SENSITIVE_VIEW, $school);
            $current = $this->independence->version($run);
            if ($showDetails) {
                $this->audit->school($school, 'payroll.run.independence_reviewed', actor: $actor, subject: $run, metadata: ['reconciliationId' => $obligation->id, 'evidenceVersion' => $current, 'page' => max(1, $page)]);
            }

            return [
                'id' => $obligation->id,
                'state' => $review === null ? 'open' : 'reviewed',
                'openedAt' => $obligation->created_at->toIso8601String(),
                'affectedRun' => [
                    'id' => $run->id,
                    'periodMonth' => $this->periodMonth($run),
                    'approvedAt' => $run->approved_at?->toIso8601String(),
                    'postedAt' => $run->posted_at?->toIso8601String(),
                    // Reversed after this opened: the obligation stays, and the reviewer is told.
                    'sinceReversed' => PayrollRunPosting::query()->where('school_id', $school->id)->where('payroll_run_id', $run->id)->where('posting_kind', 'reversal')->exists(),
                ],
                'reversedRun' => ['id' => $reversed->id, 'periodMonth' => $this->periodMonth($reversed), 'reversedAt' => $reversal->created_at->toIso8601String()],
                'exceptionStillApplies' => $current !== null,
                'blockedReason' => $blocked,
                'reviewVersion' => $blocked === null ? $this->reconciliations->version($obligation) : null,
                'details' => $showDetails ? [
                    ...$this->independence->details($run, $page),
                    'reversedBy' => User::query()->whereKey($reversal->actor_user_id)->value('name'),
                    'employeesWithoutAccount' => $this->beneficiaries->of($run, $obligation->payroll_run_calculation_id, $actor->id)->unlinkedEmployees,
                ] : null,
                'review' => $review === null ? null : [
                    'reviewedAt' => $review->created_at->toIso8601String(),
                    'statementCode' => $review->statement_code,
                    'reviewedBy' => $showDetails ? User::query()->whereKey($review->reviewed_by_user_id)->value('name') : null,
                    'employeesWithoutAccount' => $showDetails ? $review->unlinked_employee_count : null,
                ],
            ];
        });
    }

    private function review(PayrollReversalReconciliation $obligation): ?PayrollReversalReconciliationReview
    {
        return PayrollReversalReconciliationReview::query()->where('school_id', $obligation->school_id)->where('reconciliation_id', $obligation->id)->first();
    }

    private function periodMonth(PayrollRun $run): ?string
    {
        return PayrollPeriod::query()->where('school_id', $run->school_id)->find($run->payroll_period_id)?->period_month?->format('Y-m');
    }

    private function iso(mixed $value): string
    {
        return Carbon::parse((string) $value)->toIso8601String();
    }

    private function requireView(School $school, User $actor): void
    {
        if (! $this->capabilities->canInSchool($actor, self::VIEW, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
