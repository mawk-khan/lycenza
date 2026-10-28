<?php

namespace App\Domain\Campuses\Application\Setup;

use App\Domain\Campuses\Application\CampusStructureSummary;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;
use App\Support\Setup\SetupStatus;

/**
 * ORG.12A (ADR 0080 §10): Campus readiness from CampusStructureSummary's
 * aggregate counts (one query). Campus is data here, never authority.
 */
class CampusSetupReadiness implements SetupReadinessProvider
{
    public function __construct(private readonly CampusStructureSummary $campuses) {}

    public function keys(): array
    {
        return ['organization.campuses'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        ['active' => $active, 'inactive' => $inactive] = $this->campuses->counts($school);

        return match (true) {
            $active > 0 => new SetupReadiness(SetupStatus::CONFIGURED, $active === 1 ? '1 active Campus.' : "{$active} active Campuses.", 1, 1),
            $inactive > 0 => new SetupReadiness(SetupStatus::ATTENTION_REQUIRED, 'Every Campus is inactive. Reactivate one or add a Campus.', 0, 1),
            default => new SetupReadiness(SetupStatus::NOT_CONFIGURED, 'Add the School\'s first Campus.', 0, 1),
        };
    }
}
