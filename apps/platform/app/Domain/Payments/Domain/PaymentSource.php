<?php

namespace App\Domain\Payments\Domain;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 1): how a
 * Payment entered Lycenza. Mirrors `payments_source_check`.
 *
 * `Provider` -- recognized from a verified provider event through
 * `PaymentProviderEventService` (a real `payment_provider_events` row).
 * `Manual` -- recorded by an authorized School user through
 * `ManualPaymentRecordingService` for money already received outside
 * Lycenza; never backed by a provider event.
 */
enum PaymentSource: string
{
    case Provider = 'provider';
    case Manual = 'manual';
}
