<?php

namespace Tests\Feature\Communications;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Audience\AcademicCohortSelection;
use App\Domain\Communications\Application\Exceptions\InvalidAcademicCohortException;
use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAcademicCohortType;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAcademicCohort;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5C.1 -- SubjectOfferingAudienceResolver correctness, resolved
 * exclusively through App\Domain\Students\Application\
 * SubjectOfferingRosterReadService (Phase 1C) -- never against
 * `student_enrollments`/`student_subject_enrollments` directly. Mirrors
 * AcademicCohortAudienceResolverTest's structure/discipline for
 * Grade/Section (dynamic resolution, enrollment-never-implies-account-
 * identity regression, bounded query cost).
 */
class SubjectOfferingAudienceResolverTest extends TestCase
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
     * @return array{year: AcademicYear, grade: GradeLevel, requiredOffering: SubjectOffering, electiveOffering: SubjectOffering}
     */
    private function graph(School $school): array
    {
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $electiveSubject = $this->createSubject($school, ['code' => 'ELEC']);
        $section = $this->createSection($year, $campus, $grade);
        $requiredOffering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true]);
        $electiveOffering = $this->createSubjectOffering($year, $campus, $grade, $electiveSubject, ['is_required' => false]);

        return [
            'year' => $year, 'grade' => $grade, 'section' => $section,
            'requiredOffering' => $requiredOffering, 'electiveOffering' => $electiveOffering,
        ];
    }

    private function cohortSelection(AcademicYear $year, SubjectOffering $offering, CommunicationAcademicCohortRecipientKind $kind = CommunicationAcademicCohortRecipientKind::Student): AcademicCohortSelection
    {
        return new AcademicCohortSelection(
            CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null, $kind, $offering->id,
        );
    }

    // --- 1: required offering resolves via the implied roster -------------

    #[Test]
    public function a_required_offering_audience_resolves_every_compatibly_enrolled_student(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $inGrade = $this->createStudent($school);
        $this->createStudentEnrollment($inGrade, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$inGrade->id], $preview->studentIds);
    }

    // --- 2: elective offering resolves via the explicit roster -------------

    #[Test]
    public function an_elective_offering_audience_resolves_only_explicitly_enrolled_students(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'electiveOffering' => $offering] = $this->graph($school);

        $enrolled = $this->createStudent($school);
        $this->createStudentEnrollment($enrolled, $section, ['status' => 'active']);
        $this->createStudentSubjectEnrollment($enrolled, $offering, ['status' => 'active']);

        // Compatible-grade student who never explicitly enrolled in the
        // elective -- must NOT appear (this is what distinguishes
        // elective from required; §12 -- Communications never branches
        // on is_required to know this, the roster service does).
        $notEnrolled = $this->createStudent($school);
        $this->createStudentEnrollment($notEnrolled, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$enrolled->id], $preview->studentIds);
    }

    // --- Guardian projection ------------------------------------------------

    #[Test]
    public function a_subject_offering_audience_guardians_projects_eligible_guardians_and_dedupes(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $sharedGuardian = $this->createGuardian($school);
        $child1 = $this->createStudent($school);
        $this->createStudentEnrollment($child1, $section, ['status' => 'active']);
        $this->createStudentGuardianRelationship($child1, $sharedGuardian, ['is_primary' => true]);

        $child2 = $this->createStudent($school);
        $this->createStudentEnrollment($child2, $section, ['status' => 'active']);
        $this->createStudentGuardianRelationship($child2, $sharedGuardian, ['is_legal_guardian' => true]);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering, CommunicationAcademicCohortRecipientKind::Guardian),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$sharedGuardian->id], $preview->guardianIds);
    }

    // --- 3: inactive offering resolves to an empty audience -----------------

    #[Test]
    public function an_offering_deactivated_after_the_audience_was_authored_resolves_empty_without_touching_the_definition(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        $before = $this->announcements()->previewAudience($announcement);
        $this->assertSame(1, $before->count());

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));

        $after = $this->announcements()->previewAudience($announcement);
        $this->assertTrue($after->isEmpty());

        $cohort = app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAnnouncementAcademicCohort::query()->where('announcement_id', $announcement->id)->first(),
        );
        $this->assertNotNull($cohort, 'Deactivating the offering must never delete/modify the audience definition.');
        $this->assertSame($offering->id, $cohort->subject_offering_id);
    }

    // --- 4: reactivation restores the dynamic audience -----------------------

    #[Test]
    public function reactivating_the_offering_restores_the_ordinary_audience(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));
        $this->assertTrue($this->announcements()->previewAudience($announcement)->isEmpty());

        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'active']));
        $restored = $this->announcements()->previewAudience($announcement);

        $this->assertSame([$student->id], $restored->studentIds);
    }

    // --- 5: a Student's academic movement is reflected dynamically ----------

    #[Test]
    public function a_student_who_leaves_the_offerings_grade_before_publication_is_excluded_at_publish_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $staying = $this->createStudent($school);
        $this->createStudentEnrollment($staying, $section, ['status' => 'active']);
        $this->links()->linkStudent($school, $staying, $this->createMembership($this->createUser(), $school), $admin);

        $leaving = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($leaving, $section, ['status' => 'active']);
        $this->links()->linkStudent($school, $leaving, $this->createMembership($this->createUser(), $school), $admin);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            channels: [CommunicationChannel::InApp],
            academicCohort: $this->cohortSelection($year, $offering),
        );

        // §3/§5: never frozen at draft time -- the Student withdraws
        // from the underlying StudentEnrollment (not the offering
        // itself) AFTER the draft was created.
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
    public function a_student_who_withdraws_their_elective_enrollment_is_excluded_from_the_current_audience(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'electiveOffering' => $offering] = $this->graph($school);

        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $enrollment = $this->createStudentSubjectEnrollment($student, $offering, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        $this->assertSame(1, $this->announcements()->previewAudience($announcement)->count());

        app(TenantContext::class)->withSchool($school, fn () => $enrollment->update(['status' => 'withdrawn']));

        $this->assertTrue($this->announcements()->previewAudience($announcement)->isEmpty());
    }

    // --- 6/7: cross-School / random UUID rejected identically --------------

    #[Test]
    public function drafting_a_subject_offering_cohort_with_another_schools_offering_id_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        ['requiredOffering' => $foreignOffering] = $this->graph($otherSchool);

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $foreignOffering),
        );
    }

    #[Test]
    public function drafting_a_subject_offering_cohort_with_a_random_nonexistent_id_fails_the_same_way_as_a_foreign_id(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);

        try {
            $this->announcements()->createDraft(
                $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
                academicCohort: new AcademicCohortSelection(
                    CommunicationAcademicCohortType::SubjectOffering, $year->id, null, null,
                    CommunicationAcademicCohortRecipientKind::Student, (string) Str::uuid(),
                ),
            );
            $this->fail('Expected InvalidAcademicCohortException.');
        } catch (InvalidAcademicCohortException $foreignCase) {
            // Same exception type/shape as the cross-School case above
            // -- no enumeration signal distinguishes "exists in another
            // School" from "does not exist at all".
            $this->assertInstanceOf(InvalidAcademicCohortException::class, $foreignCase);
        }
    }

    #[Test]
    public function drafting_a_subject_offering_cohort_with_a_mismatched_academic_year_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['requiredOffering' => $offering] = $this->graph($school);
        $wrongYear = $this->createAcademicYear($school, ['name' => 'Wrong Year', 'code' => 'AY-WRONG']);

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($wrongYear, $offering),
        );
    }

    #[Test]
    public function drafting_an_inactive_offerings_cohort_is_rejected_at_authoring_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'requiredOffering' => $offering] = $this->graph($school);
        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );
    }

    #[Test]
    public function drafting_a_subject_offering_cohort_with_no_selection_at_all_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        $this->expectException(InvalidAcademicCohortException::class);

        $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
        );
    }

    // --- enrollment never implies account identity (mandatory regression) --

    #[Test]
    public function an_enrolled_but_unlinked_student_in_a_subject_offering_audience_receives_no_in_app_delivery(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            channels: [CommunicationChannel::InApp],
            academicCohort: $this->cohortSelection($year, $offering),
        );
        $published = $this->announcements()->publish($announcement, $admin);

        $deliveries = app(TenantContext::class)->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get());
        $this->assertCount(0, $deliveries, 'SubjectOffering membership alone must never grant IN_APP reachability.');

        $reason = app(TenantContext::class)->withSchool($school, fn () => CommunicationDeliveryPolicyDecision::query()
            ->where('message_id', $published->message_id)->where('recipient_student_id', $student->id)
            ->where('channel', 'in_app')->value('reason'));
        $this->assertSame('recipient_ineligible', $reason);
    }

    // --- call-seam: delegates to the roster service, never reconstructs ----

    /**
     * Phase 5C.1 §38: proves delegation without introducing mocking --
     * this test suite has no Mockery/spy precedent anywhere (ADR 0024's
     * real-integration discipline), so the delegation proof is: the
     * SAME roster the real SubjectOfferingRosterReadService reports
     * directly (called here, independently of Communications) is
     * EXACTLY what the Communications preview returns. If
     * SubjectOfferingAudienceResolver ever reimplemented roster logic
     * itself instead of delegating, a change to the roster service's
     * own compatibility rules (grade/campus/year matching) would make
     * this assertion diverge without this test file changing at all.
     */
    #[Test]
    public function the_resolver_delegates_to_the_real_roster_service_output_exactly(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        $matching = $this->createStudent($school);
        $this->createStudentEnrollment($matching, $section, ['status' => 'active']);

        $otherGrade = $this->createGradeLevel($school);
        $otherSection = $this->createSection($year, $this->createCampus($school), $otherGrade);
        $incompatible = $this->createStudent($school);
        $this->createStudentEnrollment($incompatible, $otherSection, ['status' => 'active']);

        $expectedRoster = app(TenantContext::class)->withSchool(
            $school,
            fn () => app(SubjectOfferingRosterReadService::class)->currentRosterStudentIds($offering->fresh()),
        );

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering),
        );

        $preview = $this->announcements()->previewAudience($announcement);

        $this->assertSame($expectedRoster, $preview->studentIds);
        $this->assertSame([$matching->id], $preview->studentIds);
    }

    // --- performance at scale ------------------------------------------------

    #[Test]
    public function resolving_a_subject_offering_guardian_audience_at_scale_uses_a_bounded_query_count(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'requiredOffering' => $offering] = $this->graph($school);

        for ($i = 0; $i < 60; $i++) {
            $student = $this->createStudent($school);
            $this->createStudentEnrollment($student, $section, ['status' => 'active', 'roll_number' => (string) ($i + 1)]);
            $guardian = $this->createGuardian($school);
            $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);
        }

        $announcement = $this->announcements()->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SubjectOffering,
            academicCohort: $this->cohortSelection($year, $offering, CommunicationAcademicCohortRecipientKind::Guardian),
        );

        DB::enableQueryLog();
        $preview = $this->announcements()->previewAudience($announcement);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(60, $preview->guardianIds);
        // One additional query versus Grade/Section's bound: this
        // resolver must look up the SubjectOffering itself (Grade/
        // Section already have the id directly usable in their
        // StudentEnrollment query, no separate lookup). Still a small
        // constant, never one query per Student/Guardian.
        $this->assertLessThan(20, $queryCount);
    }
}
