<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * ORG.7E-1 (ADR 0076 §43): the ONE answer a Campus-scoped HR actor gets for
 * an assignment change that cannot happen in their scope -- a destination
 * Campus outside it or no longer active, an employment that is not current,
 * an end date before today, a manager outside scope, or a lost race. It never
 * names another Campus or record (CLAUDE.md rule 101).
 */
class HrAssignmentUnavailableException extends HrException
{
    public const string CODE = 'HR_ASSIGNMENT_UNAVAILABLE';

    public function __construct()
    {
        parent::__construct(422, self::CODE, 'This assignment change cannot be made here right now.');
    }
}
