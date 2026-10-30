<?php

namespace App\Domain\Students\Application\Import;

use App\Support\Http\WebDomainRefusalResponder;
use RuntimeException;
use Throwable;

/**
 * SIS.EXP-5: a canonical write path refused one row during the commit; the
 * whole batch was rolled back. Names the row and the domain's own operator
 * message (never a database or framework message).
 */
final class StudentImportRowFailedException extends RuntimeException
{
    public function __construct(public readonly int $row, Throwable $previous)
    {
        $reason = WebDomainRefusalResponder::refusalStatus($previous) !== null
            ? WebDomainRefusalResponder::operatorMessage($previous)
            : 'it could not be created.';
        parent::__construct("Row {$row} could not be imported: {$reason} Nothing was imported.", 0, $previous);
    }
}
