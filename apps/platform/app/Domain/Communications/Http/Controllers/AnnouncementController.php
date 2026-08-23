<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.2 §15/§20/§21 -- the Announcement composer/list/detail
 * shell, following App\Domain\Communications\Http\Controllers\
 * CommunicationHubController's exact thin-controller shape: every
 * write delegates to AnnouncementService, every action re-derives the
 * active School from TenantContext (root CLAUDE.md rule 19/68), no
 * `school_id` ever comes from request input.
 *
 * There is no separate "preview audience" endpoint -- the draft
 * `show()` page itself IS the audience preview surface (brief §14),
 * computed live via AnnouncementService::previewAudience() on every
 * GET while the Announcement is still a draft. This keeps the whole
 * composer flow inside plain Inertia GET/POST/PUT actions, matching
 * every other App controller in this codebase (no ad hoc JSON route).
 */
class AnnouncementController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);

        $announcements = CommunicationAnnouncement::query()
            ->with(['createdBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Communications/Announcements/Index', [
            'announcements' => $announcements->through(fn (CommunicationAnnouncement $a) => $this->presentSummary($a)),
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.announce', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        return Inertia::render('App/Communications/Announcements/Create');
    }

    public function store(Request $request, TenantContext $context, AnnouncementService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $validated = $this->validateComposer($request);

        try {
            $announcement = $service->createDraft(
                $school,
                $context->actor(),
                $validated['title'],
                $validated['body'],
                CommunicationPriority::from($validated['priority']),
                CommunicationAudienceType::from($validated['audience_type']),
                $validated['member_user_ids'] ?? [],
            );
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['member_user_ids' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$announcement->id}");
    }

    public function show(TenantContext $context, AnnouncementService $service, string $announcement): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->with(['createdBy:id,name'])->findOrFail($announcement);

        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        $isCreator = $model->created_by_user_id === $actor->id;
        $isRecipient = CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $model->id)
            ->where('user_id', $actor->id)
            ->exists();

        abort_unless($isCreator || $isRecipient || $canManage, 403);

        $preview = $model->isDraft() ? $service->previewAudience($model) : null;

        return Inertia::render('App/Communications/Announcements/Show', [
            'announcement' => $this->presentDetail($model),
            'preview' => $preview === null ? null : [
                'count' => $preview->count(),
                'categoryBreakdown' => $preview->categoryBreakdown,
            ],
            'canEdit' => ($isCreator || $canManage) && $model->isDraft(),
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.announce', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, AnnouncementService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        $validated = $this->validateComposer($request);

        try {
            $service->updateDraft(
                $model,
                $actor,
                $validated['title'],
                $validated['body'],
                CommunicationPriority::from($validated['priority']),
                $model->audienceTypeEnum() === CommunicationAudienceType::Individual ? ($validated['member_user_ids'] ?? []) : null,
            );
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['body' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    public function publish(TenantContext $context, AnnouncementService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        try {
            $service->publish($model, $actor);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['audience' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    public function cancel(TenantContext $context, AnnouncementService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        try {
            $service->cancel($model, $actor);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateComposer(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'in:normal,important,urgent,critical'],
            'audience_type' => ['required', 'in:individual,school_wide'],
            'member_user_ids' => ['required_if:audience_type,individual', 'array'],
            'member_user_ids.*' => ['string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(CommunicationAnnouncement $a): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'createdByName' => $a->createdBy?->name,
            'audienceType' => $a->audience_type,
            'recipientCount' => $a->recipient_count,
            'status' => $a->status,
            'priority' => $a->priority,
            'publishedAt' => $a->published_at?->toIso8601String(),
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(CommunicationAnnouncement $a): array
    {
        return array_merge($this->presentSummary($a), [
            'body' => $a->body,
        ]);
    }
}
