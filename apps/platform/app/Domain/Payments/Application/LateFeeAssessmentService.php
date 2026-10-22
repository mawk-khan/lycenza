<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\Exceptions\LateFeeAssessmentAlreadyVoidedException;
use App\Domain\Payments\Application\Exceptions\LateFeeAssessmentNotFoundException;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FEE.5 (ADR 0062 §16.3 -> §11.3): the one way to cancel a late fee. In one
 * transaction it voids the late-fee assessment and cancels its late-fee
 * charge through `ChargeService::cancel` (a Finance reversal), keeping
 * every charge-cancellation rule -- refused when a payment is allocated to
 * the late fee. The database backs both halves
 * (`charges_late_fee_guard_trigger`,
 * `late_fee_assessments_void_requires_cancelled_charge`).
 *
 * Voiding frees `late_fee_assessments_one_live_per_rule`, so one
 * deliberate later run may assess that source charge and rule again --
 * the only re-assessment path; nothing recurs by itself. Authorization:
 * `finance.charges.manage`, the charge-cancel authority (the FEE.2 void
 * precedent). No refund or payment reversal exists.
 */
class LateFeeAssessmentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** ORG.8G (ADR 0076 §65): a listed reason, the note for `other`, and the actor's fresh MFA proof. */
    public function void(School $school, string $assessmentId, User $actor, string $reasonCode, ?string $reason, FreshMfaProof $mfa): LateFeeAssessment
    {
        $this->authorizeCapabilityFor($actor, ChargeService::CANCEL_CAPABILITY, $school);
        ChargeCancellationReason::require(ChargeCancellationReason::LATE_FEE_VOID, $reasonCode, $reason);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assessmentId, $actor, $reasonCode, $reason, $mfa) {
            $assessment = Str::isUuid($assessmentId)
                ? LateFeeAssessment::query()->where('school_id', $school->id)->lockForUpdate()->find($assessmentId)
                : null;
            if ($assessment === null) {
                throw new LateFeeAssessmentNotFoundException($assessmentId);
            }
            if (! $assessment->isLive()) {
                throw new LateFeeAssessmentAlreadyVoidedException($assessment->id);
            }

            $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255);
            $assessment->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => $actor->id,
                'void_reason' => $reason ?? $reasonCode,
            ])->save();

            $this->charges->cancel($school, $assessment->charge_id, $actor, ChargeCancellationReason::LATE_FEE_VOID, $reasonCode, $reason, $mfa);

            $this->audit->school($school, 'late_fee_assessment.voided', actor: $actor, subject: $assessment, metadata: [
                'lateFeeAssessmentId' => $assessment->id,
                'chargeId' => $assessment->charge_id,
                'sourceChargeId' => $assessment->source_charge_id,
            ]);

            return $assessment->refresh();
        }));
    }
}
