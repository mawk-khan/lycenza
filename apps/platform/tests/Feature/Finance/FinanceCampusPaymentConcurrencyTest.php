<?php

namespace Tests\Feature\Finance;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.7G-2 (ADR 0076 §51): Campus payment entry under real concurrency -- two
 * GENUINELY separate OS processes whose transactions overlap (the contender
 * is observed blocked), with no deadlock:
 * - two payments on one Charge never over-allocate it (the second sees the
 *   first and is refused when the balance is gone);
 * - two payments over the same Charges named in OPPOSITE order both commit
 *   (Charges are locked in id order, never request order);
 * - a payment and a Campus deactivation, in both orders: whoever locks first
 *   wins; a deactivation that committed first refuses the payment.
 */
class FinanceCampusPaymentConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private Campus $a;

    private User $cashier;

    private User $admin;

    private string $settlement;

    /** @var list<string> */
    private array $charges = [];

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString()]);
        $section = $this->createSection($year, $this->a, $grade, ['code' => 'SA', 'status' => 'active']);
        $receivable = $this->createLedgerAccount($this->school, ['code' => 'AR-RACE', 'type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['code' => 'INC-RACE', 'type' => 'income']);
        $this->settlement = $this->createLedgerAccount($this->school, ['code' => 'CASH-RACE', 'type' => 'asset'])->id;
        foreach (['First', 'Second'] as $name) {
            $student = $this->createStudent($this->school, ['first_name' => $name, 'status' => 'active']);
            $this->createStudentEnrollment($student, $section, ['status' => 'active', 'starts_on' => now()->subDays(60)->toDateString()]);
            $this->charges[] = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '50.00')->id;
        }
        sort($this->charges);

        $this->cashier = $this->user('cashier', $this->a->id);
        $this->admin = $this->user('school_admin', null);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function user(string $role, ?string $campusId): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $this->assignSchoolRole($this->createMembership($user, $this->school), $role, $campusId);

        return $user;
    }

    /** @return array<int, string> */
    private function pay(string ...$lines): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', 'campus-payment', $this->school->id, $this->cashier->id, $this->settlement, implode(',', $lines)];
    }

    /** @return array<int, string> */
    private function deactivate(): array
    {
        return ['php', __DIR__.'/../../Support/student-eligibility-op.php', 'campus-inactivate', $this->school->id, $this->admin->id, $this->a->id];
    }

    private function allocated(string $chargeId): string
    {
        return (string) DB::connection('pgsql_admin')->table('payment_allocations')->where('charge_id', $chargeId)->sum('amount');
    }

    private function payments(): int
    {
        return DB::connection('pgsql_admin')->table('payments')->where('school_id', $this->school->id)->count();
    }

    #[Test]
    public function two_payments_on_one_charge_never_over_allocate_it(): void
    {
        [$first, $second] = $this->raceWithHeldHolder($this->pay("{$this->charges[0]}:40.00"), $this->pay("{$this->charges[0]}:40.00"));

        $this->assertSame('ok', $first);
        $this->assertStringStartsWith('rejected:', $second, 'the second sees the first allocation and is refused');
        $this->assertSame(40.0, (float) $this->allocated($this->charges[0]));
        $this->assertSame(1, $this->payments());
    }

    #[Test]
    public function overlapping_charge_sets_in_opposite_order_both_commit_without_deadlock(): void
    {
        [$first, $second] = $this->raceWithHeldHolder(
            $this->pay("{$this->charges[0]}:10.00", "{$this->charges[1]}:10.00"),
            $this->pay("{$this->charges[1]}:10.00", "{$this->charges[0]}:10.00"),
        );

        $this->assertSame(['ok', 'ok'], [$first, $second]);
        $this->assertSame(20.0, (float) $this->allocated($this->charges[0]));
        $this->assertSame(20.0, (float) $this->allocated($this->charges[1]));
    }

    #[Test]
    public function a_payment_that_locks_first_commits_before_the_campus_deactivation(): void
    {
        [$payment, $deactivation] = $this->raceWithHeldHolder($this->pay("{$this->charges[0]}:10.00"), $this->deactivate());

        $this->assertSame(['ok', 'ok'], [$payment, $deactivation]);
        $this->assertSame(1, $this->payments());
        $this->assertSame('inactive', DB::connection('pgsql_admin')->table('campuses')->where('id', $this->a->id)->value('status'));
    }

    #[Test]
    public function a_deactivation_that_locks_first_refuses_the_payment(): void
    {
        [$deactivation, $payment] = $this->raceWithHeldHolder($this->deactivate(), $this->pay("{$this->charges[0]}:10.00"));

        $this->assertSame('ok', $deactivation);
        $this->assertStringStartsWith('rejected:', $payment, 'no payment is written for a Campus that has just become inactive');
        $this->assertSame(0, $this->payments());
    }
}
