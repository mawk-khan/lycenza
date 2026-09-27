<?php

namespace App\Domain\Platform\Application\Domains;

/**
 * ADR 0054 section 10.2: the `school.domain.*` audit vocabulary. Metadata
 * is bounded: canonical hostname, old and new state, the action and a
 * closed outcome code -- NEVER the challenge token, a DNS answer, TLS
 * material beyond the public fingerprint, or a handoff ticket.
 */
final class SchoolDomainAudit
{
    public const CLAIMED = 'school.domain.claimed';

    public const CHALLENGE_REGENERATED = 'school.domain.challenge_regenerated';

    public const VERIFIED = 'school.domain.verified';

    public const TLS_PENDING = 'school.domain.tls_pending';

    public const ACTIVATED = 'school.domain.activated';

    public const SUSPENDED = 'school.domain.suspended';

    public const REACTIVATED = 'school.domain.reactivated';

    public const PRIMARY_CHANGED = 'school.domain.primary_changed';

    public const REVOKED = 'school.domain.revoked';

    public const EXPIRED = 'school.domain.expired';

    /** Platform ledger: an operator's console revocation. */
    public const OPERATOR_REVOKED = 'platform.school_domain.revoked';

    /**
     * @param  array<string, string|int|bool|null>  $extra
     * @return array<string, string|int|bool|null>
     */
    public static function metadata(string $hostname, ?string $from, ?string $to, string $outcome, array $extra = []): array
    {
        return ['hostname' => $hostname, 'from_state' => $from, 'to_state' => $to, 'outcome' => $outcome, ...$extra];
    }
}
