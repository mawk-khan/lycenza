<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 (rule 22/64, security-critical): the SAME
 * (school_id, provider, provider_event_id) was redelivered with
 * DIFFERENT normalized content (amount/currency/provider_payment_reference/
 * event_type/occurred_at) than the already-persisted original. The
 * persisted original is NEVER mutated to match the new payload, and no
 * second Payment/allocation/ledger effect is ever created for the
 * conflicting delivery -- this exception is the caller-visible signal
 * that something (a misbehaving/compromised provider, a bug, a replay
 * attack) sent inconsistent data under an event id already claimed.
 */
class ProviderEventContentConflictException extends PaymentsException
{
    public function __construct(string $provider, string $providerEventId)
    {
        parent::__construct(
            409,
            'PROVIDER_EVENT_CONTENT_CONFLICT',
            "Provider event '{$providerEventId}' from '{$provider}' was already recorded with different content; the original is authoritative and unchanged."
        );
    }
}
