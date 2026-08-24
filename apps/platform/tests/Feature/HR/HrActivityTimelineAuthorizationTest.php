<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.11 -- REQUIRED authorization proof (checkpoint brief
 * section 58): default deny, per-category capability gating (reusing
 * the exact 8A.10 capability set, no new capability), multi-School
 * separation, and RLS-vs-RBAC.
 */
class HrActivityTimelineAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_ordinary_member_is_denied_base_timeline_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $ordinaryMember, new EmployeeActivityTimelineQuery);
    }

    #[Test]
    public function directory_capability_alone_does_not_grant_timeline_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $directoryOnly = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $directoryOnly, new EmployeeActivityTimelineQuery);
    }

    #[Test]
    public function personal_view_grants_base_timeline_access_with_employee_and_personal_categories(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'a@example.com'], $actor);

        $baseActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $baseActor, new EmployeeActivityTimelineQuery);

        $this->assertNotNull($result);
        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'));
    }

    #[Test]
    public function without_assignments_view_employment_events_are_absent(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);

        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $limitedActor, new EmployeeActivityTimelineQuery);

        $this->assertFalse(collect($result->items())->contains(fn ($e) => $e->category === 'employment'));
    }

    #[Test]
    public function with_assignments_view_employment_events_are_present(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);

        $permittedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.assignments.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $permittedActor, new EmployeeActivityTimelineQuery);

        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.employment.created'));
    }

    #[Test]
    public function without_qualifications_view_professional_events_are_absent(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors', 'qualification_name' => 'B.Sc', 'institution' => 'Some University',
        ], $actor);

        $limitedActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $limitedActor, new EmployeeActivityTimelineQuery);

        $this->assertFalse(collect($result->items())->contains(fn ($e) => $e->category === 'professional'));
    }

    #[Test]
    public function ordinary_documents_view_sees_restricted_document_events_only(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
            'storage_path' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);
        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'background_check', 'classification_tier' => 'highly_sensitive', 'storage_disk' => 'local',
            'storage_path' => 'b.pdf', 'original_filename' => 'b.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        $documentsOnly = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $documentsOnly, new EmployeeActivityTimelineQuery);

        $documentEvents = collect($result->items())->filter(fn ($e) => $e->eventType === 'hr.employee_document.created');
        $this->assertCount(1, $documentEvents, 'Only the restricted document\'s created event must be visible.');
    }

    #[Test]
    public function no_sensitive_view_means_sensitive_category_completely_absent(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'background_check', 'classification_tier' => 'highly_sensitive', 'storage_disk' => 'local',
            'storage_path' => 'b.pdf', 'original_filename' => 'b.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        $documentsOnly = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $documentsOnly, new EmployeeActivityTimelineQuery);

        $this->assertFalse(collect($result->items())->contains(fn ($e) => $e->category === 'sensitive_access'));
        $this->assertFalse(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.employee_document.created'), 'The highly_sensitive document\'s created event must not appear either.');
    }

    #[Test]
    public function sensitive_view_reveals_approved_sensitive_entries(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'background_check', 'classification_tier' => 'highly_sensitive', 'storage_disk' => 'local',
            'storage_path' => 'b.pdf', 'original_filename' => 'b.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ], $actor);

        $fullDocActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view', 'hr.employees.documents.view', 'hr.employees.sensitive.view']);
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $fullDocActor, new EmployeeActivityTimelineQuery);

        $this->assertTrue(collect($result->items())->contains(fn ($e) => $e->eventType === 'hr.employee_document.created'));
    }

    #[Test]
    public function the_same_user_is_permitted_in_school_a_and_denied_in_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $membershipA = $this->createMembership($user, $schoolA);
        $role = Role::query()->create(['key' => 'test.timeline.'.uniqid(), 'name' => 'Test Timeline', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['hr.employees.personal.view']);
        $this->assignSchoolRole($membershipA, $role->key);
        $this->createMembership($user, $schoolB);

        $employeeA = $this->createEmployee($schoolA);
        $resultA = app(EmployeeActivityTimelineService::class)->get($schoolA, $employeeA->id, $user, new EmployeeActivityTimelineQuery);
        $this->assertNotNull($resultA);

        $employeeB = $this->createEmployee($schoolB);
        $this->expectException(AuthorizationException::class);
        app(EmployeeActivityTimelineService::class)->get($schoolB, $employeeB->id, $user, new EmployeeActivityTimelineQuery);
    }

    #[Test]
    public function a_capability_in_school_a_cannot_reach_a_school_b_employees_timeline(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.personal.view']);
        $employeeB = $this->createEmployee($schoolB);

        $result = app(EmployeeActivityTimelineService::class)->get($schoolA, $employeeB->id, $actor, new EmployeeActivityTimelineQuery);

        $this->assertNull($result, 'A School B Employee id requested under School A context must resolve to null, never School B data.');
    }

    #[Test]
    public function rls_visible_same_school_data_is_still_denied_without_the_capability(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $member = $this->createUserWithCapabilities($school, []);

        app(TenantContext::class)->set($school);
        $this->assertTrue(Employee::query()->where('id', $employee->id)->exists(), 'Sanity: RLS genuinely permits this row.');

        $this->expectException(AuthorizationException::class);
        app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $member, new EmployeeActivityTimelineQuery);
    }

    #[Test]
    public function a_suspended_membership_loses_timeline_access_via_the_shared_resolver(): void
    {
        // Uses the SAME shared CapabilityResolver membership-status
        // behavior CapabilityResolverTest already proves generally
        // (docs/modules/HR.md 8A.10 as-built "Membership status") --
        // this is a Timeline-specific instance of that proof, not a new
        // mechanism. Closes 8A.10's own P4 evidence gap ("relies on
        // shared behavior, not independently retested for HR") for the
        // Timeline specifically.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school, status: 'suspended');
        $role = Role::query()->create(['key' => 'test.timeline.suspended.'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['hr.employees.personal.view']);
        $this->assignSchoolRole($membership, $role->key);

        $this->expectException(AuthorizationException::class);

        app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $user, new EmployeeActivityTimelineQuery);
    }

    #[Test]
    public function manager_relationship_alone_grants_no_timeline_access(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);
        $managerUser = $this->createUser();
        $this->createMembership($managerUser, $school);
        $subordinateEmployee = $this->createEmployee($school);
        $managerEmployee = app(EmployeeService::class)->create($school, ['full_name' => 'Manager', 'user_id' => $managerUser->id], $actor);

        $subordinateAssignment = $this->createEmployeeAssignment($this->createEmploymentRecord($subordinateEmployee), $position);
        $managerAssignment = $this->createEmployeeAssignment($this->createEmploymentRecord($managerEmployee), $position);
        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        // managerUser has a real membership but NO hr.* capability at all --
        // being someone's manager must never itself grant Timeline access.
        $this->expectException(AuthorizationException::class);
        app(EmployeeActivityTimelineService::class)->get($school, $subordinateEmployee->id, $managerUser, new EmployeeActivityTimelineQuery);
    }
}
