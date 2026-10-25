<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * ORG.9D-2 (ADR 0076 §74): an operation a fee reminder never supports --
 * scheduling (reminders are manual, OD-9.6), Emergency dispatch, a channel
 * other than email, or publication by anyone but its own initiator (whose
 * Finance authority the population is read under), a Required mark (a
 * reminder respects each Guardian's email preference and consent), approval
 * by its own preparer, or a class filter with no active academic year.
 */
class FeeReminderNotAllowedException extends CommunicationException
{
    public static function scheduling(): self
    {
        return new self('A fee reminder is sent manually; it cannot be scheduled.');
    }

    public static function emergency(): self
    {
        return new self('A fee reminder cannot be an Emergency communication.');
    }

    public static function publisher(): self
    {
        return new self('Only the person who prepared this fee reminder can send it.');
    }

    public static function classUnavailable(): self
    {
        return new self('There is no active academic year, so the class in this fee reminder cannot be resolved; it was not sent.');
    }

    public static function required(): self
    {
        return new self('A fee reminder cannot be marked Required; it respects each guardian\'s email choices.');
    }

    public static function selfApproval(): self
    {
        return new self('The person who prepared a fee reminder cannot approve it.');
    }

    public static function selection(): self
    {
        return new self('A fee reminder needs a fee selection.');
    }
}
