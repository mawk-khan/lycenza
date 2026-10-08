<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkNotEligibleException;
use App\Domain\Examinations\Application\Exceptions\StudentMarksUnavailableException;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkReadService;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * RES.2 (ADR 0068 §4.1, §9, §19): the per-ExaminationPaper marks surface --
 * session-authenticated JSON only (bearer tokens carry no MFA assurance, ADR
 * 0049), each route composing `capability:examinations.marks.*` with `mfa`
 * (routes/web.php). Exactly two actions: the grid read and the atomic batch
 * write. No list, search, export, report or Student-centric endpoint.
 *
 * Validation is manual and always answers JSON: a failed write never flashes
 * its input (mark values) into the session, and no message echoes a value.
 * The paper is route-bound under the School context, so another School's
 * paper is a 404.
 *
 * S8 (ADR 0068 §27.11): `marks-development-only` refuses first outside local /
 * testing; if that route wiring ever regressed, the read service's own block
 * (StudentMarksUnavailableException) answers the same fixed 403 here rather
 * than a 500. The grid translates nothing else -- it has no other domain
 * refusal.
 */
class StudentMarkController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, ExaminationPaper $examinationPaper, StudentMarkReadService $marks): JsonResponse
    {
        try {
            return response()->json(['data' => $marks->grid($this->context->requireSchool(), $examinationPaper->id, $request->user())]);
        } catch (StudentMarksUnavailableException $e) {
            return $this->refusal($e);
        }
    }

    public function update(Request $request, ExaminationPaper $examinationPaper, StudentMarkService $marks): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'marks' => ['required', 'array', 'min:1', 'max:500'],
            'marks.*.student_id' => ['required', 'uuid', 'distinct'],
            'marks.*.status' => ['required', 'string', 'in:present,absent,exempt'],
            'marks.*.value' => ['present', 'nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'marks.*.expected_version' => ['present', 'nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            // Field names and fixed rule messages only -- never the submitted values.
            return response()->json(['error' => [
                'code' => 'STUDENT_MARK_VALIDATION_FAILED',
                'message' => 'The marks request is invalid; nothing was saved.',
                'status' => 422,
                'fields' => array_keys($validator->errors()->messages()),
            ]], 422);
        }

        $entries = array_map(fn (array $row) => new StudentMarkEntry(
            studentId: (string) $row['student_id'],
            status: (string) $row['status'],
            value: $row['value'] === null ? null : (string) $row['value'],
            expectedVersion: $row['expected_version'] === null ? null : (int) $row['expected_version'],
        ), $validator->validated()['marks']);

        try {
            $written = $marks->record($this->context->requireSchool(), $examinationPaper->id, $entries, $request->user());
        } catch (ExaminationException $e) {
            return $this->refusal($e);
        }

        return response()->json(['data' => ['marks' => $written]]);
    }

    /** The fixed code, message and status; a Student id / eligibility reason only where the refusal names one. */
    private function refusal(ExaminationException $e): JsonResponse
    {
        return response()->json(['error' => array_filter([
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'status' => $e->getStatusCode(),
            'studentId' => property_exists($e, 'studentId') ? $e->studentId : null,
            'reason' => $e instanceof StudentMarkNotEligibleException ? $e->reason : null,
        ], fn ($v) => $v !== null)], $e->getStatusCode());
    }
}
