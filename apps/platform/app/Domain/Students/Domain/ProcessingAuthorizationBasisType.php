<?php

namespace App\Domain\Students\Domain;

/**
 * Phase 0H.4D-P2 -- the three processing bases the approved privacy
 * decision (docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md)
 * distinguishes. Never a `consent = true` boolean, which would
 * destroy this distinction.
 */
enum ProcessingAuthorizationBasisType: string
{
    /** Recorded via a specific legal-guardian StudentGuardianRelationship. Qualifies only while the Student's current age is under 18. */
    case GuardianConsent = 'guardian_consent';

    /** The Student themself is the provider -- no platform User account required. Qualifies only while the Student's current age is 18 or over. */
    case AdultStudentConsent = 'adult_student_consent';

    /** No natural-person provider; the School as Data Fiduciary asserts this basis. Age-independent. */
    case StatutorySchoolPurpose = 'statutory_school_purpose';
}
