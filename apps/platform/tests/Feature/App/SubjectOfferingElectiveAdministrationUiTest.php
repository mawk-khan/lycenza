<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1H.1: the Inertia surface for SubjectOffering roster / elective
 * administration (App\Http\Controllers\App\SubjectOfferingController +
 * App\Http\Controllers\App\StudentSubjectEnrollmentController). Domain
 * validation is already covered at depth by StudentSubjectEnrollmentServiceTest
 * and SubjectOfferingRosterReadServiceTest; this file proves the
 * Inertia-specific integration: required-vs-elective action visibility,
 * the two narrow read adapters (eligible-students/transfer-targets),
 * canonical-service-only mutation, capability gating (no students.*
 * requirement), cross-School isolation, and history distinct from
 * current-roster authority.
 */
class SubjectOfferingElectiveAdministrationUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function grantCapabilities(User $user, School $school, array $capabilities): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_subj_admin_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
    }

    /**
     * `student_subject_enrollments` is RLS-protected -- a raw
     * `assertDatabaseHas`/`assertDatabaseCount` outside a School context
     * finds nothing (fail-closed, CLAUDE.md rule 5), exactly like
     * EnrollmentRolloverSubjectMappingUiTest's `freshPlan()` helper.
     */
    private function freshSubjectSubjectEnrollment(School $school, string $id): StudentSubjectEnrollment
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->findOrFail($id));
    }

    private function subjectSubjectEnrollmentCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->count());
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, section: Section, subject: Subject}
     */
    private function baseContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $subject = $this->createSubject($school);

        return compact('school', 'campus', 'year', 'grade', 'section', 'subject');
    }

    // ==================================================================
    // Required Offering: read-only
    // ==================================================================

    #[Test]
    public function a_required_offering_shows_an_implied_roster_with_no_history_and_no_management_surface(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudentEnrollment($student, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view']);
        $this->activate($user, $school);

        $this->get("/app/subject-offerings/{$offering->id}")->assertInertia(fn ($page) => $page
            ->component('App/SubjectOfferings/Show')
            ->where('offering.isRequired', true)
            ->has('roster', 1)
            ->where('roster.0.id', $student->id)
            ->where('history', null));
    }

    #[Test]
    public function enrolling_into_a_required_offering_is_defensively_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true]);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-offerings/{$offering->id}/enrollments", [
            'student_id' => $student->id,
            'starts_on' => '2026-06-01',
        ])->assertSessionHasErrors('student_id');

        $this->assertSame(0, $this->subjectSubjectEnrollmentCount($school));
    }

    // ==================================================================
    // Elective Offering: roster + enroll
    // ==================================================================

    #[Test]
    public function an_authorized_manager_can_enroll_a_compatible_student_through_the_canonical_service(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-2001']);
        $this->createStudentEnrollment($student, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-offerings/{$offering->id}/enrollments", [
            'student_id' => $student->id,
            'starts_on' => '2026-06-01',
        ])->assertRedirect("/app/subject-offerings/{$offering->id}");

        $created = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_id', $student->id)->where('subject_offering_id', $offering->id)->firstOrFail());
        $this->assertSame('active', $created->status);
    }

    #[Test]
    public function view_only_cannot_enroll_but_can_still_view_the_roster(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view']);
        $this->activate($user, $school);

        $this->get("/app/subject-offerings/{$offering->id}")->assertOk();
        $this->post("/app/subject-offerings/{$offering->id}/enrollments", [
            'student_id' => $student->id,
            'starts_on' => '2026-06-01',
        ])->assertForbidden();
    }

    #[Test]
    public function neither_view_nor_manage_requires_a_students_capability(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        // Deliberately grants ONLY the academics.subjects.* pair -- no
        // students.view/students.manage/enrollments.manage anywhere
        // (docs/students/PHASE-1H-0-...ARCHITECTURE.md §13).
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->get("/app/subject-offerings/{$offering->id}")->assertOk();
        $this->post("/app/subject-offerings/{$offering->id}/enrollments", [
            'student_id' => $student->id,
            'starts_on' => '2026-06-01',
        ])->assertRedirect();

        // The roster row must render WITHOUT a Student-detail link since
        // this user lacks students.view (§O/canViewStudentDetail).
        $this->get("/app/subject-offerings/{$offering->id}")->assertInertia(fn ($page) => $page
            ->where('canViewStudentDetail', false));
    }

    // ==================================================================
    // Withdraw / Cancel / Transfer -- canonical service only
    // ==================================================================

    #[Test]
    public function withdraw_transitions_the_row_and_preserves_it_for_history(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $sse = $this->createStudentSubjectEnrollment($student, $offering, [
            'student_enrollment_id' => $enrollment->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-enrollments/{$sse->id}/withdraw", ['ends_on' => '2026-06-01'])
            ->assertRedirect("/app/subject-offerings/{$offering->id}");

        $this->assertSame('withdrawn', $this->freshSubjectSubjectEnrollment($school, $sse->id)->status);
        $this->assertSame(1, $this->subjectSubjectEnrollmentCount($school));
    }

    #[Test]
    public function cancel_transitions_the_row_without_deleting_it(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $sse = $this->createStudentSubjectEnrollment($student, $offering, [
            'student_enrollment_id' => $enrollment->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-enrollments/{$sse->id}/cancel", ['ends_on' => '2026-06-01'])
            ->assertRedirect("/app/subject-offerings/{$offering->id}");

        $this->assertSame('cancelled', $this->freshSubjectSubjectEnrollment($school, $sse->id)->status);
    }

    #[Test]
    public function transfer_is_one_atomic_command_never_a_sequential_withdraw_then_enroll(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section] = $this->baseContext();
        $frenchSubject = $this->createSubject($school, ['code' => 'FR']);
        $spanishSubject = $this->createSubject($school, ['code' => 'SP']);
        $source = $this->createSubjectOffering($year, $campus, $grade, $frenchSubject, ['is_required' => false]);
        $target = $this->createSubjectOffering($year, $campus, $grade, $spanishSubject, ['is_required' => false]);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $sse = $this->createStudentSubjectEnrollment($student, $source, [
            'student_enrollment_id' => $enrollment->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-enrollments/{$sse->id}/transfer", [
            'target_subject_offering_id' => $target->id,
            'effective_date' => '2026-06-01',
        ])->assertRedirect("/app/subject-offerings/{$source->id}");

        $this->assertSame('transferred', $this->freshSubjectSubjectEnrollment($school, $sse->id)->status);
        $targetRow = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_id', $student->id)->where('subject_offering_id', $target->id)->firstOrFail());
        $this->assertSame('active', $targetRow->status);
        $this->assertSame(2, $this->subjectSubjectEnrollmentCount($school));
    }

    #[Test]
    public function transfer_to_an_offering_already_occupying_the_same_elective_group_is_rejected_and_source_stays_active(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section] = $this->baseContext();
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $subjectA = $this->createSubject($school, ['code' => 'A1']);
        $subjectB = $this->createSubject($school, ['code' => 'B1']);
        $subjectC = $this->createSubject($school, ['code' => 'C1']);
        $source = $this->createSubjectOffering($year, $campus, $grade, $subjectA, ['is_required' => false]);
        $occupiedTarget = $this->createSubjectOffering($year, $campus, $grade, $subjectB, ['is_required' => false, 'elective_group_id' => $group->id]);
        $intendedTarget = $this->createSubjectOffering($year, $campus, $grade, $subjectC, ['is_required' => false, 'elective_group_id' => $group->id]);

        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $sse = $this->createStudentSubjectEnrollment($student, $source, [
            'student_enrollment_id' => $enrollment->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);
        // Occupies the group via the OTHER Offering already.
        $this->createStudentSubjectEnrollment($student, $occupiedTarget, [
            'student_enrollment_id' => $enrollment->id, 'elective_group_id' => $group->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $this->post("/app/subject-enrollments/{$sse->id}/transfer", [
            'target_subject_offering_id' => $intendedTarget->id,
            'effective_date' => '2026-06-01',
        ])->assertSessionHasErrors('target_subject_offering_id');

        $this->assertSame('active', $this->freshSubjectSubjectEnrollment($school, $sse->id)->status);
    }

    // ==================================================================
    // Eligible-students adapter (root task §8/§54)
    // ==================================================================

    #[Test]
    public function the_eligible_students_adapter_excludes_already_enrolled_and_flags_group_conflicts_without_a_students_capability(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section] = $this->baseContext();
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $subjectA = $this->createSubject($school, ['code' => 'A2']);
        $subjectB = $this->createSubject($school, ['code' => 'B2']);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subjectA, ['is_required' => false, 'elective_group_id' => $group->id]);
        $otherGroupOffering = $this->createSubjectOffering($year, $campus, $grade, $subjectB, ['is_required' => false, 'elective_group_id' => $group->id]);

        $alreadyEnrolled = $this->createStudent($school, ['student_number' => 'S-3001']);
        $alreadyEnrolledPlacement = $this->createStudentEnrollment($alreadyEnrolled, $section);
        $this->createStudentSubjectEnrollment($alreadyEnrolled, $offering, [
            'student_enrollment_id' => $alreadyEnrolledPlacement->id, 'elective_group_id' => $group->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        $groupConflictStudent = $this->createStudent($school, ['student_number' => 'S-3002']);
        $groupConflictPlacement = $this->createStudentEnrollment($groupConflictStudent, $section);
        $this->createStudentSubjectEnrollment($groupConflictStudent, $otherGroupOffering, [
            'student_enrollment_id' => $groupConflictPlacement->id, 'elective_group_id' => $group->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        $freshCandidate = $this->createStudent($school, ['student_number' => 'S-3003']);
        $this->createStudentEnrollment($freshCandidate, $section);

        [$user] = $this->createSchoolAdmin('school_admin');
        // No students.view/students.manage granted -- proves the adapter
        // never requires it (root task §54).
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $response = $this->getJson("/app/subject-offerings/{$offering->id}/eligible-students")->assertOk();
        $ids = collect($response->json('data'))->pluck('student.id')->all();

        $this->assertNotContains($alreadyEnrolled->id, $ids, 'Already-active Student must not appear as a candidate.');
        $this->assertContains($groupConflictStudent->id, $ids, 'Group-conflict Student is still a valid candidate -- flagged, not excluded.');
        $this->assertContains($freshCandidate->id, $ids);

        $conflictRow = collect($response->json('data'))->firstWhere('student.id', $groupConflictStudent->id);
        $this->assertTrue($conflictRow['hasCurrentGroupConflict']);
        $freshRow = collect($response->json('data'))->firstWhere('student.id', $freshCandidate->id);
        $this->assertFalse($freshRow['hasCurrentGroupConflict']);
    }

    // ==================================================================
    // Transfer-targets adapter (root task §31/§32)
    // ==================================================================

    #[Test]
    public function the_transfer_targets_adapter_excludes_the_source_offering_but_includes_a_same_group_target(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section] = $this->baseContext();
        $group = $this->createElectiveGroup($year, $campus, $grade);
        $subjectA = $this->createSubject($school, ['code' => 'A3']);
        $subjectB = $this->createSubject($school, ['code' => 'B3']);
        $subjectC = $this->createSubject($school, ['code' => 'C3']);
        $source = $this->createSubjectOffering($year, $campus, $grade, $subjectA, ['is_required' => false, 'elective_group_id' => $group->id]);
        $sameGroupTarget = $this->createSubjectOffering($year, $campus, $grade, $subjectB, ['is_required' => false, 'elective_group_id' => $group->id]);
        $ungroupedTarget = $this->createSubjectOffering($year, $campus, $grade, $subjectC, ['is_required' => false]);

        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $sse = $this->createStudentSubjectEnrollment($student, $source, [
            'student_enrollment_id' => $enrollment->id, 'elective_group_id' => $group->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view', 'academics.subjects.manage']);
        $this->activate($user, $school);

        $response = $this->getJson("/app/subject-enrollments/{$sse->id}/transfer-targets")->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($source->id, $ids, 'The source Offering itself must never be its own transfer target.');
        $this->assertContains($sameGroupTarget->id, $ids, 'Same-ElectiveGroup transfer must be a valid candidate (root task §32).');
        $this->assertContains($ungroupedTarget->id, $ids);
    }

    // ==================================================================
    // Cross-School isolation (root task §33/§56)
    // ==================================================================

    #[Test]
    public function a_foreign_school_offering_404s_on_the_show_route(): void
    {
        ['school' => $school] = $this->baseContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignOffering = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view']);
        $this->activate($user, $school);

        $this->get("/app/subject-offerings/{$foreignOffering->id}")->assertNotFound();
    }

    // ==================================================================
    // History distinct from current roster (root task §13/§19)
    // ==================================================================

    #[Test]
    public function history_includes_every_status_while_roster_only_ever_reflects_current_active_membership(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'section' => $section, 'subject' => $subject] = $this->baseContext();
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);

        $active = $this->createStudent($school, ['student_number' => 'S-4001']);
        $activeEnrollment = $this->createStudentEnrollment($active, $section);
        $this->createStudentSubjectEnrollment($active, $offering, [
            'student_enrollment_id' => $activeEnrollment->id, 'status' => 'active', 'starts_on' => '2026-05-01',
        ]);

        $withdrawn = $this->createStudent($school, ['student_number' => 'S-4002']);
        $withdrawnEnrollment = $this->createStudentEnrollment($withdrawn, $section);
        $this->createStudentSubjectEnrollment($withdrawn, $offering, [
            'student_enrollment_id' => $withdrawnEnrollment->id, 'status' => 'withdrawn', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-01',
        ]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['academics.subjects.view']);
        $this->activate($user, $school);

        $this->get("/app/subject-offerings/{$offering->id}")->assertInertia(fn ($page) => $page
            ->has('roster', 1)
            ->where('roster.0.id', $active->id)
            ->has('history.data', 2));
    }
}
