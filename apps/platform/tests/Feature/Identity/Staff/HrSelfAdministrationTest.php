<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Exceptions\HrSelfAdministrationException;
use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.4 (ADR 0071 §26.2): the HR officer self-elevation audit, as attacks.
 * `hr_officer` holds the identity substrate (`hr.employees.manage`,
 * `hr.employees.assignments.manage`) every ActingEmployee surface hangs
 * from -- teaching ownership, staff self-service, leave decisions,
 * payslips, staff attendance. Before SR.4 an officer could relink a
 * teacher's Employee to themselves and inherit their classes, or unlink
 * themselves and approve their own leave. Every officer here is granted the
 * production role through the real SR.2 grant path; every transition goes
 * through the real HR services (or HTTP).
 */
class HrSelfAdministrationTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa, StaffAccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00'); // a Monday
    }

    /** Re-reads a tenant row inside its School's context. */
    private function reload(School $school, mixed $model): mixed
    {
        return app(TenantContext::class)->withSchool($school, fn () => $model->fresh());
    }

    private function refusal(callable $operation): string
    {
        try {
            $operation();
        } catch (HrException $e) {
            return $e->errorCode();
        } catch (LeaveException $e) {
            return $e->errorCode();
        }

        return 'allowed';
    }

    /** An active production-role holder, granted by the School's admin through the real rule. @return array{0: User, 1: \App\Models\SchoolMembership} */
    private function holder(School $school, User $admin, string ...$roles): array
    {
        [$user, $membership] = $this->staffMember($school, 'staff_self_service');
        foreach ($roles as $role) {
            app(StaffAccessService::class)->grantRole($school, $admin, $membership->id, $role);
        }

        return [$user, $membership];
    }

    /** $user's own Employee and current employment, made by $peer (never by $user). */
    private function employ(School $school, User $user, User $peer, string $name = 'Staff'): EmploymentRecord
    {
        $employee = app(EmployeeService::class)->create($school, ['full_name' => $name, 'user_id' => $user->id], $peer);

        return app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'], $peer);
    }

    private function assignment(School $school, EmploymentRecord $employment, User $peer): EmployeeAssignment
    {
        $position = app(PositionService::class)->create($school, ['name' => 'Role '.uniqid(), 'code' => 'P'.strtoupper(uniqid())], $peer);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2024-01-01'], $position, $peer);

        return app(EmployeeAssignmentService::class)->setPrimary($assignment, $peer);
    }

    #[Test]
    public function an_actor_never_links_an_employee_to_themselves_by_any_path(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer] = $this->holder($school, $admin, 'hr_officer');
        [$peer] = $this->holder($school, $admin, 'hr_officer');
        $employees = app(EmployeeService::class);

        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employees->create($school, ['full_name' => 'Me', 'user_id' => $officer->id], $officer)));
        $unlinked = $employees->create($school, ['full_name' => 'Me'], $officer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employees->linkUser($unlinked, $officer->id, $officer)));
        $this->assertNull($this->reload($school, $unlinked)->user_id);

        // Over the JSON API: the same refusal, 403 with a stable code, no link.
        $token = $this->mfaToken($officer);
        $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('Idempotency-Key', 'sr4-self-link')
            ->postJson("/api/v1/schools/{$school->id}/employees/{$unlinked->id}/link-user", ['user_id' => $officer->id])
            ->assertForbidden()->assertJsonPath('error.code', HrSelfAdministrationException::CODE);
        $this->assertNull($this->reload($school, $unlinked)->user_id);

        // School Admin is bound by the same rule; a peer links instead.
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employees->linkUser($unlinked, $admin->id, $admin)));
        $this->assertSame($officer->id, $employees->linkUser($unlinked, $officer->id, $peer)->user_id);
    }

    #[Test]
    public function relinking_a_teachers_employee_to_oneself_never_inherits_their_classes(): void
    {
        $w = $this->teachingWorld();
        $school = $w['school'];
        [$admin] = $this->staffAdmin($school);
        [$officer] = $this->holder($school, $admin, 'hr_officer', 'teacher');
        [$victim] = $this->holder($school, $admin, 'teacher');
        $victimEmployee = $w['employee'];
        app(EmployeeService::class)->linkUser($victimEmployee, $victim->id, $officer);
        $this->assign($w, '2026-06-01', null, $victimEmployee, $admin);

        // The attack: take the teacher's Employee over. Unlinking someone else
        // is HR's job (allowed); re-pointing it at oneself is not.
        app(EmployeeService::class)->unlinkUser($victimEmployee, $officer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => app(EmployeeService::class)->linkUser($victimEmployee, $officer->id, $officer)));

        $this->assertNull($this->reload($school, $victimEmployee)->user_id);
        $this->assertThrows(fn () => app(ActingEmployeeResolver::class)->resolve($officer, $school), ActingEmployeeUnavailableException::class);
        $this->assertNotSame([], app(TeachingOwnership::class)->periods($school, $victimEmployee->id), 'The classes stay with the Employee, never move to the officer.');
    }

    #[Test]
    public function an_officer_can_neither_unlink_themselves_nor_decide_their_own_leave(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer] = $this->holder($school, $admin, 'hr_officer');
        [$peer] = $this->holder($school, $admin, 'hr_officer');
        $mine = $this->employ($school, $officer, $peer, 'The Officer');

        $type = app(LeaveTypeService::class)->create($school, 'CL', 'Casual leave', true, true, true, $officer);
        $policy = app(LeavePolicyService::class)->create($school, $type->id, [
            'name' => 'Standard', 'annual_allocation_units' => 24, 'carry_forward_allowed' => false,
        ], null, $officer);
        $year = app(LeaveYearService::class)->open($school, '2026-06-01', $officer);
        app(StaffCalendarService::class)->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], $officer);
        app(LeavePolicyAssignmentService::class)->assign($school, $mine->id, $policy->id, '2026-04-01', null, $peer);
        app(LeaveLedgerService::class)->allocate($school, $mine->id, $type->id, $year->id, 24, $peer);

        $leave = app(TenantContext::class)->withSchool($school, fn () => app(LeaveRequestService::class)
            ->submitOnBehalf($school, $mine->id, $type->id, '2026-10-12', 'full', '2026-10-12', 'full', null, $officer));
        $this->assertSame('LEAVE_SELF_DECISION', $this->refusal(fn () => app(LeaveRequestService::class)->approve($school, $leave->id, $officer)));

        // The pre-SR.4 bypass: drop the link so neither the service nor the
        // database recognises the decider -- refused at the first step.
        $employee = app(TenantContext::class)->withSchool($school, fn () => Employee::query()->findOrFail($mine->employee_id));
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => app(EmployeeService::class)->unlinkUser($employee, $officer)));
        $this->assertSame($officer->id, $this->reload($school, $employee)->user_id);
        $this->assertSame('LEAVE_SELF_DECISION', $this->refusal(fn () => app(LeaveRequestService::class)->approve($school, $leave->id, $officer)));

        // A peer decides it.
        $this->assertSame('allowed', $this->refusal(fn () => app(LeaveRequestService::class)->approve($school, $leave->id, $peer)));
    }

    #[Test]
    public function own_employment_assignments_reporting_line_and_lifecycle_are_refused_and_a_peer_may_act(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer] = $this->holder($school, $admin, 'hr_officer');
        [$peer] = $this->holder($school, $admin, 'hr_officer');
        [$colleague] = $this->holder($school, $admin);
        $employees = app(EmployeeService::class);
        $employment = app(EmploymentService::class);
        $assignments = app(EmployeeAssignmentService::class);
        $reporting = app(ReportingHierarchyService::class);

        $ownEmployee = $employees->create($school, ['full_name' => 'The Officer', 'user_id' => $officer->id], $peer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employment->create($ownEmployee, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'], $officer)));
        $mine = $employment->create($ownEmployee, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'], $peer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employment->update($mine, ['employment_type' => 'contract'], $officer)));

        $position = app(PositionService::class)->create($school, ['name' => 'Officer', 'code' => 'HRO'], $officer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $assignments->create($mine, ['starts_on' => '2024-01-01'], $position, $officer)));
        $ownAssignment = $this->assignment($school, $mine, $peer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $assignments->setPrimary($ownAssignment, $officer)));

        // Reporting lines: never one the officer is on, either side.
        $theirs = $this->employ($school, $colleague, $officer, 'Colleague');
        $theirAssignment = $this->assignment($school, $theirs, $officer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $reporting->setManager($ownAssignment, $theirAssignment, $officer)), 'choosing your own approver');
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $reporting->setManager($theirAssignment, $ownAssignment, $officer)), 'making yourself someone\'s manager');
        $this->assertSame('allowed', $this->refusal(fn () => $reporting->setManager($theirAssignment, $ownAssignment, $peer)));

        // Lifecycle: archive, restore, end, separate.
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employees->archive($ownEmployee, $officer)));
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $assignments->end($ownAssignment, '2026-10-01', $officer)));
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => app(EmployeeLifecycleService::class)->separate($mine, '2026-10-01', $officer)));
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $employment->end($mine, '2026-10-01', $officer)));
        $this->assertNull($this->reload($school, $mine)->ends_on, 'Nothing changed.');

        // Ordinary HR work on everyone else is untouched.
        $this->assertSame('allowed', $this->refusal(fn () => $employment->end($theirs, '2026-10-01', $officer)));
        $this->assertSame('allowed', $this->refusal(fn () => $employment->end($mine, '2026-10-01', $peer)));
    }

    #[Test]
    public function nobody_records_or_corrects_their_own_staff_attendance(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer] = $this->holder($school, $admin, 'hr_officer');
        [$peer] = $this->holder($school, $admin, 'hr_officer');
        [$colleague] = $this->holder($school, $admin);
        $mine = $this->employ($school, $officer, $peer, 'The Officer');
        $theirs = $this->employ($school, $colleague, $peer, 'Colleague');
        app(StaffCalendarService::class)->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], $officer);
        $attendance = app(StaffAttendanceService::class);
        $date = '2026-10-05';

        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $attendance->record($school, $mine->id, $date, 'present', 'present', $officer)));
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $attendance->recordRegister($school, $date, [
            ['employment_record_id' => $theirs->id, 'first_half' => 'present', 'second_half' => 'present'],
            ['employment_record_id' => $mine->id, 'first_half' => 'present', 'second_half' => 'present'],
        ], $officer)), 'A register naming the recorder is refused as a whole.');

        // Over the browser: a form error, never a 500, and nothing recorded.
        $this->enterSchool($officer, $school);
        $this->from('/app/staff-attendance')->post('/app/staff-attendance/records', [
            'employment_record_id' => $mine->id, 'date' => $date, 'first_half' => 'present', 'second_half' => 'present',
        ])->assertRedirect('/app/staff-attendance')->assertSessionHasErrors('self_administration');

        $attendance->recordRegister($school, $date, [['employment_record_id' => $theirs->id, 'first_half' => 'present', 'second_half' => 'present']], $officer);
        $ownRecord = $attendance->record($school, $mine->id, $date, 'present', 'present', $peer);
        $this->assertSame(HrSelfAdministrationException::CODE, $this->refusal(fn () => $attendance->correct($school, $ownRecord->id, 1, 'absent', 'absent', 'entered_in_error', $officer)));
        $this->assertSame(1, $this->reload($school, $ownRecord)->version);
    }

    #[Test]
    public function nobody_sets_their_own_pay_even_holding_hr_and_payroll_together(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer] = $this->holder($school, $admin, 'hr_officer', 'payroll_officer');
        [$peer] = $this->holder($school, $admin, 'hr_officer');
        [$colleague] = $this->holder($school, $admin);
        $mine = $this->employ($school, $officer, $peer, 'The Officer');
        $theirs = $this->employ($school, $colleague, $peer, 'Colleague');

        $api = "/api/v1/schools/{$school->id}";
        $token = $this->mfaToken($officer);
        $this->withHeader('Authorization', 'Bearer '.$token);
        $component = $this->postJson("{$api}/salary-components", ['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning'])->assertCreated()->json('data.id');
        $structure = $this->postJson("{$api}/salary-structures", ['code' => 'G1', 'name' => 'G1'])->assertCreated()->json('data.id');
        $line = $this->postJson("{$api}/salary-structures/{$structure}/components", ['salary_component_id' => $component, 'calculation_type' => 'fixed_amount', 'base_component_id' => null, 'rate' => null, 'display_order' => 1])->json('data.id');
        $this->postJson("{$api}/salary-structures/{$structure}/activate")->assertOk();
        $pay = fn (string $amount) => ['salary_structure_id' => $structure, 'effective_from' => '2026-04-01', 'fixed_values' => [['salary_structure_component_id' => $line, 'amount' => $amount]]];

        $this->postJson("{$api}/employment-records/{$mine->id}/compensation-assignments", $pay('999999.00') + $this->mfaBody($token))
            ->assertForbidden()->assertJsonPath('error.code', 'PAYROLL_SELF_ADMINISTRATION');
        $this->postJson("{$api}/employment-records/{$theirs->id}/compensation-assignments", $pay('40000.00') + $this->mfaBody($token))->assertCreated();

        // Inside a run: no manual override or correction delta on one's own employment either.
        $period = $this->postJson("{$api}/payroll-periods", ['period_month' => '2026-09-01'])->json('data.id');
        $this->postJson("{$api}/payroll-periods/{$period}/open")->assertOk();
        $run = $this->withHeader('Idempotency-Key', 'sr4-self-pay-run')->postJson("{$api}/payroll-periods/{$period}/payroll-runs")->assertCreated()->json('data.id');
        $this->postJson("{$api}/payroll-runs/{$run}/manual-overrides", ['employment_record_id' => $mine->id, 'component_amounts' => [$component => '1.00'], 'reason' => 'mine'])
            ->assertForbidden()->assertJsonPath('error.code', 'PAYROLL_SELF_ADMINISTRATION');
    }

    #[Test]
    public function an_identity_in_one_school_is_never_the_acting_employee_in_another(): void
    {
        [$adminA, $schoolA] = $this->staffAdmin();
        [, $schoolB] = $this->staffAdmin();
        [$officerA] = $this->holder($schoolA, $adminA, 'hr_officer');
        [$person] = $this->holder($schoolA, $adminA);
        $this->createMembership($person, $schoolB);
        $this->employ($schoolA, $person, $officerA, 'Person');

        $this->assertNotNull(app(ActingEmployeeResolver::class)->resolve($person, $schoolA)->employeeId);
        $this->assertThrows(fn () => app(ActingEmployeeResolver::class)->resolve($person, $schoolB), ActingEmployeeUnavailableException::class);

        // School A's officer has no HR authority in School B at all.
        Auth::forgetGuards();
        $this->assertThrows(fn () => app(EmployeeService::class)->create($schoolB, ['full_name' => 'X'], $officerA));
    }
}
