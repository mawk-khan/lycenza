<?php

namespace App\Support\Portal;

/**
 * POR (ADR 0070 §18.2): the production block for the whole Guardian/Student
 * portal. POR-L1 (ADR 0058 E46) is a DRAFT REQUEST — NOT SENT, NOT
 * ANSWERED; until it is recorded and its production conditions are met,
 * every portal route and Application entry point runs only when `APP_ENV`
 * is `local` or `testing`.
 *
 * Deliberately not configurable: no variable, flag or request input opens
 * it. Lifting it is a reviewed code change made only after POR-L1. Checked by
 * the `portal-development-only` route middleware AND again by every portal
 * Application entry point, so "implemented" can never silently become
 * "production enabled" (the StudentMarkAvailability pattern, ADR 0068 §27).
 */
final class PortalAvailability
{
    /** @var list<string> */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public static function isAvailable(): bool
    {
        return app()->environment(self::ENVIRONMENTS);
    }

    /** @throws PortalUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new PortalUnavailableException;
        }
    }
}
