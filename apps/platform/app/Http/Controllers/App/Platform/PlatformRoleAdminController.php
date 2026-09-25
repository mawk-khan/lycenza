<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Http\Controllers\Controller;
use App\Models\PlatformRoleAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.7 (ADR 0046 sections 3-4): the minimum platform-role governance
 * surface -- grant or revoke `platform_auditor` for an exactly identified
 * person. No role picker (the root role is never offered, and the service
 * and database refuse it anyway), no capability editor, no search, no
 * bulk grant. The list of active runtime grants names people (Sensitive)
 * and is shown only behind `platform.role_grants.manage`.
 */
class PlatformRoleAdminController extends Controller
{
    public const ROLE = 'platform_auditor';

    public function __construct(private readonly PlatformRoleGovernanceService $governance) {}

    public function index(): Response
    {
        return Inertia::render('App/Platform/Roles', [
            'role' => ['key' => self::ROLE, 'name' => 'Platform Auditor'],
            'grants' => PlatformRoleAssignment::query()->active()
                ->whereNotNull('granted_by_user_id')
                ->whereHas('role', fn ($q) => $q->where('key', self::ROLE))
                ->with('user:id,name,email')
                ->orderBy('granted_at')
                ->get()
                ->map(fn (PlatformRoleAssignment $a) => [
                    'id' => $a->id,
                    'userName' => $a->user->name,
                    'userEmail' => $a->user->email,
                    'grantedAt' => $a->granted_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        $this->governance->grant($request->user(), (string) $request->input('user'), self::ROLE);

        return back();
    }

    public function revoke(Request $request, PlatformRoleAssignment $assignment): RedirectResponse
    {
        $this->governance->revoke($request->user(), $assignment);

        return back();
    }
}
