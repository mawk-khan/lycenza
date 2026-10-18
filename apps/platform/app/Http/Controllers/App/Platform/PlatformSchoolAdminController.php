<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Audit\PlatformAuditLogReviewService;
use App\Domain\Platform\Application\Schools\Registry\SchoolRegistryFilters;
use App\Domain\Platform\Application\Schools\Registry\SchoolRegistryReadService;
use App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService;
use App\Domain\Platform\Application\Schools\SchoolClosureReason;
use App\Domain\Platform\Application\Schools\SchoolLifecycleAuthority;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Domain\Platform\Application\Schools\SchoolSuspensionReason;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.9 (ADR 0047 sections 7, 16): the narrow platform School
 * lifecycle surface -- list, create (with the bootstrap administrator),
 * replace that administrator while `provisioning`, activate, suspend,
 * resume. Context-neutral: no School context is set. No archive, no
 * delete, no membership or role administration, no API route.
 *
 * ORG.3 (ADR 0076 §9.2): the list and the overview are the School
 * Registry, under `platform.schools.view` (route middleware). They show
 * non-business tenant metadata only, through SchoolRegistryReadService
 * (one School's TenantContext at a time for Campus, academic-year and
 * administrator summaries). The bootstrap administrator's identity is
 * shown only while `provisioning`. The recent platform governance events
 * appear only through the platform audit review (`platform.audit.view` +
 * current MFA assurance, one access event). Lifecycle pages and actions
 * keep `platform.schools.manage`; every change reaches the lifecycle
 * services, which authorize, audit refusals and require explicit
 * confirmation plus a fresh MFA re-verification.
 */
class PlatformSchoolAdminController extends Controller
{
    private const ACTIONS = ['activate', 'suspend', 'resume', 'bootstrap-admin', 'close', 'reopen'];

    public function __construct(
        private readonly SchoolLifecycleService $lifecycle,
        private readonly SchoolBootstrapAdministrationService $bootstrap,
        private readonly SchoolLifecycleAuthority $authority,
        private readonly SchoolRegistryReadService $registry,
        private readonly PlatformAuditLogReviewService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:'.SchoolRegistryReadService::MAX_SEARCH_LENGTH],
            'status' => ['nullable', Rule::in(SchoolRegistryReadService::STATUS_FILTERS)],
            'group' => ['nullable', 'uuid'],
            'domain' => ['nullable', Rule::in(SchoolRegistryReadService::DOMAIN_FILTERS)],
            'cursor' => ['nullable', 'string', 'max:512', fn ($attribute, $value, $fail) => SchoolRegistryReadService::isValidCursor((string) $value) ? null : $fail('The page link is no longer valid.')],
            'per_page' => ['nullable', 'integer', 'between:'.SchoolRegistryReadService::MIN_PER_PAGE.','.SchoolRegistryReadService::MAX_PER_PAGE],
        ]);

        $page = $this->registry->list(new SchoolRegistryFilters(
            search: $validated['q'] ?? null,
            status: $validated['status'] ?? null,
            groupId: $validated['group'] ?? null,
            domain: $validated['domain'] ?? null,
            cursor: $validated['cursor'] ?? null,
            perPage: (int) ($validated['per_page'] ?? SchoolRegistryReadService::DEFAULT_PER_PAGE),
        ));

        return Inertia::render('App/Platform/Schools/Index', [
            ...$page,
            'filters' => [
                'q' => $validated['q'] ?? '',
                'status' => $validated['status'] ?? null,
                'group' => $validated['group'] ?? null,
                'domain' => $validated['domain'] ?? null,
                'perPage' => $page['perPage'],
                'paged' => isset($validated['cursor']),
            ],
            'options' => [
                'statuses' => SchoolRegistryReadService::STATUS_FILTERS,
                'domains' => SchoolRegistryReadService::DOMAIN_FILTERS,
                'groups' => SchoolGroup::query()->where('status', SchoolGroup::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (SchoolGroup $g): array => ['id' => $g->id, 'name' => $g->name])->values(),
            ],
            'canCreate' => $this->canManage($request),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('App/Platform/Schools/Create', [
            'mfaEnrolled' => $this->authority->hasActiveFactor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $school = $this->attempt(fn () => $this->lifecycle->create(
            $request,
            $request->user(),
            $request->only(['name', 'slug', 'code', 'admin']),
            $request->input('confirmed'),
            $request->input('mfa_code'),
        ));

        return redirect()->route('app.platform.schools.show', $school)->with('lifecycleNotice', 'created');
    }

    public function show(Request $request, School $school, SensitiveReadAssurance $assurance): Response
    {
        $canManage = $this->canManage($request);

        return Inertia::render('App/Platform/Schools/Show', [
            'school' => $this->summary($school),
            'registry' => $this->registry->overview($school),
            'audit' => $this->auditSummary($request, $school, $assurance),
            'canManage' => $canManage,
            'bootstrapAdmins' => $this->bootstrapAdmins($school),
            // Offered only to `platform.schools.manage`; the lifecycle services
            // remain the authority for every change.
            'actions' => array_map(fn (bool $offered): bool => $offered && $canManage, [
                'activate' => $school->isProvisioning(),
                'replaceBootstrapAdmin' => $school->isProvisioning(),
                'suspend' => $school->isActive(),
                'resume' => $school->isSuspended() && ! $school->isClosed(),
                'close' => ($school->isActive() || $school->isSuspended()) && ! $school->isClosed(),
                'reopen' => $school->isClosed(),
            ]),
            'notice' => $request->session()->get('lifecycleNotice'),
        ]);
    }

    /** The review step of one action: what changes, then confirm with a fresh code. */
    public function review(Request $request, School $school, string $action): Response
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        return Inertia::render('App/Platform/Schools/Action', [
            'school' => $this->summary($school),
            'action' => $action,
            'targetStatus' => match ($action) {
                'activate', 'resume', 'reopen' => SchoolStatus::Active->value,
                'suspend', 'close' => SchoolStatus::Suspended->value,
                default => null,
            },
            'reasons' => $action === 'close'
                ? array_map(fn (SchoolClosureReason $r) => ['value' => $r->value, 'label' => $r->label()], SchoolClosureReason::cases())
                : array_map(fn (SchoolSuspensionReason $r) => ['value' => $r->value, 'label' => $r->label()], SchoolSuspensionReason::cases()),
            'bootstrapAdmins' => $this->bootstrapAdmins($school),
            'mfaEnrolled' => $this->authority->hasActiveFactor($request->user()),
        ]);
    }

    public function perform(Request $request, School $school, string $action): RedirectResponse
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);

        $user = $request->user();
        $confirmed = $request->input('confirmed');
        $code = $request->input('mfa_code');

        $this->attempt(fn () => match ($action) {
            'activate' => $this->lifecycle->activate($request, $user, $school, $confirmed, $code),
            'suspend' => $this->lifecycle->suspend($request, $user, $school, $request->input('reason_code'), $confirmed, $code),
            'resume' => $this->lifecycle->resume($request, $user, $school, $confirmed, $code),
            'close' => $this->lifecycle->close($request, $user, $school, $request->input('reason_code'), $confirmed, $code),
            'reopen' => $this->lifecycle->reopen($request, $user, $school, $confirmed, $code),
            'bootstrap-admin' => $this->bootstrap->replace($request, $user, $school, $request->input('admin'), $confirmed, $code),
        });

        return redirect()->route('app.platform.schools.show', $school)->with('lifecycleNotice', $action);
    }

    private function canManage(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('capability', [SchoolLifecycleAuthority::CAPABILITY, null]);
    }

    /**
     * ORG.3: the School's recent platform governance events, shown only
     * through the platform audit review (`platform.audit.view` + current MFA
     * assurance; the review service re-checks both and records the access).
     *
     * @return array{available: bool, reason: string|null, entries: list<array<string, string|null>>}
     */
    private function auditSummary(Request $request, School $school, SensitiveReadAssurance $assurance): array
    {
        if (Gate::forUser($request->user())->denies('capability', [PlatformAuditLogReviewService::VIEW_CAPABILITY, null])) {
            return ['available' => false, 'reason' => 'capability', 'entries' => []];
        }
        if (! $assurance->holds($request)) {
            return ['available' => false, 'reason' => 'mfa', 'entries' => []];
        }

        return ['available' => true, 'reason' => null, 'entries' => $this->audit->reviewSchool($request, $request->user(), $school)];
    }

    /**
     * A 403 refusal stays a 403; every other refusal is shown on the form
     * like a validation error.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function attempt(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (SchoolLifecycleDeniedException $e) {
            if ($e->getStatusCode() === 403 && $e->field !== 'mfa_code') {
                abort(403, $e->getMessage());
            }

            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }

    /**
     * @return array{id: string, name: string, slug: string, code: string|null, status: string}
     */
    private function summary(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'slug' => $school->slug,
            'code' => $school->code,
            'status' => $school->status,
            'closedAt' => $school->closed_at?->toIso8601String(),
            'closureReason' => $school->closure_reason,
        ];
    }

    /**
     * Shown only while the School is `provisioning` -- afterwards these are
     * ordinary School memberships the platform does not administer.
     *
     * @return list<array{name: string, email: string}>
     */
    private function bootstrapAdmins(School $school): array
    {
        if (! $school->isProvisioning()) {
            return [];
        }

        return array_map(fn (SchoolMembership $m) => [
            'name' => $m->user->name,
            'email' => $m->user->email,
        ], $this->bootstrap->currentAdministrators($school));
    }
}
