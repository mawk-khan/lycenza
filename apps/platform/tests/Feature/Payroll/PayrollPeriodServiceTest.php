<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\Exceptions\InvalidPeriodTransitionException;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.11 (Security/Concurrency/Migration Closure) -- proves
 * `PayrollPeriodService`'s `draft -> open -> closed` state machine,
 * previously unenforced (a bare, unconditional `$period->update()`
 * with no state validation at all). See `PayrollPeriodConcurrencyTest`
 * for the real-two-process race proof of the same guarantee.
 */
class PayrollPeriodServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function makePeriod(School $school, User $actor): PayrollPeriod
    {
        return $this->context()->withSchool(
            $school,
            fn () => app(PayrollPeriodService::class)->createPeriod($school, Carbon::parse('2026-09-01'), null, $actor),
        );
    }

    #[Test]
    public function a_draft_period_can_be_opened(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);

        $opened = $this->context()->withSchool($school, fn () => app(PayrollPeriodService::class)->open($period, $actor));

        $this->assertSame('open', $opened->status);
    }

    #[Test]
    public function an_open_period_can_be_closed(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);
        $this->context()->withSchool($school, fn () => app(PayrollPeriodService::class)->open($period, $actor));

        $closed = $this->context()->withSchool(
            $school,
            fn () => app(PayrollPeriodService::class)->close(PayrollPeriod::query()->findOrFail($period->id), $actor),
        );

        $this->assertSame('closed', $closed->status);
    }

    #[Test]
    public function opening_an_already_open_period_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);
        $this->context()->withSchool($school, fn () => app(PayrollPeriodService::class)->open($period, $actor));

        $this->expectException(InvalidPeriodTransitionException::class);
        $this->context()->withSchool(
            $school,
            fn () => app(PayrollPeriodService::class)->open(PayrollPeriod::query()->findOrFail($period->id), $actor),
        );
    }

    #[Test]
    public function closing_a_still_draft_period_is_rejected_never_skipping_open(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);

        $this->expectException(InvalidPeriodTransitionException::class);
        $this->context()->withSchool($school, fn () => app(PayrollPeriodService::class)->close($period, $actor));
    }

    #[Test]
    public function closing_an_already_closed_period_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);
        $this->context()->withSchool($school, function () use ($period, $actor) {
            app(PayrollPeriodService::class)->open($period, $actor);
            app(PayrollPeriodService::class)->close(PayrollPeriod::query()->findOrFail($period->id), $actor);
        });

        $this->expectException(InvalidPeriodTransitionException::class);
        $this->context()->withSchool(
            $school,
            fn () => app(PayrollPeriodService::class)->close(PayrollPeriod::query()->findOrFail($period->id), $actor),
        );
    }

    #[Test]
    public function reopening_a_closed_period_is_rejected(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser();
        $period = $this->makePeriod($school, $actor);
        $this->context()->withSchool($school, function () use ($period, $actor) {
            app(PayrollPeriodService::class)->open($period, $actor);
            app(PayrollPeriodService::class)->close(PayrollPeriod::query()->findOrFail($period->id), $actor);
        });

        $this->expectException(InvalidPeriodTransitionException::class);
        $this->context()->withSchool(
            $school,
            fn () => app(PayrollPeriodService::class)->open(PayrollPeriod::query()->findOrFail($period->id), $actor),
        );
    }

    // The stale-model/lost-race case (a concurrent transition winning
    // between the up-front check and the conditional UPDATE) is proven
    // with two GENUINELY separate OS processes, not simulated here --
    // see PayrollPeriodConcurrencyTest, mirroring
    // ConcurrentRunApprovalConflictException's identical precedent
    // (proven only by PayrollRunLifecycleConcurrencyTest, no
    // single-process unit-test equivalent exists for it either).
}
