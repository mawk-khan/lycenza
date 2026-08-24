<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\ConversationReadModel;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.11 §15/§25/§28/§30 -- read-only delivery analytics surfaces:
 * the School-wide operational overview (`/app/communications/analytics`)
 * and the per-Announcement delivery summary + drill-down
 * (`/app/communications/announcements/{announcement}/analytics`).
 * `communications.manage`-gated throughout (brief §30) -- ordinary
 * recipients never reach either surface (brief §29). Every action stays
 * thin: all aggregation happens in
 * App\Domain\Communications\Application\CommunicationDeliveryAnalyticsReadModel,
 * never here (brief §15). No action here ever mutates domain state
 * (brief §35) -- read-model queries only.
 */
class CommunicationAnalyticsController extends Controller
{
    use AuthorizesCapability;

    /**
     * Brief §26: the fixed, bounded set of supported quick ranges plus
     * one explicitly-bounded custom range -- never an arbitrary
     * client-supplied open-ended window.
     */
    private const RANGE_KEYS = ['today', '7d', '30d', 'custom'];

    public function overview(TenantContext $context, CommunicationDeliveryAnalyticsReadModel $readModel, CommunicationInboxReadModel $inboxReadModel, ConversationReadModel $conversationReadModel, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);
        $actor = $context->actor();

        $range = $this->resolveDateRange($school, $request);
        $summary = $readModel->schoolOverview($school, $range['fromUtc'], $range['toUtc']);

        return Inertia::render('App/Communications/Analytics', array_merge([
            'summary' => $summary,
            'range' => [
                'key' => $range['key'],
                'from' => $range['fromLocal']->toDateString(),
                'to' => $range['toLocal']->toDateString(),
            ],
            'schoolTimezone' => $school->timezone,
        ], $this->navFlags($school, $actor, $inboxReadModel, $conversationReadModel)));
    }

    /**
     * Brief §34: a detail sub-page of one Announcement -- follows
     * App\Domain\Communications\Http\Controllers\AnnouncementController::show()'s
     * own convention of a back-link only, no HubNav sidebar (this is
     * not one of the primary Hub tabs the sidebar lists).
     */
    public function announcement(TenantContext $context, CommunicationDeliveryAnalyticsReadModel $readModel, Request $request, string $announcement): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.manage', $school);

        $model = CommunicationAnnouncement::query()->with('createdBy:id,name')->findOrFail($announcement);

        $summary = $readModel->announcementSummary($school, $model);

        $filters = [
            'channel' => $request->string('channel')->value() ?: null,
            'status' => $request->string('status')->value() ?: null,
            'failureCode' => $request->string('failure_code')->value() ?: null,
        ];
        $page = max(1, (int) $request->integer('page', 1));
        $deliveries = $readModel->deliveryDetail($school, $model, $filters, 25, $page);

        return Inertia::render('App/Communications/Announcements/Analytics', [
            'announcement' => [
                'id' => $model->id,
                'title' => $model->title,
                'status' => $model->status,
                'dispatchMode' => $model->dispatch_mode,
                'createdByName' => $model->createdBy?->name,
                'publishedAt' => $model->published_at?->toIso8601String(),
            ],
            'summary' => $summary,
            'deliveries' => $deliveries->items(),
            'deliveriesMeta' => [
                'currentPage' => $deliveries->currentPage(),
                'lastPage' => $deliveries->lastPage(),
                'total' => $deliveries->total(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * @return array{key: string, fromUtc: Carbon, toUtc: Carbon, fromLocal: Carbon, toLocal: Carbon}
     */
    private function resolveDateRange(School $school, Request $request): array
    {
        $tz = SchoolTimezone::resolve($school);
        $requested = $request->string('range')->value();
        $key = in_array($requested, self::RANGE_KEYS, true) ? $requested : '7d';
        $now = Carbon::now($tz);

        if ($key === 'custom') {
            $validated = $request->validate([
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
            ]);

            $from = Carbon::parse($validated['from'], $tz)->startOfDay();
            $to = Carbon::parse($validated['to'], $tz)->endOfDay();

            if ($from->diffInDays($to) > 366) {
                throw ValidationException::withMessages(['to' => ['The date range cannot exceed 366 days.']]);
            }
        } elseif ($key === 'today') {
            $from = $now->copy()->startOfDay();
            $to = $now->copy()->endOfDay();
        } elseif ($key === '30d') {
            $from = $now->copy()->subDays(29)->startOfDay();
            $to = $now->copy()->endOfDay();
        } else {
            $from = $now->copy()->subDays(6)->startOfDay();
            $to = $now->copy()->endOfDay();
        }

        return [
            'key' => $key,
            'fromUtc' => $from->copy()->utc(),
            'toUtc' => $to->copy()->utc(),
            'fromLocal' => $from,
            'toLocal' => $to,
        ];
    }

    /**
     * Mirrors CommunicationInboxController::navFlags()'s exact bundle
     * (brief §33: keep navigation manageable, no new pattern).
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
        ];
    }
}
