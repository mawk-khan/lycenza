<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Payments\Application\Collections\OutstandingFeeRow;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UAT-C3 (UAT-FIN-08): Finance -> Outstanding fees, the follow-up list of
 * Students who still owe money. Balances come only from Payments'
 * `OutstandingFeesReadService` (which authorizes `finance.charges.view` +
 * `finance.payments.view` itself). This controller only narrows the Student
 * set and names the rows, reading `Student` for display and search (the
 * FeeStatementController precedent) and class placement through Students'
 * read service -- class shown and filterable only with `enrollments.view`.
 * Staff only; no export, no headcount analytics.
 */
class OutstandingFeesController extends Controller
{
    use AuthorizesCapability;

    private const int PER_PAGE = 25;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, OutstandingFeesReadService $outstanding, StudentEnrollmentReadService $placements): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);
        $this->authorizeCapability('finance.payments.view', $school);
        $canSeeClass = $capabilities->canInSchool($context->actor(), 'enrollments.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([OutstandingFeesReadService::OUTSTANDING, OutstandingFeesReadService::OVERDUE])],
            'academic_year_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'grade_level_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $validated['status'] ?? OutstandingFeesReadService::OUTSTANDING;
        $search = trim((string) ($validated['q'] ?? ''));
        $gradeId = $canSeeClass ? ($validated['grade_level_id'] ?? null) : null;
        $sectionId = $canSeeClass ? ($validated['section_id'] ?? null) : null;

        $studentIds = null;
        if ($search !== '') {
            $term = '%'.$search.'%';
            $studentIds = Student::query()
                ->where(fn ($q) => $q->where('student_number', 'ilike', $term)
                    ->orWhereRaw("concat_ws(' ', first_name, middle_name, last_name) ilike ?", [$term])
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$term]))
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
        $activeYear = AcademicYear::query()->where('status', 'active')->first();
        if (($gradeId !== null || $sectionId !== null) && $activeYear !== null) {
            $inClass = StudentEnrollment::query()
                ->where('academic_year_id', $activeYear->id)->where('status', 'active')
                ->when($gradeId !== null, fn ($q) => $q->where('grade_level_id', $gradeId))
                ->when($sectionId !== null, fn ($q) => $q->where('section_id', $sectionId))
                ->pluck('student_id')->map(fn ($id) => (string) $id)->all();
            $studentIds = $studentIds === null ? $inClass : array_values(array_intersect($studentIds, $inClass));
        }

        $list = $outstanding->list($school, $context->actor(), $status, $validated['academic_year_id'] ?? null, $studentIds);

        $page = (int) ($validated['page'] ?? 1);
        $slice = array_slice($list->rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $ids = array_map(fn (OutstandingFeeRow $r) => $r->studentId, $slice);
        $students = Student::query()->whereIn('id', $ids)->get()->keyBy('id');
        $classes = $canSeeClass ? $placements->currentForMany($school, $ids) : collect();

        $paginator = (new LengthAwarePaginator(
            array_map(fn (OutstandingFeeRow $r) => [
                'studentId' => $r->studentId,
                'name' => collect([$students->get($r->studentId)?->first_name, $students->get($r->studentId)?->middle_name, $students->get($r->studentId)?->last_name])->filter()->implode(' '),
                'studentNumber' => $students->get($r->studentId)?->student_number,
                'class' => ($e = $classes->get($r->studentId)) === null ? null : "{$e->gradeLevel?->name} · {$e->section?->name}",
                'outstanding' => $r->outstanding->amount(),
                'overdue' => $r->overdue->amount(),
                'openCharges' => $r->openCharges,
                'earliestOverdueDueDate' => $r->earliestOverdueDueDate,
                'nextDueDate' => $r->nextDueDate,
            ], $slice),
            count($list->rows),
            self::PER_PAGE,
            $page,
            ['path' => '/app/finance/outstanding'],
        ))->withQueryString();

        return Inertia::render('App/Finance/Outstanding/Index', [
            'rows' => $paginator,
            'totals' => ['outstanding' => $list->totalOutstanding->amount(), 'overdue' => $list->totalOverdue->amount(), 'currency' => 'INR'],
            'today' => $list->schoolToday,
            'filters' => [
                'status' => $status,
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'q' => $search,
                'grade_level_id' => $gradeId ?? '',
                'section_id' => $sectionId ?? '',
            ],
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get()->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values()->all(),
            'canSeeClass' => $canSeeClass,
            'gradeLevels' => $canSeeClass ? GradeLevel::query()->orderBy('sequence')->get()->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])->values()->all() : [],
            'sections' => $canSeeClass && $activeYear !== null
                ? Section::query()->where('academic_year_id', $activeYear->id)->with('gradeLevel')->get()
                    ->sortBy(fn (Section $s) => [$s->gradeLevel?->sequence, $s->name])
                    ->map(fn (Section $s) => ['id' => $s->id, 'label' => "{$s->gradeLevel?->name} · {$s->name}", 'gradeLevelId' => $s->grade_level_id])->values()->all()
                : [],
        ]);
    }
}
