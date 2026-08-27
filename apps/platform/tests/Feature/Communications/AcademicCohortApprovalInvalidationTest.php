<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Audience\AcademicCohortSelection;
use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAcademicCohortType;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.3 §31/§32 -- CommunicationApprovalFingerprint's
 * academicCohort key covers only the cohort DEFINITION (audience type,
 * academic_year_id, grade_level_id/section_id, recipient_kind), never
 * resolved Student/Guardian ids. Changing the DEFINITION must
 * invalidate a prior approval (mirrors
 * StudentGuardianAudienceServiceTest's domain-audience-id invalidation
 * tests); changing only who happens to be currently enrolled -- an
 * enrollment membership change, entirely outside this table -- must
 * never invalidate it (mirrors StudentGuardianAccountLinkInAppTest's
 * "linking/unlinking never invalidates" precedent).
 */
class AcademicCohortApprovalInvalidationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function approvals(): CommunicationApprovalService
    {
        return app(CommunicationApprovalService::class);
    }

    #[Test]
    public function changing_the_selected_grade_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $gradeA = $this->createGradeLevel($school);
        $gradeB = $this->createGradeLevel($school);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $gradeA->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        $this->announcements()->updateDraft(
            $announcement, $admin,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $gradeB->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function changing_the_recipient_kind_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        $this->announcements()->updateDraft(
            $announcement, $admin,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Guardian,
            ),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function a_student_joining_the_approved_grade_after_approval_does_not_invalidate_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        // Purely an enrollment-membership change -- no
        // communication_announcement_academic_cohorts row is touched.
        $newStudent = $this->createStudent($school);
        $this->createStudentEnrollment($newStudent, $section, ['status' => 'active']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);
    }

    #[Test]
    public function a_student_leaving_the_approved_grade_after_approval_does_not_invalidate_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        app(TenantContext::class)->withSchool($school, function () use ($enrollment): void {
            $enrollment->update(['status' => 'withdrawn']);
        });

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);
    }

    /**
     * Phase 5C.1 closure-blocker fix: CommunicationApprovalFingerprint
     * previously omitted `subject_offering` from its academic-cohort
     * detection (only `grade`/`section` were checked), so an approved
     * SubjectOffering-audience announcement's fingerprint always
     * computed `academicCohort => null` regardless of which
     * SubjectOffering was actually targeted -- switching the targeted
     * SubjectOffering after approval silently left the approval intact.
     * Mirrors changing_the_selected_grade_after_approval_invalidates_it
     * exactly, for the third cohort type.
     */
    #[Test]
    public function changing_the_selected_subject_offering_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subjectA = $this->createSubject($school, ['code' => 'MATHA']);
        $subjectB = $this->createSubject($school, ['code' => 'MATHB']);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $subjectA, ['is_required' => true]);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $subjectB, ['is_required' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, CommunicationAcademicCohortRecipientKind::Student, $offeringA->id,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        $this->announcements()->updateDraft(
            $announcement, $admin,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, CommunicationAcademicCohortRecipientKind::Student, $offeringB->id,
            ),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function changing_the_recipient_kind_for_subject_offering_after_approval_invalidates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, CommunicationAcademicCohortRecipientKind::Student, $offering->id,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        $this->announcements()->updateDraft(
            $announcement, $admin,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, CommunicationAcademicCohortRecipientKind::Guardian, $offering->id,
            ),
        );

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('draft', $fresh->status);
    }

    #[Test]
    public function a_student_joining_the_approved_subject_offering_after_approval_does_not_invalidate_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $approver = $this->createUser();
        $approverMembership = $this->createMembership($approver, $school);
        $this->assignSchoolRole($approverMembership, 'principal');
        $this->createApprovalPolicy($school, ['require_required_communication_approval' => true]);

        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school, ['code' => 'ELEC']);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            channels: [CommunicationChannel::InApp],
            requirement: CommunicationRequirement::Required,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, CommunicationAcademicCohortRecipientKind::Student, $offering->id,
            ),
        );
        $request = $this->approvals()->submit($announcement, $admin);
        $this->approvals()->approve($request, $approver);

        // Purely a roster-membership change -- no
        // communication_announcement_academic_cohorts row is touched.
        $newStudent = $this->createStudent($school);
        $this->createStudentSubjectEnrollment($newStudent, $offering, ['status' => 'active']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $announcement->fresh());
        $this->assertSame('approved', $fresh->status);
    }
}
