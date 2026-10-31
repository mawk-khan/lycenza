<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 11.7: the Student's House membership changed after this form was
 * opened (StudentHouseVersion), or a House changed under a House form.
 * Nothing was written; no value appears in the message.
 */
class StudentHouseChangedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_HOUSE_CHANGED', 'This record changed after you opened it. Nothing was saved. Reload and review the latest information before saving again.');
    }
}
