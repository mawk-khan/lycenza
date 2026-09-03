<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * The submitted record set is not EXACTLY the authoritative as-of-date roster. Complete-register discipline: no omitted member, no extra enrollment, no partial save, and never an implicit default-to-present.
 */
class RegisterDoesNotMatchRosterException extends AttendanceException
{
    /**
     * @param  list<string>  $missing  roster members absent from the payload
     * @param  list<string>  $unexpected  payload entries not on the roster
     */
    public function __construct(public readonly array $missing, public readonly array $unexpected)
    {
        parent::__construct(422, 'ATTENDANCE_REGISTER_DOES_NOT_MATCH_ROSTER', 'The submitted register does not exactly match this class roster ('.count($missing).' missing, '.count($unexpected).' unexpected).');
    }
}
