<?php

namespace App\Support\Domains;

use App\Models\SchoolDomain;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADR 0054 section 8.8: Host -> domain lookup for request classification.
 * PostgreSQL is authoritative (the partial unique index on `hostname` makes
 * this one indexed read of the one claiming row). A positive answer may be
 * cached for at most 60 s; every transition, primary change, revocation and
 * School lifecycle change forgets it after commit (forget()/forgetSchool()),
 * so the only staleness left is another replica's <= 60 s. A cache failure
 * only costs the database read -- it never fails a request, and a missing
 * entry never means "allowed". Nothing unknown is cached, so a random Host
 * cannot fill the cache.
 *
 * This is platform data (no TenantContext, no RLS): it reads only
 * `school_domains` and `schools.status`, never tenant tables.
 */
final class DomainDirectory
{
    public const TTL_SECONDS = 60;

    private const PREFIX = 'domains:host:';

    public function __construct(private readonly Repository $cache) {}

    /**
     * The claiming row for a canonical hostname, or null.
     *
     * @return array{domain_id: string, school_id: string, hostname: string, state: string, is_primary: bool, school_active: bool, primary_hostname: string|null}|null
     */
    public function lookup(string $hostname): ?array
    {
        try {
            $cached = $this->cache->get(self::PREFIX.$hostname);
            if (is_array($cached) && isset($cached['domain_id'], $cached['school_id'], $cached['state'])) {
                /** @var array{domain_id: string, school_id: string, hostname: string, state: string, is_primary: bool, school_active: bool, primary_hostname: string|null} $cached */
                return $cached;
            }
        } catch (Throwable) {
            // The database stays authoritative.
        }

        $row = DB::table('school_domains as d')
            ->join('schools as s', 's.id', '=', 'd.school_id')
            ->where('d.hostname', $hostname)
            ->whereIn('d.state', DomainState::CLAIMING)
            ->first(['d.id', 'd.school_id', 'd.hostname', 'd.state', 'd.is_primary', 's.status']);

        if ($row === null) {
            return null;
        }

        $entry = [
            'domain_id' => (string) $row->id,
            'school_id' => (string) $row->school_id,
            'hostname' => (string) $row->hostname,
            'state' => (string) $row->state,
            'is_primary' => (bool) $row->is_primary,
            'school_active' => $row->status === SchoolStatus::Active->value,
            'primary_hostname' => $this->primaryHostname((string) $row->school_id),
        ];

        try {
            $this->cache->put(self::PREFIX.$hostname, $entry, self::TTL_SECONDS);
        } catch (Throwable) {
            // Uncached is still correct.
        }

        return $entry;
    }

    /** The School's ACTIVE primary hostname, read from PostgreSQL. */
    public function primaryHostname(string $schoolId): ?string
    {
        $hostname = SchoolDomain::query()
            ->where('school_id', $schoolId)
            ->where('is_primary', true)
            ->where('state', DomainState::Active->value)
            ->value('hostname');

        return is_string($hostname) ? $hostname : null;
    }

    public function forget(string $hostname): void
    {
        try {
            $this->cache->forget(self::PREFIX.$hostname);
        } catch (Throwable) {
            // Bounded by the 60 s TTL.
        }
    }

    /** Forget every claiming hostname of a School (primary change, School lifecycle). */
    public function forgetSchool(string $schoolId): void
    {
        foreach (SchoolDomain::query()->where('school_id', $schoolId)->pluck('hostname') as $hostname) {
            $this->forget((string) $hostname);
        }
    }
}
