<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\Exceptions\DepartmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\DepartmentHierarchyCycleException;
use App\Domain\HR\Application\Exceptions\DepartmentParentMismatchException;
use App\Domain\HR\Infrastructure\Department;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.3: proves the HR Department organizational reference entity
 * -- UUIDv7 identity, School ownership, School-scoped code uniqueness,
 * the active/inactive lifecycle, Campus scope, and hierarchy (parent/
 * child, self-parent rejection, indirect-cycle rejection, cross-School
 * rejection). See tests/Feature/Postgres/HrRawIsolationTest for the
 * independent raw-SQL/RLS proof.
 */
class DepartmentTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Schema / model -------------------------------------------------

    #[Test]
    public function department_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($department->id));
    }

    #[Test]
    public function department_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $department->fresh()->school_id);
    }

    #[Test]
    public function code_is_normalized_to_uppercase(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school, ['code' => 'admin']);

        $this->assertSame('ADMIN', $department->code);
    }

    #[Test]
    public function the_same_code_is_valid_in_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $departmentA = $this->createDepartment($schoolA, ['code' => 'FIN']);
        $departmentB = $this->createDepartment($schoolB, ['code' => 'FIN']);

        app(TenantContext::class)->set($schoolA);
        $this->assertSame('FIN', $departmentA->fresh()->code);

        app(TenantContext::class)->set($schoolB);
        $this->assertSame('FIN', $departmentB->fresh()->code);
    }

    #[Test]
    public function a_duplicate_code_within_the_same_school_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createDepartment($school, ['code' => 'HR']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($school): void {
            Department::query()->create([
                'school_id' => $school->id,
                'name' => 'Human Resources Again',
                'code' => 'HR',
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function a_department_defaults_to_active_status(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        $this->assertSame('active', $department->status);
        $this->assertTrue($department->isActive());
    }

    #[Test]
    public function the_inactive_factory_state_produces_an_inactive_department(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school, ['status' => 'inactive']);

        app(TenantContext::class)->set($school);
        $this->assertFalse($department->fresh()->isActive());
    }

    // --- Service ----------------------------------------------------------

    #[Test]
    public function service_create_produces_an_active_department(): void
    {
        $school = $this->createSchool();

        $department = app(DepartmentService::class)->create($school, ['name' => 'Finance', 'code' => 'fin'], actor: $this->fullHrActor($school));

        $this->assertSame('FIN', $department->code);
        $this->assertSame('active', $department->status);
        $this->assertSame($school->id, $department->school_id);
    }

    #[Test]
    public function service_create_rejects_a_campus_from_a_different_school(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $foreignCampus = $this->createCampus($otherSchool);

        $this->expectException(DepartmentCampusMismatchException::class);

        app(DepartmentService::class)->create($school, ['name' => 'Facilities', 'code' => 'FAC'], actor: $this->fullHrActor($school), campus: $foreignCampus);
    }

    #[Test]
    public function service_create_rejects_a_parent_from_a_different_school(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $foreignParent = $this->createDepartment($otherSchool);

        $this->expectException(DepartmentParentMismatchException::class);

        app(DepartmentService::class)->create($school, ['name' => 'Payroll', 'code' => 'PAY'], actor: $this->fullHrActor($school), parent: $foreignParent);
    }

    #[Test]
    public function service_update_ignores_school_id_campus_id_parent_id_and_status(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $department = $this->createDepartment($schoolA, ['name' => 'Original Name']);

        $updated = app(DepartmentService::class)->update($department, [
            'name' => 'Updated Name',
            'school_id' => $schoolB->id,
            'campus_id' => (string) Str::orderedUuid(),
            'parent_department_id' => (string) Str::orderedUuid(),
            'status' => 'inactive',
        ], actor: $this->fullHrActor($schoolA));

        $this->assertSame('Updated Name', $updated->name);
        $this->assertSame($schoolA->id, $updated->school_id);
        $this->assertNull($updated->campus_id);
        $this->assertNull($updated->parent_department_id);
        $this->assertSame('active', $updated->status, 'update() must never silently change status -- archive()/reactivate() own that.');
    }

    #[Test]
    public function archive_then_reactivate_round_trips_correctly(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);
        $service = app(DepartmentService::class);
        $actor = $this->fullHrActor($school);

        $archived = $service->archive($department, $actor);
        $this->assertSame('inactive', $archived->status);

        $reactivated = $service->reactivate($archived, $actor);
        $this->assertSame('active', $reactivated->status);
    }

    // --- Hierarchy ----------------------------------------------------------

    #[Test]
    public function a_valid_parent_child_relationship_works(): void
    {
        $school = $this->createSchool();
        $parent = $this->createDepartment($school, ['name' => 'Administration']);
        $child = $this->createDepartment($school, ['name' => 'Accounts', 'parent_department_id' => $parent->id]);

        app(TenantContext::class)->set($school);

        $this->assertSame($parent->id, $child->fresh()->parent_department_id);
        $this->assertTrue($parent->children()->get()->contains('id', $child->id));
    }

    #[Test]
    public function self_parenting_is_rejected_by_the_database_check_constraint(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($department): void {
            Department::query()->where('id', $department->id)->update(['parent_department_id' => $department->id]);
        });
    }

    #[Test]
    public function reparenting_a_department_under_itself_is_rejected_by_the_service(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        $this->expectException(DepartmentHierarchyCycleException::class);

        app(DepartmentService::class)->reparent($department, $department, $this->fullHrActor($school));
    }

    #[Test]
    public function an_indirect_cycle_is_rejected_by_the_service(): void
    {
        $school = $this->createSchool();
        $a = $this->createDepartment($school, ['name' => 'A']);
        $b = $this->createDepartment($school, ['name' => 'B', 'parent_department_id' => $a->id]);
        $c = $this->createDepartment($school, ['name' => 'C', 'parent_department_id' => $b->id]);

        $this->expectException(DepartmentHierarchyCycleException::class);

        // A -> B -> C already; reparenting A under C would close the loop.
        app(DepartmentService::class)->reparent($a, $c, $this->fullHrActor($school));
    }

    #[Test]
    public function reparenting_to_null_makes_a_department_top_level_again(): void
    {
        $school = $this->createSchool();
        $parent = $this->createDepartment($school);
        $child = $this->createDepartment($school, ['parent_department_id' => $parent->id]);

        $updated = app(DepartmentService::class)->reparent($child, null, $this->fullHrActor($school));

        $this->assertNull($updated->parent_department_id);
    }

    #[Test]
    public function a_cross_school_parent_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $parentB = $this->createDepartment($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $parentB): void {
            Department::query()->create([
                'school_id' => $schoolA->id,
                'name' => 'Rogue Child',
                'code' => 'ROGUE',
                'parent_department_id' => $parentB->id,
                'status' => 'active',
            ]);
        });
    }

    // --- Campus scope -------------------------------------------------------

    #[Test]
    public function a_school_wide_department_is_valid_with_a_null_campus(): void
    {
        $school = $this->createSchool();
        $department = $this->createDepartment($school);

        $this->assertNull($department->campus_id);
    }

    #[Test]
    public function a_department_scoped_to_a_same_school_campus_is_valid(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $department = $this->createDepartment($school, ['campus_id' => $campus->id]);

        app(TenantContext::class)->set($school);

        $this->assertSame($campus->id, $department->fresh()->campus_id);
        $this->assertSame($campus->id, $department->fresh()->campus->id);
    }

    #[Test]
    public function a_cross_school_campus_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $campusB): void {
            Department::query()->create([
                'school_id' => $schoolA->id,
                'name' => 'Rogue Campus Department',
                'code' => 'ROGUE2',
                'campus_id' => $campusB->id,
                'status' => 'active',
            ]);
        });
    }

    // --- Tenant isolation (Eloquent layer) -----------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_department(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createDepartment($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, Department::query()->count());
    }

    #[Test]
    public function school_b_cannot_update_school_as_department(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $departmentA = $this->createDepartment($schoolA, ['name' => 'Original Name']);

        app(TenantContext::class)->set($schoolB);

        $affected = Department::query()->where('id', $departmentA->id)->update(['name' => 'Hacked Name']);

        $this->assertSame(0, $affected);
    }
}
