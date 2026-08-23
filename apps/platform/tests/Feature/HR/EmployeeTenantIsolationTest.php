<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Support\Tenancy\SchoolScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.1: proves Eloquent-layer (Layer 1) tenant isolation for
 * Employee, mirroring tests/Feature/Tenancy/SchoolScopeTest's pattern
 * applied to this specific table. See tests/Feature/Postgres/
 * HrRawIsolationTest for the independent raw-SQL/RLS (Layer 2) proof.
 */
class EmployeeTenantIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function school_a_cannot_see_school_bs_employee(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->assertNull(Employee::query()->find($employeeB->id));
        $this->assertSame(0, Employee::query()->count());
    }

    #[Test]
    public function school_a_sees_only_its_own_employees(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $this->createEmployee($schoolB);

        app(TenantContext::class)->set($schoolA);

        $results = Employee::query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($employeeA->id, $results->first()->id);
    }

    #[Test]
    public function school_b_cannot_update_school_as_employee(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA, ['full_name' => 'Original Name']);

        app(TenantContext::class)->set($schoolB);

        $affected = Employee::query()->where('id', $employeeA->id)->update(['full_name' => 'Hacked Name']);

        $this->assertSame(0, $affected, 'School B must not be able to affect any rows when targeting School A\'s Employee by id.');

        app(TenantContext::class)->set($schoolA);
        $this->assertSame('Original Name', $employeeA->fresh()->full_name);
    }

    #[Test]
    public function school_b_cannot_archive_school_as_employee(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);

        app(TenantContext::class)->set($schoolB);

        $affected = Employee::query()->where('id', $employeeA->id)->update(['record_status' => 'archived']);

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function no_tenant_context_fails_closed(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school);

        app(TenantContext::class)->clear();

        $this->assertSame(0, Employee::query()->count());
    }

    #[Test]
    public function creating_an_employee_without_tenant_context_throws(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(TenantContextRequiredException::class);

        Employee::query()->create(['employee_number' => 'EMP-000001', 'full_name' => 'Orphan']);
    }

    #[Test]
    public function removing_the_eloquent_scope_still_cannot_leak_another_schools_employee_because_rls_catches_it(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createEmployee($schoolA);
        $this->createEmployee($schoolB);

        app(TenantContext::class)->set($schoolA);

        $unscopedResults = Employee::query()->withoutGlobalScope(SchoolScope::class)->get();

        $this->assertCount(1, $unscopedResults, 'RLS should have hidden School B\'s Employee even though the Eloquent scope was removed.');
    }
}
