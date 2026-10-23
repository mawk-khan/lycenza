<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-06: the Employee directory narrows by the
 * latest employment state (HR-040), by working on several Campuses (HR-045),
 * by joining in a period (HR-056) and by reporting to a manager (HR-044) --
 * filters only, inside the reader's existing scope (HrEmployeeAccess), never
 * revealing another Campus or any sensitive field.
 */
class HrDirectoryReportFiltersTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Position $role;

    /** @var array<string, EmploymentRecord> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $this->role = $this->createPosition($this->school, ['name' => 'Teacher', 'status' => 'active']);

        $this->staff['onlyA'] = $this->employee('Asha OnlyA', [$this->a], starts: '2025-01-01');
        $this->staff['onlyB'] = $this->employee('Bela OnlyB', [$this->b], starts: '2026-07-01');
        $this->staff['both'] = $this->employee('Chitra Both', [$this->b, $this->a], starts: '2026-06-15');
        $this->staff['wide'] = $this->employee('Dev Wide', [null], starts: '2025-01-01');
        $this->staff['notice'] = $this->employee('Esha Notice', [$this->a], ['status' => 'notice_period'], starts: '2025-01-01');
        $this->staff['ended'] = $this->employee('Gita Ended', [$this->a], ['status' => 'terminated', 'ends_on' => '2026-06-30'], starts: '2025-01-01');
        $this->staff['joining'] = $this->employee('Hari Joining', [$this->a], ['status' => 'pre_joining'], starts: '2026-08-01');
        // A rehire: an ended employment, then a new active one -- the LATEST decides.
        $this->staff['rehired'] = $this->employee('Indu Rehired', [$this->a], ['status' => 'separated', 'ends_on' => '2025-12-31'], starts: '2024-01-01');
        $rehired = $this->inSchool(fn () => Employee::query()->findOrFail($this->staff['rehired']->employee_id));
        $again = $this->createEmploymentRecord($rehired, ['status' => 'active', 'starts_on' => '2026-07-10', 'ends_on' => null]);
        $this->createEmployeeAssignment($again, $this->role, ['campus_id' => $this->a->id, 'is_primary' => true, 'starts_on' => '2026-07-10', 'ends_on' => null]);

        // Manager on North; one report on North, one on South.
        $this->staff['manager'] = $this->employee('Kiran Manager', [$this->a], starts: '2025-01-01');
        $this->staff['reportA'] = $this->employee('Lata ReportA', [$this->a], starts: '2025-01-01');
        $this->staff['reportB'] = $this->employee('Mohan ReportB', [$this->b], starts: '2025-01-01');
        $managerAssignment = $this->assignmentOf('manager');
        foreach (['reportA', 'reportB'] as $key) {
            $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($this->assignmentOf($key)->id)->update(['manager_assignment_id' => $managerAssignment->id]));
        }
    }

    /** @param  list<?Campus>  $campuses  first = primary */
    private function employee(string $name, array $campuses, array $employment = [], string $starts = '2025-01-01'): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, ['full_name' => $name]);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => $starts, 'ends_on' => null, 'status' => 'active', ...$employment]);
        foreach ($campuses as $i => $campus) {
            $this->createEmployeeAssignment($record, $this->role, ['campus_id' => $campus?->id, 'is_primary' => $i === 0, 'starts_on' => $starts, 'ends_on' => $employment['ends_on'] ?? null]);
        }

        return $record;
    }

    private function assignmentOf(string $key): EmployeeAssignment
    {
        return $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $this->staff[$key]->id)->orderBy('created_at')->firstOrFail());
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function hr(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'hr_officer', $campusId);
        }

        return $user;
    }

    /** @return list<string> */
    private function names(User $user, string $query): array
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $props = $this->get('/app/hr/employees?'.$query)->assertOk()->viewData('page')['props'];

        return collect($props['employees']['data'])->pluck('display_name')->sort()->values()->all();
    }

    #[Test]
    public function the_latest_employment_state_filters_current_notice_joining_and_ended(): void
    {
        $hr = $this->hr([null]);

        $this->assertSame(['Esha Notice'], $this->names($hr, 'employment=notice_period'));
        $this->assertSame(['Hari Joining'], $this->names($hr, 'employment=pre_joining'));
        $this->assertSame(['Gita Ended'], $this->names($hr, 'employment=ended'), 'The rehired Employee\'s LATEST employment is active, so not ended.');
        $current = $this->names($hr, 'employment=current');
        $this->assertContains('Indu Rehired', $current);
        $this->assertContains('Esha Notice', $current);
        $this->assertNotContains('Gita Ended', $current);
        $this->assertNotContains('Hari Joining', $current);
    }

    #[Test]
    public function a_campus_reader_never_reaches_ended_or_unplaced_staff_through_a_filter(): void
    {
        $north = $this->hr([$this->a->id]);

        $this->assertSame([], $this->names($north, 'employment=ended'));
        $this->assertSame([], $this->names($north, 'employment=pre_joining'));
        $this->assertNotContains('Dev Wide', $this->names($north, 'employment=current'));
    }

    #[Test]
    public function several_campuses_counts_only_campuses_the_reader_may_see(): void
    {
        $this->assertSame(['Chitra Both'], $this->names($this->hr([null]), 'several_campuses=1'));
        $this->assertSame([], $this->names($this->hr([$this->a->id]), 'several_campuses=1'), 'From North alone, Chitra is on ONE visible Campus: the South placement is never revealed.');
        $this->assertSame(['Chitra Both'], $this->names($this->hr([$this->a->id, $this->b->id]), 'several_campuses=1'));
    }

    #[Test]
    public function joined_in_a_period_includes_a_rehire_and_follows_scope(): void
    {
        $hr = $this->hr([null]);

        $this->assertSame(['Bela OnlyB', 'Indu Rehired'], $this->names($hr, 'joined_from=2026-07-01&joined_to=2026-07-31'));
        $this->assertSame(['Chitra Both', 'Hari Joining'], array_values(array_diff($this->names($hr, 'joined_from=2026-06-01&joined_to=2026-08-31'), ['Bela OnlyB', 'Indu Rehired'])));
        $this->assertSame(['Indu Rehired'], $this->names($this->hr([$this->a->id]), 'joined_from=2026-07-01&joined_to=2026-07-31'), 'A North reader: only North staff.');
    }

    #[Test]
    public function reports_to_a_manager_never_crosses_a_campus_boundary(): void
    {
        $manager = $this->staff['manager']->employee_id;

        $this->assertSame(['Lata ReportA', 'Mohan ReportB'], $this->names($this->hr([null]), 'manager_id='.$manager));
        $this->assertSame(['Lata ReportA'], $this->names($this->hr([$this->a->id]), 'manager_id='.$manager));
        $this->assertSame([], $this->names($this->hr([$this->b->id]), 'manager_id='.$manager), 'From South, a line to a North manager is never revealed.');
    }

    #[Test]
    public function filters_are_validated_and_rows_stay_directory_tier(): void
    {
        $hr = $this->hr([null]);
        $this->actingAs($hr)->post("/app/schools/{$this->school->id}/activate");

        $this->get('/app/hr/employees?employment=retired')->assertSessionHasErrors('employment');
        $this->get('/app/hr/employees?joined_from=2026-07-10&joined_to=2026-07-01')->assertSessionHasErrors('joined_to');
        $this->get('/app/hr/employees?manager_id=not-a-uuid')->assertSessionHasErrors('manager_id');

        $row = $this->get('/app/hr/employees?employment=current')->assertOk()->viewData('page')['props']['employees']['data'][0];
        $this->assertSame(['employee_id', 'employee_number', 'display_name', 'position_id', 'position_name', 'department_id', 'department_name', 'campus_id', 'campus_name', 'manager_employee_id', 'manager_employee_number', 'manager_display_name'], array_keys($row), 'No status, pay, health, identity or contact field.');
    }

    #[Test]
    public function only_the_directory_capability_opens_it(): void
    {
        $outsider = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $this->actingAs($outsider)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/hr/employees?employment=ended')->assertForbidden();
    }

    #[Test]
    public function a_campus_reader_never_learns_an_out_of_scope_assignment_through_reports_to(): void
    {
        // Nila works on North AND South; only her SOUTH assignment reports to the North manager.
        $nila = $this->employee('Nila Split', [$this->a, $this->b], starts: '2025-01-01');
        $south = $this->inSchool(fn () => EmployeeAssignment::query()->where('employment_record_id', $nila->id)->where('campus_id', $this->b->id)->firstOrFail());
        $this->inSchool(fn () => EmployeeAssignment::query()->whereKey($south->id)->update(['manager_assignment_id' => $this->assignmentOf('manager')->id]));
        $manager = $this->staff['manager']->employee_id;

        $this->assertContains('Nila Split', $this->names($this->hr([null]), 'manager_id='.$manager));
        $this->assertNotContains('Nila Split', $this->names($this->hr([$this->a->id]), 'manager_id='.$manager), 'From North, her South line to the North manager is never revealed.');
    }

    #[Test]
    public function a_campus_reader_is_judged_on_the_employment_in_force_today_only(): void
    {
        // A planned re-hire: Indu is active today and has a future pre-joining record.
        $indu = $this->inSchool(fn () => Employee::query()->findOrFail($this->staff['onlyA']->employee_id));
        $this->createEmploymentRecord($indu, ['status' => 'pre_joining', 'starts_on' => '2027-01-01', 'ends_on' => null]);
        $north = $this->hr([$this->a->id]);

        $this->assertSame([], $this->names($north, 'employment=pre_joining'), 'A planned re-hire is never revealed to a Campus reader.');
        $this->assertContains('Asha OnlyA', $this->names($north, 'employment=current'));
        $this->assertContains('Asha OnlyA', $this->names($this->hr([null]), 'employment=pre_joining'), 'Whole School: the LATEST record decides.');

        // The rehired Employee's earlier employment (2024) is history.
        $this->assertSame([], $this->names($north, 'joined_from=2024-01-01&joined_to=2024-12-31'));
        $this->assertContains('Indu Rehired', $this->names($this->hr([null]), 'joined_from=2024-01-01&joined_to=2024-12-31'));
    }
}
