<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAcademicCohortType;

/**
 * Phase 5B.3 -- the caller-supplied academic-cohort audience selection
 * for `audience_type = grade`/`section`/`subject_offering` (Phase 5C.1
 * adds the third), validated and persisted by
 * App\Domain\Communications\Application\AnnouncementService::syncAcademicCohort().
 * Exactly one of `gradeLevelId`/`sectionId`/`subjectOfferingId` is
 * meaningful, matching `cohortType`.
 */
final class AcademicCohortSelection
{
    public function __construct(
        public readonly CommunicationAcademicCohortType $cohortType,
        public readonly string $academicYearId,
        public readonly ?string $gradeLevelId,
        public readonly ?string $sectionId,
        public readonly CommunicationAcademicCohortRecipientKind $recipientKind,
        public readonly ?string $subjectOfferingId = null,
    ) {}
}
