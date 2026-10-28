<?php

namespace App\Domain\Admissions\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Admissions has no mandatory configuration of its
 * own -- applications target the School's academic structure, expressed as
 * a setup DEPENDENCY (`academic.structure`). The optional Admission fee
 * mapping (OPF.3) is not a setup requirement.
 */
class AdmissionsSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['admissions.admissions'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::noSetupRequired('Applications use the School\'s academic structure; no separate admissions setup is needed.');
    }
}
