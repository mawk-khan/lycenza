<?php

namespace App\Domain\Platform\Application\Elevation;

use App\Models\School;
use App\Support\Tenancy\VerifiedSchoolDomain;
use Illuminate\Support\Str;

/**
 * Exact target resolution for starting an elevation (Phase 0N.3, owner
 * decision 4; ADR 0044 section 6). No School directory, search, list,
 * autocomplete or partial match exists anywhere in this flow.
 *
 * Two exact identifiers are accepted, both already operational in this
 * repository:
 * - a verified School domain, through VerifiedSchoolDomain -- the same
 *   exact lookup ResolveSchoolContext uses for the request host
 *   (preferred where a School has one);
 * - the School's UUID -- the identifier the platform already uses for a
 *   School in `/api/v1/schools/{school}/...`, `POST
 *   /app/schools/{school}/activate` and `/invitations/{school}/...`.
 *   Needed because verified domains are optional (no School in the demo
 *   has one, and nothing yet manages them), so domain-only would make
 *   most Schools unreachable.
 * Anything else is malformed. The caller gives one uniform refusal for
 * malformed, unknown and inactive targets.
 */
final class ElevationTargetResolver
{
    public const OUTCOME_MALFORMED = 'target_malformed';

    public const OUTCOME_NOT_FOUND = 'target_not_found';

    /**
     * @return array{0: School|null, 1: string|null} [school, failure outcome]
     */
    public function resolve(string $identifier): array
    {
        $identifier = trim($identifier);

        if (Str::isUuid($identifier)) {
            $school = School::query()->find(strtolower($identifier));

            return $school !== null ? [$school, null] : [null, self::OUTCOME_NOT_FOUND];
        }

        $host = strtolower($identifier);

        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
            return [null, self::OUTCOME_MALFORMED];
        }

        $school = VerifiedSchoolDomain::schoolFor($host);

        return $school !== null ? [$school, null] : [null, self::OUTCOME_NOT_FOUND];
    }
}
