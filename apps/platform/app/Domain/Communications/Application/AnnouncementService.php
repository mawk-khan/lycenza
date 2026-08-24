<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Audience\CommunicationAudienceResolverRegistry;
use App\Domain\Communications\Application\Audience\ResolvedAudience;
use App\Domain\Communications\Application\Channels\EmailAddressResolver;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\EmergencyCannotBeScheduledException;
use App\Domain\Communications\Application\Exceptions\EmergencyJustificationRequiredException;
use App\Domain\Communications\Application\Exceptions\EmergencyMustBeRequiredException;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\InvalidAudienceMemberException;
use App\Domain\Communications\Application\Exceptions\InvalidScheduledTimeException;
use App\Domain\Communications\Application\Exceptions\UnsupportedAnnouncementChannelException;
use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Application\Policy\CommunicationTimingReason;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Events\CommunicationAnnouncementCancelled;
use App\Domain\Communications\Events\CommunicationAnnouncementCreated;
use App\Domain\Communications\Events\CommunicationAnnouncementPublished;
use App\Domain\Communications\Events\CommunicationAnnouncementScheduled;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAudienceMember;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementChannel;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryPolicyDecision;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * Delivery channels an Announcement draft may explicitly request
     * (brief §16) -- deliberately narrower than the full
     * CommunicationChannel enum (which already reserves Sms/WhatsApp/
     * Push cases for a later checkpoint); requesting anything else
     * throws UnsupportedAnnouncementChannelException before ever
     * reaching the communication_announcement_channels_channel_check
     * database constraint.
     */
    private const SUPPORTED_CHANNELS = [CommunicationChannel::InApp, CommunicationChannel::Email];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationAudienceResolverRegistry $audienceResolvers,
        private readonly CommunicationDeliveryFactory $deliveryFactory,
        private readonly EmailAddressResolver $emailAddressResolver,
        private readonly CommunicationChannelPolicyService $channelPolicy,
        private readonly CommunicationDeliveryTimingPolicyService $timingPolicy,
        private readonly CommunicationApprovalService $approvalService,
    ) {}

    /**
     * @param  array<int, string>  $individualMemberUserIds  only used when $audienceType is Individual
     * @param  array<int, CommunicationChannel>  $channels  in_app is always included regardless of what's passed (brief §15)
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
        array $channels = [],
        ?string $sourceTemplateId = null,
        CommunicationRequirement $requirement = CommunicationRequirement::Optional,
        CommunicationDispatchMode $dispatchMode = CommunicationDispatchMode::Standard,
        ?string $emergencyJustification = null,
    ): CommunicationAnnouncement {
        // Phase 5A.10 §6/§23: validated BEFORE any write, independent
        // of whatever the controller already validated -- an
        // Application-layer caller (including a direct service test)
        // gets the same guarantee an HTTP caller does. Authorization
        // (whether this $creator may even set Emergency at all) is the
        // CALLER's responsibility -- App\Domain\Communications\Http\Controllers\AnnouncementController
        // -- exactly like `communications.announce` itself is never
        // re-checked in this service.
        $this->assertValidDispatchMode($dispatchMode, $requirement, $emergencyJustification);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $creator, $title, $body, $priority, $audienceType, $individualMemberUserIds, $campus, $channels, $sourceTemplateId, $requirement, $dispatchMode, $emergencyJustification) {
            $isEmergency = $dispatchMode === CommunicationDispatchMode::Emergency;

            $announcement = CommunicationAnnouncement::query()->create([
                'school_id' => $school->id,
                'campus_id' => $campus?->id,
                'created_by_user_id' => $creator->id,
                'title' => $title,
                'body' => $body,
                'priority' => $priority->value,
                'status' => 'draft',
                'audience_type' => $audienceType->value,
                'source_template_id' => $sourceTemplateId,
                'requirement' => $requirement->value,
                'dispatch_mode' => $dispatchMode->value,
                'emergency_justification' => $isEmergency ? $emergencyJustification : null,
                'emergency_declared_by_user_id' => $isEmergency ? $creator->id : null,
                'emergency_declared_at' => $isEmergency ? now() : null,
            ]);

            if ($audienceType === CommunicationAudienceType::Individual) {
                $this->syncAudienceMembers($announcement, $individualMemberUserIds);
            }

            $this->syncChannels($announcement, $channels);

            $this->audit->school($school, 'announcement.created', actor: $creator, subject: $announcement, metadata: [
                'audienceType' => $audienceType->value,
            ]);

            if ($isEmergency) {
                $this->auditEmergencyDeclared($announcement, $creator, $requirement, $emergencyJustification);
            }

            event(new CommunicationAnnouncementCreated($school->id, $announcement->id, $audienceType->value, $creator->id));

            return $announcement->fresh();
        }));
    }

    /**
     * @param  array<int, string>|null  $individualMemberUserIds  null leaves the current member list untouched
     * @param  array<int, CommunicationChannel>|null  $channels  null leaves the current channel selection untouched
     */
    /**
     * @param  array<int, string>|null  $individualMemberUserIds  null leaves the current member list untouched
     * @param  array<int, CommunicationChannel>|null  $channels  null leaves the current channel selection untouched
     */
    public function updateDraft(
        CommunicationAnnouncement $announcement,
        User $actor,
        ?string $title = null,
        ?string $body = null,
        ?CommunicationPriority $priority = null,
        ?array $individualMemberUserIds = null,
        ?array $channels = null,
        ?CommunicationRequirement $requirement = null,
        ?CommunicationDispatchMode $dispatchMode = null,
        ?string $emergencyJustification = null,
    ): CommunicationAnnouncement {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $title, $body, $priority, $individualMemberUserIds, $channels, $requirement, $dispatchMode, $emergencyJustification) {
            // Phase 5A.4 §31: a SCHEDULED announcement remains editable
            // up until it is claimed for publication -- the exact same
            // content-editing rules as a draft (its own canonical
            // content, never its source template).
            if (! $announcement->isEditable()) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'edit');
            }

            // Phase 5A.10 §6/§27/§28: validated against the EFFECTIVE
            // combination -- whichever value this call is changing, or
            // else the announcement's current stored value -- so an
            // edit to an unrelated field (e.g. title) on an
            // already-Emergency draft can never silently leave it in
            // an invalid state, and setting Emergency on an already-
            // SCHEDULED announcement is rejected the same way
            // schedule() itself rejects scheduling an Emergency draft.
            $effectiveRequirement = $requirement ?? $announcement->requirementEnum();
            $effectiveDispatchMode = $dispatchMode ?? $announcement->dispatchModeEnum();
            $effectiveJustification = $dispatchMode !== null ? $emergencyJustification : $announcement->emergency_justification;

            if ($effectiveDispatchMode === CommunicationDispatchMode::Emergency && ! $announcement->isDraft()) {
                throw new EmergencyCannotBeScheduledException;
            }

            $this->assertValidDispatchMode($effectiveDispatchMode, $effectiveRequirement, $effectiveJustification);

            return DB::transaction(function () use ($announcement, $actor, $title, $body, $priority, $individualMemberUserIds, $channels, $requirement, $dispatchMode, $emergencyJustification, $effectiveRequirement) {
                $updates = array_filter([
                    'title' => $title,
                    'body' => $body,
                    'priority' => $priority?->value,
                    'requirement' => $requirement?->value,
                ], fn ($value) => $value !== null);

                // Phase 5A.12 §36: editing a REJECTED announcement is
                // exactly what re-enters it into the normal Draft
                // editing cycle (brief §36's "Rejected -> Draft/Edit ->
                // Submit new approval request") -- there is no active
                // approval to invalidate for a rejected request (it was
                // never granted), so this is a plain status reset, not
                // an invalidation event.
                if ($announcement->isRejected()) {
                    $updates['status'] = 'draft';
                }

                $becameEmergency = false;

                if ($dispatchMode !== null) {
                    $updates['dispatch_mode'] = $dispatchMode->value;

                    if ($dispatchMode === CommunicationDispatchMode::Emergency) {
                        $becameEmergency = true;
                        $updates['emergency_justification'] = $emergencyJustification;
                        $updates['emergency_declared_by_user_id'] = $actor->id;
                        $updates['emergency_declared_at'] = now();
                    } else {
                        // Brief §27: removing Emergency also clears its
                        // justification/declaration metadata.
                        $updates['emergency_justification'] = null;
                        $updates['emergency_declared_by_user_id'] = null;
                        $updates['emergency_declared_at'] = null;
                    }
                }

                $announcement->update($updates);

                if ($individualMemberUserIds !== null && $announcement->audienceTypeEnum() === CommunicationAudienceType::Individual) {
                    $this->syncAudienceMembers($announcement, $individualMemberUserIds);
                }

                if ($channels !== null) {
                    $this->syncChannels($announcement, $channels);
                }

                $this->audit->school($announcement->school, 'announcement.updated', actor: $actor, subject: $announcement);

                if ($becameEmergency) {
                    $this->auditEmergencyDeclared($announcement, $actor, $effectiveRequirement, $emergencyJustification);
                }

                // Phase 5A.12 §35: an approval-sensitive edit to an
                // APPROVED (or already-SCHEDULED-via-approval)
                // announcement invalidates its approval immediately and
                // returns it to Draft, requiring resubmission -- no
                // semantic-diff heuristics (brief §35), the fingerprint
                // recomputed from THIS updated state is compared
                // byte-for-byte against the active request's stored
                // one. A no-op, single indexed lookup for the
                // overwhelming majority of Schools that never use
                // approval (brief §8's safe default).
                $this->approvalService->invalidateIfFingerprintChanged($announcement, $actor);

                return $announcement->fresh();
            });
        });
    }

    public function cancel(CommunicationAnnouncement $announcement, User $actor): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            // Brief §15/§25: DRAFT -> CANCELLED and SCHEDULED -> CANCELLED
            // are both valid; a scheduled cancellation racing the
            // scheduler's own claim is resolved by this SAME
            // conditional UPDATE -- if the scheduler already flipped
            // the row to `published`, this WHERE matches 0 rows and
            // cancellation is correctly rejected (brief §25: "a
            // cancellation racing with scheduler execution must have
            // deterministic behavior").
            $wasScheduled = $announcement->isScheduled();

            $claimed = CommunicationAnnouncement::query()
                ->where('id', $announcement->id)
                ->whereIn('status', ['draft', 'scheduled'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $fresh = $announcement->fresh();

            if ($claimed === 0) {
                if ($fresh->status === 'cancelled') {
                    return $fresh;
                }

                throw new InvalidAnnouncementTransitionException($fresh->status, 'cancel');
            }

            $eventType = $wasScheduled ? 'announcement.schedule_cancelled' : 'announcement.cancelled';
            $this->audit->school($announcement->school, $eventType, actor: $actor, subject: $fresh);
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

    /**
     * Brief §21: the SAME atomic claim serves both a manual "Publish
     * Now" (draft) and a scheduler-driven due publication (scheduled +
     * due) -- App\Console\Commands\PublishScheduledAnnouncements never
     * claims anything itself; it just calls this method for each due
     * candidate ID and relies entirely on THIS conditional UPDATE for
     * concurrency safety (brief §22: two overlapping scheduler runs
     * calling publish() for the same announcement -- only one's UPDATE
     * matches a row, the other sees $claimed === 0 and, since the
     * winner already committed `published`, returns the fresh state as
     * a no-op, identical to the existing manual-republish idempotency
     * proof). A crash/exception between claim and commit rolls back
     * the WHOLE transaction -- a scheduled announcement whose audience
     * resolves empty reverts all the way to `scheduled`, never stuck
     * in a half-published state (brief §23 -- see
     * App\Console\Commands\PublishScheduledAnnouncements for what
     * happens next).
     */
    public function publish(CommunicationAnnouncement $announcement, User $actor): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $deliveryIds = [];

            $result = DB::transaction(function () use ($announcement, $actor, &$deliveryIds) {
                // Phase 5A.12 §32/§33/§52/§62/§82/§83: evaluated FRESH,
                // inside this same transaction, immediately before the
                // atomic claim below -- never trusts a possibly-stale
                // `$announcement` the caller already held. `approved`
                // is only ever a valid claim source when a currently-
                // matching-fingerprint approval genuinely exists
                // (App\Domain\Communications\Application\Approval\CommunicationApprovalService::currentlyApprovedAndValid());
                // `draft` is only a valid claim source when the
                // School's CURRENT policy does not require approval for
                // this announcement. Emergency is exempt entirely
                // (brief §10/§40) -- its own capability/acknowledgement
                // gate already governs it in
                // App\Domain\Communications\Http\Controllers\AnnouncementController::publish().
                $approvedSourceAllowed = ! $announcement->isEmergency()
                    && $this->approvalService->currentlyApprovedAndValid($announcement);

                $draftSourceAllowed = $announcement->isEmergency() || $approvedSourceAllowed
                    || ! $this->approvalService->requirement($announcement)->required;

                $claimed = CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where(function ($query) use ($approvedSourceAllowed, $draftSourceAllowed) {
                        $query->where(function ($query) {
                            $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
                        });

                        if ($approvedSourceAllowed) {
                            $query->orWhere('status', 'approved');
                        }

                        if ($draftSourceAllowed) {
                            $query->orWhere('status', 'draft');
                        }
                    })
                    ->update(['status' => 'published', 'published_at' => now()]);

                $fresh = $announcement->fresh();

                if ($claimed === 0) {
                    if ($fresh->status === 'published') {
                        // Idempotent replay (brief §18) -- already
                        // published by an earlier call, nothing more
                        // to do, no new deliveries dispatched.
                        return $fresh;
                    }

                    if (($fresh->status === 'draft' && ! $draftSourceAllowed)
                        || ($fresh->status === 'approved' && ! $approvedSourceAllowed)) {
                        throw new ApprovalRequiredException;
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

                // Phase 5A.6 §6/§25: backfills the message-rendering FK
                // on every attachment already associated with this
                // Announcement -- the SAME row (same checksum, same
                // storage_path) simply gains a second FK; nothing is
                // re-uploaded or re-copied, which is exactly what makes
                // the "scheduled attachment snapshot" invariant hold
                // for free (there is no later step that could pick up
                // a newer version of anything).
                CommunicationAttachment::query()
                    ->where('communication_announcement_id', $fresh->id)
                    ->update(['communication_message_id' => $message->id]);

                $requestedChannels = $this->requestedChannels($fresh);
                $requirement = $fresh->requirementEnum();
                // Phase 5A.10 §9/§21: read from the ALREADY-PERSISTED,
                // already-validated announcement row -- never re-derived
                // from priority/requirement/anything else. This is the
                // ONE place `publish()` learns whether emergency
                // quiet-hours bypass may even be CONSIDERED; the School's
                // own per-channel `emergency_bypass_allowed` setting
                // (brief §15) still decides whether it actually applies.
                $emergencyBypassRequested = $fresh->isEmergency();

                // Phase 5A.9 -- evaluated ONCE per requested channel for
                // this whole publish() call, not once per recipient
                // (brief §60: "one policy lookup -> evaluate many
                // deliveries"). The decision (SEND_NOW, or DEFER until a
                // fixed UTC instant) is identical for every recipient of
                // a given channel at a given `now`, so there is no
                // reason to re-evaluate it inside the recipient loop
                // below. IN_APP always resolves to SEND_NOW without any
                // query (brief §5).
                $now = now();
                $timingDecisions = [];
                foreach ($requestedChannels as $channel) {
                    $timingDecisions[$channel->value] = $this->timingPolicy->evaluate($fresh->school, $channel, $now, $emergencyBypassRequested);
                }

                // Phase 5A.10 §16/§24/§44: one audit event per BYPASSED
                // channel (never per recipient -- brief §24's own
                // "avoid one generic audit event per recipient" -- the
                // timing decision is already per-channel, not
                // per-recipient, so this is the natural, non-duplicated
                // granularity), giving an operator durable, traceable
                // evidence that quiet hours were bypassed and why.
                foreach ($timingDecisions as $channelValue => $decision) {
                    if ($decision->reason === CommunicationTimingReason::EmergencyQuietHoursBypass) {
                        $this->audit->school($fresh->school, 'communication.emergency_quiet_hours_bypass_used', actor: $actor, subject: $fresh, metadata: [
                            'channel' => $channelValue,
                        ]);
                    }
                }

                foreach (array_chunk($resolved->userIds, self::CHUNK_SIZE) as $chunk) {
                    // Reused for BOTH the historical snapshot AND the
                    // policy engine's eligibility/preference lookups
                    // (brief §55) -- never a second membership query.
                    $memberships = $this->snapshotRecipients($fresh, $chunk);

                    // One batch User lookup per chunk (never per-recipient
                    // N+1) purely to resolve email destinations -- brief
                    // §19's chunking discipline applies here exactly like
                    // snapshotRecipients()/audience resolution already do.
                    $users = in_array(CommunicationChannel::Email, $requestedChannels, true)
                        ? User::query()->whereIn('id', $chunk)->get(['id', 'email'])->keyBy('id')
                        : null;

                    if (in_array(CommunicationChannel::Email, $requestedChannels, true)) {
                        $this->channelPolicy->preloadPreferences(
                            $fresh->school,
                            $memberships->pluck('id')->all(),
                            CommunicationChannel::Email,
                        );
                    }

                    foreach ($chunk as $userId) {
                        $membershipId = $memberships->get($userId)?->id;
                        $recipient = null;

                        foreach ($requestedChannels as $channel) {
                            $decision = $this->channelPolicy->evaluate($fresh->school, $membershipId, $channel, $requirement);

                            if (! $decision->allowed) {
                                CommunicationDeliveryPolicyDecision::query()->create([
                                    'school_id' => $fresh->school_id,
                                    'message_id' => $message->id,
                                    'recipient_user_id' => $userId,
                                    'channel' => $channel->value,
                                    'reason' => $decision->reason->value,
                                ]);

                                continue;
                            }

                            // Created lazily, only once ALLOW is known for
                            // at least one channel -- a fully ineligible
                            // recipient (every channel suppressed) never
                            // gets a CommunicationRecipient row at all,
                            // matching the historical snapshot's own
                            // exclusion above rather than being
                            // inconsistent with it.
                            $recipient ??= $this->deliveryFactory->createRecipient($fresh->school_id, $message->id, $userId);

                            $timingDecision = $timingDecisions[$channel->value];

                            $delivery = $this->deliveryFactory->createDelivery(
                                $recipient,
                                $channel,
                                $channel === CommunicationChannel::Email
                                    ? $this->emailDestinationSnapshot($users?->get($userId))
                                    : null,
                                $timingDecision->shouldDefer ? $timingDecision->availableAt : null,
                            );

                            // Phase 5A.9 §22/§23: a deferred delivery is
                            // never dispatched to the queue now -- it
                            // already carries the `queued` status +
                            // `next_attempt_at` App\Console\Commands\
                            // RedispatchDueCommunicationDeliveries scans
                            // for, and will be picked up once due,
                            // exactly like a retry backoff already is.
                            if (! $timingDecision->shouldDefer) {
                                $deliveryIds[] = $delivery->id;
                            }
                        }
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

                if ($emergencyBypassRequested) {
                    $this->audit->school($fresh->school, 'announcement.emergency_published', actor: $actor, subject: $fresh, metadata: [
                        'requestedChannels' => array_map(fn (CommunicationChannel $c) => $c->value, $requestedChannels),
                        'resolvedCount' => count($resolved->userIds),
                    ]);
                }

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
     * Brief §16/§17: does NOT resolve or persist a recipient snapshot
     * -- only the intended audience DEFINITION (already stored on the
     * draft via audience_type/audience members/channels) plus the
     * schedule metadata. The authoritative snapshot is produced later,
     * at due-publication time, by this same class's publish() --
     * reused unchanged, not reimplemented.
     */
    public function schedule(CommunicationAnnouncement $announcement, User $actor, Carbon $scheduledAtUtc): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $scheduledAtUtc) {
            if ($scheduledAtUtc->isPast()) {
                throw new InvalidScheduledTimeException;
            }

            // Phase 5A.10 §28: Emergency is an immediate exceptional
            // dispatch mode -- it never enters the normal scheduled
            // state. A future scheduled advisory must remain STANDARD.
            if ($announcement->isEmergency()) {
                throw new EmergencyCannotBeScheduledException;
            }

            return DB::transaction(function () use ($announcement, $actor, $scheduledAtUtc) {
                // Phase 5A.12 §34/§52: the same approval gate
                // publish() applies -- 'approved' is a valid claim
                // source only with a currently-matching fingerprint;
                // 'draft' only when current policy does not require
                // approval for this announcement. Reschedule() (a
                // SEPARATE method, unchanged) never touches this gate
                // at all -- brief §34's "schedule time is operational
                // timing, not message meaning."
                $approvedSourceAllowed = $this->approvalService->currentlyApprovedAndValid($announcement);
                $draftSourceAllowed = $approvedSourceAllowed || ! $this->approvalService->requirement($announcement)->required;

                $claimed = CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where(function ($query) use ($approvedSourceAllowed, $draftSourceAllowed) {
                        // Defensive base clause: if NEITHER source is
                        // currently permitted, this must match zero
                        // rows -- never fall through to an unqualified
                        // group that would match ANY status.
                        $query->whereRaw('1 = 0');

                        if ($approvedSourceAllowed) {
                            $query->orWhere('status', 'approved');
                        }

                        if ($draftSourceAllowed) {
                            $query->orWhere('status', 'draft');
                        }
                    })
                    ->update([
                        'status' => 'scheduled',
                        'scheduled_at' => $scheduledAtUtc,
                        'scheduled_by_user_id' => $actor->id,
                    ]);

                $fresh = $announcement->fresh();

                if ($claimed === 0) {
                    if (($fresh->status === 'draft' && ! $draftSourceAllowed)
                        || ($fresh->status === 'approved' && ! $approvedSourceAllowed)) {
                        throw new ApprovalRequiredException;
                    }

                    throw new InvalidAnnouncementTransitionException($fresh->status, 'schedule');
                }

                $this->audit->school($announcement->school, 'announcement.scheduled', actor: $actor, subject: $fresh, metadata: [
                    'scheduledAt' => $scheduledAtUtc->toIso8601String(),
                ]);

                event(new CommunicationAnnouncementScheduled(
                    $announcement->school_id,
                    $announcement->id,
                    $actor->id,
                    $scheduledAtUtc->toIso8601String(),
                ));

                return $fresh;
            });
        });
    }

    /**
     * Brief §24: changes the schedule time of an already-SCHEDULED
     * announcement. Race-safe against a concurrent scheduler claim via
     * the same "conditional UPDATE ... WHERE status = 'scheduled'"
     * discipline as every other transition here -- if the scheduler
     * already published it (status is no longer 'scheduled' by the
     * time this UPDATE runs), $claimed is 0 and this throws rather
     * than silently rewriting a schedule that no longer applies to
     * anything.
     */
    public function reschedule(CommunicationAnnouncement $announcement, User $actor, Carbon $newScheduledAtUtc): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor, $newScheduledAtUtc) {
            if ($newScheduledAtUtc->isPast()) {
                throw new InvalidScheduledTimeException;
            }

            return DB::transaction(function () use ($announcement, $actor, $newScheduledAtUtc) {
                $previousScheduledAt = $announcement->scheduled_at;

                $claimed = CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where('status', 'scheduled')
                    ->update(['scheduled_at' => $newScheduledAtUtc]);

                $fresh = $announcement->fresh();

                if ($claimed === 0) {
                    throw new InvalidAnnouncementTransitionException($fresh->status, 'reschedule');
                }

                $this->audit->school($announcement->school, 'announcement.rescheduled', actor: $actor, subject: $fresh, metadata: [
                    'previousScheduledAt' => $previousScheduledAt?->toIso8601String(),
                    'newScheduledAt' => $newScheduledAtUtc->toIso8601String(),
                ]);

                return $fresh;
            });
        });
    }

    /**
     * Phase 5A.8 §14 -- announcement in-app read state. Reuses the
     * `communication_deliveries.read_at`/`status='read'` column and
     * enum value already reserved by Phase 5A.1's schema (see that
     * table's migration) but never written by any code path until now
     * -- the narrowest possible addition, not a new column. Scoped to
     * the CALLING actor's own IN_APP delivery row only (found via
     * their own `CommunicationRecipient` row for this announcement's
     * message) -- never any other recipient's, and a no-op for a
     * non-recipient (nothing to mark) or an unpublished announcement
     * (no message/recipients exist yet). Only promotes a 'delivered'
     * IN_APP row to 'read' -- never touches a 'failed'/other terminal
     * status. Deliberately NOT audited, matching
     * CommunicationThreadService::markRead()'s exact reasoning
     * (Phase 5A.7): per-viewer UI convenience state, not a fact worth
     * a permanent audit trail entry. In-app read state is distinct
     * from email delivery/open status -- this never touches the EMAIL
     * channel's own delivery row.
     */
    public function markRead(CommunicationAnnouncement $announcement, User $actor): void
    {
        if ($announcement->message_id === null) {
            return;
        }

        $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $recipient = CommunicationRecipient::query()
                ->where('message_id', $announcement->message_id)
                ->where('recipient_user_id', $actor->id)
                ->first();

            if ($recipient === null) {
                return;
            }

            $now = now();

            $recipient->deliveries()
                ->where('channel', CommunicationChannel::InApp->value)
                ->where('status', 'delivered')
                ->update(['status' => 'read', 'read_at' => $now]);
        });
    }

    /**
     * Phase 5A.10 §6: the ONE place the `EMERGENCY requires REQUIRED`
     * and `EMERGENCY requires a justification` invariants are checked
     * -- called from both createDraft() and updateDraft() against
     * whichever combination of values is EFFECTIVE for that call, so
     * neither entry point can independently drift from the other.
     */
    private function assertValidDispatchMode(
        CommunicationDispatchMode $dispatchMode,
        CommunicationRequirement $requirement,
        ?string $justification,
    ): void {
        if ($dispatchMode !== CommunicationDispatchMode::Emergency) {
            return;
        }

        if ($requirement !== CommunicationRequirement::Required) {
            throw new EmergencyMustBeRequiredException;
        }

        if ($justification === null || trim($justification) === '') {
            throw new EmergencyJustificationRequiredException;
        }
    }

    /**
     * Phase 5A.10 §24/§25: a dedicated, distinct audit event from
     * `announcement.created`/`announcement.updated` -- "who explicitly
     * declared this Emergency, and when, with what justification" must
     * be answerable from the audit trail alone, not reconstructed from
     * mutable current announcement state. Never logs recipient
     * addresses or message body -- only the announcement's own id and
     * the operational justification text (tenant-owned, restricted to
     * `communications.audit.view`, never a generic application log).
     */
    private function auditEmergencyDeclared(
        CommunicationAnnouncement $announcement,
        User $actor,
        CommunicationRequirement $requirement,
        ?string $justification,
    ): void {
        $this->audit->school($announcement->school, 'announcement.emergency_declared', actor: $actor, subject: $announcement, metadata: [
            'requirement' => $requirement->value,
            'justification' => $justification,
        ]);
    }

    /**
     * @param  array<int, string>  $userIds
     * @return Collection<string, SchoolMembership> user_id => active membership, reused by publish() for
     *                                              eligibility + policy preference batching (brief §55) so this is not
     *                                              a second, duplicate membership query.
     */
    private function snapshotRecipients(CommunicationAnnouncement $announcement, array $userIds): Collection
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

        return $memberships;
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

    /**
     * @return array<int, CommunicationChannel>
     */
    private function requestedChannels(CommunicationAnnouncement $announcement): array
    {
        $channels = $announcement->requestedChannels()->pluck('channel')
            ->map(fn (string $value) => CommunicationChannel::from($value))
            ->all();

        // Defensive fallback only -- createDraft() always writes at
        // least an `in_app` row (§15), so this branch exists for
        // robustness, not as the normal path.
        return $channels === [] ? [CommunicationChannel::InApp] : $channels;
    }

    /**
     * @return array{email: string}|null
     */
    private function emailDestinationSnapshot(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $email = $this->emailAddressResolver->resolve($user);

        return $email === null ? null : ['email' => $email];
    }

    /**
     * @param  array<int, CommunicationChannel>  $channels
     */
    private function syncChannels(CommunicationAnnouncement $announcement, array $channels): void
    {
        foreach ($channels as $channel) {
            if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
                throw new UnsupportedAnnouncementChannelException($channel->value);
            }
        }

        // In-app is always included regardless of what was passed --
        // brief §15: never let an Announcement end up with zero
        // delivery channels, and IN_APP remains the one channel every
        // Announcement can always rely on.
        $values = array_unique(array_map(
            fn (CommunicationChannel $c) => $c->value,
            [...$channels, CommunicationChannel::InApp],
        ));

        CommunicationAnnouncementChannel::query()->where('announcement_id', $announcement->id)->delete();

        $now = now();
        $rows = array_map(fn (string $channel) => [
            'id' => (string) new UuidV7,
            'school_id' => $announcement->school_id,
            'announcement_id' => $announcement->id,
            'channel' => $channel,
            'created_at' => $now,
            'updated_at' => $now,
        ], $values);

        CommunicationAnnouncementChannel::query()->insert($rows);
    }
}
