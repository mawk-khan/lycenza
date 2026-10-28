<?php

namespace App\Domain\Identity\Application\Portal;

use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Models\School;
use App\Support\Portal\PortalAvailability;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;

/**
 * ORG.12A (ADR 0080 §10): Guardian portal readiness. The environment gate
 * comes first and an entitlement never opens it (PortalAvailability, ADR
 * 0070 §18.2): outside local/testing the area is BLOCKED. Otherwise, one
 * EXISTS answer -- an active Guardian account link -- never an account.
 */
class GuardianPortalSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['portal.guardian_portal'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        if (! PortalAvailability::isAvailable()) {
            return new SetupReadiness(SetupStatus::BLOCKED, 'The Guardian portal is not available in this environment (POR-L1).');
        }

        return StudentGuardianAccountLink::query()->where('school_id', $school->id)->where('status', 'active')->exists()
            ? new SetupReadiness(SetupStatus::CONFIGURED, 'Guardian accounts are linked.', 1, 1)
            : new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'No Guardian account is linked yet.', 0, 1);
    }
}
