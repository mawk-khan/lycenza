<?php

namespace App\Support\Integrations;

use App\Models\School;

/**
 * ORG.12G (ADR 0080 §12): the read-only readiness of one or more AVAILABLE
 * integration families, implemented by the module that owns the
 * integration. Called inside TenantContext::withSchool($school).
 *
 * Contract (IntegrationHubArchitectureTest):
 * - reads only: bounded EXISTS/aggregate queries over the owning module's
 *   own tables and its own non-secret configuration state;
 * - never an outbound call (HTTP, SMTP, DNS, socket, device, provider SDK);
 * - never selects or reads a secret column or a credential value;
 * - never writes, audits, caches or stores a result.
 */
interface IntegrationReadinessProvider
{
    /** @return list<string> the catalogue keys this provider answers */
    public function keys(): array;

    public function evaluate(string $key, School $school): IntegrationReadiness;
}
