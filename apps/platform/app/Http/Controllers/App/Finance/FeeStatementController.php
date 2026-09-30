<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.4 (ADR 0062 §18): Finance -> Student fee statements, staff only.
 * Every page and the Student search need `finance.charges.view` AND
 * `finance.payments.view` (checked here and again in
 * `StudentFeeStatementReadService`). Computed on read; no export, PDF,
 * email or Guardian/Student view. Reads `Student`/`AcademicYear` for
 * display only (the ChargeController precedent).
 */
class FeeStatementController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context): Response
    {
        $this->authorizeBoth($context);

        return Inertia::render('App/Finance/Statements/Index');
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeBoth($context);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);
        $term = '%'.$validated['q'].'%';

        return response()->json(['data' => Student::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term)->orWhere('student_number', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get()
            ->map(fn (Student $s) => ['id' => $s->id, 'name' => $this->fullName($s), 'studentNumber' => $s->student_number])
            ->values()->all()]);
    }

    public function show(Request $request, TenantContext $context, StudentFeeStatementReadService $statements, string $student): Response
    {
        $school = $this->authorizeBoth($context);
        $validated = $request->validate(['academic_year_id' => ['sometimes', 'nullable', 'uuid']]);

        $model = Str::isUuid($student) ? Student::query()->find($student) : null;
        if ($model === null) {
            throw new NotFoundHttpException;
        }

        $yearId = $validated['academic_year_id'] ?? null;
        $statement = $statements->statementFor($school, $model->id, $yearId, $context->actor());
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();

        return Inertia::render('App/Finance/Statements/Show', [
            'student' => ['id' => $model->id, 'name' => $this->fullName($model), 'studentNumber' => $model->student_number],
            'statement' => [
                'currency' => $statement->currency,
                'lines' => collect($statement->lines)->map(fn (array $l) => [
                    ...$l,
                    'academicYearName' => $years->firstWhere('id', $l['academicYearId'])?->name,
                    'payments' => collect($l['payments'])->map(fn (array $p) => [
                        ...$p,
                        'receivedOn' => $p['settledAt'] ? Carbon::parse($p['settledAt'])->setTimezone($school->timezone)->toDateString() : null,
                    ])->all(),
                ])->all(),
                'totals' => $statement->totals,
            ],
            'academicYears' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values()->all(),
            'filters' => ['academic_year_id' => $yearId ?? ''],
        ]);
    }

    private function authorizeBoth(TenantContext $context): School
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);
        $this->authorizeCapability('finance.payments.view', $school);

        return $school;
    }

    private function fullName(Student $student): string
    {
        return collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    }
}
