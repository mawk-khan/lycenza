<?php

namespace App\Domain\Identity\Application\Portal;

/**
 * POR (ADR 0070 §5): who a signed-in User acts as in the portal of ONE
 * School -- resolved fresh from current rows by ActingGuardianResolver on
 * every request, never stored in the session or any cache. It names the
 * Guardian persona only; which Students that persona may reach is
 * GuardianStudentScope's separate, live answer (§4: identity is not
 * authority).
 */
final readonly class ActingGuardian
{
    public function __construct(
        public string $schoolId,
        public string $userId,
        public string $membershipId,
        public string $accountLinkId,
        public string $guardianId,
    ) {}
}
