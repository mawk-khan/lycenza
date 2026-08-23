<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeNumberAllocator;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\HrEmployeeNumberCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.1: proves App\Domain\HR\Application\EmployeeService's full
 * creation pathway -- number format, sequential allocation, School
 * scoping, uniqueness enforcement, and the gap-free-under-rollback
 * transactional guarantee. Real concurrent-process proof is a separate
 * file, EmployeeNumberConcurrencyTest.
 */
class EmployeeNumberAllocationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function first_employee_for_a_school_receives_number_000001(): void
    {
        $school = $this->createSchool();

        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Asha Verma']);

        $this->assertSame('EMP-000001', $employee->employee_number);
    }

    #[Test]
    public function subsequent_employees_receive_the_next_sequential_number(): void
    {
        $school = $this->createSchool();
        $service = app(EmployeeService::class);

        $first = $service->create($school, ['full_name' => 'Asha Verma']);
        $second = $service->create($school, ['full_name' => 'Rahul Nair']);
        $third = $service->create($school, ['full_name' => 'Priya Iyer']);

        $this->assertSame('EMP-000001', $first->employee_number);
        $this->assertSame('EMP-000002', $second->employee_number);
        $this->assertSame('EMP-000003', $third->employee_number);
    }

    #[Test]
    public function independent_schools_maintain_independent_counters(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $service = app(EmployeeService::class);

        $employeeA1 = $service->create($schoolA, ['full_name' => 'Asha Verma']);
        $employeeB1 = $service->create($schoolB, ['full_name' => 'Rahul Nair']);
        $employeeA2 = $service->create($schoolA, ['full_name' => 'Priya Iyer']);

        $this->assertSame('EMP-000001', $employeeA1->employee_number);
        $this->assertSame('EMP-000001', $employeeB1->employee_number, 'School B\'s first employee must also be EMP-000001 -- numbering is School-scoped, not global.');
        $this->assertSame('EMP-000002', $employeeA2->employee_number);
    }

    #[Test]
    public function duplicate_employee_number_within_the_same_school_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['employee_number' => 'EMP-000001']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        // Wrapped in its own DB::transaction() so the unique-constraint
        // violation aborts only a nested SAVEPOINT, not the outer
        // per-test transaction RefreshDatabase already has open -- a
        // raw failed statement on that outer transaction would poison
        // it for the rest of the test (and its teardown). Same
        // savepoint mechanism a_rolled_back_employee_creation_does_not_consume_a_number
        // relies on, applied here for the same reason.
        DB::transaction(function () use ($school): void {
            Employee::query()->create([
                'school_id' => $school->id,
                'employee_number' => 'EMP-000001',
                'full_name' => 'Duplicate Attempt',
                'record_status' => 'active',
            ]);
        });
    }

    #[Test]
    public function the_same_literal_employee_number_is_valid_in_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $employeeA = $this->createEmployee($schoolA, ['employee_number' => 'EMP-000001']);
        $employeeB = $this->createEmployee($schoolB, ['employee_number' => 'EMP-000001']);

        app(TenantContext::class)->set($schoolA);
        $this->assertSame('EMP-000001', $employeeA->fresh()->employee_number);

        app(TenantContext::class)->set($schoolB);
        $this->assertSame('EMP-000001', $employeeB->fresh()->employee_number);
    }

    #[Test]
    public function a_rolled_back_employee_creation_does_not_consume_a_number(): void
    {
        $school = $this->createSchool();
        $service = app(EmployeeService::class);

        $first = $service->create($school, ['full_name' => 'Asha Verma']);
        $this->assertSame('EMP-000001', $first->employee_number);

        // Reproduces EmployeeService::create()'s real transaction shape: the
        // allocator's own DB::transaction() call becomes a SAVEPOINT nested
        // inside this single, real, top-level transaction (Laravel nests
        // transactions via SAVEPOINT when already inside one) -- so when
        // THIS outer transaction rolls back, the savepoint's changes roll
        // back with it. The increment is never independently committed.
        try {
            app(TenantContext::class)->withSchool($school, function () use ($school) {
                DB::transaction(function () use ($school) {
                    app(EmployeeNumberAllocator::class)->allocate($school);

                    throw new RuntimeException('Simulated failure after allocation, before commit.');
                });
            });
        } catch (RuntimeException) {
            // expected
        }

        $counter = app(TenantContext::class)->withSchool(
            $school,
            fn () => HrEmployeeNumberCounter::query()->where('school_id', $school->id)->first(),
        );
        $this->assertSame(2, $counter->next_value, 'The failed attempt\'s increment must be fully rolled back -- the counter must read exactly as it did after the first successful creation, not 3.');

        $second = $service->create($school, ['full_name' => 'Rahul Nair']);
        $this->assertSame('EMP-000002', $second->employee_number, 'No gap: the failed attempt above never actually consumed a number once its enclosing transaction rolled back, because the increment and the (attempted) Employee insert share one atomic unit of work.');
    }
}
