<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * This AcademicYear already has an Examination with this normalized
 * code.
 *
 * The authoritative guarantee is the database's own unconditional
 * expression index `examinations_year_code_ci_unique` -- never an
 * application check-then-insert, which would leave a race window
 * (CLAUDE.md rule 30's principle). The service translates ONLY that
 * specific named constraint's violation into this exception; any other
 * unique violation stays an unexpected failure rather than being
 * silently mislabelled as a duplicate code.
 *
 * The same normalized code in a DIFFERENT AcademicYear is legitimate
 * and accepted -- "MID1" recurs every year as a distinct row.
 */
class DuplicateExaminationCodeException extends ExaminationException
{
    // Deliberately NOT named `$code`: Exception already declares a
    // non-readonly `$code` property, and redeclaring it as readonly is
    // a fatal error.
    public function __construct(public readonly string $examinationCode)
    {
        parent::__construct(422, 'EXAMINATION_DUPLICATE_CODE', "An Examination with the code '{$examinationCode}' already exists in this AcademicYear.");
    }
}
