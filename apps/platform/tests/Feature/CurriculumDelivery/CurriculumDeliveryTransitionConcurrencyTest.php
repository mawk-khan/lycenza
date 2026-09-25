<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryStatusChangedException;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof: two GENUINELY separate OS processes
 * -- not two sequential calls in one PHP process -- both attempt to
 * complete the SAME in-progress delivery with the SAME
 * `expected_status` against real PostgreSQL, at the same time.
 *
 * The row lock plus the expected-status compare-and-swap inside
 * CurriculumDeliveryService::transition() is what makes this safe. This
 * test proves the final state, not just that the application code
 * "looks" correct: exactly one process wins, the other is refused with
 * DeliveryStatusChangedException, and the row ends completed exactly
 * once with a single completion date.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures this
 * test creates (see $connectionsToTransact) -- the two subprocesses are
 * separate PostgreSQL sessions and can never see this test process's
 * uncommitted rows, mirroring AcademicYearActivationConcurrencyTest's
 * identical reasoning.
 */
class CurriculumDeliveryTransitionConcurrencyTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            // Cascades through school_id; every other parent FK is
            // RESTRICT, so the delivery row must go with its School.
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_processes_completing_one_delivery_leave_exactly_one_winner(): void
    {
        $w = $this->deliveryWorld();
        $this->school = $w['school'];

        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit'], [
            'started_on' => $this->today()->subDays(5)->toDateString(),
        ]);
        $completedOn = $this->today()->subDay()->toDateString();

        $script = __DIR__.'/../../Support/transition-curriculum-delivery.php';
        $args = [$w['school']->id, $delivery->id, 'in_progress', 'completed', $completedOn, $w['actor']->id];

        $processA = new Process(array_merge(['php', $script], $args));
        $processB = new Process(array_merge(['php', $script], $args));
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $completed = array_filter($outputs, fn ($o) => $o === 'transitioned:completed');
        $refused = array_filter($outputs, fn ($o) => $o === 'rejected:'.DeliveryStatusChangedException::class);

        $this->assertCount(1, $completed,
            'Exactly one of the two concurrent completions must succeed. Outputs: '.implode(' | ', $outputs));
        $this->assertCount(1, $refused,
            'The loser must be refused by the compare-and-swap, not by an unrelated error. Outputs: '.implode(' | ', $outputs));

        $final = $this->inDeliverySchool(
            $w['school'],
            fn () => CurriculumDelivery::query()->where('id', $delivery->id)->firstOrFail(),
        );

        $this->assertSame('completed', $final->status);
        $this->assertSame($completedOn, $final->completed_on->toDateString());
        $this->assertSame(1, $this->inDeliverySchool(
            $w['school'],
            fn () => CurriculumDelivery::query()->where('section_id', $w['section']->id)->count(),
        ), 'The race must not have produced a second delivery row.');
    }
}
