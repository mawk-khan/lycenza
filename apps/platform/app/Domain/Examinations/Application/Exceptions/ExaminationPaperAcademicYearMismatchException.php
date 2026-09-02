<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * The Examination and SubjectOffering do not belong to the same
 * AcademicYear. The database's composite FKs
 * (`examination_papers_examination_fk`,
 * `examination_papers_subject_offering_fk`, both sharing the same stored
 * `academic_year_id`) are the authoritative, bypass-proof guarantee
 * (CLAUDE.md rule 70); this service-level check exists only so the
 * ordinary case through the API returns a clean 422 instead of a raw
 * foreign-key violation.
 */
class ExaminationPaperAcademicYearMismatchException extends ExaminationException
{
    public function __construct(public readonly string $examinationId, public readonly string $subjectOfferingId)
    {
        parent::__construct(422, 'EXAMINATION_PAPER_ACADEMIC_YEAR_MISMATCH', 'The Examination and SubjectOffering do not belong to the same AcademicYear.');
    }
}
