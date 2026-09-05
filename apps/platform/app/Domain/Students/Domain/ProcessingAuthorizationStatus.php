<?php

namespace App\Domain\Students\Domain;

/**
 * Phase 0H.4D-P2 -- the closed lifecycle of one
 * StudentProcessingAuthorization row. `Recorded` rows are grants
 * (`terminates_authorization_id IS NULL`); the other three are
 * TERMINAL EVENTS pointing at the grant they end
 * (`terminates_authorization_id IS NOT NULL`) -- never a status flip
 * on the grant row itself (see the creating migration's docblock).
 * `Withdrawn` (the consent provider ended it) and `Revoked`
 * (administrative invalidation) are deliberately distinct despite the
 * identical structural shape.
 */
enum ProcessingAuthorizationStatus: string
{
    case Recorded = 'recorded';
    case Withdrawn = 'withdrawn';
    case Revoked = 'revoked';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return $this !== self::Recorded;
    }
}
