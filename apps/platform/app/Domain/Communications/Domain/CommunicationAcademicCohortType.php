<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5B.3 -- which academic-structure entity an academic-cohort
 * Announcement targets. No `Class` case exists: the current School OS
 * academic model has no independent Class entity distinct from
 * GradeLevel/Section (docs/communication-hub/
 * PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md).
 *
 * Phase 5C.1 adds `SubjectOffering` -- a SubjectOffering's current
 * roster (required or elective) resolved through Phase 1C's
 * SubjectOfferingRosterReadService (docs/communication-hub/
 * PHASE-5C-1-SUBJECT-OFFERING-AUDIENCES.md).
 */
enum CommunicationAcademicCohortType: string
{
    case GradeLevel = 'grade_level';
    case Section = 'section';
    case SubjectOffering = 'subject_offering';
}
