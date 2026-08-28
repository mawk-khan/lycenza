<?php

namespace Tests\Feature\Visitor;

use App\Domain\Visitor\Application\Exceptions\HostEmployeeNotEligibleException;
use App\Domain\Visitor\Application\Exceptions\VisitAlreadyCheckedOutException;
use App\Domain\Visitor\Application\Exceptions\VisitorAlreadyCheckedInException;
use App\Domain\Visitor\Application\Exceptions\VisitorNotEligibleException;
use App\Domain\Visitor\Application\VisitorVisitService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10C -- Visitor check-in/check-out lifecycle. Every test
 * exercises the real
 * App\Domain\Visitor\Application\VisitorVisitService, never a raw
 * model write, mirroring
 * TransportStudentAssignmentServiceTest.php's exact pattern.
 */
class VisitorVisitServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function check_in_creates_an_active_visit_and_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $host = $this->createEmployee($school);
        $visitor = $this->createVisitor($school);

        $visit = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, $host, 'Parent meeting', 'GP-001', $admin));

        $this->assertSame('checked_in', $visit->status);
        $this->assertSame($campus->id, $visit->campus_id);
        $this->assertSame($host->id, $visit->host_employee_id);
        $this->assertSame('GP-001', $visit->gate_pass_number);
        $this->assertNull($visit->checked_out_at);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'visitor.visit.checked_in')
            ->where('subject_id', $visit->id)
            ->first());
        $this->assertNotNull($event, 'A visitor.visit.checked_in audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertArrayNotHasKey('purpose', $event->metadata, 'Purpose text must never be copied into audit metadata.');
    }

    #[Test]
    public function check_in_works_without_a_host(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);

        $visit = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'General enquiry', null, $admin));

        $this->assertSame('checked_in', $visit->status);
        $this->assertNull($visit->host_employee_id);
    }

    #[Test]
    public function check_in_refuses_a_visitor_that_already_has_an_active_visit(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campusOne = $this->createCampus($school);
        $campusTwo = $this->createCampus($school);
        $visitor = $this->createVisitor($school);

        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campusOne, null, 'First visit', null, $admin));

        $this->expectException(VisitorAlreadyCheckedInException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campusTwo, null, 'Second visit', null, $admin));
    }

    #[Test]
    public function check_in_refuses_an_inactive_visitor(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school, ['status' => 'inactive']);

        $this->expectException(VisitorNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'Delivery', null, $admin));
    }

    #[Test]
    public function check_in_refuses_an_inactive_host_employee(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $host = $this->createEmployee($school, ['record_status' => 'archived']);

        $this->expectException(HostEmployeeNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, $host, 'Meeting', null, $admin));
    }

    #[Test]
    public function check_out_marks_the_visit_checked_out_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $visit = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'Meeting', null, $admin));

        $checkedOut = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkOut($visit, $admin));

        $this->assertSame('checked_out', $checkedOut->status);
        $this->assertNotNull($checkedOut->checked_out_at);
        $this->assertEquals($visit->checked_in_at, $checkedOut->checked_in_at, 'check-out must never rewrite the original checked_in_at.');

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'visitor.visit.checked_out')
            ->where('subject_id', $visit->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function check_out_cannot_be_performed_twice(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $visit = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'Meeting', null, $admin));

        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkOut($visit, $admin));

        $this->expectException(VisitAlreadyCheckedOutException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkOut($visit, $admin));
    }

    #[Test]
    public function after_checking_out_the_visitor_can_be_checked_in_again(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $service = app(VisitorVisitService::class);

        $first = app(TenantContext::class)->withSchool($school, fn () => $service->checkIn($visitor, $campus, null, 'First visit', null, $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->checkOut($first, $admin));

        $second = app(TenantContext::class)->withSchool($school, fn () => $service->checkIn($visitor, $campus, null, 'Second visit', null, $admin));

        $this->assertSame('checked_in', $second->status);
        $this->assertNotSame($first->id, $second->id, 'A new check-in must create a fresh row, never reuse/mutate the checked-out one.');
    }

    #[Test]
    public function historical_visits_remain_valid_after_the_campus_is_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $visit = app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkIn($visitor, $campus, null, 'Meeting', null, $admin));
        app(TenantContext::class)->withSchool($school, fn () => app(VisitorVisitService::class)->checkOut($visit, $admin));

        $campus->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $visit->fresh());
        $this->assertSame('checked_out', $fresh->status);
        $this->assertSame($campus->id, $fresh->campus_id);
    }
}
