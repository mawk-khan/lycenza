<?php

namespace App\Domain\Analytics\Application\Group;

use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;
use App\Models\School;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Phase 0N.11 (ADR 0048 sections 2, 4, 7) -- Analytics' Group-safe read
 * path: the only way a School's data reaches a Group report. It is NOT
 * AnalyticsReadGate: it authorizes by the Group grant, never by
 * `analytics.view`, and it serves only GroupSafeReportRegistry entries,
 * only their fixed Group-safe summary.
 *
 * summarize(), for ONE School, in order:
 * 1. the report key is registered as Group-safe (and counts no people);
 * 2. the actor still holds `group.reporting.view` in the named Group
 *    (read fresh, never cached);
 * 3. no School context is already set (never two Schools at once);
 * 4. the School is `active`, read FOR SHARE inside the caller's
 *    transaction (SchoolOperationalGuard) -- otherwise null: unavailable,
 *    and no tenant data is read;
 * 5. the summary is computed inside TenantContext::withSchool() for that
 *    School only (SchoolScope + forced RLS), which restores "no School"
 *    on success and on failure; the gate re-asserts that before returning.
 *
 * It never reads School Group tables: the caller (the Group layer) owns
 * Group membership and has already re-checked it for this School.
 */
class GroupSafeReportGate
{
    public const CAPABILITY = 'group.reporting.view';

    public function __construct(
        private readonly GroupSafeReportRegistry $registry,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolOperationalGuard $operational,
        private readonly TenantContext $context,
    ) {}

    public function report(string $key): GroupSafeReport
    {
        $report = $this->registry->find($key);

        if ($report === null) {
            throw AnalyticsReportUnavailableException::notRegistered($key);
        }

        return $report;
    }

    /**
     * @return GroupSafeSchoolSummary|null null when the School is not active (unavailable)
     */
    public function summarize(GroupReportAuthority $authority, School $school): ?GroupSafeSchoolSummary
    {
        $report = $this->report($authority->reportKey);

        if (! $this->capabilities->canInGroupById($authority->actor, self::CAPABILITY, $authority->groupId)) {
            throw new GroupReportAuthorityLostException('The Group reporting authority is no longer held.');
        }

        $this->assertNoSchoolContext();

        if (! $this->operational->holdOperational($school->id)) {
            return null;
        }

        try {
            return $this->context->withSchool($school, fn (): GroupSafeSchoolSummary => $report->groupSafeSummary($school));
        } finally {
            $this->assertNoSchoolContext();
        }
    }

    /**
     * The report's own, source-defined aggregate (ADR 0048 section 8).
     *
     * @param  list<GroupSafeSchoolSummary>  $summaries  contributing Schools only
     * @return array<string, int|string|null>
     */
    public function aggregate(string $key, array $summaries): array
    {
        return $this->report($key)->aggregateGroup($summaries);
    }

    private function assertNoSchoolContext(): void
    {
        if ($this->context->schoolId() !== null) {
            throw new LogicException('A Group-safe report read must start and end with no School context.');
        }
    }
}
