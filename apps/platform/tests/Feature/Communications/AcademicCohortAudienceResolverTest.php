<?php

namespace Tests\Feature\Communications;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Audience\AcademicCohortSelection;
use App\Domain\Communications\Application\Exceptions\InvalidAcademicCohortException;
use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAcademicCohortType;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.3 -- GradeAudienceResolver/SectionAudienceResolver
 * correctness against Phase 1B's `student_enrollments`, dynamic
 * (never-frozen) resolution, the enrollment-never-implies-account-
 * identity regression, and the GuardianProjectionResolver's bounded
 * query cost at scale.
 */
class AcademicCohortAudienceResolverTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function links(): AccountLinkService
    {
        return app(AccountLinkService::class);
    }

    /**
     * @return array{year: AcademicYear, grade: GradeLevel, section: Section}
     */
    private function graph(School $school, array $sectionAttributes = []): array
    {
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade, $sectionAttributes);

        return ['year' => $year, 'grade' => $grade, 'section' => $section];
    }

    // --- Grade: Students -------------------------------------------------

    #[Test]
    public function grade_audience_resolves_only_actively_enrolled_students_in_that_grade_and_year(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $gradeA, 'section' => $sectionA] = $this->graph($school);
        $otherGrade = $this->createGradeLevel($school);
        $otherYear = $this->createAcademicYear($school, ['name' => 'Other Year', 'code' => 'AY-OTHER']);
        $otherCampus = $this->createCampus($school);
        $sectionOtherGrade = $this->createSection($year, $otherCampus, $otherGrade);
        $sectionOtherYear = $this->createSection($otherYear, $otherCampus, $gradeA);

        $inGrade = $this->createStudent($school);
        $this->createStudentEnrollment($inGrade, $sectionA, ['status' => 'active']);

        $withdrawn = $this->createStudent($school);
        $this->createStudentEnrollment($withdrawn, $sectionA, ['status' => 'withdrawn']);

        $otherGradeStudent = $this->createStudent($school);
        $this->createStudentEnrollment($otherGradeStudent, $sectionOtherGrade, ['status' => 'active']);

        $otherYearStudent = $this->createStudent($school);
        $this->createStudentEnrollment($otherYearStudent, $sectionOtherYear, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $gradeA->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$inGrade->id], $preview->studentIds);
    }

    // --- Grade: Guardians (dedup + eligibility) ---------------------------

    #[Test]
    public function grade_audience_guardians_projects_eligible_guardians_and_dedupes_a_shared_guardian(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);

        $sharedGuardian = $this->createGuardian($school);
        $child1 = $this->createStudent($school);
        $this->createStudentEnrollment($child1, $section, ['status' => 'active']);
        $this->createStudentGuardianRelationship($child1, $sharedGuardian, ['is_primary' => true]);

        $child2 = $this->createStudent($school);
        $this->createStudentEnrollment($child2, $section, ['status' => 'active']);
        $this->createStudentGuardianRelationship($child2, $sharedGuardian, ['is_legal_guardian' => true]);

        // Neither primary nor legal guardian -- excluded (brief's
        // reused Phase 5B.1 eligibility predicate).
        $ineligibleGuardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($child1, $ineligibleGuardian, [
            'is_primary' => false, 'is_legal_guardian' => false, 'is_emergency_contact' => true,
        ]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Guardian,
            ),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$sharedGuardian->id], $preview->guardianIds);
    }

    // --- Section: Students/Guardians --------------------------------------

    #[Test]
    public function section_audience_resolves_only_actively_enrolled_students_in_that_section_and_year(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $sectionA] = $this->graph($school);
        $campus = $this->createCampus($school);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);

        $inSectionA = $this->createStudent($school);
        $this->createStudentEnrollment($inSectionA, $sectionA, ['status' => 'active']);

        $inSectionB = $this->createStudent($school);
        $this->createStudentEnrollment($inSectionB, $sectionB, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Section,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::Section, $year->id, null, $sectionA->id, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$inSectionA->id], $preview->studentIds);
    }

    #[Test]
    public function section_audience_guardians_projects_eligible_guardians(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);

        $guardian = $this->createGuardian($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Section,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::Section, $year->id, null, $section->id, CommunicationAcademicCohortRecipientKind::Guardian,
            ),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$guardian->id], $preview->guardianIds);
    }

    // --- §7: Section<->AcademicYear defense-in-depth ----------------------

    #[Test]
    public function drafting_a_section_cohort_with_a_mismatched_academic_year_id_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['section' => $section] = $this->graph($school);
        $wrongYear = $this->createAcademicYear($school, ['name' => 'Wrong Year', 'code' => 'AY-WRONG']);

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Section,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::Section, $wrongYear->id, null, $section->id, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
    }

    // --- §19: cross-school forgery (root CLAUDE.md rule 19) --------------

    #[Test]
    public function drafting_a_grade_cohort_with_another_schools_grade_level_id_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        $foreignGrade = $this->createGradeLevel($otherSchool);

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $foreignGrade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
    }

    #[Test]
    public function drafting_a_grade_cohort_with_no_selection_at_all_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
        );
    }

    // --- §3: dynamic/publication-time resolution --------------------------

    #[Test]
    public function a_student_who_leaves_the_grade_before_publication_is_excluded_at_publish_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);

        // A second, stays-enrolled Student keeps the resolved audience
        // non-empty at publish time -- isolating this test to the
        // dynamic-exclusion behavior itself, not publish()'s separate
        // (and separately tested) EmptyAudienceException guard.
        $staying = $this->createStudent($school);
        $this->createStudentEnrollment($staying, $section, ['status' => 'active']);
        $this->links()->linkStudent($school, $staying, $this->createMembership($this->createUser(), $school), $admin);

        $leaving = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($leaving, $section, ['status' => 'active']);
        $this->links()->linkStudent($school, $leaving, $this->createMembership($this->createUser(), $school), $admin);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );

        // §3: NEVER frozen at scheduling/draft time -- the Student
        // leaves the Grade (withdrawn) AFTER the draft was created.
        app(TenantContext::class)->withSchool($school, function () use ($enrollment): void {
            $enrollment->update(['status' => 'withdrawn']);
        });

        $published = $this->announcements()->publish($announcement, $admin);

        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(1, $deliveries, 'Only the Student who stayed enrolled should receive a delivery.');
    }

    #[Test]
    public function a_student_who_joins_the_grade_before_publication_is_included_at_publish_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );

        // §3: a Student who joins the Grade AFTER the draft was
        // created (no enrollment existed at draft time at all).
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->links()->linkStudent($school, $student, $this->createMembership($this->createUser(), $school), $admin);

        $published = $this->announcements()->publish($announcement, $admin);

        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(1, $deliveries);
    }

    // --- enrollment never implies account identity (mandatory regression) -

    #[Test]
    public function an_enrolled_but_unlinked_student_in_a_grade_audience_receives_no_in_app_delivery(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $published = $this->announcements()->publish($announcement, $admin);

        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(0, $deliveries, 'Enrollment membership alone must never grant IN_APP reachability.');

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_student_id', $student->id)
            ->where('channel', 'in_app')->value('reason'));
        $this->assertSame('recipient_ineligible', $reason);
    }

    #[Test]
    public function a_linked_and_enrolled_student_in_a_grade_audience_receives_a_real_in_app_delivery(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->links()->linkStudent($school, $student, $membership, $admin);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            channels: [CommunicationChannel::InApp],
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Student,
            ),
        );
        $published = $this->announcements()->publish($announcement, $admin);

        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(1, $deliveries);
        $this->assertSame('delivered', $deliveries->first()->status);
    }

    // --- §49: performance at scale -----------------------------------------

    #[Test]
    public function resolving_a_grade_guardian_audience_at_scale_uses_a_bounded_query_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);

        // 60 Students, sharing 30 Guardians two-children-each -- 100+
        // enrollments across this test's total fixture writes.
        for ($i = 0; $i < 60; $i++) {
            $student = $this->createStudent($school);
            $this->createStudentEnrollment($student, $section, ['status' => 'active', 'roll_number' => (string) ($i + 1)]);
            $guardian = $this->createGuardian($school);
            $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);
        }

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::Grade,
            academicCohort: new AcademicCohortSelection(
                CommunicationAcademicCohortType::GradeLevel, $year->id, $grade->id, null, CommunicationAcademicCohortRecipientKind::Guardian,
            ),
        );

        DB::enableQueryLog();
        $preview = $this->announcements()->previewAudience($announcement);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(60, $preview->guardianIds);
        // GradeAudienceResolver issues exactly ONE StudentEnrollment
        // query, then GuardianProjectionResolver issues exactly ONE
        // joined query -- never one query per Student/Guardian.
        $this->assertLessThan(15, $queryCount);
    }
}
