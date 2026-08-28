<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0G.5 (rule 19: "the goal is idempotent business effect", never
 * "exactly-once delivery"). `Recognized` -- this call's own INSERT won
 * the durable claim and created a new Payment. `DuplicateReplay` -- an
 * identical (school, provider, provider_event_id) with matching content
 * was already processed by an earlier call; the earlier Payment is
 * returned verbatim, no second business effect occurs.
 */
enum PaymentProviderEventOutcome: string
{
    case Recognized = 'recognized';
    case DuplicateReplay = 'duplicate_replay';
}
