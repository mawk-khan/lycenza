<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\ElectiveGroupService;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Http\Controllers\App\Concerns\MapsAcademicStructureErrors;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SWC.2 (ADR 0073 sections 4.3, 16 X1): the browser configuration of
 * Elective Groups over the existing ElectiveGroupService (Phase 1F.3) --
 * create a group for one (Academic Year, Campus, Grade Level), and assign or
 * remove an elective Subject Offering of that same context. The service is
 * authorization-neutral by contract; its prescribed capability,
 * `academics.subjects.manage`, is checked here (viewing:
 * `academics.subjects.view`). There is no rename, delete or deactivate:
 * the contract has none, and SWC adds none.
 *
 * Every lookup runs under SchoolScope, so another School's group or
 * Offering is the same 404 as a missing one. The service refuses a required
 * Offering, a different context and an Offering with participation history;
 * those refusals are form errors here.
 *
 * Duplicate submissions (rule 29): a group's code is unique in its context;
 * assigning an Offering already in the group, or removing one already
 * removed, is a no-op in the service.
 */
class ElectiveGroupController extends Controller
{
    use AuthorizesCapability, MapsAcademicStructureErrors;

    public function index(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $requested = $request->query('academic_year_id');
        $selected = $years->firstWhere('id', is_string($requested) ? $requested : null)
            ?? $years->firstWhere('status', 'active') ?? $years->first();

        return Inertia::render('App/ElectiveGroups/Index', [
            'academicYears' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])->all(),
            'selectedYearId' => $selected?->id,
            'groups' => $selected === null ? [] : ElectiveGroup::query()
                ->where('academic_year_id', $selected->id)
                ->with(['campus', 'gradeLevel'])
                ->withCount('subjectOfferings')
                ->orderBy('name')
                ->get()
                ->map(fn (ElectiveGroup $g) => [
                    'id' => $g->id, 'name' => $g->name, 'code' => $g->code,
                    'campus' => $g->campus?->name, 'gradeLevel' => $g->gradeLevel?->name,
                    'offeringCount' => $g->subject_offerings_count,
                ])->all(),
            'campuses' => Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->all(),
            'gradeLevels' => GradeLevel::query()->where('status', 'active')->orderBy('sequence')->get(['id', 'name'])->all(),
            'canManage' => $this->can($context, 'academics.subjects.manage'),
        ]);
    }

    public function store(Request $request, TenantContext $context, ElectiveGroupService $groups): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'string'],
            'campus_id' => ['required', 'string'],
            'grade_level_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        // Another School's Year/Campus/Grade is invisible under SchoolScope: the same 404 as a missing one.
        $year = AcademicYear::query()->findOrFail($validated['academic_year_id']);
        $campus = Campus::query()->findOrFail($validated['campus_id']);
        $gradeLevel = GradeLevel::query()->findOrFail($validated['grade_level_id']);

        $group = $this->withFormErrors('code', fn () => $groups->create($school, $year, $campus, $gradeLevel, $validated['name'], $validated['code'], $context->actor()));

        return redirect()->route('app.elective-groups.show', $group->id);
    }

    public function show(TenantContext $context, string $electiveGroup): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        $group = ElectiveGroup::query()->with(['academicYear', 'campus', 'gradeLevel'])->findOrFail($electiveGroup);

        // The elective Offerings of the group's exact context: the ones in
        // it, and the ones that could be assigned (not required, not
        // already in it). The service still decides every assignment.
        $offerings = SubjectOffering::query()
            ->where('academic_year_id', $group->academic_year_id)
            ->where('campus_id', $group->campus_id)
            ->where('grade_level_id', $group->grade_level_id)
            ->where('is_required', false)
            ->with(['subject', 'electiveGroup'])
            ->orderBy('sequence')
            ->get();

        $present = fn (SubjectOffering $o) => [
            'id' => $o->id,
            'subject' => $o->subject === null ? null : ['name' => $o->subject->name, 'code' => $o->subject->code],
            'status' => $o->status,
            'otherGroup' => $o->elective_group_id !== null && $o->elective_group_id !== $group->id ? $o->electiveGroup?->name : null,
        ];

        return Inertia::render('App/ElectiveGroups/Show', [
            'group' => [
                'id' => $group->id, 'name' => $group->name, 'code' => $group->code,
                'academicYear' => $group->academicYear?->name, 'campus' => $group->campus?->name, 'gradeLevel' => $group->gradeLevel?->name,
            ],
            'assigned' => $offerings->where('elective_group_id', $group->id)->map($present)->values()->all(),
            'candidates' => $offerings->where('elective_group_id', '!==', $group->id)->where('status', 'active')->map($present)->values()->all(),
            'canManage' => $this->can($context, 'academics.subjects.manage'),
        ]);
    }

    public function assign(Request $request, TenantContext $context, ElectiveGroupService $groups, string $electiveGroup): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $validated = $request->validate(['subject_offering_id' => ['required', 'string']]);
        $group = ElectiveGroup::query()->findOrFail($electiveGroup);
        $offering = SubjectOffering::query()->findOrFail($validated['subject_offering_id']);

        $this->withFormErrors('subject_offering_id', fn () => $groups->assignOffering($group, $offering, $context->actor()));

        return redirect()->route('app.elective-groups.show', $group->id);
    }

    public function remove(TenantContext $context, ElectiveGroupService $groups, string $electiveGroup, string $subjectOffering): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $group = ElectiveGroup::query()->findOrFail($electiveGroup);
        // Only an Offering that is in THIS group can be removed through it.
        $offering = SubjectOffering::query()->where('elective_group_id', $group->id)->findOrFail($subjectOffering);

        $this->withFormErrors('subject_offering_id', fn () => $groups->removeOffering($offering, $context->actor()));

        return redirect()->route('app.elective-groups.show', $group->id);
    }

    private function can(TenantContext $context, string $capability): bool
    {
        return app(CapabilityResolver::class)->canInSchool($context->actor(), $capability, $context->requireSchool());
    }
}
