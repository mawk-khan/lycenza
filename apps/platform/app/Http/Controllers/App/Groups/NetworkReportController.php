<?php

namespace App\Http\Controllers\App\Groups;

use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\NetworkReportReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupDirectoryReadService;
use App\Domain\Platform\Application\Groups\SchoolGroupRelationship;
use App\Http\Controllers\Controller;
use App\Models\SchoolGroup;
use App\Support\Reports\OrganizationReportCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12H (ADR 0080 §13.3): a School Group's Network MIS -- overview,
 * Schools, governance distribution and attention -- read-only, in Group
 * context only, scoped by GroupGovernanceAccess::viewScope() (no scope =
 * 404, like the Group page). Platform tables only; it never sets School
 * context and offers no link into a School (entering a School stays the
 * Group page's ADR 0044 elevation). No export.
 */
class NetworkReportController extends Controller
{
    public function index(Request $request, SchoolGroup $schoolGroup, GroupGovernanceAccess $access, SchoolGroupDirectoryReadService $directory, NetworkReportReadService $reports): Response
    {
        $scope = $access->viewScope($request->user(), $schoolGroup);
        abort_if($scope === null, 404);
        $filters = array_filter($request->only(['relationship', 'region_id', 'zone_id', 'status', 'q', 'attention', 'sort']), 'is_string');

        return Inertia::render('App/Groups/NetworkReport', [
            'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name],
            'scope' => $scope->describe(),
            'reports' => OrganizationReportCatalogue::keys(OrganizationReportCatalogue::NETWORK),
            'overview' => $directory->overview($schoolGroup, $scope),
            'lifecycle' => $reports->lifecycle($schoolGroup, $scope),
            'distribution' => $reports->distribution($schoolGroup, $scope),
            'filters' => $filters,
            'relationships' => SchoolGroupRelationship::options(),
            'sorts' => array_keys(SchoolGroupDirectoryReadService::SORTS),
            'directory' => $directory->directory($schoolGroup, $filters, (int) $request->query('page', 1), $scope)
                ->withQueryString()
                ->through(fn (object $row): array => SchoolGroupDirectoryReadService::present($row)),
        ]);
    }
}
