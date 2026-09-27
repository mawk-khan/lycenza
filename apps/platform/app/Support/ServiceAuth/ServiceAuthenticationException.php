<?php

namespace App\Support\ServiceAuth;

use RuntimeException;

/**
 * Service authentication failed (always a uniform 401). `reason` is one of
 * ServiceAuthContract::FAILURE_CODES, for logs and metrics only; `kid` is set
 * only once it was found in the ring (never an attacker-chosen string).
 */
final class ServiceAuthenticationException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $kid = null, public readonly ?string $service = null)
    {
        parent::__construct('service_authentication_failed');
    }
}
