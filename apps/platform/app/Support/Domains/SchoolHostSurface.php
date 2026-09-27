<?php

namespace App\Support\Domains;

/**
 * ADR 0054 section 8.2: the closed browser School surface a custom domain
 * serves. Everything not admitted here answers a fixed 404 on a School host
 * -- platform (`app/platform/*`), Group (`app/groups/*`) and elevation pages,
 * platform MFA administration, personal API tokens (the API is platform-host
 * only), `/api/*` (v1, internal, health), `/up`, `storage/*`,
 * `sanctum/csrf-cookie` and any future surface nobody added here. The
 * complete route-by-route result is pinned by
 * Tests\Feature\CustomDomains\SchoolHostSurfaceGuardTest.
 */
final class SchoolHostSurface
{
    /** Exact paths (no leading slash; '' is `/`). */
    public const EXACT = ['', 'login', 'login/mfa', 'logout', 'app', 'session/handoff'];

    /** Path prefixes admitted, minus the exclusions below. */
    public const PREFIXES = ['app/', 'invitations/'];

    /** Never on a School host, even under an admitted prefix. */
    public const EXCLUDED = ['app/platform', 'app/groups', 'app/account/admin', 'app/account/api-tokens'];

    public static function admits(string $path): bool
    {
        $path = trim($path, '/');

        if (in_array($path, self::EXACT, true)) {
            return true;
        }

        foreach (self::EXCLUDED as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded.'/')) {
                return false;
            }
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
