<?php

namespace App\Support\Authorization;

/**
 * ORG.10-R1 (ADR 0076 §79, OD-9.7): the School consumer capabilities whose
 * ACTIVATION is independent of the grant's scope -- the one narrow exception
 * to rule 101's "whole-School paths never count a Campus grant".
 *
 * A capability listed here is satisfied in a School by an active whole-School
 * grant OR an active Campus-scoped grant (on an active Campus) of the same
 * School. That only proves the User may consume; what they see stays bound to
 * themselves: their own received Communications (recipient snapshot or thread
 * participation) and the Events their current audience membership reaches.
 * Neither key reaches Campus business data, so this is not Campus adoption
 * (`CampusScopeCatalogue` never lists them) and nothing is filtered by Campus.
 *
 * Exactly two keys, pinned by ConsumerCapabilityActivationArchitectureTest;
 * never a wildcard. A third key is an owner decision.
 */
final class ConsumerCapabilities
{
    /** @var list<string> */
    public const SCOPE_INDEPENDENT = [
        'communications.events.view',
        'communications.inbox.view',
    ];

    /**
     * The scope-independent consumer capabilities among $keys.
     *
     * @param  array<int, string>  $keys
     * @return list<string>
     */
    public static function scopeIndependentAmong(array $keys): array
    {
        return array_values(array_intersect(self::SCOPE_INDEPENDENT, $keys));
    }
}
