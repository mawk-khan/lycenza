<?php

namespace App\Domain\AcademicStructure\Application\Calendar;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;

/**
 * SIS 5.2: work another module is doing INTO a draft Academic Year that its
 * activation must not cut in half -- today, an enrollment rollover that is
 * executing into it (a multi-transaction run). AcademicStructure asks every
 * blocker while activating, under the year's FOR UPDATE lock; implementations
 * live in the owning modules (rule 4). Registered under the container tag
 * `TAG`. A blocker only answers.
 */
interface AcademicYearActivationBlocker
{
    public const TAG = 'academic_structure.year_activation_blockers';

    /** The refusal the operator reads, or null when activation may proceed. */
    public function blocksActivation(AcademicYear $year): ?string;
}
