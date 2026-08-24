<?php

namespace Tests\Feature\App;

use App\Domain\Guardians\Infrastructure\ContactType;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5B.1 -- HTTP-layer coverage for the Student/Guardian audience
 * composer flow and the audience-picker search endpoints. Mirrors
 * AnnouncementHubTest's conventions.
 */
class CommunicationDomainAudienceHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_school_admin_can_draft_and_publish_a_student_audience_announcement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Sports Day',
            'body' => 'Report to the field by 9 AM.',
            'priority' => 'normal',
            'audience_type' => 'student',
            'domain_audience_member_ids' => [$student->id],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->component('App/Communications/Announcements/Show')
            ->where('announcement.audienceType', 'student')
        );

        $this->post($showUrl.'/publish')->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.status', 'published')
            ->where('announcement.recipientCount', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_draft_a_guardian_audience_announcement_and_see_reachability_preview(): void
    {
        Config::set('communications.channels.email.enabled', true);

        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $reachable = $this->createGuardian($school);
        $this->createGuardianContact($reachable, ContactType::Email, 'reachable@example.com');
        $unreachable = $this->createGuardian($school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Fee Reminder',
            'body' => 'Please settle the term fee by Friday.',
            'priority' => 'normal',
            'audience_type' => 'guardian',
            'domain_audience_member_ids' => [$reachable->id, $unreachable->id],
            'channels' => ['in_app', 'email'],
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.audienceType', 'guardian')
            ->where('preview.domain.guardianCount', 2)
            ->where('preview.domain.guardianEmailEligible', 1)
            ->where('preview.domain.guardianEmailUnavailable', 1)
            ->where('preview.domain.inAppReachable', 0)
        );
    }

    #[Test]
    public function a_cross_school_student_id_is_rejected_with_a_clean_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $foreignStudent = $this->createStudent($otherSchool);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'student',
            'domain_audience_member_ids' => [$foreignStudent->id],
        ])->assertSessionHasErrors('domain_audience_member_ids');
    }

    #[Test]
    public function submitting_a_domain_audience_type_without_any_selected_ids_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T',
            'body' => 'B',
            'priority' => 'normal',
            'audience_type' => 'guardian',
            'domain_audience_member_ids' => [],
        ])->assertSessionHasErrors('domain_audience_member_ids');
    }

    // --- §25/§26: search endpoints ------------------------------------

    #[Test]
    public function the_student_search_endpoint_finds_a_same_school_active_student_by_name(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $student = $this->createStudent($school, ['first_name' => 'Zylia', 'last_name' => 'Tan']);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/students/search?q=zylia');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $student->id]);
    }

    #[Test]
    public function the_student_search_endpoint_never_returns_a_cross_school_student(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $otherSchool = $this->createSchool();
        $this->createStudent($otherSchool, ['first_name' => 'Uniquexyz']);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/students/search?q=uniquexyz');

        $response->assertOk();
        $response->assertJson(['students' => []]);
    }

    #[Test]
    public function the_guardian_search_endpoint_finds_a_same_school_active_guardian_by_name(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Wenceslas', 'last_name' => 'Ortiz']);
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/guardians/search?q=wenceslas');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $guardian->id]);
    }

    #[Test]
    public function the_guardian_search_response_never_includes_a_contact_value(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school, ['first_name' => 'Contactcheck']);
        $this->createGuardianContact($guardian, ContactType::Email, 'super-secret@example.com');
        $this->activate($admin, $school);

        $response = $this->actingAs($admin)->get('/app/communications/audience/guardians/search?q=contactcheck');

        $response->assertOk();
        $response->assertDontSee('super-secret', false);
    }

    #[Test]
    public function a_member_without_communications_announce_is_denied_the_student_search_endpoint(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app/communications/audience/students/search?q=a')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403_for_the_guardian_search_endpoint(): void
    {
        $this->get('/app/communications/audience/guardians/search?q=a')->assertRedirect('/login');
    }
}
