<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.7 (OD-SIS-4): one Student's House -- current membership, history,
 * responsibilities. Thin: the page is a declared whole-School operation of
 * `students.houses.view`; every write needs whole-School
 * `students.houses.assign` (route floor) and carries the StudentHouseVersion
 * the page showed; StudentHouseService decides everything else.
 */
class StudentHouseController extends Controller
{
    public function show(TenantContext $context, StudentHouseService $service, HouseAdministrationService $houses, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $data = $service->history($school, $actor, $student);
        $model = $data['student'];
        $responsibilities = $data['responsibilities']->groupBy('student_group_membership_id');
        $houseNames = $data['memberships']->mapWithKeys(fn (StudentGroupMembership $m) => [$m->student_group_id => $m->group->name]);

        return Inertia::render('App/Students/House', [
            'student' => [
                'id' => $model->id,
                'name' => trim(implode(' ', array_filter([$model->first_name, $model->middle_name, $model->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'studentNumber' => $model->student_number,
                'status' => $model->status,
            ],
            'current' => $data['current'] === null ? null : [
                'house' => $data['current']->group->name,
                'houseId' => $data['current']->student_group_id,
                'since' => $data['current']->starts_on->toDateString(),
            ],
            'history' => $data['memberships']->map(fn (StudentGroupMembership $m) => [
                'key' => $m->id,
                'house' => $m->group->name.($m->group->status === 'inactive' ? ' (inactive)' : ''),
                'from' => $m->starts_on->toDateString(),
                'to' => $m->ends_on?->toDateString(),
                'sameDay' => $m->ends_on !== null && $m->ends_on->lt($m->starts_on),
                'started' => $m->start_reason->label(),
                'ended' => $m->end_reason?->label(),
            ])->values()->all(),
            'responsibilities' => $data['responsibilities']->map(fn (StudentGroupResponsibility $r) => [
                'id' => $r->id,
                'label' => $r->responsibility->label(),
                'house' => $houseNames[$r->student_group_id] ?? '—',
                'from' => $r->starts_on->toDateString(),
                'to' => $r->ends_on?->toDateString(),
                'sameDay' => $r->ends_on !== null && $r->ends_on->lt($r->starts_on),
                'current' => $r->ends_on === null,
                'version' => $r->version,
            ])->values()->all(),
            'version' => $data['version'],
            'options' => [
                'houses' => $houses->activeHouses($school)->map(fn ($h) => ['value' => $h->id, 'label' => $h->name])->values()->all(),
                'changeReasons' => HouseMembershipReason::options(HouseMembershipReason::changeReasons()),
                'endReasons' => HouseMembershipReason::options(HouseMembershipReason::endReasons()),
                'responsibilities' => HouseResponsibility::options(),
            ],
            'canAssign' => Gate::forUser($actor)->allows('capability', [StudentHouseService::ASSIGN, $school]),
        ]);
    }

    public function assign(Request $request, TenantContext $context, StudentHouseService $service, string $student): RedirectResponse
    {
        $validated = $request->validate([
            'house_id' => ['required', 'uuid'],
            'reason' => ['nullable', Rule::in(array_map(fn (HouseMembershipReason $r) => $r->value, HouseMembershipReason::changeReasons()))],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $membership = $service->assign($context->requireSchool(), $context->actor(), $student, $validated['house_id'],
            isset($validated['reason']) ? HouseMembershipReason::from($validated['reason']) : null, $validated['version']);

        return redirect("/app/students/{$student}/house")->with('flash', $membership->start_reason === HouseMembershipReason::ASSIGNMENT ? 'House assigned.' : 'House changed.');
    }

    public function end(Request $request, TenantContext $context, StudentHouseService $service, string $student): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_map(fn (HouseMembershipReason $r) => $r->value, HouseMembershipReason::endReasons()))],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $service->end($context->requireSchool(), $context->actor(), $student, HouseMembershipReason::from($validated['reason']), $validated['version']);

        return redirect("/app/students/{$student}/house")->with('flash', 'House membership ended.');
    }

    public function assignResponsibility(Request $request, TenantContext $context, StudentHouseService $service, string $student): RedirectResponse
    {
        $validated = $request->validate([
            'responsibility' => ['required', Rule::enum(HouseResponsibility::class)],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $service->assignResponsibility($context->requireSchool(), $context->actor(), $student, HouseResponsibility::from($validated['responsibility']), $validated['version']);

        return redirect("/app/students/{$student}/house")->with('flash', 'Responsibility assigned.');
    }

    public function endResponsibility(Request $request, TenantContext $context, StudentHouseService $service, string $student, string $responsibility): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->endResponsibility($context->requireSchool(), $context->actor(), $student, $responsibility, (int) $validated['version']);

        return redirect("/app/students/{$student}/house")->with('flash', 'Responsibility ended.');
    }
}
