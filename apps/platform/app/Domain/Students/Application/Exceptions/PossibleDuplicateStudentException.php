<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 2.3: the new Student's name and date of birth match one or more
 * existing Students of the same School (StudentDuplicateCandidates), and the
 * request did not acknowledge exactly this candidate set. Nothing was
 * written. A warning, not a verdict: resubmitting with `acknowledgement`
 * creates a distinct Student.
 *
 * Carries candidate ids (for a page that re-reads what the actor may see)
 * and the acknowledgement; the API envelope exposes only the count and the
 * acknowledgement (details()), never names or ids.
 */
class PossibleDuplicateStudentException extends StudentException
{
    /**
     * @param  list<string>  $candidateIds
     */
    public function __construct(
        public readonly array $candidateIds,
        public readonly string $acknowledgement,
    ) {
        parent::__construct(
            409,
            'POSSIBLE_DUPLICATE_STUDENT',
            'A Student with the same name and date of birth already exists at this School. Check the existing record; to create a different Student, resubmit with the duplicate acknowledgement.',
        );
    }

    /**
     * @return array{candidateCount: int, duplicateAcknowledgement: string}
     */
    public function details(): array
    {
        return ['candidateCount' => count($this->candidateIds), 'duplicateAcknowledgement' => $this->acknowledgement];
    }
}
