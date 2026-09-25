<?php

namespace App\Http\Controllers\App\Groups;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\ElevationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0N.5 (ADR 0045 section 12): the Group Admin's read-only view of
 * the Groups they hold `group.schools.view` in -- and nothing else. Only
 * platform tables are read (`school_groups`, `school_group_members`,
 * `schools`): the Group's id, name and status, and each member School's
 * id, name and status (all Confidential, owner-approved v1). No counts,
 * no tenant record, no other Group, no list of the other people holding
 * grants (grants are Sensitive and platform-governed). TenantContext is
 * never touched; the Group is authorized per request from the route.
 */
class SchoolGroupController extends Controller
{
    public function index(Request $request, CapabilityResolver $capabilities): Response
    {
        return Inertia::render('App/Groups/Index', [
            'groups' => $capabilities->groupsWith($request->user(), 'group.schools.view')
                ->map(fn (SchoolGroup $g) => ['id' => $g->id, 'name' => $g->name])
                ->values(),
        ]);
    }

    public function show(Request $request, SchoolGroup $schoolGroup, CapabilityResolver $capabilities, ElevationContext $elevated): Response
    {
        $user = $request->user();

        // A Group the actor holds no view authority in is indistinguishable
        // from one that does not exist.
        abort_unless($capabilities->canInGroup($user, 'group.schools.view', $schoolGroup), 404);

        $canElevate = ! $elevated->isElevated()
            && $capabilities->canInGroup($user, SchoolElevationService::GROUP_CAPABILITY, $schoolGroup);
        $memberOf = SchoolMembership::query()->active()->where('user_id', $user->id)->pluck('school_id')->all();

        return Inertia::render('App/Groups/Show', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name, 'status' => $schoolGroup->status],
            // Phase 0N.11 (ADR 0048): the Group report link, UX only -- the
            // report re-authorizes everything itself.
            'canViewReports' => $capabilities->canInGroup($user, 'group.reporting.view', $schoolGroup),
            'schools' => $schoolGroup->schools()->orderBy('schools.name')->get(['schools.id', 'schools.name', 'schools.status'])
                ->map(fn (School $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'status' => $s->status,
                    // Elevation is refused into a School the actor already
                    // belongs to (ADR 0044): offer ordinary selection instead.
                    'canEnter' => $canElevate && $s->status === 'active' && ! in_array($s->id, $memberOf, true),
                    'isMember' => in_array($s->id, $memberOf, true),
                ])->values(),
        ]);
    }
}
