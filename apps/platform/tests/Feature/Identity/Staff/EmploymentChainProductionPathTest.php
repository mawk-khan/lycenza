<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.4 (ADR 0071 §19 "employment chain, production profile", §26.1): the
 * EmploymentRecord -> ActingEmployee chain end to end, with production roles
 * only. A School Admin grants `hr_officer` and `payroll_officer` through the
 * real SR.2 path (fresh MFA); the HR officer creates the Employee, links the
 * User, creates the EmploymentRecord, the assignment and the reporting line
 * through the real HTTP surfaces; every downstream surface (teaching,
 * self-service, leave, staff attendance, payroll) then works -- each under
 * its OWN authorization -- and nothing else does. No identity or employment
 * row is written by a fixture.
 */
class EmploymentChainProductionPathTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesMfaFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, ProvidesSensitiveActionMfa, StaffAccountTestHelpers {
        StaffAccountTestHelpers::staffMember insteadof CreatesTeacherDeliveryFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00'); // a Monday
    }

    private function grant(User $admin, School $school, string $membershipId, string $role): void
    {
        $this->staffPost($admin, $school, "/members/{$membershipId}/roles", ['role' => $role])->assertOk();
    }

    private function bearer(User $user): static
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$this->mfaToken($user));
    }

    /** The HR officer, over the browser: Employee, employment and a primary assignment. */
    private function hire(User $officer, School $school, string $name, Position $position): EmploymentRecord
    {
        $this->enterSchool($officer, $school);
        $this->post('/app/hr/employees', ['full_name' => $name])->assertRedirect();
        $employee = $this->inSchool($school, fn () => Employee::query()->where('full_name', $name)->sole());
        $this->post("/app/hr/employees/{$employee->id}/employment-records", ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $employment = $this->inSchool($school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->sole());
        $this->post("/app/hr/employees/{$employee->id}/employment-records/{$employment->id}/assignments", ['starts_on' => '2024-01-01', 'position_id' => $position->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        return $employment;
    }

    /** The HR officer links the Employee to the User over the JSON API. */
    private function link(User $officer, School $school, EmploymentRecord $employment, User $user): void
    {
        $this->bearer($officer)->withHeader('Idempotency-Key', 'link-'.$employment->id)
            ->postJson("/api/v1/schools/{$school->id}/employees/{$employment->employee_id}/link-user", ['user_id' => $user->id])
            ->assertOk();
        $this->flushHeaders();
        Auth::forgetGuards();
    }

    #[Test]
    public function the_employment_chain_works_end_to_end_with_production_roles_only(): void
    {
        $w = $this->teacherAttendanceWorld();
        $school = $w['school'];
        [$admin] = $this->staffAdmin($school);
        [$officer, $officerMembership] = $this->staffMember($school, 'staff_self_service');
        [$teacher, $teacherMembership] = $this->staffMember($school, 'staff_self_service');
        [$principal] = $this->staffMember($school, 'principal');
        [$payroll, $payrollMembership] = $this->staffMember($school, 'staff_self_service');

        // 1. School Admin grants hr_officer (grant right school.roles.grant.hr) and payroll_officer.
        $this->grant($admin, $school, $officerMembership->id, 'hr_officer');
        $this->grant($admin, $school, $payrollMembership->id, 'payroll_officer');

        // 2-4. The HR officer establishes both identities and the reporting line.
        $this->enterSchool($officer, $school);
        $this->post('/app/hr/positions', ['name' => 'Teacher', 'code' => 'TCHR'])->assertRedirect();
        $position = $this->inSchool($school, fn () => Position::query()->where('code', 'TCHR')->sole());
        $teacherEmployment = $this->hire($officer, $school, 'Asha Teacher', $position);
        $principalEmployment = $this->hire($officer, $school, 'Ravi Principal', $position);
        $this->link($officer, $school, $teacherEmployment, $teacher);
        $this->link($officer, $school, $principalEmployment, $principal);
        [$teacherAssignment, $principalAssignment] = $this->inSchool($school, fn () => [
            EmployeeAssignment::query()->where('employment_record_id', $teacherEmployment->id)->sole(),
            EmployeeAssignment::query()->where('employment_record_id', $principalEmployment->id)->sole(),
        ]);
        $this->enterSchool($officer, $school);
        $this->post("/app/hr/employees/{$teacherEmployment->employee_id}/employment-records/{$teacherEmployment->id}/assignments/{$teacherAssignment->id}/manager", [
            'manager_assignment_id' => $principalAssignment->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // 5. ActingEmployee resolves -- to exactly that identity.
        $acting = app(ActingEmployeeResolver::class)->resolve($teacher, $school);
        $this->assertSame([$teacherEmployment->employee_id, $teacherEmployment->id], [$acting->employeeId, $acting->employmentRecordId]);

        // 6. Teacher authority: a teacher grant plus a TeachingAssignment, owned context only.
        $this->grant($admin, $school, $teacherMembership->id, 'teacher');
        app(TeachingAssignmentService::class)->create($school, $teacherEmployment->employee_id, $w['section']->id, $w['offering']->id, '2026-06-01', null, $admin);
        $this->enterSchool($teacher, $school);
        $this->withMfaAssurance($teacher)->get('/app/my-attendance')->assertOk();
        $this->post('/app/my-attendance', ['timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsA'])])
            ->assertRedirect();
        $this->post('/app/my-attendance', ['timetable_entry_id' => $w['entryB']->id, 'attendance_date' => '2026-09-14', 'records' => $this->allPresent($w['studentsB'])])
            ->assertNotFound();

        // The officer is not a teacher; a second operational role (librarian) adds its
        // own module and leaves teacher ownership exactly as it was.
        $this->enterSchool($officer, $school);
        $this->withMfaAssurance($officer)->get('/app/my-attendance')->assertForbidden();
        $this->grant($admin, $school, $teacherMembership->id, 'librarian');
        $this->enterSchool($teacher, $school);
        $this->withMfaAssurance($teacher)->get('/app/library/titles')->assertOk();
        $this->post('/app/my-attendance', ['timetable_entry_id' => $w['entryB']->id, 'attendance_date' => '2026-09-21', 'records' => $this->allPresent($w['studentsB'])])
            ->assertNotFound();

        // 7. Leave: configured by the HR officer, requested by the teacher on the
        // self-service path, decided by the reporting-line manager -- never by HR
        // on the manager path (`hr.leave.approve` is not an HR officer key).
        $type = app(LeaveTypeService::class)->create($school, 'CL', 'Casual leave', true, true, true, $officer);
        $policy = app(LeavePolicyService::class)->create($school, $type->id, ['name' => 'Standard', 'annual_allocation_units' => 24, 'carry_forward_allowed' => false], null, $officer);
        $year = app(LeaveYearService::class)->open($school, '2026-06-01', $officer);
        app(StaffCalendarService::class)->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], $officer);
        app(LeavePolicyAssignmentService::class)->assign($school, $teacherEmployment->id, $policy->id, '2026-04-01', null, $officer);
        app(LeaveLedgerService::class)->allocate($school, $teacherEmployment->id, $type->id, $year->id, 24, $officer);

        $this->enterSchool($teacher, $school);
        $this->get('/app/my-leave')->assertOk();
        $this->post('/app/my-leave/requests', ['leave_type_id' => $type->id, 'starts_on' => '2026-10-12', 'start_portion' => 'full', 'ends_on' => '2026-10-12', 'end_portion' => 'full'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $leave = $this->inSchool($school, fn () => LeaveRequest::query()->where('employment_record_id', $teacherEmployment->id)->sole());

        $this->enterSchool($officer, $school);
        $this->post("/app/leave/approvals/{$leave->id}/approve")->assertForbidden();
        $this->enterSchool($principal, $school);
        $this->post("/app/leave/approvals/{$leave->id}/approve")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->inSchool($school, fn () => $leave->fresh()->status));

        // 8. Staff attendance: the HR officer records it; the teacher reads only their own.
        $this->enterSchool($officer, $school);
        $this->post('/app/staff-attendance/register', ['date' => '2026-10-05', 'items' => [
            ['employment_record_id' => $teacherEmployment->id, 'first_half' => 'present', 'second_half' => 'present'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inSchool($school, fn () => StaffAttendanceRecord::query()->where('employment_record_id', $teacherEmployment->id)->count()));
        $this->enterSchool($teacher, $school);
        $this->get('/app/my-staff-attendance')->assertOk();
        $this->get('/app/staff-attendance')->assertForbidden();
        $this->get('/app/hr/employees')->assertForbidden();

        // 9. Payroll references the legitimate employment: the payroll officer
        // assigns compensation (fresh MFA) and cannot approve, post or reach statutory payroll.
        $base = "/api/v1/schools/{$school->id}";
        $component = $this->bearer($payroll)->postJson("{$base}/salary-components", ['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning'])->assertCreated()->json('data.id');
        $structure = $this->postJson("{$base}/salary-structures", ['code' => 'TCH', 'name' => 'Teacher'])->assertCreated()->json('data.id');
        $line = $this->postJson("{$base}/salary-structures/{$structure}/components", [
            'salary_component_id' => $component, 'calculation_type' => 'fixed_amount', 'base_component_id' => null, 'rate' => null, 'display_order' => 1,
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/salary-structures/{$structure}/activate")->assertOk();
        $this->postJson("{$base}/employment-records/{$teacherEmployment->id}/compensation-assignments", [
            'salary_structure_id' => $structure, 'effective_from' => '2026-04-01',
            'fixed_values' => [['salary_structure_component_id' => $line, 'amount' => '42000.00']],
        ] + $this->mfaBody())->assertCreated();
        $this->assertSame(1, $this->inSchool($school, fn () => EmployeeCompensationAssignment::query()->where('employment_record_id', $teacherEmployment->id)->count()));
        $this->enterSchool($payroll, $school);
        // Statutory payroll stays legal-gated: the shell page carries no data and every statutory read refuses.
        $this->get('/app/payroll/statutory')->assertOk()->assertInertia(fn ($page) => $page->where('can.view', false)->where('ruleStatus', null));
        $this->get('/app/payroll/statutory/employees/search?q=Asha')->assertForbidden();
        $this->get('/app/payroll/accounting')->assertForbidden(); // SR.4 §26.5: not the payroll officer's

        // 10. Legal gates stay independent of every role used here.
        // (`teacher`'s own `examinations.marks.teacher` is RES-L0-gated in code: refused in production, ADR 0068 §27.)
        $roles = Role::query()->whereIn('key', ['hr_officer', 'payroll_officer', 'staff_self_service', 'librarian'])->with('capabilities')->get();
        $keys = $roles->flatMap(fn (Role $r) => $r->capabilities->pluck('key'))->unique();
        foreach (['examinations.marks.', 'payroll.statutory.', 'communications.conversations.students', 'analytics.export'] as $gated) {
            $this->assertFalse($keys->contains(fn (string $k) => str_starts_with($k, $gated)), "{$gated} stays legal-gated");
        }
        app(CapabilityResolver::class)->forgetCache($officer, $school);
        $this->assertFalse(app(CapabilityResolver::class)->can($officer, 'payroll.compensation.sensitive.view', $school));
    }
}
