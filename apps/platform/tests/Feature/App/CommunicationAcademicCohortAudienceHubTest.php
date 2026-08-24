<?php

namespace Tests\Feature\App;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.3 -- HTTP-layer coverage for the Grade/Section
 * academic-cohort composer flow, the Grade/Section search endpoints,
 * and cross-school forgery via a raw HTTP request. Mirrors
 * CommunicationDomainAudienceHubTest's conventions.
 */
class CommunicationAcademicCohortAudienceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function graph($school): array
    {
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        return ['year' => $year, 'grade' => $grade, 'section' => $section];
    }

    #[Test]
    public function a_school_admin_can_draft_and_publish_a_grade_academic_cohort_announcement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade, 'section' => $section] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Grade Assembly',
            'body' => 'Report to the hall at 9 AM.',
            'priority' => 'normal',
            'audience_type' => 'grade',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'section_id' => null,
                'recipient_kind' => 'student',
            ],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->component('App/Communications/Announcements/Show')
            ->where('announcement.audienceType', 'grade')
            ->where('preview.academicCohort.cohortType', 'grade_level')
            ->where('preview.academicCohort.gradeLevelName', $grade->name)
            ->where('preview.academicCohort.recipientKind', 'student')
            ->where('preview.domain.studentCount', 1)
        );

        $this->post($showUrl.'/publish')->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.status', 'published')
            ->where('announcement.recipientCount', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_draft_a_section_academic_cohort_announcement_for_guardians(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section] = $this->graph($school);
        $student = $this->createStudent($school);
        $this->createStudentEnrollment($student, $section, ['status' => 'active']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_primary' => true]);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Fee Reminder',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'section',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'grade_level_id' => null,
                'section_id' => $section->id,
                'recipient_kind' => 'guardian',
            ],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.audienceType', 'section')
            ->where('preview.academicCohort.cohortType', 'section')
            ->where('preview.academicCohort.recipientKind', 'guardian')
            ->where('preview.domain.guardianCount', 1)
        );
    }

    #[Test]
    public function submitting_a_grade_audience_with_no_academic_cohort_selection_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'grade',
        ])->assertSessionHasErrors('academic_cohort');
    }

    #[Test]
    public function a_cross_school_grade_level_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        $foreignGrade = $this->createGradeLevel($otherSchool);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'grade',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'grade_level_id' => $foreignGrade->id,
                'section_id' => null,
                'recipient_kind' => 'student',
            ],
        ])->assertSessionHasErrors('academic_cohort');
    }

    #[Test]
    public function a_cross_school_section_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $otherSchool = $this->createSchool();
        $otherYear = $this->createAcademicYear($otherSchool, ['status' => 'active']);
        $otherCampus = $this->createCampus($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $foreignSection = $this->createSection($otherYear, $otherCampus, $otherGrade);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'section',
            'academic_cohort' => [
                'academic_year_id' => $year->id,
                'grade_level_id' => null,
                'section_id' => $foreignSection->id,
                'recipient_kind' => 'student',
            ],
        ])->assertSessionHasErrors('academic_cohort');
    }

    // --- §34: search endpoints ---------------------------------------------

    #[Test]
    public function the_grade_level_search_endpoint_finds_a_same_school_active_grade_by_name(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $grade = $this->createGradeLevel($school, ['name' => 'Zylia Grade', 'code' => 'ZG1', 'sequence' => 401]);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/grade-levels/search?q=zylia');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $grade->id]);
    }

    #[Test]
    public function the_grade_level_search_endpoint_never_returns_a_cross_school_grade(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $this->createGradeLevel($otherSchool, ['name' => 'Uniquexyzgrade', 'code' => 'UXG', 'sequence' => 402]);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/grade-levels/search?q=uniquexyzgrade');

        $response->assertOk();
        $response->assertJson(['gradeLevels' => []]);
    }

    #[Test]
    public function the_section_search_endpoint_requires_an_academic_year_id_and_finds_a_matching_section(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'section' => $section] = $this->graph($school);
        $this->activate($admin, $school);

        $withoutYear = $this->actingAs($admin)->get('/app/communications/audience/sections/search?q=A');
        $withoutYear->assertOk();
        $withoutYear->assertJson(['sections' => []]);

        $withYear = $this->actingAs($admin)->get("/app/communications/audience/sections/search?q=A&academic_year_id={$year->id}");
        $withYear->assertOk();
        $withYear->assertJsonFragment(['id' => $section->id]);
    }

    #[Test]
    public function the_section_search_endpoint_never_returns_a_section_from_a_different_academic_year(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        ['year' => $year, 'grade' => $grade] = $this->graph($school);
        $otherYear = $this->createAcademicYear($school, ['name' => 'Other Year', 'code' => 'AY-OTHER']);
        $otherCampus = $this->createCampus($school);
        $this->createSection($otherYear, $otherCampus, $grade, ['name' => 'OtherYearSection', 'code' => 'OYS']);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get("/app/communications/audience/sections/search?q=OtherYearSection&academic_year_id={$year->id}");

        $response->assertOk();
        $response->assertJson(['sections' => []]);
    }

    #[Test]
    public function a_member_without_communications_announce_is_denied_the_grade_level_search_endpoint(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app/communications/audience/grade-levels/search?q=a')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403_for_the_section_search_endpoint(): void
    {
        $this->get('/app/communications/audience/sections/search?q=a')->assertRedirect('/login');
    }
}
