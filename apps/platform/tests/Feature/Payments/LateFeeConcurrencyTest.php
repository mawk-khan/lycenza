<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\Exceptions\ChargeHasLiveLateFeeException;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Payments\Application\Exceptions\LateFeeAssessmentAlreadyVoidedException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunOpenConflictException;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\TestCase;

/**
 * FEE.5 (ADR 0062 §12, §16): real two-process races against real
 * PostgreSQL with forced, verified overlap (the contender is observed
 * blocked on the holder's uncommitted work before the holder commits).
 * Never SQLite, never a sequential simulation. The source is T1 5000.00.
 *
 * 1. Two workers on the same item -> one late fee.
 * 2. Two concurrent run creations for one rule -> one open run (so two
 *    runs can never race the same source and rule; the one-live key backs
 *    every other path, proven raw in LateFeeRlsIsolationTest).
 * 3. A payment holding the source makes a racing percentage late fee use
 *    the post-payment outstanding.
 * 4. The same for a concession approval.
 * 5. Source cancellation vs. execution, both orders.
 * 6. Two voids of one late fee -> one reversal.
 */
class LateFeeConcurrencyTest extends TestCase
{
    use CreatesLateFeeFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        DB::connection('pgsql_admin')->table('roles')
            ->where('key', 'like', 'test.capability_grant.%')
            ->where('created_at', '>=', $this->startedAt)
            ->delete();

        parent::tearDown();
    }

    private function world(array $rule = []): array
    {
        $w = $this->lateFeeWorld();
        $this->school = $w['school'];
        $w['rule'] = $this->activeRule($w, $rule);

        return $w;
    }

    /** A run that is executing with its item pending (the job is held back). */
    private function executingRun(array $w): array
    {
        Queue::fake();
        $run = $this->previewedLateRun($w, $w['rule']);
        $this->lateRuns()->execute($w['school'], $run->id, $w['actor']);

        return [$run, $this->lateItems($w, $run)->sole()];
    }

    private function script(string $name): string
    {
        return __DIR__."/../../Support/{$name}.php";
    }

    /** @return list<string> */
    private function executeItem(array $w, LateFeeRun $run, $item): array
    {
        return ['php', $this->script('race-late-fee'), 'execute-item', $w['school']->id, $run->id, $item->id];
    }

    #[Test]
    public function two_workers_on_the_same_item_create_one_late_fee(): void
    {
        $w = $this->world();
        [$run, $item] = $this->executingRun($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->executeItem($w, $run, $item), $this->executeItem($w, $run, $item));

        $this->assertSame('succeeded', $holder);
        $this->assertSame('not_pending', $contender, 'The second worker waited on the item lock, then found it done.');
        $this->assertCount(1, $this->lateFees($w));
        $this->assertCount(1, $this->outboxOf($w, 'late_fee.assessed.v1'));
    }

    #[Test]
    public function concurrent_run_creation_for_one_rule_leaves_one_open_run(): void
    {
        $w = $this->world();
        $command = ['php', $this->script('race-late-fee'), 'create-run', $w['school']->id, $w['actor']->id, $w['rule']->id, '2026-06-11'];

        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);

        $this->assertSame('created', $holder);
        $this->assertSame('rejected:'.LateFeeRunOpenConflictException::class, $contender);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => LateFeeRun::query()->count()));
    }

    #[Test]
    public function a_payment_holding_the_source_changes_a_racing_percentage_late_fee(): void
    {
        $w = $this->world(['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00']);
        [$run, $item] = $this->executingRun($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('race-manual-payment'), 'record', $w['school']->id, $w['recorder']->id, $w['settlement']->id, (string) Str::uuid(), '3000.00', "{$w['source']->id}:3000.00"],
            $this->executeItem($w, $run, $item),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('succeeded', $contender);
        $item = $this->lateItems($w, $run)->sole();
        $this->assertSame(['500.00', '2000.00', '200.00'], [$item->final_amount, $item->executed_outstanding_amount, $item->executed_amount], 'The late fee waited on the source lock and used the post-payment outstanding.');
    }

    #[Test]
    public function a_concession_holding_the_source_changes_a_racing_percentage_late_fee(): void
    {
        $w = $this->world(['kind' => 'percentage', 'fixed_amount' => null, 'percentage' => '10.00']);
        $concession = $this->requestTargeted($w, '1000.00', charge: $w['source']);
        [$run, $item] = $this->executingRun($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('race-fee-concession'), 'approve', $w['school']->id, $w['checker']->id, $concession->id],
            $this->executeItem($w, $run, $item),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('succeeded', $contender);
        $this->assertSame(['4000.00', '400.00'], [$this->lateItems($w, $run)->sole()->executed_outstanding_amount, $this->lateItems($w, $run)->sole()->executed_amount]);
    }

    #[Test]
    public function a_source_cancelled_first_makes_the_racing_late_fee_fail_closed(): void
    {
        $w = $this->world();
        [$run, $item] = $this->executingRun($w);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('race-late-fee'), 'void-source', $w['school']->id, $w['actor']->id, $assessment->id],
            $this->executeItem($w, $run, $item),
        );

        $this->assertSame('voided', $holder);
        $this->assertSame('failed', $contender);
        $this->assertSame('source_not_eligible', $this->lateItems($w, $run)->sole()->failure_reason);
        $this->assertCount(0, $this->lateFees($w));
    }

    #[Test]
    public function a_late_fee_committed_first_refuses_a_racing_source_cancellation(): void
    {
        $w = $this->world();
        [$run, $item] = $this->executingRun($w);
        $assessment = $this->inSchool($w['school'], fn () => FeeAssessment::query()->sole());

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->executeItem($w, $run, $item),
            ['php', $this->script('race-late-fee'), 'void-source', $w['school']->id, $w['actor']->id, $assessment->id],
        );

        $this->assertSame('succeeded', $holder);
        $this->assertSame('rejected:'.ChargeHasLiveLateFeeException::class, $contender);
        $this->assertNull($this->inSchool($w['school'], fn () => $assessment->refresh()->voided_at));
    }

    #[Test]
    public function two_voids_of_one_late_fee_make_one_reversal(): void
    {
        $w = $this->world();
        $this->executedLateRun($w, $w['rule']);
        $link = $this->lateFees($w)->sole();
        $command = ['php', $this->script('race-late-fee'), 'void', $w['school']->id, $w['actor']->id, $link->id];

        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);

        $this->assertSame('voided', $holder);
        $this->assertSame('rejected:'.LateFeeAssessmentAlreadyVoidedException::class, $contender);
        $this->assertNotNull($this->chargeOf($w, $link->charge_id)->cancellation_journal_entry_id);
    }
}
