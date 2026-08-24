<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.12 §42/§43/§45 -- the authorized approval queue and review
 * detail. `communications.approve`-gated throughout (brief §12) --
 * deliberately NOT `communications.manage`, which is a different,
 * broader capability (thread/participant administration, brief §12's
 * own reasoning for why `.approve` is distinct). Emergency
 * communications never appear here by construction (they never enter
 * this table at all, see
 * App\Domain\Communications\Application\Approval\CommunicationApprovalService::submit()).
 * Every action here stays thin -- all workflow logic lives in
 * CommunicationApprovalService (brief §51); this controller never
 * manipulates a CommunicationApprovalRequest row directly.
 */
class CommunicationApprovalController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.approve', $school);

        $page = max(1, (int) $request->integer('page', 1));

        $paginated = CommunicationApprovalRequest::query()
            ->where('status', 'pending')
            ->with([
                'announcement:id,title,priority,requirement,audience_type,status',
                'requestedBy:id,name',
            ])
            ->orderBy('requested_at')
            ->paginate(20, page: $page)
            ->withQueryString();

        return Inertia::render('App/Communications/Approvals/Index', [
            'requests' => $paginated->through(fn (CommunicationApprovalRequest $r) => $this->presentQueueRow($r))->items(),
            'meta' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function show(TenantContext $context, string $approvalRequest): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.approve', $school);
        $actor = $context->actor();

        $model = CommunicationApprovalRequest::query()
            ->with(['announcement:id,title,status', 'requestedBy:id,name', 'decidedBy:id,name'])
            ->findOrFail($approvalRequest);

        return Inertia::render('App/Communications/Approvals/Show', [
            'request' => $this->presentDetail($model),
            // Brief §13: the composer must know NOT to offer
            // Approve/Reject at all when the current actor is the
            // requester -- server-side enforcement in
            // CommunicationApprovalService::decide() is the real
            // guarantee; this flag is presentation only.
            'canDecide' => $model->isPending() && $model->requested_by_user_id !== $actor->id,
        ]);
    }

    public function approve(Request $request, TenantContext $context, CommunicationApprovalService $service, string $approvalRequest): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.approve', $school);
        $actor = $context->actor();

        $model = CommunicationApprovalRequest::query()->findOrFail($approvalRequest);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $service->approve($model, $actor, $validated['note'] ?? null);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return redirect('/app/communications/approvals');
    }

    public function reject(Request $request, TenantContext $context, CommunicationApprovalService $service, string $approvalRequest): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.approve', $school);
        $actor = $context->actor();

        $model = CommunicationApprovalRequest::query()->findOrFail($approvalRequest);

        // Brief §30/§65: bounded plain text, required for a rejection.
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $service->reject($model, $actor, $validated['reason']);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['reason' => [$e->getMessage()]]);
        }

        return redirect('/app/communications/approvals');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQueueRow(CommunicationApprovalRequest $r): array
    {
        $snapshot = $r->snapshot;

        return [
            'id' => $r->id,
            'announcementId' => $r->announcement_id,
            'title' => $snapshot['title'] ?? $r->announcement?->title,
            'requestedByName' => $r->requestedBy?->name,
            'requestedAt' => $r->requested_at->toIso8601String(),
            'priority' => $snapshot['priority'] ?? null,
            'requirement' => $snapshot['requirement'] ?? null,
            'audienceType' => $snapshot['audienceType'] ?? null,
            'channels' => $snapshot['channels'] ?? [],
            'attachmentCount' => count($snapshot['attachmentChecksums'] ?? []),
        ];
    }

    /**
     * Brief §18/§43: renders from the immutable `snapshot` captured at
     * submission time -- the exact reviewed content, never the LIVE
     * announcement (which, for an already-decided historical request,
     * may since have been edited past it). For a still-PENDING request
     * this is identical to the live content anyway (editing is blocked
     * while pending, brief §29).
     *
     * @return array<string, mixed>
     */
    private function presentDetail(CommunicationApprovalRequest $r): array
    {
        $snapshot = $r->snapshot;

        return [
            'id' => $r->id,
            'announcementId' => $r->announcement_id,
            'announcementStatus' => $r->announcement?->status,
            'status' => $r->status,
            'title' => $snapshot['title'] ?? null,
            'body' => $snapshot['body'] ?? null,
            'priority' => $snapshot['priority'] ?? null,
            'requirement' => $snapshot['requirement'] ?? null,
            'dispatchMode' => $snapshot['dispatchMode'] ?? null,
            'audienceType' => $snapshot['audienceType'] ?? null,
            'individualMemberCount' => count($snapshot['individualMemberIds'] ?? []),
            'channels' => $snapshot['channels'] ?? [],
            'attachmentCount' => count($snapshot['attachmentChecksums'] ?? []),
            'requestedByName' => $r->requestedBy?->name,
            'requestedAt' => $r->requested_at->toIso8601String(),
            'decidedByName' => $r->decidedBy?->name,
            'decidedAt' => $r->decided_at?->toIso8601String(),
            'decisionNote' => $r->decision_note,
        ];
    }
}
