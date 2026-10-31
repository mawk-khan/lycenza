<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.7 (OD-SIS-4): House administration. Thin: the list opens on the
 * Campus-adopted `students.houses.view` (counts follow the viewer's Student
 * scope -- a Campus reader never learns the School-wide size); writes need
 * whole-School `students.houses.manage` (route floor), re-checked by
 * HouseAdministrationService, the one writer.
 */
class HouseController extends Controller
{
    use AuthorizesCampusScope;

    public function index(TenantContext $context, HouseAdministrationService $houses): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(HouseAdministrationService::VIEW, $school);

        return Inertia::render('App/Students/Houses', [
            'houses' => $houses->list($school, $scope)->map(fn (array $row) => [
                'id' => $row['house']->id,
                'name' => $row['house']->name,
                'code' => $row['house']->code,
                'description' => $row['house']->description,
                'status' => $row['house']->status,
                'displayOrder' => $row['house']->display_order,
                'version' => $row['house']->version,
                'members' => $row['members'],
                'rosterUrl' => '/app/students/reports/students.houses?'.http_build_query(['house_id' => $row['house']->id]),
            ])->values()->all(),
            'countsScope' => $scope->isWholeSchool() ? 'whole_school' : 'campus',
            'canManage' => Gate::forUser($context->actor())->allows('capability', [HouseAdministrationService::MANAGE, $school]),
        ]);
    }

    public function store(Request $request, TenantContext $context, HouseAdministrationService $houses): RedirectResponse
    {
        $houses->create($context->requireSchool(), $context->actor(), $request->only(['name', 'code', 'description', 'display_order']));

        return redirect('/app/students/houses')->with('flash', 'House created.');
    }

    public function update(Request $request, TenantContext $context, HouseAdministrationService $houses, string $house): RedirectResponse
    {
        $houses->update($context->requireSchool(), $context->actor(), $house, $request->only(['name', 'code', 'description', 'display_order', 'version']));

        return redirect('/app/students/houses')->with('flash', 'House saved.');
    }

    public function status(Request $request, TenantContext $context, HouseAdministrationService $houses, string $house): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $houses->setStatus($context->requireSchool(), $context->actor(), $house, $validated['status'], (int) $validated['version']);

        return redirect('/app/students/houses')->with('flash', $validated['status'] === 'inactive' ? 'House deactivated.' : 'House reactivated.');
    }
}
