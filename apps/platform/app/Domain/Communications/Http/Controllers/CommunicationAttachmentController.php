<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5A.6 §11/§13/§35/§46 -- upload/remove/download for
 * Communication Hub attachments. store()/destroy() follow this
 * module's uniform convention exactly (a plain Inertia POST/DELETE
 * redirecting back to the Announcement composer/detail page, same
 * shape as publish()/cancel()/schedule() in AnnouncementController) --
 * Inertia's `router.post()` already handles a `FormData` payload (file
 * upload) as a normal request, so no separate JSON/AJAX endpoint style
 * is needed here.
 *
 * `download()` is registered OUTSIDE the `announcements/` prefix
 * (brief §11's exact route shape,
 * `GET /app/communications/attachments/{attachment}/download`) and
 * resolves its parent Announcement itself -- an attachment id alone is
 * never sufficient (brief §5/§12): School membership, School
 * ownership of the row, AND read-entitlement to the parent
 * announcement are all re-verified here, every time, never cached from
 * a prior request.
 */
class CommunicationAttachmentController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, TenantContext $context, CommunicationAttachmentService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        try {
            $service->upload($model, $actor, $validated['file']);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    public function destroy(TenantContext $context, CommunicationAttachmentService $service, string $announcement, string $attachment): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        $attachmentModel = CommunicationAttachment::query()
            ->where('communication_announcement_id', $model->id)
            ->findOrFail($attachment);

        try {
            $service->remove($attachmentModel, $actor);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['attachment' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    /**
     * Phase 5A.7 §15/§16 -- the conversation-message counterpart to
     * store(): pending-attachment upload scoped to a THREAD, authorized
     * by active participation rather than announcement ownership.
     */
    public function storeForThread(Request $request, TenantContext $context, CommunicationAttachmentService $service, string $thread): RedirectResponse
    {
        // POR.5 (ADR 0070 §28.4): a pending thread attachment exists only to be
        // sent with a staff Hub reply, so uploading it needs that reply's own
        // capability, not participation alone -- a Guardian-only member is a
        // participant too (the §24.8 download fix, applied to the writes).
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.reply', $school);
        $actor = $context->actor();

        $model = CommunicationThread::query()->findOrFail($thread);
        abort_unless($this->isActiveParticipant($model, $actor->id), 403);

        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        try {
            $service->uploadForThread($model, $actor, $validated['file']);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/{$model->id}");
    }

    public function destroyForThread(TenantContext $context, CommunicationAttachmentService $service, string $thread, string $attachment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.reply', $school);
        $actor = $context->actor();

        $model = CommunicationThread::query()->findOrFail($thread);
        abort_unless($this->isActiveParticipant($model, $actor->id), 403);

        $attachmentModel = CommunicationAttachment::query()
            ->where('communication_thread_id', $model->id)
            ->findOrFail($attachment);

        try {
            $service->remove($attachmentModel, $actor);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['attachment' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/{$model->id}");
    }

    private function isActiveParticipant(CommunicationThread $thread, string $userId): bool
    {
        return $thread->participants()->where('user_id', $userId)->whereNull('left_at')->exists();
    }

    public function download(TenantContext $context, CommunicationAttachmentService $service, AuditRecorder $audit, string $attachment)
    {
        $school = $context->requireSchool();
        // POR.1 (ADR 0070 §24.8): the staff Hub's download needs the Hub
        // capability like every other Hub page -- a membership alone (an
        // off-boarded staff member who stays a Guardian, a role-less member)
        // never reaches staff attachments by id. Guardians use the portal's
        // own route.
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        // School-scoped by construction (BelongsToSchool/RLS already
        // limit this query to the active School) -- a forged id for
        // another School's row is invisible here, not merely
        // forbidden.
        $model = CommunicationAttachment::query()->where('school_id', $school->id)->findOrFail($attachment);

        abort_unless($service->authorizeRead($model, $actor, $school), 403);

        $context->withSchool($school, function () use ($model, $actor, $school, $audit) {
            $audit->school($school, 'communication_attachment.downloaded', actor: $actor, subject: $model, metadata: [
                'announcementId' => $model->communication_announcement_id,
                'threadId' => $model->communication_thread_id,
            ]);
        });

        return Storage::disk($model->storage_disk)->download($model->storage_path, $model->safe_display_name, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
