<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * Phase 5A.10 §11/§12/§13/§46/§48 -- HTTP-layer authorization coverage
 * for declaring/publishing an Emergency communication, mirroring
 * AnnouncementHubTest's existing "required" authorization test shape
 * (`communications.manage` there, `communications.emergency` here).
 */
class AnnouncementEmergencyAuthorizationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    /** SR.4 (ADR 0071 §26.7): an Emergency publish -- each request gets a fresh code or current MFA assurance. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->applySensitiveActionMfa($method, $uri, $parameters, $content, '#^/app/communications/announcements/[^/]+/publish$#', null);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function an_emergency_capable_sender_can_declare_and_publish_an_emergency_announcement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
            'requirement' => 'required',
            'dispatch_mode' => 'emergency',
            'emergency_justification' => 'Building evacuation.',
            'emergency_acknowledged' => true,
        ]);
        $showUrl = $create->headers->get('Location');
        $create->assertRedirect();

        $this->get($showUrl)->assertInertia(fn ($page) => $page
            ->where('announcement.dispatchMode', 'emergency')
            ->where('announcement.emergencyJustification', 'Building evacuation.')
        );

        $this->post("{$showUrl}/publish", ['acknowledged' => true])->assertRedirect();
        $this->get($showUrl)->assertInertia(fn ($page) => $page->where('announcement.status', 'published'));
    }

    #[Test]
    public function an_announce_only_sender_cannot_declare_emergency(): void
    {
        // `principal` has communications.announce but not
        // communications.emergency (CapabilityAndRoleSeeder) -- a
        // forged 'dispatch_mode' => 'emergency' payload must fail
        // validation, never silently downgrade or silently succeed.
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
            'requirement' => 'required',
            'dispatch_mode' => 'emergency',
            'emergency_justification' => 'Building evacuation.',
            'emergency_acknowledged' => true,
        ])->assertSessionHasErrors('dispatch_mode');
    }

    #[Test]
    public function the_composer_only_exposes_emergency_when_the_sender_can_dispatch_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);
        $this->get('/app/communications/announcements/create')->assertInertia(fn ($page) => $page
            ->where('canDispatchEmergency', true)
        );

        [$principal, $schoolB] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $schoolB);
        $this->get('/app/communications/announcements/create')->assertInertia(fn ($page) => $page
            ->where('canDispatchEmergency', false)
        );
    }

    #[Test]
    public function an_unauthorized_actor_cannot_publish_someone_elses_emergency_draft_even_with_acknowledgement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        // `principal` is neither the creator nor communications.manage-
        // capable -- forging `acknowledged=true` must not substitute
        // for either the ownership check or the emergency capability.
        $principal = $this->createUser();
        $principalMembership = $this->createMembership($principal, $school);
        $this->assignSchoolRole($principalMembership, 'principal');
        $this->activate($principal, $school);

        $this->post("/app/communications/announcements/{$announcement->id}/publish", ['acknowledged' => true])
            ->assertForbidden();
    }

    #[Test]
    public function school_a_admin_cannot_view_school_bs_emergency_announcement(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $schoolA);
        $announcementA = app(AnnouncementService::class)->createDraft(
            $schoolA, $adminA, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'School A internal reason.',
        );

        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->activate($adminB, $schoolB);

        $this->get("/app/communications/announcements/{$announcementA->id}")->assertNotFound();
    }

    #[Test]
    public function a_users_emergency_authority_in_one_school_does_not_grant_it_in_another(): void
    {
        $user = $this->createUser();

        $schoolA = $this->createSchool();
        $membershipA = $this->createMembership($user, $schoolA);
        $this->assignSchoolRole($membershipA, 'school_admin');

        $schoolB = $this->createSchool();
        $membershipB = $this->createMembership($user, $schoolB);
        $this->assignSchoolRole($membershipB, 'principal');

        $this->activate($user, $schoolA);
        $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
            'requirement' => 'required',
            'dispatch_mode' => 'emergency',
            'emergency_justification' => 'Evacuation.',
            'emergency_acknowledged' => true,
        ])->assertRedirect();

        $this->activate($user, $schoolB);
        $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
            'requirement' => 'required',
            'dispatch_mode' => 'emergency',
            'emergency_justification' => 'Evacuation.',
            'emergency_acknowledged' => true,
        ])->assertSessionHasErrors('dispatch_mode');
    }

    #[Test]
    public function a_standard_publish_never_requires_acknowledgement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        $create = $this->post('/app/communications/announcements', [
            'title' => 'T', 'body' => 'B', 'priority' => 'normal', 'audience_type' => 'school_wide',
        ]);
        $showUrl = $create->headers->get('Location');

        $this->post("{$showUrl}/publish")->assertRedirect();
        $this->get($showUrl)->assertInertia(fn ($page) => $page->where('announcement.status', 'published'));
    }

    #[Test]
    public function publishing_an_emergency_draft_without_acknowledgement_is_rejected(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);
        $this->activate($admin, $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $admin, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            requirement: CommunicationRequirement::Required,
            dispatchMode: CommunicationDispatchMode::Emergency,
            emergencyJustification: 'Evacuation.',
        );

        $this->post("/app/communications/announcements/{$announcement->id}/publish")
            ->assertSessionHasErrors('acknowledged');
    }
}
