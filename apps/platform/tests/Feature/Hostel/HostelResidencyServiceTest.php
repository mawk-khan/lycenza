<?php

namespace Tests\Feature\Hostel;

use App\Domain\Hostel\Application\Exceptions\BedAlreadyOccupiedException;
use App\Domain\Hostel\Application\Exceptions\BedNotAvailableException;
use App\Domain\Hostel\Application\Exceptions\ResidencyAlreadyEndedException;
use App\Domain\Hostel\Application\Exceptions\StudentAlreadyResidentException;
use App\Domain\Hostel\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10D -- Hostel residency lifecycle. Every test exercises the
 * real App\Domain\Hostel\Application\HostelResidencyService, never a
 * raw model write, mirroring VisitorVisitServiceTest.php's exact
 * pattern.
 */
class HostelResidencyServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function assign_creates_an_active_residency_and_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));

        $this->assertSame('active', $assignment->status);
        $this->assertSame($bed->id, $assignment->hostel_bed_id);
        $this->assertNull($assignment->ends_on);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'hostel.residency.assigned')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event, 'A hostel.residency.assigned audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    #[Test]
    public function assign_refuses_a_student_that_already_has_an_active_residency(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);

        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bedOne, $admin));

        $this->expectException(StudentAlreadyResidentException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bedTwo, $admin));
    }

    #[Test]
    public function assign_refuses_a_bed_that_already_has_an_active_resident(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);

        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($studentOne, $bed, $admin));

        $this->expectException(BedAlreadyOccupiedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($studentTwo, $bed, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_student(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'inactive']);

        $this->expectException(StudentNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_bed(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room, ['status' => 'inactive']);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->expectException(BedNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));
    }

    #[Test]
    public function end_marks_the_residency_ended_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));

        $ended = app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->end($assignment, $admin));

        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ends_on);
        $this->assertEquals($assignment->starts_on, $ended->starts_on, 'end() must never rewrite the original starts_on.');

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'hostel.residency.ended')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function end_cannot_be_performed_twice(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));

        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->end($assignment, $admin));

        $this->expectException(ResidencyAlreadyEndedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->end($assignment, $admin));
    }

    #[Test]
    public function after_ending_a_residency_the_student_can_be_assigned_to_a_new_bed(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $service = app(HostelResidencyService::class);

        $first = app(TenantContext::class)->withSchool($school, fn () => $service->assign($student, $bedOne, $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->end($first, $admin));

        $second = app(TenantContext::class)->withSchool($school, fn () => $service->assign($student, $bedTwo, $admin));

        $this->assertSame('active', $second->status);
        $this->assertSame($bedTwo->id, $second->hostel_bed_id);
        $this->assertNotSame($first->id, $second->id, 'A new assignment must create a fresh row, never reuse/mutate the ended one.');
    }

    #[Test]
    public function historical_assignments_remain_valid_after_the_bed_is_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->assign($student, $bed, $admin));
        app(TenantContext::class)->withSchool($school, fn () => app(HostelResidencyService::class)->end($assignment, $admin));

        $bed->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $assignment->fresh());
        $this->assertSame('ended', $fresh->status);
        $this->assertSame($bed->id, $fresh->hostel_bed_id);
    }
}
