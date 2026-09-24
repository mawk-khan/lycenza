<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Http\Controllers\Controller;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.5 (ADR 0045 section 5): the platform governance surface for
 * School Groups -- separate from the Group Admin's own view. Reading needs
 * `platform.school_groups.view`; boundary changes
 * `platform.school_groups.manage`; grants `platform.school_group_grants.manage`
 * (the grant list itself is Sensitive and is shown only to that
 * capability). Every change goes through SchoolGroupGovernanceService,
 * which re-checks the capability and audits. Exact identifiers only: a
 * School by UUID or verified domain, a person by exact email or UUID.
 * Holding these capabilities never makes the operator a Group Admin.
 */
class SchoolGroupAdminController extends Controller
{
    public function __construct(private readonly SchoolGroupGovernanceService $governance) {}

    public function index(Request $request, CapabilityResolver $capabilities): Response
    {
        return Inertia::render('App/Platform/Groups/Index', [
            'groups' => SchoolGroup::query()->orderBy('name')->get(['id', 'name', 'slug', 'status'])
                ->map(fn (SchoolGroup $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'status' => $g->status]),
            'canManage' => $capabilities->canPlatform($request->user(), SchoolGroupGovernanceService::MANAGE),
        ]);
    }

    public function show(Request $request, SchoolGroup $schoolGroup, CapabilityResolver $capabilities): Response
    {
        $user = $request->user();
        $canManageGrants = $capabilities->canPlatform($user, SchoolGroupGovernanceService::MANAGE_GRANTS);

        return Inertia::render('App/Platform/Groups/Show', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name, 'slug' => $schoolGroup->slug, 'status' => $schoolGroup->status],
            'schools' => $schoolGroup->schools()->orderBy('schools.name')->get(['schools.id', 'schools.name', 'schools.status'])
                ->map(fn (School $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status])->values(),
            'grants' => $canManageGrants
                ? $schoolGroup->grants()->active()->with(['user:id,name,email', 'role:id,key'])->orderBy('granted_at')->get()
                    ->map(fn (GroupRoleAssignment $g) => [
                        'id' => $g->id,
                        'userName' => $g->user->name,
                        'userEmail' => $g->user->email,
                        'role' => $g->role->key,
                        'grantedAt' => $g->granted_at->toIso8601String(),
                    ])->values()
                : null,
            'canManage' => $capabilities->canPlatform($user, SchoolGroupGovernanceService::MANAGE),
            'canManageGrants' => $canManageGrants,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = $this->governance->create($request->user(), (string) $request->input('name'), (string) $request->input('slug'));

        return redirect()->route('app.platform.groups.show', $group)->with('status', 'Group created.');
    }

    public function rename(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->rename($request->user(), $schoolGroup, (string) $request->input('name'));

        return back();
    }

    public function archive(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->archive($request->user(), $schoolGroup);

        return back();
    }

    public function addSchool(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->addSchool($request->user(), $schoolGroup, (string) $request->input('school'));

        return back();
    }

    public function removeSchool(Request $request, SchoolGroup $schoolGroup, School $school): RedirectResponse
    {
        $this->governance->removeSchool($request->user(), $schoolGroup, $school);

        return back();
    }

    public function grant(Request $request, SchoolGroup $schoolGroup): RedirectResponse
    {
        $this->governance->grant($request->user(), $schoolGroup, (string) $request->input('user'));

        return back();
    }

    public function revoke(Request $request, SchoolGroup $schoolGroup, GroupRoleAssignment $grant): RedirectResponse
    {
        abort_unless($grant->school_group_id === $schoolGroup->id, 404);

        $this->governance->revoke($request->user(), $grant);

        return back();
    }
}
