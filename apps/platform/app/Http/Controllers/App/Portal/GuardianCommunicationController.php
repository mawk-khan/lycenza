<?php

namespace App\Http\Controllers\App\Portal;

use App\Domain\Communications\Application\Portal\GuardianAnnouncementReadService;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POR.1 (ADR 0070 §10.1): the read-only Guardian inbox -- web/Inertia,
 * session only (no bearer-token or API route). Each route already carries
 * `portal-development-only` and `capability:portal.communications.view`
 * (routes/web.php); here the ActingGuardian is resolved fresh (fixed 403 if
 * this User is not a Guardian of this School), and the Communications read
 * seam decides visibility (an unknown or foreign id is the same 404).
 */
class GuardianCommunicationController extends Controller
{
    public function index(Request $request, TenantContext $context, ActingGuardianResolver $guardians, GuardianAnnouncementReadService $inbox): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $guardian = $guardians->require($actor, $school);
        $unreadOnly = $request->boolean('unread');

        return Inertia::render('App/Portal/Communications/Index', [
            'schoolName' => $school->name,
            'filter' => $unreadOnly ? 'unread' : 'all',
            'items' => $inbox->inbox($school, $guardian, $actor, $unreadOnly),
        ]);
    }

    public function show(TenantContext $context, ActingGuardianResolver $guardians, GuardianAnnouncementReadService $inbox, string $announcement): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $guardian = $guardians->require($actor, $school);

        return Inertia::render('App/Portal/Communications/Show', [
            'schoolName' => $school->name,
            'announcement' => $inbox->show($school, $guardian, $actor, $announcement),
        ]);
    }

    public function download(TenantContext $context, ActingGuardianResolver $guardians, GuardianAnnouncementReadService $inbox, string $announcement, string $attachment): StreamedResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $model = $inbox->attachmentForDownload($school, $guardians->require($actor, $school), $actor, $announcement, $attachment);

        return Storage::disk($model->storage_disk)->download($model->storage_path, $model->safe_display_name, [
            'Content-Type' => $model->mime_type,
        ]);
    }
}
