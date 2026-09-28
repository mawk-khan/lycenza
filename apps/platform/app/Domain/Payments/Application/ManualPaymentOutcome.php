<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0O.11A: `Recorded` -- this request created the Payment;
 * `DuplicateReplay` -- the same request (same key, same User, same
 * content) already had, and nothing new happened.
 */
enum ManualPaymentOutcome: string
{
    case Recorded = 'recorded';
    case DuplicateReplay = 'duplicate_replay';
}
