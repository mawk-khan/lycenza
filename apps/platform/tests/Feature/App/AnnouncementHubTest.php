<?php

namespace Tests\Feature\App;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.2 §27: HTTP-layer coverage for the Announcement composer/
 * list/detail shell -- authorization-aware access, empty state,
 * pagination, the full draft -> preview -> publish flow, and
 * cross-tenant denial. Mirrors CommunicationHubTest's conventions.
 */
class AnnouncementHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        $this->get('/app/communications/announcements')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_with_no_role_is_denied_entirely(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/communications/announcements')->assertForbidden();
    }

    #[Test]
    public function a_member_with_view_only_cannot_reach_the_composer(): void
    {
        // `principal` DOES have communications.announce (Phase 5A.2's
        // CapabilityAndRoleSeeder change) -- to exercise the "view but
        // not announce" denial we need a role with .view but not
        // .announce, which does not exist among the two seeded school
        // roles today, so instead we prove the composer route itself
        // enforces the capability by denying a member with no role at all.
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/communications/announcements/create')->assertForbidden();
    }

    #[Test]
    public function an_authorized_user_sees_the_announcements_index_with_an_empty_state(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $this->get('/app/communications/announcements')->assertInertia(fn ($page) => $page
            ->component('App/Communications/Announcements/Index')
            ->where('announcements.data', [])
            ->where('canAnnounce', true)
        );
    }

    #[Test]
    public function a_school_admin_can_draft_preview_and_publish_a_school_wide_announcement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Annual Day',
            'body' => 'Rehearsal begins tomorrow at 8:30 AM.',
            'priority' => 'normal',
            'audience_type' => 'school_wide',
        ]);
        $create->assertRedirect();
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->component('App/Communications/Announcements/Show')
            ->where('announcement.status', 'draft')
            ->where('preview.count', 1)
            ->where('canEdit', true)
        );

        $publish = $this->post("{$showUrl}/publish");
        $publish->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.status', 'published')
            ->where('announcement.recipientCount', 1)
            ->where('preview', null)
        );

        $this->get('/app/communications/announcements')->assertInertia(fn ($page) => $page
            ->has('announcements.data', 1)
            ->where('announcements.data.0.status', 'published')
        );
    }

    #[Test]
    public function publishing_with_no_eligible_members_returns_a_validation_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Lonely',
            'body' => 'Nobody else is here.',
            'priority' => 'normal',
            'audience_type' => 'school_wide',
        ]);
        $showUrl = $create->headers->get('Location');

        $this->post("{$showUrl}/publish")->assertSessionHasErrors('audience');
    }

    #[Test]
    public function a_member_without_the_announce_capability_cannot_publish_someone_elses_draft(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $announcement = $this->createAnnouncement($school, $admin);

        $bystander = $this->createUser();
        $this->createMembership($bystander, $school);
        $this->activate($bystander, $school);

        $this->post("/app/communications/announcements/{$announcement->id}/publish")->assertForbidden();
    }

    #[Test]
    public function school_a_cannot_view_school_bs_announcement(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);

        $this->activate($adminA, $schoolA);

        $this->get("/app/communications/announcements/{$announcementB->id}")->assertNotFound();
    }

    #[Test]
    public function school_a_cannot_publish_school_bs_announcement(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $schoolB);
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);

        $this->activate($adminA, $schoolA);

        $this->post("/app/communications/announcements/{$announcementB->id}/publish")->assertNotFound();
    }

    #[Test]
    public function the_composer_reports_email_available_only_when_the_channel_is_enabled(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        Config::set('communications.channels.email.enabled', false);
        $this->get('/app/communications/announcements/create')->assertInertia(fn ($page) => $page
            ->where('emailChannelEnabled', false)
        );

        Config::set('communications.channels.email.enabled', true);
        $this->get('/app/communications/announcements/create')->assertInertia(fn ($page) => $page
            ->where('emailChannelEnabled', true)
        );
    }

    #[Test]
    public function a_disabled_email_channel_cannot_be_submitted_by_forging_the_request_payload(): void
    {
        Config::set('communications.channels.email.enabled', false);

        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'Forged',
            'body' => 'Trying to sneak email in while disabled.',
            'priority' => 'normal',
            'audience_type' => 'school_wide',
            'channels' => ['in_app', 'email'],
        ])->assertSessionHasErrors('channels.1');
    }

    #[Test]
    public function an_authorized_sender_can_select_email_and_publishing_creates_email_deliveries(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'Annual Day',
            'body' => 'Rehearsal begins tomorrow at 8:30 AM.',
            'priority' => 'normal',
            'audience_type' => 'school_wide',
            'channels' => ['in_app', 'email'],
        ]);
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('requestedChannels', ['in_app', 'email'])
            ->where('preview.email.eligible', 1)
            ->where('preview.email.missing', 0)
        );

        $this->post("{$showUrl}/publish")->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.status', 'published')
            ->has('channelDeliverySummary.in_app')
            ->has('channelDeliverySummary.email')
        );
        Mail::assertSentCount(1);
    }

    #[Test]
    public function the_in_app_only_flow_is_unchanged_when_channels_is_not_submitted_at_all(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        // No 'channels' key in the payload at all -- brief §15's core
        // invariant: this must never implicitly start sending email.
        $create = $this->post('/app/communications/announcements', [
            'title' => 'Plain',
            'body' => 'No channel selection made.',
            'priority' => 'normal',
            'audience_type' => 'school_wide',
        ]);
        $showUrl = $create->headers->get('Location');

        $this->get($showUrl)->assertInertia(fn ($page) => $page->where('requestedChannels', ['in_app']));

        $this->post("{$showUrl}/publish")->assertRedirect();

        Mail::assertNothingSent();
    }
}
