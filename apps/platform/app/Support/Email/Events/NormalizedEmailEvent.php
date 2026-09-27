<?php

namespace App\Support\Email\Events;

use Carbon\CarbonImmutable;

/**
 * One provider event after its adapter authenticated and normalized it.
 * No address, no School, no raw payload: the School is found later from
 * the stored message, and the payload is not retained (ADR 0055 §11).
 */
final class NormalizedEmailEvent
{
    public function __construct(
        public readonly string $eventKey,
        public readonly EmailEventType $type,
        public readonly ?string $providerMessageId,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $bounceClass = null,
    ) {}

    /**
     * ADR 0055 section 11.3: the key when a vendor has no stable event id.
     * (`recipient_hash` from the ADR is used only by adapters whose events
     * carry a recipient; the fields here are what every event carries.)
     */
    public static function fingerprint(string $provider, ?string $providerMessageId, EmailEventType $type, ?CarbonImmutable $occurredAt, string $extra = ''): string
    {
        return 'fp-'.hash('sha256', implode('|', [$provider, (string) $providerMessageId, $type->value, $occurredAt?->toIso8601String() ?? '', $extra]));
    }
}
