<?php

namespace App\Support\Domains;

use App\Models\School;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0054 section 8.9: the ONE source of absolute School URLs, in a request
 * or a queued job alike. A School's canonical origin is
 * `https://<its ACTIVE primary custom hostname>` while the School is active
 * and has one, otherwise the platform origin (APP_URL). It is read from
 * PostgreSQL at generation time and never derived from a request Host,
 * `X-Forwarded-Host` or the current request origin -- so a Host header can
 * never poison an emailed link (the GuardianAccountInvitationMail finding).
 *
 * Use it for School switching, invitation and notification links, and
 * (future, O14) account-recovery links. Platform surfaces always use
 * platformUrl().
 */
final class CanonicalOrigin
{
    public function __construct(
        private readonly Repository $config,
        private readonly DomainDirectory $directory,
    ) {}

    public function forSchool(School $school): string
    {
        $primary = $school->isActive() ? $this->directory->primaryHostname($school->id) : null;

        return $primary !== null ? 'https://'.$primary : $this->platformOrigin();
    }

    /** An absolute URL for a path on the School's canonical origin. */
    public function schoolUrl(School $school, string $path): string
    {
        return $this->forSchool($school).'/'.ltrim($path, '/');
    }

    /** The platform origin: scheme://host[:port] of APP_URL, never a request Host. */
    public function platformOrigin(): string
    {
        $parts = parse_url((string) $this->config->get('app.url'));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    public function platformUrl(string $path): string
    {
        return $this->platformOrigin().'/'.ltrim($path, '/');
    }

    /** The host part of an origin this service produced (used to bind a handoff to its target). */
    public static function hostOf(string $origin): string
    {
        return strtolower((string) parse_url($origin, PHP_URL_HOST));
    }
}
