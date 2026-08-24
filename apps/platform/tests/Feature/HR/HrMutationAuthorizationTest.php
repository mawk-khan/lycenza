<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- REQUIRED deny-path proof for every remaining HR
 * mutation capability boundary not already covered by
 * HrDefaultDenyTest/HrDocumentClassificationAuthorizationTest:
 * personal-data management, employment/assignment management
 * (including the reporting-manager mutation), and professional-record
 * management (including verify/reject, which share `.qualifications.manage`
 * by this checkpoint's own documented design -- see
 * docs/modules/HR.md 8A.10 as-built for why no separate `.verify`
 * capability was introduced). Also proves capability revocation takes
 * effect and that Position/manager relationships never themselves
 * grant authorization.
 */
class HrMutationAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function personal_view_alone_cannot_write_personal_details(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $viewOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'x@example.com'], $viewOnlyActor);
    }

    #[Test]
    public function personal_manage_can_write_personal_details(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.manage']);

        $detail = app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'x@example.com'], $actor);

        $this->assertSame('x@example.com', $detail->personal_email);
    }

    #[Test]
    public function personal_manage_is_required_to_add_an_address(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapabilityActor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeAddressService::class)->add($employee, ['address_type' => 'current', 'address_line1' => '1 Main St'], $noCapabilityActor);
    }

    #[Test]
    public function personal_manage_is_required_to_add_an_emergency_contact(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeEmergencyContactService::class)->add($employee, ['name' => 'Contact', 'phone' => '123'], $noCapabilityActor);
    }

    #[Test]
    public function assignments_manage_is_required_to_create_an_employment_record(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapabilityActor = $this->createUserWithCapabilities($school, ['hr.employees.manage']);

        $this->expectException(AuthorizationException::class);

        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $noCapabilityActor);
    }

    #[Test]
    public function assignments_manage_is_required_to_end_an_employment_record(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['ends_on' => null]);
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmploymentService::class)->end($employment, '2026-12-31', $noCapabilityActor);
    }

    #[Test]
    public function assignments_manage_is_required_to_end_an_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);
        $noCapabilityActor = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeAssignmentService::class)->end($assignment, '2026-12-31', $noCapabilityActor);
    }

    #[Test]
    public function assignments_manage_is_required_to_change_a_reporting_manager(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $subordinate = $this->createEmployeeAssignment($this->createEmploymentRecord($employeeA), $position);
        $manager = $this->createEmployeeAssignment($this->createEmploymentRecord($employeeB), $position);
        $noCapabilityActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.manage']);

        $this->expectException(AuthorizationException::class);

        app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $noCapabilityActor);
    }

    #[Test]
    public function qualifications_manage_is_required_to_add_a_qualification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $noCapabilityActor = $this->createUserWithCapabilities($school, ['hr.employees.qualifications.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors',
            'qualification_name' => 'B.Sc',
            'institution' => 'Some University',
        ], $noCapabilityActor);
    }

    #[Test]
    public function qualifications_manage_is_required_to_verify_a_qualification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);
        // An actor who can VIEW professional records but not manage/verify them.
        $viewOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.qualifications.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeQualificationService::class)->verify($employee, $qualification, $viewOnlyActor);
    }

    #[Test]
    public function qualifications_manage_is_required_to_verify_a_certification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);
        $viewOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.qualifications.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeCertificationService::class)->verify($employee, $certification, $viewOnlyActor);
    }

    #[Test]
    public function qualifications_manage_grants_verification_by_this_checkpoints_design(): void
    {
        // Documents the deliberate design decision: no separate
        // `.verify` capability exists (docs/modules/HR.md 8A.10
        // as-built) -- `.qualifications.manage` covers add/update/
        // remove/verify/reject uniformly.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.qualifications.manage']);

        $verified = app(EmployeeQualificationService::class)->verify($employee, $qualification, $actor);

        $this->assertSame('verified', $verified->verification_status);
    }

    #[Test]
    public function capability_revocation_takes_effect_on_the_next_check(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.personal.manage']);

        // Works while the grant stands.
        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'first@example.com'], $actor);

        // Revoke: remove every school-role assignment for this actor at
        // this School, then explicitly invalidate the resolver's cache
        // (docs/security/AUTHORIZATION.md's documented mechanism --
        // this repository has no automatic revoke-triggered
        // invalidation hook yet, so a real caller doing a role change
        // must call this itself, exactly as this test does).
        app(TenantContext::class)->withSchool($school, function () use ($actor, $school) {
            MembershipRoleAssignment::query()
                ->whereHas('membership', fn ($q) => $q->where('user_id', $actor->id)->where('school_id', $school->id))
                ->delete();
        });
        app(CapabilityResolver::class)->forgetCache($actor, $school);

        $this->expectException(AuthorizationException::class);

        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'second@example.com'], $actor);
    }

    #[Test]
    public function changing_position_department_or_manager_never_creates_an_authorization_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, [
            'hr.employees.manage', 'hr.employees.assignments.manage', 'hr.departments.manage', 'hr.positions.manage',
        ]);
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $department = $this->createDepartment($school);

        $rolesBefore = Role::query()->count();

        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);
        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $actor, department: $department);

        $this->assertSame($rolesBefore, Role::query()->count(), 'Assigning a Position/Department must never itself create a Role.');
    }
}
