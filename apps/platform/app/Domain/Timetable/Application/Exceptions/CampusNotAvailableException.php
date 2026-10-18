<?php

namespace App\Domain\Timetable\Application\Exceptions;

/**
 * ORG.7D-0 R5 (ADR 0076 §36): nothing new is scheduled on an inactive
 * Campus (create, update or re-activation). Existing entries there are
 * kept, and can still be deactivated.
 */
class CampusNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_CAMPUS_NOT_AVAILABLE', 'This Campus is not active, so nothing new can be scheduled there.');
    }
}
