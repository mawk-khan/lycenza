<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveRequestDay;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.9 (ADR 0065 §28.2): request-timing rules on the policy version --
 * notice and backdating in calendar days, a maximum per request in half-day
 * units -- applied at submission on every path, against every version
 * assigned on the requested dates; frozen with the version.
 *
 * Calendar: Monday-Friday full, Saturday morning, Sunday off. Today is
 * Monday 2026-10-05 (School-local). Rule values are synthetic test inputs.
 */
class LeavePolicyRulesTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 160);
        } catch (ModelNotFoundException) {
            return 'not_found';
        }
    }

    /**
     * A world whose employment holds ONE version with these rules from 2026-04-01.
     *
     * @param  array<string, int|null>  $rules
     * @return array<string, mixed>
     */
    private function ruled(array $rules): array
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $policy = $this->leavePolicy($school, $type, $admin, $rules);
        $year = $this->leaveYear($school, $admin);
        $employment = $this->currentEmployment($school);
        app(LeavePolicyAssignmentService::class)->assign($school, $employment->id, $policy->id, '2026-04-01', null, $admin);
        $w = compact('school', 'admin', 'type', 'policy', 'year', 'employment');
        $this->workingWeek($school, $admin);
        $this->allocate($w, 40);
        $this->leaveClerk($school); // created outside any rolled-back attempt below

        return $w;
    }

    #[Test]
    public function a_version_without_rules_keeps_todays_behaviour(): void
    {
        $w = $this->ruled([]);
        $this->assertNull($w['policy']->min_notice_days);
        $this->assertSame(0, $w['policy']->eligibility_rule_count);

        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-09-01', '2026-09-01')), 'a past date with no backdating rule');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-05', '2026-10-05')), 'today with no notice rule');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-11-02', '2026-11-13')), 'a long request with no maximum');
    }

    #[Test]
    public function notice_is_counted_in_calendar_days_from_the_school_local_submission_date(): void
    {
        $w = $this->ruled(['min_notice_days' => 3]);

        $this->assertSame('LEAVE_NOTICE_TOO_SHORT', $this->code(fn () => $this->submitLeave($w, '2026-10-05', '2026-10-05')), 'today is 0 days');
        $this->assertSame('LEAVE_NOTICE_TOO_SHORT', $this->code(fn () => $this->submitLeave($w, '2026-10-07', '2026-10-07')), '2 days');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-08', '2026-10-08')), 'exactly 3 calendar days');
        // A past date is a backdating question; with no backdating rule it is not refused for notice.
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-01', '2026-10-01')));
    }

    #[Test]
    public function backdating_is_bounded_in_calendar_days_and_zero_means_never(): void
    {
        $w = $this->ruled(['max_backdate_days' => 2]);
        $this->assertSame('LEAVE_BACKDATE_TOO_FAR', $this->code(fn () => $this->submitLeave($w, '2026-10-02', '2026-10-02')), 'three days back');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-03', '2026-10-03', 'first_half')), 'two days back (Saturday morning)');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-06', '2026-10-06')), 'the future is not backdating');

        $never = $this->ruled(['max_backdate_days' => 0]);
        $this->assertSame('LEAVE_BACKDATE_TOO_FAR', $this->code(fn () => $this->submitLeave($never, '2026-10-02', '2026-10-02')));
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($never, '2026-10-05', '2026-10-05')), 'today is not in the past');
    }

    #[Test]
    public function the_maximum_per_request_counts_chargeable_half_day_units_only(): void
    {
        $w = $this->ruled(['max_units_per_request' => 4]);
        $this->assertSame('LEAVE_REQUEST_TOO_LONG', $this->code(fn () => $this->submitLeave($w, '2026-10-12', '2026-10-14')), 'three working days = 6 units');
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-12', '2026-10-13')), 'two working days = 4 units');
        // Friday + Saturday morning + Sunday off = 3 units: the weekend is not counted (no sandwich rule).
        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-16', '2026-10-18')));
    }

    #[Test]
    public function every_version_assigned_on_the_requested_dates_must_be_satisfied(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $lenient = $this->leavePolicy($school, $type, $admin, ['name' => 'Lenient']);
        $strict = $this->leavePolicy($school, $type, $admin, ['name' => 'Strict', 'max_units_per_request' => 2]);
        $year = $this->leaveYear($school, $admin);
        $employment = $this->currentEmployment($school);
        $this->workingWeek($school, $admin);
        $assign = app(LeavePolicyAssignmentService::class);
        $assign->assign($school, $employment->id, $lenient->id, '2026-04-01', '2026-10-14', $admin);
        $assign->assign($school, $employment->id, $strict->id, '2026-10-15', null, $admin);
        $w = compact('school', 'admin', 'type', 'year', 'employment') + ['policy' => $lenient];
        $this->leaveClerk($school);

        $this->assertSame('ok', $this->code(fn () => $this->submitLeave($w, '2026-10-12', '2026-10-13')), 'wholly inside the lenient version');
        $this->assertSame('LEAVE_REQUEST_TOO_LONG', $this->code(fn () => $this->submitLeave($w, '2026-10-14', '2026-10-15')), 'touching the strict version');

        $request = $this->submitLeave($w, '2026-10-15', '2026-10-15');
        $event = $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'leave.request.submitted')->where('subject_id', $request->id)->firstOrFail());
        $this->assertSame([$strict->id], $event->metadata['timingPolicyIds'], 'the version whose rules applied is recorded (ids only)');
    }

    #[Test]
    public function self_service_is_held_to_the_same_rules(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $policy = $this->leavePolicy($school, $type, $admin, ['min_notice_days' => 5]);
        $this->leaveYear($school, $admin);
        $this->workingWeek($school, $admin);
        $staff = $this->staffMember($school, ['hr.leave.self']);
        app(LeavePolicyAssignmentService::class)->assign($school, $staff['employment']->id, $policy->id, '2026-04-01', null, $admin);
        $service = app(LeaveRequestService::class);

        $this->assertSame('LEAVE_NOTICE_TOO_SHORT', $this->code(fn () => $service->submitOwn($school, $type->id, '2026-10-07', 'full', '2026-10-07', 'full', null, $staff['user'])));
        $this->assertSame('ok', $this->code(fn () => $service->submitOwn($school, $type->id, '2026-10-12', 'full', '2026-10-12', 'full', null, $staff['user'])));
    }

    #[Test]
    public function approval_does_not_re_judge_timing_and_a_new_version_never_rewrites_approved_leave(): void
    {
        $w = $this->ruled(['min_notice_days' => 3]);
        $request = $this->submitLeave($w, '2026-10-08', '2026-10-08');

        $this->travelTo('2026-10-07 06:00:00');
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);

        // A stricter replacement version does not touch the approved evidence.
        app(LeavePolicyService::class)->create($w['school'], $w['type']->id, ['name' => 'Stricter', 'annual_allocation_units' => 20, 'carry_forward_allowed' => false,
            'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null, 'min_notice_days' => 30], $w['policy']->id, $w['admin']);
        $day = $this->inSchool($w['school'], fn () => LeaveRequestDay::query()->where('leave_request_id', $request->id)->sole());
        $this->assertSame($w['policy']->id, $day->leave_policy_id);
        $this->assertSame('approved', $this->inSchool($w['school'], fn () => $request->refresh()->status));
        $this->assertSame($w['policy']->id, $this->inSchool($w['school'], fn () => LeavePolicyAssignment::query()->where('employment_record_id', $w['employment']->id)->sole()->leave_policy_id),
            'superseding never repoints an assignment');
    }

    #[Test]
    public function rule_values_are_validated_by_the_service_and_the_database(): void
    {
        $school = $this->createSchool();
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $whole = $this->leaveType($school, $admin, 'EL', halfDay: false);
        $base = ['name' => 'P', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null];
        $create = fn (string $typeId, array $terms) => fn () => app(LeavePolicyService::class)->create($school, $typeId, $terms + $base, null, $admin);

        $this->assertSame('LEAVE_POLICY_RULE_INVALID', $this->code($create($type->id, ['min_notice_days' => 0])));
        $this->assertSame('LEAVE_POLICY_RULE_INVALID', $this->code($create($type->id, ['max_backdate_days' => 367])));
        $this->assertSame('LEAVE_POLICY_RULE_INVALID', $this->code($create($type->id, ['max_units_per_request' => 0])));
        $this->assertSame('LEAVE_HALF_DAY_NOT_ALLOWED', $this->code($create($whole->id, ['max_units_per_request' => 3])));
        $this->assertSame('ok', $this->code($create($type->id, ['max_backdate_days' => 0, 'max_units_per_request' => 3])));

        foreach (['min_notice_days = 0', 'max_backdate_days = -1', 'max_units_per_request = 735', 'eligibility_rule_count = 21'] as $set) {
            $this->assertStringContainsString('leave_policies_rules_check', $this->rawInsert($school, $type->id, $admin, $set), $set);
        }
    }

    /** A raw INSERT with one bad rule value, as the runtime role; answers the database's message. */
    private function rawInsert(School $school, string $typeId, User $admin, string $set): string
    {
        [$column, $value] = array_map('trim', explode('=', $set));

        return $this->code(fn () => $this->inSchool($school, fn () => DB::table('leave_policies')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school->id, 'leave_type_id' => $typeId, 'name' => 'Raw',
            'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'status' => 'active', 'created_by_user_id' => $admin->id,
            'created_at' => now(), 'updated_at' => now(), $column => (int) $value,
        ])));
    }

    #[Test]
    public function a_saved_versions_rules_are_immutable_for_the_runtime_role(): void
    {
        $w = $this->ruled(['min_notice_days' => 3, 'max_units_per_request' => 10]);

        foreach (['min_notice_days = 1', 'max_backdate_days = 5', 'max_units_per_request = NULL', 'eligibility_rule_count = 1'] as $set) {
            $this->assertStringContainsString('leave_policy_immutable', $this->code(fn () => $this->inSchool($w['school'], fn () => DB::statement(
                "UPDATE leave_policies SET {$set} WHERE id = ?", [$w['policy']->id],
            ))), $set);
        }
        $this->assertSame(3, $this->inSchool($w['school'], fn () => LeavePolicy::query()->findOrFail($w['policy']->id)->min_notice_days));
    }
}
