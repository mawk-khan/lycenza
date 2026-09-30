<?php

namespace App\Domain\LMS\Application\Exceptions;

/**
 * TCH.5B: the owner Employee of a teacher-owned resource is not an
 * Employee of the resource's School -- refused by the composite
 * (owner_employee_id, school_id) foreign key and translated here.
 */
class LmsOwnerEmployeeInvalidException extends LmsException
{
    public function __construct()
    {
        parent::__construct(422, 'LMS_OWNER_EMPLOYEE_INVALID', 'The owner must be an Employee of this School.');
    }
}
