<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\StudentMarksAlreadyLockedException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * RES.3 (ADR 0068 §7.1, §21): the ONLY writer of `examination_paper_mark_states`
 * -- locks one paper's marks, `open` -> `locked`, once. There is no unlock
 * (the database refuses any change to a locked state).
 *
 * The paper row is taken FOR UPDATE first (the head of the canonical lock
 * order, §21.5), so the lock waits for every in-flight ordinary entry (which
 * holds the paper FOR SHARE) and every later entry, request or approval waits
 * for the lock; nothing is written across the boundary. A second lock is a
 * 409, never a second transition. Audited with ids and a count only.
 * Authorization is the caller's: `examinations.marks.lock` + `mfa` + a fresh
 * MFA re-verification (controller).
 */
class StudentMarkLockService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function lock(School $school, string $examinationPaperId, User $actor): ExaminationPaperMarkState
    {
        return $this->context->withSchool($school, fn (): ExaminationPaperMarkState => DB::transaction(function () use ($school, $examinationPaperId, $actor): ExaminationPaperMarkState {
            $paper = ExaminationPaper::query()->where('school_id', $school->id)->whereKey($examinationPaperId)->lockForUpdate()->firstOrFail();

            $state = ExaminationPaperMarkState::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)->lockForUpdate()->first();
            if ($state?->state === ExaminationPaperMarkState::STATE_LOCKED) {
                throw new StudentMarksAlreadyLockedException;
            }
            if ($state === null) {
                $state = new ExaminationPaperMarkState;
                $state->forceFill(['school_id' => $school->id, 'examination_paper_id' => $paper->id, 'state' => ExaminationPaperMarkState::STATE_OPEN])->save();
            }
            $state->forceFill(['state' => ExaminationPaperMarkState::STATE_LOCKED, 'locked_by_user_id' => $actor->id, 'locked_at' => now()])->save();

            $this->audit->school($school, 'examinations.student_marks.locked', actor: $actor, metadata: [
                'examinationPaperId' => $paper->id,
                'markCount' => StudentMark::query()->where('school_id', $school->id)->where('examination_paper_id', $paper->id)->count(),
            ]);

            return $state;
        }));
    }
}
