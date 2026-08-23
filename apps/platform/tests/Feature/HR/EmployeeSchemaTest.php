<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\Exceptions\EmployeeNumberIsImmutableException;
use App\Domain\HR\Infrastructure\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.1: proves the Employee core aggregate's own shape and
 * invariants -- UUIDv7 identity, School ownership, optional User
 * linkage, and employee_number immutability. Cross-tenant behavior is
 * covered separately in EmployeeTenantIsolationTest and
 * tests/Feature/Postgres/HrRawIsolationTest.
 */
class EmployeeSchemaTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function employee_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($employee->id));
    }

    #[Test]
    public function employee_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $employee->fresh()->school_id);
        $this->assertSame($school->id, $employee->fresh()->school->id);
    }

    #[Test]
    public function employee_may_exist_with_no_linked_user_account(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['user_id' => null]);

        app(TenantContext::class)->set($school);

        $this->assertNull($employee->fresh()->user_id);
        $this->assertNull($employee->fresh()->user);
    }

    #[Test]
    public function employee_can_be_linked_to_a_valid_user(): void
    {
        [$user, $school] = $this->createSchoolAdmin();
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);

        app(TenantContext::class)->set($school);

        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame($user->id, $employee->fresh()->user->id);
    }

    #[Test]
    public function employee_number_is_stable_across_unrelated_updates(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['employee_number' => 'EMP-000042']);

        app(TenantContext::class)->set($school);
        $employee->update(['full_name' => 'Updated Name']);

        $this->assertSame('EMP-000042', $employee->fresh()->employee_number);
    }

    #[Test]
    public function directly_changing_employee_number_on_an_existing_row_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['employee_number' => 'EMP-000001']);

        app(TenantContext::class)->set($school);

        $this->expectException(EmployeeNumberIsImmutableException::class);

        $employee->update(['employee_number' => 'EMP-999999']);
    }

    #[Test]
    public function record_status_defaults_to_active(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $this->assertSame('active', $employee->record_status);
        $this->assertTrue($employee->isActive());
    }

    #[Test]
    public function archived_factory_state_produces_an_archived_record_status(): void
    {
        $school = $this->createSchool();

        $archivedEmployee = app(TenantContext::class)->withSchool(
            $school,
            fn () => Employee::factory()->archived()->for($school, 'school')->create(),
        );

        $this->assertSame('archived', $archivedEmployee->record_status);
        $this->assertFalse($archivedEmployee->isActive());
    }

    /**
     * Phase 8A.3/8A.4/8A.5 scope-creep guard: organizational placement
     * (department/position/campus), reporting hierarchy (manager --
     * which lives on `employee_assignments.manager_assignment_id`, an
     * Assignment-to-Assignment relationship, never an Employee-to-
     * Employee or Employee-to-Assignment one), and employment
     * lifecycle/history (employment_id, joined_at, left_at) belong to
     * EmploymentRecord/EmployeeAssignment, never to employees itself
     * (docs/modules/HR.md's Employee != Employment != Assignment
     * principle, extended to Employee != Reporting Line). This fails
     * loudly if a future checkpoint accidentally adds one of these
     * columns directly to employees instead of through
     * EmploymentRecord/EmployeeAssignment.
     */
    #[Test]
    public function employees_table_has_no_organizational_placement_columns(): void
    {
        $forbiddenColumns = [
            'department_id', 'position_id', 'campus_id', 'manager_id',
            'employment_id', 'employment_record_id', 'joined_at', 'left_at',
            'manager_employee_id', 'manager_assignment_id', 'supervisor_id',
        ];

        foreach ($forbiddenColumns as $column) {
            $this->assertFalse(
                Schema::hasColumn('employees', $column),
                "employees.{$column} must not exist -- organizational placement and employment lifecycle belong to EmploymentRecord/EmployeeAssignment, not Employee.",
            );
        }
    }

    /**
     * Phase 8A.6 scope-creep guard: `employees` must not accumulate
     * denormalized summary columns derived from its Qualification/
     * Experience/Certification children (docs/modules/HR.md's
     * "Employee schema remains lean" principle) -- those values are
     * always computed from the child tables, never stored here.
     */
    #[Test]
    public function employees_table_has_no_denormalized_professional_record_summary_columns(): void
    {
        $forbiddenColumns = [
            'highest_qualification', 'years_of_experience',
            'certification_count', 'certification_expiry',
        ];

        foreach ($forbiddenColumns as $column) {
            $this->assertFalse(
                Schema::hasColumn('employees', $column),
                "employees.{$column} must not exist -- derived from EmployeeQualification/EmployeeExperience/EmployeeCertification, never stored on Employee.",
            );
        }
    }

    /**
     * Phase 8A.6 scope-creep guard: no Qualification/Experience/
     * Certification table may carry a document/file/evidence-storage
     * column -- document evidence is explicitly deferred to Phase
     * 8A.7's `employee_documents` (ADR 0028).
     */
    #[Test]
    public function professional_record_tables_have_no_document_or_file_storage_columns(): void
    {
        $forbiddenColumns = [
            'document_id', 'file_path', 'storage_key', 'storage_disk',
            'storage_path', 'bucket', 'blob', 'signed_url',
            'certificate_file', 'attachment_path',
        ];

        foreach (['employee_qualifications', 'employee_experience_records', 'employee_certifications'] as $table) {
            foreach ($forbiddenColumns as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} must not exist -- document evidence is deferred to Phase 8A.7's employee_documents.",
                );
            }
        }
    }
}
