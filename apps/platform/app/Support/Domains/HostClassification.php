<?php

namespace App\Support\Domains;

use Illuminate\Http\Request;

/**
 * The one classification of a request's Host (ADR 0054 section 8.1),
 * stored on the request by App\Http\Middleware\ClassifyRequestHost before
 * any session, CSRF, School or URL logic runs.
 */
final class HostClassification
{
    public const ATTRIBUTE = 'lycenza.host_classification';

    /** APP_URL host, a configured alias, or (local/testing only) a development host. */
    public const PLATFORM = 'platform';

    /** The ACTIVE primary custom domain of an active School. */
    public const SCHOOL = 'school';

    /** An ACTIVE non-primary custom domain: 308 to the primary. */
    public const SCHOOL_ALIAS = 'school_alias';

    /** A configured private internal host: internal AI routes and health only. */
    public const INTERNAL = 'internal';

    /** The probe path on a tls_pending/active/suspended domain host. */
    public const PROBE = 'probe';

    /** A health path on an IP-literal host (orchestrators probe by address). */
    public const HEALTH = 'health';

    /** A health path on any other name: 404, answered without a lookup. */
    public const NOT_SERVED = 'not_served';

    /** Everything else, including every non-active domain: 421. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $class,
        public readonly ?string $hostname = null,
        public readonly ?string $schoolId = null,
        public readonly ?string $domainId = null,
        public readonly ?string $primaryHostname = null,
        public readonly bool $development = false,
    ) {}

    public static function platform(bool $development = false): self
    {
        return new self(self::PLATFORM, development: $development);
    }

    public static function school(string $hostname, string $schoolId, string $domainId): self
    {
        return new self(self::SCHOOL, $hostname, $schoolId, $domainId, $hostname);
    }

    public static function schoolAlias(string $hostname, string $schoolId, string $domainId, string $primary): self
    {
        return new self(self::SCHOOL_ALIAS, $hostname, $schoolId, $domainId, $primary);
    }

    public static function probe(string $hostname, string $domainId): self
    {
        return new self(self::PROBE, $hostname, domainId: $domainId);
    }

    public static function of(string $class): self
    {
        return new self($class);
    }

    public function is(string $class): bool
    {
        return $this->class === $class;
    }

    public static function for(Request $request): ?self
    {
        $value = $request->attributes->get(self::ATTRIBUTE);

        return $value instanceof self ? $value : null;
    }

    /** The School the Host names -- only ever for an ACTIVE primary domain. */
    public static function schoolHostOf(Request $request): ?self
    {
        $classification = self::for($request);

        return $classification !== null && $classification->is(self::SCHOOL) ? $classification : null;
    }
}
