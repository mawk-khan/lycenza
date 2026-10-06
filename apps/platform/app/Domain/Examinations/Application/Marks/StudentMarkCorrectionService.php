<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionAlreadyDecidedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionContextChangedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionInvalidException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionPaperNotLockedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionPendingExistsException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkCorrectionSelfDecisionException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkProcessingBasisUnavailableException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkRejectedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkVersionConflictException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Domain\Students\Application\Exceptions\StudentNotAuthorizedForProcessingException;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Application\SubjectOfferingEligibilityReadService;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RES.3 (ADR 0068 §7.2-§7.4, §19, §21): the ONLY writer of
 * `student_mark_corrections` -- post-lock changes to a StudentMark, requested
 * by one person and decided by another, each step re-checking P3 and the ADR
 * 0038 processing basis. A correction never writes the mark itself: approval
 * calls StudentMarkService::applyCorrection(), and the database's history
 * trigger records the old and new value as for any write.
 *
 * Canonical lock order (§21.5, shared with RES.2 entry and the lock):
 * paper (FOR SHARE) -> marks state -> correction request (FOR UPDATE) -> P3
 * (Offering, placements, elective rows, FOR SHARE) -> ADR 0038 (Student,
 * grant, relationship) -> mark (FOR SHARE to request, FOR UPDATE to approve).
 *
 * - A closed AcademicYear does not refuse: this is the ONE controlled
 *   post-close change path (§7.4). An inactive paper does not refuse either.
 * - No current processing basis refuses a request and an approval (deny by
 *   default); a rejection needs none (it processes no mark value).
 * - A stale version refuses; the request stays pending for a reviewer to
 *   reject. One pending request per mark.
 * - Audit: ids, version and the closed reason only -- never a value.
 *
 * Authorization is the caller's: `examinations.marks.correction.request` +
 * `mfa`; `examinations.marks.correction.approve` + `mfa` + fresh MFA to decide.
 */
class StudentMarkCorrectionService
{
    public function __construct(
        private readonly StudentMarkService $marks,
        private readonly SubjectOfferingEligibilityReadService $eligibility,
        private readonly StudentProcessingAuthorizationReadService $authorizations,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function request(School $school, string $examinationPaperId, string $studentMarkId, int $expectedVersion, string $status, ?string $value, string $reasonCode, User $actor): StudentMarkCorrection
    {
        if (! in_array($reasonCode, StudentMarkCorrection::REASONS, true)) {
            throw new StudentMarkCorrectionInvalidException;
        }

        return $this->context->withSchool($school, fn (): StudentMarkCorrection => DB::transaction(function () use ($school, $examinationPaperId, $studentMarkId, $expectedVersion, $status, $value, $reasonCode, $actor): StudentMarkCorrection {
            $paper = $this->lockedPaper($school, $examinationPaperId);
            $mark = StudentMark::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)->whereKey($studentMarkId)->firstOrFail();
            $proposed = StudentMarkService::normalizedValue($mark->student_id, $status, $value, (string) $paper->max_marks);

            $this->assertContextUnchanged($school, $paper, $mark);
            $authorizationId = $this->basis($school, $mark->student_id);

            $mark = StudentMark::query()->whereKey($mark->id)->sharedLock()->firstOrFail();
            if ($mark->version !== $expectedVersion) {
                throw new StudentMarkVersionConflictException($mark->student_id);
            }
            if ($mark->status === $status && (string) $mark->value === (string) $proposed) {
                throw new StudentMarkCorrectionInvalidException($mark->student_id);
            }

            $correction = new StudentMarkCorrection;
            $correction->forceFill([
                'school_id' => $school->id,
                'student_mark_id' => $mark->id,
                'examination_paper_id' => $paper->id,
                'student_id' => $mark->student_id,
                'base_version' => $mark->version,
                'previous_status' => $mark->status,
                'previous_value' => $mark->value,
                'proposed_status' => $status,
                'proposed_value' => $proposed,
                'reason_code' => $reasonCode,
                'status' => StudentMarkCorrection::STATUS_PENDING,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now(),
                'request_processing_authorization_id' => $authorizationId,
            ]);
            $this->save($correction, $mark->student_id);

            $this->audit->school($school, 'examinations.student_mark_correction.requested', actor: $actor, metadata: [
                'studentMarkCorrectionId' => $correction->id,
                'studentMarkId' => $mark->id,
                'examinationPaperId' => $paper->id,
                'studentId' => $mark->student_id,
                'baseVersion' => $mark->version,
                'reasonCode' => $reasonCode,
            ]);

            return $correction;
        }));
    }

    public function approve(School $school, string $correctionId, User $actor): StudentMarkCorrection
    {
        return $this->context->withSchool($school, fn (): StudentMarkCorrection => DB::transaction(function () use ($school, $correctionId, $actor): StudentMarkCorrection {
            [$paper, $correction] = $this->pendingForDecision($school, $correctionId, $actor);
            $mark = StudentMark::query()->where('school_id', $school->id)->whereKey($correction->student_mark_id)->firstOrFail();

            $this->assertContextUnchanged($school, $paper, $mark);
            $authorizationId = $this->basis($school, $mark->student_id);

            $mark = StudentMark::query()->whereKey($mark->id)->lockForUpdate()->firstOrFail();
            if ($mark->version !== $correction->base_version) {
                throw new StudentMarkVersionConflictException($mark->student_id);
            }
            $proposed = StudentMarkService::normalizedValue($mark->student_id, $correction->proposed_status, $correction->proposed_value === null ? null : (string) $correction->proposed_value, (string) $paper->max_marks);

            $this->marks->applyCorrection($mark, $correction->proposed_status, $proposed, $authorizationId, $actor);

            $correction->forceFill([
                'status' => StudentMarkCorrection::STATUS_APPROVED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_processing_authorization_id' => $authorizationId,
            ]);
            $this->save($correction, $mark->student_id);

            $this->audit->school($school, 'examinations.student_mark_correction.approved', actor: $actor, metadata: [
                'studentMarkCorrectionId' => $correction->id,
                'studentMarkId' => $mark->id,
                'examinationPaperId' => $paper->id,
                'studentId' => $mark->student_id,
                'version' => $mark->version,
            ]);

            return $correction;
        }));
    }

    public function reject(School $school, string $correctionId, User $actor): StudentMarkCorrection
    {
        return $this->context->withSchool($school, fn (): StudentMarkCorrection => DB::transaction(function () use ($school, $correctionId, $actor): StudentMarkCorrection {
            [$paper, $correction] = $this->pendingForDecision($school, $correctionId, $actor);

            $correction->forceFill([
                'status' => StudentMarkCorrection::STATUS_REJECTED,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ]);
            $this->save($correction, $correction->student_id);

            $this->audit->school($school, 'examinations.student_mark_correction.rejected', actor: $actor, metadata: [
                'studentMarkCorrectionId' => $correction->id,
                'studentMarkId' => $correction->student_mark_id,
                'examinationPaperId' => $paper->id,
                'studentId' => $correction->student_id,
            ]);

            return $correction;
        }));
    }

    /** The paper FOR SHARE (it waits for, or blocks, a concurrent lock) and it must be locked. */
    private function lockedPaper(School $school, string $examinationPaperId): ExaminationPaper
    {
        $paper = ExaminationPaper::query()->where('school_id', $school->id)->whereKey($examinationPaperId)->sharedLock()->firstOrFail();
        if (ExaminationPaperMarkState::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)
            ->value('state') !== ExaminationPaperMarkState::STATE_LOCKED) {
            throw new StudentMarkCorrectionPaperNotLockedException;
        }

        return $paper;
    }

    /** @return array{0: ExaminationPaper, 1: StudentMarkCorrection} the locked paper and the pending request FOR UPDATE, decided by someone else */
    private function pendingForDecision(School $school, string $correctionId, User $actor): array
    {
        // The paper id is immutable on a request, so reading it before the paper lock keeps the canonical order.
        $paperId = StudentMarkCorrection::query()->where('school_id', $school->id)->whereKey($correctionId)->firstOrFail()->examination_paper_id;
        $paper = $this->lockedPaper($school, $paperId);

        $correction = StudentMarkCorrection::query()->where('school_id', $school->id)->whereKey($correctionId)->lockForUpdate()->firstOrFail();
        if ($correction->status !== StudentMarkCorrection::STATUS_PENDING) {
            throw new StudentMarkCorrectionAlreadyDecidedException($correction->student_id);
        }
        if ($correction->requested_by_user_id === $actor->id) {
            throw new StudentMarkCorrectionSelfDecisionException($correction->student_id);
        }

        return [$paper, $correction];
    }

    /**
     * P3, re-checked under lock on the paper's date: the Student must still be eligible on exactly the placement,
     * source and elective row the mark was recorded under. Anything else fails closed (§20.1: is_required is
     * mutable; a correction never re-derives a mark's meaning).
     */
    private function assertContextUnchanged(School $school, ExaminationPaper $paper, StudentMark $mark): void
    {
        $now = $this->eligibility->lockEligibilityAsOf($school, $mark->student_id, $paper->subject_offering_id, $paper->scheduled_on->toDateString());
        if (! $now->eligible || $now->studentEnrollmentId !== $mark->student_enrollment_id || $now->source !== $mark->eligibility_source
            || $now->studentSubjectEnrollmentId !== $mark->student_subject_enrollment_id) {
            throw new StudentMarkCorrectionContextChangedException($mark->student_id);
        }
    }

    /** ADR 0038 under lock: the currently qualifying authorization, or refuse (deny by default). */
    private function basis(School $school, string $studentId): string
    {
        try {
            return $this->authorizations->lockQualifyingAuthorizationIdForStudentId($school, $studentId, ProcessingAuthorizationPurpose::AcademicRecords);
        } catch (StudentNotAuthorizedForProcessingException) {
            throw new StudentMarkProcessingBasisUnavailableException($studentId);
        }
    }

    private function save(StudentMarkCorrection $correction, string $studentId): void
    {
        try {
            $correction->save();
        } catch (QueryException $e) {
            // Never rethrow: the message carries SQL and bindings (mark values).
            if (str_contains($e->getMessage(), 'student_mark_corrections_one_pending_per_mark')) {
                throw new StudentMarkCorrectionPendingExistsException($studentId);
            }
            if (str_contains($e->getMessage(), 'student_mark_corrections_maker_checker_check')) {
                throw new StudentMarkCorrectionSelfDecisionException($studentId);
            }
            throw new StudentMarkRejectedException($studentId);
        }
    }
}
