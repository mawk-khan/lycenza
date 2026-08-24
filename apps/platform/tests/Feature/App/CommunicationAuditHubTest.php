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
 * Phase 5A.11 §12/§30/§57 -- HTTP-layer authorization for the
 * Announcement audit timeline (`communications.audit.view`, distinct
 * from `communications.view`/`communications.manage`). Read-only
 * throughout -- see the tests confirming state never changes.
 */
class CommunicationAuditHubTest extends TestCase
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
    public function an_audit_capable_actor_can_view_the_timeline(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);
        $this->activate($admin, $school);

        $this->get("/app/communications/announcements/{$published->id}/audit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('announcement.id', $published->id)
                ->has('entries'));
    }

    #[Test]
    public function communications_view_alone_is_not_sufficient_for_the_audit_timeline(): void
    {
        // `principal` has communications.view but not
        // communications.audit.view (CapabilityAndRoleSeeder).
        [$principal, $school] = $this->createSchoolAdmin('principal');
        $admin = $this->createUser();
        $adminMembership = $this->createMembership($admin, $school);
        $this->assignSchoolRole($adminMembership, 'school_admin');
        $published = $this->publishedAnnouncement($school, $admin);

        $this->activate($principal, $school);

        $this->get("/app/communications/announcements/{$published->id}/audit")
            ->assertForbidden();
    }

    #[Test]
    public function an_ordinary_member_cannot_view_the_audit_timeline(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);

        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->activate($member, $school);

        $this->get("/app/communications/announcements/{$published->id}/audit")
            ->assertForbidden();
    }

    #[Test]
    public function a_cross_school_announcement_id_is_not_found(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $publishedA = $this->publishedAnnouncement($schoolA, $adminA);

        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->activate($adminB, $schoolB);

        $this->get("/app/communications/announcements/{$publishedA->id}/audit")
            ->assertNotFound();
    }

    #[Test]
    public function a_forged_announcement_id_is_not_found(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->get('/app/communications/announcements/00000000-0000-0000-0000-000000000000/audit')
            ->assertNotFound();
    }

    #[Test]
    public function viewing_the_audit_timeline_never_mutates_announcement_state(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $published = $this->publishedAnnouncement($school, $admin);
        $this->activate($admin, $school);

        $this->get("/app/communications/announcements/{$published->id}/audit")->assertOk();

        $this->get("/app/communications/announcements/{$published->id}")
            ->assertInertia(fn ($page) => $page->where('announcement.status', 'published'));
    }
}
