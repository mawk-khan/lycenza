<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * ORG.7D-5 (ADR 0076 §41): the ONE answer a Campus-scoped Transport actor
 * gets for a rider assignment that cannot happen -- an unknown, inactive,
 * unplaced or other-Campus Student, a Student already assigned (anywhere), a
 * route that moved out of scope, or a lost race. It never names another
 * Campus, route, rider or assignment (CLAUDE.md rule 101).
 */
class TransportAssignmentUnavailableException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_ASSIGNMENT_UNAVAILABLE', 'This Student cannot be assigned to this route right now.');
    }
}
