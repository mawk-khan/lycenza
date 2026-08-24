<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5B.3 -- whether an academic-cohort Announcement (`audience_type
 * = grade`/`section`) targets the cohort's currently-enrolled Students
 * directly, or their eligible Guardians (via
 * App\Domain\Communications\Application\Audience\GuardianProjectionResolver).
 */
enum CommunicationAcademicCohortRecipientKind: string
{
    case Student = 'student';
    case Guardian = 'guardian';
}
