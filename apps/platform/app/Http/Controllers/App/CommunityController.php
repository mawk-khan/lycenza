<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.8 (OD-SIS-5A, OD-SIS-5B): the Student Communities hub, and Club and
 * Activity administration. Thin: the type comes from the route (a closed
 * default, never input); the lists open on the Campus-adopted
 * `students.communities.view` (counts follow the viewer's Student scope);
 * writes need whole-School `students.communities.manage` (route floor),
 * re-checked by CommunityAdministrationService.
 */
class CommunityController extends Controller
{
    use AuthorizesCampusScope, PresentsCommunityDirectory;

    public function hub(TenantContext $context, CommunityAdministrationService $communities): Response
    {
        $areas = $communities->hub($context->requireSchool(), $context->actor());

        return Inertia::render('App/Students/Communities', [
            'areas' => array_map(function (string $value) use ($areas): array {
                $type = StudentGroupType::from($value);

                return [
                    'type' => $type->value,
                    'label' => $type->plural(),
                    'url' => '/app/students/'.strtolower($type->plural()),
                    'campus' => ! $areas[$value]->isWholeSchool(),
                ];
            }, array_keys($areas)),
        ]);
    }

    public function index(Request $request, TenantContext $context, CommunityAdministrationService $communities): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(CommunityAdministrationService::VIEW, $school);
        $groupType = $this->type($request);

        return $this->directory($groupType, $communities->list($school, $groupType, $scope), $scope,
            Gate::forUser($context->actor())->allows('capability', [CommunityAdministrationService::MANAGE, $school]));
    }

    public function store(Request $request, TenantContext $context, CommunityAdministrationService $communities): RedirectResponse
    {
        $groupType = $this->type($request);
        $communities->create($context->requireSchool(), $context->actor(), $groupType, $request->only(['name', 'code', 'description', 'display_order', 'activity_category']));

        return redirect($this->base($groupType))->with('flash', "{$groupType->label()} created.");
    }

    public function update(Request $request, TenantContext $context, CommunityAdministrationService $communities, string $group): RedirectResponse
    {
        $groupType = $this->type($request);
        $communities->update($context->requireSchool(), $context->actor(), $groupType, $group, $request->only(['name', 'code', 'description', 'display_order', 'activity_category', 'version']));

        return redirect($this->base($groupType))->with('flash', "{$groupType->label()} saved.");
    }

    public function status(Request $request, TenantContext $context, CommunityAdministrationService $communities, string $group): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $groupType = $this->type($request);
        $communities->setStatus($context->requireSchool(), $context->actor(), $groupType, $group, $validated['status'], (int) $validated['version']);

        return redirect($this->base($groupType))->with('flash', "{$groupType->label()} ".($validated['status'] === 'inactive' ? 'deactivated.' : 'reactivated.'));
    }

    /**
     * The closed type of this route (`clubs` -> club, `activities` ->
     * activity): the route's own default, never request input. Read here, not
     * as an action argument, because Laravel passes route values by position.
     */
    private function type(Request $request): StudentGroupType
    {
        return StudentGroupType::from((string) $request->route()?->defaults['type']);
    }

    private function base(StudentGroupType $type): string
    {
        return '/app/students/'.strtolower($type->plural());
    }
}
