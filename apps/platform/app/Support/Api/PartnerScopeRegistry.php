<?php

namespace App\Support\Api;

/**
 * Phase 0O.3 (ADR 0049 sections 6 and 6.4): the closed catalog of PARTNER
 * API scopes. Partner access is deny-by-default: a partner route exists
 * only when registered under `/api/v1/partner` with one of these scopes.
 *
 * PRODUCTION is deliberately EMPTY -- no partner integration is approved
 * (owner decision 2026-09-25; O15 open). The recommended first scope
 * `academic_structure.read` is NOT here and must not be added without an
 * approved O15/integration decision or an ADR 0049 amendment.
 *
 * PROBE exists only in the `local` and `testing` environments, where it
 * backs the single probe route that proves the partner substrate
 * (authentication, one-School binding, RLS, throttling, expiry, rotation,
 * revocation). It is never available in production, and the probe route
 * is never registered there (so never in a production route cache).
 */
final class PartnerScopeRegistry
{
    /** @var list<string> */
    public const PRODUCTION = [];

    public const PROBE = 'partner.probe.read';

    /** @var list<string> */
    public const PROBE_ENVIRONMENTS = ['local', 'testing'];

    public static function probeEnabled(): bool
    {
        return app()->environment(self::PROBE_ENVIRONMENTS);
    }

    /** @return list<string> the scopes that may be granted and honoured here */
    public static function available(): array
    {
        return self::probeEnabled() ? [...self::PRODUCTION, self::PROBE] : self::PRODUCTION;
    }

    public static function isAvailable(string $scope): bool
    {
        return in_array($scope, self::available(), true);
    }

    /**
     * @param  array<int, mixed>  $scopes
     */
    public static function isValidSet(array $scopes): bool
    {
        if ($scopes === []) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! self::isAvailable($scope)) {
                return false;
            }
        }

        return count(array_unique($scopes)) === count($scopes);
    }
}
