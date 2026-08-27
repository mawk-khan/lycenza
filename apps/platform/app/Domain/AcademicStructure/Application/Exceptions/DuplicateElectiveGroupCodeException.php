<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 1F.3: translates the database's unique constraint
 * (`elective_groups_code_unique`, scoped to `school_id`/
 * `academic_year_id`/`campus_id`/`grade_level_id`/`code`) into a
 * predictable domain error -- the same code remains freely reusable in
 * a different academic context (a different GradeLevel, a different
 * AcademicYear, ...).
 */
class DuplicateElectiveGroupCodeException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'DUPLICATE_ELECTIVE_GROUP_CODE',
            'This code is already in use by another ElectiveGroup in this academic context.',
        );
    }
}
