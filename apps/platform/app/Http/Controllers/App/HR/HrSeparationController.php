<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseReadService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.12 (owner decisions HR-061, 2026-11-04): the separation pages. Each
 * action validates, calls SeparationCaseService / the requirement service /
 * the read service, and redirects; every rule (capability, maker/checker,
 * never one's own, lock order, stale version) lives in those services. The
 * routes carry the whole-School `capability:` floor; the clearance route's
 * any-of floor lets the service decide the item's own responsibility.
 * Fresh MFA is taken after the capability check and outside the business
 * transaction (rule 95).
 */
class HrSeparationController extends Controller
{
    public function index(Request $request, TenantContext $context, SeparationCaseReadService $reads): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(SeparationCaseReadService::STATUS_FILTERS)],
            'reason' => ['nullable', Rule::in(SeparationReason::values())],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $school = $context->requireSchool();
        $actor = $context->actor();

        return Inertia::render('App/HR/Separations/Index', [
            'result' => $reads->page($school, $actor, ['status' => $validated['status'] ?? 'open', 'reason' => $validated['reason'] ?? null], (int) ($validated['page'] ?? 1)),
            'filters' => ['status' => $validated['status'] ?? 'open', 'reason' => $validated['reason'] ?? null],
            'reasons' => SeparationReason::options(),
            'canConfigure' => app(CapabilityResolver::class)->can($actor, 'hr.separations.configure', $school),
        ]);
    }

    public function create(Request $request, TenantContext $context, SeparationCaseReadService $reads): Response
    {
        $validated = $request->validate(['employment' => ['required', 'uuid']]);

        return Inertia::render('App/HR/Separations/Create', $reads->recordable($context->requireSchool(), $context->actor(), $validated['employment']));
    }

    public function store(Request $request, TenantContext $context, SeparationCaseService $cases): RedirectResponse
    {
        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'reason' => ['required', Rule::in(SeparationReason::values())],
            'notified_on' => ['required', 'date_format:Y-m-d'],
            'planned_last_day' => ['required', 'date_format:Y-m-d'],
        ]);

        $case = $cases->record($context->requireSchool(), $validated['employment_record_id'], SeparationReason::from($validated['reason']), $validated['notified_on'], $validated['planned_last_day'], $context->actor());

        return redirect()->route('app.hr.separations.show', $case->id)->with('flash', 'Separation recorded. Another person must approve it.');
    }

    public function show(TenantContext $context, SeparationCaseReadService $reads, string $separationCase): Response
    {
        return Inertia::render('App/HR/Separations/Show', [
            'case' => $reads->detail($context->requireSchool(), $context->actor(), $separationCase),
            'hasMfaFactor' => app(FreshMfaRequirement::class)->hasActiveFactor($context->actor()),
        ]);
    }

    public function approve(Request $request, TenantContext $context, SeparationCaseService $cases, FreshMfaRequirement $mfa, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $proof = $mfa->requireForAction($request, $request->user());
        $cases->approve($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor(), $proof);

        return back()->with('flash', 'Separation approved. The employment is now in its notice period.');
    }

    public function reject(Request $request, TenantContext $context, SeparationCaseService $cases, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $cases->reject($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor());

        return back()->with('flash', 'Separation rejected. The employment is unchanged.');
    }

    public function withdraw(Request $request, TenantContext $context, SeparationCaseService $cases, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $cases->withdraw($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor());

        return back()->with('flash', 'Separation withdrawn. The employment is unchanged.');
    }

    public function changeLastDay(Request $request, TenantContext $context, SeparationCaseService $cases, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'planned_last_day' => ['required', 'date_format:Y-m-d']]);
        $cases->changePlannedLastDay($context->requireSchool(), $separationCase, (int) $validated['version'], $validated['planned_last_day'], $context->actor());

        return back()->with('flash', 'Planned last working day changed.');
    }

    public function cancel(Request $request, TenantContext $context, SeparationCaseService $cases, FreshMfaRequirement $mfa, string $separationCase): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'integer'],
            'reason' => ['required', Rule::in(SeparationCancelReason::values())],
            'confirm' => ['accepted'],
        ]);
        $proof = $mfa->requireForAction($request, $request->user());
        $cases->cancel($context->requireSchool(), $separationCase, (int) $validated['version'], SeparationCancelReason::from($validated['reason']), $context->actor(), $proof);

        return back()->with('flash', 'Separation cancelled. The employment is active again.');
    }

    public function finalize(Request $request, TenantContext $context, SeparationCaseService $cases, FreshMfaRequirement $mfa, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $proof = $mfa->requireForAction($request, $request->user());
        $cases->finalize($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor(), $proof);

        return back()->with('flash', 'Separation finalized. The employment has ended.');
    }

    public function clearItem(TenantContext $context, SeparationCaseService $cases, string $separationCase, string $item): RedirectResponse
    {
        $cases->clearItem($context->requireSchool(), $separationCase, $item, $context->actor());

        return back()->with('flash', 'Clearance item cleared.');
    }

    public function recordAccessRetained(Request $request, TenantContext $context, SeparationCaseService $cases, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $cases->recordAccessRetained($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor());

        return back()->with('flash', 'Recorded that this School access is kept.');
    }

    public function complete(Request $request, TenantContext $context, SeparationCaseService $cases, string $separationCase): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer']]);
        $cases->complete($context->requireSchool(), $separationCase, (int) $validated['version'], $context->actor());

        return back()->with('flash', 'Offboarding complete.');
    }

    public function requirements(TenantContext $context, SeparationCaseReadService $reads): Response
    {
        return Inertia::render('App/HR/Separations/Requirements', [
            'requirements' => $reads->requirements($context->requireSchool(), $context->actor()),
            'responsibilities' => ClearanceResponsibility::options(),
            'staffTypes' => array_map(fn (StaffType $t) => ['value' => $t->value, 'label' => $t->label()], StaffType::cases()),
        ]);
    }

    public function storeRequirement(Request $request, TenantContext $context, SeparationClearanceRequirementService $requirements): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'label' => ['required', 'string', 'max:120'],
            'responsibility' => ['required', Rule::in(ClearanceResponsibility::values())],
            'mandatory' => ['required', 'boolean'],
            'staff_type' => ['nullable', Rule::in(array_map(fn (StaffType $t) => $t->value, StaffType::cases()))],
        ]);

        $requirements->create($context->requireSchool(), $validated['code'], $validated['label'], ClearanceResponsibility::from($validated['responsibility']),
            (bool) $validated['mandatory'], isset($validated['staff_type']) ? StaffType::from($validated['staff_type']) : null, $context->actor());

        return back()->with('flash', 'Clearance requirement added. It applies to separations approved from now on.');
    }

    public function retireRequirement(TenantContext $context, SeparationClearanceRequirementService $requirements, string $requirement): RedirectResponse
    {
        $requirements->retire($context->requireSchool(), $requirement, $context->actor());

        return back()->with('flash', 'Clearance requirement retired. Cases already approved keep their checklist.');
    }
}
