<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0O.11A: a manual/offline payment request that fails the
 * Application-layer contract (ADR 0031 implementation amendment sections
 * 3-6) -- `field` names the offending input so an HTTP adapter can attach
 * the message to the right form field.
 */
class InvalidManualPaymentException extends PaymentsException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct(422, 'INVALID_MANUAL_PAYMENT', $message);
    }
}
