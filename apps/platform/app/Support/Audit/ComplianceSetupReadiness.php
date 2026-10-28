<?php

namespace App\Support\Audit;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): audit recording is platform infrastructure
 * (ADR 0017, append-only ledgers) and is never School-configurable, so
 * there is nothing to set up. Reading readiness never writes an audit row.
 * Lives with the audit infrastructure, not in Compliance: Compliance is a
 * Layer 5 consumer that no module depends on (ComplianceArchitectureGuardTest).
 */
class ComplianceSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['compliance.audit'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::noSetupRequired('Audit recording is always on and needs no setup.');
    }
}
