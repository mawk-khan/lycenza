<?php

namespace App\Domain\Timetable\Application\Exceptions;

class RoomNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_ROOM_NOT_AVAILABLE', 'This Room is not active and cannot be scheduled.');
    }
}
