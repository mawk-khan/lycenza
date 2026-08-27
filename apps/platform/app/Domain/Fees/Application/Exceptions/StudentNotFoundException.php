<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * Raised uniformly whether a caller-supplied `studentId` genuinely
 * does not exist OR exists only in a different School.
 *
 * `App\Domain\Fees` never reads Students/SIS' `Student` Eloquent model
 * directly (CLAUDE.md rule 4, DOMAIN-MAP.md's Fees->Students/SIS
 * dependency is Application-layer/event-based only where it exists at
 * all) -- there is deliberately no Fees-side pre-check query against
 * `students`. `charges_student_fk` (the composite
 * `(student_id, school_id)` foreign key against `students(id,
 * school_id)`) is the sole, structural source of truth: `ChargeService::assess()`
 * catches ITS specific constraint-violation and raises this exception,
 * never distinguishing "does not exist" from "exists in another
 * School" (the same cross-School "no oracle" principle every other
 * lookup in this repository already establishes).
 */
class StudentNotFoundException extends FeesException
{
    public function __construct(public readonly string $studentId)
    {
        parent::__construct(404, 'STUDENT_NOT_FOUND', "No student with id '{$studentId}' was found in this School.");
    }
}
