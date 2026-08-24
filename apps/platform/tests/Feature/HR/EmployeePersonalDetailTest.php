<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.2: proves the Employee's 1:1 Restricted-tier personal-
 * details extension -- UUIDv7 identity, School/Employee ownership, the
 * 1:1 invariant, and EmployeePersonalDetailService's create-if-absent/
 * update-otherwise semantics.
 */
class EmployeePersonalDetailTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function personal_detail_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $detail = $this->createEmployeePersonalDetail($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($detail->id));
    }

    #[Test]
    public function personal_detail_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $detail = $this->createEmployeePersonalDetail($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $detail->fresh()->school_id);
    }

    #[Test]
    public function personal_detail_belongs_to_its_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $detail = $this->createEmployeePersonalDetail($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($employee->id, $detail->fresh()->employee_id);
        $this->assertSame($employee->id, $detail->fresh()->employee->id);
    }

    #[Test]
    public function nullable_optional_fields_behave_correctly(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $detail = $this->createEmployeePersonalDetail($employee, [
            'date_of_birth' => null,
            'nationality' => null,
            'marital_status' => null,
            'preferred_language' => null,
            'personal_email' => null,
            'personal_phone' => null,
            'alternate_phone' => null,
        ]);

        app(TenantContext::class)->set($school);
        $fresh = $detail->fresh();

        $this->assertNull($fresh->date_of_birth);
        $this->assertNull($fresh->nationality);
        $this->assertNull($fresh->personal_email);
    }

    #[Test]
    public function setting_details_for_the_first_time_creates_the_row(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $detail = app(EmployeePersonalDetailService::class)->setDetails($employee, [
            'personal_email' => 'asha.personal@example.com',
            'personal_phone' => '9876543210',
        ], $this->fullHrActor($school));

        $this->assertSame('asha.personal@example.com', $detail->personal_email);
        $this->assertSame($employee->id, $detail->employee_id);
    }

    #[Test]
    public function setting_details_a_second_time_updates_the_existing_row_not_a_new_one(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $service = app(EmployeePersonalDetailService::class);
        $actor = $this->fullHrActor($school);

        $first = $service->setDetails($employee, ['personal_email' => 'first@example.com'], $actor);
        $second = $service->setDetails($employee, ['personal_email' => 'second@example.com'], $actor);

        $this->assertSame($first->id, $second->id, 'Updating details must reuse the same 1:1 row, never create a second one.');

        app(TenantContext::class)->set($school);
        $this->assertSame(1, EmployeePersonalDetail::query()->where('employee_id', $employee->id)->count());
        $this->assertSame('second@example.com', $second->fresh()->personal_email);
    }

    #[Test]
    public function a_second_personal_detail_row_for_the_same_employee_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeePersonalDetail($employee);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($employee): void {
            EmployeePersonalDetail::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'personal_email' => 'duplicate@example.com',
            ]);
        });
    }

    #[Test]
    public function a_school_a_employee_id_combined_with_school_b_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);

        app(TenantContext::class)->set($schoolB);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employeeA, $schoolB): void {
            EmployeePersonalDetail::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'personal_email' => 'cross-school@example.com',
            ]);
        });
    }
}
