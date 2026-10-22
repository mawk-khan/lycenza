<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\FeeAssessmentAlreadyVoidedException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentNotFoundException;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FEE.2 (ADR 0062 §11.3): the one way to cancel a charge a fee assessment
 * produced. In one transaction it voids the assessment (freeing the
 * Student x year x fee head x period key for a deliberate re-assessment)
 * and cancels the charge through `ChargeService::cancel` -- which keeps
 * every existing charge-cancellation rule, including the refusal when
 * payments are allocated. The database backs both halves
 * (`charges_fee_assessment_guard_trigger`,
 * `fee_assessments_void_requires_cancelled_charge`).
 *
 * Authorization: `finance.charges.manage`, the existing charge-cancel
 * authority. No refund, credit or payment reversal exists here.
 */
class FeeAssessmentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** ORG.8G (ADR 0076 §65): a listed reason, the note for `other`, and the actor's fresh MFA proof. */
    public function void(School $school, string $feeAssessmentId, User $actor, string $reasonCode, ?string $reason, FreshMfaProof $mfa): FeeAssessment
    {
        $this->authorizeCapabilityFor($actor, ChargeService::CANCEL_CAPABILITY, $school);
        ChargeCancellationReason::require(ChargeCancellationReason::ASSESSMENT_VOID, $reasonCode, $reason);

        return $this->context->withSchool($school, function () use ($school, $feeAssessmentId, $actor, $reasonCode, $reason, $mfa) {
            return DB::transaction(function () use ($school, $feeAssessmentId, $actor, $reasonCode, $reason, $mfa) {
                $assessment = FeeAssessment::query()->where('school_id', $school->id)->lockForUpdate()->find($feeAssessmentId);

                if ($assessment === null) {
                    throw new FeeAssessmentNotFoundException($feeAssessmentId);
                }

                if (! $assessment->isLive()) {
                    throw new FeeAssessmentAlreadyVoidedException($assessment->id);
                }

                $assessment->forceFill([
                    'voided_at' => now(),
                    'voided_by_user_id' => $actor->id,
                    'void_reason' => $reason === null || trim($reason) === '' ? $reasonCode : mb_substr(trim($reason), 0, 255),
                ])->save();

                $this->charges->cancel($school, $assessment->charge_id, $actor, ChargeCancellationReason::ASSESSMENT_VOID, $reasonCode, $reason, $mfa);

                $this->audit->school($school, 'fee_assessment.voided', actor: $actor, subject: $assessment, metadata: [
                    'feeAssessmentId' => $assessment->id,
                    'chargeId' => $assessment->charge_id,
                ]);

                return $assessment->refresh();
            });
        });
    }
}
