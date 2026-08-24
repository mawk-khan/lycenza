<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.1: proves the exact User<->Employee linkage invariant
 * decided in docs/modules/HR.md's "Database constraints" table --
 * `unique(school_id, user_id)` (never a global `unique(user_id)`,
 * since App\Models\User is a central/global identity that may
 * legitimately correspond to Employee records at more than one
 * School), plus the application-level "the User must have a real
 * SchoolMembership at this School" safety check
 * (UnrelatedUserLinkageException).
 */
class EmployeeUserLinkageTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function linking_a_user_with_an_active_membership_at_the_school_succeeds(): void
    {
        [$user, $school] = $this->createSchoolAdmin();

        $employee = app(EmployeeService::class)->create($school, [
            'full_name' => 'Asha Verma',
            'user_id' => $user->id,
        ], $user);

        $this->assertSame($user->id, $employee->user_id);
    }

    #[Test]
    public function linking_a_user_with_no_membership_at_the_school_is_rejected(): void
    {
        $school = $this->createSchool();
        $unrelatedUser = $this->createUser();

        $this->expectException(UnrelatedUserLinkageException::class);

        app(EmployeeService::class)->create($school, [
            'full_name' => 'Asha Verma',
            'user_id' => $unrelatedUser->id,
        ], $this->fullHrActor($school));
    }

    #[Test]
    public function linking_a_user_whose_only_membership_is_at_a_different_school_is_rejected(): void
    {
        [$user, $otherSchool] = $this->createSchoolAdmin();
        $targetSchool = $this->createSchool();

        $this->expectException(UnrelatedUserLinkageException::class);

        app(EmployeeService::class)->create($targetSchool, [
            'full_name' => 'Asha Verma',
            'user_id' => $user->id,
        ], $this->fullHrActor($targetSchool));

        $this->assertNotSame($otherSchool->id, $targetSchool->id);
    }

    #[Test]
    public function a_user_may_be_linked_as_an_employee_at_two_different_schools(): void
    {
        $user = $this->createUser();
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        $employeeA = app(EmployeeService::class)->create($schoolA, ['full_name' => 'Asha Verma', 'user_id' => $user->id], $this->fullHrActor($schoolA));
        $employeeB = app(EmployeeService::class)->create($schoolB, ['full_name' => 'Asha Verma', 'user_id' => $user->id], $this->fullHrActor($schoolB));

        $this->assertSame($user->id, $employeeA->user_id);
        $this->assertSame($user->id, $employeeB->user_id);
    }

    #[Test]
    public function the_same_user_cannot_be_linked_to_two_employee_records_in_the_same_school(): void
    {
        [$user, $school] = $this->createSchoolAdmin();
        $this->createEmployee($school, ['user_id' => $user->id]);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        // Wrapped in its own DB::transaction() so the unique-constraint
        // violation aborts only a nested SAVEPOINT, not the outer
        // per-test transaction RefreshDatabase already has open --
        // matches EmployeeNumberAllocationTest's identical pattern.
        DB::transaction(function () use ($school, $user): void {
            Employee::query()->create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'employee_number' => 'EMP-999999',
                'full_name' => 'Second Attempt',
                'record_status' => 'active',
            ]);
        });
    }

    #[Test]
    public function multiple_employees_in_the_same_school_may_all_have_no_linked_user(): void
    {
        $school = $this->createSchool();

        $first = $this->createEmployee($school, ['user_id' => null, 'employee_number' => 'EMP-000001']);
        $second = $this->createEmployee($school, ['user_id' => null, 'employee_number' => 'EMP-000002']);

        app(TenantContext::class)->set($school);
        $this->assertNull($first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
    }

    #[Test]
    public function a_users_membership_being_later_suspended_does_not_retroactively_break_an_established_linkage(): void
    {
        [$user, $school] = $this->createSchoolAdmin();
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Asha Verma', 'user_id' => $user->id], $user);

        SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $school->id)
            ->update(['status' => 'suspended']);

        app(TenantContext::class)->set($school);

        $this->assertSame($user->id, $employee->fresh()->user_id, 'Employee<->User linkage is a distinct fact from current membership status (docs/modules/HR.md 2.6) -- it must not be silently unwound by an unrelated membership change.');
    }
}
