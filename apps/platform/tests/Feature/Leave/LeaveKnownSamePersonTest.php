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
 * HR.R1-C34 (WO-16; owner decision HR.R1-C33): a leave approval or rejection is never taken by an
 * account known to be the same person as the submitter, or as the requester through an uncorrected
 * link, current or ended (a replaced account) -- in the service and as the raw runtime role. Unknown is
 * never refused; a corrected link is no evidence; the ledger moves only for an independent decision.
 */
class LeaveKnownSamePersonTest extends TestCase
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

    /** @return array<string, mixed> */
    private function team(array $requesterCapabilities = []): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->staffMember($w['school'], $requesterCapabilities);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);

        return $w + compact('requester');
    }

    /** One person, two accounts: an Employee (never employed) linked to $first, unlinked, then linked to $second. */
    private function samePerson(School $school, User $first, User $second): Employee
    {
        $own = $this->createEmployee($school, ['user_id' => null]);
        foreach ([$first->id, null, $second->id] as $userId) {
            $this->inSchool($school, fn () => Employee::query()->whereKey($own->id)->update(['user_id' => $userId]));
        }

        return $own;
    }

    private function requests(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    /** @return array{0: int, 1: int} decisions and ledger rows of this School */
    private function counts(School $school): array
    {
        return $this->inSchool($school, fn () => [DB::table('leave_decisions')->where('school_id', $school->id)->count(), DB::table('leave_ledger_entries')->where('school_id', $school->id)->count()]);
    }

    #[Test]
    public function a_known_same_account_of_the_submitter_never_decides_and_nothing_moves(): void
    {
        $t = $this->team();
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $alt = $this->leaveAdmin($t['school']);
        $this->samePerson($t['school'], $this->leaveClerk($t['school']), $alt);
        $before = $this->counts($t['school']);

        $this->assertSame('LEAVE_SAME_PERSON_DECISION', $this->code(fn () => $this->requests()->approve($t['school'], $request->id, $alt)));
        $this->assertSame('LEAVE_SAME_PERSON_DECISION', $this->code(fn () => $this->requests()->reject($t['school'], $request->id, 'other', $alt)));
        $this->assertSame($before, $this->counts($t['school']), 'no decision, no ledger movement');
        $this->assertSame('submitted', $this->inSchool($t['school'], fn () => $request->fresh()->status));

        // The submitter's own account is refused as before; an unknown account decides.
        $this->assertSame('LEAVE_PARTICIPANT_DECISION', $this->code(fn () => $this->requests()->approve($t['school'], $request->id, $this->leaveClerk($t['school']))));
        $this->assertSame('approved', $this->requests()->approve($t['school'], $request->id, $t['admin'])->status);
    }

    #[Test]
    public function a_replaced_account_of_the_requester_never_decides_their_leave(): void
    {
        // The requester's old account holds hr.leave.manage; the School replaced it with a new one.
        $t = $this->team(['hr.leave.manage', 'hr.leave.view']);
        $old = $t['requester']['user'];
        $employeeId = $t['requester']['employment']->employee_id;
        $new = $this->createUserWithCapabilities($t['school'], []);
        $this->inSchool($t['school'], fn () => Employee::query()->whereKey($employeeId)->update(['user_id' => null]));
        $this->inSchool($t['school'], fn () => Employee::query()->whereKey($employeeId)->update(['user_id' => $new->id]));
        $request = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['requester']['employment']);

        // The old account has no current link, so the current-link rule alone let it through before WO-16.
        $this->assertSame('LEAVE_SAME_PERSON_DECISION', $this->code(fn () => $this->requests()->approve($t['school'], $request->id, $old)));
        $this->assertSame('submitted', $this->inSchool($t['school'], fn () => $request->fresh()->status));
        $this->assertSame('approved', $this->requests()->approve($t['school'], $request->id, $t['admin'])->status);
    }

    #[Test]
    public function a_corrected_link_is_no_evidence_and_unknown_is_never_refused(): void
    {
        $t = $this->team();
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $clerk = $this->leaveClerk($t['school']);
        $alt = $this->leaveAdmin($t['school']);
        $own = $this->samePerson($t['school'], $clerk, $alt);

        $link = $this->inSchool($t['school'], fn () => EmployeeAccountLink::query()->where('employee_id', $own->id)->where('user_id', $clerk->id)->firstOrFail());
        $this->correctLink($this->inSchool($t['school'], fn () => Employee::query()->findOrFail($own->id)), $link->id);

        $this->assertSame('approved', $this->requests()->approve($t['school'], $request->id, $alt)->status, 'the voided link no longer joins the two accounts');
    }

    #[Test]
    public function the_runtime_role_cannot_record_a_decision_by_a_known_same_account(): void
    {
        $t = $this->team(['hr.leave.manage', 'hr.leave.view']);
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $alt = $this->leaveAdmin($t['school']);
        $this->samePerson($t['school'], $this->leaveClerk($t['school']), $alt);
        $old = $t['requester']['user'];
        $this->inSchool($t['school'], fn () => Employee::query()->whereKey($t['requester']['employment']->employee_id)->update(['user_id' => null]));
        $insert = fn (User $as, string $decision = 'approved') => $this->code(fn () => $this->inSchool($t['school'], fn () => DB::insert(
            'insert into leave_decisions (id, school_id, leave_request_id, requester_employee_id, decision, path, decided_by_user_id, decider_employee_id, reason_code) values (?, ?, ?, ?, ?, ?, ?, null, ?)',
            [(string) Str::uuid7(), $t['school']->id, $request->id, $t['requester']['employment']->employee_id, $decision, 'administrative', $as->id, $decision === 'rejected' ? 'other' : null],
        )));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $this->assertStringContainsString('leave_decisions_same_person', $insert($alt));
        $this->assertStringContainsString('leave_decisions_same_person', $insert($alt, 'rejected'));
        $this->assertStringContainsString('leave_decisions_same_person', $insert($old), 'an ended, uncorrected link to the requester');
        $this->assertSame(0, $this->counts($t['school'])[0]);
        $this->assertSame('ok', $insert($t['admin']), 'the positive control');
    }
}
