<?php

namespace Tests\Feature\App;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.1 §18/§25: HTTP-layer coverage for the Communication Hub
 * shell -- authorization-aware access, empty state, the full compose ->
 * view -> reply flow, and cross-tenant denial. Mirrors
 * tests/Feature/App/SchoolSettingsTest.php's session-auth conventions.
 */
class CommunicationHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        $this->get('/app/communications')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_with_no_role_is_denied_entirely(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->activate($user, $school);

        $this->get('/app/communications')->assertForbidden();
    }

    #[Test]
    public function an_authorized_user_sees_the_hub_with_an_empty_state(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Index')
            ->where('threads', [])
            ->where('canSend', true)
        );
    }

    #[Test]
    public function a_school_admin_can_start_a_thread_and_send_a_message_and_see_it_listed(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $teacher = $this->createUser();
        $this->createMembership($teacher, $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications', [
            'subject' => 'Field trip',
            'thread_type' => 'direct',
            'participant_user_ids' => [$teacher->id],
        ]);
        $create->assertRedirect();
        $threadUrl = $create->headers->get('Location');

        $this->post("{$threadUrl}/messages", ['body' => 'Are we still on for Friday?']);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Index')
            ->has('threads', 1)
        );

        $this->get($threadUrl)->assertInertia(fn ($page) => $page
            ->component('App/Communications/Show')
            ->has('messages', 1)
            ->where('messages.0.body', 'Are we still on for Friday?')
        );
    }

    #[Test]
    public function a_principal_can_view_and_send_but_a_bystander_principal_cannot_read_a_thread_they_are_not_in(): void
    {
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->get('/app/communications')->assertInertia(fn ($page) => $page->where('canSend', true));

        // `principal` was seeded with communications.view/.send/.reply
        // but deliberately NOT communications.manage (CapabilityAndRoleSeeder) --
        // so a principal who isn't a participant still cannot read someone
        // else's thread.
        $otherSchoolAdmin = $this->createUser();
        $membership = $this->createMembership($otherSchoolAdmin, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $thread = $this->createThread($school, $otherSchoolAdmin);
        $this->createParticipant($thread, $otherSchoolAdmin);

        $this->get("/app/communications/{$thread->id}")->assertForbidden();
    }

    #[Test]
    public function a_member_who_is_not_a_thread_participant_and_cannot_manage_is_denied(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $thread = $this->createThread($school, $admin);
        $this->createParticipant($thread, $admin);

        // A different active member with the `principal` role (has
        // `communications.view` but NOT `communications.manage`) and
        // not a participant of this thread must be denied read access.
        $bystander = $this->createUser();
        $membership = $this->createMembership($bystander, $school);
        $this->assignSchoolRole($membership, 'principal');

        $this->activate($bystander, $school);

        $this->get("/app/communications/{$thread->id}")->assertForbidden();
    }

    #[Test]
    public function school_a_cannot_view_school_bs_thread(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$senderB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $threadB = $this->createThread($schoolB, $senderB);
        $this->createParticipant($threadB, $senderB);

        $this->activate($adminA, $schoolA);

        $this->get("/app/communications/{$threadB->id}")->assertNotFound();
    }
}
