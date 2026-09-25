<?php

namespace App\Domain\Analytics\Application\Group;

use App\Models\School;

/**
 * Phase 0N.11 (ADR 0048 sections 6-8): an Analytics report whose owning
 * source has declared it safe for Group (cross-School) aggregation. Being
 * an AnalyticsReadModel -- even a registered one -- never makes a report
 * Group-safe; only GroupSafeReportRegistry does, and only through
 * GroupSafeReportGate, one School at a time.
 */
interface GroupSafeReport
{
    public function groupDeclaration(): GroupSafeReportDeclaration;

    /**
     * The fixed Group-safe summary for ONE School. Runs only inside
     * GroupSafeReportGate, with exactly that School's TenantContext set.
     */
    public function groupSafeSummary(School $school): GroupSafeSchoolSummary;

    /**
     * The source-defined Group aggregate over contributing Schools'
     * summaries.
     *
     * @param  list<GroupSafeSchoolSummary>  $summaries
     * @return array<string, int|string|null>
     */
    public function aggregateGroup(array $summaries): array;
}
