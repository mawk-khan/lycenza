<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeRunStalePreviewException extends PaymentsException
{
    public function __construct(string $runId)
    {
        parent::__construct(409, 'LATE_FEE_RUN_STALE_PREVIEW', "Late-fee run '{$runId}' must be previewed again: its rule changed or is no longer active.");
    }
}
