<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmployeeCategoryNotFoundException;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
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
 * Phase 8A closure correction (item 4) -- proves the EmployeeCategory
 * reference entity, built to reconcile the original 8A.0 plan's
 * requirement rather than leaving it permanently deferred. Same shape
 * as PositionTest -- UUIDv7 identity, School ownership, School-scoped
 * code uniqueness, active/inactive lifecycle, no authorization-table
 * coupling -- plus the `employment_records.employee_category_id`
 * composite-FK attachment point this correction chose.
 */
class EmployeeCategoryTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function category_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($category->id));
    }

    #[Test]
    public function category_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $category->fresh()->school_id);
    }

    #[Test]
    public function code_is_normalized_to_uppercase(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school, ['code' => 'teach']);

        $this->assertSame('TEACH', $category->code);
    }

    #[Test]
    public function the_same_code_is_valid_in_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $categoryA = $this->createEmployeeCategory($schoolA, ['code' => 'CONTRACT']);
        $categoryB = $this->createEmployeeCategory($schoolB, ['code' => 'CONTRACT']);

        app(TenantContext::class)->set($schoolA);
        $this->assertSame('CONTRACT', $categoryA->fresh()->code);

        app(TenantContext::class)->set($schoolB);
        $this->assertSame('CONTRACT', $categoryB->fresh()->code);
    }

    #[Test]
    public function a_duplicate_code_within_the_same_school_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createEmployeeCategory($school, ['code' => 'VISIT']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($school): void {
            EmployeeCategory::query()->create([
                'school_id' => $school->id,
                'name' => 'Visiting Again',
                'code' => 'VISIT',
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function a_category_defaults_to_active_status(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school);

        $this->assertSame('active', $category->status);
        $this->assertTrue($category->isActive());
    }

    #[Test]
    public function the_inactive_factory_state_produces_an_inactive_category(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school, ['status' => 'inactive']);

        app(TenantContext::class)->set($school);
        $this->assertFalse($category->fresh()->isActive());
    }

    #[Test]
    public function service_create_produces_an_active_category(): void
    {
        $school = $this->createSchool();

        $category = app(EmployeeCategoryService::class)->create($school, ['name' => 'Teaching', 'code' => 'teach'], $this->fullHrActor($school));

        $this->assertSame('TEACH', $category->code);
        $this->assertSame('active', $category->status);
    }

    #[Test]
    public function service_update_ignores_school_id_and_status(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $category = $this->createEmployeeCategory($schoolA, ['name' => 'Original Name']);

        $updated = app(EmployeeCategoryService::class)->update($category, [
            'name' => 'Updated Name',
            'school_id' => $schoolB->id,
            'status' => 'inactive',
        ], $this->fullHrActor($schoolA));

        $this->assertSame('Updated Name', $updated->name);
        $this->assertSame($schoolA->id, $updated->school_id);
        $this->assertSame('active', $updated->status);
    }

    #[Test]
    public function archive_then_reactivate_round_trips_correctly(): void
    {
        $school = $this->createSchool();
        $category = $this->createEmployeeCategory($school);
        $service = app(EmployeeCategoryService::class);
        $actor = $this->fullHrActor($school);

        $archived = $service->archive($category, $actor);
        $this->assertSame('inactive', $archived->status);

        $reactivated = $service->reactivate($archived, $actor);
        $this->assertSame('active', $reactivated->status);
    }

    #[Test]
    public function school_a_cannot_see_school_bs_category(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createEmployeeCategory($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, EmployeeCategory::query()->count());
    }

    #[Test]
    public function school_b_cannot_update_school_as_category(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $categoryA = $this->createEmployeeCategory($schoolA, ['name' => 'Original Name']);

        app(TenantContext::class)->set($schoolB);

        $affected = EmployeeCategory::query()->where('id', $categoryA->id)->update(['name' => 'Hacked Name']);

        $this->assertSame(0, $affected);
    }

    #[Test]
    public function the_full_category_lifecycle_never_touches_an_authorization_table(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        app(TenantContext::class)->set($school);
        $rolesBefore = Role::query()->count();
        $membershipRoleAssignmentsBefore = MembershipRoleAssignment::query()->count();
        $platformRoleAssignmentsBefore = PlatformRoleAssignment::query()->count();

        $service = app(EmployeeCategoryService::class);
        $category = $service->create($school, ['name' => 'Contract', 'code' => 'CONTRACT'], $actor);
        $category = $service->update($category, ['name' => 'Contract Staff'], $actor);
        $category = $service->archive($category, $actor);
        $service->reactivate($category, $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($rolesBefore, Role::query()->count());
        $this->assertSame($membershipRoleAssignmentsBefore, MembershipRoleAssignment::query()->count());
        $this->assertSame($platformRoleAssignmentsBefore, PlatformRoleAssignment::query()->count());
    }

    // --- Attachment to EmploymentRecord (this correction's chosen FK) ---

    #[Test]
    public function an_employment_record_may_reference_an_employee_category_in_the_same_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $category = $this->createEmployeeCategory($school, ['code' => 'TEACH']);

        $employment = app(EmploymentService::class)->create($employee, [
            'employment_type' => 'full_time',
            'starts_on' => '2026-06-01',
            'employee_category_id' => $category->id,
        ], $this->fullHrActor($school));

        $this->assertSame($category->id, $employment->employee_category_id);

        app(TenantContext::class)->set($school);
        $this->assertSame($category->id, $employment->fresh()->category->id);
    }

    #[Test]
    public function a_cross_school_employee_category_id_is_rejected_with_no_oracle(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employee = $this->createEmployee($schoolA);
        $foreignCategory = $this->createEmployeeCategory($schoolB);

        $this->expectException(EmployeeCategoryNotFoundException::class);

        app(EmploymentService::class)->create($employee, [
            'employment_type' => 'full_time',
            'starts_on' => '2026-06-01',
            'employee_category_id' => $foreignCategory->id,
        ], $this->fullHrActor($schoolA));
    }

    #[Test]
    public function a_nonexistent_employee_category_id_is_rejected_with_the_same_exception_as_cross_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->expectException(EmployeeCategoryNotFoundException::class);

        app(EmploymentService::class)->create($employee, [
            'employment_type' => 'full_time',
            'starts_on' => '2026-06-01',
            'employee_category_id' => (string) Str::orderedUuid(),
        ], $this->fullHrActor($school));
    }

    #[Test]
    public function omitting_employee_category_id_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $employment = app(EmploymentService::class)->create($employee, [
            'employment_type' => 'full_time',
            'starts_on' => '2026-06-01',
        ], $this->fullHrActor($school));

        $this->assertNull($employment->employee_category_id);
    }

    #[Test]
    public function a_cross_school_employee_category_id_written_directly_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employee = $this->createEmployee($schoolA);
        $foreignCategory = $this->createEmployeeCategory($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $employee, $foreignCategory): void {
            EmploymentRecord::query()->create([
                'school_id' => $schoolA->id,
                'employee_id' => $employee->id,
                'employee_category_id' => $foreignCategory->id,
                'employment_type' => 'full_time',
                'starts_on' => '2026-06-01',
                'status' => 'active',
            ]);
        });
    }
}
