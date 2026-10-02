<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Finance\Concerns\CreatesFinanceRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3A2 (E21-D8): real two-process races between Finance retention
 * expiry and the operations that could touch the evidence it removes.
 * Whatever commits first, no dangling link survives, nothing still needed
 * is deleted, and the dual-read check reconciles.
 *
 * Commits its fixtures: the processes are separate PostgreSQL sessions.
 */
class FinanceRetentionConcurrencyTest extends TestCase
{
    use CreatesFinanceRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private array $schools = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->purgeCommittedSchools($this->schools);
        parent::tearDown();
    }

    private function world(bool $closeSecondYear = true): array
    {
        $w = $this->d8World($closeSecondYear);
        $this->schools[] = $w['school'];

        return $w;
    }

    /** @return list<string> */
    private function retentionOp(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/finance-retention-op.php', ...$args];
    }

    /** @return list<string> */
    private function periodOp(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/finance-period-op.php', ...$args];
    }

    /**
     * The holder acts and holds its transaction; the contender runs to
     * completion meanwhile (it must not need anything the holder locked).
     *
     * @return array{0: string, 1: string}
     */
    private function withoutConflict(array $holderCommand, array $contenderCommand): array
    {
        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($holderCommand, null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        try {
            $holder->start();
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'The holder never acted: '.$holder->getOutput().$holder->getErrorOutput());
                usleep(2_000);
            }
            $contender = new Process($contenderCommand);
            $contender->setTimeout(60);
            $contender->run();
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        return [trim($holder->getOutput()), trim($contender->getOutput())];
    }

    #[Test]
    public function a_new_posting_in_the_open_year_runs_freely_while_expiry_holds_and_is_never_included(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->withoutConflict(
            $this->retentionOp('prune', $w['school']->id),
            $this->periodOp('post', $w['school']->id, $w['settlement']->id, $w['revenue']->id, '3.00'),
        );

        $this->assertSame('pruned:deleted=4,blocked=2,errors=0,verification_failed=0', $holder);
        $this->assertStringStartsWith('posted:', $contender, 'A posting in the open year never waits for expiry.');
        $this->assertTrue($this->rowExists($w, 'journal_entries', substr($contender, strlen('posted:'))));
        $this->assertFalse($this->rowExists($w, 'journal_entries', $w['m1']));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_reversal_committed_first_keeps_the_evidence_it_links_to(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->periodOp('reverse', $w['school']->id, $w['m1r']),
            $this->retentionOp('prune', $w['school']->id),
        );

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertStringStartsWith('pruned:', $contender);
        $this->assertStringNotContainsString('verification_failed=1', $contender);
        foreach (['m1', 'm1r'] as $e) {
            $this->assertTrue($this->rowExists($w, 'journal_entries', $w[$e]), "{$e} is still linked from the new reversal");
        }
        $this->assertTrue($this->rowExists($w, 'journal_entries', substr($holder, strlen('reversed:'))));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function an_expiry_committed_first_makes_a_later_reversal_fail_safely_with_no_dangling_link(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->retentionOp('prune', $w['school']->id),
            $this->periodOp('reverse', $w['school']->id, $w['m1r']),
        );

        $this->assertSame('pruned:deleted=4,blocked=2,errors=0,verification_failed=0', $holder);
        $this->assertStringStartsWith('rejected:', $contender, 'The reversal waited, then found its original gone.');
        $this->assertFalse($this->rowExists($w, 'journal_entries', $w['m1r']));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('journal_entries')->whereIn('reversal_of_journal_entry_id', [$w['m1'], $w['m1r']])->count()));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function an_adjustment_void_committed_first_keeps_the_now_owing_charge(): void
    {
        $w = $this->world();
        $adjustment = $this->adjustmentsOf($w, $w['c4']->id)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->retentionOp('void-adjustment', $w['school']->id, $adjustment->id, $w['checker']->id),
            $this->retentionOp('prune', $w['school']->id),
        );

        $this->assertSame('voided', $holder);
        $this->assertStringStartsWith('pruned:', $contender);
        $this->assertTrue($this->rowExists($w, 'charges', $w['c4']->id), 'c4 owes 400.00 again: it must stay');
        $this->assertSame('400.00', $this->golden($w, [$w['c4']->id])["charge:{$w['c4']->id}"]);
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function an_expiry_committed_first_makes_a_later_void_fail_safely(): void
    {
        $w = $this->world();
        $adjustment = $this->adjustmentsOf($w, $w['c4']->id)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->retentionOp('prune', $w['school']->id),
            $this->retentionOp('void-adjustment', $w['school']->id, $adjustment->id, $w['checker']->id),
        );

        $this->assertSame('pruned:deleted=4,blocked=2,errors=0,verification_failed=0', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertFalse($this->rowExists($w, 'charges', $w['c4']->id));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_payment_on_a_retained_charge_runs_freely_while_expiry_holds(): void
    {
        $w = $this->world();
        $payment = ['php', __DIR__.'/../../Support/race-manual-payment.php', 'record', $w['school']->id, $w['recorder']->id, $w['settlement']->id, (string) Str::uuid(), '10.00', "{$w['c3']->id}:10.00"];

        [$holder, $contender] = $this->withoutConflict($this->retentionOp('prune', $w['school']->id), $payment);

        $this->assertSame('pruned:deleted=4,blocked=2,errors=0,verification_failed=0', $holder);
        $this->assertStringStartsWith('recorded:', $contender);
        $this->assertSame('740.00', $this->golden($w, [$w['c3']->id])["charge:{$w['c3']->id}"], '800 - 50 - 10');
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_close_and_an_expiry_serialize_on_the_period_maintenance_lock(): void
    {
        $w = $this->world(closeSecondYear: false);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->retentionOp('prune', $w['school']->id),
            $this->periodOp('close', $w['school']->id, $w['closer']->id, $w['fy1617']->id, '2016-17'),
        );

        $this->assertSame('pruned:deleted=4,blocked=2,errors=0,verification_failed=0', $holder);
        $this->assertSame('closed:2016-17', $contender, 'The close waited for the expiry, then closed on the remaining detail.');
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function an_expiry_waits_for_a_running_close(): void
    {
        $w = $this->world(closeSecondYear: false);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->periodOp('close', $w['school']->id, $w['closer']->id, $w['fy1617']->id, '2016-17'),
            $this->retentionOp('prune', $w['school']->id),
        );

        $this->assertSame('closed:2016-17', $holder);
        $this->assertStringStartsWith('pruned:', $contender);
        $this->assertStringContainsString('verification_failed=0', $contender);
        $this->assertSame('closed', $this->periodByKey($w['school'], '2016-17')->status);
        $this->assertSame([], $this->verifyBalances($w['school']));
    }
}
