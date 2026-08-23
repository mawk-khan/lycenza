<?php

namespace App\Domain\Guardians\Infrastructure;

/**
 * The channel a GuardianContact value belongs to. Deliberately does NOT
 * include `whatsapp` -- a mobile number being WhatsApp-capable is a
 * future Communications-module concern (docs/architecture/DOMAIN-MAP.md
 * Layer 4), not a distinct contact identity here. Stored as a plain
 * string column, cast via this native PHP backed enum -- the same
 * pattern `RelationshipType` (Phase 1A.2) established for this
 * codebase's first Eloquent-enum-cast columns.
 */
enum ContactType: string
{
    case Email = 'email';
    case Mobile = 'mobile';
}
