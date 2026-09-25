<?php

namespace App\Support\Api;

/**
 * Phase 0O.3 (ADR 0049 section 6): the closed, code-defined scope catalog
 * for HUMAN API tokens (Sanctum personal access tokens). There is no
 * wildcard (`*`, `all`, `admin`) and no implication between scopes:
 * `api.read` permits safe methods (GET/HEAD/OPTIONS) only, `api.write`
 * permits mutations only -- a token that needs both carries both. A scope
 * never grants a capability: every request still needs the user's own
 * current capability in the School.
 */
final class ApiScope
{
    public const READ = 'api.read';

    public const WRITE = 'api.write';

    /** @var list<string> */
    public const HUMAN = [self::READ, self::WRITE];

    /**
     * True only for a non-empty list drawn entirely from the catalog.
     *
     * @param  array<int, mixed>  $scopes
     */
    public static function isValidHumanSet(array $scopes): bool
    {
        if ($scopes === []) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, self::HUMAN, true)) {
                return false;
            }
        }

        return true;
    }

    /** The human scope a request of this HTTP method needs. */
    public static function forMethod(string $method): string
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) ? self::READ : self::WRITE;
    }
}
