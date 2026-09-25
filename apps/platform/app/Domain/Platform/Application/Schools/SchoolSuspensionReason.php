<?php

namespace App\Domain\Platform\Application\Schools;

/**
 * Phase 0N.9 (ADR 0047 section 8, owner decision 14): the closed v1 set
 * of reasons a School is suspended. Recorded as a code in platform audit
 * only; there is no free-text reason.
 */
enum SchoolSuspensionReason: string
{
    case SecurityIncident = 'security_incident';
    case AdministrativeHold = 'administrative_hold';
    case SchoolRequested = 'school_requested';

    public function label(): string
    {
        return match ($this) {
            self::SecurityIncident => 'Security incident',
            self::AdministrativeHold => 'Administrative hold',
            self::SchoolRequested => 'Requested by the School',
        };
    }
}
