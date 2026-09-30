<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1 (ADR 0063 section 6): `user_id` is an authorization input and is
 * no longer a generic Employee field. EmployeeService::update() refuses
 * it outright -- never silently drops it -- and points the caller at the
 * explicit, audited link/unlink operations.
 */
class EmployeeUserLinkNotEditableException extends HrException
{
    public function __construct()
    {
        parent::__construct(422, 'HR_USER_LINK_NOT_EDITABLE', 'An Employee\'s User link cannot be changed through an Employee update; use the link or unlink operation.');
    }
}
