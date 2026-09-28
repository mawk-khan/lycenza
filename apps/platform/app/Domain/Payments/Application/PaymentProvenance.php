<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Domain\PaymentSource;

/**
 * Phase 0O.11A: the source-specific columns of one `payments` row, built
 * only by its ingress -- `SettledPaymentRecorder` persists them without
 * knowing how the ingress established authority. The two shapes mirror
 * `payments_source_shape_check` exactly: a provider row carries provider
 * evidence and no manual column; a manual row carries its recording User,
 * method and request key and no provider column.
 */
final class PaymentProvenance
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    private function __construct(
        public readonly PaymentSource $source,
        private readonly array $attributes,
    ) {}

    public static function provider(string $provider, string $providerPaymentReference, string $providerEventId): self
    {
        return new self(PaymentSource::Provider, [
            'provider' => $provider,
            'provider_payment_reference' => $providerPaymentReference,
            'provider_event_id' => $providerEventId,
        ]);
    }

    public static function manual(ManualPaymentMethod $method, ?string $reference, string $recordedByUserId, string $idempotencyKey): self
    {
        return new self(PaymentSource::Manual, [
            'method' => $method->value,
            'manual_reference' => $reference,
            'recorded_by_user_id' => $recordedByUserId,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    public function columns(): array
    {
        return ['source' => $this->source->value, ...$this->attributes];
    }
}
