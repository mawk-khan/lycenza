<?php

namespace Tests\Feature\App;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.11 §28/§30/§57 -- HTTP-layer authorization for the
 * School-wide delivery overview and per-Announcement delivery
 * analytics, both `communications.manage`-gated. Read-only throughout.
 */
class CommunicationAnalyticsHubTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function publishedAnnouncement($school, $creator)
    {
        $this->createMembership($this->createUser(), $school);

        $announcement = app(AnnouncementService::class)->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );

        return app(AnnouncementService::class)->publish($announcement, $creator);
    }

    #[Test]
    public function a_management_capable_actor_can_view_the_school_overview(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->publishedAnnouncement($school, $admin);
        $this->activate($admin, $school);

        $this->get('/app/communications/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('summary')
                ->where('summary.published', 1)
                ->where('range.key', '7d'));
    }

    #[Test]
    public function a_management_capable_actor_can_view_announcement_analytics(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);
        $this->activate($admin, $school);

        $this->get("/app/communications/announcements/{$published->id}/analytics")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('announcement.id', $published->id)
                ->has('summary')
                ->has('deliveries'));
    }

    #[Test]
    public function an_ordinary_recipient_cannot_view_the_school_overview(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($member, $school);

        $this->get('/app/communications/analytics')->assertForbidden();
    }

    #[Test]
    public function an_ordinary_recipient_cannot_view_announcement_analytics(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);

        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($member, $school);

        $this->get("/app/communications/announcements/{$published->id}/analytics")
            ->assertForbidden();
    }

    #[Test]
    public function communications_view_alone_is_not_sufficient_for_analytics(): void
    {
        // `principal` has communications.view but not
        // communications.manage (CapabilityAndRoleSeeder).
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $this->activate($principal, $school);

        $this->get('/app/communications/analytics')->assertForbidden();
    }

    #[Test]
    public function a_cross_school_announcement_id_is_not_found_for_analytics(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $publishedA = $this->publishedAnnouncement($schoolA, $adminA);

        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->activate($adminB, $schoolB);

        $this->get("/app/communications/announcements/{$publishedA->id}/analytics")
            ->assertNotFound();
    }

    #[Test]
    public function a_forged_announcement_id_is_not_found_for_analytics(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/announcements/00000000-0000-0000-0000-000000000000/analytics')
            ->assertNotFound();
    }

    #[Test]
    public function school_bs_counts_never_leak_into_school_as_overview(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $this->activate($adminA, $schoolA);

        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->publishedAnnouncement($schoolB, $adminB);

        $this->get('/app/communications/analytics')
            ->assertInertia(fn ($page) => $page->where('summary.published', 0));
    }

    #[Test]
    public function viewing_analytics_never_mutates_announcement_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);
        $this->activate($admin, $school);

        $this->get("/app/communications/announcements/{$published->id}/analytics")->assertOk();

        $this->get("/app/communications/announcements/{$published->id}")
            ->assertInertia(fn ($page) => $page->where('announcement.status', 'published'));
    }
}
