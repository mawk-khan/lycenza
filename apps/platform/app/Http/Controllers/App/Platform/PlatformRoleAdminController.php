<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Http\Controllers\Controller;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.7 (ADR 0046 sections 3-4): the minimum platform-role governance
 * surface -- grant or revoke a runtime-assignable platform role for an
 * exactly identified person. No role builder, no capability editor, no
 * search, no bulk grant. The list of active runtime grants names people
 * (Sensitive) and is shown only behind `platform.role_grants.manage`.
 *
 * ORG.4 (ADR 0076 §6-§8): the code allowlist
 * (PlatformRoleGovernanceService::RUNTIME_ASSIGNABLE) now offers Platform
 * Manager and Platform Auditor; the root role (shown as "Platform Admin")
 * is provisioned out of band and is never offered. The service and the
 * database refuse anything else, a self-grant, and a Manager + Auditor
 * combination.
 */
class PlatformRoleAdminController extends Controller
{
    /** Display order and product description of each runtime-assignable role. */
    public const ROLES = [
        'platform_manager' => 'Onboards Schools: creates them in provisioning and sets their first School administrator. Cannot activate, suspend or close a School, govern platform roles, elevate, or see the platform audit log.',
        'platform_auditor' => 'Read-only oversight: the School Registry, School Groups and the platform audit log (with MFA). Changes nothing.',
    ];

    public function __construct(private readonly PlatformRoleGovernanceService $governance) {}

    public function index(): Response
    {
        $names = Role::query()->where('scope', 'platform')->pluck('name', 'key');

        return Inertia::render('App/Platform/Roles', [
            'roles' => collect(self::ROLES)->filter(fn ($d, string $key) => in_array($key, PlatformRoleGovernanceService::RUNTIME_ASSIGNABLE, true))
                ->map(fn (string $description, string $key) => [
                    'key' => $key,
                    'name' => $names[$key] ?? $key,
                    'description' => $description,
                    'grants' => $this->grants($key),
                ])->values(),
            'exclusive' => PlatformRoleGovernanceService::EXCLUSIVE,
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        $this->governance->grant($request, $request->user(), (string) $request->input('user'), (string) $request->input('role'));

        return back();
    }

    public function revoke(Request $request, PlatformRoleAssignment $assignment): RedirectResponse
    {
        $this->governance->revoke($request, $request->user(), $assignment);

        return back();
    }

    /** @return list<array{id: string, userName: string, userEmail: string, grantedAt: string}> */
    private function grants(string $roleKey): array
    {
        return PlatformRoleAssignment::query()->active()
            ->whereNotNull('granted_by_user_id')
            ->whereHas('role', fn ($q) => $q->where('key', $roleKey))
            ->with('user:id,name,email')
            ->orderBy('granted_at')
            ->get()
            ->map(fn (PlatformRoleAssignment $a) => [
                'id' => $a->id,
                'userName' => $a->user->name,
                'userEmail' => $a->user->email,
                'grantedAt' => $a->granted_at->toIso8601String(),
            ])->values()->all();
    }
}
