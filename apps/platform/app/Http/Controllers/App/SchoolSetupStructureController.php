<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\AcademicDepartmentAdministrationService;
use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Application\RoomAdministrationService;
use App\Domain\AcademicStructure\Application\SectionAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Http\Controllers\App\Concerns\MapsAcademicStructureErrors;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SWC.2 (ADR 0073 section 10): the School Setup screens Phase 0D deferred --
 * Terms, Sections, Academic Departments and Rooms. Thin controllers over the
 * SAME Application services `/api/v1` uses (one implementation each), the
 * active School from TenantContext only (never a client-supplied id, rule
 * 68), and the existing capabilities: Terms under `academics.years.*`
 * (part of the Academic Year calendar), everything else under
 * `academics.structure.*`.
 *
 * Duplicate submissions (rule 29): every create is protected by its
 * School-scoped unique code (a repeat is a field error); every update sets
 * values, so a repeat converges. No idempotency key is needed.
 */
class SchoolSetupStructureController extends Controller
{
    use AuthorizesCapability, MapsAcademicStructureErrors;

    public function terms(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.view', $school);

        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selected = $this->selectedYear($request, $years->all());

        return Inertia::render('App/SchoolSetup/Terms', [
            'academicYears' => $years->map(fn (AcademicYear $y) => $this->presentYear($y))->all(),
            'selectedYearId' => $selected?->id,
            'terms' => $selected === null ? [] : AcademicTerm::query()->where('academic_year_id', $selected->id)->orderBy('sequence')->get()
                ->map(fn (AcademicTerm $t) => [
                    'id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'sequence' => $t->sequence,
                    'startsOn' => $t->starts_on->toDateString(), 'endsOn' => $t->ends_on->toDateString(),
                ])->all(),
            'canManage' => $this->can($context, 'academics.years.manage'),
        ]);
    }

    public function storeTerm(Request $request, TenantContext $context, AcademicTermService $terms, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        // Out-of-range and overlapping dates are the service's refusals -- shown on the dates.
        $this->withFormErrors('starts_on', fn () => $terms->createFromInput($school, $academicYear, $request->only(['name', 'code', 'starts_on', 'ends_on', 'sequence']), $context->actor()));

        return redirect()->route('app.school-setup.terms', ['academic_year_id' => $academicYear]);
    }

    public function renameTerm(Request $request, TenantContext $context, AcademicTermService $terms, string $academicTerm): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $term = $terms->rename($school, $academicTerm, $request->only(['name', 'code']), $context->actor());

        return redirect()->route('app.school-setup.terms', ['academic_year_id' => $term->academic_year_id]);
    }

    public function sections(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.view', $school);

        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selected = $this->selectedYear($request, $years->all());

        return Inertia::render('App/SchoolSetup/Sections', [
            'academicYears' => $years->map(fn (AcademicYear $y) => $this->presentYear($y))->all(),
            'selectedYearId' => $selected?->id,
            'sections' => $selected === null ? null : Section::query()
                ->where('academic_year_id', $selected->id)
                ->with(['campus', 'gradeLevel'])
                ->orderBy('name')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (Section $s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'capacity' => $s->capacity, 'status' => $s->status,
                    'campus' => ['id' => $s->campus_id, 'name' => $s->campus?->name],
                    'gradeLevel' => ['id' => $s->grade_level_id, 'name' => $s->gradeLevel?->name],
                ]),
            // New Sections are placed under ACTIVE Campuses and Grade Levels only.
            'campuses' => $this->activeOptions(Campus::query()),
            'gradeLevels' => GradeLevel::query()->where('status', 'active')->orderBy('sequence')->get(['id', 'name', 'code'])->all(),
            'canManage' => $this->can($context, 'academics.structure.manage'),
        ]);
    }

    public function storeSection(Request $request, TenantContext $context, SectionAdministrationService $sections, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $sections->create($school, $academicYear, $request->only(['campus_id', 'grade_level_id', 'name', 'code', 'capacity']), $context->actor());

        return redirect()->route('app.school-setup.sections', ['academic_year_id' => $academicYear]);
    }

    public function updateSection(Request $request, TenantContext $context, SectionAdministrationService $sections, string $section): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $sections->update($school, $section, $request->only(['name', 'code', 'capacity', 'status']), $context->actor());

        return redirect()->route('app.school-setup.sections', ['academic_year_id' => $model->academic_year_id]);
    }

    public function departments(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.view', $school);

        return Inertia::render('App/SchoolSetup/Departments', [
            'departments' => AcademicDepartment::query()->orderBy('name')->get()
                ->map(fn (AcademicDepartment $d) => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'status' => $d->status])->all(),
            'canManage' => $this->can($context, 'academics.structure.manage'),
        ]);
    }

    public function storeDepartment(Request $request, TenantContext $context, AcademicDepartmentAdministrationService $departments): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $departments->create($school, $request->only(['name', 'code']), $context->actor());

        return redirect()->route('app.school-setup.departments');
    }

    public function updateDepartment(Request $request, TenantContext $context, AcademicDepartmentAdministrationService $departments, string $academicDepartment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $departments->update($school, $academicDepartment, $request->only(['name', 'code', 'status']), $context->actor());

        return redirect()->route('app.school-setup.departments');
    }

    public function rooms(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.view', $school);

        $campuses = Campus::query()->orderBy('name')->get();
        $requested = $request->query('campus_id');
        // Only a Campus of THIS School can be selected; anything else falls back to the first one.
        $selected = $campuses->firstWhere('id', is_string($requested) ? $requested : null) ?? $campuses->first();

        return Inertia::render('App/SchoolSetup/Rooms', [
            'campuses' => $campuses->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'status' => $c->status])->all(),
            'selectedCampusId' => $selected?->id,
            'rooms' => $selected === null ? [] : Room::query()->where('campus_id', $selected->id)->orderBy('name')->get()
                ->map(fn (Room $r) => [
                    'id' => $r->id, 'name' => $r->name, 'code' => $r->code, 'roomType' => $r->room_type,
                    'capacity' => $r->capacity, 'status' => $r->status,
                ])->all(),
            'roomTypes' => RoomAdministrationService::ROOM_TYPES,
            'canManage' => $this->can($context, 'academics.structure.manage'),
        ]);
    }

    public function storeRoom(Request $request, TenantContext $context, RoomAdministrationService $rooms, string $campus): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $rooms->create($school, $campus, $request->only(['name', 'code', 'room_type', 'capacity']), $context->actor());

        return redirect()->route('app.school-setup.rooms', ['campus_id' => $campus]);
    }

    public function updateRoom(Request $request, TenantContext $context, RoomAdministrationService $rooms, string $room): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = $rooms->update($school, $room, $request->only(['name', 'code', 'room_type', 'capacity', 'status']), $context->actor());

        return redirect()->route('app.school-setup.rooms', ['campus_id' => $model->campus_id]);
    }

    /**
     * The requested Academic Year when it is one of THIS School's (the list
     * came through SchoolScope), else the active one, else the newest.
     *
     * @param  list<AcademicYear>  $years
     */
    private function selectedYear(Request $request, array $years): ?AcademicYear
    {
        $requested = $request->query('academic_year_id');
        $collection = collect($years);

        return $collection->firstWhere('id', is_string($requested) ? $requested : null)
            ?? $collection->firstWhere('status', 'active')
            ?? $collection->first();
    }

    /** @return array<string, mixed> */
    private function presentYear(AcademicYear $year): array
    {
        return [
            'id' => $year->id, 'name' => $year->name, 'code' => $year->code, 'status' => $year->status,
            'startsOn' => $year->starts_on->toDateString(), 'endsOn' => $year->ends_on->toDateString(),
        ];
    }

    /**
     * @param  Builder<Campus>  $query
     * @return list<array{id: string, name: string, code: string}>
     */
    private function activeOptions($query): array
    {
        return $query->where('status', 'active')->orderBy('name')->get()
            ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values()->all();
    }

    private function can(TenantContext $context, string $capability): bool
    {
        return app(CapabilityResolver::class)->canInSchool($context->actor(), $capability, $context->requireSchool());
    }
}
