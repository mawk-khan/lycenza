<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleAuthority;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Domain\Platform\Application\Schools\SchoolSuspensionReason;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.9 (ADR 0047 sections 7, 16): the narrow platform School
 * lifecycle surface -- list, create (with the bootstrap administrator),
 * replace that administrator while `provisioning`, activate, suspend,
 * resume. Context-neutral: no School context is set and no tenant-domain
 * table is read. It shows only the School's platform metadata (id, name,
 * slug, code, status: Confidential) and, for a `provisioning` School
 * only, its bootstrap administrator (Sensitive). No archive, no delete,
 * no membership or role administration, no counts, no API route.
 *
 * Reads need `platform.schools.manage` (route middleware); every change
 * reaches the lifecycle services, which authorize, audit refusals and
 * require explicit confirmation plus a fresh MFA re-verification.
 */
class PlatformSchoolAdminController extends Controller
{
    private const ACTIONS = ['activate', 'suspend', 'resume', 'bootstrap-admin'];

    public function __construct(
        private readonly SchoolLifecycleService $lifecycle,
        private readonly SchoolBootstrapAdministrationService $bootstrap,
        private readonly SchoolLifecycleAuthority $authority,
    ) {}

    public function index(): Response
    {
        return Inertia::render('App/Platform/Schools/Index', [
            'schools' => School::query()->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'slug', 'code', 'status'])
                ->map(fn (School $s) => $this->summary($s))->values(),
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

    public function show(Request $request, School $school): Response
    {
        return Inertia::render('App/Platform/Schools/Show', [
            'school' => $this->summary($school),
            'bootstrapAdmins' => $this->bootstrapAdmins($school),
            'actions' => [
                'activate' => $school->isProvisioning(),
                'replaceBootstrapAdmin' => $school->isProvisioning(),
                'suspend' => $school->isActive(),
                'resume' => $school->isSuspended(),
            ],
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
                'activate', 'resume' => SchoolStatus::Active->value,
                'suspend' => SchoolStatus::Suspended->value,
                default => null,
            },
            'reasons' => array_map(fn (SchoolSuspensionReason $r) => ['value' => $r->value, 'label' => $r->label()], SchoolSuspensionReason::cases()),
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
            'bootstrap-admin' => $this->bootstrap->replace($request, $user, $school, $request->input('admin'), $confirmed, $code),
        });

        return redirect()->route('app.platform.schools.show', $school)->with('lifecycleNotice', $action);
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
