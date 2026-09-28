<?php

namespace App\Domain\Identity\Application\Staff;

use RuntimeException;

/**
 * Phase 0O.12B (ADR 0059 section 5): the operator console refused a
 * bootstrap-account operation. The operator is trusted, so the message may
 * be specific; `reason` is a bounded code.
 */
final class BootstrapAccountRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
