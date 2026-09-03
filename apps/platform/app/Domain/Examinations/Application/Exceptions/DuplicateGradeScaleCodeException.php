<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * This School already has a GradeScale with this normalized code.
 *
 * The authoritative guarantee is the database's own unconditional
 * expression index `grade_scales_school_id_code_ci_unique` -- never an
 * application check-then-insert. The service translates ONLY that
 * specific named constraint's violation into this exception; any other
 * unique violation stays an unexpected failure rather than being
 * silently mislabelled.
 */
class DuplicateGradeScaleCodeException extends ExaminationException
{
    public function __construct(public readonly string $gradeScaleCode)
    {
        parent::__construct(422, 'GRADE_SCALE_DUPLICATE_CODE', "A GradeScale with the code '{$gradeScaleCode}' already exists in this School.");
    }
}
