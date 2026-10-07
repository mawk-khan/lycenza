<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\Examinations\Application\Marks\StudentMarkEntry;
use App\Domain\Examinations\Application\Marks\StudentMarkService;
use App\Domain\Examinations\Application\Marks\TeacherExaminationPaperDiscoveryService;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkAccess;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkReadService;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * RES.4 (ADR 0068 §25.5-§25.6): "My examination paper marks" -- the owned
 * teacher surface. Session-authenticated JSON only, each route composing
 * `capability:examinations.marks.teacher`, `mfa` and the development-only
 * block (routes/web.php); the Application layer decides everything again
 * (TeacherStudentMarkReadService; StudentMarkService with the teacher guard).
 * Exactly two marks actions, the owned read and the owned batch write, plus
 * (RES.4A) the owned paper discovery list -- never the administrative grid, a
 * Student or mark list, search, export or Student-centric endpoint.
 *
 * The paper id is NOT route-model-bound: an unknown id, another School's
 * paper and a paper the teacher does not own all answer the identical 404
 * body. Validation never echoes a value or flashes input into the session;
 * an ineligible identity is one fixed 403.
 */
class TeacherStudentMarkController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** RES.4A (ADR 0068 §26): the papers the teacher can open here -- discovery only, no Student or mark. */
    public function papers(Request $request, TeacherExaminationPaperDiscoveryService $papers): JsonResponse
    {
        try {
            return response()->json(['data' => $papers->papers($this->context->requireSchool(), $request->user())]);
        } catch (ActingEmployeeUnavailableException $e) {
            return $this->identityUnavailable($e);
        } catch (ExaminationException $e) {
            return $this->error($e);
        }
    }

    public function index(Request $request, string $examinationPaper, TeacherStudentMarkReadService $marks): JsonResponse
    {
        if (! Str::isUuid($examinationPaper)) {
            return $this->error(new TeacherStudentMarkPaperNotFoundException);
        }

        try {
            return response()->json(['data' => $marks->paper($this->context->requireSchool(), $examinationPaper, $request->user())]);
        } catch (ActingEmployeeUnavailableException $e) {
            return $this->identityUnavailable($e);
        } catch (ExaminationException $e) {
            return $this->error($e);
        }
    }

    public function update(Request $request, string $examinationPaper, StudentMarkService $marks, TeacherStudentMarkAccess $access): JsonResponse
    {
        if (! Str::isUuid($examinationPaper)) {
            return $this->error(new TeacherStudentMarkPaperNotFoundException);
        }

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

        $user = $request->user();
        try {
            $written = $marks->record($this->context->requireSchool(), $examinationPaper, $entries, $user, $access->guard($user));
        } catch (ActingEmployeeUnavailableException $e) {
            return $this->identityUnavailable($e);
        } catch (ExaminationException $e) {
            return $this->error($e);
        }

        return response()->json(['data' => ['marks' => $written]]);
    }

    private function error(ExaminationException $e): JsonResponse
    {
        // Code, fixed message and the caller's own Student id -- never a value, a name or an eligibility reason.
        return response()->json(['error' => array_filter([
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'status' => $e->getStatusCode(),
            'studentId' => property_exists($e, 'studentId') ? $e->studentId : null,
        ], fn ($v) => $v !== null)], $e->getStatusCode());
    }

    private function identityUnavailable(ActingEmployeeUnavailableException $e): JsonResponse
    {
        return response()->json(['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage(), 'status' => 403]], 403);
    }
}
