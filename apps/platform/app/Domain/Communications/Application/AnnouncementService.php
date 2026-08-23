<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Audience\CommunicationAudienceResolverRegistry;
use App\Domain\Communications\Application\Audience\ResolvedAudience;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidAudienceMemberException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Events\CommunicationAnnouncementCancelled;
use App\Domain\Communications\Events\CommunicationAnnouncementCreated;
use App\Domain\Communications\Events\CommunicationAnnouncementPublished;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAudienceMember;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 5A.2 §16/§17 -- the sole write path for the Announcement
 * lifecycle, mirroring CommunicationThreadService/CommunicationMessageService's
 * shape (validate -> write -> audit -> emit event, one transaction).
 *
 * `publish()` is the checkpoint's centerpiece: Audience Definition ->
 * Audience Resolver -> Resolved Recipient Snapshot -> Communication
 * Message -> Logical Recipients -> Channel Deliveries -> existing
 * delivery pipeline (brief §5/§16), reusing
 * App\Domain\Communications\Application\CommunicationDeliveryFactory
 * rather than a second delivery system.
 *
 * Idempotent publish (brief §18) is guaranteed by ONE atomic claim -- a
 * conditional `UPDATE ... WHERE status = 'draft'` -- executed FIRST,
 * inside the same DB::transaction() as everything that follows. A
 * second publish() call for an already-published Announcement affects
 * 0 rows and returns the existing state as a no-op; any exception
 * thrown after a successful claim (including EmptyAudienceException)
 * rolls back the WHOLE transaction, claim included, so the Announcement
 * reverts to 'draft' rather than getting stuck in a broken state
 * (brief §17). This is the same "atomic claim before any side effect"
 * discipline App\Jobs\ProcessCommunicationDeliveryJob::claim() and
 * App\Jobs\DeliverWebhookJob::claim() already established.
 */
class AnnouncementService
{
    /**
     * Bounds a single publish transaction's batch size (brief §19).
     * Large-audience true bulk/async dispatch is deferred -- see
     * docs/communication-hub/PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md.
     */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationAudienceResolverRegistry $audienceResolvers,
        private readonly CommunicationDeliveryFactory $deliveryFactory,
    ) {}

    /**
     * @param  array<int, string>  $individualMemberUserIds  only used when $audienceType is Individual
     */
    public function createDraft(
        School $school,
        User $creator,
        string $title,
        string $body,
        CommunicationPriority $priority,
        CommunicationAudienceType $audienceType,
        array $individualMemberUserIds = [],
        ?Campus $campus = null,
    ): CommunicationAnnouncement {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $creator, $title, $body, $priority, $audienceType, $individualMemberUserIds, $campus) {
            $announcement = CommunicationAnnouncement::query()->create([
                'school_id' => $school->id,
                'campus_id' => $campus?->id,
                'created_by_user_id' => $creator->id,
                'title' => $title,
                'body' => $body,
                'priority' => $priority->value,
                'status' => 'draft',
                'audience_type' => $audienceType->value,
            ]);

            if ($audienceType === CommunicationAudienceType::Individual) {
                $this->syncAudienceMembers($announcement, $individualMemberUserIds);
            }

            $this->audit->school($school, 'announcement.created', actor: $creator, subject: $announcement, metadata: [
                'audienceType' => $audienceType->value,
            ]);

            event(new CommunicationAnnouncementCreated($school->id, $announcement->id, $audienceType->value, $creator->id));

            return $announcement->fresh();
        }));
    }

    /**
     * @param  array<int, string>|null  $individualMemberUserIds  null leaves the current member list untouched
     */
    public function updateDraft(
        CommunicationAnnouncement $announcement,
        User $actor,
        ?string $title = null,
        ?string $body = null,
        ?CommunicationPriority $priority = null,
        ?array $individualMemberUserIds = null,
    ): CommunicationAnnouncement {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $title, $body, $priority, $individualMemberUserIds) {
            if (! $announcement->isDraft()) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'edit');
            }

            return DB::transaction(function () use ($announcement, $actor, $title, $body, $priority, $individualMemberUserIds) {
                $announcement->update(array_filter([
                    'title' => $title,
                    'body' => $body,
                    'priority' => $priority?->value,
                ], fn ($value) => $value !== null));

                if ($individualMemberUserIds !== null && $announcement->audienceTypeEnum() === CommunicationAudienceType::Individual) {
                    $this->syncAudienceMembers($announcement, $individualMemberUserIds);
                }

                $this->audit->school($announcement->school, 'announcement.updated', actor: $actor, subject: $announcement);

                return $announcement->fresh();
            });
        });
    }

    public function cancel(CommunicationAnnouncement $announcement, User $actor): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $claimed = CommunicationAnnouncement::query()
                ->where('id', $announcement->id)
                ->where('status', 'draft')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $fresh = $announcement->fresh();

            if ($claimed === 0) {
                if ($fresh->status === 'cancelled') {
                    return $fresh;
                }

                throw new InvalidAnnouncementTransitionException($fresh->status, 'cancel');
            }

            $this->audit->school($announcement->school, 'announcement.cancelled', actor: $actor, subject: $fresh);
            event(new CommunicationAnnouncementCancelled($announcement->school_id, $announcement->id, $actor->id));

            return $fresh;
        });
    }

    /**
     * Read-only -- resolves the SAME way publish() would but persists
     * nothing (brief §14: "the definitive recipient snapshot must
     * still be produced during publish/send transaction so stale
     * preview data does not become authoritative").
     */
    public function previewAudience(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool(
            $announcement->school,
            fn () => $this->audienceResolvers->resolve($announcement),
        );
    }

    public function publish(CommunicationAnnouncement $announcement, User $actor): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $deliveryIds = [];

            $result = DB::transaction(function () use ($announcement, $actor, &$deliveryIds) {
                $claimed = CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where('status', 'draft')
                    ->update(['status' => 'published', 'published_at' => now()]);

                $fresh = $announcement->fresh();

                if ($claimed === 0) {
                    if ($fresh->status === 'published') {
                        // Idempotent replay (brief §18) -- already
                        // published by an earlier call, nothing more
                        // to do, no new deliveries dispatched.
                        return $fresh;
                    }

                    throw new InvalidAnnouncementTransitionException($fresh->status, 'publish');
                }

                $resolved = $this->audienceResolvers->resolve($fresh);

                if ($resolved->isEmpty()) {
                    throw new EmptyAudienceException;
                }

                $message = CommunicationMessage::query()->create([
                    'school_id' => $fresh->school_id,
                    'thread_id' => null,
                    'announcement_id' => $fresh->id,
                    'sender_user_id' => $fresh->created_by_user_id,
                    'message_type' => 'text',
                    'body' => $fresh->body,
                    'priority' => $fresh->priority,
                    'status' => 'sent',
                ]);

                foreach (array_chunk($resolved->userIds, self::CHUNK_SIZE) as $chunk) {
                    $this->snapshotRecipients($fresh, $chunk);

                    foreach ($chunk as $userId) {
                        $recipient = $this->deliveryFactory->createRecipient($fresh->school_id, $message->id, $userId);
                        $deliveryIds[] = $this->deliveryFactory->createInAppDelivery($recipient)->id;
                    }
                }

                $fresh->update([
                    'message_id' => $message->id,
                    'recipient_count' => count($resolved->userIds),
                ]);

                $this->audit->school($fresh->school, 'announcement.published', actor: $actor, subject: $fresh, metadata: [
                    'audienceType' => $fresh->audience_type,
                    'resolvedCount' => count($resolved->userIds),
                ]);

                event(new CommunicationAnnouncementPublished(
                    $fresh->school_id,
                    $fresh->id,
                    $message->id,
                    $fresh->audience_type,
                    count($resolved->userIds),
                    $actor->id,
                ));

                return $fresh->fresh();
            });

            foreach ($deliveryIds as $deliveryId) {
                ProcessCommunicationDeliveryJob::dispatch($result->school_id, $deliveryId)
                    ->onQueue(QueueName::Notifications->value)
                    ->afterCommit();
            }

            return $result;
        });
    }

    /**
     * @param  array<int, string>  $userIds
     */
    private function snapshotRecipients(CommunicationAnnouncement $announcement, array $userIds): void
    {
        $memberships = SchoolMembership::query()
            ->where('school_id', $announcement->school_id)
            ->whereIn('user_id', $userIds)
            ->active()
            ->get(['id', 'user_id'])
            ->keyBy('user_id');

        $now = now();
        $rows = [];

        foreach ($userIds as $userId) {
            $membership = $memberships->get($userId);

            if ($membership === null) {
                // Resolved a moment ago, no longer an active member --
                // skip rather than fail the whole publish (§13).
                continue;
            }

            $rows[] = [
                'id' => (string) new UuidV7,
                'school_id' => $announcement->school_id,
                'announcement_id' => $announcement->id,
                'school_membership_id' => $membership->id,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            CommunicationAnnouncementRecipient::query()->insert($rows);
        }
    }

    /**
     * @param  array<int, string>  $userIds
     */
    private function syncAudienceMembers(CommunicationAnnouncement $announcement, array $userIds): void
    {
        $userIds = array_values(array_unique($userIds));

        $memberships = SchoolMembership::query()
            ->where('school_id', $announcement->school_id)
            ->whereIn('user_id', $userIds)
            ->active()
            ->get(['id', 'user_id'])
            ->keyBy('user_id');

        if ($memberships->count() !== count($userIds)) {
            throw new InvalidAudienceMemberException;
        }

        CommunicationAnnouncementAudienceMember::query()->where('announcement_id', $announcement->id)->delete();

        $now = now();
        $rows = $memberships->map(fn (SchoolMembership $membership) => [
            'id' => (string) new UuidV7,
            'school_id' => $announcement->school_id,
            'announcement_id' => $announcement->id,
            'school_membership_id' => $membership->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        if ($rows !== []) {
            CommunicationAnnouncementAudienceMember::query()->insert($rows);
        }
    }
}
