<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * ORG.8C (ADR 0076 §61): a correction request or decision the workflow
 * refuses. Fixed texts: a decision conflict never says why (no relationship
 * oracle); nothing is written.
 */
class PaymentCorrectionRequestRefusedException extends PaymentsException
{
    public static function notFound(): self
    {
        return new self(404, 'PAYMENT_CORRECTION_REQUEST_NOT_FOUND', 'That correction request was not found.');
    }

    public static function notPending(string $status): self
    {
        return new self(409, 'PAYMENT_CORRECTION_REQUEST_DECIDED', "This correction request is already {$status}.");
    }

    public static function ownRequest(): self
    {
        return new self(403, 'PAYMENT_CORRECTION_SELF_DECISION', 'You requested this correction; another person must decide it.');
    }

    public static function decisionConflict(): self
    {
        return new self(403, 'PAYMENT_CORRECTION_DECISION_CONFLICT', 'This correction must be decided by someone else.');
    }

    public static function notRequester(): self
    {
        return new self(403, 'PAYMENT_CORRECTION_NOT_REQUESTER', 'Only the person who requested this correction can withdraw it.');
    }

    public static function referenceInvalid(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_REQUEST_REFERENCE_INVALID', 'One of the selected charges or accounts was not found.');
    }

    public static function idempotencyConflict(): self
    {
        return new self(409, 'PAYMENT_CORRECTION_REQUEST_KEY_CONFLICT', 'This form was already submitted with different details. Reload the page and try again.');
    }
}
