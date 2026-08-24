<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when authoring a `grade`/`section` audience with a
 * GradeLevel/Section/AcademicYear id that does not exist in this
 * School, or a Section that does not actually belong to the given
 * AcademicYear -- root CLAUDE.md rule 19's "an id alone is not
 * authorization" applied to academic-cohort selection (brief §31: fail
 * deterministically, never silently resolve to an empty audience;
 * brief §52: never leak whether a foreign id exists).
 */
class InvalidAcademicCohortException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('That Grade/Section could not be found for this School and academic year.');
    }
}
