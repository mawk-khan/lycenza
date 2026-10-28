<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §5): where a setup area is configured. School stays the
 * only tenant; Campus is a sub-School dimension (ADR 0076 §10).
 *
 * NETWORK_FUTURE and PLATFORM_FUTURE are reserved vocabulary only: no
 * definition may use them until a later checkpoint (ORG.12D) is authorized,
 * and OrganizationSetupRegistry::validate() refuses them.
 */
enum SetupScopeLevel: string
{
    case SCHOOL = 'school';
    case CAMPUS = 'campus';
    case SCHOOL_AND_CAMPUS = 'school_and_campus';
    case NETWORK_FUTURE = 'network_future';
    case PLATFORM_FUTURE = 'platform_future';

    public function isReserved(): bool
    {
        return $this === self::NETWORK_FUTURE || $this === self::PLATFORM_FUTURE;
    }
}
