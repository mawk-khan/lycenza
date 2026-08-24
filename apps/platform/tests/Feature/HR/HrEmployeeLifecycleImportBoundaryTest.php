<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED boundary-protection regression (checkpoint
 * brief sections 38/68): 8A.12's `EmployeeImportService` deliberately
 * does NOT perform rehire automatically, and 8A.13 must not quietly
 * change that. This file adds no new import logic and does not modify
 * EmployeeImportService -- it only proves the boundary still holds now
 * that a real `EmployeeLifecycleService::rehire()` exists elsewhere in
 * the codebase.
 */
class HrEmployeeLifecycleImportBoundaryTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function importing_a_row_for_a_separated_employees_linked_user_remains_a_duplicate_not_an_automatic_rehire(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school);
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmployeeLifecycleService::class)->separate($employment, '2022-12-31', $actor);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Someone Rehired',
            'user_id' => $linkedUser->id,
            'employment_type' => 'permanent',
            'employment_starts_on' => '2026-06-01',
        ]]);

        $this->assertSame('duplicate_exact', $result->rows[0]->status, 'Import must report this as a duplicate for human review, never silently create a new EmploymentRecord under the existing Employee.');

        app(TenantContext::class)->set($school);
        $this->assertSame(1, Employee::query()->where('school_id', $school->id)->count());
        $this->assertSame(1, $employee->fresh()->employmentRecords()->count(), 'Import must never have created a second (rehire) EmploymentRecord -- only the original, already-ended one exists.');
    }
}
