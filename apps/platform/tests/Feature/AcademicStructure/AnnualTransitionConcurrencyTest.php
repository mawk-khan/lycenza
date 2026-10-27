<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\RolloverTargetYearNotDraftException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS 5.2: a rollover execution and the activation of its target year, in two
 * real processes with a forced, observed overlap. The execution share-locks
 * the target year from its claim on; activation locks it FOR UPDATE and is
 * refused while a rollover into it is executing. Whichever commits first
 * decides: the rollover completes under draft and activation then proceeds,
 * or activation wins and the rollover refuses before touching any Student.
 * There is never a half-executed rollover in a year that became active.
 */
class AnnualTransitionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @return array{school: School, next: AcademicYear, plan: EnrollmentRolloverPlan} */
    private function world(): array
    {
        $school = $this->createSchool();
        $today = CarbonImmutable::parse(SchoolTimezone::today($school));
        $campus = $this->createCampus($school);
        $g5 = $this->createGradeLevel($school, ['code' => 'G5', 'sequence' => 5]);
        $g6 = $this->createGradeLevel($school, ['code' => 'G6', 'sequence' => 6]);
        $current = $this->createAcademicYear($school, ['code' => 'Y1', 'status' => 'active', 'starts_on' => $today->subDays(430)->toDateString(), 'ends_on' => $today->subDays(31)->toDateString()]);
        $next = $this->createAcademicYear($school, ['code' => 'Y2', 'status' => 'draft', 'starts_on' => $today->subDays(30)->toDateString(), 'ends_on' => $today->addDays(330)->toDateString()]);
        $source = $this->createSection($current, $campus, $g5, ['code' => 'S5', 'status' => 'active']);
        $target = $this->createSection($next, $campus, $g6, ['code' => 'T6', 'status' => 'active']);
        foreach (['1', '2'] as $roll) {
            $this->createStudentEnrollment($this->createStudent($school), $source, ['roll_number' => $roll]);
        }
        $plans = app(EnrollmentRolloverPlanService::class);
        $plan = $plans->createDraft($school, $current, $next);
        $plans->upsertMapping($plan, $g5, null, $g6, $target);
        $in = fn (callable $fn) => app(TenantContext::class)->withSchool($school, $fn);
        app(EnrollmentRolloverDryRunService::class)->run($in(fn () => $plan->fresh()));
        foreach ($in(fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->get()) as $item) {
            $plans->setItemDecision($in(fn () => $plan->fresh()), $item, 'promote', null, 'preserve_source');
        }
        app(EnrollmentRolloverDryRunService::class)->run($in(fn () => $plan->fresh()));
        $this->assertSame('validated', $in(fn () => $plan->fresh())->status);

        return ['school' => $school, 'next' => $next, 'plan' => $plan];
    }

    /** @return array<int, string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/annual-transition-op.php', ...$args];
    }

    /** @return array{0: string, 1: string, 2: int} [target status, plan status, placements in the target] */
    private function outcome(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => [
            AcademicYear::query()->findOrFail($w['next']->id)->status,
            EnrollmentRolloverPlan::query()->findOrFail($w['plan']->id)->status,
            StudentEnrollment::query()->where('academic_year_id', $w['next']->id)->count(),
        ]);
    }

    #[Test]
    public function a_rollover_that_commits_first_completes_in_draft_and_the_waiting_activation_then_proceeds(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('rollover', $w['school']->id, $w['plan']->id),
            $this->op('activate', $w['school']->id, $w['next']->id),
        );

        $this->assertSame('rolled:completed', $holder);
        $this->assertSame('activated', $contender);
        $this->assertSame(['active', 'completed', 2], $this->outcome($w));
    }

    #[Test]
    public function an_activation_that_commits_first_makes_the_waiting_rollover_refuse_before_any_student(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('activate', $w['school']->id, $w['next']->id),
            $this->op('rollover', $w['school']->id, $w['plan']->id),
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:'.RolloverTargetYearNotDraftException::class, $contender);
        $this->assertSame(['active', 'validated', 0], $this->outcome($w));
    }
}
