<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED tenant-reference proof (checkpoint brief
 * sections 27/28/75). Position/Department/Campus are resolved by
 * School-scoped CODE, case-insensitively, and a code belonging to a
 * different School (or no School at all) is indistinguishable from a
 * genuine typo -- never a cross-School existence signal.
 */
class HrEmployeeImportReferenceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_position_code_resolves_case_insensitively_within_the_same_school(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school, ['code' => 'TCH']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => 'tch',
        ]]);

        $this->assertSame('created', $result->rows[0]->status);
    }

    #[Test]
    public function a_position_code_belonging_to_a_different_school_is_rejected_as_not_found(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->fullHrActor($schoolA);
        $positionB = $this->createPosition($schoolB, ['code' => 'TCH']);

        $result = app(EmployeeImportService::class)->import($schoolA, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $positionB->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('reference_not_found', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function a_department_code_belonging_to_a_different_school_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->fullHrActor($schoolA);
        $position = $this->createPosition($schoolA);
        $departmentB = $this->createDepartment($schoolB, ['code' => 'FIN']);

        $result = app(EmployeeImportService::class)->import($schoolA, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code, 'department_code' => $departmentB->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('reference_not_found', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function a_campus_code_belonging_to_a_different_school_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->fullHrActor($schoolA);
        $position = $this->createPosition($schoolA);
        $campusB = $this->createCampus($schoolB, ['code' => 'MAIN']);

        $result = app(EmployeeImportService::class)->import($schoolA, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code, 'campus_code' => $campusB->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('reference_not_found', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function an_unknown_position_code_is_rejected_identically_to_a_cross_school_one(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => 'NOPE',
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('reference_not_found', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function an_inactive_position_is_rejected_for_a_new_assignment(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $inactivePosition->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
    }

    #[Test]
    public function an_inactive_department_is_rejected_for_a_new_assignment(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);
        $inactiveDepartment = $this->createDepartment($school, ['status' => 'inactive']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code, 'department_code' => $inactiveDepartment->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);
    }

    #[Test]
    public function no_cross_school_reference_ever_attaches_to_the_wrong_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->fullHrActor($schoolA);
        $positionB = $this->createPosition($schoolB, ['code' => 'TCH']);

        app(EmployeeImportService::class)->import($schoolA, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $positionB->code,
        ]]);

        // No Employee/Assignment must exist referencing School B's Position.
        app(TenantContext::class)->set($schoolB);
        $this->assertSame(0, EmployeeAssignment::query()->where('position_id', $positionB->id)->count());
    }
}
