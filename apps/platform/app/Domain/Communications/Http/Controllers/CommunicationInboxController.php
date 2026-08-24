<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationDeliveryFailureReadModel;
use App\Domain\Communications\Application\CommunicationInboxItem;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.8 §4/§10 -- the operational Inbox/Unread/Sent/Failed/
 * Search surfaces: `/app/communications` (Inbox, the new Hub landing
 * page), `/unread`, `/sent`, `/failed`, `/search`. Every action stays
 * thin -- all composition happens in
 * App\Domain\Communications\Application\CommunicationInboxReadModel /
 * CommunicationDeliveryFailureReadModel, never here.
 *
 * `communications.view` is the only capability Inbox/Unread/Sent/
 * Search require -- each ITEM inside them is independently authorized
 * by the read model re-deriving genuine participation/recipiency
 * (brief §9/§12), never by this broader capability alone. `Failed` is
 * the one surface gated by `communications.manage` (brief §41).
 */
class CommunicationInboxController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $limit = $this->boundedLimit($request);
        $filters = $this->validatedFilters($request);
        $items = $readModel->inbox($school, $actor, $limit, filters: $filters);

        return Inertia::render('App/Communications/Inbox', array_merge([
            'items' => $items->map(fn (CommunicationInboxItem $i) => $i->toArray())->all(),
            'limit' => $limit,
            'filters' => $filters,
        ], $this->navFlags($school, $actor, $readModel, $conversationReadModel)));
    }

    /**
     * Phase 5A.8 §21 -- every filter value is checked against a fixed
     * allowlist here, server-side, before it ever reaches a query --
     * an unrecognized value is silently dropped (treated as "no
     * filter"), never passed through as a raw SQL fragment.
     *
     * @return array{type: ?string, priority: ?string, hasAttachments: ?bool}
     */
    private function validatedFilters(Request $request): array
    {
        $type = $request->string('type')->value();
        $priority = $request->string('priority')->value();

        return [
            'type' => in_array($type, ['conversation', 'announcement'], true) ? $type : null,
            'priority' => in_array($priority, ['normal', 'important', 'urgent', 'critical'], true) ? $priority : null,
            'hasAttachments' => $request->boolean('has_attachments') ? true : null,
        ];
    }

    public function unread(TenantContext $context, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $limit = $this->boundedLimit($request);
        $items = $readModel->unread($school, $actor, $limit);

        return Inertia::render('App/Communications/Unread', array_merge([
            'items' => $items->map(fn (CommunicationInboxItem $i) => $i->toArray())->all(),
            'limit' => $limit,
        ], $this->navFlags($school, $actor, $readModel, $conversationReadModel)));
    }

    public function sent(TenantContext $context, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $limit = $this->boundedLimit($request);
        $items = $readModel->sent($school, $actor, $limit);

        return Inertia::render('App/Communications/Sent', array_merge([
            'items' => $items->map(fn (CommunicationInboxItem $i) => $i->toArray())->all(),
            'limit' => $limit,
        ], $this->navFlags($school, $actor, $readModel, $conversationReadModel)));
    }

    /**
     * Phase 5A.8 §18/§41 -- communications.manage only. Never exposes
     * individual destination addresses or provider payloads -- see
     * CommunicationDeliveryFailureReadModel.
     */
    public function failed(TenantContext $context, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel, CommunicationDeliveryFailureReadModel $failureReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);
        $actor = $context->actor();

        $page = max(1, (int) $request->integer('page', 1));
        $paginated = $failureReadModel->failedAnnouncements($school, 20, $page);
        $breakdown = $failureReadModel->failureBreakdown($school, $paginated->pluck('id')->all());

        return Inertia::render('App/Communications/Failed', array_merge([
            'announcements' => $paginated->through(fn (CommunicationAnnouncement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'createdByName' => $a->createdBy?->name,
                'publishedAt' => $a->published_at?->toIso8601String(),
                'failures' => $breakdown[$a->id] ?? [],
            ]),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ], $this->navFlags($school, $actor, $readModel, $conversationReadModel)));
    }

    /**
     * Phase 5A.8 §9/§24/§27 -- a bounded, per-type-limited, privacy-
     * safe mixed search. `q` is trimmed and length-capped before ever
     * reaching a query; matching is a plain parameterized `ILIKE`,
     * never a client-controlled SQL fragment.
     */
    public function search(TenantContext $context, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        $canManageTemplates = app(CapabilityResolver::class)->canInSchool($actor, 'communications.templates.manage', $school);

        $items = $query === '' ? collect() : $readModel->search($school, $actor, $query, $canManage, $canManageTemplates);

        return Inertia::render('App/Communications/Search', array_merge([
            'query' => $query ?: null,
            'items' => $items->map(fn (CommunicationInboxItem $i) => $i->toArray())->all(),
        ], $this->navFlags($school, $actor, $readModel, $conversationReadModel)));
    }

    /**
     * Brief §27: a reasonable, fixed max -- never an arbitrarily large
     * client-supplied "show everything" value.
     */
    private function boundedLimit(Request $request): int
    {
        $limit = (int) $request->integer('limit', 20);

        return max(5, min(100, $limit));
    }

    /**
     * The same capability/unread-count bundle every Hub page's sidebar
     * needs (brief §32: "only show items appropriate to the current
     * user's capabilities") -- shared here, and duplicated (not
     * abstracted further) in CommunicationHubController::conversations(),
     * the one other action outside this controller that renders the
     * same sidebar.
     *
     * @return array<string, mixed>
     */
    private function navFlags(School $school, User $actor, CommunicationInboxReadModel $readModel, ConversationReadModel $conversationReadModel): array
    {
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);

        return [
            'totalUnreadCount' => $conversationReadModel->totalUnreadCount($school, $actor->id) + $readModel->unreadAnnouncementCount($school, $actor),
            'canSend' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.send', $school),
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.announce', $school),
            'canManage' => $canManage,
            'canManageChannelPolicy' => $canManage,
            'canManageTemplates' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.templates.manage', $school),
            'canApprove' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.approve', $school),
        ];
    }
}
