<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorCannotReviewException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\PostingEvidenceStaleException;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Infrastructure\PayrollPostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Database\Query\Builder;
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
        private readonly PayrollSelfBeneficiaries $beneficiaries,
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
        // HR.R1-C6 (OD-HR-24): nobody certifies independence over a change to their own pay.
        if ($this->beneficiaries->of($run, (string) $run->approved_calculation_id, $actor->id)->conflicted) {
            throw new SelfBeneficiaryConflictException($run->id);
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
            // OD-HR-24: Employees this attestation's conflict check could not see, recorded on the decision.
            'employeesWithoutAccount' => $this->beneficiaries->of($run, (string) $run->approved_calculation_id, $actor->id)->unlinkedEmployees,
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
            // The guards' own RAISE only: never a deadlock, or a permission error, that merely passed through them.
            if ($e->getCode() === '23514' && (str_contains($e->getMessage(), 'payroll_posting_attestations_guard:') || str_contains($e->getMessage(), 'payroll_runs_posting_independence_guard:'))) {
                throw new PostingEvidenceStaleException($run->id);
            }
            throw $e;
        }
    }

    /**
     * HR.R1-C4: a reversal can take independence from a run it never touches.
     * It does not block the reversal or change that run. An approved run meets the
     * attestation at posting. HR.R1-C6 (OD-HR-23): a run already posted has nothing
     * left to stop, so it gets an OPEN obligation, here, in the reversal's own
     * transaction (`payroll_run_postings_reconciliation_required` refuses the commit
     * without one). Under the reversal's exclusive compensation lock, after its
     * posting row. "Affected" is `payroll_reversal_affected_runs`, with no cap.
     *
     * A reversed run with no recorded provenance cannot say what it discharged, so
     * that is recorded as unknown, never as "nothing affected".
     *
     * @return int the number of runs recorded
     */
    public function recordAffectedBy(PayrollRun $reversed, PayrollRunPosting $reversal, User $actor): int
    {
        if ($reversed->run_kind === 'regular' && ! $this->hasProvenance($reversed)) {
            $this->audit->school($reversed->school, 'payroll.run.reversal_impact_unknown', actor: $actor, subject: $reversed, metadata: ['reversalPostingId' => $reversal->id]);

            return 0;
        }

        $affected = DB::select('SELECT payroll_run_id, status FROM payroll_reversal_affected_runs(?, ?) ORDER BY payroll_run_id', [$reversed->school_id, $reversal->id]);

        foreach ($affected as $row) {
            $run = PayrollRun::query()->where('school_id', $reversed->school_id)->findOrFail($row->payroll_run_id);
            $version = (string) $this->version($run);
            $this->audit->school($reversed->school, 'payroll.run.independence_changed', actor: $actor, subject: $run, metadata: [
                'reversedRunId' => $reversed->id,
                'status' => $run->status,
                'evidenceVersion' => $version,
            ]);
            if ($row->status !== 'posted') {
                continue; // an approved run meets the attestation at posting
            }
            $snapshot = DB::selectOne(
                <<<'SQL'
                SELECT (SELECT COALESCE(array_agg(f.compensation_assignment_id ORDER BY f.compensation_assignment_id), '{}') FROM payroll_run_first_paid_compensation(?, ?, ?) f)::text AS first_paid,
                       (SELECT COALESCE(array_agg(e.compensation_assignment_id ORDER BY e.compensation_assignment_id), '{}') FROM payroll_run_independence_exception(?, ?) e)::text AS implicated
                SQL,
                [$run->school_id, $run->id, $run->approved_calculation_id, $run->school_id, $run->id],
            );
            $obligation = PayrollReversalReconciliation::query()->create([
                'school_id' => $reversed->school_id,
                'payroll_run_id' => $run->id,
                'payroll_run_calculation_id' => $run->approved_calculation_id,
                'reversal_posting_id' => $reversal->id,
                'reversed_payroll_run_id' => $reversed->id,
                'reversed_period_month' => $reversed->period?->period_month,
                'reversed_at' => DB::table('payroll_run_postings')->where('school_id', $reversed->school_id)->where('id', $reversal->id)->value('created_at'),
                'reversed_by_user_id' => $reversal->actor_user_id,
                'evidence_version' => $version,
                'first_paid_assignments' => $snapshot->first_paid,
                'implicated_assignments' => $snapshot->implicated,
            ]);
            $this->audit->school($reversed->school, 'payroll.run.reconciliation_opened', actor: $actor, subject: $run, metadata: [
                'reconciliationId' => $obligation->id,
                'reversedRunId' => $reversed->id,
                'reversalPostingId' => $reversal->id,
                'approvedCalculationId' => $run->approved_calculation_id,
                'evidenceVersion' => $version,
            ]);
        }

        return count($affected);
    }

    private function hasProvenance(PayrollRun $run): bool
    {
        return $run->approved_calculation_id !== null
            && DB::table('payroll_run_calculation_sources')->where('school_id', $run->school_id)->where('payroll_run_calculation_id', $run->approved_calculation_id)->exists();
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
        $beneficiary = $version === null ? null : $this->beneficiaries->of($run, (string) $run->approved_calculation_id, $actor->id);
        $blocked = match (true) {
            $run->status !== 'approved' => null,
            ! $independent => 'not_independent',
            $beneficiary?->conflicted === true => 'self_beneficiary',
            // A read, outside any decision transaction; the decision re-reads this fresh in assertMayAttest().
            ! $this->capabilities->canInSchool($actor, self::SENSITIVE_VIEW, $run->school) => 'cannot_review',
            ! $maySeeDetails => 'needs_mfa',
            default => null,
        };
        $canAttest = $run->status === 'approved' && $version !== null && $blocked === null;
        // Whatever the run's status: nobody implicated reads the evidence of their own change.
        $showDetails = $version !== null && $maySeeDetails && $independent && $beneficiary?->conflicted === false;
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
            // Nobody implicated learns who attested to the evidence of their own change.
            'attestation' => ! $maySeeDetails || $attested === null || ! $independent || $beneficiary?->conflicted === true ? null : [
                'attestedBy' => User::query()->whereKey($attested->attested_by_user_id)->value('name'),
                'attestedAt' => $attested->created_at->toIso8601String(),
                'statementCode' => $attested->statement_code,
            ],
            'blockedReason' => $blocked,
            'evidenceVersion' => $canAttest ? $version : null,
            'details' => $showDetails ? [...$this->details($run, $page), 'employeesWithoutAccount' => $beneficiary->unlinkedEmployees] : null,
        ];
    }

    /**
     * The purpose-limited evidence of an exception: who approved, which runs were
     * reversed and when, and the affected employee numbers, names, structures and
     * dates. Never an amount, bank, statutory or profile field.
     *
     * @return array<string, mixed>
     */
    public function details(PayrollRun $run, int $page): array
    {
        $changes = DB::table(DB::raw('payroll_run_independence_exception(?, ?) AS e'))->addBinding([$run->school_id, $run->id], 'from');

        $reversals = DB::select(
            <<<'SQL'
            SELECT DISTINCT o.id, pp.period_month, rp.created_at
              FROM payroll_run_independence_exception(?, ?) e
              JOIN payroll_run_calculation_sources os ON os.school_id = ? AND os.compensation_assignment_id = e.compensation_assignment_id AND os.payroll_run_id <> ? AND os.basis = 'structure'
              JOIN payroll_runs o ON o.school_id = os.school_id AND o.id = os.payroll_run_id AND o.approved_calculation_id = os.payroll_run_calculation_id AND o.run_kind = 'regular'
              JOIN employee_compensation_assignments a ON a.school_id = os.school_id AND a.id = os.compensation_assignment_id AND o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id
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
            'changes' => $this->changesPage($run->school_id, $changes, $page),
        ];
    }

    /**
     * One page of a set of changes, `e.compensation_assignment_id`: the employee
     * number and name, the structure and the date. Never an amount.
     *
     * @return array<string, mixed>
     */
    public function changesPage(string $schoolId, Builder $set, int $page): array
    {
        $changes = $set
            ->join('employee_compensation_assignments as a', fn ($j) => $j->on('a.id', '=', 'e.compensation_assignment_id')->where('a.school_id', '=', $schoolId))
            ->join('employment_records as er', fn ($j) => $j->on('er.id', '=', 'a.employment_record_id')->where('er.school_id', '=', $schoolId))
            ->join('employees as emp', fn ($j) => $j->on('emp.id', '=', 'er.employee_id')->where('emp.school_id', '=', $schoolId))
            ->join('salary_structures as s', fn ($j) => $j->on('s.id', '=', 'a.salary_structure_id')->where('s.school_id', '=', $schoolId))
            ->orderBy('emp.employee_number')->orderBy('a.id')
            ->paginate(25, ['a.id', 'emp.employee_number', 'emp.full_name', 'a.effective_from', 's.code', 's.version'], 'independence_page', max(1, $page));

        return [
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
