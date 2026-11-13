<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-19 (D-C35-3): a Leave ledger or accrual write against a concurrent loss of authority, in two
 * real OS processes, holder held open and contender observed blocked (COMMITTED fixtures).
 *
 * The write's current-authority read (`ApprovalDecisionAuthority::requireCurrent()`) takes the School FOR
 * SHARE and the acting membership and its grants FOR SHARE until commit, so a revocation (an UPDATE of the
 * grant row) or a School suspension is ordered strictly before or after the write:
 * - the revocation first: the waiting write re-reads the revoked grant and is refused; nothing is credited;
 * - the write first: it commits, and the waiting revocation lands after it; the credit stands (a later loss
 *   of authority never rewrites history);
 * - the School suspended first: the waiting write is refused as not operational.
 * The account's disabled flag and the module entitlement are read fresh at the write, not locked (the
 * existing `holdsInSchoolNow()` contract); they are not raced here.
 */
class LeaveWriteAuthorityConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $w['operator'] = $this->leaveAdmin($w['school']);

        return $w;
    }

    /** @return list<string> */
    private function leaveOp(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    /** @return list<string> */
    private function revoke(array $w, User $user): array
    {
        $membership = (string) DB::connection('pgsql_admin')->table('school_memberships')->where('school_id', $w['school']->id)->where('user_id', $user->id)->value('id');

        return ['php', __DIR__.'/../../Support/staff-role-grant-op.php', 'revoke-issuer', $w['school']->id, $membership];
    }

    /** @return list<string> */
    private function allocate(array $w): array
    {
        return $this->leaveOp('allocate', $w['school']->id, $w['employment']->id, $w['type']->id, $w['year']->id, '24', $w['operator']->id);
    }

    private function ledgerRows(array $w): int
    {
        return (int) DB::connection('pgsql_admin')->table('leave_ledger_entries')->where('school_id', $w['school']->id)->count();
    }

    private function activeGrants(array $w, User $user): int
    {
        return (int) DB::connection('pgsql_admin')->table('membership_role_assignments')->whereNull('revoked_at')
            ->whereIn('school_membership_id', DB::connection('pgsql_admin')->table('school_memberships')->where('school_id', $w['school']->id)->where('user_id', $user->id)->pluck('id'))
            ->count();
    }

    #[Test]
    public function a_revocation_held_open_refuses_the_waiting_allocation(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->revoke($w, $w['operator']), $this->allocate($w));

        $this->assertSame('revoked:1', $holder);
        $this->assertSame('rejected:AuthorizationException', $contender);
        $this->assertSame(0, $this->ledgerRows($w), 'nothing credited');
    }

    #[Test]
    public function an_allocation_held_open_is_ordered_first_and_the_waiting_revocation_never_undoes_it(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->allocate($w), $this->revoke($w, $w['operator']));

        $this->assertSame('ok:24', $holder);
        $this->assertSame('revoked:1', $contender);
        $this->assertSame([1, 0], [$this->ledgerRows($w), $this->activeGrants($w, $w['operator'])], 'the credit stands; the revocation landed after it');
    }

    #[Test]
    public function a_revocation_held_open_refuses_the_waiting_accrual_run(): void
    {
        $w = $this->world();
        $type = $this->leaveType($w['school'], $w['admin'], 'EL');
        $policy = app(LeavePolicyService::class)->create($w['school'], $type->id, [
            'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 2, 'accrual_timing' => 'period_start',
        ], null, $w['admin']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $policy->id, '2026-04-01', null, $w['admin']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->revoke($w, $w['operator']),
            $this->leaveOp('accrue', $w['school']->id, $type->id, $w['year']->id, '2026-04-01', $w['operator']->id),
        );

        $this->assertSame('revoked:1', $holder);
        $this->assertSame('rejected:AuthorizationException', $contender);
        $this->assertSame([0, 0], [$this->ledgerRows($w), (int) DB::connection('pgsql_admin')->table('leave_accrual_runs')->where('school_id', $w['school']->id)->count()], 'no run opened, nothing credited');
    }

    #[Test]
    public function a_school_suspension_held_open_refuses_the_waiting_allocation(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(['php', __DIR__.'/../../Support/suspend-school.php', $w['school']->id], $this->allocate($w));

        $this->assertStringContainsString('suspended', $holder);
        $this->assertSame('rejected:SchoolNotOperationalException', $contender);
        $this->assertSame(0, $this->ledgerRows($w));
    }
}
