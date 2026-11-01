<?php

namespace App\Domain\Students\Application\Bulk;

use App\Domain\Students\Application\Exceptions\StudentException;

/**
 * SIS 11.13: the requested bulk move as a whole is not one this workflow
 * performs (another year, another Grade, the wrong kind of class, an expired
 * or altered preview). Nothing was read beyond the operator's own reach and
 * nothing was moved. `rollover` marks the refusals that belong to
 * Academic-Year Rollover, so the page can link to it.
 */
final class BulkPlacementRefusedException extends StudentException
{
    public function __construct(string $message, public readonly bool $rollover = false)
    {
        parent::__construct(422, 'BULK_PLACEMENT_REFUSED', $message);
    }

    public static function crossYear(): self
    {
        return new self('A bulk move stays within one Academic Year. To move Students into another Academic Year, use Academic-Year Rollover.', true);
    }

    public static function gradeChange(): self
    {
        return new self('A bulk move keeps each Student\'s Grade. Moving to another Grade is promotion, done through Academic-Year Rollover.', true);
    }

    public static function classes(): self
    {
        return new self('Choose the source and target classes again.');
    }
}
