<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Exceptions\NotThreadParticipantException;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Events\CommunicationMessageCreated;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.1 §2.9 -- the "smallest complete internal communication
 * path" from the brief §19: Message -> Recipient (one per other active
 * participant) -> in_app Delivery, all created in ONE transaction, with
 * delivery processing dispatched only after commit (root CLAUDE.md rule
 * 38/20). No external provider call happens anywhere in this class.
 * Recipient/delivery creation itself is extracted into
 * CommunicationDeliveryFactory (Phase 5A.2) so
 * AnnouncementService::publish() reuses it rather than duplicating it.
 */
class CommunicationMessageService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationDeliveryFactory $deliveryFactory,
    ) {}

    public function send(CommunicationThread $thread, User $sender, string $body, CommunicationPriority $priority = CommunicationPriority::Normal): CommunicationMessage
    {
        return $this->context->withSchool($thread->school, function () use ($thread, $sender, $body, $priority) {
            // The participant check must run INSIDE withSchool(): the
            // thread_participants row is RLS-protected, and this
            // service method may be called without an ambient
            // TenantContext already matching the thread's School (e.g.
            // a queued job or a direct service call from a test).
            $senderParticipant = $thread->participants()
                ->where('user_id', $sender->id)
                ->whereNull('left_at')
                ->first();

            if ($senderParticipant === null) {
                throw new NotThreadParticipantException;
            }

            $deliveryIds = [];

            $message = DB::transaction(function () use ($thread, $sender, $body, $priority, &$deliveryIds) {
                $message = CommunicationMessage::query()->create([
                    'school_id' => $thread->school_id,
                    'thread_id' => $thread->id,
                    'sender_user_id' => $sender->id,
                    'message_type' => 'text',
                    'body' => $body,
                    'priority' => $priority->value,
                    'status' => 'sent',
                ]);

                $recipientUserIds = $thread->participants()
                    ->where('user_id', '!=', $sender->id)
                    ->whereNull('left_at')
                    ->where('muted', false)
                    ->pluck('user_id');

                foreach ($recipientUserIds as $userId) {
                    $recipient = $this->deliveryFactory->createRecipient($thread->school_id, $message->id, $userId);

                    $deliveryIds[] = $this->deliveryFactory->createInAppDelivery($recipient)->id;
                }

                $thread->update(['last_activity_at' => now()]);

                $this->audit->school($thread->school, 'communication.message.created', actor: $sender, subject: $message, metadata: [
                    'threadId' => $thread->id,
                    'priority' => $message->priority,
                    'recipientCount' => count($deliveryIds),
                ]);

                event(new CommunicationMessageCreated(
                    $thread->school_id,
                    $thread->id,
                    $message->id,
                    $sender->id,
                    $message->priority,
                    count($deliveryIds),
                ));

                return $message;
            });

            foreach ($deliveryIds as $deliveryId) {
                ProcessCommunicationDeliveryJob::dispatch($thread->school_id, $deliveryId)
                    ->onQueue(QueueName::Notifications->value)
                    ->afterCommit();
            }

            return $message;
        });
    }
}
