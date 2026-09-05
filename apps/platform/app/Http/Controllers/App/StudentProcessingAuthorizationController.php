<?php

namespace App\Http\Controllers\App;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\Exceptions\GuardianRelationshipNotEligibleException;
use App\Domain\Students\Application\Exceptions\StudentException;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\StudentProcessingAuthorizationService;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\RendersAuthJsonErrors;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0H.4D-P2 -- staff-only JSON surface for the Student
 * processing-authorization ledger. Every route composes
 * `capability:students.processing_authorizations.{view|manage}` with
 * `mfa` (routes/web.php) -- this is the first genuine production
 * consumer of ADR 0037's capability+MFA seam, not a demonstration
 * route. No PATCH of historical records, no DELETE: only explicit
 * actions (record/withdraw/revoke/supersede), matching
 * AccountSecurityController's "no generic CRUD" precedent. Every
 * action re-derives the active School from TenantContext, never a
 * client-supplied id (CLAUDE.md rule 19/68).
 *
 * These routes live under `/app/...`, not `/api/*`, so bootstrap/
 * app.php's `{"error": {...}}` envelope does not apply automatically
 * (it is deliberately scoped to `/api/*` only) -- exactly the same
 * situation MFA's Account Security surface was in, reusing the same
 * RendersAuthJsonErrors trait rather than inventing a parallel one.
 */
class StudentProcessingAuthorizationController extends Controller
{
    use RendersAuthJsonErrors;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentProcessingAuthorizationService $service,
        private readonly StudentProcessingAuthorizationReadService $readService,
    ) {}

    public function index(Student $student): JsonResponse
    {
        $school = $this->context->requireSchool();
        $purpose = ProcessingAuthorizationPurpose::AcademicRecords;

        $qualifying = $this->readService->qualifyingGrants($school, $student, $purpose);

        $history = $this->context->withSchool($school, fn () => StudentProcessingAuthorization::query()
            ->where('student_id', $student->id)
            ->where('purpose', $purpose->value)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get());

        return response()->json([
            'purpose' => $purpose->value,
            'authorized' => $qualifying->isNotEmpty(),
            'qualifyingAuthorizationId' => $qualifying->first()?->id,
            'qualifyingBases' => $qualifying->pluck('basis_type')->map(fn ($b) => $b->value)->values(),
            'history' => $history->map(fn (StudentProcessingAuthorization $row) => $this->present($row))->values(),
        ]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        return $this->handle(function () use ($request, $student) {
            $school = $this->context->requireSchool();
            $validated = $this->validatedBasisPayload($request);

            $grant = match ($validated['basis_type']) {
                ProcessingAuthorizationBasisType::GuardianConsent->value => $this->service->recordGuardianConsent(
                    $school,
                    $student,
                    ProcessingAuthorizationPurpose::from($validated['purpose']),
                    $this->resolveRelationship($school, $student, $validated['student_guardian_relationship_id']),
                    $request->user(),
                    $validated['note'] ?? null,
                ),
                ProcessingAuthorizationBasisType::AdultStudentConsent->value => $this->service->recordAdultStudentConsent(
                    $school, $student, ProcessingAuthorizationPurpose::from($validated['purpose']), $request->user(), $validated['note'] ?? null,
                ),
                ProcessingAuthorizationBasisType::StatutorySchoolPurpose->value => $this->service->recordStatutorySchoolPurpose(
                    $school, $student, ProcessingAuthorizationPurpose::from($validated['purpose']), $request->user(), $validated['note'] ?? null,
                ),
                // Unreachable: validatedBasisPayload() already validates
                // basis_type against Rule::in(ProcessingAuthorizationBasisType::cases()).
                default => throw ValidationException::withMessages(['basis_type' => 'Invalid basis type.']),
            };

            return response()->json($this->present($grant), 201);
        });
    }

    public function withdraw(Request $request, Student $student, StudentProcessingAuthorization $authorization): JsonResponse
    {
        return $this->handle(function () use ($request, $student, $authorization) {
            $school = $this->context->requireSchool();
            $this->assertBelongsToStudent($student, $authorization);

            $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

            $terminal = $this->service->withdraw($school, $authorization, $request->user(), $validated['note'] ?? null);

            return response()->json($this->present($terminal));
        });
    }

    public function revoke(Request $request, Student $student, StudentProcessingAuthorization $authorization): JsonResponse
    {
        return $this->handle(function () use ($request, $student, $authorization) {
            $school = $this->context->requireSchool();
            $this->assertBelongsToStudent($student, $authorization);

            $validated = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

            $terminal = $this->service->revoke($school, $authorization, $request->user(), $validated['note'] ?? null);

            return response()->json($this->present($terminal));
        });
    }

    public function supersede(Request $request, Student $student, StudentProcessingAuthorization $authorization): JsonResponse
    {
        return $this->handle(function () use ($request, $student, $authorization) {
            $school = $this->context->requireSchool();
            $this->assertBelongsToStudent($student, $authorization);

            $validated = $this->validatedBasisPayload($request);
            $newBasisType = ProcessingAuthorizationBasisType::from($validated['basis_type']);

            $result = $this->service->supersede(
                $school,
                $authorization,
                $newBasisType,
                $request->user(),
                $newBasisType === ProcessingAuthorizationBasisType::GuardianConsent
                    ? $this->resolveRelationship($school, $student, $validated['student_guardian_relationship_id'])
                    : null,
                $validated['note'] ?? null,
            );

            return response()->json([
                'terminal' => $this->present($result['terminal']),
                'new' => $this->present($result['new']),
            ]);
        });
    }

    private function handle(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (StudentException $e) {
            return $this->jsonError($e);
        }
    }

    /** @return array<string, mixed> */
    private function validatedBasisPayload(Request $request): array
    {
        return $request->validate([
            // Purpose defaults to the single currently-closed value
            // when omitted -- there is nothing else a client could
            // meaningfully choose today; validated against the enum
            // when it IS supplied so an invalid value is still rejected.
            'purpose' => ['sometimes', Rule::in(array_column(ProcessingAuthorizationPurpose::cases(), 'value'))],
            'basis_type' => ['required', Rule::in(array_column(ProcessingAuthorizationBasisType::cases(), 'value'))],
            'student_guardian_relationship_id' => [
                Rule::requiredIf(fn () => $request->input('basis_type') === ProcessingAuthorizationBasisType::GuardianConsent->value),
                Rule::prohibitedIf(fn () => $request->input('basis_type') !== ProcessingAuthorizationBasisType::GuardianConsent->value),
                'uuid',
            ],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]) + ['purpose' => $request->input('purpose', ProcessingAuthorizationPurpose::AcademicRecords->value)];
    }

    private function resolveRelationship(School $school, Student $student, string $relationshipId): StudentGuardianRelationship
    {
        $relationship = $this->context->withSchool(
            $school,
            fn () => StudentGuardianRelationship::query()->find($relationshipId),
        );

        if ($relationship === null || $relationship->school_id !== $school->id || $relationship->student_id !== $student->id) {
            throw new GuardianRelationshipNotEligibleException;
        }

        return $relationship;
    }

    private function assertBelongsToStudent(Student $student, StudentProcessingAuthorization $authorization): void
    {
        if ($authorization->student_id !== $student->id) {
            throw ValidationException::withMessages(['authorization' => 'This authorization record does not belong to this Student.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(StudentProcessingAuthorization $authorization): array
    {
        return [
            'id' => $authorization->id,
            'purpose' => $authorization->purpose->value,
            'basisType' => $authorization->basis_type->value,
            'status' => $authorization->status->value,
            'terminatesAuthorizationId' => $authorization->terminates_authorization_id,
            'recordedAt' => $authorization->recorded_at->toIso8601String(),
            'recordedByUserId' => $authorization->recorded_by_user_id,
        ];
    }
}
