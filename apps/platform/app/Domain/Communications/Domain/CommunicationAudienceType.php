<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.2 §9: only audience types resolvable correctly from data
 * that actually exists today. `CampusWide` is deliberately NOT a case
 * here -- no reliable Campus<->SchoolMembership relationship exists
 * yet to resolve it safely (docs/communication-hub/
 * PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md documents why and what a
 * future `CampusWide` case needs). Student/Guardian/Class/Section/
 * Grade/... audience types (brief §9's "must remain architecturally
 * possible") are future enum cases added when their identity
 * foundations land -- adding a case is additive to the CHECK
 * constraint, never destructive.
 */
enum CommunicationAudienceType: string
{
    case Individual = 'individual';
    case SchoolWide = 'school_wide';
}
