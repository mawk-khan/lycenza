<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SIS 11.H1: a Section edit was prepared against a version that is no longer
 * current (SectionVersion). It is refused, never applied over the newer
 * state; no value appears in the message.
 */
class SectionChangedException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'SECTION_CHANGED',
            'This record changed after you opened it. Reload and review the latest information before saving again.',
        );
    }
}
