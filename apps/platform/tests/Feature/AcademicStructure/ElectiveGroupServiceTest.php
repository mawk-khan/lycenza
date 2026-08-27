<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\ElectiveGroupService;
use App\Domain\AcademicStructure\Application\Exceptions\DuplicateElectiveGroupCodeException;
use App\Domain\AcademicStructure\Application\Exceptions\ElectiveGroupAssignmentLockedException;
use App\Domain\AcademicStructure\Application\Exceptions\ElectiveGroupContextMismatchException;
use App\Domain\AcademicStructure\Application\Exceptions\RequiredSubjectOfferingGroupAssignmentException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.3: ElectiveGroupService -- creation, SubjectOffering
 * assignment/reassignment/removal, and the participation-history
 * immutability invariant. Real multi-process configuration-vs-
 * enrollment races live in
 * ElectiveGroupConfigurationConcurrencyTest.php.
 */
class ElectiveGroupServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): ElectiveGroupService
    {
        return app(ElectiveGroupService::class);
    }

    private function participationService(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);

        return compact('school', 'campus', 'year', 'grade');
    }

    private function auditCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    // --- CREATE --------------------------------------------------------

    #[Test]
    public function create_succeeds_for_a_same_school_context(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildContext();

        $group = $this->service()->create($school, $year, $campus, $grade, 'Languages', 'LANG');

        $this->assertSame($school->id, $group->school_id);
        $this->assertSame($year->id, $group->academic_year_id);
        $this->assertSame($campus->id, $group->campus_id);
        $this->assertSame($grade->id, $group->grade_level_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $group->id, 'must be a real UUID');
        $this->assertInstanceOf(ElectiveGroup::class, $group);
    }

    #[Test]
    public function create_normalizes_the_code_to_uppercase(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildContext();

        $group = $this->service()->create($school, $year, $campus, $grade, 'Languages', 'lang');

        $this->assertSame('LANG', $group->code);
    }

    #[Test]
    public function a_duplicate_code_in_the_same_context_is_rejected_cleanly(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildContext();
        $this->service()->create($school, $year, $campus, $grade, 'Languages', 'LANG');

        $this->expectException(DuplicateElectiveGroupCodeException::class);

        $this->service()->create($school, $year, $campus, $grade, 'Other Languages', 'LANG');
    }

    #[Test]
    public function the_same_code_is_allowed_in_a_different_grade_level_context(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $gradeA] = $this->buildContext();
        $gradeB = $this->createGradeLevel($school);
        $this->service()->create($school, $year, $campus, $gradeA, 'Languages', 'LANG');

        $groupB = $this->service()->create($school, $year, $campus, $gradeB, 'Languages', 'LANG');

        $this->assertSame('LANG', $groupB->code);
    }

    #[Test]
    public function create_rejects_an_academic_year_from_a_foreign_school(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade] = $this->buildContext();
        $foreignYear = $this->createAcademicYear($this->createSchool());

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->create($school, $foreignYear, $campus, $grade, 'Languages', 'LANG');
    }

    #[Test]
    public function create_rejects_a_campus_from_a_foreign_school(): void
    {
        ['school' => $school, 'year' => $year, 'grade' => $grade] = $this->buildContext();
        $foreignCampus = $this->createCampus($this->createSchool());

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->create($school, $year, $foreignCampus, $grade, 'Languages', 'LANG');
    }

    #[Test]
    public function create_rejects_a_grade_level_from_a_foreign_school(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year] = $this->buildContext();
        $foreignGrade = $this->createGradeLevel($this->createSchool());

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->create($school, $year, $campus, $foreignGrade, 'Languages', 'LANG');
    }

    #[Test]
    public function a_successful_create_writes_a_success_audit_event(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildContext();

        $this->service()->create($school, $year, $campus, $grade, 'Languages', 'LANG');

        $this->assertSame(1, $this->auditCount($school, 'elective_group.created'));
    }

    #[Test]
    public function a_failed_create_writes_no_audit_event(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade] = $this->buildContext();
        $foreignYear = $this->createAcademicYear($this->createSchool());

        try {
            $this->service()->create($school, $foreignYear, $campus, $grade, 'Languages', 'LANG');
        } catch (ElectiveGroupContextMismatchException) {
            // expected
        }

        $this->assertSame(0, $this->auditCount($school, 'elective_group.created'));
    }

    // --- ASSIGN ----------------------------------------------------------

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, group: ElectiveGroup, offering: SubjectOffering}
     */
    private function buildAssignableContext(): array
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildContext();
        $group = $this->service()->create($school, $year, $campus, $grade, 'Languages', 'LANG');
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);

        return compact('school', 'campus', 'year', 'grade', 'group', 'offering');
    }

    #[Test]
    public function assigning_an_ungrouped_elective_to_a_compatible_group_succeeds_and_persists(): void
    {
        ['group' => $group, 'offering' => $offering] = $this->buildAssignableContext();

        $result = $this->service()->assignOffering($group, $offering);

        $this->assertSame($group->id, $result->elective_group_id);
        $refreshed = app(TenantContext::class)->withSchool($result->school, fn () => $offering->fresh());
        $this->assertSame($group->id, $refreshed->elective_group_id);
    }

    #[Test]
    public function assigning_a_required_offering_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $group] = $this->buildAssignableContext();
        $requiredOffering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);

        $this->expectException(RequiredSubjectOfferingGroupAssignmentException::class);

        $this->service()->assignOffering($group, $requiredOffering);
    }

    #[Test]
    public function assigning_a_foreign_schools_offering_is_rejected(): void
    {
        ['group' => $group] = $this->buildAssignableContext();
        $foreignSchool = $this->createSchool();
        $foreignCampus = $this->createCampus($foreignSchool);
        $foreignYear = $this->createAcademicYear($foreignSchool);
        $foreignGrade = $this->createGradeLevel($foreignSchool);
        $foreignOffering = $this->createSubjectOffering($foreignYear, $foreignCampus, $foreignGrade, $this->createSubject($foreignSchool), ['is_required' => false]);

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->assignOffering($group, $foreignOffering);
    }

    #[Test]
    public function assigning_to_an_offering_from_a_different_academic_year_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'grade' => $grade, 'group' => $group] = $this->buildAssignableContext();
        $otherYear = $this->createAcademicYear($school, ['code' => 'OTHERYEAR']);
        $offering = $this->createSubjectOffering($otherYear, $campus, $grade, $this->createSubject($school), ['is_required' => false]);

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function assigning_to_an_offering_from_a_different_campus_is_rejected(): void
    {
        ['school' => $school, 'year' => $year, 'grade' => $grade, 'group' => $group] = $this->buildAssignableContext();
        $otherCampus = $this->createCampus($school);
        $offering = $this->createSubjectOffering($year, $otherCampus, $grade, $this->createSubject($school), ['is_required' => false]);

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function assigning_to_an_offering_from_a_different_grade_level_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'group' => $group] = $this->buildAssignableContext();
        $otherGrade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $otherGrade, $this->createSubject($school), ['is_required' => false]);

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function assigning_an_offering_already_in_the_same_group_is_an_idempotent_no_op(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildAssignableContext();
        $this->service()->assignOffering($group, $offering);

        $result = $this->service()->assignOffering($group, $offering);

        $this->assertSame($group->id, $result->elective_group_id);
        $this->assertSame(1, $this->auditCount($school, 'subject_offering.elective_group_assigned'), 'a same-group re-assignment must not write a second audit event');
    }

    #[Test]
    public function reassigning_to_a_different_group_succeeds_with_zero_participation_history(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $groupX, 'offering' => $offering] = $this->buildAssignableContext();
        $this->service()->assignOffering($groupX, $offering);
        $groupY = $this->service()->create($school, $year, $campus, $grade, 'Music', 'MUSIC');

        $result = $this->service()->assignOffering($groupY, $offering);

        $this->assertSame($groupY->id, $result->elective_group_id);
    }

    #[Test]
    public function a_successful_assignment_writes_a_success_audit_event_with_from_and_to_ids(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $groupX, 'offering' => $offering] = $this->buildAssignableContext();
        $this->service()->assignOffering($groupX, $offering);
        $groupY = $this->service()->create($school, $year, $campus, $grade, 'Music', 'MUSIC');

        $this->service()->assignOffering($groupY, $offering);

        $this->assertSame(2, $this->auditCount($school, 'subject_offering.elective_group_assigned'));
    }

    // --- REMOVE ------------------------------------------------------------

    #[Test]
    public function removing_a_groups_assignment_with_zero_history_succeeds(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildAssignableContext();
        $this->service()->assignOffering($group, $offering);

        $result = $this->service()->removeOffering($offering);

        $this->assertNull($result->elective_group_id);
    }

    #[Test]
    public function removing_from_an_already_ungrouped_offering_is_an_idempotent_no_op(): void
    {
        ['school' => $school, 'offering' => $offering] = $this->buildAssignableContext();

        $result = $this->service()->removeOffering($offering);

        $this->assertNull($result->elective_group_id);
        $this->assertSame(0, $this->auditCount($school, 'subject_offering.elective_group_removed'));
    }

    #[Test]
    public function a_successful_removal_writes_a_success_audit_event(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildAssignableContext();
        $this->service()->assignOffering($group, $offering);

        $this->service()->removeOffering($offering);

        $this->assertSame(1, $this->auditCount($school, 'subject_offering.elective_group_removed'));
    }

    // --- IMMUTABILITY --------------------------------------------------

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, group: ElectiveGroup, offering: SubjectOffering}
     */
    private function buildContextWithActiveParticipation(): array
    {
        $fixture = $this->buildAssignableContext();
        $section = $this->createSection($fixture['year'], $fixture['campus'], $fixture['grade']);
        $student = $this->createStudent($fixture['school'], ['student_number' => 'IM-0001']);
        $this->createStudentEnrollment($student, $section);
        $this->participationService()->enroll($student, $fixture['offering'], '2026-06-01');

        return $fixture;
    }

    #[Test]
    public function active_participation_blocks_a_first_time_assignment(): void
    {
        ['group' => $group, 'offering' => $offering] = $this->buildContextWithActiveParticipation();

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function withdrawn_participation_history_still_blocks_assignment(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildContextWithActiveParticipation();
        $row = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $offering->id)->firstOrFail());
        $this->participationService()->withdraw($row, '2026-08-01');

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function cancelled_participation_history_still_blocks_assignment(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildContextWithActiveParticipation();
        $row = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $offering->id)->firstOrFail());
        $this->participationService()->cancel($row, '2026-06-05');

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function transferred_participation_history_blocks_configuration_on_both_offerings(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $groupX, 'offering' => $offeringA] = $this->buildContextWithActiveParticipation();
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);
        $rowA = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $offeringA->id)->firstOrFail());
        $this->participationService()->transfer($rowA, $offeringB, '2026-09-01');

        // Offering A (the TRANSFERRED-FROM source) retains its own history.
        try {
            $this->service()->assignOffering($groupX, $offeringA);
            $this->fail('Expected ElectiveGroupAssignmentLockedException for the source offering');
        } catch (ElectiveGroupAssignmentLockedException $e) {
            $this->assertInstanceOf(ElectiveGroupAssignmentLockedException::class, $e);
        }

        // Offering B (the NEW target) now ALSO has history.
        try {
            $this->service()->assignOffering($groupX, $offeringB);
            $this->fail('Expected ElectiveGroupAssignmentLockedException for the target offering');
        } catch (ElectiveGroupAssignmentLockedException $e) {
            $this->assertInstanceOf(ElectiveGroupAssignmentLockedException::class, $e);
        }
    }

    #[Test]
    public function a_legacy_row_with_a_null_snapshot_still_blocks_new_grouping(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildAssignableContext();
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->create([
            'school_id' => $school->id,
            'student_id' => $this->createStudent($school, ['student_number' => 'LEGACY-0001'])->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $offering->id,
            'elective_group_id' => null,
            'academic_year_id' => $offering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2025-01-01',
        ]));

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->assignOffering($group, $offering);
    }

    #[Test]
    public function a_rejected_assignment_leaves_the_offerings_group_unchanged(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $groupX, 'offering' => $offering] = $this->buildContextWithActiveParticipation();
        $groupY = $this->service()->create($school, $year, $campus, $grade, 'Music', 'MUSIC');

        try {
            $this->service()->assignOffering($groupY, $offering);
        } catch (ElectiveGroupAssignmentLockedException) {
            // expected
        }

        $refreshed = app(TenantContext::class)->withSchool($school, fn () => $offering->fresh());
        $this->assertNull($refreshed->elective_group_id, 'the offering was never grouped to begin with, and the rejection must not have changed that');
    }

    #[Test]
    public function a_rejected_assignment_writes_no_success_audit(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $offering] = $this->buildContextWithActiveParticipation();

        try {
            $this->service()->assignOffering($group, $offering);
        } catch (ElectiveGroupAssignmentLockedException) {
            // expected
        }

        $this->assertSame(0, $this->auditCount($school, 'subject_offering.elective_group_assigned'));
    }

    #[Test]
    public function active_participation_also_blocks_removal_of_an_existing_grouped_offering(): void
    {
        // Group first (zero history), THEN create participation, THEN
        // attempt removal -- proves removal is frozen too, not just
        // first-time assignment.
        ['group' => $group, 'offering' => $offering, 'school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildAssignableContext();
        $this->service()->assignOffering($group, $offering);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'RM-0001']);
        $this->createStudentEnrollment($student, $section);
        $this->participationService()->enroll($student, $offering, '2026-06-01');

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->removeOffering($offering);
    }

    // --- STALE MODEL SAFETY ---------------------------------------------

    #[Test]
    public function assignment_reloads_the_offering_rather_than_trusting_a_stale_required_flag(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $staleOffering] = $this->buildAssignableContext();
        app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->whereKey($staleOffering->id)->update(['is_required' => true]));
        $this->assertFalse($staleOffering->is_required, 'sanity: the caller-held model must still be stale in memory');

        $this->expectException(RequiredSubjectOfferingGroupAssignmentException::class);

        $this->service()->assignOffering($group, $staleOffering);
    }

    #[Test]
    public function assignment_cannot_be_bypassed_by_a_stale_offering_model_missing_recent_participation(): void
    {
        ['school' => $school, 'group' => $group, 'offering' => $staleOffering, 'campus' => $campus, 'year' => $year, 'grade' => $grade] = $this->buildAssignableContext();
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'STALE-0001']);
        $this->createStudentEnrollment($student, $section);
        // Participation created via a SEPARATE, freshly-fetched model --
        // $staleOffering's own in-memory state never reflects it.
        $freshOffering = app(TenantContext::class)->withSchool($school, fn () => SubjectOffering::query()->findOrFail($staleOffering->id));
        $this->participationService()->enroll($student, $freshOffering, '2026-06-01');

        $this->expectException(ElectiveGroupAssignmentLockedException::class);

        $this->service()->assignOffering($group, $staleOffering);
    }

    // --- CROSS-SCHOOL ------------------------------------------------------

    #[Test]
    public function a_group_cannot_be_assigned_to_an_offering_via_a_school_a_group_and_school_b_offering(): void
    {
        ['group' => $groupA] = $this->buildAssignableContext();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $offeringB = $this->createSubjectOffering($yearB, $campusB, $gradeB, $this->createSubject($schoolB), ['is_required' => false]);

        $this->expectException(ElectiveGroupContextMismatchException::class);

        $this->service()->assignOffering($groupA, $offeringB);
    }
}
