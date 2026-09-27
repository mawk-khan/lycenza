<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.3 §8/§38: proves the destination snapshot is genuinely
 * immutable/historical -- a later change to the recipient's own
 * User.email must NEVER retroactively alter an already-created
 * delivery's destination, while a brand new delivery created after
 * the change resolves the NEW address. All addresses use .test
 * domains and Mail::fake() (brief §3).
 */
class EmailDestinationSnapshotTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    #[Test]
    public function a_published_deliverys_destination_survives_a_later_change_to_the_users_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser(['email' => 'old@school-os.test']);
        $this->createMembership($member, $school);

        $firstAnnouncement = $this->service()->createDraft(
            $school, $creator, 'First', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $firstPublished = $this->service()->publish($firstAnnouncement, $creator);

        // The member's canonical email changes AFTER the first
        // Announcement was published.
        $member->update(['email' => 'new@school-os.test']);

        $secondAnnouncement = $this->service()->createDraft(
            $school, $creator, 'Second', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $secondPublished = $this->service()->publish($secondAnnouncement, $creator);

        $context = app(TenantContext::class);

        $firstEmailDelivery = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $firstPublished->message_id)->pluck('id'))
            ->where('channel', 'email')->first());

        $secondEmailDelivery = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $secondPublished->message_id)->pluck('id'))
            ->where('channel', 'email')->first());

        // The historical record still shows the OLD address...
        $this->assertSame('old@school-os.test', $firstEmailDelivery->destination_snapshot['email']);
        // ...even though it is read AFTER the email change happened,
        // and even though the delivery itself is fully terminal.
        $this->assertSame('sent', $firstEmailDelivery->status);

        // ...while the newly created delivery resolves the NEW address.
        $this->assertSame('new@school-os.test', $secondEmailDelivery->destination_snapshot['email']);

        $this->assertEmailAcceptedTo('old@school-os.test');
        $this->assertEmailAcceptedTo('new@school-os.test');
    }
}
