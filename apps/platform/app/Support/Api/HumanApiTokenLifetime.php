<?php

namespace App\Support\Api;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Phase 0O.3 owner values V1/V2 (ADR 0049 implementation amendment): a
 * human API token lasts 30 days by default and never more than 90 days
 * after issue. The expiry is absolute (no sliding renewal).
 * `config('sanctum.expiration')` carries the same 90-day ceiling as a
 * backstop measured from `created_at`.
 */
final class HumanApiTokenLifetime
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 90;

    public static function expiryFor(?DateTimeInterface $requested): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($requested === null) {
            return $now->addDays(self::DEFAULT_DAYS);
        }

        $expiry = CarbonImmutable::instance($requested);

        if ($expiry->lte($now) || $expiry->gt($now->addDays(self::MAX_DAYS))) {
            throw new InvalidArgumentException('An API token must expire within '.self::MAX_DAYS.' days.');
        }

        return $expiry;
    }
}
