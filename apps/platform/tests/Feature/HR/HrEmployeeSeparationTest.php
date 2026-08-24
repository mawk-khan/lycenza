<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED separation-operation proof for
 * App\Domain\HR\Application\EmployeeLifecycleService::separate()
 * (checkpoint brief sections 13/14/15/19/20/21/22): authorization,
 * effective-date policy (including the future-dating restriction this
 * checkpoint deliberately adds ON TOP of the lower-level
 * `EmploymentService::end()`), explicit target validation, and
 * repeated-separation safety. Uses a fixed clock (`Carbon::setTestNow()`)
 * throughout, per the checkpoint's own explicit instruction against
 * off-by-one lifecycle tests using the machine's real date.
 */
class HrEmployeeSeparationTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_authorized_actor_can_separate_an_employment(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $separated = app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        $this->assertSame('2026-06-15', $separated->ends_on->toDateString());
        $this->assertSame('separated', $separated->status);
    }

    #[Test]
    public function an_ordinary_member_is_denied(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $hrActor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $hrActor);
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $ordinaryMember);
    }

    #[Test]
    public function an_actor_authorized_in_school_a_cannot_separate_school_bs_employment(): void
    {
        Carbon::setTestNow('2026-06-15');
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $hrActorB = $this->fullHrActor($schoolB);
        $employmentB = app(EmploymentService::class)->create($employeeB, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $hrActorB);
        $actorA = $this->fullHrActor($schoolA);

        $this->expectException(AuthorizationException::class);

        app(EmployeeLifecycleService::class)->separate($employmentB, '2026-06-15', $actorA);
    }

    #[Test]
    public function a_future_dated_separation_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $this->expectException(InvalidEmploymentEffectiveDateException::class);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-07-01', $actor);
    }

    #[Test]
    public function a_separation_effective_exactly_today_is_accepted(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $separated = app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        $this->assertSame('2026-06-15', $separated->ends_on->toDateString());
    }

    #[Test]
    public function a_separation_effective_in_the_past_is_accepted(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $separated = app(EmployeeLifecycleService::class)->separate($employment, '2026-01-31', $actor);

        $this->assertSame('2026-01-31', $separated->ends_on->toDateString());
    }

    #[Test]
    public function separating_an_already_separated_employment_through_the_lifecycle_service_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmployeeLifecycleService::class)->separate($employment, '2026-01-31', $actor);

        $this->expectException(EmploymentAlreadyEndedException::class);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);
    }

    #[Test]
    public function separating_the_employees_current_employment_never_touches_a_separate_historical_employment(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');
        $secondEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2023-06-01'], $actor);

        app(EmployeeLifecycleService::class)->separate($secondEmployment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame('2022-12-31', $firstEmployment->fresh()->ends_on->toDateString());
        $this->assertSame('separated', $firstEmployment->fresh()->status);
        $this->assertSame('2026-06-15', $secondEmployment->fresh()->ends_on->toDateString());
    }

    #[Test]
    public function separation_never_changes_the_employees_identity_or_number(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $originalNumber = $employee->employee_number;

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $fresh = $employee->fresh();
        $this->assertSame($employee->id, $fresh->id);
        $this->assertSame($originalNumber, $fresh->employee_number);
        $this->assertSame('active', $fresh->record_status);
    }
}
