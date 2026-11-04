<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCancelReason;
use App\Domain\WorkforceTasks\Application\TaskCapabilities;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskFrequency;
use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Domain\WorkforceTasks\Application\TaskReadService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.13 (owner decisions 2026-11-04): the staff task pages. Each action
 * validates, calls the WorkforceTasks service, and renders or redirects; who
 * may do what (the administrative path, the current-manager path, the
 * assignee) is decided in the services. Routes carry the capability floor.
 */
class HrTaskController extends Controller
{
    private function statusFilter(Request $request): string
    {
        $status = $request->query('status', 'open');

        return is_string($status) && in_array($status, TaskReadService::STATUS_FILTERS, true) ? $status : 'open';
    }

    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    public function mine(Request $request, TenantContext $context, TaskReadService $reads): Response
    {
        return Inertia::render('App/HR/Tasks/Mine', ['result' => $reads->mine($context->requireSchool(), $context->actor(), $this->statusFilter($request), $this->page($request))]);
    }

    public function monitor(Request $request, TenantContext $context, TaskReadService $reads): Response
    {
        $filters = $request->validate([
            'category' => ['nullable', 'uuid'], 'department' => ['nullable', 'uuid'], 'position' => ['nullable', 'uuid'],
            'frequency' => ['nullable', Rule::in(TaskFrequency::values())],
            'staffType' => ['nullable', Rule::in(array_map(fn (StaffType $t) => $t->value, StaffType::cases()))],
        ]);
        $school = $context->requireSchool();
        $actor = $context->actor();
        $capabilities = app(CapabilityResolver::class);

        return Inertia::render('App/HR/Tasks/Monitor', [
            'result' => $reads->monitor($school, $actor, [...$filters, 'status' => $this->statusFilter($request)], $this->page($request)),
            'filters' => [...$filters, 'status' => $this->statusFilter($request)],
            'can' => [
                'configure' => $capabilities->can($actor, TaskCapabilities::CONFIGURE, $school),
                'assign' => $capabilities->can($actor, TaskCapabilities::MANAGE, $school) || $capabilities->can($actor, TaskCapabilities::TEAM, $school),
            ],
            'frequencies' => TaskFrequency::options(),
            'staffTypes' => array_map(fn (StaffType $t) => ['value' => $t->value, 'label' => $t->label()], StaffType::cases()),
        ]);
    }

    public function assignedByMe(Request $request, TenantContext $context, TaskReadService $reads): Response
    {
        return Inertia::render('App/HR/Tasks/AssignedByMe', ['result' => $reads->assignedByMe($context->requireSchool(), $context->actor(), $this->statusFilter($request), $this->page($request))]);
    }

    public function team(Request $request, TenantContext $context, TaskReadService $reads): Response
    {
        return Inertia::render('App/HR/Tasks/Team', ['result' => $reads->team($context->requireSchool(), $context->actor(), $this->statusFilter($request), $this->page($request))]);
    }

    public function show(TenantContext $context, TaskReadService $reads, string $task): Response
    {
        return Inertia::render('App/HR/Tasks/Show', ['task' => $reads->detail($context->requireSchool(), $context->actor(), $task)]);
    }

    public function create(TenantContext $context, TaskReadService $reads): Response
    {
        return Inertia::render('App/HR/Tasks/Assign', $reads->assignmentOptions($context->requireSchool(), $context->actor()));
    }

    public function assignees(Request $request, TenantContext $context, TaskReadService $reads): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $reads->assignees($context->requireSchool(), $context->actor(), $validated['q'] ?? null)]);
    }

    public function store(Request $request, TenantContext $context, TaskAssignmentService $assignments): RedirectResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'uuid'],
            'completion_mode' => ['required', Rule::in(['self', 'reviewed'])],
            'available_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'section_id' => ['nullable', 'uuid'],
            'subject_offering_id' => ['nullable', 'uuid'],
        ]);
        $task = $assignments->assign($context->requireSchool(), $validated['employment_record_id'], $validated, $context->actor());

        return redirect()->route('app.hr.tasks.show', $task->id)->with('flash', 'Task assigned.');
    }

    public function submit(Request $request, TenantContext $context, TaskWorkflowService $workflow, string $task): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:'.TaskWorkflowService::NOTE_MAX]]);
        $workflow->submit($context->requireSchool(), $task, (int) $validated['version'], $validated['remarks'] ?? null, $context->actor());

        return back()->with('flash', 'Task submitted.');
    }

    public function review(Request $request, TenantContext $context, TaskWorkflowService $workflow, string $task): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['complete', 'return'])],
            'feedback' => ['nullable', 'string', 'max:'.TaskWorkflowService::NOTE_MAX],
        ]);
        $workflow->review($context->requireSchool(), $task, (int) $validated['version'], $validated['decision'], $validated['feedback'] ?? null, $context->actor());

        return back()->with('flash', $validated['decision'] === 'complete' ? 'Task completed.' : 'Task returned for revision.');
    }

    public function cancel(Request $request, TenantContext $context, TaskWorkflowService $workflow, string $task): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'reason' => ['required', Rule::in(['no_longer_needed', 'assigned_in_error'])], 'confirm' => ['accepted']]);
        $workflow->cancel($context->requireSchool(), $task, (int) $validated['version'], TaskCancelReason::from($validated['reason']), $context->actor());

        return back()->with('flash', 'Task cancelled.');
    }

    public function reassign(Request $request, TenantContext $context, TaskAssignmentService $assignments, string $task): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'employment_record_id' => ['required', 'uuid']]);
        $new = $assignments->reassign($context->requireSchool(), $task, (int) $validated['version'], $validated['employment_record_id'], $context->actor());

        return redirect()->route('app.hr.tasks.show', $new->id)->with('flash', 'Task reassigned.');
    }

    public function catalogue(TenantContext $context, TaskReadService $reads): Response
    {
        return Inertia::render('App/HR/Tasks/Catalogue', [
            ...$reads->catalogue($context->requireSchool(), $context->actor()),
            'frequencies' => TaskFrequency::options(),
            'today' => SchoolTimezone::today($context->requireSchool()),
        ]);
    }

    public function storeCategory(Request $request, TenantContext $context, TaskCatalogueService $catalogue): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:40'], 'label' => ['required', 'string', 'max:80']]);
        $catalogue->createCategory($context->requireSchool(), $validated['code'], $validated['label'], $context->actor());

        return back()->with('flash', 'Category added.');
    }

    public function retireCategory(TenantContext $context, TaskCatalogueService $catalogue, string $category): RedirectResponse
    {
        $catalogue->retireCategory($context->requireSchool(), $category, $context->actor());

        return back()->with('flash', 'Category retired.');
    }

    public function storeTemplate(Request $request, TenantContext $context, TaskCatalogueService $catalogue): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:40'], ...self::termRules()]);
        $catalogue->createTemplate($context->requireSchool(), $validated['code'], $validated, $context->actor());

        return back()->with('flash', 'Recurring task added. It generates from its effective date.');
    }

    public function storeVersion(Request $request, TenantContext $context, TaskCatalogueService $catalogue, string $template): RedirectResponse
    {
        $validated = $request->validate(self::termRules());
        $catalogue->addVersion($context->requireSchool(), $template, $validated, $context->actor());

        return back()->with('flash', 'New version added. Tasks already created keep the version that created them.');
    }

    public function retireTemplate(TenantContext $context, TaskCatalogueService $catalogue, string $template): RedirectResponse
    {
        $catalogue->retireTemplate($context->requireSchool(), $template, $context->actor());

        return back()->with('flash', 'Recurring task retired. Tasks it already created stay.');
    }

    public function generate(TenantContext $context, TaskGenerationService $generation): RedirectResponse
    {
        $run = $generation->generateNow($context->requireSchool(), $context->actor());

        return back()->with('flash', "Generated {$run->created} new task(s) for {$run->occurrence_on->toDateString()} ({$run->existing} already existed, {$run->not_matched} not applicable).");
    }

    /** @return array<string, list<mixed>> */
    private static function termRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'uuid'],
            'completion_mode' => ['required', Rule::in(['self', 'reviewed'])],
            'frequency' => ['required', Rule::in(TaskFrequency::values())],
            'weekdays' => ['nullable', 'integer', 'between:1,127'],
            'month_day' => ['nullable', 'integer', 'between:1,31'],
            'last_day' => ['nullable', 'boolean'],
            'year_month' => ['nullable', 'integer', 'between:1,12'],
            'due_days' => ['required', 'integer', 'between:0,366'],
            'applies_teaching' => ['nullable', 'boolean'],
            'applies_non_teaching' => ['nullable', 'boolean'],
            'applies_outsourced' => ['nullable', 'boolean'],
            'department_id' => ['nullable', 'uuid'],
            'position_id' => ['nullable', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
