<?php

namespace Tests\Feature\Communications\Channels;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Channels\EmailChannelDriver;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5A.3 §36: EmailChannelDriver in isolation -- every test uses
 * Mail::fake() (brief §3: "local/test verification must send zero
 * real external emails"), which replaces the mailer with a fake that
 * performs no I/O regardless of the configured transport, so these
 * tests are safe even if COMMUNICATION_EMAIL_MAILER pointed at a real
 * provider mailer name by mistake.
 */
class EmailChannelDriverTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    #[Test]
    public function the_email_driver_is_registered_for_the_email_channel(): void
    {
        $registry = app(CommunicationChannelRegistry::class);

        $this->assertTrue($registry->has(CommunicationChannel::Email));
        $this->assertInstanceOf(EmailChannelDriver::class, $registry->driver(CommunicationChannel::Email));
    }

    #[Test]
    public function the_driver_refuses_to_send_when_the_channel_is_disabled(): void
    {
        Config::set('communications.channels.email.enabled', false);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser(['email' => 'recipient@school-os.test']);
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'recipient@school-os.test'],
        ]);

        $result = app(EmailChannelDriver::class)->send($delivery);

        $this->assertFalse($result->success);
        $this->assertSame('email_channel_disabled', $result->failureCode);
        $this->assertFalse($result->retryable);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function the_driver_fails_deterministically_and_non_retryably_when_no_destination_email_was_snapshotted(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, ['channel' => 'email', 'destination_snapshot' => null]);

        $result = app(EmailChannelDriver::class)->send($delivery);

        $this->assertFalse($result->success);
        $this->assertSame('recipient_email_missing', $result->failureCode);
        $this->assertFalse($result->retryable);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function the_driver_fails_deterministically_when_the_snapshotted_email_is_not_a_valid_address(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'not-an-email'],
        ]);

        $result = app(EmailChannelDriver::class)->send($delivery);

        $this->assertFalse($result->success);
        $this->assertSame('recipient_email_invalid', $result->failureCode);
        $this->assertFalse($result->retryable);
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function the_driver_sends_to_the_delivery_destination_snapshot_not_the_recipients_live_email(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser(['email' => 'live-current-address@school-os.test']);
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        // Deliberately a DIFFERENT address than the user's current live
        // email -- proves the driver reads the snapshot, never
        // re-resolves the User row (brief §8/§38).
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'snapshotted-address@school-os.test'],
        ]);

        // The driver is always reached via App\Jobs\ProcessCommunicationDeliveryJob
        // in production, which sets TenantContext before calling it --
        // relation traversal inside send() (delivery->recipient->message)
        // is RLS-scoped, so this test must do the same.
        $result = app(TenantContext::class)->withSchool($school, fn () => app(EmailChannelDriver::class)->send($delivery));

        $this->assertTrue($result->success);
        $this->assertSame('accepted', $result->status);
        $this->assertEmailAcceptedTo('snapshotted-address@school-os.test');
        $this->assertEmailNotAcceptedTo('live-current-address@school-os.test');
    }

    #[Test]
    public function a_successful_hand_off_returns_accepted_never_sent_or_delivered(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        [$sender, $school] = $this->createSchoolAdmin('school_admin');
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, [
            'channel' => 'email',
            'destination_snapshot' => ['email' => 'someone@school-os.test'],
        ]);

        $result = app(TenantContext::class)->withSchool($school, fn () => app(EmailChannelDriver::class)->send($delivery));

        // Brief §19, Phase 0O.9A (ADR 0055 section 9.5): the driver only HANDS
        // the delivery to the email layer -- `accepted`. SENT (the provider
        // accepted it) and DELIVERED (provider evidence) come later, from the
        // email layer's own state, never from the driver.
        $this->assertSame('accepted', $result->status);
        $this->assertNotContains($result->status, ['sent', 'delivered']);
    }
}
