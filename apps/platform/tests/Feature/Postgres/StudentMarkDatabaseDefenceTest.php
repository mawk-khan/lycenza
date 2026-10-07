<?php

namespace Tests\Feature\Postgres;

use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperMarksRecordedException;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * S6 (ADR 0068 §27.11): StudentMark's database defence in depth, proven at the
 * raw PostgreSQL layer as the runtime role under the School's RLS context --
 * no service in the way:
 * - no ordinary mark is inserted or changed on a LOCKED paper (only a pending
 *   correction's exact change, approved by commit -- RES.3);
 * - once a paper has a mark, its Examination and Subject Offering never change
 *   (with its maximum and date, already frozen by RES.2);
 * - no-op writes and unmarked papers are unaffected; another School cannot
 *   reach any of it.
 */
class StudentMarkDatabaseDefenceTest extends TestCase
{
    use CreatesStudentMarkFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function refused(callable $op, string $fragment): void
    {
        try {
            DB::connection('pgsql')->transaction($op); // a savepoint: the refusal never aborts the test transaction
            $this->fail("expected a refusal containing '{$fragment}'");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** The raw column values of a valid, fresh mark for $student on the required paper. @return array<string, mixed> */
    private function rawMark(array $w, Student $student, string $value = '10.00'): array
    {
        return $this->inMarksSchool($w['school'], fn () => [
            'id' => (string) Str::uuid7(),
            'school_id' => $w['school']->id,
            'examination_paper_id' => $w['paper']->id,
            'academic_year_id' => $w['year']->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $this->placementOf($w, $student)->id,
            'eligibility_source' => 'required',
            'student_subject_enrollment_id' => null,
            'processing_authorization_id' => DB::table('student_processing_authorizations')->where('student_id', $student->id)->value('id'),
            'status' => 'present',
            'value' => $value,
            'version' => 1,
            'recorded_by_user_id' => $w['admin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A second Examination in the paper's year, and a second required Offering in its grade -- both without a paper. @return array{0: string, 1: string} */
    private function otherIdentity(array $w): array
    {
        $examination = $this->createExamination($w['year'], ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'active']);
        $offering = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);

        return [$examination->id, $offering->id];
    }

    #[Test]
    public function a_locked_paper_takes_no_ordinary_mark_insert_or_change_but_an_approved_correction_still_applies(): void
    {
        $w = $this->marksWorld();
        $marked = $this->markStudent($w);
        $late = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($marked, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $marked);
        $raw = $this->rawMark($w, $late);
        $this->setSchool($w['school']->id); // after every fixture call: withSchool() leaves the GUC empty

        $this->refused(fn () => DB::table('student_marks')->insert($raw), 'the paper\'s marks are locked');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['value' => '41.00', 'version' => 2]), 'a locked mark changes only through a pending correction');

        // RES.3: the approved correction is still the one way to change it.
        $correction = $this->requestCorrection($w, $mark, 'present', '42');
        $this->approveCorrection($w, $correction, $this->checker($w));
        $this->assertSame(['42.00', 2], [(string) $this->markOf($w, $marked)->value, $this->markOf($w, $marked)->version]);
    }

    #[Test]
    public function a_marked_paper_keeps_its_examination_and_offering(): void
    {
        $w = $this->marksWorld();
        $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '40')]);
        [$examinationId, $offeringId] = $this->otherIdentity($w);
        $this->setSchool($w['school']->id);
        $paper = fn () => DB::table('examination_papers')->where('id', $w['paper']->id);

        $this->refused(fn () => $paper()->update(['subject_offering_id' => $offeringId]), 'keeps its Examination and Subject Offering');
        $this->refused(fn () => $paper()->update(['examination_id' => $examinationId]), 'keeps its Examination and Subject Offering');
        $this->refused(fn () => $paper()->update(['max_marks' => '90.00']), 'keeps its maximum marks and date');

        // No-op identity writes, and the fields a mark does not depend on, stay writable.
        $this->assertSame(1, $paper()->update(['subject_offering_id' => $w['required']->id, 'examination_id' => $w['examination']->id]));
        $this->assertSame(1, $paper()->update(['starts_at' => '10:00:00', 'ends_at' => '11:30:00', 'status' => 'inactive']));
        $this->assertSame([$w['examination']->id, $w['required']->id], [(string) $paper()->value('examination_id'), (string) $paper()->value('subject_offering_id')]);
    }

    #[Test]
    public function an_unmarked_paper_is_unaffected_and_the_service_keeps_its_domain_answer(): void
    {
        $w = $this->marksWorld();
        [$examinationId, $offeringId] = $this->otherIdentity($w);
        $this->setSchool($w['school']->id);

        $this->assertSame(1, DB::table('examination_papers')->where('id', $w['paper']->id)->update(['subject_offering_id' => $offeringId, 'examination_id' => $examinationId]),
            'without marks the database does not freeze the identity (the application never reassigns it)');

        // The application contract is unchanged: a marked paper's maximum is a 409 EXAMINATION_PAPER_HAS_MARKS, not a database error.
        $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '40')]);
        $paper = $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->findOrFail($w['paper']->id));
        $this->assertThrows(fn () => $this->inMarksSchool($w['school'], fn () => app(ExaminationPaperService::class)->update($w['school'], $paper, ['max_marks' => '90.00'], $w['admin'])), ExaminationPaperMarksRecordedException::class);
    }

    #[Test]
    public function another_school_cannot_reach_a_marked_paper_or_its_marks(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $other = $this->marksWorld();
        $raw = $this->rawMark($w, $this->markStudent($w));
        $this->setSchool($other['school']->id);

        $this->assertSame(0, DB::table('examination_papers')->where('id', $w['paper']->id)->update(['subject_offering_id' => $other['required']->id]));
        $this->assertSame(0, DB::table('student_marks')->where('examination_paper_id', $w['paper']->id)->count());
        // Under another School's context the paper is invisible, so the mark's own guard refuses it (RLS would too).
        $this->refused(fn () => DB::table('student_marks')->insert($raw), 'student_marks: the mark is not in its paper');
    }
}
