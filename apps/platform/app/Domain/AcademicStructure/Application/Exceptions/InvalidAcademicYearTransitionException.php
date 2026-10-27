<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D sections 16-18: only `draft` -> `active` (activation) and
 * `active` -> `closed` (closing) are valid transitions in this
 * checkpoint. Reopening a closed year and archiving are deliberately
 * out of scope (section 18) -- see docs/modules/ACADEMIC-STRUCTURE.md.
 */
class InvalidAcademicYearTransitionException extends AcademicStructureException
{
    public function __construct(string $from, string $to)
    {
        // SIS 9.1: the operator's words, never status keys (same code).
        $now = ['draft' => 'is still a draft', 'active' => 'is already active', 'closed' => 'is closed'][$from] ?? 'has changed';
        $action = ['active' => 'activated', 'closed' => 'closed', 'draft' => 'returned to draft'][$to] ?? 'changed this way';

        parent::__construct(
            422,
            'INVALID_ACADEMIC_YEAR_TRANSITION',
            "This academic year {$now}, so it can't be {$action}.",
        );
    }
}
