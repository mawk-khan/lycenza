<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Channels\CommunicationMail;
use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.6 §28/§29/§45 -- EMAIL channel attachment behavior.
 * Mail::fake() throughout -- no real transport is ever exercised, and
 * COMMUNICATION_EMAIL_ENABLED is explicitly flipped on per-test via
 * Config::set(), never globally, matching AnnouncementEmailDeliveryTest's
 * own convention.
 */
class AnnouncementAttachmentEmailDeliveryTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function announcements(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function attachments(): CommunicationAttachmentService
    {
        return app(CommunicationAttachmentService::class);
    }

    #[Test]
    public function an_email_delivery_includes_the_attachment_when_within_the_configured_email_size_limit(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.attachments.email_max_total_size_mb', 5);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 500, 'application/pdf'));

        $published = $this->announcements()->publish($announcement, $creator);

        Mail::assertSent(CommunicationMail::class, fn (CommunicationMail $mail) => count($mail->attachments()) === 1);

        $context = app(TenantContext::class);
        $emailDelivery = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->where('channel', 'email')->first());
        $this->assertSame('sent', $emailDelivery->status);

        // Brief §51: no storage disk/path/key ever leaks into a
        // durable delivery record.
        $this->assertArrayNotHasKey('storage_path', $emailDelivery->destination_snapshot ?? []);
        $this->assertArrayNotHasKey('disk', $emailDelivery->destination_snapshot ?? []);
    }

    #[Test]
    public function an_oversized_attachment_fails_only_the_email_channel_while_in_app_still_succeeds(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Config::set('communications.attachments.max_file_size_mb', 20);
        Config::set('communications.attachments.max_total_size_mb', 20);
        Config::set('communications.attachments.email_max_total_size_mb', 1);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        // 2 MB -- within the canonical per-message limit but over the
        // 1 MB EMAIL transport threshold configured above.
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'));

        $published = $this->announcements()->publish($announcement, $creator);

        Mail::assertNothingSent();

        $context = app(TenantContext::class);
        $deliveries = $context->withSchool($school, fn () => CommunicationDelivery::query()
            ->whereIn('recipient_id', CommunicationRecipient::query()->where('message_id', $published->message_id)->pluck('id'))
            ->get(['channel', 'status', 'failure_code']));

        $inApp = $deliveries->firstWhere('channel', 'in_app');
        $this->assertSame('delivered', $inApp->status);

        $email = $deliveries->firstWhere('channel', 'email');
        $this->assertSame('failed', $email->status);
        $this->assertSame('attachment_email_size_exceeded', $email->failure_code);

        // Brief §29: the Announcement itself is still fully published.
        $this->assertSame('published', $published->status);
    }

    #[Test]
    public function an_in_app_only_announcement_with_an_attachment_never_sends_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->announcements()->publish($announcement, $creator);

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_global_email_gate_disabled_sends_no_mail_even_with_an_attachment(): void
    {
        Config::set('communications.channels.email.enabled', false);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);

        // Only in_app is REQUESTABLE while the gate is disabled (brief
        // §17 of Phase 5A.3 -- 'email' is rejected by composer
        // validation entirely), so this exercises the same safety
        // path AnnouncementEmailDeliveryTest already covers, now with
        // an attachment present.
        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $this->announcements()->publish($announcement, $creator);

        Mail::assertNothingSent();
    }

    #[Test]
    public function republishing_does_not_duplicate_the_attachment_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        Mail::fake();

        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $this->createMembership($this->createUser(['email' => 'member@school-os.test']), $school);

        $announcement = $this->announcements()->createDraft(
            $school, $creator, 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp, CommunicationChannel::Email],
        );
        $this->attachments()->upload($announcement, $creator, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));

        $first = $this->announcements()->publish($announcement, $creator);
        $this->announcements()->publish($first, $creator);

        Mail::assertSentCount(1);
    }
}
