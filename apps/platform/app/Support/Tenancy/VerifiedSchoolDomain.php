<?php

namespace App\Support\Tenancy;

use App\Models\School;
use App\Models\SchoolDomain;

/**
 * The one exact verified-domain -> School lookup (`school_domains`,
 * unique `domain`, `verified_at` set). Used by ResolveSchoolContext for
 * the request host and, since Phase 0N.3, by the platform elevation
 * target resolver for an operator-entered domain. Exact match only --
 * never a prefix, pattern or partial search.
 */
final class VerifiedSchoolDomain
{
    public static function schoolFor(string $host): ?School
    {
        $domain = SchoolDomain::query()
            ->where('domain', $host)
            ->whereNotNull('verified_at')
            ->first();

        return $domain?->school;
    }
}
