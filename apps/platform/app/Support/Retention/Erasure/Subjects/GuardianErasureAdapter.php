<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Domain\Guardians\Application\Retention\GuardianLifecycle;
use App\Domain\Guardians\Application\Retention\GuardianRetentionEligibility;
use App\Models\School;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasurePeriods;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\GuardianRetention;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F/E21.3C (E21-D10, E21.2G G1): a reviewed erasure case for one
 * Guardian. An erasure request never shortens G1: the Guardian's personal
 * data (the Guardian, contacts, Documents, revoked account links, its own
 * consent and preferences) goes only once the Guardian has had no Student
 * relationship for GUARDIAN_RETENTION_YEARS and nothing retained needs it,
 * through the very same locked purge the scheduled run uses
 * (GuardianRetention), for this one Guardian:
 * - a current relationship keeps everything (`subject_current`);
 * - a Guardian without a relationship and without a trustworthy marker is
 *   kept (`trigger_unresolved`);
 * - the School hold wins; any retained dependent blocks.
 * A Guardian's relationships are governed by each Student's D7 retention,
 * not by the Guardian's case.
 */
final class GuardianErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionHolds $holds,
        private readonly GuardianRetentionEligibility $eligibility,
        private readonly GuardianRetention $retention,
    ) {}

    public function subjectType(): string
    {
        return 'guardian';
    }

    public function exists(?School $school, string $subjectId): bool
    {
        return $school !== null && $this->context->withSchool($school, fn (): bool => DB::table('guardians')->where('id', $subjectId)->exists());
    }

    public function plan(?School $school, string $subjectId): array
    {
        if ($school === null || ! $this->exists($school, $subjectId)) {
            return [new ErasureCategory('guardian_record', ErasureCategory::COMPLETED, 'subject_absent')];
        }

        if ($this->holds->isHeld($school->id)) {
            return array_map(fn (string $c) => new ErasureCategory($c, ErasureCategory::LEGAL_HOLD, 'school_hold'), ['guardian_personal_data', 'guardian_relationships']);
        }

        return [
            $this->personalData($school, $subjectId),
            new ErasureCategory('guardian_relationships', ErasureCategory::OUTSIDE_SCOPE, 'governed_by_student_retention'),
        ];
    }

    public function execute(?School $school, string $subjectId): array
    {
        $plan = $this->plan($school, $subjectId);
        $eligible = collect($plan)->contains(fn (ErasureCategory $c) => $c->category === 'guardian_personal_data' && $c->outcome === ErasureCategory::ELIGIBLE);

        if ($school !== null && $eligible) {
            $this->retention->prune($school, RetentionPeriod::yearsBeforeNow((int) ErasurePeriods::years('guardian_years')), $this->authorityCutoff(), 100, false, $subjectId);
        }

        return $this->plan($school, $subjectId);
    }

    private function personalData(School $school, string $guardianId): ErasureCategory
    {
        $years = ErasurePeriods::years('guardian_years');
        $lifecycle = $this->eligibility->lifecycleOf($school, $guardianId);
        $category = 'guardian_personal_data';

        if ($years === null) {
            return new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_not_configured');
        }
        if ($lifecycle === null || $lifecycle->state === GuardianLifecycle::RELATED) {
            return new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'subject_current');
        }
        if ($lifecycle->state === GuardianLifecycle::UNRESOLVED) {
            return new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'trigger_unresolved');
        }
        if (! $lifecycle->endedBefore(RetentionPeriod::yearsBeforeNow($years)->format('Y-m-d H:i:s'))) {
            return new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_running',
                CarbonImmutable::parse((string) $lifecycle->since, 'UTC')->addYearsNoOverflow($years)->addSecond()->toDateString());
        }

        $blocker = $this->retention->blockerFor($school, $guardianId, $this->authorityCutoff());

        return $blocker === null
            ? new ErasureCategory($category, ErasureCategory::ELIGIBLE, 'period_passed')
            : new ErasureCategory($category, ErasureCategory::DEPENDENCY_BLOCKED, $blocker);
    }

    private function authorityCutoff(): ?CarbonImmutable
    {
        $years = RetentionPeriod::years(config('retention.authority_history_years'));

        return $years === null ? null : RetentionPeriod::yearsBeforeNow($years);
    }
}
