<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeaveLedgerService;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §17): ledger writes racing on one balance key, in two real
 * OS processes with an observed lock wait (COMMITTED fixtures). Every write
 * of a key takes `leave.balance:{school}:{employment}:{type}:{year}` (the
 * service, and again the database trigger), so:
 * - two allocations: exactly one is granted, the other is refused;
 * - two debits: the second sees the first and is refused if the balance
 *   would go negative;
 * - two annual runs: exactly one executes.
 */
class LeaveConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        // The ledger is append-only and every Employee link RESTRICT: committed rows go
        // with their School through the migration role in replica mode (test database only).
        $admin = DB::connection('pgsql_admin');
        foreach ($this->schools as $school) {
            $users = $admin->table('school_memberships')->where('school_id', $school->id)->pluck('user_id')->all();
            $admin->transaction(function () use ($admin, $school, $users) {
                $admin->statement("SET LOCAL session_replication_role = 'replica'");
                foreach ($admin->select("SELECT c.table_name FROM information_schema.columns c JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace WHERE c.table_schema = 'public' AND c.column_name = 'school_id'") as $row) {
                    $admin->table($row->table_name)->where('school_id', $school->id)->delete();
                }
                $admin->table('schools')->where('id', $school->id)->delete();
                $admin->table('users')->whereIn('id', $users)->delete();
            });
        }

        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];

        return $w;
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    private function balance(array $w): int
    {
        return (int) DB::connection('pgsql_admin')->selectOne(
            "select coalesce(sum(case when kind in ('allocation','reversal','carry_forward_in') or direction = 'credit' then units else -units end), 0) as b
               from leave_ledger_entries where employment_record_id = ? and leave_type_id = ? and leave_year_id = ?",
            [$w['employment']->id, $w['type']->id, $w['year']->id],
        )->b;
    }

    #[Test]
    public function two_concurrent_allocations_of_one_key_grant_exactly_once(): void
    {
        $w = $this->world();
        $args = [$w['school']->id, $w['employment']->id, $w['type']->id, $w['year']->id];

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('allocate', ...$args, ...['10', $w['admin']->id]),
            $this->script('allocate', ...$args, ...['12', $w['admin']->id]),
        );

        $this->assertSame('ok:10', $holder);
        $this->assertSame('rejected:LEAVE_ALREADY_ALLOCATED', $contender);
        $this->assertSame(10, $this->balance($w));
    }

    #[Test]
    public function two_concurrent_debits_can_never_take_the_balance_below_zero(): void
    {
        $w = $this->world();
        app(LeaveLedgerService::class)->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['year']->id, 10, $w['admin']);
        $args = [$w['school']->id, $w['employment']->id, $w['type']->id, $w['year']->id];

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('debit', ...$args, ...['8', $w['admin']->id]),
            $this->script('debit', ...$args, ...['5', $w['admin']->id]),
        );

        $this->assertSame('ok:8', $holder);
        $this->assertSame('rejected:LEAVE_BALANCE_INSUFFICIENT', $contender);
        $this->assertSame(2, $this->balance($w));
    }

    #[Test]
    public function two_concurrent_annual_runs_execute_exactly_once(): void
    {
        $w = $this->world();
        $args = [$w['school']->id, $w['type']->id, $w['year']->id, $w['admin']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('run', ...$args), $this->script('run', ...$args));

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:LEAVE_RUN_ALREADY_EXECUTED', $contender);
        $this->assertSame(24, $this->balance($w));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('leave_allocation_runs')->where('school_id', $w['school']->id)->count());
    }
}
