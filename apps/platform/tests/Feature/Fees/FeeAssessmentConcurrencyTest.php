<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunOpenConflictException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * FEE.2 (ADR 0062 §12): real two-process races against real PostgreSQL
 * with forced, verified overlap (the contender is observed blocked on the
 * holder's uncommitted work before the holder commits). Never SQLite, never
 * a sequential simulation.
 *
 * 1. Two concurrent run creations for one structure and period -> one run.
 * 2. Two workers executing the same item -> one charge.
 * 3. Two items with the same Student x year x fee head x period key (a
 *    campus transfer) executed concurrently -> one charge; the loser is
 *    skipped and leaves no charge or journal entry.
 * 4. An enrollment withdrawal racing an item -> the item sees the
 *    withdrawal and fails closed.
 * 5. A structure retirement racing an item -> the item fails closed.
 */
class FeeAssessmentConcurrencyTest extends TestCase
{
    use CreatesFeeAssessmentFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    private function script(string $name): string
    {
        return __DIR__."/../../Support/{$name}.php";
    }

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        // Committed data (no DatabaseTransactions): the fixture actors'
        // `test.capability_grant.*` roles outlive the School and would
        // otherwise leak Finance capabilities into later registry tests
        // (the GroupReportConcurrencyTest precedent).
        DB::connection('pgsql_admin')->table('roles')
            ->where('key', 'like', 'test.capability_grant.%')
            ->where('created_at', '>=', $this->startedAt)
            ->delete();

        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->assessmentWorld();
        $this->school = $w['school'];
        Queue::fake();

        return $w;
    }

    private function chargeCount(array $w): int
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->count());
    }

    #[Test]
    public function concurrent_run_creation_leaves_exactly_one_open_run(): void
    {
        $w = $this->world();
        $args = [$w['school']->id, $w['structure']->id, 'T1', $w['actor']->id];

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('create-fee-assessment-run'), ...$args],
            ['php', $this->script('create-fee-assessment-run'), ...$args],
        );

        $this->assertSame('created', $holder);
        $this->assertSame('rejected:'.FeeAssessmentRunOpenConflictException::class, $contender);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => FeeAssessmentRun::query()->count()));
    }

    #[Test]
    public function two_workers_on_the_same_item_create_one_charge(): void
    {
        $w = $this->world();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->sole();
        $args = [$w['school']->id, $run->id, $item->id];

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('execute-fee-assessment-item'), ...$args],
            ['php', $this->script('execute-fee-assessment-item'), ...$args],
        );

        $this->assertSame('succeeded', $holder);
        $this->assertSame('not_pending', $contender, 'The second worker waited on the item lock, then found it done.');
        $this->assertSame(1, $this->chargeCount($w));
    }

    #[Test]
    public function the_same_assessment_key_from_two_runs_has_exactly_one_financial_winner(): void
    {
        $w = $this->world();
        $north = $this->createCampus($w['school']);
        $override = $this->activeTwoTermStructure($w, ['code' => 'G-NORTH', 'campus_id' => $north->id], $w['head']);
        $student = $this->createStudent($w['school']);
        $this->enroll($w, '2026-06-01', ['status' => 'transferred', 'ends_on' => '2026-07-31'], null, $student);
        $this->enroll($w, '2026-08-01', [], $this->sectionIn($w, $north), $student);

        $default = $this->previewedRun($w);
        $northRun = $this->previewedRun($w, 'T1', $override);
        $this->runs()->execute($w['school'], $default->id, $w['actor']);
        $this->runs()->execute($w['school'], $northRun->id, $w['actor']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('execute-fee-assessment-item'), $w['school']->id, $default->id, $this->itemFor($w, $default, $student->id)->id],
            ['php', $this->script('execute-fee-assessment-item'), $w['school']->id, $northRun->id, $this->itemFor($w, $northRun, $student->id)->id],
        );

        $this->assertSame('succeeded', $holder);
        $this->assertSame('skipped_already_assessed', $contender, 'The loser met the one-live-assessment key and rolled back its charge.');
        $this->assertSame(1, $this->chargeCount($w));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => FeeAssessment::query()->count()));
    }

    #[Test]
    public function an_enrollment_withdrawal_racing_an_item_makes_it_fail_closed(): void
    {
        $w = $this->world();
        $enrollment = $this->enroll($w);
        $run = $this->previewedRun($w, 'T2');
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('withdraw-student-enrollment'), $w['school']->id, $enrollment->id, '2026-08-31'],
            ['php', $this->script('execute-fee-assessment-item'), $w['school']->id, $run->id, $item->id],
        );

        $this->assertSame('withdrawn', $holder);
        $this->assertSame('failed', $contender);
        $this->assertSame('enrollment_not_qualifying', $this->inSchool($w['school'], fn () => $item->refresh()->failure_reason));
        $this->assertSame(0, $this->chargeCount($w));
    }

    #[Test]
    public function a_structure_retirement_racing_an_item_makes_it_fail_closed(): void
    {
        $w = $this->world();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->script('retire-fee-structure'), $w['school']->id, $w['structure']->id, $w['actor']->id],
            ['php', $this->script('execute-fee-assessment-item'), $w['school']->id, $run->id, $item->id],
        );

        $this->assertSame('retired', $holder);
        $this->assertSame('failed', $contender);
        $this->assertSame('structure_not_active', $this->inSchool($w['school'], fn () => $item->refresh()->failure_reason));
        $this->assertSame(0, $this->chargeCount($w));
    }
}
