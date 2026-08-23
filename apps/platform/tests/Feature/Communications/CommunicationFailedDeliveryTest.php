<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationDeliveryFailureReadModel;
use App\Domain\Communications\Application\Policy\CommunicationPreferenceService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.8 §18/§19/§41/§50 -- the Failed-delivery operational
 * surface: real FAILED deliveries only (never policy SUPPRESSED),
 * communications.manage-gated, cross-School isolated.
 */
class CommunicationFailedDeliveryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function failureReadModel(): CommunicationDeliveryFailureReadModel
    {
        return app(CommunicationDeliveryFailureReadModel::class);
    }

    private function activate($user, $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function an_announcement_with_a_real_failed_delivery_appears(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        // No usable email -- EmailChannelDriver fails this delivery
        // deterministically (recipient_email_missing/invalid), a real
        // attempted-and-failed delivery.
        $this->createMembership($this->createUser(['email' => 'not-a-valid-email']), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Failing Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $paginated = $this->failureReadModel()->failedAnnouncements($school);
        $this->assertTrue(collect($paginated->items())->contains(fn ($a) => $a->id === $published->id));

        $breakdown = $this->failureReadModel()->failureBreakdown($school, [$published->id]);
        $this->assertArrayHasKey($published->id, $breakdown);
        $this->assertSame('email', $breakdown[$published->id][0]['channel']);
    }

    #[Test]
    public function a_successful_delivery_never_appears_as_failed(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Successful Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $paginated = $this->failureReadModel()->failedAnnouncements($school);

        $this->assertFalse(collect($paginated->items())->contains(fn ($a) => $a->id === $published->id));
    }

    /**
     * Brief §19: a policy-suppressed channel never created a
     * `communication_deliveries` row at all -- it must never be
     * mislabeled as a failure here.
     */
    #[Test]
    public function a_policy_suppressed_channel_is_not_mislabeled_as_failed(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['email' => 'member@school-os.test']);
        $membership = $this->createMembership($member, $school);

        // Recipient opts out of optional email -- a suppression
        // decision, never an attempted delivery.
        app(CommunicationPreferenceService::class)
            ->setPreference($membership, $creator, CommunicationChannel::Email, false);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'Suppressed Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $published = $this->announcements()->publish($announcement, $creator);

        $paginated = $this->failureReadModel()->failedAnnouncements($school);

        $this->assertFalse(collect($paginated->items())->contains(fn ($a) => $a->id === $published->id));
    }

    #[Test]
    public function an_ordinary_member_cannot_view_the_failed_surface(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($member, $school);

        $this->actingAs($member)->get('/app/communications/failed')->assertForbidden();
    }

    #[Test]
    public function communications_manage_can_view_the_failed_surface(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($admin, $school);

        $this->actingAs($admin)->get('/app/communications/failed')->assertOk();
    }

    #[Test]
    public function school_a_cannot_see_school_bs_failed_deliveries(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'still-not-valid']), $schoolB);

        $announcementB = $this->announcements()->createDraft(
            $schoolB, $creatorB, 'School B Failing Notice', 'Body', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $publishedB = $this->announcements()->publish($announcementB, $creatorB);

        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');

        $paginated = $this->failureReadModel()->failedAnnouncements($schoolA);

        $this->assertFalse(collect($paginated->items())->contains(fn ($a) => $a->id === $publishedB->id));
    }
}
