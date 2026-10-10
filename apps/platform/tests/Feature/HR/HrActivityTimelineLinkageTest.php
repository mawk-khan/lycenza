<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineEntry;
use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.11 -- REQUIRED linkage proof (checkpoint brief section 57):
 * every mapped HR audit-event family resolves to the correct Employee's
 * Timeline via a real domain operation -> AuditRecorder -> Timeline
 * round trip (not synthetic audit rows), and the critical actor != subject
 * invariant (section 6) holds.
 */
class HrActivityTimelineLinkageTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_employee_created_event_appears_on_its_own_timeline(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Asha Verma'], $actor);

        $entries = $this->entries($school, $employee->id, $actor);

        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'employee.created'));
    }

    #[Test]
    public function a_personal_detail_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeePersonalDetailService::class)->setDetails($employee, ['personal_email' => 'a@example.com'], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'));
    }

    #[Test]
    public function an_address_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeeAddressService::class)->add($employee, ['address_type' => 'current', 'address_line1' => '1 Main St'], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'employee.address.created'));
    }

    #[Test]
    public function an_employment_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);
        app(EmploymentService::class)->update($employment, ['employment_type' => 'contract'], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.employment.created'));
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.employment.updated'), 'hr.employment.updated must resolve via the 8A.11 employeeId metadata addition.');
    }

    #[Test]
    public function an_assignment_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01'], $actor);

        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $actor);
        app(EmployeeAssignmentService::class)->end($assignment, '2026-06-01', $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.assignment.created'));
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.assignment.ended'));
    }

    #[Test]
    public function a_reporting_manager_change_resolves_to_the_subordinates_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        $subordinateEmployee = $this->createEmployee($school, ['full_name' => 'Subordinate']);
        $managerEmployee = $this->createEmployee($school, ['full_name' => 'Manager']);
        $subordinateAssignment = $this->createEmployeeAssignment($this->createEmploymentRecord($subordinateEmployee), $position);
        $managerAssignment = $this->createEmployeeAssignment($this->createEmploymentRecord($managerEmployee), $position);

        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        $subordinateEntries = $this->entries($school, $subordinateEmployee->id, $actor);
        $this->assertTrue(collect($subordinateEntries)->contains(fn ($e) => $e->eventType === 'hr.assignment.manager_changed'), 'The manager-changed event belongs to the SUBORDINATE\'s timeline.');

        $managerEntries = $this->entries($school, $managerEmployee->id, $actor);
        $this->assertFalse(collect($managerEntries)->contains(fn ($e) => $e->eventType === 'hr.assignment.manager_changed'), 'The manager-changed event must NOT appear on the MANAGER\'s own timeline.');
    }

    #[Test]
    public function a_qualification_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors', 'qualification_name' => 'B.Sc', 'institution' => 'Some University',
        ], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.qualification.created'));
    }

    #[Test]
    public function a_certification_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeeCertificationService::class)->add($employee, ['name' => 'First Aid', 'issuer' => 'Red Cross'], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.certification.created'));
    }

    #[Test]
    public function an_employee_document_event_resolves_to_the_target_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(EmployeeDocumentService::class)->register($employee, [
            'category' => 'id_proof', 'classification_tier' => 'restricted', 'storage_disk' => 'local',
            'storage_path' => 'employee-documents/doc.pdf', 'original_filename' => 'doc.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
        ], $actor);

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertTrue(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.employee_document.created'));
    }

    #[Test]
    public function an_event_where_the_employees_linked_user_is_the_actor_but_not_the_subject_does_not_appear_on_the_actors_own_timeline(): void
    {
        $school = $this->createSchool();
        $hrUser = $this->createUserWithCapabilities($school, ['hr.employees.manage', 'hr.employees.personal.manage', 'hr.employees.personal.view']);
        // SR.4 (ADR 0071 §26.2): another HR holder links the actor's own Employee.
        $actorEmployee = app(EmployeeService::class)->create($school, ['full_name' => 'Actor Employee', 'user_id' => $hrUser->id], $this->fullHrActor($school));
        $subjectEmployee = $this->createEmployee($school, ['full_name' => 'Subject Employee']);

        // The Employee whose OWN linked User performs an action on a
        // DIFFERENT Employee must not see that action in their own timeline.
        app(EmployeePersonalDetailService::class)->setDetails($subjectEmployee, ['personal_email' => 'x@example.com'], $hrUser);

        $actorEmployeeEntries = $this->entries($school, $actorEmployee->id, $hrUser);
        $this->assertFalse(
            collect($actorEmployeeEntries)->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'),
            'Actor identity must never be mistaken for subject Employee identity.',
        );

        $subjectEmployeeEntries = $this->entries($school, $subjectEmployee->id, $hrUser);
        $this->assertTrue(collect($subjectEmployeeEntries)->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'));
    }

    #[Test]
    public function an_event_belonging_to_a_different_employee_never_appears(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);

        app(EmployeePersonalDetailService::class)->setDetails($employeeA, ['personal_email' => 'a@example.com'], $actor);

        $employeeBEntries = $this->entries($school, $employeeB->id, $actor);
        $this->assertFalse(collect($employeeBEntries)->contains(fn ($e) => $e->eventType === 'employee.personal_details.updated'));
    }

    #[Test]
    public function an_unresolvable_event_is_excluded_rather_than_guessed(): void
    {
        // A real audit event that carries NO Employee linkage at all
        // (Department is not Employee-scoped) -- must never appear on
        // any Employee's timeline, and must never be guessed into one
        // via name/string matching.
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->withSchool($school, function () use ($school) {
            app(AuditRecorder::class)->school($school, 'hr.department.created', metadata: ['code' => 'FIN']);
        });

        $entries = $this->entries($school, $employee->id, $actor);
        $this->assertFalse(collect($entries)->contains(fn ($e) => $e->eventType === 'hr.department.created'));

        // Sanity: the event really was written (not silently dropped by AuditRecorder).
        app(TenantContext::class)->set($school);
        $this->assertSame(1, SchoolAuditEvent::query()->where('event_type', 'hr.department.created')->count());
    }

    /**
     * @return array<int, EmployeeActivityTimelineEntry>
     */
    private function entries(School $school, string $employeeId, User $actor): array
    {
        $result = app(EmployeeActivityTimelineService::class)->get($school, $employeeId, $actor, new EmployeeActivityTimelineQuery(perPage: 100));

        return $result === null ? [] : $result->items();
    }
}
