<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAcademicCohort;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5C.2 -- HTTP-layer coverage for the SubjectOffering
 * academic-cohort composer flow and the SubjectOffering search
 * endpoint, mirroring CommunicationAcademicCohortAudienceHubTest's
 * (Grade/Section) exact conventions.
 */
class CommunicationSubjectOfferingAudienceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /**
     * @return array{year: AcademicYear, grade: GradeLevel, section: Section, required: SubjectOffering, elective: SubjectOffering}
     */
    private function graph($school): array
    {
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $requiredSubject = $this->createSubject($school, ['name' => 'Math', 'code' => 'MATH']);
        $electiveSubject = $this->createSubject($school, ['name' => 'Art', 'code' => 'ART']);
        $required = $this->createSubjectOffering($year, $campus, $grade, $requiredSubject, ['is_required' => true]);
        $elective = $this->createSubjectOffering($year, $campus, $grade, $electiveSubject, ['is_required' => false]);

        return ['year' => $year, 'grade' => $grade, 'section' => $section, 'required' => $required, 'elective' => $elective];
    }

    // --- 33: authorized composer access ------------------------------------

    #[Test]
    public function an_authorized_user_can_open_the_composer(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/announcements/create')->assertOk();
    }

    #[Test]
    public function a_member_without_communications_announce_cannot_open_the_composer(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app/communications/announcements/create')->assertForbidden();
    }

    // --- 35/41: create + publish, required offering, Students --------------

    #[Test]
    public function a_school_admin_can_draft_and_publish_a_required_subject_offering_announcement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'required' => $offering] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Math Assessment',
            'body' => 'Bring a calculator.',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'subject_offering_id' => $offering->id,
                'recipient_kind' => 'student',
            ],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->component('App/Communications/Announcements/Show')
            ->where('announcement.audienceType', 'subject_offering')
            ->where('preview.academicCohort.cohortType', 'subject_offering')
            ->where('preview.academicCohort.subjectOfferingLabel', 'Math (MATH) — Required')
            ->where('preview.academicCohort.recipientKind', 'student')
            ->where('preview.domain.studentCount', 1)
        );

        $this->post($showUrl.'/publish')->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.status', 'published')
            ->where('announcement.recipientCount', 1)
        );
    }

    // --- 42: elective offering, Guardians -----------------------------------

    #[Test]
    public function a_school_admin_can_draft_an_elective_subject_offering_announcement_for_guardians(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section, 'elective' => $offering] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->createStudentSubjectEnrollment($student, $offering, ['status' => 'active']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);

        // Compatible-grade Student who never explicitly enrolled in the
        // elective -- must not count (proves the composer never
        // branches on required/elective itself; the roster service
        // does).
        $notEnrolled = $this->createStudent($school);
        $this->createStudentEnrollment($notEnrolled, $section, ['status' => 'active']);

        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Art Club Fee',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'subject_offering_id' => $offering->id,
                'recipient_kind' => 'guardian',
            ],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.audienceType', 'subject_offering')
            ->where('preview.academicCohort.cohortType', 'subject_offering')
            ->where('preview.academicCohort.subjectOfferingLabel', 'Art (ART) — Elective')
            ->where('preview.academicCohort.recipientKind', 'guardian')
            ->where('preview.domain.guardianCount', 1)
        );
    }

    // --- 40: inactive offering (excluded from the picker, matching the -----
    // --- backend's authoring-time active-only gate) -------------------------

    #[Test]
    public function an_inactive_subject_offering_cannot_be_selected_at_authoring_time(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'required' => $offering] = $this->graph($school);
        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'subject_offering_id' => $offering->id,
                'recipient_kind' => 'student',
            ],
        ])->assertSessionHasErrors('academic_cohort');
    }

    // --- no selection --------------------------------------------------------

    #[Test]
    public function submitting_a_subject_offering_audience_with_no_selection_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
        ])->assertSessionHasErrors('academic_cohort');
    }

    // --- 36: cross-School / random UUID rejected identically ---------------

    #[Test]
    public function a_cross_school_subject_offering_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        ['required' => $foreignOffering] = $this->graph($otherSchool);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'subject_offering_id' => $foreignOffering->id,
                'recipient_kind' => 'student',
            ],
        ])->assertSessionHasErrors('academic_cohort');
    }

    #[Test]
    public function a_random_nonexistent_subject_offering_id_is_rejected_the_same_way_as_a_foreign_one(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'subject_offering_id' => (string) Str::uuid(),
                'recipient_kind' => 'student',
            ],
        ])->assertSessionHasErrors('academic_cohort');
    }

    // --- 37: switching audience types clears stale grade/section fields ----
    // (server-side proof: even a malformed client payload carrying a
    // grade_level_id alongside audience_type=subject_offering never
    // persists it -- AnnouncementService::syncAcademicCohort()'s
    // SubjectOffering branch ignores it unconditionally)

    #[Test]
    public function a_stale_grade_level_id_submitted_alongside_subject_offering_is_never_persisted(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'required' => $offering] = $this->graph($school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'subject_offering',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'section_id' => null,
                'subject_offering_id' => $offering->id,
                'recipient_kind' => 'student',
            ],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');
        $announcementId = basename(parse_url($showUrl, PHP_URL_PATH));

        $cohort = app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationAnnouncementAcademicCohort::query()->where('announcement_id', $announcementId)->first(),
        );

        $this->assertSame('subject_offering', $cohort->cohort_type);
        $this->assertSame($offering->id, $cohort->subject_offering_id);
        $this->assertNull($cohort->grade_level_id, 'A stale grade_level_id in the payload must never be persisted for a subject_offering audience.');
        $this->assertNull($cohort->section_id);
    }

    // --- 34: search endpoint --------------------------------------------------

    #[Test]
    public function the_subject_offering_search_endpoint_requires_an_academic_year_id_and_finds_a_matching_offering(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'required' => $offering] = $this->graph($school);
        $this->activate($admin, $school);

        $withoutYear = $this->actingAs($admin)->get('/app/communications/audience/subject-offerings/search?q=Math');
        $withoutYear->assertOk();
        $withoutYear->assertJson(['subjectOfferings' => []]);

        $withYear = $this->actingAs($admin)->get("/app/communications/audience/subject-offerings/search?q=Math&academic_year_id={$year->id}");
        $withYear->assertOk();
        $withYear->assertJsonFragment(['id' => $offering->id]);
        // §29: only safe reference metadata, never Student membership.
        $body = $withYear->json();
        $this->assertStringContainsString('[required]', $body['subjectOfferings'][0]['label']);
        $this->assertArrayNotHasKey('students', $body);
        $this->assertArrayNotHasKey('roster', $body);
    }

    #[Test]
    public function the_subject_offering_search_endpoint_never_returns_a_cross_school_offering(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        ['year' => $otherYear, 'required' => $foreignOffering] = $this->graph($otherSchool);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get("/app/communications/audience/subject-offerings/search?q=Math&academic_year_id={$year->id}");

        $response->assertOk();
        $response->assertJson(['subjectOfferings' => []]);
    }

    #[Test]
    public function the_subject_offering_search_endpoint_excludes_inactive_offerings(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'required' => $offering] = $this->graph($school);
        app(TenantContext::class)->withSchool($school, fn () => $offering->update(['status' => 'inactive']));
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get("/app/communications/audience/subject-offerings/search?q=Math&academic_year_id={$year->id}");

        $response->assertOk();
        $response->assertJson(['subjectOfferings' => []]);
    }

    #[Test]
    public function a_member_without_communications_announce_is_denied_the_subject_offering_search_endpoint(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app/communications/audience/subject-offerings/search?q=a')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403_for_the_subject_offering_search_endpoint(): void
    {
        $this->get('/app/communications/audience/subject-offerings/search?q=a')->assertRedirect('/login');
    }
}
