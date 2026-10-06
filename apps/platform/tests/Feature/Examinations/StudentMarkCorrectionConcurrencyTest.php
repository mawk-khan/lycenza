<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Domain\Examinations\Infrastructure\StudentMarkRevision;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.3 (ADR 0068 §21.5): the marks lock and the correction workflow under
 * real concurrency -- two genuinely separate OS processes against real
 * PostgreSQL, the holder's work held uncommitted until the contender is
 * observed blocked on a lock (ForcesConcurrentOverlap). Committed synthetic
 * fixtures, purged afterwards; every commit here also runs the deferred
 * "approved in the same transaction" check for real.
 *
 * - T1: lock vs entry, both ways -- the paper row (entry FOR SHARE, lock FOR
 *   UPDATE): nothing is written across the lock boundary.
 * - T2: two approvals of one correction -- the request row FOR UPDATE: the
 *   mark advances exactly once.
 * - T3: approval vs processing-authorization withdrawal -- ADR 0038's Student
 *   and grant locks: an approval behind a withdrawal is refused.
 * - T4: correction request vs lock -- a request that waited for the lock reads
 *   the committed `locked` state.
 * - T5: stale version -- a request naming the version an in-flight approval
 *   replaces waits on it, then is refused.
 */
class StudentMarkCorrectionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function lockOp(array $w, string $actorId): array
    {
        return $this->op('lock', $w['school']->id, $w['paper']->id, $actorId);
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function requestOp(array $w, StudentMark $mark, string $actorId, int $expected, string $value): array
    {
        return $this->op('request-correction', $w['school']->id, $w['paper']->id, $mark->id, $actorId, (string) $expected, 'present', $value, 'entry_error');
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function decideOp(array $w, string $decision, StudentMarkCorrection $correction, string $actorId): array
    {
        return $this->op($decision.'-correction', $w['school']->id, $correction->id, $actorId);
    }

    /** @param  array<string, mixed>  $w */
    private function stateOf(array $w): ?string
    {
        return $this->inMarksSchool($w['school'], fn () => ExaminationPaperMarkState::query()->where('examination_paper_id', $w['paper']->id)->value('state'));
    }

    #[Test]
    public function t1_a_lock_waits_for_in_flight_entry(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('record', $w['school']->id, $w['paper']->id, $w['admin']->id, $student->id, 'present', '20', '-'),
            $this->lockOp($w, $this->checker($w)->id),
        );

        $this->assertSame(['recorded:v1', 'locked:locked'], [$holder, $contender], 'the lock waited on the paper FOR SHARE, then locked the committed mark');
        $this->assertSame('20.00', (string) $this->markOf($w, $student)->value);
    }

    #[Test]
    public function t1_entry_behind_a_lock_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->lockOp($w, $w['admin']->id),
            $this->op('record', $w['school']->id, $w['paper']->id, $w['admin']->id, $student->id, 'present', '25', '1'),
        );

        $this->assertSame(['locked:locked', 'refused:STUDENT_MARK_PAPER_LOCKED'], [$holder, $contender], 'the entry waited on the paper FOR UPDATE, then found it locked');
        $this->assertSame(['20.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function t2_two_approvals_advance_the_mark_exactly_once(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);
        $this->lockMarks($w);
        $correction = $this->requestCorrection($w, $this->markOf($w, $student), 'present', '24');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->decideOp($w, 'approve', $correction, $this->checker($w)->id),
            $this->decideOp($w, 'approve', $correction, $this->checker($w)->id),
        );

        $this->assertSame(['decided:approved', 'refused:STUDENT_MARK_CORRECTION_ALREADY_DECIDED'], [$holder, $contender]);
        $mark = $this->markOf($w, $student);
        $this->assertSame(['24.00', 2], [(string) $mark->value, $mark->version]);
        $this->assertSame(2, $this->inMarksSchool($w['school'], fn () => StudentMarkRevision::query()->where('student_mark_id', $mark->id)->count()));
    }

    #[Test]
    public function t2_an_approval_and_a_rejection_never_both_decide(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);
        $this->lockMarks($w);
        $correction = $this->requestCorrection($w, $this->markOf($w, $student), 'present', '24');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->decideOp($w, 'reject', $correction, $this->checker($w)->id),
            $this->decideOp($w, 'approve', $correction, $this->checker($w)->id),
        );

        $this->assertSame(['decided:rejected', 'refused:STUDENT_MARK_CORRECTION_ALREADY_DECIDED'], [$holder, $contender]);
        $this->assertSame(['20.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function t3_an_approval_behind_a_withdrawal_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w, authorised: false);
        $grant = $this->authorise($w, $student);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);
        $this->lockMarks($w);
        $correction = $this->requestCorrection($w, $this->markOf($w, $student), 'present', '24');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('withdraw-authorization', $w['school']->id, $grant->id, $w['admin']->id),
            $this->decideOp($w, 'approve', $correction, $this->checker($w)->id),
        );

        $this->assertSame(['withdrawn', 'refused:STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE'], [$holder, $contender], 'the approval waited on the Student/grant, re-evaluated under lock, found no basis');
        $this->assertSame(['20.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->assertSame(StudentMarkCorrection::STATUS_PENDING, $this->freshCorrection($w, $correction)->status);
    }

    #[Test]
    public function t4_a_request_that_waited_for_the_lock_reads_it(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);
        $mark = $this->markOf($w, $student);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->lockOp($w, $w['admin']->id),
            $this->requestOp($w, $mark, $this->checker($w)->id, 1, '22'),
        );

        $this->assertSame(['locked:locked', 'requested:pending'], [$holder, $contender], 'the request waited on the paper FOR UPDATE, then read the committed lock');
        $this->assertSame('locked', $this->stateOf($w));
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }

    #[Test]
    public function t5_a_request_on_the_version_an_approval_replaces_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '20')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $correction = $this->requestCorrection($w, $mark, 'present', '24');
        $checker = $this->checker($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->decideOp($w, 'approve', $correction, $checker->id),
            $this->requestOp($w, $mark, $this->checker($w)->id, 1, '26'),
        );

        $this->assertSame(['decided:approved', 'refused:STUDENT_MARK_VERSION_CONFLICT'], [$holder, $contender], 'the stale request waited on the approval, then found version 2');
        $this->assertSame(['24.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->count()));
    }
}
