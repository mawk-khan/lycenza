<?php

namespace App\Domain\Students\Application\Import;

use RuntimeException;

/**
 * SIS.EXP-5: the commit re-evaluated the batch and refused it as a whole
 * (an error row, a result that changed since the preview, or warnings not
 * acknowledged). Carries the fresh evaluation so the page can show it.
 */
final class StudentImportNotCommittableException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $summary
     */
    public function __construct(public readonly array $rows, public readonly array $summary, string $message)
    {
        parent::__construct($message);
    }
}
