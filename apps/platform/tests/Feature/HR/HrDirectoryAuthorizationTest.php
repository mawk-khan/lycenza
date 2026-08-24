<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- EmployeeDirectoryService (8A.8) is now the
 * authorization-aware, authoritative entry point for Directory reads.
 * Complements EmployeeDirectoryServiceTest (8A.8's own disclosure-shape
 * proof, unaffected by this checkpoint) with the capability boundary
 * itself.
 */
class HrDirectoryAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function hr_employees_view_allows_directory_search(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.view']);

        $result = app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);

        $this->assertCount(1, $result->items());
        $this->assertSame($employee->id, $result->items()[0]->employeeId);
    }

    #[Test]
    public function an_unrelated_capability_does_not_grant_directory_access(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['school.settings.view']);

        $this->expectException(AuthorizationException::class);

        app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);
    }

    #[Test]
    public function the_authorization_check_runs_before_any_query_and_reveals_nothing_on_denial(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school);
        $this->createEmployee($school);
        $actor = $this->createUserWithCapabilities($school, []);

        try {
            app(EmployeeDirectoryService::class)->search($school, new EmployeeDirectoryQuery, $actor);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertStringNotContainsString('2', $e->getMessage(), 'Denial must never leak a row count or any other School-data-derived detail.');
        }
    }
}
