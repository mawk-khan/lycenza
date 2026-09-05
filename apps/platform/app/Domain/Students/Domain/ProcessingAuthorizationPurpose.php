<?php

namespace App\Domain\Students\Domain;

/**
 * Phase 0H.4D-P2 -- the closed vocabulary of purposes a
 * StudentProcessingAuthorization can cover. Deliberately narrow: this
 * is NOT an "all Student processing" registry -- Communications
 * consent and Guardian-portal account-linking already separately own
 * their own narrower facts and must never be proxied by this one.
 * `AcademicRecords` is the only value this checkpoint needs (the
 * future StudentMark checkpoint); additional closed values may be
 * added later without a redesign, but are never invented speculatively
 * ahead of an actual consumer.
 */
enum ProcessingAuthorizationPurpose: string
{
    case AcademicRecords = 'academic_records';
}
