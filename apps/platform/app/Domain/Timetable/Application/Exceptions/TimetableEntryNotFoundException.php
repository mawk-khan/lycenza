<?php

namespace App\Domain\Timetable\Application\Exceptions;

class TimetableEntryNotFoundException extends TimetableException
{
    public function __construct(?string $entryId = null)
    {
        parent::__construct(404, 'TIMETABLE_ENTRY_NOT_FOUND', $entryId !== null
            ? "TimetableEntry '{$entryId}' was not found for this School."
            : 'TimetableEntry was not found for this School.');
    }
}
