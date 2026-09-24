<?php

namespace App\Domain\Platform\Application\Elevation;

/**
 * Platform-ledger event types for elevation (ADR 0044 section 13). Their
 * metadata is limited to the allowlisted identifiers and codes each
 * writer passes -- never School data, request bodies or free text.
 */
final class ElevationAudit
{
    public const DENIED = 'platform.school_elevation.denied';

    public const STARTED = 'platform.school_elevation.started';

    public const ENDED = 'platform.school_elevation.ended';

    public const EXPIRED = 'platform.school_elevation.expired';

    public const TERMINATED = 'platform.school_elevation.terminated';
}
