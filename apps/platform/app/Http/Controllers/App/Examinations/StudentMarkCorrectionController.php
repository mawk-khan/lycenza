<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\Marks\StudentMarkCorrectionService;
use App\Domain\Examinations\Application\Marks\StudentMarkLockService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * RES.3 (ADR 0068 §7, §21): locking one paper's marks and the post-lock
 * correction workflow -- session-authenticated JSON only, each route composing
 * its `capability:examinations.marks.*` key with `mfa` (routes/web.php).
 * Locking and deciding a correction additionally re-verify a fresh MFA code
 * here (FreshMfaRequirement), before the service runs. There is no unlock,
 * reopen, bypass, list or search action.
 *
 * Validation answers JSON with field names only; no response echoes a value.
 * Ids are route-bound or UUID-constrained under the School context, so
 * another School's paper, mark or correction is a 404.
 */
class StudentMarkCorrectionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FreshMfaRequirement $mfa,
    ) {}

    public function lock(Request $request, ExaminationPaper $examinationPaper, StudentMarkLockService $locks): JsonResponse
    {
        $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

        return $this->answer(function () use ($examinationPaper, $locks, $request): array {
            $state = $locks->lock($this->context->requireSchool(), $examinationPaper->id, $request->user());

            return ['examinationPaperId' => $examinationPaper->id, 'marksState' => $state->state];
        });
    }

    public function store(Request $request, ExaminationPaper $examinationPaper, string $studentMark, StudentMarkCorrectionService $corrections): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:present,absent,exempt'],
            'value' => ['present', 'nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'reason_code' => ['required', 'string', Rule::in(StudentMarkCorrection::REASONS)],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => [
                'code' => 'STUDENT_MARK_CORRECTION_VALIDATION_FAILED',
                'message' => 'The correction request is invalid; nothing was saved.',
                'status' => 422,
                'fields' => array_keys($validator->errors()->messages()),
            ]], 422);
        }
        $input = $validator->validated();

        return $this->answer(fn (): array => $this->summary($corrections->request(
            $this->context->requireSchool(), $examinationPaper->id, $studentMark, (int) $input['expected_version'],
            (string) $input['status'], $input['value'] === null ? null : (string) $input['value'], (string) $input['reason_code'], $request->user(),
        )), 201);
    }

    public function approve(Request $request, string $studentMarkCorrection, StudentMarkCorrectionService $corrections): JsonResponse
    {
        $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

        return $this->answer(fn (): array => $this->summary($corrections->approve($this->context->requireSchool(), $studentMarkCorrection, $request->user())));
    }

    public function reject(Request $request, string $studentMarkCorrection, StudentMarkCorrectionService $corrections): JsonResponse
    {
        $this->mfa->require($request, $request->user(), $request->input('mfa_code'));

        return $this->answer(fn (): array => $this->summary($corrections->reject($this->context->requireSchool(), $studentMarkCorrection, $request->user())));
    }

    /** Ids and state only -- never a previous or proposed value. */
    private function summary(StudentMarkCorrection $correction): array
    {
        return [
            'studentMarkCorrectionId' => $correction->id,
            'studentMarkId' => $correction->student_mark_id,
            'status' => $correction->status,
            'baseVersion' => (int) $correction->base_version,
        ];
    }

    /** @param Closure(): array<string, mixed> $action */
    private function answer(Closure $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], $status);
        } catch (ExaminationException $e) {
            return response()->json(['error' => array_filter([
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'status' => $e->getStatusCode(),
                'studentId' => property_exists($e, 'studentId') ? $e->studentId : null,
            ], fn ($v) => $v !== null)], $e->getStatusCode());
        }
    }
}
