<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\Movements\StudentMovementType;
use App\Domain\Students\Application\StudentAccess;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS.EXP-2: the School-wide Student movement and exit register
 * (StudentMovementRegister) -- screen only, read only: no mutation, no
 * export, no print, no approval, no audit of the read.
 *
 * - A declared whole-School-only operation of `enrollments.view`
 *   (CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS): Student history is
 *   whole-School (rule 101: a Campus reader sees current placements only,
 *   never history), so a Campus-only grant is refused by the route floor.
 * - Student lifecycle rows (marked inactive / active again) and the link to
 *   the Student profile also need whole-School `students.view`; the
 *   profile re-checks its own authority.
 * - Filters only narrow, in SQL; ids are validated as UUIDs and a foreign
 *   or unknown one matches nothing. Sorts come from a closed map.
 */
class StudentMovementController extends Controller
{
    public function index(Request $request, TenantContext $context, StudentAccess $access, StudentMovementRegister $register): Response
    {
        $school = $context->requireSchool();
        // The route floor is `capability:enrollments.view` (whole-School, declared).
        $canViewStudents = $access->holdsWholeSchool($context->actor(), 'students.view', $school);

        $types = $canViewStudents
            ? StudentMovementType::cases()
            : array_values(array_filter(StudentMovementType::cases(), fn (StudentMovementType $t) => ! $t->isStudentLifecycle()));

        $validated = $request->validate([
            // A year id, or (R-4) the events dated in no academic year.
            'academic_year_id' => ['sometimes', 'nullable', 'bail', 'string', function (string $attribute, mixed $value, Closure $fail) use ($canViewStudents): void {
                if (! Str::isUuid($value) && ! ($canViewStudents && $value === StudentMovementRegister::OUTSIDE_ACADEMIC_YEAR)) {
                    $fail('Choose an academic year from the list.');
                }
            }],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['sometimes', 'nullable', Rule::in(StudentMovementType::CATEGORIES)],
            'type' => ['sometimes', 'nullable', Rule::in(array_map(fn (StudentMovementType $t) => $t->value, $types))],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'grade_level_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(StudentMovementRegister::SORTS))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $rows = $register->page($school, $validated, $canViewStudents, (int) ($validated['page'] ?? 1))->withQueryString();

        return Inertia::render('App/Students/Movements', [
            'movements' => $rows,
            'filters' => collect(['academic_year_id', 'from', 'to', 'category', 'type', 'campus_id', 'grade_level_id', 'section_id', 'q', 'sort'])
                ->mapWithKeys(fn (string $k) => [$k => (string) ($validated[$k] ?? '')])->all(),
            'options' => [...$this->options($school, $types), 'canFilterOutsideYear' => $canViewStudents],
            'canOpenStudent' => $canViewStudents,
        ]);
    }

    /**
     * Whole-School options (this page is whole-School only): the School's
     * years, Campuses, Grades and Sections, and the movement types the viewer
     * may filter by.
     *
     * @param  list<StudentMovementType>  $types
     * @return array<string, mixed>
     */
    private function options(School $school, array $types): array
    {
        return [
            'academicYears' => AcademicYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->get(['id', 'name'])
                ->map(fn (AcademicYear $y) => ['id' => (string) $y->id, 'name' => $y->name])->all(),
            'campuses' => Campus::query()->where('school_id', $school->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Campus $c) => ['id' => (string) $c->id, 'name' => $c->name])->all(),
            'gradeLevels' => GradeLevel::query()->where('school_id', $school->id)->orderBy('sequence')->get(['id', 'name'])
                ->map(fn (GradeLevel $g) => ['id' => (string) $g->id, 'name' => $g->name])->all(),
            'sections' => Section::query()->where('sections.school_id', $school->id)->with(['academicYear:id,name,starts_on', 'campus:id,name', 'gradeLevel:id,name,sequence'])->get()
                ->sortBy(fn (Section $s) => sprintf('%s|%08d|%s|%s', 9999999999 - (int) $s->academicYear->starts_on->timestamp, $s->gradeLevel->sequence, $s->name, $s->campus->name))
                ->map(fn (Section $s) => [
                    'id' => (string) $s->id,
                    'label' => SectionLabel::of($s)." · {$s->campus->name} · {$s->academicYear->name}",
                    'academicYearId' => (string) $s->academic_year_id,
                    'campusId' => (string) $s->campus_id,
                    'gradeLevelId' => (string) $s->grade_level_id,
                ])->values()->all(),
            'categories' => array_map(fn (string $c) => ['value' => $c, 'label' => ucfirst($c)], StudentMovementType::CATEGORIES),
            'types' => array_map(fn (StudentMovementType $t) => ['value' => $t->value, 'label' => $t->label(), 'category' => $t->category()], $types),
        ];
    }
}
