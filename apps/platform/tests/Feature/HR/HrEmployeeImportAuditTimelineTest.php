<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmployeeService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED audit/Timeline compatibility proof
 * (checkpoint brief sections 58-60/81). Because import uses the exact
 * same authoritative services as interactive creation, imported
 * Employees emit the exact same audit events (ADR 0017) and are
 * therefore immediately Timeline-compatible (8A.11) -- no special
 * "imported" audit event exists, and none is needed.
 */
class HrEmployeeImportAuditTimelineTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_successfully_imported_employee_emits_the_ordinary_employee_created_audit_event(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [['full_name' => 'Asha Verma']]);
        $employeeId = $result->rows[0]->employeeId;

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'employee.created')
            ->where('subject_id', $employeeId)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($actor->id, $event->actor_user_id);
    }

    #[Test]
    public function imported_child_mutations_emit_the_ordinary_existing_audit_events(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com',
            'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code,
        ]]);

        app(TenantContext::class)->set($school);
        $eventTypes = SchoolAuditEvent::query()->pluck('event_type')->all();

        $this->assertContains('employee.created', $eventTypes);
        $this->assertContains('employee.personal_details.updated', $eventTypes);
        $this->assertContains('hr.employment.created', $eventTypes);
        $this->assertContains('hr.assignment.created', $eventTypes);
        $this->assertContains('hr.assignment.primary_changed', $eventTypes);
    }

    #[Test]
    public function the_activity_timeline_correctly_links_an_imported_employees_activity(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com',
            'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code,
        ]]);
        $employeeId = $result->rows[0]->employeeId;

        $timeline = app(EmployeeActivityTimelineService::class)->get($school, $employeeId, $actor, new EmployeeActivityTimelineQuery(perPage: 100));

        $this->assertNotNull($timeline);
        $eventTypes = collect($timeline->items())->pluck('eventType')->all();
        $this->assertContains('employee.created', $eventTypes);
        $this->assertContains('employee.personal_details.updated', $eventTypes);
        $this->assertContains('hr.employment.created', $eventTypes);
        $this->assertContains('hr.assignment.created', $eventTypes);
    }

    #[Test]
    public function the_importing_actor_is_never_confused_with_the_imported_employee_as_timeline_subject(): void
    {
        $school = $this->createSchool();
        $hrUser = $this->createUserWithCapabilities($school, [
            'hr.employees.manage', 'hr.employees.personal.manage', 'hr.employees.assignments.manage', 'hr.employees.personal.view',
        ]);
        $hrActorEmployee = app(EmployeeService::class)->create($school, ['full_name' => 'HR Staff Member', 'user_id' => $hrUser->id], $hrUser);

        $result = app(EmployeeImportService::class)->import($school, $hrUser, [['full_name' => 'Imported Employee']]);
        $importedEmployeeId = $result->rows[0]->employeeId;

        // The HR actor's OWN Employee timeline must show exactly ONE
        // employee.created event -- their own -- never a second one for
        // the Employee they merely acted upon (actor identity is never
        // mistaken for subject Employee identity, 8A.11's invariant,
        // unaffected by import).
        $hrActorTimeline = app(EmployeeActivityTimelineService::class)->get($school, $hrActorEmployee->id, $hrUser, new EmployeeActivityTimelineQuery(perPage: 100));
        $this->assertCount(1, collect($hrActorTimeline->items())->filter(fn ($e) => $e->eventType === 'employee.created'));

        $importedEmployeeTimeline = app(EmployeeActivityTimelineService::class)->get($school, $importedEmployeeId, $hrUser, new EmployeeActivityTimelineQuery(perPage: 100));
        $this->assertCount(1, collect($importedEmployeeTimeline->items())->filter(fn ($e) => $e->eventType === 'employee.created'), 'The imported Employee has its own, separate employee.created event on its own timeline.');
    }

    #[Test]
    public function a_failed_row_produces_no_committed_mutation_audit_event(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Never Created', 'employment_type' => 'permanent',
            'employment_starts_on' => '2026-01-01', 'position_code' => 'NOPE',
        ]]);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, SchoolAuditEvent::query()->where('event_type', 'employee.created')->count());
    }
}
