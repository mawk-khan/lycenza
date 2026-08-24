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
 */
enum CommunicationAudienceType: string
{
    case Individual = 'individual';
    case SchoolWide = 'school_wide';
    case Student = 'student';
    case Guardian = 'guardian';
    case GuardiansOfStudents = 'guardians_of_students';
}
