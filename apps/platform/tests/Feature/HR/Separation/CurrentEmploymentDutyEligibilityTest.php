<?php

namespace Tests\Feature\HR\Separation;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Transport\Application\Exceptions\DriverNotEligibleException;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Domain\Visitor\Application\Exceptions\HostEmployeeNotEligibleException;
use App\Domain\Visitor\Application\VisitorVisitService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.12 (X-1, HR.0A): a NEW operational duty -- a driver's route, a visit's
 * host, a timetable teacher -- needs the person's planned or current
 * employment today (HR's EmploymentCoverage), never the Employee identity
 * flag alone. A separated Employee keeps an active identity, so before HR.12
 * they could still be newly assigned. History is never touched.
 */
class CurrentEmploymentDutyEligibilityTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_separated_employee_is_never_assigned_a_new_driver_route_but_the_old_one_stays(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$admin, $school] = $this->createSchoolAdmin();
        $driver = $this->createEmployedEmployee($school);
        $route = $this->createTransportRoute($school);
        $other = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $assign = fn ($r) => app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($r, $vehicle, $driver, $admin));

        $history = $assign($route);
        $this->endEmployment($school, $driver->id);

        $this->assertThrows(fn () => $assign($other), DriverNotEligibleException::class);
        $this->assertSame('active', app(TenantContext::class)->withSchool($school, fn () => DB::table('transport_route_assignments')->where('id', $history->id)->value('status')), 'history is never changed by a separation');
    }

    #[Test]
    public function an_identity_only_employee_without_any_employment_is_not_an_eligible_driver(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $driver = $this->createEmployee($school);

        $this->assertThrows(fn () => app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($this->createTransportRoute($school), $this->createTransportVehicle($school), $driver, $admin)), DriverNotEligibleException::class);
    }

    #[Test]
    public function a_separated_employee_never_hosts_a_new_visit_and_a_visit_without_a_host_is_unaffected(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $host = $this->createEmployedEmployee($school);
        $checkIn = fn ($h) => app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($this->createVisitor($school), $campus, $h, 'Meeting', null, $admin));

        $this->assertNotNull($checkIn($host)->id);
        $this->endEmployment($school, $host->id);

        $this->assertThrows(fn () => $checkIn($host), HostEmployeeNotEligibleException::class);
        $this->assertNotNull($checkIn(null)->id);
    }

    private function endEmployment($school, string $employeeId): void
    {
        $hr = $this->fullHrActor($school);
        app(TenantContext::class)->withSchool($school, function () use ($employeeId, $hr): void {
            $employment = EmploymentRecord::query()->where('employee_id', $employeeId)->firstOrFail();
            app(EmploymentService::class)->end($employment, '2026-06-14', $hr);
        });
    }
}
