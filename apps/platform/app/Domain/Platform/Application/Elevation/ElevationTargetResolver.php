<?php

namespace App\Domain\Platform\Application\Elevation;

use App\Models\School;
use App\Support\Domains\DomainDirectory;
use App\Support\Domains\DomainState;
use App\Support\Domains\HostnameNormalizer;
use Illuminate\Support\Str;

/**
 * Exact target resolution for starting an elevation (Phase 0N.3, owner
 * decision 4; ADR 0044 section 6). No School directory, search, list,
 * autocomplete or partial match exists anywhere in this flow.
 *
 * Two exact identifiers are accepted, both already operational in this
 * repository:
 * - an ACTIVE custom School domain (Phase 0O.8A, ADR 0054 section 8.5):
 *   the canonical hostname (HostnameNormalizer, the one normalization) and
 *   the same PostgreSQL lookup the Host boundary uses (DomainDirectory) --
 *   never a pending, suspended, revoked or expired row;
 * - the School's UUID -- the identifier the platform already uses for a
 *   School in `/api/v1/schools/{school}/...`, `POST
 *   /app/schools/{school}/activate` and `/invitations/{school}/...`.
 *   Needed because custom domains are optional, so domain-only would make
 *   most Schools unreachable.
 * Anything else is malformed. The caller gives one uniform refusal for
 * malformed, unknown and inactive targets.
 */
final class ElevationTargetResolver
{
    public function __construct(
        private readonly HostnameNormalizer $names,
        private readonly DomainDirectory $directory,
    ) {}

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

        $host = $this->names->canonicalRequestHost($identifier);

        if ($host === null) {
            return [null, self::OUTCOME_MALFORMED];
        }

        $entry = $this->directory->lookup($host);
        $school = $entry !== null && $entry['state'] === DomainState::Active->value ? School::query()->find($entry['school_id']) : null;

        return $school !== null ? [$school, null] : [null, self::OUTCOME_NOT_FOUND];
    }
}
