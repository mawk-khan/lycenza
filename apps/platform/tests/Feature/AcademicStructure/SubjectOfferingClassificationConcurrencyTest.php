<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * ADR 0069 (S1): the classification freeze under real concurrency -- two
 * separate OS processes against PostgreSQL, the holder held uncommitted until
 * the contender is observed blocked (ForcesConcurrentOverlap).
 *
 * - T1 required evidence (TeachingAssignment) vs a flip, both orders;
 * - T2 elective evidence (StudentSubjectEnrollment) vs a flip, both orders;
 * - T3 an ExaminationPaper vs a flip, both orders;
 * - T4 a mark being recorded vs a flip: the flip waits, then is refused;
 * - T5 a writer that checks the classification WITHOUT locking the Offering
 *   (Curriculum Delivery) vs a flip: the evidence trigger's FOR SHARE makes it
 *   wait and re-read, and the database refuses the mismatch.
 * Whichever commits first decides; nothing commits against a classification
 * that changed underneath it, and no classification changes under evidence.
 */
class SubjectOfferingClassificationConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTeacherStudentMarkFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/subject-offering-classification-op.php', ...$args];
    }

    /** @param  array<string, mixed>  $w */
    private function offering(array $w, bool $required): SubjectOffering
    {
        return $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => $required, 'status' => 'active']);
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function flip(array $w, SubjectOffering $offering, bool $toRequired): array
    {
        $w['structureAdmin'] ??= $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        return $this->op('flip', $w['school']->id, $offering->id, $toRequired ? '1' : '0', $w['structureAdmin']->id);
    }

    private function isRequired(array $w, SubjectOffering $offering): bool
    {
        return (bool) $this->inMarksSchool($w['school'], fn () => SubjectOffering::query()->whereKey($offering->id)->value('is_required'));
    }

    private function rowsFor(array $w, string $model, SubjectOffering $offering): int
    {
        return $this->inMarksSchool($w['school'], fn () => $model::query()->where('subject_offering_id', $offering->id)->count());
    }

    #[Test]
    public function t1_a_flip_behind_the_first_teaching_assignment_is_refused(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $offering = $this->offering($w, true);
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('create-ta', $w['school']->id, $employee->id, $w['a1']->id, $offering->id, $w['assigner']->id),
            $this->flip($w, $offering, false),
        );

        $this->assertSame("assigned:{$offering->id}", $holder);
        $this->assertSame('refused:SUBJECT_OFFERING_CLASSIFICATION_LOCKED', $contender, 'the flip waited for the assignment, then saw it');
        $this->assertTrue($this->isRequired($w, $offering));
    }

    #[Test]
    public function t1_a_teaching_assignment_behind_a_flip_sees_the_new_classification(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $offering = $this->offering($w, true);
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->flip($w, $offering, false),
            $this->op('create-ta', $w['school']->id, $employee->id, $w['a1']->id, $offering->id, $w['assigner']->id),
        );

        $this->assertSame('flipped:elective', $holder);
        $this->assertSame('refused:TEACHING_ASSIGNMENT_REQUIRED_OFFERING_ONLY', $contender);
        $this->assertSame(0, $this->rowsFor($w, TeachingAssignment::class, $offering));
    }

    #[Test]
    public function t2_a_flip_behind_the_first_elective_enrollment_is_refused(): void
    {
        $w = $this->teacherMarksWorld();
        $offering = $this->offering($w, false);
        $student = $this->markStudent($w, 'a1');
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('enroll-elective', $w['school']->id, $student->id, $offering->id),
            $this->flip($w, $offering, true),
        );

        $this->assertSame('enrolled:active', $holder);
        $this->assertSame('refused:SUBJECT_OFFERING_CLASSIFICATION_LOCKED', $contender);
        $this->assertFalse($this->isRequired($w, $offering));
    }

    #[Test]
    public function t2_an_elective_enrollment_behind_a_flip_sees_the_new_classification(): void
    {
        $w = $this->teacherMarksWorld();
        $offering = $this->offering($w, false);
        $student = $this->markStudent($w, 'a1');
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->flip($w, $offering, true),
            $this->op('enroll-elective', $w['school']->id, $student->id, $offering->id),
        );

        $this->assertSame('flipped:required', $holder);
        $this->assertSame('refused:REQUIRED_SUBJECT_OFFERING_ENROLLMENT', $contender);
        $this->assertSame(0, $this->rowsFor($w, StudentSubjectEnrollment::class, $offering));
    }

    #[Test]
    public function t3_a_flip_behind_the_first_examination_paper_is_refused_and_a_paper_behind_a_flip_follows_it(): void
    {
        $w = $this->teacherMarksWorld();
        $first = $this->offering($w, true);
        $second = $this->offering($w, true);
        $paperAdmin = $this->createUserWithCapabilities($w['school'], ['examinations.papers.view', 'examinations.papers.manage']);
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('create-paper', $w['school']->id, $w['examination']->id, $first->id, '2026-09-21', $paperAdmin->id),
            $this->flip($w, $first, false),
        );
        $this->assertSame(['paper:active', 'refused:SUBJECT_OFFERING_CLASSIFICATION_LOCKED'], [$holder, $contender]);
        $this->assertTrue($this->isRequired($w, $first));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->flip($w, $second, false),
            $this->op('create-paper', $w['school']->id, $w['examination']->id, $second->id, '2026-09-22', $paperAdmin->id),
        );
        $this->assertSame(['flipped:elective', 'paper:active'], [$holder, $contender], 'a paper is classification-neutral: it waited, then attached to the new classification');
        $this->assertSame([false, 1], [$this->isRequired($w, $second), $this->rowsFor($w, ExaminationPaper::class, $second)]);
    }

    #[Test]
    public function t4_a_flip_behind_a_mark_being_recorded_waits_and_is_refused(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w, 'a1');
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('record-mark', $w['school']->id, $w['paper']->id, $w['admin']->id, $student->id, '44', '-'),
            $this->flip($w, $w['required'], false),
        );

        $this->assertSame(['recorded:v1', 'refused:SUBJECT_OFFERING_CLASSIFICATION_LOCKED'], [$holder, $contender]);
        $this->assertSame(['44.00', 'required'], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->eligibility_source]);
        $this->assertTrue($this->isRequired($w, $w['required']));
    }

    #[Test]
    public function t5_a_writer_that_reads_the_classification_without_a_lock_is_stopped_by_the_database(): void
    {
        $w = $this->teacherMarksWorld();
        $offering = $this->offering($w, true);
        $unit = $this->inMarksSchool($w['school'], fn () => SyllabusUnit::query()->create(['school_id' => $w['school']->id, 'subject_offering_id' => $offering->id, 'code' => 'R1', 'title' => 'Race', 'sequence' => 1, 'status' => 'active']));
        $deliveryAdmin = $this->createUserWithCapabilities($w['school'], ['curriculum.delivery.view', 'curriculum.delivery.manage']);
        $w['structureAdmin'] = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);

        // Curriculum Delivery checks "required" on an unlocked read, which still says required while the flip is
        // uncommitted; its insert then waits on the evidence trigger's FOR SHARE and meets the committed elective.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->flip($w, $offering, false),
            $this->op('start-delivery', $w['school']->id, $offering->id, $w['a1']->id, $unit->id, '2026-07-01', $deliveryAdmin->id),
        );

        $this->assertSame('flipped:elective', $holder);
        $this->assertSame('db:subject_offering_classification_mismatch', $contender);
        $this->assertSame(0, $this->rowsFor($w, CurriculumDelivery::class, $offering));
    }
}
