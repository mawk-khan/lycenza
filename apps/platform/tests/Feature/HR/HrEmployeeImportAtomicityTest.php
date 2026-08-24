<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED row-atomicity proof (checkpoint brief
 * sections 9/64/65/79): one database transaction per row. A row that
 * fails partway through leaves NO Employee, NO PersonalDetail, NO
 * EmploymentRecord, NO Assignment, and NO committed audit event --
 * never a half-created Employee. A bad row must never roll back other,
 * independent, valid rows in the same batch (per-row atomicity, not
 * whole-batch atomicity).
 */
class HrEmployeeImportAtomicityTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_assignment_failure_rolls_back_the_entire_new_employee_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com',
            'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $inactivePosition->code,
        ]]);

        $this->assertSame('failed', $result->rows[0]->status);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Employee::query()->where('school_id', $school->id)->count(), 'No Employee.');
        $this->assertSame(0, EmployeePersonalDetail::query()->where('school_id', $school->id)->count(), 'No PersonalDetail.');
        $this->assertSame(0, EmploymentRecord::query()->where('school_id', $school->id)->count(), 'No EmploymentRecord.');
        $this->assertSame(0, EmployeeAssignment::query()->where('school_id', $school->id)->count(), 'No Assignment.');
    }

    #[Test]
    public function a_rolled_back_row_leaves_no_committed_audit_event(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);

        app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Rollback Sentinel Name', 'employment_type' => 'permanent',
            'employment_starts_on' => '2026-01-01', 'position_code' => $inactivePosition->code,
        ]]);

        app(TenantContext::class)->set($school);
        $this->assertSame(
            0,
            SchoolAuditEvent::query()->where('event_type', 'employee.created')->count(),
            'No employee.created audit event may survive a rolled-back row -- AuditRecorder writes inside the same transaction as the domain mutation.',
        );
    }

    #[Test]
    public function an_overlapping_employment_conflict_rolls_back_the_new_employee(): void
    {
        // A row whose Employee creation succeeds but whose Employment
        // creation fails (Employment overlap is impossible on a BRAND
        // NEW Employee within one row, so this specifically exercises
        // the "second EmploymentRecord in the same row" shape via a
        // duplicate-linked-User row that reaches the transaction due to
        // a race is covered by the concurrency test; here we simulate a
        // genuine mid-row domain failure using an inactive Department
        // instead, which is reachable from a single row).
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);
        $inactiveDepartment = $this->createDepartment($school, ['status' => 'inactive']);

        app(EmployeeImportService::class)->import($school, $actor, [[
            'full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
            'position_code' => $position->code, 'department_code' => $inactiveDepartment->code,
        ]]);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, Employee::query()->where('school_id', $school->id)->count());
        $this->assertSame(0, EmploymentRecord::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function a_failed_row_does_not_roll_back_other_valid_rows_in_the_same_batch(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Valid Employee One'],
            ['full_name' => 'Invalid Employee', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01', 'position_code' => 'NOPE'],
            ['full_name' => 'Valid Employee Two'],
        ]);

        $this->assertSame('created', $result->rows[0]->status);
        $this->assertSame('failed', $result->rows[1]->status);
        $this->assertSame('created', $result->rows[2]->status);
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->failed);

        app(TenantContext::class)->set($school);
        $this->assertSame(2, Employee::query()->where('school_id', $school->id)->count(), 'Both valid rows must have committed despite the middle row failing.');
    }
}
