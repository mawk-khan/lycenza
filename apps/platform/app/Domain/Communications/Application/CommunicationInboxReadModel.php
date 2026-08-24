<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5A.8 §10/§11/§31 -- the server-authoritative, bounded, mixed-
 * domain read model behind the Inbox/Unread/Sent surfaces. Composes
 * the SAME authorized queries `CommunicationHubController`/
 * `AnnouncementController` already use -- never a new denormalized
 * inbox table (brief §10: "prefer query/read-model composition
 * first").
 *
 * Mixed-domain pagination tradeoff (brief §31): a true page-2/page-3
 * over a UNION of two structurally different tables (Conversation,
 * Announcement) is fragile to get correct at page boundaries. Instead
 * every method here fetches up to `$limit` of EACH domain, merges,
 * sorts by latest activity, and caps to `$limit` total -- a bounded
 * "Show more" (re-request with a larger `$limit`) rather than true
 * cursor/offset pagination across domains. This is deliberately NOT
 * "the entire communication history" (brief §30) -- it is a capped
 * recent-activity view. `Unread`/`Sent`, which must not silently
 * under-fill just because the first `$limit` raw rows happened to be
 * read/authored-by-someone-else, over-fetch a wider raw pool before
 * filtering+capping (see `OVERFETCH_MULTIPLIER`) -- still bounded, not
 * unbounded, but wide enough that a genuine user's realistic thread/
 * announcement count won't quietly miss items.
 *
 * Single-domain surfaces (Scheduled: reuses AnnouncementController's
 * own `?status=scheduled` filter; Failed: `CommunicationDeliveryReadModel`)
 * do NOT have this problem and use real, correctness-safe
 * `paginate()` instead -- see those classes.
 */
class CommunicationInboxReadModel
{
    private const OVERFETCH_MULTIPLIER = 5;

    private const OVERFETCH_CAP = 200;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ConversationReadModel $conversationReadModel,
    ) {}

    /**
     * Phase 5A.8 §20/§21/§23 -- `$filters` supports `type`
     * ('conversation'|'announcement', restricts to just that domain),
     * `priority` (announcement-only -- a Thread has no single priority
     * of its own, only its individual messages do, so this filter is
     * a documented no-op on the conversation side), and
     * `hasAttachments` (bool). All three are optional and combine with
     * AND semantics. Every value is validated against a fixed
     * allowlist by the caller (CommunicationInboxController) before it
     * ever reaches here -- never a client-controlled SQL fragment.
     *
     * @param  array{type?: ?string, priority?: ?string, hasAttachments?: ?bool}  $filters
     * @return Collection<int, CommunicationInboxItem>
     */
    public function inbox(School $school, User $actor, int $limit = 20, ?string $search = null, array $filters = []): Collection
    {
        $wantConversations = ($filters['type'] ?? null) !== 'announcement';
        $wantAnnouncements = ($filters['type'] ?? null) !== 'conversation';

        return $this->merge(
            $wantConversations ? $this->conversationItems($school, $actor, $limit, $search, unreadOnly: false, sentOnly: false, filters: $filters) : collect(),
            $wantAnnouncements ? $this->announcementItems($school, $actor, $limit, $search, unreadOnly: false, sentOnly: false, filters: $filters) : collect(),
            $limit,
        );
    }

    /**
     * @return Collection<int, CommunicationInboxItem>
     */
    public function unread(School $school, User $actor, int $limit = 20): Collection
    {
        return $this->merge(
            $this->conversationItems($school, $actor, $limit, null, unreadOnly: true, sentOnly: false, filters: []),
            $this->announcementItems($school, $actor, $limit, null, unreadOnly: true, sentOnly: false, filters: []),
            $limit,
        );
    }

    /**
     * @return Collection<int, CommunicationInboxItem>
     */
    public function sent(School $school, User $actor, int $limit = 20): Collection
    {
        return $this->merge(
            $this->conversationItems($school, $actor, $limit, null, unreadOnly: false, sentOnly: true, filters: []),
            $this->announcementItems($school, $actor, $limit, null, unreadOnly: false, sentOnly: true, filters: []),
            $limit,
        );
    }

    /**
     * Phase 5A.8 §15/§23 -- a genuine bounded COUNT aggregate (ONE
     * query, no over-fetch cap needed since it's an aggregate, not a
     * row fetch) for the Hub nav badge -- deliberately NOT built by
     * calling unread() and counting the announcement-typed items,
     * which would waste a whole item-hydration pass just to discard
     * everything but a number.
     */
    public function unreadAnnouncementCount(School $school, User $actor): int
    {
        return $this->context->withSchool($school, function () use ($actor) {
            return CommunicationAnnouncement::query()
                ->where('status', 'published')
                ->whereNotNull('message_id')
                ->whereIn('id', fn ($q) => $q->select('announcement_id')
                    ->from('communication_announcement_recipients')
                    ->where('user_id', $actor->id))
                ->whereExists(function ($q) use ($actor) {
                    $q->selectRaw('1')
                        ->from('communication_recipients as cr')
                        ->join('communication_deliveries as cd', 'cd.recipient_id', '=', 'cr.id')
                        ->whereColumn('cr.message_id', 'communication_announcements.message_id')
                        ->where('cr.recipient_user_id', $actor->id)
                        ->where('cd.channel', 'in_app')
                        ->whereNull('cd.read_at');
                })
                ->count();
        });
    }

    /**
     * Phase 5A.8 §9/§37/§38 -- searchable domains, each independently
     * authorized using the EXACT SAME predicate its own detail page
     * already enforces -- an item invisible through its normal domain
     * access path is invisible here too, by construction (never a
     * separately-derived, potentially-looser search authorization
     * check).
     *
     * @return Collection<int, CommunicationInboxItem>
     */
    public function search(School $school, User $actor, string $query, bool $canManage, bool $canManageTemplates, int $limitPerType = 10): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $conversations = $this->conversationItems($school, $actor, $limitPerType, $query, unreadOnly: false, sentOnly: false, filters: []);
        $announcements = $this->searchAnnouncements($school, $actor, $query, $canManage, $limitPerType);
        $templates = $canManageTemplates ? $this->searchTemplates($school, $query, $limitPerType) : collect();

        return $conversations->concat($announcements)->concat($templates)
            ->sortByDesc(fn (CommunicationInboxItem $item) => $item->latestActivityAt)
            ->values();
    }

    /**
     * @param  array{type?: ?string, priority?: ?string, hasAttachments?: ?bool}  $filters
     * @return Collection<int, CommunicationInboxItem>
     */
    private function conversationItems(School $school, User $actor, int $limit, ?string $search, bool $unreadOnly, bool $sentOnly, array $filters): Collection
    {
        // Brief §23: a Thread has no single priority of its own --
        // only its individual messages do -- so an explicit priority
        // filter excludes conversations entirely rather than silently
        // ignoring the filter or guessing which message's priority to
        // compare against.
        if (($filters['priority'] ?? null) !== null) {
            return collect();
        }

        return $this->context->withSchool($school, function () use ($school, $actor, $limit, $search, $unreadOnly, $sentOnly, $filters) {
            $fetchLimit = $unreadOnly ? min(self::OVERFETCH_CAP, $limit * self::OVERFETCH_MULTIPLIER) : $limit;

            $query = CommunicationThread::query()
                ->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id)
                    ->whereNull('left_at')
                    ->where('archived', false));

            if ($sentOnly) {
                $query->where('created_by_user_id', $actor->id);
            }

            if (($filters['hasAttachments'] ?? null) === true) {
                $query->whereHas('messages', fn ($q) => $q->whereHas('attachments'));
            }

            if ($search !== null && $search !== '') {
                $query->where(fn ($q) => $q->where('subject', 'ilike', "%{$search}%")
                    ->orWhereHas('participants', fn ($q2) => $q2->whereNull('left_at')
                        ->whereHas('user', fn ($q3) => $q3->where('name', 'ilike', "%{$search}%"))));
            }

            $threads = $query
                ->with(['participants' => fn ($q) => $q->whereNull('left_at')->with('user:id,name')])
                ->orderByDesc('last_activity_at')
                ->limit($fetchLimit)
                ->get();

            if ($threads->isEmpty()) {
                return collect();
            }

            $summaries = $this->conversationReadModel->summarize($school, $threads->pluck('id')->all(), $actor->id);

            $items = $threads->map(function (CommunicationThread $thread) use ($actor, $summaries, $sentOnly) {
                $summary = $summaries->get($thread->id);
                $otherParticipants = $thread->participants->filter(fn (CommunicationThreadParticipant $p) => $p->user_id !== $actor->id);
                $title = $thread->subject ?? ($otherParticipants->isNotEmpty()
                    ? $otherParticipants->map(fn (CommunicationThreadParticipant $p) => $p->user->name)->implode(', ')
                    : ($thread->thread_type === 'group' ? 'Group conversation' : 'Direct conversation'));

                $senderName = null;
                if ($summary?->latestMessageSenderId !== null) {
                    $sender = $thread->participants->firstWhere('user_id', $summary->latestMessageSenderId);
                    $senderName = $sender?->user->name ?? ($summary->latestMessageSenderId === $actor->id ? 'You' : null);
                }

                return new CommunicationInboxItem(
                    type: 'conversation',
                    id: $thread->id,
                    title: $title,
                    preview: $summary?->latestMessageBody === null ? null : Str::limit($summary->latestMessageBody, 120),
                    actorName: $senderName,
                    latestActivityAt: $thread->last_activity_at,
                    unread: ! $sentOnly && $summary->unread,
                    priority: null,
                    requirement: null,
                    status: $thread->status,
                    hasAttachments: $summary->hasAttachment,
                    route: "/app/communications/{$thread->id}",
                );
            });

            if ($unreadOnly) {
                $items = $items->filter(fn (CommunicationInboxItem $i) => $i->unread);
            }

            return $items->sortByDesc(fn (CommunicationInboxItem $i) => $i->latestActivityAt)->take($limit)->values();
        });
    }

    /**
     * @param  array{type?: ?string, priority?: ?string, hasAttachments?: ?bool}  $filters
     * @return Collection<int, CommunicationInboxItem>
     */
    private function announcementItems(School $school, User $actor, int $limit, ?string $search, bool $unreadOnly, bool $sentOnly, array $filters): Collection
    {
        return $this->context->withSchool($school, function () use ($actor, $limit, $search, $unreadOnly, $sentOnly, $filters) {
            $fetchLimit = $unreadOnly ? min(self::OVERFETCH_CAP, $limit * self::OVERFETCH_MULTIPLIER) : $limit;

            $query = CommunicationAnnouncement::query()->where('status', 'published');

            if (($filters['priority'] ?? null) !== null) {
                $query->where('priority', $filters['priority']);
            }

            if (($filters['hasAttachments'] ?? null) === true) {
                $query->whereHas('attachments');
            }

            if ($sentOnly) {
                // Brief §16: authored communications only -- never
                // another member's, regardless of capability.
                $query->where('created_by_user_id', $actor->id);
            } else {
                // Brief §12/§40: the actual resolved-recipient snapshot,
                // never "every published announcement in the School."
                $query->whereIn('id', fn ($q) => $q->select('announcement_id')
                    ->from('communication_announcement_recipients')
                    ->where('user_id', $actor->id));
            }

            if ($search !== null && $search !== '') {
                $query->where('title', 'ilike', "%{$search}%");
            }

            $announcements = $query->with('createdBy:id,name')
                ->orderByDesc('published_at')
                ->limit($fetchLimit)
                ->get();

            if ($announcements->isEmpty()) {
                return collect();
            }

            [$readAtByMessage, $messageIdsWithAttachments] = $this->announcementReadAndAttachmentState($announcements, $actor);

            $items = $announcements->map(function (CommunicationAnnouncement $a) use ($readAtByMessage, $messageIdsWithAttachments, $sentOnly) {
                $unread = ! $sentOnly && $a->message_id !== null && $readAtByMessage->get($a->message_id) === null;

                return new CommunicationInboxItem(
                    type: 'announcement',
                    id: $a->id,
                    title: $a->title,
                    preview: Str::limit($a->body, 120),
                    actorName: $a->createdBy?->name,
                    latestActivityAt: $a->published_at,
                    unread: $unread,
                    priority: $a->priority,
                    requirement: $a->requirement,
                    status: $a->status,
                    hasAttachments: $a->message_id !== null && $messageIdsWithAttachments->has($a->message_id),
                    route: "/app/communications/announcements/{$a->id}",
                    isEmergency: $a->dispatch_mode === 'emergency',
                );
            });

            if ($unreadOnly) {
                $items = $items->filter(fn (CommunicationInboxItem $i) => $i->unread);
            }

            return $items->sortByDesc(fn (CommunicationInboxItem $i) => $i->latestActivityAt)->take($limit)->values();
        });
    }

    /**
     * Phase 5A.8 §37/§38/§40 -- search inclusion re-derives the EXACT
     * predicate AnnouncementController::show() already enforces
     * (creator OR resolved recipient OR communications.manage) rather
     * than a second, looser definition.
     *
     * @return Collection<int, CommunicationInboxItem>
     */
    private function searchAnnouncements(School $school, User $actor, string $search, bool $canManage, int $limit): Collection
    {
        return $this->context->withSchool($school, function () use ($actor, $search, $canManage, $limit) {
            $query = CommunicationAnnouncement::query()
                ->where('title', 'ilike', "%{$search}%")
                ->where(function ($q) use ($actor, $canManage) {
                    $q->where('created_by_user_id', $actor->id)
                        ->orWhereIn('id', fn ($sub) => $sub->select('announcement_id')
                            ->from('communication_announcement_recipients')
                            ->where('user_id', $actor->id));

                    if ($canManage) {
                        $q->orWhere('status', 'published');
                    }
                });

            $announcements = $query->with('createdBy:id,name')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            if ($announcements->isEmpty()) {
                return collect();
            }

            [$readAtByMessage, $messageIdsWithAttachments] = $this->announcementReadAndAttachmentState($announcements, $actor);

            return $announcements->map(fn (CommunicationAnnouncement $a) => new CommunicationInboxItem(
                type: 'announcement',
                id: $a->id,
                title: $a->title,
                preview: Str::limit($a->body, 120),
                actorName: $a->createdBy?->name,
                latestActivityAt: $a->published_at ?? $a->created_at,
                unread: $a->message_id !== null && $readAtByMessage->get($a->message_id) === null,
                priority: $a->priority,
                requirement: $a->requirement,
                status: $a->status,
                hasAttachments: $a->message_id !== null && $messageIdsWithAttachments->has($a->message_id),
                route: "/app/communications/announcements/{$a->id}",
                isEmergency: $a->dispatch_mode === 'emergency',
            ));
        });
    }

    /**
     * @return Collection<int, CommunicationInboxItem>
     */
    private function searchTemplates(School $school, string $search, int $limit): Collection
    {
        return $this->context->withSchool($school, function () use ($search, $limit) {
            $templates = CommunicationTemplate::query()
                ->where('name', 'ilike', "%{$search}%")
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();

            return $templates->map(fn ($t) => new CommunicationInboxItem(
                type: 'template',
                id: $t->id,
                title: $t->name,
                preview: $t->description,
                actorName: null,
                latestActivityAt: $t->updated_at,
                unread: false,
                priority: $t->priority,
                requirement: null,
                status: $t->status,
                hasAttachments: false,
                route: "/app/communications/templates/{$t->id}/edit",
            ));
        });
    }

    /**
     * Phase 5A.8 §28/§29 -- exactly 2 batched queries for ANY number
     * of announcements (never one query per announcement): read state
     * (this actor's own IN_APP delivery per message) and attachment
     * presence (per message).
     *
     * @param  Collection<int, CommunicationAnnouncement>  $announcements
     * @return array{0: Collection<string, mixed>, 1: Collection<string, mixed>}
     */
    private function announcementReadAndAttachmentState(Collection $announcements, User $actor): array
    {
        $messageIds = $announcements->pluck('message_id')->filter()->values()->all();

        if ($messageIds === []) {
            return [collect(), collect()];
        }

        $readAtByMessage = DB::table('communication_recipients as cr')
            ->join('communication_deliveries as cd', 'cd.recipient_id', '=', 'cr.id')
            ->where('cr.recipient_user_id', $actor->id)
            ->whereIn('cr.message_id', $messageIds)
            ->where('cd.channel', 'in_app')
            ->pluck('cd.read_at', 'cr.message_id');

        $messageIdsWithAttachments = DB::table('communication_attachments')
            ->whereIn('communication_message_id', $messageIds)
            ->distinct()
            ->pluck('communication_message_id')
            ->flip();

        return [$readAtByMessage, $messageIdsWithAttachments];
    }

    /**
     * @param  Collection<int, CommunicationInboxItem>  $conversations
     * @param  Collection<int, CommunicationInboxItem>  $announcements
     * @return Collection<int, CommunicationInboxItem>
     */
    private function merge(Collection $conversations, Collection $announcements, int $limit): Collection
    {
        return $conversations->concat($announcements)
            ->sortByDesc(fn (CommunicationInboxItem $item) => $item->latestActivityAt)
            ->take($limit)
            ->values();
    }
}
