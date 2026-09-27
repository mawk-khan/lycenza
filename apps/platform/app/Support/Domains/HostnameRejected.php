<?php

namespace App\Support\Domains;

use InvalidArgumentException;

/**
 * A hostname that can never be claimed (ADR 0054 section 3). The reason is
 * a closed code, safe to log; the message never echoes the input.
 */
final class HostnameRejected extends InvalidArgumentException
{
    public const SYNTAX = 'syntax';

    public const IP_LITERAL = 'ip_literal';

    public const IDN = 'idn';

    public const LOCAL_NAME = 'local_name';

    public const PUBLIC_SUFFIX = 'public_suffix';

    public const UNKNOWN_SUFFIX = 'unknown_suffix';

    public const RESERVED = 'reserved';

    public const REASONS = [self::SYNTAX, self::IP_LITERAL, self::IDN, self::LOCAL_NAME, self::PUBLIC_SUFFIX, self::UNKNOWN_SUFFIX, self::RESERVED];

    public function __construct(public readonly string $reason)
    {
        parent::__construct("hostname rejected: {$reason}");
    }
}
