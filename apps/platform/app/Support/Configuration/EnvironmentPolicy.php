<?php

namespace App\Support\Configuration;

/**
 * PPR.1 (ADR 0074 section 26, owner decision OD-PPR-1 option B): the one
 * authoritative classification of the application environment.
 *
 * Exactly four values are supported; anything else refuses to boot
 * (AppServiceProvider::register()):
 *
 *   - `production` -- the real deployment;
 *   - `staging`    -- the production-equivalent qualification environment
 *                     without real School data (ADR 0058 section 3);
 *   - `local`      -- DDEV / a developer machine;
 *   - `testing`    -- PHPUnit.
 *
 * Security hardening keys on {@see isHardened()} (production AND staging),
 * never on the literal `production`: finding F-2 (ADR 0074 section 21) was
 * exactly that staging silently lost the boot guard, HSTS, forced HTTPS and
 * the email refusals because each checked `production` alone. Development
 * conveniences key on {@see isDevelopment()} (local and testing) -- staging
 * is never development. The few deliberate staging differences are listed,
 * test-pinned, in {@see STAGING_DIFFERENCES} and decided through
 * {@see isProduction()} only where that list names them.
 *
 * An unset APP_ENV resolves to `production` (config/app.php), the
 * strictest value -- never to a weaker one.
 */
final class EnvironmentPolicy
{
    public const PRODUCTION = 'production';

    public const STAGING = 'staging';

    public const LOCAL = 'local';

    public const TESTING = 'testing';

    /** @var list<string> */
    public const SUPPORTED = [self::PRODUCTION, self::STAGING, self::LOCAL, self::TESTING];

    /** Production-equivalent: every security hardening applies. @var list<string> */
    public const HARDENED = [self::PRODUCTION, self::STAGING];

    /** Development conveniences may exist (each still behind its own switch). @var list<string> */
    public const DEVELOPMENT = [self::LOCAL, self::TESTING];

    /**
     * ADR 0074 section 26.3: the complete list of intentional differences
     * between staging and production. Each is decided through
     * isProduction() at exactly one place and pinned by
     * Tests\Unit\Configuration\EnvironmentPolicyTest and
     * Tests\Feature\Configuration\StagingParityTest. Adding a difference is a
     * reviewed ADR 0074 amendment, never a convenience.
     *
     * @var array<string, string>
     */
    public const STAGING_DIFFERENCES = [
        'mail_staging_capture' => 'Staging may declare its SMTP target a non-delivering capture sink (MAIL_STAGING_DELIVERY=capture); production refuses the declaration.',
        'mail_sending_verified_not_required_for_capture' => 'A staging capture sink needs no MAIL_SENDING_VERIFIED attestation (no domain is verified with a provider before E19).',
    ];

    public static function current(): string
    {
        return (string) app()->environment();
    }

    public static function isSupported(string $environment): bool
    {
        return in_array($environment, self::SUPPORTED, true);
    }

    /**
     * Refuses an unsupported value. Called with no argument at boot, so no
     * stack frame (a console renderer prints frame arguments) carries the
     * value; the message never echoes it either.
     */
    public static function assertSupported(?string $environment = null): void
    {
        $environment ??= self::current();
        if (self::isSupported($environment)) {
            return;
        }

        throw new UnsupportedEnvironmentException($environment);
    }

    public static function isHardened(?string $environment = null): bool
    {
        return in_array($environment ?? self::current(), self::HARDENED, true);
    }

    /** The real production deployment only -- for the listed STAGING_DIFFERENCES, never for hardening. */
    public static function isProduction(?string $environment = null): bool
    {
        return ($environment ?? self::current()) === self::PRODUCTION;
    }

    public static function isStaging(?string $environment = null): bool
    {
        return ($environment ?? self::current()) === self::STAGING;
    }

    public static function isDevelopment(?string $environment = null): bool
    {
        return in_array($environment ?? self::current(), self::DEVELOPMENT, true);
    }
}
