<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationAuditEntry;
use App\Domain\Communications\Application\CommunicationAuditReadModel;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.11 §9/§12/§30 -- the authorized audit timeline for one
 * Announcement. `communications.audit.view`-gated (brief §12/§30) --
 * deliberately NOT `communications.view`, which only proves the actor
 * can see the announcement's current state, not its full change
 * history. Thin controller -- all projection happens in
 * App\Domain\Communications\Application\CommunicationAuditReadModel.
 *
 * A detail sub-page of one Announcement, matching
 * App\Domain\Communications\Http\Controllers\AnnouncementController::show()'s
 * own convention -- a back-link only, no HubNav sidebar.
 */
class CommunicationAuditController extends Controller
{
    use AuthorizesCapability;

    public function show(TenantContext $context, CommunicationAuditReadModel $readModel, Request $request, string $announcement): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.audit.view', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->with('createdBy:id,name')->findOrFail($announcement);

        // Brief §14/§31: the SAME gate
        // AnnouncementController::presentDetail() already uses for
        // `emergencyJustification` -- audit privilege never becomes a
        // wider route to that content than the viewer already has.
        $includeJustification = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school)
            || app(CapabilityResolver::class)->canInSchool($actor, 'communications.emergency', $school);

        $page = max(1, (int) $request->integer('page', 1));
        $timeline = $readModel->timelineForAnnouncement($school, $model, $includeJustification, 25, $page);

        return Inertia::render('App/Communications/Announcements/Audit', [
            'announcement' => [
                'id' => $model->id,
                'title' => $model->title,
                'status' => $model->status,
                'dispatchMode' => $model->dispatch_mode,
                'createdByName' => $model->createdBy?->name,
            ],
            'entries' => $timeline->through(fn (CommunicationAuditEntry $entry) => $entry->toArray())->items(),
            'meta' => [
                'currentPage' => $timeline->currentPage(),
                'lastPage' => $timeline->lastPage(),
                'total' => $timeline->total(),
            ],
        ]);
    }
}
