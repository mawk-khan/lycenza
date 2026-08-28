<?php

namespace App\Domain\Visitor\Application\Exceptions;

/**
 * A deactivated Visitor directory record cannot receive a new
 * check-in -- an ordinary reference-lifecycle rule (VISITOR.md
 * "Active/inactive is not blocklisting"), not a security decision.
 */
class VisitorNotEligibleException extends VisitorException
{
    public function __construct()
    {
        parent::__construct(422, 'VISITOR_NOT_ELIGIBLE', 'This Visitor is inactive and cannot be checked in. Reactivate the Visitor first.');
    }
}
