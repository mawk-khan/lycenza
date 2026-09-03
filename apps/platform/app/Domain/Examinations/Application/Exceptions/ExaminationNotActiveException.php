<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * A new ExaminationPaper may only be created under an ACTIVE Examination,
 * and an existing Paper may only be reactivated (inactive -> active)
 * while its Examination is currently active. Ordinary corrections to an
 * existing Paper (not a reactivation) are deliberately NOT guarded by
 * this rule -- historical correction remains possible even after the
 * parent Examination becomes inactive.
 *
 * Module-local: never imported from Timetable or any other module's
 * analogous exception (CLAUDE.md rule 4).
 */
class ExaminationNotActiveException extends ExaminationException
{
    public function __construct(public readonly string $examinationId)
    {
        parent::__construct(422, 'EXAMINATION_NOT_ACTIVE', 'This Examination is not active.');
    }
}
