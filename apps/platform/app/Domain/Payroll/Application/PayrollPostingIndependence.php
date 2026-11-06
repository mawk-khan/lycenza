<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorCannotReviewException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\PostingEvidenceStaleException;
use App\Domain\Payroll\Infrastructure\PayrollPostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C4 (PAY-16, OD-HR-22): the one answer to "has this run's approval lost
 * the independence it relied on". An approval taken while an independent run
 * stood stays valid history, but if that run is reversed the approver becomes
 * the author of a change the run now pays first. The approval guard refuses
 * that at approval, so finding it here can only mean it changed afterwards.
 *
 * The rules live in SQL (`payroll_run_independence_exception`,
 * `payroll_run_independence_version`), which the posting guard also calls.
 * Every method that decides runs under the run lock and the shared compensation
 * lock, so no reversal can pass between the check and the commit.
 *
 * This is a compensating control, never a new approval, and it never changes the
 * run's approval, calculation or history.
 */
final class PayrollPostingIndependence
{
    public const SENSITIVE_VIEW = 'payroll.compensation.sensitive.view';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    /** The evidence version of an open exception, or null when the approval still stands on its own. */
    public function version(PayrollRun $run): ?string
    {
        $version = DB::selectOne('SELECT payroll_run_independence_version(?, ?) AS version', [$run->school_id, $run->id])->version;

        return $version === null ? null : (string) $version;
    }

    /** Refuses unless $actor may attest to $version now. Under the run and compensation locks. */
    public function assertMayAttest(PayrollRun $run, User $actor, string $version, ?PostingAttestation $attestation): void
    {
        $school = $run->school;
        if (! $this->isIndependent($run, $actor)) {
            throw new PostingAttestorNotIndependentException($run->id);
        }
        if (! $this->capabilities->holdsInSchoolNow($actor, self::SENSITIVE_VIEW, $school)) {
            throw new PostingAttestorCannotReviewException($run->id);
        }
        if ($attestation === null || ! $attestation->accepted) {
            throw new PostingAttestationRequiredException($run->id);
        }
        if (! hash_equals($version, $attestation->evidenceVersion)) {
            throw new PostingEvidenceStaleException($run->id);
        }
    }

    public function record(PayrollRun $run, PayrollRunPosting $posting, User $actor, string $version): PayrollPostingAttestation
    {
        $attestation = $this->withinGuards($run, fn () => PayrollPostingAttestation::query()->create([
            'school_id' => $run->school_id,
            'payroll_run_id' => $run->id,
            'payroll_run_calculation_id' => $run->approved_calculation_id,
            'payroll_run_posting_id' => $posting->id,
            'evidence_version' => $version,
            'statement_code' => PayrollPostingAttestation::STATEMENT_INDEPENDENCE_CHANGED,
            'attested_by_user_id' => $actor->id,
        ]));

        $this->audit->school($run->school, 'payroll.run.independence_attested', actor: $actor, subject: $run, metadata: [
            'attestationId' => $attestation->id,
            'postingId' => $posting->id,
            'approvedCalculationId' => $run->approved_calculation_id,
            'approvedByUserId' => $run->approved_by_user_id,
            'evidenceVersion' => $version,
            'statementCode' => PayrollPostingAttestation::STATEMENT_INDEPENDENCE_CHANGED,
        ]);

        return $attestation;
    }

    /**
     * Another run's approval holds the compensation lock shared too, so it can
     * narrow the evidence between the service's read and the database guards. That
     * fails closed; this keeps it a controlled refusal, never a 500. The enclosing
     * posting transaction rolls back, ledger entry included.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public function withinGuards(PayrollRun $run, callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'payroll_posting_attestations_guard') || str_contains($e->getMessage(), 'payroll_runs_posting_independence_guard')) {
                throw new PostingEvidenceStaleException($run->id);
            }
            throw $e;
        }
    }

    /**
     * HR.R1-C4: a reversal can take independence from a run it never touches.
     * It does not block the reversal or change that run. An approved run meets the
     * attestation at posting; a run already posted has nothing left to stop, so
     * this durable event is the record (PAY-18, OD-HR-23). Under the reversal's
     * exclusive compensation lock, after its posting row.
     *
     * @return int the number of runs recorded
     */
    public function recordAffectedBy(PayrollRun $reversed, User $actor): int
    {
        $affected = DB::select(
            <<<'SQL'
            SELECT DISTINCT o.id, o.status
              FROM payroll_run_calculation_sources s
              JOIN payroll_run_calculation_sources os
                ON os.school_id = s.school_id AND os.compensation_assignment_id = s.compensation_assignment_id AND os.payroll_run_id <> s.payroll_run_id
              JOIN payroll_runs o
                ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
              JOIN employee_compensation_assignments a
                ON a.school_id = os.school_id AND a.id = os.compensation_assignment_id AND a.assigned_by_user_id = o.approved_by_user_id
             WHERE s.school_id = ? AND s.payroll_run_id = ? AND s.payroll_run_calculation_id = ?
               -- Only what the reversed run actually discharged: priced from the assignment, approved independently of its author.
               AND s.basis = 'structure' AND ?::uuid IS DISTINCT FROM a.assigned_by_user_id
               AND o.run_kind = 'regular' AND o.status IN ('approved', 'posted')
            SQL,
            [$reversed->school_id, $reversed->id, $reversed->approved_calculation_id, $reversed->approved_by_user_id],
        );

        $recorded = 0;
        foreach ($affected as $row) {
            $run = PayrollRun::query()->where('school_id', $reversed->school_id)->findOrFail($row->id);
            $version = $this->version($run);
            if ($version === null) {
                continue;
            }
            $this->audit->school($reversed->school, 'payroll.run.independence_changed', actor: $actor, subject: $run, metadata: [
                'reversedRunId' => $reversed->id,
                'status' => $run->status,
                'evidenceVersion' => $version,
            ]);
            $recorded++;
        }

        return $recorded;
    }

    /**
     * The purpose-limited evidence for the posting page and API. Identification
     * of who and which changes reaches only an actor who may read compensation
     * (`$maySeeDetails`), and never a bank, statutory or profile field or an amount.
     *
     * @return array<string, mixed>|null null when no exception applies
     */
    public function review(PayrollRun $run, User $actor, bool $maySeeDetails, int $page = 1): ?array
    {
        $version = $this->version($run);
        $attested = PayrollPostingAttestation::query()->where('school_id', $run->school_id)->where('payroll_run_id', $run->id)->first();
        if ($version === null && $attested === null) {
            return null;
        }

        $independent = $this->isIndependent($run, $actor);
        $blocked = match (true) {
            $run->status !== 'approved' => null,
            ! $independent => 'not_independent',
            // A read, outside any decision transaction; the decision re-reads this fresh in assertMayAttest().
            ! $this->capabilities->canInSchool($actor, self::SENSITIVE_VIEW, $run->school) => 'cannot_review',
            ! $maySeeDetails => 'needs_mfa',
            default => null,
        };
        $canAttest = $run->status === 'approved' && $version !== null && $blocked === null;
        $showDetails = $version !== null && $maySeeDetails && $blocked !== 'not_independent';
        if ($showDetails) {
            // Who was paid what structure, from when: reading it is audited, like every other sensitive payroll read.
            $this->audit->school($run->school, 'payroll.run.independence_reviewed', actor: $actor, subject: $run, metadata: ['evidenceVersion' => $version, 'page' => max(1, $page)]);
        }

        return [
            // An attestation covers only the evidence it named: a later reversal is new, unattested exposure.
            'phase' => match (true) {
                $run->status === 'approved' => 'before_posting',
                $attested !== null && ($version === null || hash_equals($attested->evidence_version, $version)) => 'attested',
                default => 'after_posting',
            },
            'attestation' => ! $maySeeDetails || $attested === null ? null : [
                'attestedBy' => User::query()->whereKey($attested->attested_by_user_id)->value('name'),
                'attestedAt' => $attested->created_at->toIso8601String(),
                'statementCode' => $attested->statement_code,
            ],
            'blockedReason' => $blocked,
            'evidenceVersion' => $canAttest ? $version : null,
            'details' => $showDetails ? $this->details($run, $page) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function details(PayrollRun $run, int $page): array
    {
        $changes = DB::table(DB::raw('payroll_run_independence_exception(?, ?) AS e'))
            ->addBinding([$run->school_id, $run->id], 'from')
            ->join('employee_compensation_assignments as a', fn ($j) => $j->on('a.id', '=', 'e.compensation_assignment_id')->where('a.school_id', '=', $run->school_id))
            ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'e.employment_record_id')->where('er.school_id', '=', $run->school_id))
            ->join('employees as emp', fn ($j) => $j->on('emp.id', '=', 'er.employee_id')->where('emp.school_id', '=', $run->school_id))
            ->join('salary_structures as s', fn ($j) => $j->on('s.id', '=', 'a.salary_structure_id')->where('s.school_id', '=', $run->school_id))
            ->orderBy('emp.employee_number')->orderBy('a.id')
            ->paginate(25, ['a.id', 'emp.employee_number', 'emp.full_name', 'a.effective_from', 's.code', 's.version'], 'independence_page', max(1, $page));

        $reversals = DB::select(
            <<<'SQL'
            SELECT DISTINCT o.id, pp.period_month, rp.created_at
              FROM payroll_run_independence_exception(?, ?) e
              JOIN payroll_run_calculation_sources os ON os.school_id = ? AND os.compensation_assignment_id = e.compensation_assignment_id AND os.payroll_run_id <> ?
              JOIN payroll_runs o ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id
              JOIN payroll_run_postings rp ON rp.school_id = o.school_id AND rp.payroll_run_id = o.id AND rp.posting_kind = 'reversal'
              JOIN payroll_periods pp ON pp.school_id = o.school_id AND pp.id = o.payroll_period_id
             ORDER BY rp.created_at
            SQL,
            [$run->school_id, $run->id, $run->school_id, $run->id],
        );

        return [
            'calculationId' => $run->approved_calculation_id,
            'approvedBy' => User::query()->whereKey($run->approved_by_user_id)->value('name'),
            'approvedAt' => $run->approved_at?->toIso8601String(),
            'reversedRuns' => array_map(fn ($r) => ['id' => $r->id, 'periodMonth' => substr((string) $r->period_month, 0, 7), 'reversedAt' => (string) $r->created_at], $reversals),
            'changes' => [
                'total' => $changes->total(),
                'page' => $changes->currentPage(),
                'lastPage' => $changes->lastPage(),
                'data' => collect($changes->items())->map(fn ($c) => [
                    'key' => $c->id,
                    'employeeNumber' => $c->employee_number,
                    'employee' => $c->full_name,
                    'effectiveFrom' => (string) $c->effective_from,
                    'structure' => "{$c->code} v{$c->version}",
                ])->all(),
            ],
        ];
    }

    /**
     * Posting is a direct-assured action, not a request/decide workflow
     * (`ApprovalWorkflowRegistry::DIRECT_ASSURED`), so this reads the run's
     * participants directly: every maker, plus the approver the exception names.
     */
    private function isIndependent(PayrollRun $run, User $actor): bool
    {
        return $actor->id !== $run->approved_by_user_id
            && app(PayrollRunService::class)->participants($run)->participationOf($actor->id) === null;
    }
}
