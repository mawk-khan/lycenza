<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Audience\ResolvedAudience;
use App\Domain\Communications\Application\Channels\EmailAddressResolver;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        $query = CommunicationAnnouncement::query()->with(['createdBy:id,name', 'requestedChannels']);

        // Brief §34/§35: All/Drafts/Scheduled/Published tabs -- an
        // unrecognized/absent value is treated as "All", never a 422,
        // since this is a read-model filter, not a mutation.
        $status = $request->string('status')->value();
        if (in_array($status, ['draft', 'scheduled', 'published', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $announcements = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        return Inertia::render('App/Communications/Announcements/Index', [
            'announcements' => $announcements->through(fn (CommunicationAnnouncement $a) => $this->presentSummary($a)),
            'filters' => ['status' => $status ?: null],
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.announce', $school),
        ]);
    }

    public function create(TenantContext $context, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        // Brief §13: "Use Template" pre-fills the composer -- reading
        // an ACTIVE template only (brief §10's chosen policy: an
        // inactive template cannot be newly applied). An invalid,
        // inactive, or cross-School template id (RLS makes a cross-
        // School row invisible to begin with) simply renders a blank
        // composer rather than erroring -- this is a convenience
        // pre-fill, not a hard dependency.
        $template = $request->filled('template')
            ? CommunicationTemplate::query()->where('status', 'active')->find($request->string('template')->value())
            : null;

        return Inertia::render('App/Communications/Announcements/Create', [
            'emailChannelEnabled' => (bool) config('communications.channels.email.enabled'),
            'schoolTimezone' => $school->timezone,
            // Brief §8: only a communications.manage-capable sender may
            // ever mark a communication Required.
            'canMarkRequired' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.manage', $school),
            // Phase 5A.10 §11: a distinct, elevated capability -- never
            // inferred from `canMarkRequired`/`communications.announce`.
            'canDispatchEmergency' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.emergency', $school),
            'template' => $template === null ? null : [
                'id' => $template->id,
                'title' => $template->subject,
                'body' => $template->body,
                'priority' => $template->priority,
            ],
        ]);
    }

    public function store(Request $request, TenantContext $context, AnnouncementService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $canMarkRequired = app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.manage', $school);
        $canDispatchEmergency = app(CapabilityResolver::class)->canInSchool($context->actor(), 'communications.emergency', $school);

        $validated = $this->validateComposer($request, $canMarkRequired, $canDispatchEmergency);

        try {
            $announcement = $service->createDraft(
                $school,
                $context->actor(),
                $validated['title'],
                $validated['body'],
                CommunicationPriority::from($validated['priority']),
                CommunicationAudienceType::from($validated['audience_type']),
                $validated['member_user_ids'] ?? [],
                channels: $this->channelsFromInput($validated),
                sourceTemplateId: $this->verifiedTemplateId($validated['source_template_id'] ?? null),
                requirement: CommunicationRequirement::from($validated['requirement'] ?? 'optional'),
                dispatchMode: CommunicationDispatchMode::from($validated['dispatch_mode'] ?? 'standard'),
                emergencyJustification: $validated['emergency_justification'] ?? null,
            );
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['member_user_ids' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$announcement->id}");
    }

    public function show(
        TenantContext $context,
        AnnouncementService $service,
        EmailAddressResolver $emailResolver,
        CommunicationChannelPolicyService $channelPolicy,
        string $announcement,
    ): Response {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.view', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->with(['createdBy:id,name', 'requestedChannels', 'attachments'])->findOrFail($announcement);

        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        $isCreator = $model->created_by_user_id === $actor->id;
        $isRecipient = CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $model->id)
            ->where('user_id', $actor->id)
            ->exists();

        abort_unless($isCreator || $isRecipient || $canManage, 403);

        // Brief §14: only the genuine recipient's own in-app read
        // state moves -- the creator/manager viewing their own
        // announcement never marks a recipient's delivery read.
        if ($isRecipient) {
            $service->markRead($model, $actor);
        }

        $requestedChannels = $model->requestedChannels->pluck('channel')->all();
        // Brief §17: a scheduling preview is estimated/non-authoritative
        // for a SCHEDULED announcement too, computed the same live way
        // as a draft's -- the real snapshot only exists once
        // publish() actually runs at due time.
        $preview = $model->isEditable() ? $service->previewAudience($model) : null;

        $canEditOrSchedule = ($isCreator || $canManage) && $model->isEditable();
        $canDispatchEmergency = app(CapabilityResolver::class)->canInSchool($actor, 'communications.emergency', $school);

        return Inertia::render('App/Communications/Announcements/Show', [
            // Phase 5A.10 §41: `emergencyJustification` is restricted
            // server-side -- never sent to the payload at all for an
            // unauthorized viewer, rather than sent and hidden in Vue.
            'announcement' => $this->presentDetail($model, includeEmergencyJustification: $canManage || $canDispatchEmergency),
            'requestedChannels' => $requestedChannels,
            'emailChannelEnabled' => (bool) config('communications.channels.email.enabled'),
            'schoolTimezone' => $school->timezone,
            'canMarkRequired' => $canManage,
            'canDispatchEmergency' => $canDispatchEmergency,
            'preview' => $preview === null ? null : [
                'count' => $preview->count(),
                'categoryBreakdown' => $preview->categoryBreakdown,
                'email' => in_array('email', $requestedChannels, true)
                    ? $this->emailPreview($preview, $model, $school, $emailResolver, $channelPolicy, $context)
                    : null,
            ],
            'channelDeliverySummary' => $model->isPublished() ? $this->channelDeliverySummary($model) : null,
            'canEdit' => $canEditOrSchedule,
            'canSchedule' => $canEditOrSchedule,
            'canCancel' => ($isCreator || $canManage) && $model->isEditable(),
            'canAnnounce' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.announce', $school),
            'attachments' => $model->attachments->map(fn (CommunicationAttachment $a) => $this->presentAttachment($a))->all(),
            'canManageAttachments' => $canEditOrSchedule,
            // Phase 5A.11 §34/§12/§30: the announcement detail page's
            // own links into the audit timeline / delivery analytics
            // surfaces -- gated by the SAME capabilities those
            // controllers themselves enforce, never inferred from
            // `canManage` alone for audit (communications.audit.view is
            // its own capability).
            'canViewAudit' => app(CapabilityResolver::class)->canInSchool($actor, 'communications.audit.view', $school),
            'canViewAnalytics' => $canManage,
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

        // Phase 5A.10 §12/§27: computed fresh for THIS actor on every
        // update -- an unauthorized editor's payload can never contain
        // `dispatch_mode=emergency` at all (rejected by validateComposer()'s
        // Rule::in() below), whether or not the draft is already
        // Emergency. Omitting the field from their request (which the
        // Vue composer does automatically) simply leaves the existing
        // value untouched.
        $canDispatchEmergency = app(CapabilityResolver::class)->canInSchool($actor, 'communications.emergency', $school);

        $validated = $this->validateComposer($request, $canManage, $canDispatchEmergency);

        try {
            $service->updateDraft(
                $model,
                $actor,
                $validated['title'],
                $validated['body'],
                CommunicationPriority::from($validated['priority']),
                $model->audienceTypeEnum() === CommunicationAudienceType::Individual ? ($validated['member_user_ids'] ?? []) : null,
                channels: isset($validated['channels']) ? $this->channelsFromInput($validated) : null,
                requirement: isset($validated['requirement']) ? CommunicationRequirement::from($validated['requirement']) : null,
                dispatchMode: isset($validated['dispatch_mode']) ? CommunicationDispatchMode::from($validated['dispatch_mode']) : null,
                emergencyJustification: $validated['emergency_justification'] ?? null,
            );
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['body' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    public function publish(Request $request, TenantContext $context, AnnouncementService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        // Phase 5A.10 §12/§21: PUBLISHING an already-Emergency draft is
        // the moment its bypass-capable semantics actually take effect
        // -- gated by the SAME elevated capability that was required to
        // declare it, not only the capability that declared it earlier.
        // Do not treat acknowledgement (below) as authorization -- this
        // capability check is independent of it (brief §22).
        if ($model->isEmergency()) {
            $this->authorizeCapability('communications.emergency', $school);
        }

        // Phase 5A.10 §22/§38: a deliberate, explicit, server-enforced
        // re-confirmation immediately before an Emergency publish --
        // required ONLY for an Emergency announcement, never for a
        // STANDARD one. A bare `accepted` validation rule is one of
        // Laravel's IMPLICIT rules (it runs even when the field is
        // entirely absent from the request), so it cannot be made
        // conditional via `Rule::requiredIf()` alone -- an ordinary
        // STANDARD publish() (still posting an empty body, unchanged
        // from Phase 5A.4) would fail validation. A plain imperative
        // check avoids that trap entirely.
        if ($model->isEmergency() && ! $request->boolean('acknowledged')) {
            throw ValidationException::withMessages([
                'acknowledged' => ['You must acknowledge this Emergency communication before publishing.'],
            ]);
        }

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
     * Brief §14/§24: one endpoint for BOTH the initial schedule
     * (draft -> scheduled) and a reschedule (already scheduled, time
     * changed) -- dispatches to AnnouncementService::schedule()/
     * reschedule() based on the announcement's CURRENT status, so the
     * composer only needs one "Set schedule" form regardless of which
     * case applies. `scheduled_at` is a school-timezone-local naive
     * datetime string (brief §18); converted to UTC here, once, before
     * ever reaching the service -- the service only ever deals in UTC
     * Carbon instances.
     */
    public function schedule(Request $request, TenantContext $context, AnnouncementService $service, string $announcement): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);
        $actor = $context->actor();

        $model = CommunicationAnnouncement::query()->findOrFail($announcement);
        $canManage = app(CapabilityResolver::class)->canInSchool($actor, 'communications.manage', $school);
        abort_unless($model->created_by_user_id === $actor->id || $canManage, 403);

        $validated = $request->validate(['scheduled_at' => ['required', 'string']]);

        try {
            $scheduledAtUtc = Carbon::parse($validated['scheduled_at'], SchoolTimezone::resolve($school))->utc();
        } catch (\Exception) {
            throw ValidationException::withMessages(['scheduled_at' => ['The scheduled time is not a valid date/time.']]);
        }

        try {
            if ($model->isScheduled()) {
                $service->reschedule($model, $actor, $scheduledAtUtc);
            } else {
                $service->schedule($model, $actor, $scheduledAtUtc);
            }
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['scheduled_at' => [$e->getMessage()]]);
        }

        return redirect("/app/communications/announcements/{$model->id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateComposer(Request $request, bool $canMarkRequired, bool $canDispatchEmergency = false): array
    {
        // Brief §17/§40: 'email' is only an ACCEPTABLE value when the
        // channel is currently enabled -- a forged HTTP payload
        // requesting it while disabled fails validation (422) here,
        // never silently reaches AnnouncementService.
        $allowedChannels = array_merge(
            ['in_app'],
            config('communications.channels.email.enabled') ? ['email'] : [],
        );

        // Brief §8/§37: 'required' is only an ACCEPTABLE value for a
        // communications.manage-capable sender -- a forged payload
        // from anyone else fails validation (422) here, the same
        // dynamic-allowed-values pattern as the channel gate above.
        // This also covers a template that (however unlikely, since
        // templates carry no requirement field at all -- brief §37)
        // an unauthorized user tried to leverage into a required
        // communication.
        $allowedRequirements = array_merge(['optional'], $canMarkRequired ? ['required'] : []);

        // Phase 5A.10 §12/§27: the SAME dynamic-allowed-values pattern
        // as `requirement` above, but for `communications.emergency`.
        // 'standard' is always acceptable (downgrading an Emergency
        // draft never needs the elevated capability -- it removes
        // authority rather than granting it); 'emergency' is rejected
        // (422) from anyone who lacks the capability, whether they are
        // trying to newly declare it OR merely resubmitting an
        // already-Emergency draft's unchanged value.
        $allowedDispatchModes = array_merge(['standard'], $canDispatchEmergency ? ['emergency'] : []);

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'in:normal,important,urgent,critical'],
            'audience_type' => ['required', 'in:individual,school_wide'],
            'member_user_ids' => ['required_if:audience_type,individual', 'array'],
            'member_user_ids.*' => ['string'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', Rule::in($allowedChannels)],
            'source_template_id' => ['nullable', 'string'],
            'requirement' => ['sometimes', 'string', Rule::in($allowedRequirements)],
            'dispatch_mode' => ['sometimes', 'string', Rule::in($allowedDispatchModes)],
            'emergency_justification' => ['required_if:dispatch_mode,emergency', 'nullable', 'string', 'max:500'],
            // Phase 5A.10 §22: a deliberate composer-time confirmation,
            // distinct from the publish-time one in publish() -- never
            // itself treated as authorization. `accepted_if` (unlike a
            // bare `accepted`, which is one of Laravel's IMPLICIT rules
            // and runs even when the field is entirely absent) only
            // requires this when dispatch_mode is actually 'emergency'
            // -- every ordinary STANDARD submission is unaffected.
            'emergency_acknowledged' => ['accepted_if:dispatch_mode,emergency'],
        ]);
    }

    /**
     * Brief §9's "a request parameter naming an X is not authorization
     * to use it" applies to a client-supplied template id exactly like
     * a School id -- re-verified against the current School and
     * `active` status here rather than trusted from the form's hidden
     * field. An invalid id is silently dropped (announcement is simply
     * created with no source template) rather than a validation error,
     * matching create()'s own "convenience, not a hard dependency"
     * policy.
     */
    private function verifiedTemplateId(?string $templateId): ?string
    {
        if ($templateId === null) {
            return null;
        }

        return CommunicationTemplate::query()->where('status', 'active')->find($templateId)?->id;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, CommunicationChannel>
     */
    private function channelsFromInput(array $validated): array
    {
        return array_map(
            fn (string $value) => CommunicationChannel::from($value),
            $validated['channels'] ?? ['in_app'],
        );
    }

    /**
     * Brief §35: distinguishes "policy would suppress this channel"
     * (recipient preference or School policy -- brief's own example:
     * "19 optional-email disabled") from "no usable address at all"
     * (brief's "8 missing email") -- NOT the same thing, and never
     * collapsed into one count. Non-authoritative, exactly like
     * ResolvedAudience::previewAudience() itself (brief §14/§17) --
     * the definitive per-recipient decision is made again at
     * publish/due time.
     *
     * @return array{eligible: int, missing: int, policySuppressed: int}
     */
    private function emailPreview(
        ResolvedAudience $preview,
        CommunicationAnnouncement $model,
        School $school,
        EmailAddressResolver $emailResolver,
        CommunicationChannelPolicyService $channelPolicy,
        TenantContext $context,
    ): array {
        if ($preview->isEmpty()) {
            return ['eligible' => 0, 'missing' => 0, 'policySuppressed' => 0];
        }

        $memberships = $context->withSchool(
            $school,
            fn () => SchoolMembership::query()
                ->where('school_id', $school->id)
                ->whereIn('user_id', $preview->userIds)
                ->active()
                ->get(['id', 'user_id'])
                ->keyBy('user_id'),
        );

        $channelPolicy->preloadPreferences($school, $memberships->pluck('id')->all(), CommunicationChannel::Email);

        $requirement = $model->requirementEnum();
        $policySuppressed = 0;
        $notSuppressedUserIds = [];

        foreach ($preview->userIds as $userId) {
            $decision = $channelPolicy->evaluate($school, $memberships->get($userId)?->id, CommunicationChannel::Email, $requirement);

            if ($decision->allowed) {
                $notSuppressedUserIds[] = $userId;
            } else {
                $policySuppressed++;
            }
        }

        $eligible = $notSuppressedUserIds === [] ? 0 : User::query()
            ->whereIn('id', $notSuppressedUserIds)
            ->get(['id', 'email'])
            ->filter(fn (User $user) => $emailResolver->resolve($user) !== null)
            ->count();

        return [
            'eligible' => $eligible,
            'missing' => count($notSuppressedUserIds) - $eligible,
            'policySuppressed' => $policySuppressed,
        ];
    }

    /**
     * Read-only aggregation over the published Announcement's own
     * deliveries -- brief §29: per-channel/status/failure-code counts,
     * never individual destination addresses (§28: "Do not expose
     * individual addresses unless needed and authorized").
     *
     * @return array<string, array<int, array{status: string, failureCode: string|null, count: int}>>
     */
    private function channelDeliverySummary(CommunicationAnnouncement $model): array
    {
        if ($model->message_id === null) {
            return [];
        }

        $rows = DB::table('communication_deliveries as cd')
            ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
            ->where('cr.message_id', $model->message_id)
            ->select('cd.channel', 'cd.status', 'cd.failure_code', DB::raw('count(*) as delivery_count'))
            ->groupBy('cd.channel', 'cd.status', 'cd.failure_code')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $summary[$row->channel][] = [
                'status' => $row->status,
                'failureCode' => $row->failure_code,
                'count' => (int) $row->delivery_count,
            ];
        }

        // Brief §21: a SUPPRESSED channel never created a
        // CommunicationDelivery row at all -- its own ledger is the
        // only place this shows up, surfaced here with a distinct
        // pseudo-status ('suppressed') so the UI never confuses "we
        // tried and it failed" with "we deliberately never tried."
        $suppressed = DB::table('communication_delivery_policy_decisions')
            ->where('message_id', $model->message_id)
            ->select('channel', 'reason', DB::raw('count(*) as decision_count'))
            ->groupBy('channel', 'reason')
            ->get();

        foreach ($suppressed as $row) {
            $summary[$row->channel][] = [
                'status' => 'suppressed',
                'failureCode' => $row->reason,
                'count' => (int) $row->decision_count,
            ];
        }

        return $summary;
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
            'requirement' => $a->requirement,
            // Phase 5A.10 §41: the dispatch mode itself is safe to show
            // any authorized viewer (a recipient may see an
            // announcement is marked Emergency) -- only the internal
            // justification is restricted (see presentDetail()).
            'dispatchMode' => $a->dispatch_mode,
            'scheduledAt' => $a->scheduled_at?->toIso8601String(),
            'sourceTemplateId' => $a->source_template_id,
            'requestedChannels' => $a->relationLoaded('requestedChannels')
                ? $a->requestedChannels->pluck('channel')->all()
                : null,
            'publishedAt' => $a->published_at?->toIso8601String(),
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(CommunicationAnnouncement $a, bool $includeEmergencyJustification = false): array
    {
        return array_merge($this->presentSummary($a), [
            'body' => $a->body,
            // Phase 5A.10 §41: never included at all for an
            // unauthorized viewer -- restricted server-side, not
            // merely hidden in Vue.
            'emergencyJustification' => $includeEmergencyJustification ? $a->emergency_justification : null,
        ]);
    }

    /**
     * Brief §27/§46: metadata only -- never the storage disk/path/key.
     * Downloads always go through the authorized
     * CommunicationAttachmentController::download() endpoint, never a
     * raw storage URL embedded in this payload.
     *
     * @return array<string, mixed>
     */
    private function presentAttachment(CommunicationAttachment $a): array
    {
        return [
            'id' => $a->id,
            'displayName' => $a->safe_display_name,
            'mimeType' => $a->mime_type,
            'sizeBytes' => $a->size_bytes,
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
