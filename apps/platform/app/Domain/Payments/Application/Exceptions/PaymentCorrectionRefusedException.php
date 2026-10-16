<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * UAT-C3: a payment correction that the finance rules refuse -- an ordinary,
 * expected outcome shown to the user, never a 500. Each refusal has its own
 * stable code; nothing is written.
 */
class PaymentCorrectionRefusedException extends PaymentsException
{
    public static function reasonRequired(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_REASON_REQUIRED', 'Give a reason for this correction (5 to 500 characters).');
    }

    public static function invalidAmount(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_AMOUNT_INVALID', 'Enter an amount greater than zero with at most two decimal places.');
    }

    public static function alreadyReversed(): self
    {
        return new self(409, 'PAYMENT_ALREADY_REVERSED', 'This payment has been reversed. It cannot be corrected further.');
    }

    public static function exceedsPayment(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_EXCEEDS_PAYMENT', 'That is more than this payment currently has on that charge.');
    }

    public static function invalidDestination(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_DESTINATION_INVALID', 'Choose a different, uncancelled charge of the same Student.');
    }

    public static function destinationFull(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_DESTINATION_FULL', 'That charge does not have enough outstanding to take this amount.');
    }

    public static function nothingToReverse(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_NOTHING_TO_REVERSE', 'Nothing of this payment is left on any charge, so there is nothing to reverse.');
    }

    public static function invalidAccount(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_ACCOUNT_INVALID', 'Choose an active asset account the money was paid out of.');
    }

    public static function invalidDate(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_DATE_INVALID', 'The refund date must be on or after the payment date and not in the future.');
    }

    public static function invalidMethod(): self
    {
        return new self(422, 'PAYMENT_CORRECTION_METHOD_INVALID', 'Choose how the money was paid back: cash, bank transfer or cheque.');
    }
}
