<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 (rule 23): a settlement event arrived under a NEW/distinct
 * `provider_event_id` (so it is not a duplicate-event-delivery replay),
 * but a Payment for the SAME (school, provider, provider_payment_reference)
 * -- the same underlying provider transaction -- was already recognized
 * by an earlier event. `payments_provider_reference_unique` is the
 * database-authoritative guarantee; this exception is the caller-visible
 * translation. Never silently creates a second Payment for one provider
 * transaction.
 */
class DuplicateProviderPaymentReferenceException extends PaymentsException
{
    public function __construct(string $provider, string $providerPaymentReference)
    {
        parent::__construct(
            409,
            'DUPLICATE_PROVIDER_PAYMENT_REFERENCE',
            "A payment for provider '{$provider}' reference '{$providerPaymentReference}' was already recognized."
        );
    }
}
