<?php

namespace App\Http\Controllers\App\Portal;

use App\Domain\Communications\Application\Portal\GuardianConversationService;
use App\Domain\Communications\Application\Portal\GuardianReplyException;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POR.4 (ADR 0070 §27): a Guardian's existing School conversations and text
 * replies -- web/Inertia, session only. Every route already carries
 * `portal-development-only`, `capability:portal.communications.view` and
 * `mfa-page`; the reply route also `capability:portal.communications.reply`
 * and its own throttle (routes/web.php). Here the ActingGuardian is resolved
 * fresh and the Communications service decides everything else (an
 * inaccessible id is the same 404). No compose, edit, delete or upload.
 */
class GuardianConversationController extends Controller
{
    public function index(TenantContext $context, ActingGuardianResolver $guardians, GuardianConversationService $conversations): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();

        return Inertia::render('App/Portal/Conversations/Index', [
            'schoolName' => $school->name,
            'conversations' => $conversations->conversations($school, $guardians->require($actor, $school), $actor),
        ]);
    }

    public function show(Request $request, TenantContext $context, ActingGuardianResolver $guardians, GuardianConversationService $conversations, string $thread): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();

        return Inertia::render('App/Portal/Conversations/Show', [
            'schoolName' => $school->name,
            'conversation' => $conversations->thread($school, $guardians->require($actor, $school), $actor, $thread, max(1, $request->integer('page', 1))),
            // A fresh server-issued key per render: a retried submission of
            // THIS form reuses it, so it can never post twice (ADR 0070 §27.5).
            'replyKey' => (string) Str::uuid(),
            'maxLength' => GuardianConversationService::MAX_BODY_LENGTH,
        ]);
    }

    public function reply(Request $request, TenantContext $context, ActingGuardianResolver $guardians, GuardianConversationService $conversations, string $thread): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $guardian = $guardians->require($actor, $school);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.GuardianConversationService::MAX_BODY_LENGTH],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $conversations->reply($school, $guardian, $actor, $thread, $validated['body'], $validated['idempotency_key']);
        } catch (GuardianReplyException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }

        return redirect("/app/portal/conversations/{$thread}");
    }

    public function download(TenantContext $context, ActingGuardianResolver $guardians, GuardianConversationService $conversations, string $thread, string $attachment): StreamedResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $model = $conversations->attachmentForDownload($school, $guardians->require($actor, $school), $actor, $thread, $attachment);

        return Storage::disk($model->storage_disk)->download($model->storage_path, $model->safe_display_name, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
