<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C6 (UAT-HR-23, ADR 0075 section 19): the reporting manager is picked
 * by name -- "Name (EMP-…) — Position · Department" -- from CURRENT
 * assignments of active Employees with active or notice employment, never
 * the subordinate's own Employee or the actor's own. The browser never
 * shows or asks for an Assignment id; `ReportingHierarchyService` still
 * decides (same School, not the same Employee, no cycle), and its refusals
 * read without identifiers.
 */
class ReportingManagerPickerWebTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array{Employee, EmployeeAssignment} */
    private function staff(School $school, Position $position, string $name, string $number, array $employment = [], array $assignment = []): array
    {
        $employee = $this->createEmployee($school, ['full_name' => $name, 'employee_number' => $number]);
        $record = $this->createEmploymentRecord($employee, $employment);

        return [$employee, $this->createEmployeeAssignment($record, $position, array_merge(['starts_on' => '2026-01-01', 'is_primary' => true], $assignment))];
    }

    private function hr(School $school): User
    {
        $actor = $this->fullHrActor($school);
        $this->actingAs($actor)->post("/app/schools/{$school->id}/activate");

        return $actor;
    }

    private function url(School $school, EmployeeAssignment $a): string
    {
        $record = app(TenantContext::class)->withSchool($school, fn () => $a->employmentRecord);

        return "/app/hr/employees/{$record->employee_id}/employment-records/{$record->id}/assignments/{$a->id}";
    }

    private function managerOf(School $school, EmployeeAssignment $a): ?string
    {
        return app(TenantContext::class)->withSchool($school, fn () => $a->fresh()->manager_assignment_id);
    }

    #[Test]
    public function the_manager_is_found_by_name_and_saved_without_any_id_shown(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $principal = $this->createPosition($school, ['name' => 'Principal']);
        $teacher = $this->createPosition($school, ['name' => 'Trained Graduate Teacher']);
        $department = $this->createDepartment($school, ['name' => 'Academics']);
        [, $aisha] = $this->staff($school, $principal, 'Aisha Khan', 'EMP-000001', [], ['department_id' => $department->id]);
        [, $pooja] = $this->staff($school, $teacher, 'Pooja Sharma', 'EMP-000013');

        $candidates = $this->getJson($this->url($school, $pooja).'/manager-candidates?q=aisha')->assertOk()->json('candidates');
        $this->assertSame([['assignmentId' => $aisha->id, 'label' => 'Aisha Khan (EMP-000001) — Principal · Academics']], $candidates);
        $this->assertSame([$aisha->id], collect($this->getJson($this->url($school, $pooja).'/manager-candidates?q=000001')->json('candidates'))->pluck('assignmentId')->all(), 'employee number search');

        $this->post($this->url($school, $pooja).'/manager', ['manager_assignment_id' => $aisha->id])->assertSessionHasNoErrors();
        $this->assertSame($aisha->id, $this->managerOf($school, $pooja));

        $page = $this->get('/app/hr/employees/'.app(TenantContext::class)->withSchool($school, fn () => $pooja->employmentRecord->employee_id))->assertOk();
        $this->assertSame('Aisha Khan', $page->viewData('page')['props']['workspace']['summary']['manager_display_name']);

        // "Remove manager" clears it.
        $this->post($this->url($school, $pooja).'/manager', ['manager_assignment_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->managerOf($school, $pooja));
    }

    #[Test]
    public function only_current_assignments_of_active_staff_are_offered_never_the_same_employee_or_the_actor(): void
    {
        $school = $this->createSchool();
        $actor = $this->hr($school);
        $position = $this->createPosition($school, ['name' => 'Teacher']);
        [$self, $subordinate] = $this->staff($school, $position, 'Staff Subject', 'EMP-000100');
        $selfSecond = $this->createEmployeeAssignment(app(TenantContext::class)->withSchool($school, fn () => $subordinate->employmentRecord), $position, ['starts_on' => '2026-01-01']);
        [, $ended] = $this->staff($school, $position, 'Staff Ended', 'EMP-000101', [], ['ends_on' => '2026-02-01']);
        [, $future] = $this->staff($school, $position, 'Staff Future', 'EMP-000102', [], ['starts_on' => '2099-01-01']);
        [, $separated] = $this->staff($school, $position, 'Staff Separated', 'EMP-000103', ['status' => 'separated', 'ends_on' => '2026-03-01']);
        [$archivedEmployee] = $this->staff($school, $position, 'Staff Archived', 'EMP-000104');
        app(TenantContext::class)->withSchool($school, fn () => $archivedEmployee->forceFill(['record_status' => 'archived'])->save());
        [, $current] = $this->staff($school, $position, 'Staff Current', 'EMP-000105');
        [, $mine] = $this->staff($school, $position, 'Staff Mine', 'EMP-000106');
        app(TenantContext::class)->withSchool($school, fn () => Employee::query()->whereKey($mine->employmentRecord->employee_id)->update(['user_id' => $actor->id]));

        $offered = collect($this->getJson($this->url($school, $subordinate).'/manager-candidates?q=staff')->json('candidates'))->pluck('assignmentId')->all();

        $this->assertSame([$current->id], $offered);
        $this->assertNotContains($selfSecond->id, $offered);
        $this->assertNotContains($ended->id, $offered);
        $this->assertNotContains($future->id, $offered);
        $this->assertNotContains($separated->id, $offered);
        unset($self);
    }

    #[Test]
    public function the_server_still_refuses_what_the_picker_hides_without_showing_identifiers(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $position = $this->createPosition($school, ['name' => 'Teacher']);
        [, $a] = $this->staff($school, $position, 'Staff A', 'EMP-000201');
        [, $b] = $this->staff($school, $position, 'Staff B', 'EMP-000202');
        $aSecond = $this->createEmployeeAssignment(app(TenantContext::class)->withSchool($school, fn () => $a->employmentRecord), $position, ['starts_on' => '2026-01-01']);

        $this->post($this->url($school, $b).'/manager', ['manager_assignment_id' => $a->id])->assertSessionHasNoErrors();

        // A cycle (A -> B -> A).
        $cycle = $this->post($this->url($school, $a).'/manager', ['manager_assignment_id' => $b->id]);
        $cycle->assertSessionHasErrors(['manager_assignment_id' => 'That choice would make the reporting line loop back to this Employee. Choose another manager.']);
        $this->assertNull($this->managerOf($school, $a));

        // The same Employee's other assignment.
        $this->post($this->url($school, $a).'/manager', ['manager_assignment_id' => $aSecond->id])
            ->assertSessionHasErrors(['manager_assignment_id' => 'An Employee cannot report to themselves.']);

        // A stale or unknown choice.
        $this->post($this->url($school, $a).'/manager', ['manager_assignment_id' => '01a00000-0000-7000-8000-000000000000'])
            ->assertSessionHasErrors(['manager_assignment_id' => 'That manager could not be found in this School. Search again.']);

        foreach (session('errors')->getBag('default')->all() as $message) {
            $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}/', $message);
        }
    }

    #[Test]
    public function another_schools_assignment_is_never_offered_or_accepted(): void
    {
        $school = $this->createSchool();
        $foreign = $this->createSchool();
        $this->hr($school);
        [, $mine] = $this->staff($school, $this->createPosition($school), 'Local Staff', 'EMP-000301');
        [, $theirs] = $this->staff($foreign, $this->createPosition($foreign), 'Foreign Staff', 'EMP-000302');

        $this->assertSame([], $this->getJson($this->url($school, $mine).'/manager-candidates?q=foreign')->json('candidates'));
        $this->post($this->url($school, $mine).'/manager', ['manager_assignment_id' => $theirs->id])
            ->assertSessionHasErrors(['manager_assignment_id' => 'That manager could not be found in this School. Search again.']);
        $this->assertNull($this->managerOf($school, $mine));

        // The foreign chain itself is 404 here.
        $this->getJson($this->url($foreign, $theirs).'/manager-candidates?q=a')->assertNotFound();
    }

    #[Test]
    public function the_picker_needs_the_assignments_manage_capability(): void
    {
        $school = $this->createSchool();
        [, $a] = $this->staff($school, $this->createPosition($school), 'Staff A', 'EMP-000401');
        $viewer = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.employees.assignments.view']);
        $this->actingAs($viewer)->post("/app/schools/{$school->id}/activate");

        $this->getJson($this->url($school, $a).'/manager-candidates?q=staff')->assertForbidden();
    }

    #[Test]
    public function the_employee_page_has_no_assignment_id_field_and_uses_the_accessible_picker(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/App/HR/Employees/Show.vue'));

        $this->assertStringNotContainsString('Assignment UUID', $source);
        $this->assertStringNotContainsString('Manager Assignment id', $source);
        $this->assertMatchesRegularExpression('/<SearchCombobox\s+id="manager-search"/', $source);
        $this->assertMatchesRegularExpression('/<SearchCombobox\s+id="account-search"/', $source);
    }

    #[Test]
    public function a_mismatched_employee_employment_assignment_chain_is_not_found(): void
    {
        $school = $this->createSchool();
        $this->hr($school);
        $position = $this->createPosition($school);
        [$a, $aAssignment] = $this->staff($school, $position, 'Staff A', 'EMP-000501');
        [, $bAssignment] = $this->staff($school, $position, 'Staff B', 'EMP-000502');
        $bRecord = app(TenantContext::class)->withSchool($school, fn () => $bAssignment->employmentRecord);

        // Employee A with Employee B's employment / assignment.
        $this->getJson("/app/hr/employees/{$a->id}/employment-records/{$bRecord->id}/assignments/{$bAssignment->id}/manager-candidates?q=staff")->assertNotFound();
        $this->post("/app/hr/employees/{$a->id}/employment-records/{$bRecord->id}/assignments/{$bAssignment->id}/manager", ['manager_assignment_id' => $aAssignment->id])->assertNotFound();
        $this->assertNull($this->managerOf($school, $bAssignment));
    }

    #[Test]
    public function nobody_sets_their_own_reporting_line(): void
    {
        $school = $this->createSchool();
        $actor = $this->hr($school);
        $position = $this->createPosition($school);
        [$own, $ownAssignment] = $this->staff($school, $position, 'Staff Own', 'EMP-000601');
        [, $other] = $this->staff($school, $position, 'Staff Other', 'EMP-000602');
        app(TenantContext::class)->withSchool($school, fn () => $own->forceFill(['user_id' => $actor->id])->save());

        $this->post($this->url($school, $ownAssignment).'/manager', ['manager_assignment_id' => $other->id])->assertSessionHasErrors('manager_assignment_id');
        $this->post($this->url($school, $other).'/manager', ['manager_assignment_id' => $ownAssignment->id])->assertSessionHasErrors('manager_assignment_id');
        $this->assertNull($this->managerOf($school, $ownAssignment));
        $this->assertNull($this->managerOf($school, $other));
    }
}
