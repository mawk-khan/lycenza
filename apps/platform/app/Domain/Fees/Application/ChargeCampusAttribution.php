<?php

namespace App\Domain\Fees\Application;

use App\Domain\Students\Application\FeeTargetEnrollment;

/**
 * ORG.7G-1A (ADR 0076 §49): the historical Campus a new Charge is attributed
 * to, decided by the Charge's creator from the source context it actually
 * used -- written once to `charges.campus_id` and frozen there
 * (`fees_reject_recognized_charge_mutation`).
 *
 * It is DATA, never authority: it grants nobody anything, and it is never the
 * Student's current Campus. A null Campus means the Charge is whole-School
 * only, never "every Campus".
 *
 * One named constructor per source (no public constructor, so a creator can
 * never pass an arbitrary Campus):
 * - fromEnrollment(): a fee-run Charge -- the stored, locked Enrollment the
 *   run assessed (`fee_assessments.student_enrollment_id`);
 * - fromCharge(): a late fee -- its source Charge's stored Campus;
 * - fromCanteenOutlet(): a Canteen order -- its Outlet's Campus (a NULL,
 *   School-wide Outlet makes a whole-School Charge);
 * - fromLibraryLoan(): a Library fine -- the Loan's Campus frozen at checkout;
 * - placementOnAssessmentDate(): a manual Charge -- resolved by
 *   ChargeService::assess() inside its transaction from the Student's
 *   placement(s) in the Charge's year on the School-local creation date,
 *   share-locked; a unique Campus or null, never guessed.
 */
final class ChargeCampusAttribution
{
    public const string SOURCE_ENROLLMENT = 'enrollment';

    public const string SOURCE_CHARGE = 'charge';

    public const string SOURCE_CANTEEN_OUTLET = 'canteen_outlet';

    public const string SOURCE_LIBRARY_LOAN = 'loan';

    public const string SOURCE_PLACEMENT_ON_DATE = 'placement_on_date';

    private function __construct(
        public readonly string $source,
        public readonly ?string $campusId,
    ) {}

    public static function fromEnrollment(FeeTargetEnrollment $enrollment): self
    {
        return new self(self::SOURCE_ENROLLMENT, $enrollment->campusId);
    }

    public static function fromCharge(LateFeeSourceFacts $source): self
    {
        return new self(self::SOURCE_CHARGE, $source->campusId);
    }

    public static function fromCanteenOutlet(?string $outletCampusId): self
    {
        return new self(self::SOURCE_CANTEEN_OUTLET, $outletCampusId);
    }

    public static function fromLibraryLoan(?string $loanCampusId): self
    {
        return new self(self::SOURCE_LIBRARY_LOAN, $loanCampusId);
    }

    public static function placementOnAssessmentDate(): self
    {
        return new self(self::SOURCE_PLACEMENT_ON_DATE, null);
    }

    /** The Campus is decided by ChargeService::assess() itself, inside its transaction. */
    public function resolvedAtAssessment(): bool
    {
        return $this->source === self::SOURCE_PLACEMENT_ON_DATE;
    }

    /** @internal ChargeService::assess() only: the placement answer it read under lock. */
    public function withResolvedCampus(?string $campusId): self
    {
        return new self($this->source, $campusId);
    }
}
