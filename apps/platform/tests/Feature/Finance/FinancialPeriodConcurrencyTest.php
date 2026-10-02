<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §4): real two-process races between a financial-period
 * close and every kind of posting. The close locks the School's open
 * periods FOR UPDATE; every posting holds its period FOR SHARE. Whichever
 * side holds first, the other waits, both complete, the posting lands in
 * the open period, and the dual-read check still reconciles. Also the race
 * between a School's first posting and a start-month change.
 *
 * Commits its fixtures: the processes are separate PostgreSQL sessions.
 */
class FinancialPeriodConcurrencyTest extends TestCase
{
    use CreatesFinancialPeriodFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private array $schools = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->purgeCommittedSchools($this->schools);
        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->twoYearWorld();
        $this->schools[] = $w['school'];

        return $w;
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/finance-period-op.php', ...$args];
    }

    /** @return list<string> */
    private function closing(array $w): array
    {
        return $this->op('close', $w['school']->id, $w['closer']->id, $w['fy2526']->id, '2025-26');
    }

    /** @return list<string> */
    private function posting(array $w): array
    {
        return $this->op('post', $w['school']->id, $w['settlement']->id, $w['revenue']->id, '7.00');
    }

    private function assertReconciledAfter(array $w, string $postedEntryId): void
    {
        $this->assertSame('closed', $this->periodByKey($w['school'], '2025-26')->status);
        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $postedEntryId), 'The posting landed in the open period.');
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_held_posting_delays_the_close_which_then_includes_it(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->posting($w), $this->closing($w));

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertSame('closed:2025-26', $contender, 'The close waited for the posting and then closed.');
        $this->assertReconciledAfter($w, substr($holder, strlen('posted:')));
    }

    #[Test]
    public function a_held_close_delays_a_journal_posting_into_the_open_period(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->closing($w), $this->posting($w));

        $this->assertSame('closed:2025-26', $holder);
        $this->assertStringStartsWith('posted:', $contender);
        $this->assertReconciledAfter($w, substr($contender, strlen('posted:')));
    }

    #[Test]
    public function a_held_close_delays_a_manual_payment(): void
    {
        $w = $this->world();
        $payment = ['php', __DIR__.'/../../Support/race-manual-payment.php', 'record', $w['school']->id, $w['recorder']->id, $w['settlement']->id, (string) Str::uuid(), '40.00', "{$w['c4']->id}:40.00"];

        [$holder, $contender] = $this->raceWithHeldHolder($this->closing($w), $payment);

        $this->assertSame('closed:2025-26', $holder);
        $this->assertStringStartsWith('recorded:', $contender);
        $entry = $this->inSchool($w['school'], fn () => DB::table('payments')->where('id', explode(':', $contender)[1])->value('journal_entry_id'));
        $this->assertReconciledAfter($w, $entry);
    }

    #[Test]
    public function a_held_close_delays_a_reversal_of_closed_history(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->closing($w), $this->op('reverse', $w['school']->id, $w['manual']->id));

        $this->assertSame('closed:2025-26', $holder);
        $this->assertStringStartsWith('reversed:', $contender);
        $this->assertReconciledAfter($w, substr($contender, strlen('reversed:')));
        $this->assertSame('2025-26', $this->entryPeriodKey($w['school'], $w['manual']->id), 'The original keeps its closed period.');
    }

    #[Test]
    public function a_held_close_delays_a_payroll_posting(): void
    {
        $w = $this->world();
        $run = $this->approvedPayrollRun($w['school']);
        $poster = $this->createUser();

        [$holder, $contender] = $this->raceWithHeldHolder($this->closing($w), $this->op('payroll-post', $w['school']->id, $run->id, $poster->id));

        $this->assertSame('closed:2025-26', $holder);
        $this->assertStringStartsWith('posted:', $contender);
        $this->assertReconciledAfter($w, substr($contender, strlen('posted:')));
    }

    #[Test]
    public function two_closes_of_the_same_period_close_it_once(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->closing($w), $this->closing($w));

        $this->assertSame('closed:2025-26', $holder);
        $this->assertStringContainsString('FinancialPeriodCloseRefusedException', $contender);
        $this->assertStringContainsString('period_already_closed', $contender);
        $this->assertSame(1, $this->auditCount($w['school'], 'financial_period.closed'));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_start_month_change_committed_first_shapes_the_first_period(): void
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $actor = $this->createUserWithCapabilities($school, ['finance.fee_structures.view', 'finance.fee_structures.manage']);
        $cash = $this->createLedgerAccount($school, ['code' => 'CASH', 'type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['code' => 'INC', 'type' => 'income']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('set-month', $school->id, $actor->id, '1'),
            $this->op('post', $school->id, $cash->id, $income->id, '1.00'),
        );

        $this->assertSame('month:1', $holder);
        $this->assertStringStartsWith('posted:', $contender, 'The waiting first posting retried with the committed month.');
        $period = $this->inSchool($school, fn () => FinancialPeriod::query()->sole());
        $this->assertSame(Carbon::now($school->timezone)->year.'-01-01', $period->starts_on->toDateString(), 'The first period follows the January start committed first.');
    }

    #[Test]
    public function a_start_month_change_during_a_first_posting_is_refused(): void
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $actor = $this->createUserWithCapabilities($school, ['finance.fee_structures.view', 'finance.fee_structures.manage']);
        $cash = $this->createLedgerAccount($school, ['code' => 'CASH', 'type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['code' => 'INC', 'type' => 'income']);

        // The posting holds the period-creation lock uncommitted. The month
        // change only TRIES that lock (waiting could deadlock with the Fees
        // settings lock), so it is refused at once instead of waiting.
        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($this->op('post', $school->id, $cash->id, $income->id, '1.00'), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        try {
            $holder->start();
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'The posting never reached its held write: '.$holder->getOutput());
                usleep(2_000);
            }
            $contender = new Process($this->op('set-month', $school->id, $actor->id, '1'));
            $contender->setTimeout(60);
            $contender->run();
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        $this->assertStringStartsWith('posted:', trim($holder->getOutput()));
        $this->assertStringContainsString('ReceiptNumberingLockedException', $contender->getOutput());
        $this->assertSame(4, (int) $this->inSchool($school, fn () => DB::table('fee_settings')->value('financial_year_start_month') ?? 4));
        $this->assertSame('04-01', $this->inSchool($school, fn () => FinancialPeriod::query()->sole())->starts_on->format('m-d'));
    }
}
