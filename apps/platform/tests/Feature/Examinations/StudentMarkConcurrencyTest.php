<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkRevision;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.2 (ADR 0068 §6.2, §6.3, §19.3 b): StudentMark under real concurrency --
 * two genuinely separate OS processes against real PostgreSQL, the holder's
 * work held uncommitted until the contender is observed blocked on a lock
 * (ForcesConcurrentOverlap). Committed synthetic fixtures, purged afterwards.
 *
 * - T1: two editors on one mark -- the row lock plus the version guard: the
 *   loser waits, then is refused (no lost update).
 * - T2: entry vs processing-authorization withdrawal -- ADR 0038's Student and
 *   grant locks: an entry that waited behind a withdrawal is refused.
 * - T3: entry vs placement transfer -- P3's FOR SHARE on the placement: the
 *   transfer waits for the mark, which keeps the placement it was decided on.
 */
class StudentMarkConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function record(array $w, string $studentId, string $status, string $value, string $expected = '-'): array
    {
        return $this->op('record', $w['school']->id, $w['paper']->id, $w['admin']->id, $studentId, $status, $value, $expected);
    }

    #[Test]
    public function t1_two_editors_on_one_mark_never_lose_an_update(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '10')]);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($w, $student->id, 'present', '20', '1'),
            $this->record($w, $student->id, 'present', '30', '1'),
        );

        $this->assertSame('recorded:v2', $holder);
        $this->assertSame('refused:STUDENT_MARK_VERSION_CONFLICT', $contender, 'the loser waited on the mark row, then found version 2: no silent overwrite');
        $mark = $this->markOf($w, $student);
        $this->assertSame(['20.00', 2], [(string) $mark->value, $mark->version]);
        $this->assertSame(2, $this->inMarksSchool($w['school'], fn () => StudentMarkRevision::query()->where('student_mark_id', $mark->id)->count()));
    }

    #[Test]
    public function t1_two_editors_creating_one_mark_produce_one_row(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($w, $student->id, 'present', '20'),
            $this->record($w, $student->id, 'absent', '-'),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('refused:STUDENT_MARK_VERSION_CONFLICT', $contender);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMark::query()->count()));
    }

    #[Test]
    public function t2_an_entry_behind_a_withdrawal_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w, authorised: false);
        $grant = $this->authorise($w, $student);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('withdraw-authorization', $w['school']->id, $grant->id, $w['admin']->id),
            $this->record($w, $student->id, 'present', '45'),
        );

        $this->assertSame('withdrawn', $holder);
        $this->assertSame('refused:STUDENT_MARK_PROCESSING_BASIS_UNAVAILABLE', $contender, 'the entry waited on the grant, re-evaluated it under lock, and found no basis');
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function t3_a_placement_transfer_waits_for_the_mark_that_relied_on_it(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $source = $this->placementOf($w, $student);

        // A transfer effective BEFORE the paper's date would move the date's placement: it must wait.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->record($w, $student->id, 'present', '33'),
            $this->op('transfer-placement', $w['school']->id, $source->id, $w['a2']->id, '77', '2026-09-01'),
        );

        $this->assertSame('recorded:v1', $holder);
        $this->assertSame('transferred', $contender, 'the transfer waited on the FOR SHARE the mark took, then committed');
        $this->assertSame($source->id, $this->markOf($w, $student)->student_enrollment_id, 'the mark keeps the placement decided under lock');
        $this->assertSame(2, $this->inMarksSchool($w['school'], fn () => StudentEnrollment::query()->where('student_id', $student->id)->count()));
    }
}
