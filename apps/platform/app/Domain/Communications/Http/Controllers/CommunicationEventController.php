<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Communications\Application\Events\CommunicationEventCalendar;
use App\Domain\Communications\Application\Events\CommunicationEventService;
use App\Domain\Communications\Application\Events\CommunicationEventTerms;
use App\Domain\Communications\Application\Exceptions\CommunicationException;
use App\Domain\Communications\Application\Exceptions\InvalidCommunicationEventException;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.9D-3 (ADR 0076 §75): Communications -> Events, the School calendar's
 * management surface. Every route carries `capability:communications.announce`;
 * changing an Event additionally needs to be its creator or hold
 * `communications.manage` (the announcement rule). Approval is decided in
 * the existing approvals queue (`communications.approve`). Thin: validate,
 * call CommunicationEventService / CommunicationEventCalendar, respond.
 */
class CommunicationEventController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CommunicationEventCalendar $calendar): Response
    {
        $school = $context->requireSchool();
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'pending_approval', 'approved', 'rejected', 'published', 'cancelled'])],
            'when' => ['sometimes', 'nullable', Rule::in([CommunicationEventCalendar::UPCOMING, CommunicationEventCalendar::PAST])],
            'audience' => ['sometimes', 'nullable', Rule::in(CommunicationEvent::AUDIENCES)],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $events = $calendar->manage($school, $filters, (int) ($filters['page'] ?? 1));

        return Inertia::render('App/Communications/Events/Index', [
            'events' => $events->items(),
            'meta' => ['currentPage' => $events->currentPage(), 'lastPage' => $events->lastPage(), 'total' => $events->total()],
            'filters' => ['status' => $filters['status'] ?? '', 'when' => $filters['when'] ?? '', 'audience' => $filters['audience'] ?? '', 'month' => $filters['month'] ?? ''],
            'schoolTimezone' => $school->timezone,
        ]);
    }

    public function create(Request $request, TenantContext $context, CommunicationEventCalendar $calendar, CurrentAcademicYearResolver $years): Response
    {
        $school = $context->requireSchool();
        $request->validate(['from' => ['sometimes', 'nullable', 'uuid']]);
        // "Create a corrected copy" of an Event the preparer can manage: a
        // prefill only -- the copy is a NEW draft with its own approval.
        $from = $request->filled('from')
            ? CommunicationEvent::query()->with(['gradeLevel:id,name', 'section:id,name,grade_level_id', 'section.gradeLevel:id,name'])->find($request->string('from')->value())
            : null;

        return Inertia::render('App/Communications/Events/Form', [
            'event' => $from === null ? null : $calendar->present($from, $school),
            'mode' => 'create',
            ...$this->formOptions($school, $years),
        ]);
    }

    public function store(Request $request, TenantContext $context, CommunicationEventService $events): RedirectResponse
    {
        $school = $context->requireSchool();
        $event = $this->guarded(fn () => $events->create($school, $context->actor(), CommunicationEventTerms::fromInput($school, $this->input($request))));

        return redirect("/app/communications/events/{$event->id}");
    }

    public function show(TenantContext $context, CommunicationEventCalendar $calendar, CommunicationEventService $events, string $event): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $model = CommunicationEvent::query()->with(['gradeLevel:id,name', 'section:id,name,grade_level_id', 'section.gradeLevel:id,name', 'createdBy:id,name'])->findOrFail($event);
        $mayChange = $this->mayChange($model, $actor, $school);
        $latest = CommunicationApprovalRequest::query()->where('event_id', $model->id)->with(['requestedBy:id,name', 'decidedBy:id,name'])->orderByDesc('requested_at')->first();

        return Inertia::render('App/Communications/Events/Show', [
            'event' => $calendar->present($model, $school),
            'schoolTimezone' => $school->timezone,
            'latestApprovalRequest' => $latest === null ? null : [
                'status' => $latest->status,
                'requestedByName' => $latest->requestedBy?->name,
                'requestedAt' => $latest->requested_at->toIso8601String(),
                'decidedByName' => $latest->decidedBy?->name,
                'decidedAt' => $latest->decided_at?->toIso8601String(),
                'decisionNote' => $latest->decision_note,
            ],
            'approvalCurrent' => $model->status === 'approved' && $events->approvalMatches($model),
            'can' => [
                'edit' => $mayChange && $model->isEditable(),
                'submit' => $mayChange && $model->status === 'draft',
                'withdraw' => $mayChange && $model->status === 'pending_approval',
                'publish' => $mayChange && $model->status === 'approved',
                'cancel' => $mayChange && $model->status !== 'cancelled',
            ],
        ]);
    }

    public function edit(TenantContext $context, CommunicationEventCalendar $calendar, CurrentAcademicYearResolver $years, string $event): Response
    {
        $school = $context->requireSchool();
        $model = CommunicationEvent::query()->with(['gradeLevel:id,name', 'section:id,name,grade_level_id', 'section.gradeLevel:id,name'])->findOrFail($event);
        abort_unless($this->mayChange($model, $context->actor(), $school) && $model->isEditable(), 403);

        return Inertia::render('App/Communications/Events/Form', [
            'event' => $calendar->present($model, $school),
            'mode' => 'edit',
            ...$this->formOptions($school, $years),
        ]);
    }

    public function update(Request $request, TenantContext $context, CommunicationEventService $events, string $event): RedirectResponse
    {
        $school = $context->requireSchool();
        $model = $this->changeable($context, $event);
        $this->guarded(fn () => $events->update($model, $context->actor(), CommunicationEventTerms::fromInput($school, $this->input($request))));

        return redirect("/app/communications/events/{$model->id}");
    }

    public function submit(TenantContext $context, CommunicationEventService $events, string $event): RedirectResponse
    {
        $model = $this->changeable($context, $event);
        $this->guarded(fn () => $events->submit($model, $context->actor()));

        return redirect("/app/communications/events/{$model->id}");
    }

    public function withdraw(TenantContext $context, CommunicationEventService $events, string $event): RedirectResponse
    {
        $model = $this->changeable($context, $event);
        $this->guarded(fn () => $events->withdraw($model, $context->actor()));

        return redirect("/app/communications/events/{$model->id}");
    }

    public function publish(TenantContext $context, CommunicationEventService $events, string $event): RedirectResponse
    {
        $model = $this->changeable($context, $event);
        $this->guarded(fn () => $events->publish($model, $context->actor()));

        return redirect("/app/communications/events/{$model->id}");
    }

    public function cancel(TenantContext $context, CommunicationEventService $events, string $event): RedirectResponse
    {
        $model = $this->changeable($context, $event);
        $this->guarded(fn () => $events->cancel($model, $context->actor()));

        return redirect("/app/communications/events/{$model->id}");
    }

    private function mayChange(CommunicationEvent $event, User $actor, School $school): bool
    {
        return (string) $event->created_by_user_id === (string) $actor->id
            || Gate::forUser($actor)->allows('capability', ['communications.manage', $school]);
    }

    private function changeable(TenantContext $context, string $event): CommunicationEvent
    {
        $school = $context->requireSchool();
        $model = CommunicationEvent::query()->findOrFail($event);
        abort_unless($this->mayChange($model, $context->actor(), $school), 403);

        return $model;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function guarded(callable $step): mixed
    {
        try {
            return $step();
        } catch (InvalidCommunicationEventException $e) {
            throw ValidationException::withMessages([$e->field => [$e->getMessage()]]);
        } catch (CommunicationException $e) {
            throw ValidationException::withMessages(['event' => [$e->getMessage()]]);
        }
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:200'],
            'date_mode' => ['required', Rule::in([CommunicationEvent::ALL_DAY, CommunicationEvent::TIMED])],
            'starts_on' => ['nullable', 'string', 'max:10'],
            'ends_on' => ['nullable', 'string', 'max:10'],
            'starts_at' => ['nullable', 'string', 'max:19'],
            'ends_at' => ['nullable', 'string', 'max:19'],
            'audience_type' => ['required', Rule::in(CommunicationEvent::AUDIENCES)],
            'grade_level_id' => ['nullable', 'uuid'],
            'section_id' => ['nullable', 'uuid'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(School $school, CurrentAcademicYearResolver $years): array
    {
        $year = $years->tryResolve($school);

        return [
            'schoolTimezone' => $school->timezone,
            'gradeLevels' => GradeLevel::query()->where('status', 'active')->orderBy('sequence')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values()->all(),
            'sections' => $year === null ? [] : Section::query()->where('academic_year_id', $year->id)->where('status', 'active')->with('gradeLevel:id,name,sequence')->get()
                ->sortBy(fn (Section $s) => [$s->gradeLevel?->sequence, $s->name])
                ->map(fn (Section $s) => ['id' => $s->id, 'label' => trim("{$s->gradeLevel?->name} · {$s->name}")])->values()->all(),
        ];
    }
}
