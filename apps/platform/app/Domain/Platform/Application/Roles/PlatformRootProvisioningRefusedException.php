<?php

namespace App\Domain\Platform\Application\Roles;

use RuntimeException;

/**
 * Phase 0O.1: the operator console refused to provision the root role.
 * `$reason` is a fixed code; the message never echoes operator input.
 */
class PlatformRootProvisioningRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
