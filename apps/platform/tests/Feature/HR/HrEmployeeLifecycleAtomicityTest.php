<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\AssignmentInactivePositionException;
use App\Domain\HR\Application\Exceptions\EmploymentAlreadyEndedException;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED atomicity proof (checkpoint brief sections
 * 20/34/66): a rehire whose optional initial Assignment fails must roll
 * back the just-created EmploymentRecord too -- never a partial
 * "rehired but unassigned" result when an Assignment was actually
 * requested. A rejected separation attempt must leave zero trace.
 */
class HrEmployeeLifecycleAtomicityTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_failed_initial_assignment_rolls_back_the_new_rehire_employment_entirely(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        try {
            app(EmployeeLifecycleService::class)->rehire(
                $employee,
                ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
                $actor,
                assignmentAttributes: ['starts_on' => '2026-06-01'],
                position: $inactivePosition,
            );
            $this->fail('Expected AssignmentInactivePositionException.');
        } catch (AssignmentInactivePositionException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame(1, EmploymentRecord::query()->where('employee_id', $employee->id)->count(), 'Only the original (already-ended) Employment may exist -- the rehire attempt must be fully rolled back.');
        $this->assertSame(0, EmployeeAssignment::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function a_failed_rehire_leaves_no_committed_employment_created_audit_event(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        app(TenantContext::class)->set($school);
        $createdEventsBefore = SchoolAuditEvent::query()->where('event_type', 'hr.employment.created')->count();

        try {
            app(EmployeeLifecycleService::class)->rehire(
                $employee,
                ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
                $actor,
                assignmentAttributes: ['starts_on' => '2026-06-01'],
                position: $inactivePosition,
            );
        } catch (AssignmentInactivePositionException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame($createdEventsBefore, SchoolAuditEvent::query()->where('event_type', 'hr.employment.created')->count(), 'The rolled-back EmploymentRecord must not leave a committed audit event behind.');
    }

    #[Test]
    public function a_rejected_repeated_separation_leaves_zero_trace(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        app(EmployeeLifecycleService::class)->separate($employment, '2025-01-31', $actor);

        app(TenantContext::class)->set($school);
        $endedEventsBefore = SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count();

        try {
            app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);
            $this->fail('Expected EmploymentAlreadyEndedException.');
        } catch (EmploymentAlreadyEndedException) {
            // expected
        }

        app(TenantContext::class)->set($school);
        $this->assertSame('2025-01-31', $employment->fresh()->ends_on->toDateString(), 'The original end date must never be rewritten.');
        $this->assertSame($endedEventsBefore, SchoolAuditEvent::query()->where('event_type', 'hr.employment.ended')->count(), 'No second, misleading hr.employment.ended event may be committed.');
    }
}
