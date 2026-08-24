<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Exceptions\NotThreadParticipantException;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.1 §19/§21: the smallest complete internal path (Message ->
 * Recipient -> in_app Delivery), proven end-to-end, plus the
 * idempotency invariant root CLAUDE.md rule 30/brief §21 require.
 */
class CommunicationMessageServiceTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function threadWith(): array
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $recipient = $this->createUser();
        $this->createMembership($recipient, $school);

        $thread = app(CommunicationThreadService::class)->createThread($school, $creator, 'direct', null, [$recipient->id]);

        return [$school, $creator, $recipient, $thread];
    }

    #[Test]
    public function sending_a_message_creates_a_recipient_and_an_in_app_delivery_for_every_other_participant(): void
    {
        [$school, $creator, $recipientUser, $thread] = $this->threadWith();

        $message = app(CommunicationMessageService::class)->send($thread, $creator, 'Hello there');

        $context = app(TenantContext::class);
        [$recipient, $delivery] = $context->withSchool($school, function () use ($message, $recipientUser) {
            $recipient = CommunicationRecipient::query()->where('message_id', $message->id)->where('recipient_user_id', $recipientUser->id)->firstOrFail();
            $delivery = CommunicationDelivery::query()->where('recipient_id', $recipient->id)->firstOrFail();

            return [$recipient, $delivery];
        });

        $this->assertSame('in_app', $delivery->channel);
        // QUEUE_CONNECTION=sync in tests, so ProcessCommunicationDeliveryJob
        // has already run by the time send() returns.
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);

        $attemptCount = $context->withSchool(
            $school,
            fn () => CommunicationDeliveryAttempt::query()->where('communication_delivery_id', $delivery->id)->count(),
        );
        $this->assertSame(1, $attemptCount);
    }

    #[Test]
    public function the_sender_does_not_receive_their_own_message_as_a_recipient(): void
    {
        [$school, $creator, , $thread] = $this->threadWith();

        $message = app(CommunicationMessageService::class)->send($thread, $creator, 'Hello there');

        $senderIsRecipient = app(TenantContext::class)->withSchool(
            $school,
            fn () => CommunicationRecipient::query()->where('message_id', $message->id)->where('recipient_user_id', $creator->id)->exists(),
        );

        $this->assertFalse($senderIsRecipient);
    }

    #[Test]
    public function a_non_participant_cannot_send_a_message_into_a_thread(): void
    {
        [, , , $thread] = $this->threadWith();
        $outsider = $this->createUser();

        $this->expectException(NotThreadParticipantException::class);

        app(CommunicationMessageService::class)->send($thread, $outsider, 'I should not be able to send this');
    }

    #[Test]
    public function repeated_delivery_creation_for_the_same_recipient_and_channel_is_idempotent(): void
    {
        [$school, $creator, $recipientUser, $thread] = $this->threadWith();

        $message = app(CommunicationMessageService::class)->send($thread, $creator, 'First message');

        $context = app(TenantContext::class);
        $recipient = $context->withSchool(
            $school,
            fn () => CommunicationRecipient::query()->where('message_id', $message->id)->where('recipient_user_id', $recipientUser->id)->firstOrFail(),
        );

        // Simulate the exact retry scenario root CLAUDE.md rule 30
        // guards against: calling the private createDelivery() path
        // again for the SAME recipient+channel via the public service
        // API (a second send() call reuses recipients, so instead we
        // exercise the underlying invariant directly: the unique
        // constraint, not a check-then-insert, is what prevents a
        // duplicate row).
        $deliveryCountBefore = $context->withSchool(
            $school,
            fn () => CommunicationDelivery::query()->where('recipient_id', $recipient->id)->count(),
        );
        $this->assertSame(1, $deliveryCountBefore);

        $duplicateRejected = false;
        try {
            // Wrapped in DB::transaction() so PHPUnit's own enclosing
            // test transaction (Tests\TestCase uses DatabaseTransactions)
            // gets a SAVEPOINT here -- otherwise Postgres aborts the
            // whole test transaction on the constraint violation and
            // every assertion query below would also fail.
            $context->withSchool($school, fn () => DB::transaction(function () use ($recipient) {
                CommunicationDelivery::query()->create([
                    'school_id' => $recipient->school_id,
                    'recipient_id' => $recipient->id,
                    'channel' => 'in_app',
                    'status' => 'pending',
                ]);
            }));
        } catch (UniqueConstraintViolationException) {
            $duplicateRejected = true;
        }

        $this->assertTrue($duplicateRejected, 'The database unique constraint must reject a duplicate (recipient_id, channel) delivery.');

        $deliveryCountAfter = $context->withSchool(
            $school,
            fn () => CommunicationDelivery::query()->where('recipient_id', $recipient->id)->count(),
        );
        $this->assertSame(1, $deliveryCountAfter);
    }

    #[Test]
    public function critical_priority_is_accepted_and_persisted(): void
    {
        [, $creator, , $thread] = $this->threadWith();

        $message = app(CommunicationMessageService::class)->send($thread, $creator, 'Evacuate the building', CommunicationPriority::Critical);

        $this->assertSame('critical', $message->priority);
    }
}
