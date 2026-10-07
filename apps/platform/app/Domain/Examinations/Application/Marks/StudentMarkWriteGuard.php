<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Students\Application\SubjectOfferingEligibility;
use App\Models\School;

/**
 * RES.4 (ADR 0068 §25.6): the actor-scope hook StudentMarkService::record()
 * runs INSIDE its transaction, so a narrower actor (a teacher) is decided
 * under the same locks as the write -- never a second writer, never a check
 * made before the transaction and trusted afterwards. The administrative
 * path passes none (its authority is the route capability, unchanged).
 *
 * Called in the canonical lock order (§25.7):
 *   holdActor()    -- first, before any marks lock;
 *   admitPaper()   -- after the paper FOR SHARE, before its state is disclosed;
 *   admitStudent() -- per Student, after P3 under lock and before ADR 0038.
 */
interface StudentMarkWriteGuard
{
    /** The actor's own authority, held until commit (capability, identity chain FOR SHARE). */
    public function holdActor(School $school): void;

    /** Refuses (non-disclosing) a paper the actor may not see; $paper is null when none exists in the School. */
    public function admitPaper(School $school, ?ExaminationPaper $paper): void;

    /**
     * Refuses (non-disclosing) a Student outside the actor's scope -- an ineligible one included -- holding
     * whatever the decision relied on until commit.
     *
     * @return array<string, string> extra ids-only audit metadata for this Student's write
     */
    public function admitStudent(School $school, ExaminationPaper $paper, string $studentId, SubjectOfferingEligibility $eligibility): array;

    /** The audit event for a written mark. */
    public function auditEvent(bool $created): string;
}
