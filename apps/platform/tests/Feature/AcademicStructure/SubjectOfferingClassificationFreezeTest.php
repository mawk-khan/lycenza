<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\Exceptions\SubjectOfferingClassificationLockedException;
use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Academic Structure integrity (ADR 0069; ADR 0068 §27.11 S1): a
 * SubjectOffering's required/elective classification is frozen -- both
 * directions -- once any dependent academic evidence exists: an elective
 * enrollment, a teaching assignment (required or elective), a timetable
 * entry, a curriculum delivery, an attendance register or an examination
 * paper (any status). Enforced by the database (raw SQL cannot bypass it)
 * and surfaced as 409 SUBJECT_OFFERING_CLASSIFICATION_LOCKED. Other fields
 * and no-op classification writes stay allowed; evidence cannot be recorded
 * against the wrong classification either.
 */
class SubjectOfferingClassificationFreezeTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures, CreatesTimetableFixtures;

    private function structureAdmin(array $w): User
    {
        return $w['structureAdmin'] ??= $this->createUserWithCapabilities($w['school'], ['academics.subjects.view', 'academics.subjects.manage']);
    }

    /** @param  array<string, mixed>  $w */
    private function offering(array $w, bool $required): SubjectOffering
    {
        return $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => $required, 'status' => 'active']);
    }

    /** @param  array<string, mixed>  $w @param  array<string, mixed>  $attributes */
    private function update(array $w, SubjectOffering $offering, array $attributes): SubjectOffering
    {
        return app(SubjectOfferingService::class)->update($w['school'], $offering->id, $attributes, $this->structureAdmin($w));
    }

    private function classification(array $w, SubjectOffering $offering): bool
    {
        return (bool) $this->inMarksSchool($w['school'], fn () => SubjectOffering::query()->whereKey($offering->id)->value('is_required'));
    }

    /** @param  array<string, mixed>  $w */
    private function assertFrozen(array $w, SubjectOffering $offering, string $because): void
    {
        $was = $this->classification($w, $offering);
        $this->assertThrows(fn () => $this->update($w, $offering, ['is_required' => ! $was]), SubjectOfferingClassificationLockedException::class);
        $this->assertSame($was, $this->classification($w, $offering), $because);
        // Never frozen: a no-op classification and every other mutable field.
        $this->update($w, $offering, ['is_required' => $was, 'weekly_periods_target' => 5, 'sequence' => 3]);
        $this->assertSame(5, $this->inMarksSchool($w['school'], fn () => SubjectOffering::query()->whereKey($offering->id)->value('weekly_periods_target')));
    }

    #[Test]
    public function without_evidence_the_classification_changes_both_ways(): void
    {
        $w = $this->teacherMarksWorld();
        $required = $this->offering($w, true);
        $elective = $this->offering($w, false);

        $this->update($w, $required, ['is_required' => false]);
        $this->update($w, $elective, ['is_required' => true]);
        $this->assertSame([false, true], [$this->classification($w, $required), $this->classification($w, $elective)]);
        $this->update($w, $required, ['is_required' => false, 'status' => 'inactive']);
        $this->assertSame([false, 'inactive'], [$this->classification($w, $required), $this->inMarksSchool($w['school'], fn () => SubjectOffering::query()->whereKey($required->id)->value('status'))]);
    }

    #[Test]
    public function required_subject_evidence_freezes_required_to_elective(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);

        $ta = $this->offering($w, true);
        app(TeachingAssignmentService::class)->create($w['school'], $employee->id, $w['a1']->id, $ta->id, '2026-06-01', null, $w['assigner']);
        $this->assertFrozen($w, $ta, 'a TeachingAssignment freezes it');

        $timetabled = $this->offering($w, true);
        $this->createTimetableEntry($timetabled, $w['a1'], $employee, $this->createTimetablePeriod($w['school']));
        $this->assertFrozen($w, $timetabled, 'a timetable entry freezes it');

        $delivered = $this->offering($w, true);
        $this->inMarksSchool($w['school'], function () use ($w, $delivered): void {
            $unit = SyllabusUnit::query()->create(['school_id' => $w['school']->id, 'subject_offering_id' => $delivered->id, 'code' => 'U1', 'title' => 'Unit 1', 'sequence' => 1, 'status' => 'active']);
            (new CurriculumDelivery)->forceFill([
                'school_id' => $w['school']->id, 'section_id' => $w['a1']->id, 'syllabus_unit_id' => $unit->id, 'subject_offering_id' => $delivered->id,
                'academic_year_id' => $delivered->academic_year_id, 'campus_id' => $delivered->campus_id, 'grade_level_id' => $delivered->grade_level_id,
                'started_on' => '2026-07-01', 'status' => 'in_progress',
            ])->save();
        });
        $this->assertFrozen($w, $delivered, 'a curriculum delivery freezes it');

        // Classification-neutral records do not freeze it: a syllabus unit alone.
        $unitOnly = $this->offering($w, true);
        $this->inMarksSchool($w['school'], fn () => SyllabusUnit::query()->create(['school_id' => $w['school']->id, 'subject_offering_id' => $unitOnly->id, 'code' => 'U9', 'title' => 'Unit 9', 'sequence' => 1, 'status' => 'active']));
        $this->update($w, $unitOnly, ['is_required' => false]);
        $this->assertFalse($this->classification($w, $unitOnly));
    }

    #[Test]
    public function elective_evidence_freezes_elective_to_required_even_after_it_ended(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);

        $enrolled = $this->offering($w, false);
        $student = $this->markStudent($w, 'a1');
        $enrollmentId = $this->elect($w, $student, $enrolled);
        $this->assertFrozen($w, $enrolled, 'a StudentSubjectEnrollment freezes it');
        $this->inMarksSchool($w['school'], fn () => app(StudentSubjectEnrollmentService::class)->cancel(StudentSubjectEnrollment::query()->findOrFail($enrollmentId), '2026-06-01'));
        $this->assertFrozen($w, $enrolled, 'a cancelled enrollment is still history (P3 counts cancelled intervals)');

        $owned = $this->offering($w, false);
        $assignment = app(ElectiveTeachingAssignmentService::class)->create($w['school'], $employee->id, $owned->id, '2026-06-01', null, $w['assigner']);
        $this->assertFrozen($w, $owned, 'an elective teaching assignment freezes it');
        app(ElectiveTeachingAssignmentService::class)->end($w['school'], $assignment->id, '2026-06-30', 'completed', $w['assigner']);
        $this->assertFrozen($w, $owned, 'an ended assignment is still history');
    }

    #[Test]
    public function an_examination_paper_freezes_either_classification_and_marks_keep_their_context(): void
    {
        $w = $this->teacherMarksWorld();
        // The world's papers already exist: both of its Offerings are frozen before any mark.
        $this->assertFrozen($w, $w['required'], 'a paper on a required Offering freezes it');
        $this->assertFrozen($w, $w['elective'], 'a paper on an elective Offering freezes it');

        $student = $this->markStudent($w, 'a1');
        $this->elect($w, $student);
        $this->recordMarks($w, [$this->entry($student, 'present', '30')], $w['electivePaper']);
        $this->assertFrozen($w, $w['elective'], 'and so do its marks');
        $this->recordMarks($w, [$this->entry($student, 'present', '31', 1)], $w['electivePaper']);
        $this->assertSame(['31.00', 'elective'], [(string) $this->markOf($w, $student, $w['electivePaper'])->value, $this->markOf($w, $student, $w['electivePaper'])->eligibility_source]);

        $inactivePaper = $this->offering($w, true);
        $this->createExaminationPaper($w['examination'], $inactivePaper, ['scheduled_on' => '2026-09-20', 'max_marks' => '20.00', 'status' => 'inactive']);
        $this->assertFrozen($w, $inactivePaper, 'an inactive paper is still a paper');
    }

    #[Test]
    public function evidence_cannot_be_recorded_against_the_wrong_classification(): void
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        $elective = $this->offering($w, false);
        $required = $this->offering($w, true);

        try {
            $period = $this->createTimetablePeriod($w['school']);
            DB::transaction(fn () => $this->createTimetableEntry($elective, $w['a1'], $employee, $period)); // a savepoint: the refusal must not abort the test's transaction
            $this->fail('a timetable entry needs a required Offering');
        } catch (QueryException $e) {
            $this->assertStringContainsString('subject_offering_classification_mismatch', $e->getMessage());
        }

        $student = $this->markStudent($w, 'a1');
        try {
            $placement = $this->placementOf($w, $student);
            DB::transaction(fn () => $this->inMarksSchool($w['school'], fn () => (new StudentSubjectEnrollment)->forceFill([
                'school_id' => $w['school']->id, 'student_id' => $student->id, 'subject_offering_id' => $required->id,
                'academic_year_id' => $required->academic_year_id, 'student_enrollment_id' => $placement->id,
                'starts_on' => '2026-06-01', 'status' => 'active',
            ])->save()));
            $this->fail('an elective enrollment needs an elective Offering');
        } catch (QueryException $e) {
            $this->assertStringContainsString('subject_offering_classification_mismatch', $e->getMessage());
        }
    }

    #[Test]
    public function raw_sql_cannot_flip_a_frozen_classification_and_another_schools_evidence_freezes_nothing(): void
    {
        $w = $this->teacherMarksWorld();
        $other = $this->teacherMarksWorld();
        $free = $this->offering($w, true);
        $setSchool = fn (string $id) => DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $id]);

        $setSchool($w['school']->id);
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('subject_offerings')->where('id', $w['required']->id)->update(['is_required' => false]));
            $this->fail('the database refuses the flip');
        } catch (QueryException $e) {
            $this->assertStringContainsString('subject_offering_classification_locked', $e->getMessage());
        }
        $this->assertSame(1, DB::connection('pgsql')->table('subject_offerings')->where('id', $w['required']->id)->update(['is_required' => true]), 'a no-op write is not a change');
        $this->assertSame(1, DB::connection('pgsql')->table('subject_offerings')->where('id', $free->id)->update(['is_required' => false]), 'an Offering without evidence flips');

        // School B has its own papers on its own Offerings; School A's evidence-free Offering is unaffected, and School
        // B's runtime context cannot even see (let alone flip) School A's frozen one.
        $setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('subject_offerings')->where('id', $w['required']->id)->update(['is_required' => false]));
        $setSchool($w['school']->id);
        $this->assertSame(1, DB::connection('pgsql')->table('subject_offerings')->where('id', $free->id)->update(['is_required' => true]));
        $this->assertTrue($this->classification($w, $w['required']));
    }

    #[Test]
    public function the_api_answers_409_with_a_fixed_body_and_audits_only_real_updates(): void
    {
        $w = $this->teacherMarksWorld();
        $admin = $this->structureAdmin($w);
        $url = "/api/v1/schools/{$w['school']->id}/subject-offerings/{$w['required']->id}";
        $audits = fn () => $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'subject_offering.updated')->count());

        $this->actingAs($admin)->withHeader('X-School-Id', $w['school']->id)->patchJson($url, ['is_required' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'SUBJECT_OFFERING_CLASSIFICATION_LOCKED')
            ->assertJsonPath('error.message', 'This Subject Offering already has academic records, so it cannot be changed between required and elective.');
        $this->assertSame(0, $audits(), 'a refused change writes no audit row');

        $this->patchJson($url, ['is_required' => true, 'weekly_periods_target' => 6])->assertOk()->assertJsonPath('data.isRequired', true)->assertJsonPath('data.weeklyPeriodsTarget', 6);
        $this->assertSame(1, $audits());

        $viewer = $this->createUserWithCapabilities($w['school'], ['academics.subjects.view']);
        $this->actingAs($viewer)->patchJson($url, ['weekly_periods_target' => 2])->assertForbidden();
    }

    /** The rule's evidence set, as installed: one guard per evidence table, and the freeze reads exactly those tables. */
    #[Test]
    public function the_database_rule_covers_exactly_the_evidence_tables(): void
    {
        $tables = collect(DB::select("select c.relname, pg_get_triggerdef(t.oid) as def from pg_trigger t join pg_class c on c.oid = t.tgrelid
            where t.tgname = 'trg_subject_offering_evidence' and not t.tgisinternal order by c.relname"))
            ->mapWithKeys(fn ($r) => [$r->relname => preg_replace("/.*subject_offering_evidence_guard\\('(\\w+)'\\).*/", '$1', $r->def)])->all();
        $this->assertSame([
            'attendance_sessions' => 'required', 'curriculum_deliveries' => 'required', 'elective_teaching_assignments' => 'elective',
            'examination_papers' => 'any', 'student_subject_enrollments' => 'elective', 'teaching_assignments' => 'required', 'timetable_entries' => 'required',
        ], $tables);

        $freeze = (string) DB::selectOne("select pg_get_functiondef('subject_offering_classification_freeze'::regproc) as d")->d;
        foreach (array_keys($tables) as $table) {
            $this->assertStringContainsString("public.{$table} e WHERE e.subject_offering_id = OLD.id", $freeze, "{$table} is evidence");
        }
        $this->assertSame(1, (int) DB::selectOne("select count(*) as c from pg_trigger where tgname = 'trg_subject_offering_classification_freeze' and tgrelid = 'subject_offerings'::regclass")->c);
    }
}
