<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Application\Exceptions\CorrectionTargetAlreadyReversedException;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollCorrections;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C8 (PAY-17), committed, in separate OS processes with the contender observed
 * blocked. A reversal holds the target FOR UPDATE; creating, approving or posting a
 * correction holds it FOR SHARE. So each is ordered wholly before or after the other.
 */
class PayrollCorrectionTargetConcurrencyTest extends TestCase
{
    use BuildsPayrollCorrections, BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private const REFUSED = 'rejected:'.CorrectionTargetAlreadyReversedException::class;

    private PayrollRun $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->travelBack();
        $this->target = $this->postedTarget('2026-08-01');
        $this->travelBack();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeSchoolsAsAdmin([$this->school->id]);
        } catch (\Throwable) {
            // Frozen results make the School hard to delete; residue is reset between sessions.
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function reverseCommand(): array
    {
        return ['php', __DIR__.'/../../Support/reverse-payroll-run.php', $this->school->id, $this->target->id, $this->finance->id];
    }

    /** @return list<string> */
    private function postCommand(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $run->id, $this->finance->id];
    }

    /** @return list<string> */
    private function approveCommand(PayrollRun $run, User $as): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $run->id, $as->id];
    }

    /** @return list<string> */
    private function createCommand(string $periodId): array
    {
        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'create-correction', $this->school->id, $this->target->id, $periodId, $this->preparer->id];
    }

    private function corrections(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('corrects_payroll_run_id', $this->target->id)->count());
    }

    #[Test]
    public function a_reversal_held_open_refuses_a_waiting_creation(): void
    {
        $period = $this->openPeriod('2026-09-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->createCommand($period->id));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame(self::REFUSED, $contender);
        $this->assertSame(0, $this->corrections());
    }

    #[Test]
    public function a_creation_held_open_is_ordered_first_and_the_correction_then_can_never_be_approved(): void
    {
        $period = $this->openPeriod('2026-09-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->createCommand($period->id), $this->reverseCommand());

        $this->assertSame('ok', $holder);
        $this->assertStringStartsWith('reversed:', $contender, 'a reversal is never refused, only ordered');
        $this->assertSame(1, $this->corrections());
    }

    #[Test]
    public function a_reversal_held_open_refuses_a_waiting_approval(): void
    {
        $correction = $this->calculatedCorrection($this->target, '2026-09-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->approveCommand($correction, $this->checker));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame(self::REFUSED, $contender);
        $this->assertSame('calculated', $this->runRow($correction)['status']);
    }

    #[Test]
    public function an_approval_held_open_is_ordered_first_and_posting_is_then_refused(): void
    {
        $correction = $this->calculatedCorrection($this->target, '2026-09-01');

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($correction, $this->checker), $this->reverseCommand());

        $this->assertSame('approved', $holder);
        $this->assertStringStartsWith('reversed:', $contender);
        $this->expectException(CorrectionTargetAlreadyReversedException::class);
        $this->postRun($correction);
    }

    #[Test]
    public function a_reversal_held_open_refuses_a_waiting_posting(): void
    {
        $correction = $this->calculatedCorrection($this->target, '2026-09-01');
        $this->approve($correction, $this->checker);

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand(), $this->postCommand($correction));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame(self::REFUSED, $contender);
        $this->assertSame(0, $this->postings($correction));
        $this->assertSame([], $this->audit('payroll.run.reversed', $this->target)[0]['standingCorrectionRunIds']);
    }

    #[Test]
    public function a_posting_held_open_is_ordered_first_and_the_waiting_reversal_names_it(): void
    {
        $correction = $this->calculatedCorrection($this->target, '2026-09-01');
        $this->approve($correction, $this->checker);

        [$holder, $contender] = $this->raceWithHeldHolder($this->postCommand($correction), $this->reverseCommand());

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertStringStartsWith('reversed:', $contender);
        $this->assertSame([$correction->id], $this->audit('payroll.run.reversed', $this->target)[0]['standingCorrectionRunIds']);
    }
}
