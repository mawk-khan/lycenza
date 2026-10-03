<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §17, §23.10): genuine two-process races. The holder runs
 * its command and keeps its transaction open. The contender is observed
 * BLOCKED on a lock (row, or advisory lock) before the holder commits. Every
 * race ends in one deterministic, persisted outcome.
 *
 * The year-close races use 2024-25, a year that has ended on the real clock
 * the child processes see.
 */
class LeaveRequestConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        // Committed evidence is append-only and RESTRICT: it goes with its School through the migration role in replica mode (test database only).
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

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    private function world(int $allocation = 24): array
    {
        $w = $this->leaveWorld();
        $this->schools[] = $w['school'];
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w, $allocation);

        return $w;
    }

    /** A School whose 2024-25 year has ended, with 2025-26 open as the next year. */
    private function endedYearWorld(): array
    {
        $w = $this->world();
        $w['closing'] = app(LeaveYearService::class)->open($w['school'], '2024-06-01', $w['admin']);
        $w['following'] = app(LeaveYearService::class)->open($w['school'], '2025-06-01', $w['admin']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $w['employment']->id, $w['policy']->id, '2024-04-01', '2026-03-31', $w['admin']);
        app(LeaveLedgerService::class)->allocate($w['school'], $w['employment']->id, $w['type']->id, $w['closing']->id, 24, $w['admin']);

        return $w;
    }

    private function rows(array $w, string $table, array $where = []): int
    {
        return DB::connection('pgsql_admin')->table($table)->where('school_id', $w['school']->id)->where($where)->count();
    }

    private function requestStatus(string $requestId): string
    {
        return (string) DB::connection('pgsql_admin')->table('leave_requests')->where('id', $requestId)->value('status');
    }

    #[Test]
    public function two_overlapping_submissions_cannot_both_succeed(): void
    {
        $w = $this->world();
        $args = [$w['school']->id, $w['employment']->id, $w['type']->id];

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('submit', ...$args, ...['2026-10-12', '2026-10-14', $w['admin']->id]),
            $this->script('submit', ...$args, ...['2026-10-14', '2026-10-16', $w['admin']->id]),
        );

        $this->assertSame('ok:submitted', $holder);
        $this->assertSame('rejected:LEAVE_REQUEST_OVERLAP', $contender);
        $this->assertSame(1, $this->rows($w, 'leave_requests'));
    }

    #[Test]
    public function two_approvals_of_one_request_consume_once(): void
    {
        $w = $this->world();
        $id = $this->submitLeave($w, '2026-10-12', '2026-10-12')->id;

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $w['school']->id, $id, $w['admin']->id), $this->script('approve', $w['school']->id, $id, $w['admin']->id));

        $this->assertSame(['ok:approved', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'leave_ledger_entries', ['kind' => 'consumption']));
        $this->assertSame(1, $this->rows($w, 'leave_decisions'));
    }

    #[Test]
    public function two_approvals_competing_for_the_last_units_never_overdraw(): void
    {
        $w = $this->world(10);
        $first = $this->submitLeave($w, '2026-10-12', '2026-10-14')->id;   // 6 units
        $second = $this->submitLeave($w, '2026-10-19', '2026-10-21')->id;  // 6 units

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $w['school']->id, $first, $w['admin']->id), $this->script('approve', $w['school']->id, $second, $w['admin']->id));

        $this->assertSame(['ok:approved', 'rejected:LEAVE_BALANCE_INSUFFICIENT'], [$holder, $contender]);
        $this->assertSame(['approved', 'submitted'], [$this->requestStatus($first), $this->requestStatus($second)]);
        $this->assertSame(0, $this->rows($w, 'leave_request_days', ['leave_request_id' => $second]), 'the loser wrote no evidence');
    }

    #[Test]
    public function approval_races_rejection_and_withdrawal_and_exactly_one_outcome_wins(): void
    {
        $w = $this->world();
        $rejected = $this->submitLeave($w, '2026-10-12', '2026-10-12')->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('reject', $w['school']->id, $rejected, $w['admin']->id), $this->script('approve', $w['school']->id, $rejected, $w['admin']->id));
        $this->assertSame(['ok:rejected', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);

        $withdrawn = $this->submitLeave($w, '2026-10-13', '2026-10-13')->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $w['school']->id, $withdrawn, $w['admin']->id), $this->script('withdraw', $w['school']->id, $withdrawn, $w['admin']->id));
        $this->assertSame(['ok:approved', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);

        $other = $this->submitLeave($w, '2026-10-14', '2026-10-14')->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('withdraw', $w['school']->id, $other, $w['admin']->id), $this->script('reject', $w['school']->id, $other, $w['admin']->id));
        $this->assertSame(['ok:withdrawn', 'rejected:LEAVE_REQUEST_NOT_SUBMITTED'], [$holder, $contender]);

        $this->assertSame(['rejected', 'approved', 'withdrawn'], [$this->requestStatus($rejected), $this->requestStatus($withdrawn), $this->requestStatus($other)]);
        $this->assertSame(3, $this->rows($w, 'leave_decisions'), 'no duplicate decision evidence');
    }

    #[Test]
    public function an_approval_racing_a_calendar_change_reflects_exactly_one_calendar(): void
    {
        // Monday 2026-10-12 + Tuesday: 4 units under the original week.
        $w = $this->world();
        $before = $this->submitLeave($w, '2026-10-12', '2026-10-13')->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $w['school']->id, $before, $w['admin']->id), $this->script('monday', $w['school']->id, 'off', $w['admin']->id));
        $this->assertSame(['ok:approved', 'ok:off'], [$holder, $contender]);
        $this->assertSame(4, (int) DB::connection('pgsql_admin')->table('leave_request_days')->where('leave_request_id', $before)->sum('units'), 'the approval saw the calendar before the change');

        $after = $this->submitLeave($w, '2026-10-19', '2026-10-20')->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->script('monday', $w['school']->id, 'full', $w['admin']->id), $this->script('approve', $w['school']->id, $after, $w['admin']->id));
        $this->assertSame(['ok:full', 'ok:approved'], [$holder, $contender]);
        $this->assertSame(4, (int) DB::connection('pgsql_admin')->table('leave_request_days')->where('leave_request_id', $after)->sum('units'), 'the approval waited and saw the calendar after the change, never a mix');
    }

    #[Test]
    public function cancellation_racing_cancellation_reverses_once(): void
    {
        $w = $this->world();
        $id = $this->submitLeave($w, '2026-10-12', '2026-10-12')->id;
        app(LeaveRequestService::class)->approve($w['school'], $id, $w['admin']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('cancel', $w['school']->id, $id, $w['admin']->id), $this->script('cancel', $w['school']->id, $id, $w['admin']->id));

        $this->assertSame(['ok:cancelled', 'rejected:LEAVE_REQUEST_NOT_APPROVED'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'leave_ledger_entries', ['kind' => 'reversal']));
    }

    #[Test]
    public function an_approval_racing_the_year_close_is_inside_the_closing_balance_and_a_late_submission_is_refused(): void
    {
        $w = $this->endedYearWorld();
        $id = $this->submitLeave($w, '2025-03-03', '2025-03-03')->id; // Monday in 2024-25: 2 units

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('approve', $w['school']->id, $id, $w['admin']->id), $this->script('close', $w['school']->id, $w['closing']->id, $w['admin']->id));
        $this->assertSame(['ok:approved', 'ok:1'], [$holder, $contender]);
        $item = DB::connection('pgsql_admin')->table('leave_year_close_items')->where('school_id', $w['school']->id)->first();
        $this->assertSame([22, 10, 12], [(int) $item->closing_units, (int) $item->carried_units, (int) $item->lapsed_units], 'the approval is inside the finalized closing balance');

        // Close first, then a submission into the closing year waits and is refused.
        $v = $this->endedYearWorld();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('close', $v['school']->id, $v['closing']->id, $v['admin']->id),
            $this->script('submit', $v['school']->id, $v['employment']->id, $v['type']->id, '2025-03-03', '2025-03-03', $v['admin']->id),
        );
        $this->assertSame(['ok:1', 'rejected:LEAVE_YEAR_CLOSED'], [$holder, $contender]);
    }

    #[Test]
    public function a_cancellation_racing_the_year_close_is_reconciled_never_lost(): void
    {
        $w = $this->endedYearWorld();
        $id = $this->submitLeave($w, '2025-03-03', '2025-03-04')->id; // 4 units
        app(LeaveRequestService::class)->approve($w['school'], $id, $w['admin']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('close', $w['school']->id, $w['closing']->id, $w['admin']->id), $this->script('cancel', $w['school']->id, $id, $w['admin']->id));
        $this->assertSame(['ok:1', 'ok:cancelled'], [$holder, $contender]);

        // Closed at 20 (10 carried, 10 lapsed); the late reversal of 4 lapses under the saturated cap and is reconciled.
        $reconciliation = DB::connection('pgsql_admin')->table('leave_year_close_reconciliations')->where('school_id', $w['school']->id)->first();
        $this->assertNotNull($reconciliation, 'the cancellation saw the committed close and reconciled it');
        $this->assertSame([4, 0, 4], [(int) $reconciliation->units, (int) $reconciliation->carried_delta, (int) $reconciliation->lapsed_delta]);
        $closingBalance = (int) DB::connection('pgsql_admin')->selectOne("select coalesce(sum(case when kind in ('allocation','reversal','carry_forward_in') or direction = 'credit' then units else -units end), 0) as b from leave_ledger_entries where school_id = ? and leave_year_id = ?", [$w['school']->id, $w['closing']->id])->b;
        $this->assertSame(0, $closingBalance, 'the closed year stays settled');
    }

    #[Test]
    public function two_year_close_executions_write_one_close(): void
    {
        $w = $this->endedYearWorld();

        [$holder, $contender] = $this->raceWithHeldHolder($this->script('close', $w['school']->id, $w['closing']->id, $w['admin']->id), $this->script('close', $w['school']->id, $w['closing']->id, $w['admin']->id));

        $this->assertSame(['ok:1', 'rejected:LEAVE_YEAR_ALREADY_CLOSED'], [$holder, $contender]);
        $this->assertSame(1, $this->rows($w, 'leave_year_closes'));
        $this->assertSame(3, $this->rows($w, 'leave_ledger_entries', [['year_close_id', '!=', null]]), 'one carry-out, one carry-in and one expiry; nothing duplicated');
    }
}
