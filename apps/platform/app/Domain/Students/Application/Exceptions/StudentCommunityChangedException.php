<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 11.8: a Club or Activity, or a Student's membership or responsibility
 * in one, changed after this form was opened (its version). Nothing was
 * written; no value appears in the message (SIS 11.H1 wording).
 */
class StudentCommunityChangedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_COMMUNITY_CHANGED', 'This record changed after you opened it. Nothing was saved. Reload and review the latest information before saving again.');
    }
}
