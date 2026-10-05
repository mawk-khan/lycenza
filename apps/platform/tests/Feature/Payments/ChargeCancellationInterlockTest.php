<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.5 (rule 12/46/47/48): a Charge with any recognized payment
 * allocation can never be cancelled; a Charge with none still cancels
 * exactly as 0G.4 established. The concurrency case (rule 48) proves
 * `ChargeService::cancel()`'s and
 * `ChargeService::lockChargeForAllocation()`'s shared `SELECT ... FOR
 * UPDATE` lock on the SAME Charge row resolves a genuine race to
 * exactly one coherent ordering, never both an allocation against a
 * cancelled Charge and a "successful" cancellation of an allocated one.
 */
class ChargeCancellationInterlockTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

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
            // E21-RH.6: the runtime role no longer deletes outbox rows.
            DB::connection('pgsql_admin')->table('domain_event_outbox')->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_charge_with_a_payment_allocation_cannot_be_cancelled(): void
    {
        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        $this->recordSettlement($this->school, $settlement->id, [[$charge, '500.00']], '500.00');

        $this->expectException(ChargeHasPaymentAllocationsException::class);
        app(ChargeService::class)->cancel($this->school, $charge->id);
    }

    #[Test]
    public function a_charge_without_any_payment_allocation_still_cancels_normally(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        app(ChargeService::class)->cancel($this->school, $charge->id);

        $reloaded = $context->withSchool($this->school, fn () => Charge::query()->findOrFail($charge->id));
        $this->assertNotNull($reloaded->cancelled_at);
    }

    #[Test]
    public function a_real_concurrent_allocation_and_cancellation_of_the_same_charge_produce_one_coherent_outcome(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');

        $allocateScript = __DIR__.'/../../Support/allocate-payment.php';
        $cancelScript = __DIR__.'/../../Support/cancel-charge.php';

        $allocateProcess = new Process(['php', $allocateScript, $this->school->id, $charge->id, $settlement->id, '500.00']);
        $cancelProcess = new Process(['php', $cancelScript, $this->school->id, $charge->id]);
        $allocateProcess->start();
        $cancelProcess->start();
        $allocateProcess->wait();
        $cancelProcess->wait();

        $allocateOutput = $allocateProcess->getOutput();
        $cancelOutput = $cancelProcess->getOutput();

        $allocationSucceeded = str_starts_with($allocateOutput, 'settled:');
        $cancellationSucceeded = $cancelOutput === 'cancelled';

        $this->assertNotSame(
            $allocationSucceeded,
            $cancellationSucceeded,
            "Exactly one of allocate/cancel must win, never both or neither. allocate={$allocateOutput} cancel={$cancelOutput}"
        );

        $reloaded = $context->withSchool($this->school, fn () => Charge::query()->findOrFail($charge->id));

        if ($allocationSucceeded) {
            $this->assertNull($reloaded->cancelled_at, 'If the allocation won, the charge must remain active.');
        } else {
            $this->assertNotNull($reloaded->cancelled_at, 'If the cancellation won, the charge must be cancelled.');
        }
    }
}
