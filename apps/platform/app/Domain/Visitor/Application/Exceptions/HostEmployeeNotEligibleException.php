<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * An inactive Employee cannot be selected as a host for a NEW visit
 * (checkpoint brief section 12: "Prefer active Employee only"). A
 * historical Visit that already named this Employee is unaffected --
 * this exception only ever fires on check-in.
 */
class HostEmployeeNotEligibleException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(422, 'VISITOR_HOST_EMPLOYEE_NOT_ELIGIBLE', 'This Employee is inactive and cannot be selected as a host.');
    }
}
