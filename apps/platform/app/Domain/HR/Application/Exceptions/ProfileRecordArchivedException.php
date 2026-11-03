<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.2A: the profile record was archived (S-16) and is frozen evidence; it
 * can no longer be edited, verified, made primary or archived again.
 */
class ProfileRecordArchivedException extends HrException
{
    public function __construct()
    {
        parent::__construct(409, 'HR_PROFILE_RECORD_ARCHIVED', 'This record has been archived and can no longer be changed.');
    }
}
