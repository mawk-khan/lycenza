<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.2 §9: only audience types resolvable correctly from data
 * that actually exists today. `CampusWide` is deliberately NOT a case
 * here -- no reliable Campus<->SchoolMembership relationship exists
 * yet to resolve it safely (docs/communication-hub/
 * PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md documents why and what a
 * future `CampusWide` case needs). Class/Section/Grade/... audience
 * types remain future enum cases added when their identity
 * foundations land -- adding a case is additive to the CHECK
 * constraint, never destructive.
 *
 * Phase 5B.1 adds `Student`/`Guardian`/`GuardiansOfStudents` -- the
 * Student/Guardian domain identities established by Phase 1A. See
 * docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md.
 *
 * Phase 5B.3 adds `Grade`/`Section` -- academic-cohort audiences
 * resolved against Phase 1B's `student_enrollments` (current
 * placement). Deliberately NOT four separate cases (no
 * `GuardiansOfGrade`/`GuardiansOfSection`) -- the Student-vs-Guardian
 * projection is a `recipient_kind` field on the authored cohort
 * definition (`communication_announcement_academic_cohorts`), not a
 * distinct audience type, keeping the composable model brief §5
 * calls for. There is no independent Class entity in the current
 * academic model (GradeLevel/Section are it) -- see
 * docs/communication-hub/PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md.
 *
 * Phase 5C.1 adds `SubjectOffering` -- a third academic-cohort case,
 * sharing the same `communication_announcement_academic_cohorts` row
 * shape/`recipient_kind` projection as Grade/Section (still no
 * `GuardiansOfSubjectOffering` case). Resolved against
 * App\Domain\Students\Application\SubjectOfferingRosterReadService
 * (Phase 1C), never against `student_enrollments`/
 * `student_subject_enrollments` directly -- see
 * docs/communication-hub/PHASE-5C-1-SUBJECT-OFFERING-AUDIENCES.md.
 */
enum CommunicationAudienceType: string
{
    case Individual = 'individual';
    case SchoolWide = 'school_wide';
    case Student = 'student';
    case Guardian = 'guardian';
    case GuardiansOfStudents = 'guardians_of_students';
    case Grade = 'grade';
    case Section = 'section';
    case SubjectOffering = 'subject_offering';
}
