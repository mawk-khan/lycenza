<?php

namespace App\Support\DataHealth;

use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * V11.5 (ADR 0075 section 10): one Data Health check, implemented by the
 * module that owns the data it inspects (in that module's Application
 * layer, against its own models and read rules).
 *
 * A check is a READ-ONLY, recomputed-on-request query over authoritative
 * state: it never writes, never caches and never stores a finding. It is
 * authorization-neutral -- DataHealthService checks the definition's
 * capabilities before calling it -- and must scope every query to
 * `$school` explicitly (it runs inside TenantContext::withSchool()).
 */
interface DataHealthCheck
{
    public function definition(): DataHealthCheckDefinition;

    /** Cheap existence test (an EXISTS query), used by the overview. */
    public function hasFindings(School $school): bool;

    /**
     * The affected records, bounded and paginated.
     *
     * @return LengthAwarePaginator<int, DataHealthFinding>
     */
    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator;
}
