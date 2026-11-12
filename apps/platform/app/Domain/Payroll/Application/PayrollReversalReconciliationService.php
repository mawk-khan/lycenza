<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\Payroll\Application\Exceptions\KnownSamePersonDecisionException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationAcceptanceRequiredException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationAlreadyReviewedException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationEvidenceStaleException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationReviewerNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliationReview;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): the one way an OPEN obligation closes. A review is
 * an independent person's statement that they read the real chronology of a
 * reversal that took independence from a posted run. It is never a new approval
 * and certifies nothing about the original decision. It changes no run, posting or
 * calculation, moves no money and has no correction path.
 *
 * Authority: current `payroll.runs.approve` and `payroll.compensation.sensitive.view`,
 * re-read under the run lock, with the reviewer's own fresh-MFA proof.
 * Independence: never the run's approver or makers, whoever executed the reversal,
 * or the Employee of a change the run pays first. Nothing else opens this, and
 * nothing closes it automatically, so a School with nobody eligible keeps it OPEN.
 *
 * Lock order is the posting order: the affected run row, then the shared
 * compensation lock, so a reversal, a link change or a second reviewer is ordered
 * wholly before or after this decision.
 */
final class PayrollReversalReconciliationService
{
    public const APPROVE = 'payroll.runs.approve';

    public const SENSITIVE_VIEW = PayrollPostingIndependence::SENSITIVE_VIEW;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly PayrollCompensationProvenance $provenance,
        private readonly PayrollSelfBeneficiaries $beneficiaries,
        private readonly PayrollRunService $runs,
        private readonly AuditRecorder $audit,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    public function review(School $school, string $reconciliationId, User $actor, FreshMfaProof $mfa, string $expectedVersion, bool $accepted): PayrollReversalReconciliationReview
    {
        if (! $mfa->isFor($actor->id)) {
            throw new LogicException('The fresh MFA proof belongs to another User.');
        }

        return $this->context->withSchool($school, function () use ($school, $reconciliationId, $actor, $expectedVersion, $accepted) {
            $obligation = PayrollReversalReconciliation::query()->where('school_id', $school->id)->whereKey($reconciliationId)->first()
                ?? throw new ModelNotFoundException('No query results for model.');

            try {
                return DB::transaction(function () use ($school, $obligation, $actor, $expectedVersion, $accepted) {
                    $run = PayrollRun::query()->where('school_id', $school->id)->whereKey($obligation->payroll_run_id)->lockForUpdate()->firstOrFail();
                    $this->authority->requireCurrent($actor, self::APPROVE, $school);
                    $this->authority->requireCurrent($actor, self::SENSITIVE_VIEW, $school);
                    $this->provenance->lock($school->id, shared: true);

                    if (PayrollReversalReconciliationReview::query()->where('school_id', $school->id)->where('reconciliation_id', $obligation->id)->exists()) {
                        throw new ReconciliationAlreadyReviewedException($obligation->id);
                    }
                    match ($this->conflict($obligation, $run, $actor)) {
                        'not_independent' => throw new ReconciliationReviewerNotIndependentException($obligation->id),
                        'same_person' => throw new KnownSamePersonDecisionException($obligation->id),
                        'self_beneficiary' => throw new SelfBeneficiaryConflictException($run->id),
                        default => null,
                    };
                    if (! $accepted) {
                        throw new ReconciliationAcceptanceRequiredException($obligation->id);
                    }
                    $version = $this->version($obligation);
                    if (! hash_equals($version, $expectedVersion)) {
                        throw new ReconciliationEvidenceStaleException($obligation->id);
                    }

                    $finding = $this->beneficiaries->ofObligation($school->id, $obligation->id, $actor->id);
                    $unlinked = $finding->unlinkedEmployees;
                    $review = PayrollReversalReconciliationReview::query()->create([
                        'school_id' => $school->id,
                        'reconciliation_id' => $obligation->id,
                        'evidence_version' => $version,
                        'statement_code' => PayrollReversalReconciliationReview::STATEMENT_REVERSAL_IMPACT_REVIEWED,
                        'unlinked_employee_count' => $unlinked,
                        'reviewed_by_user_id' => $actor->id,
                    ]);

                    $this->audit->school($school, 'payroll.run.reconciliation_reviewed', actor: $actor, subject: $run, metadata: [
                        'reconciliationId' => $obligation->id,
                        'reviewId' => $review->id,
                        'reversalPostingId' => $obligation->reversal_posting_id,
                        'evidenceVersion' => $version,
                        'statementCode' => $review->statement_code,
                        'employeesWithoutAccount' => $unlinked,
                        'employeesWithCorrectedLink' => $finding->correctedEmployees,
                        'deciderHadCorrectedLink' => $finding->actorHadCorrectedLink,
                    ]);

                    return $review;
                });
            } catch (UniqueConstraintViolationException) {
                throw new ReconciliationAlreadyReviewedException($obligation->id);
            } catch (QueryException $e) {
                // The guard's own RAISE only: never a deadlock or permission error that merely passed through it.
                if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'payroll_reversal_reconciliation_reviews_guard:')) {
                    throw new ReconciliationEvidenceStaleException($obligation->id);
                }
                throw $e;
            }
        });
    }

    /** What a reviewer's page must still match: bound to this obligation and to what is true now. */
    public function version(PayrollReversalReconciliation $obligation): string
    {
        return (string) DB::selectOne('SELECT payroll_reversal_reconciliation_version(?, ?) AS version', [$obligation->school_id, $obligation->id])->version;
    }

    /**
     * Why this actor is not independent of this obligation, or null, read from the
     * moment it opened and from now. The run's makers, approver and reverser come
     * first, so the closed reason never reveals that someone who took part is also
     * paid by the run.
     *
     * HR.R1-C34 (WO-16, C33): then an account known to be the same person as any of them ('same_person',
     * backed by payroll_reversal_reconciliation_reviews_guard), under the shared compensation lock every
     * link change takes exclusively.
     *
     * @return 'not_independent'|'same_person'|'self_beneficiary'|null
     */
    public function conflict(PayrollReversalReconciliation $obligation, PayrollRun $run, User $actor): ?string
    {
        $subject = $this->beneficiaries->ofObligation($obligation->school_id, $obligation->id, $actor->id);
        if ($this->tookPart($obligation, $run, $actor->id, $subject->authored)) {
            return 'not_independent';
        }
        foreach ($this->selfAdministration->knownSamePersonAccounts($obligation->school_id, $actor->id) as $alias) {
            if ($this->tookPart($obligation, $run, $alias, $this->beneficiaries->ofObligation($obligation->school_id, $obligation->id, $alias)->authored)) {
                return 'same_person';
            }
        }

        return $subject->conflicted ? 'self_beneficiary' : null;
    }

    /** The run's makers, its approver, the reverser, or an author of a change in the obligation. */
    private function tookPart(PayrollReversalReconciliation $obligation, PayrollRun $run, string $userId, bool $authored): bool
    {
        return $userId === $run->approved_by_user_id || $userId === $obligation->reversed_by_user_id
            || $authored || $this->runs->participants($run)->participationOf($userId) !== null;
    }
}
