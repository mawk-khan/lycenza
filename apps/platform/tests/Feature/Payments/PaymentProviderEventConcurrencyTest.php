<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 REQUIRED real-concurrency proof (rule 21/62): two
 * GENUINELY separate OS processes deliver the IDENTICAL normalized
 * provider event (same provider/provider_event_id/amount/reference)
 * concurrently. `payment_provider_events_event_unique` is the real
 * guarantee -- exactly one process's INSERT wins; the other blocks on
 * the conflicting unique index entry until the winner commits, then
 * observes the duplicate and safely replays. No duplicate Payment/
 * allocation/ledger effect is ever created.
 */
class PaymentProviderEventConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            // domain_event_outbox has no FK/cascade to schools (platform-
            // level table, ADR 0025) -- clean up this non-transactional
            // test's own real, committed outbox rows so they cannot
            // accumulate across the suite and compete with a LATER
            // test's own rows for DispatchOutboxEvents' fixed --batch
            // window.
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->school->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_real_concurrent_deliveries_of_the_identical_provider_event_leave_exactly_one_payment(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        $script = __DIR__.'/../../Support/record-settlement-event.php';
        $eventId = (string) Str::uuid();
        $reference = (string) Str::uuid();
        $args = [$this->school->id, $charge->id, $settlement->id, '500.00', $eventId, $reference];

        $processA = new Process(['php', $script, ...$args]);
        $processB = new Process(['php', $script, ...$args]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => str_starts_with($o, 'recognized:')),
            'One process must recognize the event. Got: '.implode(', ', $outputs)
        );
        $this->assertTrue(
            collect($outputs)->contains(fn ($o) => str_starts_with($o, 'duplicate_replay:')),
            'The other process must observe a safe duplicate replay, not an error. Got: '.implode(', ', $outputs)
        );

        $paymentIds = array_unique(array_map(fn ($o) => explode(':', $o)[1], $outputs));
        $this->assertCount(1, $paymentIds, 'Both processes must agree on the SAME payment id.');

        $this->assertSame(1, $context->withSchool($this->school, fn () => Payment::query()->count()));
        $this->assertSame(1, $context->withSchool($this->school, fn () => PaymentProviderEvent::query()->count()));
    }
}
