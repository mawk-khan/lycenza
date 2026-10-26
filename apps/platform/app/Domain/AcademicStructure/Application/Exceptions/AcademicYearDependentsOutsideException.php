<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SIS 5.1 (MC-3): correcting a draft Academic Year's dates would leave
 * existing records outside the year. Nothing is moved, truncated or ended for
 * the operator: they correct the dependent records first. Counts and kinds
 * only -- never an id or a person's name.
 */
class AcademicYearDependentsOutsideException extends AcademicStructureException
{
    public function __construct(string $message)
    {
        parent::__construct(422, 'ACADEMIC_YEAR_DEPENDENTS_OUTSIDE', $message);
    }

    public static function terms(int $count): self
    {
        $terms = $count === 1 ? '1 Term' : "{$count} Terms";

        return new self("Correct the Term dates first: {$terms} would fall outside the proposed Academic Year dates.");
    }

    public static function records(string $kind, int $count): self
    {
        return new self("These dates cannot be saved: existing {$kind} would fall outside the Academic Year ({$count}). Correct or end them first.");
    }
}
