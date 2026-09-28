<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment section 10): manual
 * recording under REAL overlapping PostgreSQL transactions in two separate
 * OS processes. The holder commits nothing until the contender is
 * observed blocked on its lock (ForcesConcurrentOverlap) -- the overlap is
 * proven, never assumed from timing.
 */
class ManualPaymentConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var list<string> */
    private array $userIds = [];

    private object $settlement;

    private object $chargeX;

    private object $chargeY;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $this->settlement = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $this->chargeX = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');
        $this->chargeY = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');
        $this->alice = $this->createPaymentRecorder($this->school);
        $this->bob = $this->createPaymentRecorder($this->school);
        $this->userIds = [$this->alice->id, $this->bob->id];
    }

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            DomainEventOutbox::query()->where('school_id', $this->school->id)->delete();
            $this->deleteSchoolAsAdmin($this->school);
            $admin = DB::connection('pgsql_admin');
            $admin->table('school_memberships')->whereIn('user_id', $this->userIds)->delete();
            $admin->table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function record(User $user, string $key, string $amount, string $allocations): array
    {
        return ['php', __DIR__.'/../../Support/race-manual-payment.php', 'record', $this->school->id, $user->id, $this->settlement->id, $key, $amount, $allocations];
    }

    private function payments(): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Payment::query()->count());
    }

    private function allocatedTo(object $charge): string
    {
        return (string) app(TenantContext::class)->withSchool($this->school, fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount'));
    }

    #[Test]
    public function a_duplicate_submission_racing_the_first_replays_it_instead_of_recording_twice(): void
    {
        $key = (string) Str::uuid();
        $command = $this->record($this->alice, $key, '1000.00', "{$this->chargeX->id}:1000.00");

        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('duplicate_replay:'.substr($holder, strlen('recorded:')), $contender);
        $this->assertSame(1, $this->payments());
        $this->assertSame('1000.00', $this->allocatedTo($this->chargeX));
    }

    #[Test]
    public function the_same_key_with_different_content_racing_fails_closed(): void
    {
        $key = (string) Str::uuid();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, $key, '100.00', "{$this->chargeX->id}:100.00"),
            $this->record($this->alice, $key, '200.00', "{$this->chargeY->id}:200.00"),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('error:App\Domain\Payments\Application\Exceptions\ManualPaymentIdempotencyConflictException', $contender);
        $this->assertSame(1, $this->payments());
        $this->assertSame('0', $this->allocatedTo($this->chargeY));
    }

    #[Test]
    public function two_users_racing_for_the_same_remaining_balance_leave_exactly_one_winner(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, (string) Str::uuid(), '700.00', "{$this->chargeX->id}:700.00"),
            $this->record($this->bob, (string) Str::uuid(), '700.00', "{$this->chargeX->id}:700.00"),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('error:App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException', $contender);
        $this->assertSame(1, $this->payments());
        $this->assertSame('700.00', $this->allocatedTo($this->chargeX));
    }

    #[Test]
    public function two_payments_within_the_remaining_balance_both_succeed_in_order(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, (string) Str::uuid(), '600.00', "{$this->chargeX->id}:600.00"),
            $this->record($this->bob, (string) Str::uuid(), '400.00', "{$this->chargeX->id}:400.00"),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertStringStartsWith('recorded:', $contender);
        $this->assertSame('1000.00', $this->allocatedTo($this->chargeX));
    }

    #[Test]
    public function multi_charge_payments_naming_charges_in_opposite_orders_never_deadlock(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, (string) Str::uuid(), '600.00', "{$this->chargeX->id}:300.00,{$this->chargeY->id}:300.00"),
            $this->record($this->bob, (string) Str::uuid(), '600.00', "{$this->chargeY->id}:300.00,{$this->chargeX->id}:300.00"),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertStringStartsWith('recorded:', $contender, 'Ascending Charge lock order must serialize, not deadlock.');
        $this->assertSame('600.00', $this->allocatedTo($this->chargeX));
        $this->assertSame('600.00', $this->allocatedTo($this->chargeY));
    }

    #[Test]
    public function a_recording_holding_the_charge_wins_over_a_racing_cancellation(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, (string) Str::uuid(), '100.00', "{$this->chargeX->id}:100.00"),
            ['php', __DIR__.'/../../Support/race-manual-payment.php', 'cancel', $this->school->id, $this->chargeX->id],
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('error:App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException', $contender);
        $this->assertSame('100.00', $this->allocatedTo($this->chargeX));
    }

    #[Test]
    public function a_cancellation_holding_the_charge_wins_over_a_racing_recording(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/race-manual-payment.php', 'cancel', $this->school->id, $this->chargeX->id],
            $this->record($this->alice, (string) Str::uuid(), '100.00', "{$this->chargeX->id}:100.00"),
        );

        $this->assertSame('cancelled', $holder);
        $this->assertSame('error:App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException', $contender);
        $this->assertSame(0, $this->payments());
    }

    #[Test]
    public function a_suspension_committing_first_refuses_the_racing_recording(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/race-manual-payment.php', 'suspend', $this->school->id],
            $this->record($this->alice, (string) Str::uuid(), '100.00', "{$this->chargeX->id}:100.00"),
        );

        $this->assertSame('suspended', $holder);
        $this->assertSame('error:App\Support\Tenancy\SchoolNotOperationalException', $contender);
        $this->assertSame(0, $this->payments());
    }

    #[Test]
    public function a_recording_in_flight_completes_before_a_racing_suspension(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($this->alice, (string) Str::uuid(), '100.00', "{$this->chargeX->id}:100.00"),
            ['php', __DIR__.'/../../Support/race-manual-payment.php', 'suspend', $this->school->id],
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('suspended', $contender);
        $this->assertSame(1, $this->payments());
    }
}
