<?php

namespace App\Domain\Students\Application\Bulk;

use RuntimeException;

/**
 * SIS 11.13, internal: one row of a confirmed batch refused inside the
 * execution transaction. Thrown out of the transaction so the whole batch
 * rolls back; BulkStudentPlacementService turns it into
 * BulkPlacementNotExecutedException with a fresh preview.
 */
final class BulkRowRefusal extends RuntimeException
{
    public function __construct(public readonly ?string $studentId, string $message, public readonly bool $alreadyInTarget = false)
    {
        parent::__construct($message);
    }
}
