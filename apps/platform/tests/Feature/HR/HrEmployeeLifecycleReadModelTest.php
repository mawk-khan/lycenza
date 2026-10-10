<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeActivityTimelineQuery;
use App\Domain\HR\Application\EmployeeActivityTimelineService;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED read-model integration proof (checkpoint
 * brief sections 39-41/67): 8A.8 Directory, 8A.9 Profile Workspace, and
 * 8A.11 Activity Timeline must all correctly reflect separation/rehire
 * WITHOUT any code change to those classes -- their existing temporal
 * (`starts_on <= today <= ends_on`) and `record_status`-only filtering
 * logic already produces the right answer. These tests prove that,
 * they do not change those services.
 */
class HrEmployeeLifecycleReadModelTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_separated_employee_still_appears_in_the_directory_with_no_current_position(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor);
        app(EmployeeLifecycleService::class)->separate($employment, '2026-01-31', $actor);

        $page = app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery(includeArchived: true), $actor);
        $entry = collect($page->items())->firstWhere('employeeId', $employee->id);

        $this->assertNotNull($entry, 'A separated Employee (record_status still active) must still appear in the Directory.');
        $this->assertNull($entry->positionId, 'The Employment ended (2026-01-31) before the fixed "today" (2026-06-15), so it is no longer CURRENT -- no position is shown.');
    }

    #[Test]
    public function directory_reflects_a_rehired_employees_new_current_position_not_the_old_one(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $oldPosition = $this->createPosition($school, ['code' => 'OLD']);
        $newPosition = $this->createPosition($school, ['code' => 'NEW']);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmployeeAssignmentService::class)->create($firstEmployment, ['starts_on' => '2020-01-01'], $oldPosition, $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        app(EmployeeLifecycleService::class)->rehire(
            $employee,
            ['employment_type' => 'permanent', 'starts_on' => '2023-01-01'],
            $actor,
            assignmentAttributes: ['starts_on' => '2023-01-01'],
            position: $newPosition,
        );

        $page = app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);
        $entry = collect($page->items())->firstWhere('employeeId', $employee->id);

        $this->assertSame($newPosition->id, $entry->positionId);
        $this->assertNotSame($oldPosition->id, $entry->positionId);
    }

    #[Test]
    public function the_profile_workspace_shows_both_employment_records_with_correct_current_flags_after_rehire(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');
        $secondEmployment = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2023-01-01'], $actor);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $this->assertNotNull($profile);
        $this->assertCount(2, $profile->employmentHistory);
        $first = collect($profile->employmentHistory)->firstWhere('id', $firstEmployment->id);
        $second = collect($profile->employmentHistory)->firstWhere('id', $secondEmployment->id);
        $this->assertFalse($first->isCurrent);
        $this->assertTrue($second->isCurrent);
        $this->assertSame('active', $second->status);
        $this->assertSame('active', $profile->summary->currentEmploymentStatus);
    }

    #[Test]
    public function a_future_dated_rehire_is_not_marked_current_in_the_profile(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');
        $secondEmployment = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-09-01'], $actor);

        $profile = app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $actor);

        $second = collect($profile->employmentHistory)->firstWhere('id', $secondEmployment->id);
        $this->assertFalse($second->isCurrent, 'A future-starting Employment must not be marked current until its starts_on arrives.');
        $this->assertNull($profile->summary->positionId, 'No current position while the only rehire Employment has not started yet.');
    }

    #[Test]
    public function the_timeline_shows_separation_and_rehire_activity_using_the_existing_event_types_only(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmployeeLifecycleService::class)->separate($firstEmployment, '2022-12-31', $actor);
        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2023-01-01'], $actor);

        $timeline = app(EmployeeActivityTimelineService::class)->get($school, $employee->id, $actor, new EmployeeActivityTimelineQuery(perPage: 100));

        $this->assertNotNull($timeline);
        $eventTypes = collect($timeline->items())->pluck('eventType')->all();
        $this->assertContains('hr.employment.created', $eventTypes);
        $this->assertContains('hr.employment.ended', $eventTypes);
        $this->assertSame(2, collect($eventTypes)->filter(fn ($t) => $t === 'hr.employment.created')->count(), 'One created event per EmploymentRecord (first hire + rehire) -- no new event family was introduced.');
        foreach ($eventTypes as $eventType) {
            $this->assertStringStartsNotWith('hr.employee.', $eventType, 'No new hr.employee.separated/hr.employee.rehired event family exists -- 8A.13 reuses hr.employment.* exclusively.');
        }
    }

    #[Test]
    public function the_lifecycle_actor_is_never_confused_with_the_employee_acted_upon_on_the_timeline(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $hrUser = $this->createUserWithCapabilities($school, [
            'hr.employees.manage', 'hr.employees.assignments.manage', 'hr.employees.assignments.view', 'hr.employees.personal.view',
        ]);
        // SR.4 (ADR 0071 §26.2): another HR holder links the actor's own Employee.
        $hrActorEmployee = app(EmployeeService::class)->create($school, ['full_name' => 'HR Staff', 'user_id' => $hrUser->id], $this->fullHrActor($school));
        $targetEmployee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($targetEmployee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $hrUser);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $hrUser);

        $hrActorTimeline = app(EmployeeActivityTimelineService::class)->get($school, $hrActorEmployee->id, $hrUser, new EmployeeActivityTimelineQuery(perPage: 100));
        $this->assertCount(0, collect($hrActorTimeline->items())->filter(fn ($e) => $e->eventType === 'hr.employment.ended'), 'The separation event belongs to the TARGET employee, never the acting HR staff members own timeline.');

        $targetTimeline = app(EmployeeActivityTimelineService::class)->get($school, $targetEmployee->id, $hrUser, new EmployeeActivityTimelineQuery(perPage: 100));
        $this->assertCount(1, collect($targetTimeline->items())->filter(fn ($e) => $e->eventType === 'hr.employment.ended'));
    }
}
