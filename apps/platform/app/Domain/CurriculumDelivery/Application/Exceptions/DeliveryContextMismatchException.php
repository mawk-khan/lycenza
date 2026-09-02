<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * The chosen Section and SyllabusUnit do not describe one coherent
 * class: either the Unit's SubjectOffering sits in a different
 * AcademicYear/Campus/GradeLevel than the Section, or the two simply
 * belong to unrelated contexts.
 *
 * The database is the authoritative guard here -- the three composite
 * foreign keys reject the row outright, even via raw SQL. This
 * exception exists so the ORDINARY mismatched submission returns a
 * clean 422 rather than a raw constraint violation, exactly as
 * `NormalizesCodeInput` + `Rule::unique` do for Syllabus codes.
 */
class DeliveryContextMismatchException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_CONTEXT_MISMATCH', "The Section and SyllabusUnit do not describe the same class: {$reason}.");
    }
}
