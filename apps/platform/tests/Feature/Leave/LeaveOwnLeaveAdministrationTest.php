<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-18 (C34-N3, owner decision D-C35-9): an administrator never withdraws or cancels their own
 * leave through an old (ended, uncorrected) or known-same account -- in the service and as the raw runtime
 * role. Before WO-18 only the current link was refused, so a replaced account holding `hr.leave.manage`
 * could cancel its own started leave and recover the balance. Self-service stays the requester's path;
 * an independent administrator still acts; a corrected link is no evidence.
 */
class LeaveOwnLeaveAdministrationTest extends TestCase
{
    use CreatesLeaveFixtures, CreatesLinkedHrStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 300);
        }
    }

    private function requests(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    /**
     * The requester's Employee was linked to OLD (an administrator) and then to NEW: OLD is the replaced account.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->staffMember($w['school'], ['hr.leave.manage', 'hr.leave.view']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);
        $old = $requester['user'];
        $new = $this->createUserWithCapabilities($w['school'], ['hr.leave.self']);
        $employeeId = $requester['employment']->employee_id;
        foreach ([null, $new->id] as $userId) {
            $this->inSchool($w['school'], fn () => Employee::query()->whereKey($employeeId)->update(['user_id' => $userId]));
        }

        return $w + compact('requester', 'old', 'new', 'employeeId');
    }

    private function balanceRows(School $school): int
    {
        return $this->inSchool($school, fn () => DB::table('leave_ledger_entries')->where('school_id', $school->id)->count());
    }

    #[Test]
    public function a_replaced_account_never_withdraws_or_cancels_its_own_leave_and_nothing_moves(): void
    {
        $t = $this->world();
        $submitted = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $approved = $this->submitLeave($t, '2026-10-06', '2026-10-06', 'full', null, $t['requester']['employment']);
        $this->requests()->approve($t['school'], $approved->id, $t['admin']);
        $this->travelTo('2026-10-07 10:00:00'); // the leave has started: only an administrator may cancel it
        $before = $this->balanceRows($t['school']);

        $attempted = [];
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
            $attempted[] = strtolower($query);
        });
        $this->assertSame('LEAVE_OWN_LEAVE', $this->code(fn () => $this->requests()->withdraw($t['school'], $submitted->id, 'plans_changed', $t['old'])));
        $this->assertSame('LEAVE_OWN_LEAVE', $this->code(fn () => $this->requests()->cancel($t['school'], $approved->id, 'plans_changed', $t['old'])));
        // Every statement sent to PostgreSQL, the refused ones included (a query log keeps only those that succeed).
        $writes = array_filter($attempted, fn (string $q) => str_contains($q, 'insert into "leave_decisions"') || str_contains($q, 'insert into leave_decisions'));
        $this->assertSame([], array_values($writes), 'the service refuses before any decision is written; the database guard is the backstop, not the check');
        $this->assertSame($before, $this->balanceRows($t['school']), 'no reversal, no balance recovered');
        $this->assertSame(['submitted', 'approved'], $this->inSchool($t['school'], fn () => [$submitted->fresh()->status, $approved->fresh()->status]));

        // An independent administrator acts; the requester keeps self-service through the current account.
        $this->assertSame('cancelled', $this->requests()->cancel($t['school'], $approved->id, 'plans_changed', $t['admin'])->status);
        $this->assertSame('withdrawn', $this->requests()->withdrawOwn($t['school'], $submitted->id, 'plans_changed', $t['new'])->status);
    }

    #[Test]
    public function a_known_same_account_of_the_old_account_is_refused_and_the_current_account_keeps_its_own_refusal(): void
    {
        $t = $this->world();
        $submitted = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $alt = $this->leaveAdmin($t['school']);
        $dup = $this->createEmployee($t['school'], ['user_id' => null]);
        foreach ([$t['old']->id, null, $alt->id] as $userId) {
            $this->inSchool($t['school'], fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => $userId]));
        }

        $this->assertSame('LEAVE_OWN_LEAVE', $this->code(fn () => $this->requests()->withdraw($t['school'], $submitted->id, 'plans_changed', $alt)));

        $manager = $this->createUserWithCapabilities($t['school'], ['hr.leave.manage', 'hr.leave.view']);
        foreach ([null, $manager->id] as $userId) {
            $this->inSchool($t['school'], fn () => Employee::query()->whereKey($t['employeeId'])->update(['user_id' => $userId]));
        }
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $this->requests()->withdraw($t['school'], $submitted->id, 'plans_changed', $manager)), 'the current account keeps its existing refusal');
    }

    #[Test]
    public function after_an_approved_correction_voids_the_old_link_the_old_account_may_act(): void
    {
        $t = $this->world();
        $submitted = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $this->assertSame('LEAVE_OWN_LEAVE', $this->code(fn () => $this->requests()->withdraw($t['school'], $submitted->id, 'plans_changed', $t['old'])), 'refused before');

        $link = $this->inSchool($t['school'], fn () => EmployeeAccountLink::query()->where('employee_id', $t['employeeId'])->where('user_id', $t['old']->id)->firstOrFail());
        $this->correctLink($this->inSchool($t['school'], fn () => Employee::query()->findOrFail($t['employeeId'])), $link->id);

        $this->assertSame('withdrawn', $this->requests()->withdraw($t['school'], $submitted->id, 'plans_changed', $t['old'])->status, 'allowed after');
    }

    #[Test]
    public function the_runtime_role_cannot_record_an_administrative_withdrawal_or_cancellation_of_ones_own_leave(): void
    {
        $t = $this->world();
        $submitted = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $insert = fn (User $as, string $decision) => $this->code(fn () => $this->inSchool($t['school'], fn () => DB::insert(
            'insert into leave_decisions (id, school_id, leave_request_id, requester_employee_id, decision, path, decided_by_user_id, decider_employee_id, reason_code) values (?, ?, ?, ?, ?, ?, ?, null, ?)',
            [(string) Str::uuid7(), $t['school']->id, $submitted->id, $t['employeeId'], $decision, 'administrative', $as->id, 'plans_changed'],
        )));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $this->assertStringContainsString('leave_decisions_own_leave', $insert($t['old'], 'withdrawn'), 'an ended, uncorrected link');
        $this->assertStringContainsString('leave_decisions_own_leave', $insert($t['old'], 'cancelled'));
        $this->assertStringContainsString('leave_decisions_own_leave', $insert($t['new'], 'withdrawn'), 'the current link now has a database backstop too');
        $this->assertSame('ok', $insert($t['admin'], 'withdrawn'), 'the positive control');
    }
}
