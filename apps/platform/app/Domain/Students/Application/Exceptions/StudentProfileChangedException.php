<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS.EXP-1 (SIS.EXP-0 finding L-2): the Student's identity changed after
 * this form was opened (StudentProfileVersion). Nothing was written; no
 * value appears in the message.
 */
class StudentProfileChangedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_PROFILE_CHANGED', 'This Student\'s details were changed by someone else after you opened this form. Nothing was saved. Reload the page, review the current details and make your change again.');
    }
}
