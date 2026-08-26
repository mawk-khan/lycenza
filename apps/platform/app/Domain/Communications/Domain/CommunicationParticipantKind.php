<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5D.1 §8/§9 -- which domain capacity an authenticated
 * CommunicationThreadParticipant (always a SchoolMembership-backed
 * User, unchanged from Phase 5A.1) joined a Thread in. Optional
 * metadata layered on top of the one authenticated endpoint, never a
 * second identity -- see the creating migration's docblock.
 */
enum CommunicationParticipantKind: string
{
    case Membership = 'membership';
    case Guardian = 'guardian';
    case Student = 'student';
}
