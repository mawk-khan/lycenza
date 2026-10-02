<?php

namespace App\Domain\Guardians\Application\Retention;

/**
 * E21.3C (E21.2G G1): a Guardian's relationship lifecycle, as resolved by
 * GuardianRetentionEligibility from the durable
 * `guardians.no_relationship_since` marker. Only `ended` carries a time.
 */
final class GuardianLifecycle
{
    /** At least one Student relationship exists: no clock runs. */
    public const RELATED = 'related';

    /** No relationship, and no trustworthy time since when (predates the marker). */
    public const UNRESOLVED = 'unresolved';

    /** No relationship since `$since` (UTC). */
    public const ENDED = 'ended';

    private function __construct(
        public readonly string $state,
        public readonly ?string $since,
    ) {}

    public static function resolve(bool $hasRelationship, ?string $noRelationshipSince): self
    {
        return match (true) {
            $hasRelationship => new self(self::RELATED, null),
            $noRelationshipSince === null => new self(self::UNRESOLVED, null),
            default => new self(self::ENDED, substr($noRelationshipSince, 0, 19)),
        };
    }

    /** Strictly before the UTC cutoff (a marker exactly at it is kept). */
    public function endedBefore(string $cutoff): bool
    {
        return $this->state === self::ENDED && $this->since < $cutoff;
    }
}
