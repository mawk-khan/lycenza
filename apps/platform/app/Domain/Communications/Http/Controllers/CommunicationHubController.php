<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
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
 * Phase 5A.1 §18/§19 -- the Communication Hub shell: session-
 * authenticated Inertia pages under /app/communications, following
 * App\Http\Controllers\App\SchoolSetupController's exact convention
 * (TenantContext::requireSchool() -> authorizeCapability() ->
 * Inertia::render()/redirect()). Controllers stay thin -- every write
 * delegates to CommunicationThreadService/CommunicationMessageService.
 */
class CommunicationHubController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $threads = CommunicationThread::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $actor->id)->whereNull('left_at'))
            ->with(['createdBy:id,name'])
            ->orderByDesc('last_activity_at')
            ->get();

        return Inertia::render('App/Communications/Index', [
            'threads' => $threads->map(fn (CommunicationThread $t) => $this->presentThread($t))->all(),
            'canSend' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.send', $school),
            // Phase 5A.5 §26: channel-policy administration is gated
            // the same as thread/participant management -- reused, not
            // a new capability.
            'canManageChannelPolicy' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school),
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

    public function show(TenantContext $context, string $thread): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $model = CommunicationThread::query()->with(['participants.user:id,name'])->findOrFail($thread);

        $isParticipant = $model->participants->contains(fn (CommunicationThreadParticipant $p) => $p->user_id === $actor->id && $p->left_at === null);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);

        abort_unless($isParticipant || $canManage, 403);

        $messages = CommunicationMessage::query()
            ->where('thread_id', $model->id)
            ->with(['sender:id,name'])
            ->orderBy('created_at')
            ->get();

        return Inertia::render('App/Communications/Show', [
            'thread' => $this->presentThread($model),
            'participants' => $model->participants->map(fn (CommunicationThreadParticipant $p) => [
                'userId' => $p->user_id,
                'name' => $p->user->name,
                'active' => $p->left_at === null,
            ])->all(),
            'messages' => $messages->map(fn (CommunicationMessage $m) => $this->presentMessage($m))->all(),
            'canReply' => $isParticipant && app(CapabilityResolver::class)->canInSchool($actor, 'communications.reply', $school),
        ]);
    }

    public function storeMessage(Request $request, TenantContext $context, CommunicationMessageService $service, string $thread): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.reply', $school);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['sometimes', 'in:normal,important,urgent,critical'],
        ]);

        $model = CommunicationThread::query()->findOrFail($thread);

        try {
            $service->send(
                $model,
                $context->actor(),
                $validated['body'],
                isset($validated['priority']) ? CommunicationPriority::from($validated['priority']) : CommunicationPriority::Normal,
            );
        } catch (CommunicationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect("/app/communications/{$thread}");
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
        ];
    }
}
