<?php

namespace App\Support\Setup;

use App\Models\School;

/**
 * ORG.12A (ADR 0080 §8): answers configuration readiness for one or more
 * setup areas, implemented by the module that OWNS the configuration (in
 * that module's Application layer, over its own models and read services
 * -- the Data Health pattern, ADR 0075 §10).
 *
 * A provider is READ-ONLY and authorization-neutral: it never writes,
 * caches, audits or stores anything, and OrganizationSetupReadService has
 * already checked the viewer and the module entitlement before calling it.
 * It runs inside TenantContext::withSchool($school) and must still scope
 * every query to `$school` explicitly. Its queries are bounded (exists,
 * count, aggregate) -- never one query per Student, Employee or record.
 */
interface SetupReadinessProvider
{
    /** @return list<string> the setup keys this provider answers */
    public function keys(): array;

    public function evaluate(string $key, School $school): SetupReadiness;
}
