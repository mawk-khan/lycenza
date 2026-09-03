<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * This Section already has a delivery record for this SyllabusUnit.
 *
 * The authoritative guarantee is the database's own
 * `curriculum_deliveries_section_unit_unique` index -- never an
 * application check-then-insert, which would leave a race window
 * (CLAUDE.md rule 30's principle). The service translates ONLY that
 * specific named constraint's violation into this exception; any other
 * unique violation stays an unexpected failure rather than being
 * silently mislabelled as a duplicate.
 */
class DuplicateDeliveryException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $sectionId, public readonly string $syllabusUnitId)
    {
        parent::__construct(422, 'CURRICULUM_DELIVERY_DUPLICATE', 'This Section already has a delivery record for this SyllabusUnit; correct or transition the existing one.');
    }
}
