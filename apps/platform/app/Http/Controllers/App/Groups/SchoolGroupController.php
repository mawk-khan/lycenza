<?php

namespace App\Http\Controllers\App\Groups;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Http\Controllers\Controller;
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
 * `schools`, and since ORG.12D the Group's `school_group_regions`/`_zones`):
 * Group metadata and each member School's id, name, code, status and the
 * Group's own classification of it (all Confidential, OD-12.11). Counts
 * are of member Schools, Regions and Zones only -- never of a tenant record.
 * No other Group, no list of the other people holding grants (grants are
 * Sensitive and platform-governed). TenantContext is never touched; the
 * Group is authorized per request from the route. Read-only: a Group Admin
 * changes nothing (ADR 0045 invariant 6).
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

    /**
     * ORG.12D (ADR 0080 §9, OD-12.11): the Group overview and member-School
     * directory -- platform tables only, through SchoolGroupDirectoryReadService
     * (structural counts of member Schools, Regions and Zones; each School's
     * name, code, lifecycle status, relationship, Region, Zone and network
     * reference). No tenant table, no count of people, no Campus or setup data.
     * The directory is paginated and filterable; entering a School is still
     * ADR 0044 elevation, which grants no School permission.
     */
    public function show(Request $request, SchoolGroup $schoolGroup, CapabilityResolver $capabilities, ElevationContext $elevated, SchoolGroupDirectoryReadService $directory): Response
    {
        $user = $request->user();

        // A Group the actor holds no view authority in is indistinguishable
        // from one that does not exist.
        abort_unless($capabilities->canInGroup($user, 'group.schools.view', $schoolGroup), 404);

        $canElevate = ! $elevated->isElevated()
            && $capabilities->canInGroup($user, SchoolElevationService::GROUP_CAPABILITY, $schoolGroup);
        $memberOf = SchoolMembership::query()->active()->where('user_id', $user->id)->pluck('school_id')->all();
        $filters = array_filter($request->only(['relationship', 'region_id', 'zone_id', 'status', 'q']), 'is_string');

        return Inertia::render('App/Groups/Show', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name, 'status' => $schoolGroup->status],
            // Phase 0N.11 (ADR 0048): the Group report link, UX only -- the
            // report re-authorizes everything itself.
            'canViewReports' => $capabilities->canInGroup($user, 'group.reporting.view', $schoolGroup),
            'overview' => $directory->overview($schoolGroup),
            'filters' => $filters,
            'relationships' => SchoolGroupRelationship::options(),
            'directory' => $directory->directory($schoolGroup, $filters, (int) $request->query('page', 1))
                ->withQueryString()
                ->through(fn (object $row): array => [
                    ...SchoolGroupDirectoryReadService::present($row),
                    // Elevation is refused into a School the actor already
                    // belongs to (ADR 0044): offer ordinary selection instead.
                    'canEnter' => $canElevate && $row->status === 'active' && ! in_array($row->id, $memberOf, true),
                    'isMember' => in_array($row->id, $memberOf, true),
                ]),
        ]);
    }
}
