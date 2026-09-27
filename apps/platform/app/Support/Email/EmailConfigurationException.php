<?php

namespace App\Support\Email;

use RuntimeException;

/** A closed configuration code; never a configured value. */
final class EmailConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Email is not configured correctly ({$reason}).");
    }
}
