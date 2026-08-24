<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B Dependency Reconciliation Gate §21/§22/§23 -- minimal proof
 * that Phase 1B (academic placement) and Phase 5B (communication
 * identity/reachability) coexist without FK/RLS conflict, and that
 * the two remain the architecturally SEPARATE concerns both lines
 * always documented: an academic Enrollment is Student PLACEMENT, an
 * AccountLink is authenticated ACCOUNT identity. Neither implies the
 * other. This is NOT Phase 5B.3 audience-resolution behavior -- no
 * Grade/Section audience resolver exists yet.
 */
class EnrollmentAccountLinkCoexistenceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function enrollments(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    #[Test]
    public function a_student_can_have_both_an_active_enrollment_and_an_account_link_with_no_conflict(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $membership = $this->createMembership($this->createUser(), $school);

        $enrollment = $this->enrollments()->enroll($student, $section, 'R-1', now()->toDateString(), $admin);
        $link = $this->links()->linkStudent($school, $student, $membership, $admin);

        $this->assertTrue($enrollment->isActive());
        $this->assertSame('active', $link->status);

        // Both relationships independently resolvable from the same Student.
        app(TenantContext::class)->withSchool($school, function () use ($student, $section) {
            $fresh = $student->fresh(['enrollments']);
            $this->assertCount(1, $fresh->enrollments);
            $this->assertSame($section->id, $fresh->enrollments->first()->section_id);
        });
        $this->assertSame($student->id, $this->links()->activeLinkForStudent($student)->student_id);
    }

    #[Test]
    public function an_enrolled_student_with_no_account_link_remains_in_app_unreachable(): void
    {
        // §22: academic placement alone must NEVER grant IN_APP
        // reachability -- account identity is a separate, explicit
        // concern (Phase 5B.2).
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $this->enrollments()->enroll($student, $section, 'R-2', now()->toDateString(), $admin);

        $this->assertNull($this->links()->activeLinkForStudent($student));

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Student,
            domainAudienceMemberIds: [$student->id], channels: [CommunicationChannel::InApp],
        );
        $published = app(AnnouncementService::class)->publish($announcement, $admin);

        $deliveryCount = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()
                ->where('message_id', $published->message_id)->pluck('id'))
            ->count());

        $this->assertSame(0, $deliveryCount, 'An active academic Enrollment must never substitute for an explicit account link.');
    }

    #[Test]
    public function a_school_cannot_reach_across_the_tenant_boundary_through_the_combined_enrollment_and_link_relationships(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $campusB = $this->createCampus($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $sectionB = $this->createSection($yearB, $campusB, $gradeB);
        $membershipB = $this->createMembership($this->createUser(), $schoolB);

        $this->enrollments()->enroll($studentB, $sectionB, 'R-3', now()->toDateString(), $adminB);
        $this->links()->linkStudent($schoolB, $studentB, $membershipB, $adminB);

        // Querying by School B's real Student id, but under School A's
        // own ambient tenant context -- RLS must hide both rows
        // regardless of how the two domains are combined.
        [$crossSchoolEnrollment, $crossSchoolLink] = app(TenantContext::class)->withSchool($schoolA, fn () => [
            StudentEnrollment::query()->where('student_id', $studentB->id)->first(),
            StudentGuardianAccountLink::query()->where('student_id', $studentB->id)->first(),
        ]);

        $this->assertNull($crossSchoolEnrollment, 'RLS must hide School Bs enrollment under School As context.');
        $this->assertNull($crossSchoolLink, 'RLS must hide School Bs account link under School As context.');
    }
}
