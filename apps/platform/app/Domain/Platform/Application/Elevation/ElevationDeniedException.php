<?php

namespace App\Domain\Platform\Application\Elevation;

use RuntimeException;

/**
 * A refused elevation attempt (ADR 0044 section 15). Already audited as
 * `platform.school_elevation.denied` by SchoolElevationService before it
 * is thrown. `outcome` is the audit outcome code; `errorCode()` the stable
 * machine code a JSON caller sees; `field` the form field an Inertia page
 * shows the message on.
 */
class ElevationDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $outcome,
        private readonly int $httpStatus,
        private readonly string $machineCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->machineCode;
    }
}
