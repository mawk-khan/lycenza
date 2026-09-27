<?php

namespace Tests\Concerns;

use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Infrastructure\GuardianAccountInvitation;
use App\Models\EmailMessage;
use App\Models\School;
use App\Models\User;
use App\Support\Email\EmailPurpose;
use App\Support\Email\EmailSubmissionService;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\RetrySchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/**
 * Phase 0O.9A: email-layer fixtures. Tests that need to control WHEN a
 * message is submitted fake the queue first (holdEmailSubmission()) and
 * then drive EmailSubmissionService themselves.
 */
trait CreatesEmailFixtures
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    protected function holdEmailSubmission(): void
    {
        Queue::fake();
    }

    /** No jitter: every retry delay is exactly the ADR schedule. */
    protected function withoutRetryJitter(): void
    {
        $this->app->instance(RetrySchedule::class, new RetrySchedule(config(), fn (): float => 0.5));
    }

    /**
     * A `school_communication` message for a real Communication delivery in
     * `sending` (as the Communications job leaves it).
     *
     * @return array{0: EmailMessage, 1: CommunicationDelivery}
     */
    protected function queueStandardEmail(School $school, User $sender, string $to = 'someone@school-os.test', string $subject = 'Term update', string $text = 'Hello families.'): array
    {
        $recipientUser = $this->createUser();
        $this->createMembership($recipientUser, $school);
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender);
        $recipient = $this->createRecipient($message, $recipientUser);
        $delivery = $this->createDelivery($recipient, ['channel' => 'email', 'status' => 'sending', 'destination_snapshot' => ['email' => $to]]);

        $email = $this->inSchool($school, fn () => app(OutboundEmailGateway::class)->queue(
            school: $school,
            purpose: EmailPurpose::SchoolCommunication,
            sourceId: $delivery->id,
            recipient: $to,
            subject: $subject,
            text: $text,
        ));

        return [$email, $delivery];
    }

    /**
     * A real Guardian invitation and its `account_invitation` message.
     *
     * @return array{0: EmailMessage, 1: GuardianAccountInvitation}
     */
    protected function queueInvitationEmail(School $school, User $admin, string $to = 'guardian@example.com'): array
    {
        $guardian = $this->createGuardian($school);
        $this->createGuardianContact($guardian, ContactType::Email, $to);
        $invitation = app(AccountInvitationService::class)->invite($school, $guardian, $admin);
        $email = $this->inSchool($school, fn () => app(OutboundEmailGateway::class)->forSource('guardian_account_invitation', $invitation->id));

        return [$email, $invitation];
    }

    protected function submitEmail(School $school, string $messageId): EmailMessage
    {
        return $this->inSchool($school, function () use ($messageId) {
            app(EmailSubmissionService::class)->process($messageId);

            return EmailMessage::query()->findOrFail($messageId);
        });
    }

    protected function emailRow(School $school, string $messageId): EmailMessage
    {
        return $this->inSchool($school, fn () => EmailMessage::query()->findOrFail($messageId));
    }

    /** Make a waiting message due now (tests travel instead where timing matters). */
    protected function makeDue(School $school, string $messageId): void
    {
        $this->inSchool($school, fn () => EmailMessage::query()->whereKey($messageId)->update(['next_attempt_at' => now()]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }
}
