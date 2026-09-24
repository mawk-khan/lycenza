<?php

namespace App\Domain\Platform\Application\Elevation;

/**
 * The closed v1 reason-code catalog for starting a platform elevation
 * (Phase 0N.3, owner-approved; ADR 0044 section 5). Identifiers only --
 * no free text, no "other". Mirrored by the school_elevations
 * reason_code CHECK constraint; a new reason is a reviewed code and
 * migration change.
 */
enum ElevationReason: string
{
    case OperationalSupport = 'operational_support';
    case SecurityInvestigation = 'security_investigation';
    case ConfigurationAssistance = 'configuration_assistance';
    case IncidentResponse = 'incident_response';

    public function label(): string
    {
        return match ($this) {
            self::OperationalSupport => 'Operational support',
            self::SecurityInvestigation => 'Security investigation',
            self::ConfigurationAssistance => 'Configuration assistance',
            self::IncidentResponse => 'Incident response',
        };
    }
}
