<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * This Examination already has an ExaminationPaper for this
 * SubjectOffering.
 *
 * The authoritative guarantee is the database's own unconditional unique
 * constraint `examination_papers_examination_offering_unique` -- never an
 * application check-then-insert, which would leave a race window
 * (CLAUDE.md rule 30's principle). The service translates ONLY that
 * specific named constraint's violation into this exception; any other
 * unique violation stays an unexpected failure rather than being silently
 * mislabelled as a duplicate paper.
 */
class DuplicateExaminationPaperException extends ExaminationException
{
    public function __construct(public readonly string $examinationId, public readonly string $subjectOfferingId)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_DUPLICATE', 'An ExaminationPaper for this Examination and SubjectOffering already exists.');
    }
}
