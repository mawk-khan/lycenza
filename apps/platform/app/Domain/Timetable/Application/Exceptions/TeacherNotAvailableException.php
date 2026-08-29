<?php

namespace App\Domain\Timetable\Application\Exceptions;

class TeacherNotAvailableException extends TimetableException
{
    public function __construct()
    {
        parent::__construct(422, 'TIMETABLE_TEACHER_NOT_AVAILABLE', 'This Employee is not an active record and cannot be scheduled as a teacher.');
    }
}
