<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * ORG.9C-4: at commit, at least one row is no longer READY or ALREADY_ASSIGNED
 * (or the canonical assignment refused it under its locks). Nothing of the
 * batch was kept; the fresh row outcomes go back to the operator.
 */
class TransportRiderImportNotCommittableException extends TransportException
{
    /** @param  list<array<string, mixed>>  $outcomes */
    public function __construct(public readonly array $outcomes)
    {
        parent::__construct(409, 'TRANSPORT_RIDER_IMPORT_NOT_COMMITTABLE', 'Nothing was assigned: at least one row can no longer be assigned. Review the rows below.');
    }
}
