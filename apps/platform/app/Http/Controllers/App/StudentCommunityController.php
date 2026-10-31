<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\CommunityMembershipReason;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.8 (OD-SIS-5A, OD-SIS-5B): one Student's Clubs and Activities --
 * current memberships, Club responsibilities and full history, plus the
 * House (read-only, linked) for a whole-School House viewer. Thin: the page
 * is a declared whole-School operation of `students.communities.view`; every
 * write needs whole-School `students.communities.assign` (route floor);
 * StudentCommunityService decides everything else.
 */
class StudentCommunityController extends Controller
{
    public function show(TenantContext $context, StudentCommunityService $service, CommunityAdministrationService $communities, StudentHouseService $houses, StudentAccess $access, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $data = $service->history($school, $actor, $student);
        $model = $data['student'];
        $roles = $data['responsibilities']->groupBy('student_group_membership_id');
        $byType = fn (StudentGroupType $type) => $data['memberships']->filter(fn (StudentGroupMembership $m) => $m->group_type === $type)->values();
        $currentIds = $data['memberships']->whereNull('ends_on')->pluck('student_group_id')->all();
        $house = null;
        if ($access->scopeOf($actor, HouseAdministrationService::VIEW, $school)->isWholeSchool()) {
            $current = $houses->history($school, $actor, $student)['current'];
            $house = ['current' => $current?->group->name, 'since' => $current?->starts_on->toDateString(), 'url' => "/app/students/{$model->id}/house"];
        }

        return Inertia::render('App/Students/StudentCommunities', [
            'student' => [
                'id' => $model->id,
                'name' => trim(implode(' ', array_filter([$model->first_name, $model->middle_name, $model->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'studentNumber' => $model->student_number,
                'status' => $model->status,
            ],
            'house' => $house,
            'clubs' => $this->section($byType(StudentGroupType::CLUB), $roles),
            'activities' => $this->section($byType(StudentGroupType::ACTIVITY), $roles),
            'options' => [
                'clubs' => $this->choices($communities->active($school, StudentGroupType::CLUB), $currentIds),
                'activities' => $this->choices($communities->active($school, StudentGroupType::ACTIVITY), $currentIds),
                'clubEndReasons' => CommunityMembershipReason::options(CommunityMembershipReason::endReasons(StudentGroupType::CLUB)),
                'activityEndReasons' => CommunityMembershipReason::options(CommunityMembershipReason::endReasons(StudentGroupType::ACTIVITY)),
                'clubResponsibilities' => ClubResponsibility::options(),
            ],
            'canAssign' => Gate::forUser($actor)->allows('capability', [StudentCommunityService::ASSIGN, $school]),
        ]);
    }

    public function join(Request $request, TenantContext $context, StudentCommunityService $service, string $student): RedirectResponse
    {
        $validated = $request->validate(['group_id' => ['required', 'uuid']]);
        $membership = $service->join($context->requireSchool(), $context->actor(), $student, $validated['group_id']);

        return redirect("/app/students/{$student}/communities")
            ->with('flash', $membership->group_type === StudentGroupType::ACTIVITY ? 'Participation added.' : 'Joined the Club.');
    }

    public function leave(Request $request, TenantContext $context, StudentCommunityService $service, string $student, string $membership): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(CommunityMembershipReason::class)->except([CommunityMembershipReason::JOINED])],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $ended = $service->leave($context->requireSchool(), $context->actor(), $student, $membership, CommunityMembershipReason::from($validated['reason']), (int) $validated['version']);

        return redirect("/app/students/{$student}/communities")
            ->with('flash', $ended->group_type === StudentGroupType::ACTIVITY ? 'Participation ended.' : 'Left the Club.');
    }

    public function assignResponsibility(Request $request, TenantContext $context, StudentCommunityService $service, string $student, string $membership): RedirectResponse
    {
        $validated = $request->validate(['responsibility' => ['required', Rule::enum(ClubResponsibility::class)]]);
        $service->assignResponsibility($context->requireSchool(), $context->actor(), $student, $membership, ClubResponsibility::from($validated['responsibility']));

        return redirect("/app/students/{$student}/communities")->with('flash', 'Responsibility given.');
    }

    public function endResponsibility(Request $request, TenantContext $context, StudentCommunityService $service, string $student, string $responsibility): RedirectResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->endResponsibility($context->requireSchool(), $context->actor(), $student, $responsibility, (int) $validated['version']);

        return redirect("/app/students/{$student}/communities")->with('flash', 'Responsibility ended.');
    }

    /**
     * @param  Collection<int, StudentGroupMembership>  $memberships
     * @param  Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, StudentGroupResponsibility>>  $roles
     * @return array{current: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    private function section(Collection $memberships, Collection $roles): array
    {
        $name = fn (StudentGroupMembership $m) => $m->group->name.($m->group->status === 'inactive' ? ' (inactive)' : '');
        $role = fn (StudentGroupResponsibility $r) => [
            'id' => $r->id,
            'label' => $r->responsibility->label(),
            'from' => $r->starts_on->toDateString(),
            'to' => $r->ends_on?->toDateString(),
            'sameDay' => $r->ends_on !== null && $r->ends_on->lt($r->starts_on),
            'current' => $r->ends_on === null,
            'version' => $r->version,
        ];

        return [
            'current' => $memberships->whereNull('ends_on')->map(fn (StudentGroupMembership $m) => [
                'membershipId' => $m->id,
                'name' => $name($m),
                'category' => $m->group->activity_category?->label(),
                'since' => $m->starts_on->toDateString(),
                'version' => $m->version,
                'responsibilities' => collect($roles->get($m->id, []))->filter(fn (StudentGroupResponsibility $r) => $r->ends_on === null)->map($role)->values()->all(),
            ])->values()->all(),
            'history' => $memberships->map(fn (StudentGroupMembership $m) => [
                'key' => $m->id,
                'name' => $name($m),
                'from' => $m->starts_on->toDateString(),
                'to' => $m->ends_on?->toDateString(),
                'sameDay' => $m->ends_on !== null && $m->ends_on->lt($m->starts_on),
                'started' => $m->start_reason->label(),
                'ended' => $m->end_reason?->label(),
                'responsibilities' => collect($roles->get($m->id, []))->map($role)->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Active groups the Student is not currently in.
     *
     * @param  iterable<int, StudentGroup>  $groups
     * @param  list<string>  $currentIds
     * @return list<array{value: string, label: string}>
     */
    private function choices(iterable $groups, array $currentIds): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (! in_array($group->id, $currentIds, true)) {
                $out[] = ['value' => $group->id, 'label' => $group->activity_category === null ? $group->name : "{$group->name} ({$group->activity_category->label()})"];
            }
        }

        return $out;
    }
}
