<?php

namespace App\Support\ServiceAuth;

use RuntimeException;

/** Key configuration is unusable. Carries a bounded violation code, never key material. */
final class ServiceKeyConfigException extends RuntimeException
{
    public function __construct(public readonly string $violation)
    {
        parent::__construct($violation);
    }
}
