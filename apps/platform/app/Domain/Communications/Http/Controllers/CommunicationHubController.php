<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Application\ConversationThreadSummary;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Http\Controllers\Controller;
use App\Models\SchoolMembership;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.1 §18/§19 -- the Communication Hub shell: session-
 * authenticated Inertia pages under /app/communications, following
 * App\Http\Controllers\App\SchoolSetupController's exact convention
 * (TenantContext::requireSchool() -> authorizeCapability() ->
 * Inertia::render()/redirect()). Controllers stay thin -- every write
 * delegates to CommunicationThreadService/CommunicationMessageService.
 *
 * Phase 5A.7 completes this: pagination, unread/read-cursor state
 * (App\Domain\Communications\Application\ConversationReadModel),
 * server-authoritative participant search, and per-participant
 * archive -- all still going through the same two services, never a
 * controller-constructed row.
 */
class CommunicationHubController extends Controller
{
    use AuthorizesCapability;

    /**
     * Phase 5A.8 §33: relocated from the Hub's root (`GET /app/communications`,
     * now the operational Inbox --
     * App\Domain\Communications\Http\Controllers\CommunicationInboxController)
     * to `GET /app/communications/conversations`. Behavior is
     * unchanged from Phase 5A.7 -- same query, same pagination, same
     * filters, same unread badge.
     */
    public function conversations(TenantContext $context, ConversationReadModel $readModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $showArchived = $request->boolean('archived');
        $search = trim((string) $request->string('q'));

        $query = CommunicationThread::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id)
                ->whereNull('left_at')
                ->where('archived', $showArchived))
            ->with(['createdBy:id,name', 'participants' => fn ($q) => $q->whereNull('left_at')->with('user:id,name')]);

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('subject', 'ilike', "%{$search}%")
                ->orWhereHas('participants', fn ($q2) => $q2->whereNull('left_at')
                    ->whereHas('user', fn ($q3) => $q3->where('name', 'ilike', "%{$search}%"))));
        }

        $threads = $query->orderByDesc('last_activity_at')->paginate(20)->withQueryString();

        $summaries = $readModel->summarize($school, $threads->pluck('id')->all(), $actor->id);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);

        return Inertia::render('App/Communications/Conversations', [
            'threads' => $threads->through(fn (CommunicationThread $t) => $this->presentThreadSummary($t, $actor->id, $summaries->get($t->id))),
            'meta' => [
                'currentPage' => $threads->currentPage(),
                'lastPage' => $threads->lastPage(),
                'total' => $threads->total(),
            ],
            'filters' => ['archived' => $showArchived, 'q' => $search ?: null],
            // Phase 5A.8: the SAME combined (conversations + announcements)
            // total this page's sidebar shares with every other Hub
            // page -- see CommunicationInboxController::navFlags()'s
            // docblock for why this bundle is duplicated, not shared,
            // across the two controllers.
            'totalUnreadCount' => $readModel->totalUnreadCount($school, $actor->id) + app(CommunicationInboxReadModel::class)->unreadAnnouncementCount($school, $actor),
            'canSend' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.send', $school),
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.announce', $school),
            'canManage' => $canManage,
            // Phase 5A.5 §26: channel-policy administration is gated
            // the same as thread/participant management -- reused, not
            // a new capability.
            'canManageChannelPolicy' => $canManage,
            'canManageTemplates' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.templates.manage', $school),
            'canApprove' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.approve', $school),
        ]);
    }

    /**
     * Phase 5A.7 §9/§10 -- the server-authoritative eligible-
     * participant query for the compose picker. Same-school, active
     * memberships only, excludes the current actor, never leaks
     * cross-school membership existence (App\Models\SchoolMembership
     * carries no RLS of its own -- see its docblock -- so this
     * explicitly filters `school_id` itself rather than relying on
     * one). Bounded (limit 20) and search-driven rather than dumping
     * every membership to the browser.
     */
    public function searchParticipants(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.send', $school);
        $actor = $context->actor();

        $q = trim((string) $request->string('q'));

        $members = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->active()
            ->where('user_id', '!=', $actor->id)
            ->whereHas('user', fn ($query) => $q === '' ? $query : $query->where('name', 'ilike', "%{$q}%"))
            ->with('user:id,name')
            ->orderBy('user_id')
            ->limit(20)
            ->get();

        return response()->json([
            'participants' => $members->map(fn (SchoolMembership $m) => [
                'userId' => $m->user_id,
                'name' => $m->user->name,
            ])->values(),
        ]);
    }

    public function store(Request $request, TenantContext $context, CommunicationThreadService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.send', $school);

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'thread_type' => ['required', 'in:direct,group'],
            'participant_user_ids' => ['required', 'array', 'min:1'],
            'participant_user_ids.*' => ['string'],
        ]);

        try {
            $thread = $service->createThread(
                $school,
                $context->actor(),
                $validated['thread_type'],
                $validated['subject'] ?? null,
                $validated['participant_user_ids'],
            );
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['participant_user_ids' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/{$thread->id}");
    }

    public function show(TenantContext $context, CommunicationThreadService $service, string $thread, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $model = CommunicationThread::query()->with(['participants.user:id,name'])->findOrFail($thread);

        $participant = $model->participants->first(fn (CommunicationThreadParticipant $p) => $p->user_id === $actor->id && $p->left_at === null);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);

        abort_unless($participant !== null || $canManage, 403);

        // Brief §22: only the CURRENT participant's own read cursor
        // moves, and only when they genuinely are a participant --
        // communications.manage viewing a thread they don't belong to
        // never mutates anyone's read state.
        if ($participant !== null) {
            $service->markRead($participant);
        }

        // Brief §19/§20: newest page first (standard Laravel
        // pagination, not a custom scroll architecture), reversed for
        // chronological display -- "Load older messages" pages
        // backward from here.
        $page = max(1, (int) $request->integer('page', 1));
        $paginated = CommunicationMessage::query()
            ->where('thread_id', $model->id)
            ->with(['sender:id,name', 'attachments'])
            ->orderByDesc('created_at')
            ->paginate(30, page: $page);

        return Inertia::render('App/Communications/Show', [
            'thread' => $this->presentThread($model),
            'participants' => $model->participants->map(fn (CommunicationThreadParticipant $p) => [
                'userId' => $p->user_id,
                'name' => $p->user->name,
                'active' => $p->left_at === null,
            ])->all(),
            'messages' => collect($paginated->items())->reverse()->values()->map(fn (CommunicationMessage $m) => $this->presentMessage($m))->all(),
            'messagesMeta' => [
                'currentPage' => $paginated->currentPage(),
                'hasOlder' => $paginated->hasMorePages(),
            ],
            'canReply' => $participant !== null && $model->isOpen() && app(CapabilityResolver::class)->canInSchool($actor, 'communications.reply', $school),
            'isParticipant' => $participant !== null,
            'isArchivedByMe' => $participant !== null && $participant->archived,
            'maxAttachments' => (int) config('communications.attachments.max_per_message'),
            // Brief §16: the actor's own not-yet-sent uploads for THIS
            // thread -- lets the composer survive the full-page
            // reload every Inertia POST in this module causes (no
            // client-only state carries an upload's server-issued id
            // across that reload otherwise).
            'pendingAttachments' => $participant === null ? [] : CommunicationAttachment::query()
                ->where('communication_thread_id', $model->id)
                ->where('created_by_user_id', $actor->id)
                ->whereNull('communication_message_id')
                ->get()
                ->map(fn (CommunicationAttachment $a) => [
                    'id' => $a->id,
                    'displayName' => $a->safe_display_name,
                    'sizeBytes' => $a->size_bytes,
                ])->all(),
        ]);
    }

    public function storeMessage(Request $request, TenantContext $context, CommunicationMessageService $service, string $thread): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.reply', $school);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['sometimes', 'in:normal,important,urgent,critical'],
            'attachment_ids' => ['sometimes', 'array'],
            'attachment_ids.*' => ['string'],
        ]);

        $model = CommunicationThread::query()->findOrFail($thread);

        try {
            $service->send(
                $model,
                $context->actor(),
                $validated['body'],
                isset($validated['priority']) ? CommunicationPriority::from($validated['priority']) : CommunicationPriority::Normal,
                $validated['attachment_ids'] ?? [],
            );
        } catch (CommunicationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect("/app/communications/{$thread}");
    }

    /**
     * Phase 5A.7 §29 -- participant-specific archive/unarchive. Not
     * capability-gated: the same "inherently self-scoped, cannot
     * affect anyone else" reasoning Phase 5A.5's preference controller
     * documents -- the real gate is genuinely being an active
     * participant of this thread.
     */
    public function archive(TenantContext $context, CommunicationThreadService $service, string $thread): RedirectResponse
    {
        return $this->toggleArchive($context, $service, $thread, archived: true);
    }

    public function unarchive(TenantContext $context, CommunicationThreadService $service, string $thread): RedirectResponse
    {
        return $this->toggleArchive($context, $service, $thread, archived: false);
    }

    private function toggleArchive(TenantContext $context, CommunicationThreadService $service, string $thread, bool $archived): RedirectResponse
    {
        $context->requireSchool();
        $actor = $context->actor();

        $model = CommunicationThread::query()->findOrFail($thread);
        $participant = $model->participants()->where('user_id', $actor->id)->whereNull('left_at')->first();

        abort_if($participant === null, 403);

        if ($archived) {
            $service->archiveForParticipant($participant, $actor);
        } else {
            $service->unarchiveForParticipant($participant, $actor);
        }

        return redirect('/app/communications/conversations');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentThread(CommunicationThread $thread): array
    {
        return [
            'id' => $thread->id,
            'threadType' => $thread->thread_type,
            'subject' => $thread->subject,
            'status' => $thread->status,
            'createdByName' => $thread->createdBy?->name,
            'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
        ];
    }

    /**
     * Brief §18: never the full private message body -- a truncated
     * preview only, and never any participant's `last_read_at` (brief
     * §24 -- no read-receipt surveillance UI).
     *
     * @return array<string, mixed>
     */
    private function presentThreadSummary(CommunicationThread $thread, string $actorUserId, ?ConversationThreadSummary $summary): array
    {
        $otherNames = $thread->participants
            ->filter(fn (CommunicationThreadParticipant $p) => $p->user_id !== $actorUserId)
            ->map(fn (CommunicationThreadParticipant $p) => $p->user->name)
            ->values()
            ->all();

        $preview = $summary?->latestMessageBody;
        $preview = $preview === null ? null : (mb_strlen($preview) > 120 ? mb_substr($preview, 0, 120).'…' : $preview);

        return [
            'id' => $thread->id,
            'threadType' => $thread->thread_type,
            'subject' => $thread->subject,
            'status' => $thread->status,
            'otherParticipantNames' => $otherNames,
            'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
            'latestMessagePreview' => $preview,
            'hasAttachment' => $summary->hasAttachment,
            'unread' => $summary->unread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(CommunicationMessage $message): array
    {
        return [
            'id' => $message->id,
            'senderName' => $message->sender?->name,
            'body' => $message->body,
            'priority' => $message->priority,
            'status' => $message->status,
            'createdAt' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'displayName' => $a->safe_display_name,
                'mimeType' => $a->mime_type,
                'sizeBytes' => $a->size_bytes,
            ])->all(),
        ];
    }
}
