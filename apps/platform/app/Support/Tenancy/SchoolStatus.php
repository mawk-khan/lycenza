<?php

namespace App\Support\Tenancy;

/**
 * Phase 0N.9 (ADR 0047 section 2): the platform tenant lifecycle held in
 * `schools.status` -- the only lifecycle field. Mirrored by the
 * `schools_status_check` constraint; the database also enforces the only
 * permitted transitions (`trg_schools_status_transition`):
 * provisioning -> active -> suspended -> active. `archived` is kept for
 * compatibility with no application transition into or out of it
 * (retention/legal gate). Only `active` is operational.
 */
enum SchoolStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Provisioning',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }
}
