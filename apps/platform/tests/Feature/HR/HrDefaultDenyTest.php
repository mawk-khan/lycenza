<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\PositionService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- REQUIRED default-deny proof (checkpoint brief section
 * 28/54): no capability -> denied, membership alone -> denied, for
 * every authoritative HR entry point. Every case here uses a real,
 * active School membership with zero capabilities -- proving that
 * "the actor is a real, known School member" is never itself
 * sufficient.
 */
class HrDefaultDenyTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_ordinary_member_cannot_read_the_directory(): void
    {
        $school = $this->createSchool();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $ordinaryMember);
    }

    #[Test]
    public function an_ordinary_member_cannot_build_the_profile_workspace(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $ordinaryMember);
    }

    #[Test]
    public function an_ordinary_member_cannot_create_an_employee(): void
    {
        $school = $this->createSchool();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeService::class)->create($school, ['full_name' => 'Rogue Hire'], $ordinaryMember);
    }

    #[Test]
    public function an_ordinary_member_cannot_create_a_department(): void
    {
        $school = $this->createSchool();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(DepartmentService::class)->create($school, ['name' => 'Rogue Dept', 'code' => 'ROGUE'], $ordinaryMember);
    }

    #[Test]
    public function an_ordinary_member_cannot_create_a_position(): void
    {
        $school = $this->createSchool();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(PositionService::class)->create($school, ['name' => 'Rogue Role', 'code' => 'ROGUE'], $ordinaryMember);
    }

    #[Test]
    public function a_user_with_no_membership_at_all_cannot_read_the_directory(): void
    {
        $school = $this->createSchool();
        $unrelatedUser = $this->createUser();

        $this->expectException(AuthorizationException::class);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $unrelatedUser);
    }

    #[Test]
    public function directory_capability_alone_does_not_grant_profile_access(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $directoryOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $directoryOnlyActor);
    }

    #[Test]
    public function profile_capability_alone_does_not_grant_directory_access(): void
    {
        $school = $this->createSchool();
        $profileOnlyActor = $this->createUserWithCapabilities($school, ['hr.employees.personal.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $profileOnlyActor);
    }
}
