<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 9.11 (Security/Concurrency/Migration Closure) -- REQUIRED
 * real-concurrency proof that `PayrollPeriodService::open()`'s new
 * conditional-UPDATE claim (`WHERE status = 'draft'`) is genuinely
 * race-safe, mirroring `PayrollRunLifecycleConcurrencyTest::scenario_c`'s
 * exact pattern: two GENUINELY separate OS processes race `open()`
 * against real PostgreSQL, not a sequential simulation.
 *
 * Before this checkpoint, `open()`/`close()` were a bare, unconditional
 * `$period->update([...])` -- two concurrent callers would both
 * silently "succeed" with no conflict signal at all. This proves
 * exactly one process wins and the other receives
 * `ConcurrentPeriodTransitionConflictException`.
 */
class PayrollPeriodConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->school->delete();
            } catch (\Throwable) {
                // Best-effort only, matching PayrollRunLifecycleConcurrencyTest's
                // identical rationale.
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_processes_opening_the_same_period_leave_exactly_one_winner(): void
    {
        $this->school = $this->createSchool();
        $preparer = $this->createUser();
        $context = app(TenantContext::class);

        $period = $context->withSchool(
            $this->school,
            fn () => app(PayrollPeriodService::class)->createPeriod($this->school, Carbon::parse('2026-09-01'), null, $preparer),
        );

        // Forced, verified overlap (Tests\Concerns\ForcesConcurrentOverlap):
        // two processes merely started together can run one after the
        // other, and a SEQUENTIAL second open() fails its status
        // pre-check with InvalidPeriodTransitionException instead. B must
        // be proven blocked on A's uncommitted status update.
        $script = __DIR__.'/../../Support/open-payroll-period.php';
        $outputs = $this->raceWithHeldHolder(
            ['php', $script, $this->school->id, $period->id, $preparer->id],
            ['php', $script, $this->school->id, $period->id, $preparer->id],
        );
        $this->assertSame('opened', $outputs[0], 'The first (held) open() must succeed.');
        $openedCount = count(array_filter($outputs, fn ($o) => $o === 'opened'));

        $this->assertSame(1, $openedCount, 'Exactly one of the two concurrent open() calls must succeed.');
        $this->assertTrue(
            in_array('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\ConcurrentPeriodTransitionConflictException', $outputs, true),
            'The loser must receive a domain-specific concurrency exception, got: '.implode(', ', $outputs),
        );

        $fresh = $context->withSchool($this->school, fn () => PayrollPeriod::query()->findOrFail($period->id));
        $this->assertSame('open', $fresh->status);
    }
}
