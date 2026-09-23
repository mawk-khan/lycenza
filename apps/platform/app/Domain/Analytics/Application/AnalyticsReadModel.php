<?php

namespace App\Domain\Analytics\Application;

use App\Models\School;

/**
 * One thin, purpose-built Analytics read model (ADR 0040 §7). It is
 * only ever executed through AnalyticsReadGate::read(), which checks
 * `analytics.view`, applies the cohort policy to its declaration,
 * restricts filters, sets the tenant context and audits where the tier
 * requires -- never call compute() directly
 * (Tests\Feature\Analytics\AnalyticsArchitectureGuardTest).
 *
 * compute() runs synchronously against current source data, through
 * source modules' own read contracts only: no cache, no snapshot, no
 * cross-School read.
 */
interface AnalyticsReadModel
{
    public function declaration(): ReadModelDeclaration;

    /**
     * @param  array<string, string>  $filters  already restricted to declaration()->filters
     * @return array<string, mixed>
     */
    public function compute(School $school, array $filters): array;
}
