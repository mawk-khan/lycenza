<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Infrastructure\Student;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * S6 (ADR 0068 §27.11): the database's StudentMark rules under real
 * concurrency, against RAW runtime-role writes that take no application lock
 * (separate OS processes; ForcesConcurrentOverlap):
 * - L1 a raw mark insert vs the paper lock -- the lock first refuses it; an
 *   insert first makes the lock wait;
 * - L2 a raw mark insert vs re-pointing the paper's Offering -- the insert
 *   first freezes the paper; a re-point first is seen by the insert's guards
 *   (no mark commits against one Offering while the paper commits as another);
 * - L3 the same for the paper's Examination;
 * - L4 a correction being approved vs a re-point -- refused after it.
 */
class StudentMarkDatabaseDefenceConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function raw(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-raw-op.php', ...$args];
    }

    /** @return list<string> */
    private function marksOp(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-mark-op.php', ...$args];
    }

    /** A raw insert of a valid mark for $student on $paperKey ('paper' or 'electivePaper'). @return list<string> */
    private function insertMark(array $w, Student $student, string $paperKey = 'paper', ?string $electiveRowId = null): array
    {
        $columns = $this->inMarksSchool($w['school'], fn () => [
            'school_id' => $w['school']->id,
            'examination_paper_id' => $w[$paperKey]->id,
            'academic_year_id' => $w['year']->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $this->placementOf($w, $student)->id,
            'eligibility_source' => $electiveRowId === null ? 'required' : 'elective',
            'student_subject_enrollment_id' => $electiveRowId,
            'processing_authorization_id' => DB::table('student_processing_authorizations')->where('student_id', $student->id)->value('id'),
            'status' => 'present',
            'value' => '12.00',
            'version' => 1,
            'recorded_by_user_id' => $w['admin']->id,
        ]);

        return $this->raw('insert-mark', $w['school']->id, json_encode($columns, JSON_THROW_ON_ERROR));
    }

    private function markCount(array $w, string $paperKey = 'paper'): int
    {
        return $this->inMarksSchool($w['school'], fn () => StudentMark::query()->where('examination_paper_id', $w[$paperKey]->id)->count());
    }

    #[Test]
    public function l1_a_raw_insert_behind_the_paper_lock_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->marksOp('lock', $w['school']->id, $w['paper']->id, $w['admin']->id),
            $this->insertMark($w, $student),
        );

        $this->assertSame('locked:locked', $holder);
        $this->assertSame('refused:student_marks: the paper\'s marks are locked', $contender, 'the insert waited for the lock, then saw it');
        $this->assertSame(0, $this->markCount($w));
    }

    #[Test]
    public function l1_a_lock_behind_a_raw_insert_waits_then_locks_a_paper_that_includes_it(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->insertMark($w, $student),
            $this->marksOp('lock', $w['school']->id, $w['paper']->id, $w['admin']->id),
        );

        $this->assertSame(['inserted', 'locked:locked'], [$holder, $contender]);
        $this->assertSame(1, $this->markCount($w));
    }

    #[Test]
    public function l2_a_raw_insert_behind_an_offering_repoint_is_judged_against_the_new_offering(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $rowId = $this->elect($w, $student); // an enrollment in the elective paper's CURRENT Offering
        $otherElective = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->raw('repoint', $w['school']->id, $w['electivePaper']->id, 'subject_offering_id', $otherElective->id),
            $this->insertMark($w, $student, 'electivePaper', $rowId),
        );

        $this->assertSame('repointed:1', $holder);
        $this->assertSame('refused:student_marks: the elective enrollment is not this Student\'s enrollment in the paper\'s Offering', $contender,
            'the insert waited for the paper, then validated against the Offering the paper now has');
        $this->assertSame(0, $this->markCount($w, 'electivePaper'));
    }

    #[Test]
    public function l2_an_offering_repoint_behind_a_raw_insert_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $otherRequired = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->insertMark($w, $student),
            $this->raw('repoint', $w['school']->id, $w['paper']->id, 'subject_offering_id', $otherRequired->id),
        );

        $this->assertSame('inserted', $holder);
        $this->assertSame('refused:examination_papers: a paper with recorded marks keeps its Examination and Subject Offering', $contender);
        $this->assertSame((string) $w['required']->id, $this->inMarksSchool($w['school'], fn () => (string) DB::table('examination_papers')->where('id', $w['paper']->id)->value('subject_offering_id')));
    }

    #[Test]
    public function l3_an_examination_repoint_and_a_raw_insert_serialize_both_ways(): void
    {
        $w = $this->marksWorld();
        $first = $this->markStudent($w);
        $second = $this->markStudent($w);
        $otherExamination = $this->createExamination($w['year'], ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'active']);

        // Re-point first (no marks yet): the insert waits, then attaches to the paper as it now is -- consistently.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->raw('repoint', $w['school']->id, $w['paper']->id, 'examination_id', $otherExamination->id),
            $this->insertMark($w, $first),
        );
        $this->assertSame(['repointed:1', 'inserted'], [$holder, $contender]);

        // Now marked: an Examination change behind another insert is refused.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->insertMark($w, $second),
            $this->raw('repoint', $w['school']->id, $w['paper']->id, 'examination_id', $w['examination']->id),
        );
        $this->assertSame(['inserted', 'refused:examination_papers: a paper with recorded marks keeps its Examination and Subject Offering'], [$holder, $contender]);
        $this->assertSame((string) $otherExamination->id, $this->inMarksSchool($w['school'], fn () => (string) DB::table('examination_papers')->where('id', $w['paper']->id)->value('examination_id')));
    }

    #[Test]
    public function l4_a_repoint_behind_a_correction_approval_is_refused(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $correction = $this->requestCorrection($w, $this->markOf($w, $student), 'present', '41');
        $checker = $this->checker($w);
        $otherRequired = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->marksOp('approve-correction', $w['school']->id, $correction->id, $checker->id),
            $this->raw('repoint', $w['school']->id, $w['paper']->id, 'subject_offering_id', $otherRequired->id),
        );

        $this->assertSame(['decided:approved', 'refused:examination_papers: a paper with recorded marks keeps its Examination and Subject Offering'], [$holder, $contender]);
        $this->assertSame(['41.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }
}
