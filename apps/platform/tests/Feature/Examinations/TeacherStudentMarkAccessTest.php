<?php

namespace Tests\Feature\Examinations;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\Examinations\Application\Exceptions\StudentMarkAcademicYearClosedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperInactiveException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkPaperLockedException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkProcessingBasisUnavailableException;
use App\Domain\Examinations\Application\Exceptions\StudentMarkVersionConflictException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkStudentNotFoundException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarksUnavailableException;
use App\Domain\Examinations\Application\Marks\TeacherStudentMarkReadService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\Role;
use App\Models\SchoolAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.4 (ADR 0068 §25): teacher-owned StudentMark entry through the
 * Application layer -- every predicate required, none a substitute: the
 * development-only block, `examinations.marks.teacher`, an eligible
 * ActingEmployee today, per Student P3 on the paper's date AND ownership on
 * that date (required: the Student's Section x Offering; elective: the
 * Offering) AND a current ADR 0038 basis, the paper active and open, its year
 * not closed. Writes go through StudentMarkService (one writer, history,
 * version guard); co-teachers and short cover assignments are ordinary owners
 * (owner-adopted development rules pending RES-L2).
 */
class TeacherStudentMarkAccessTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    /** @return list<SchoolAuditEvent> */
    private function audits(array $w, string $type): array
    {
        return $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $type)->orderBy('occurred_at')->get()->all());
    }

    #[Test]
    public function an_assigned_required_subject_teacher_reads_and_writes_only_their_sections_students(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $mine = $this->markStudent($w, 'a1');
        $other = $this->markStudent($w, 'a2');
        $this->recordMarks($w, [$this->entry($other, 'present', '70')]); // an administrator's mark in the other Section

        $read = $this->teacherRead($w, $teacher);
        $this->assertSame([$mine->id], $this->rowIds($read), 'an Offering-wide paper never gives the teacher every Student');
        $this->assertSame(0, $read['unavailableCount']);
        $this->assertNull($read['rows'][0]['mark']);
        $this->assertSame(['id', 'subjectOfferingId', 'scheduledOn', 'maxMarks', 'marksState'], array_keys($read['paper']));
        $this->assertSame(['studentId', 'rollNumber', 'fullName', 'mark'], array_keys($read['rows'][0]));

        $written = $this->teacherRecord($w, $teacher, [$this->entry($mine, 'present', '55.5')]);
        $mark = $this->markOf($w, $mine);
        $this->assertSame([['studentId' => $mine->id, 'studentMarkId' => $mark->id, 'version' => 1]], $written);
        $this->assertSame(['55.50', $teacher->id, 'required'], [(string) $mark->value, $mark->recorded_by_user_id, $mark->eligibility_source]);
        $this->assertCount(1, $this->revisionsOf($w, $mark), 'the database history records the teacher write like any other');

        $this->teacherRecord($w, $teacher, [$this->entry($mine, 'absent', null, 1)]);
        $this->assertSame(['absent', 2], [$this->markOf($w, $mine)->status, $this->markOf($w, $mine)->version]);
        $this->assertSame('70.00', (string) $this->markOf($w, $other)->value);

        // The other Section's Student is refused -- and a mixed batch saves nothing (atomic).
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($other, 'present', '1', 1)]), TeacherStudentMarkStudentNotFoundException::class);
        $fresh = $this->markStudent($w, 'a1');
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($fresh, 'present', '9'), $this->entry($other, 'present', '1', 1)]), TeacherStudentMarkStudentNotFoundException::class);
        $this->assertNull($this->markOf($w, $fresh));
    }

    #[Test]
    public function an_assigned_elective_teacher_owns_the_offering_without_a_section(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownElective($w, $employee);
        $a1 = $this->markStudent($w, 'a1');
        $a2 = $this->markStudent($w, 'a2');
        $notElected = $this->markStudent($w, 'a1');
        $this->elect($w, $a1);
        $this->elect($w, $a2);

        $read = $this->teacherRead($w, $teacher, $w['electivePaper']);
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $this->rowIds($read), 'every elected Student, across Sections -- and only them');

        $this->teacherRecord($w, $teacher, [$this->entry($a1, 'present', '40'), $this->entry($a2, 'exempt', null)], $w['electivePaper']);
        $this->assertSame('elective', $this->markOf($w, $a1, $w['electivePaper'])->eligibility_source);
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($notElected, 'present', '1')], $w['electivePaper']), TeacherStudentMarkStudentNotFoundException::class);

        // Elective ownership never reaches the required paper, and required ownership never the elective one.
        $this->assertThrows(fn () => $this->teacherRead($w, $teacher), TeacherStudentMarkPaperNotFoundException::class);
        [$sectionTeacher, $sectionEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $sectionEmployee, 'a1');
        $this->assertThrows(fn () => $this->teacherRead($w, $sectionTeacher, $w['electivePaper']), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $sectionTeacher, [$this->entry($a1, 'present', '1', 1)], $w['electivePaper']), TeacherStudentMarkPaperNotFoundException::class);

        // A StudentSubjectEnrollment alone is never teacher authority; nor is another elective's ownership.
        [$otherElectiveTeacher, $otherEmployee] = $this->markTeacher($w);
        $this->ownElective($w, $otherEmployee, $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']));
        $this->assertThrows(fn () => $this->teacherRead($w, $otherElectiveTeacher, $w['electivePaper']), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $otherElectiveTeacher, [$this->entry($a1, 'present', '1', 1)], $w['electivePaper']), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertSame('40.00', (string) $this->markOf($w, $a1, $w['electivePaper'])->value);
    }

    #[Test]
    public function an_unrelated_teacher_sees_nothing_and_unknown_or_ineligible_students_look_alike(): void
    {
        $w = $this->teacherMarksWorld();
        [$unrelated] = $this->markTeacher($w);
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '10')]);

        $this->assertThrows(fn () => $this->teacherRead($w, $unrelated), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $unrelated, [$this->entry($student, 'present', '1', 1)]), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => app(TeacherStudentMarkReadService::class)->paper($w['school'], (string) Str::uuid7(), $unrelated), TeacherStudentMarkPaperNotFoundException::class);

        // An owner naming an unknown id, an unplaced Student or a Student placed after the paper's date: one answer.
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $unplaced = $this->createStudent($w['school'], ['date_of_birth' => '2016-01-01']);
        $late = $this->createStudent($w['school'], ['date_of_birth' => '2016-01-01']);
        app(StudentEnrollmentService::class)->enroll($late, $w['a1'], '901', '2026-09-20');
        $this->authorise($w, $late);
        $codes = [];
        foreach ([(string) Str::uuid7(), $unplaced->id, $late->id] as $id) {
            try {
                $this->teacherRecord($w, $teacher, [$this->entry($id, 'present', '1')]);
                $this->fail('expected a refusal');
            } catch (TeacherStudentMarkStudentNotFoundException $e) {
                $codes[] = [$e->errorCode(), $e->getStatusCode(), $e->getMessage()];
            }
        }
        $this->assertCount(1, array_unique(array_map('serialize', $codes)), 'no eligibility reason distinguishes the cases');
        $this->assertSame(['STUDENT_MARK_STUDENT_NOT_FOUND', 404], array_slice($codes[0], 0, 2));
    }

    #[Test]
    public function ownership_is_judged_on_the_papers_date_and_the_actor_must_be_eligible_today(): void
    {
        $this->travelTo('2026-10-07 10:00:00');
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w, 'a1');

        // Ended before the paper's date: no ownership on scheduled_on.
        [$ended, $endedEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $endedEmployee, 'a1', '2026-06-01', '2026-09-14');
        $this->assertThrows(fn () => $this->teacherRead($w, $ended), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $ended, [$this->entry($student, 'present', '1')]), TeacherStudentMarkPaperNotFoundException::class);

        // Starting after the paper's date: likewise.
        [$later, $laterEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $laterEmployee, 'a1', '2026-09-16');
        $this->assertThrows(fn () => $this->teacherRead($w, $later), TeacherStudentMarkPaperNotFoundException::class);

        // Historical: owned on the paper's date (inclusive last day), ended since -- still the owner of that paper.
        [$historical, $historicalEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $historicalEmployee, 'a1', '2026-06-01', '2026-09-15');
        $this->assertSame([$student->id], $this->rowIds($this->teacherRead($w, $historical)));
        $this->teacherRecord($w, $historical, [$this->entry($student, 'present', '30')]);

        // Short dated cover on the paper's date: an ordinary owner (owner-adopted development rule).
        [$cover, $coverEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $coverEmployee, 'a1', '2026-09-14', '2026-09-16');
        $this->teacherRecord($w, $cover, [$this->entry($student, 'present', '31', 1)]);

        // Owned on the paper's date but no longer employed today: refused (ActingEmployee is judged today).
        [$leaver, $leaverEmployee] = $this->markTeacher($w, employment: ['ends_on' => '2026-09-30']);
        $this->ownSection($w, $leaverEmployee, 'a1', '2026-06-01', '2026-09-30');
        $this->assertThrows(fn () => $this->teacherRead($w, $leaver), ActingEmployeeUnavailableException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $leaver, [$this->entry($student, 'present', '32', 2)]), ActingEmployeeUnavailableException::class);
        $this->assertSame(['31.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function co_teachers_are_equal_owners_and_the_version_guard_prevents_lost_updates(): void
    {
        $w = $this->teacherMarksWorld();
        [$first, $firstEmployee] = $this->markTeacher($w);
        [$second, $secondEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $firstEmployee, 'a1');
        $this->ownSection($w, $secondEmployee, 'a1');
        $student = $this->markStudent($w, 'a1');

        $this->teacherRecord($w, $first, [$this->entry($student, 'present', '20')]);
        $this->assertSame('20.00', $this->teacherRead($w, $second)['rows'][0]['mark']['value']);
        $this->assertThrows(fn () => $this->teacherRecord($w, $second, [$this->entry($student, 'present', '25')]), StudentMarkVersionConflictException::class);
        $this->teacherRecord($w, $second, [$this->entry($student, 'present', '25', 1)]);
        $this->assertSame([$first->id, $second->id], array_map(fn ($r) => $r->recorded_by_user_id, $this->revisionsOf($w, $this->markOf($w, $student))));
    }

    #[Test]
    public function an_identity_without_an_eligible_employee_or_the_capability_is_refused(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w, 'a1');

        [$unlinked, $unlinkedEmployee] = $this->markTeacher($w, linked: false);
        $this->ownSection($w, $unlinkedEmployee, 'a1');
        $this->assertThrows(fn () => $this->teacherRead($w, $unlinked), ActingEmployeeUnavailableException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $unlinked, [$this->entry($student, 'present', '1')]), ActingEmployeeUnavailableException::class);

        // Ownership without the capability: a role carrying only teacher Attendance is refused marks.
        $role = Role::query()->create(['key' => 'test.attendance_only.'.Str::uuid(), 'name' => 'Attendance only', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['attendance.teacher']);
        [$attendanceOnly, $attendanceEmployee] = $this->markTeacher($w, roleKey: $role->key);
        $this->ownSection($w, $attendanceEmployee, 'a1');
        $this->assertThrows(fn () => $this->teacherRead($w, $attendanceOnly), AuthorizationException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $attendanceOnly, [$this->entry($student, 'present', '1')]), AuthorizationException::class);

        // The administrative marks keys do not imply the teacher path either.
        $this->assertThrows(fn () => $this->teacherRead($w, $w['admin']), AuthorizationException::class);
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function without_a_current_processing_basis_nothing_is_disclosed_and_nothing_is_written(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $never = $this->markStudent($w, 'a1', authorised: false);
        $withdrawn = $this->markStudent($w, 'a1', authorised: false);
        $grant = $this->authorise($w, $withdrawn);
        $this->recordMarks($w, [$this->entry($withdrawn, 'present', '61')]);
        $this->withdrawAuthorisation($w, $grant);

        $read = $this->teacherRead($w, $teacher);
        $this->assertSame([], $read['rows'], 'no row, mark, value or existence signal for a Student without a basis');
        $this->assertSame(2, $read['unavailableCount']);

        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($never, 'present', '1')]), StudentMarkProcessingBasisUnavailableException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($withdrawn, 'present', '1', 1)]), StudentMarkProcessingBasisUnavailableException::class);
        $this->assertNull($this->markOf($w, $never));
        $this->assertSame('61.00', (string) $this->markOf($w, $withdrawn)->value, 'the recorded mark stays historical evidence');
    }

    #[Test]
    public function a_locked_paper_inactive_paper_or_closed_year_refuses_teacher_entry(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);
        $student = $this->markStudent($w, 'a1');
        $this->elect($w, $student);
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '20')]);

        $this->lockMarks($w);
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '21', 1)]), StudentMarkPaperLockedException::class);
        $this->assertSame('locked', $this->teacherRead($w, $teacher)['paper']['marksState'], 'a locked paper stays readable, never writable');

        $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->whereKey($w['electivePaper']->id)->update(['status' => 'inactive']));
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '1')], $w['electivePaper']), StudentMarkPaperInactiveException::class);
        $this->assertThrows(fn () => $this->teacherRead($w, $teacher, $w['electivePaper']), StudentMarkPaperInactiveException::class);

        app(AcademicYearService::class)->close($w['year'], $w['admin']);
        $this->assertThrows(fn () => $this->teacherRead($w, $teacher), StudentMarkAcademicYearClosedException::class);
        $this->assertSame(['20.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
    }

    #[Test]
    public function reads_and_writes_are_audited_with_ids_only(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);
        $student = $this->markStudent($w, 'a1');
        $this->markStudent($w, 'a1', authorised: false);
        $this->elect($w, $student);

        $this->teacherRead($w, $teacher);
        [$viewed] = $this->audits($w, 'examinations.student_marks.teacher_viewed');
        $this->assertSame($teacher->id, $viewed->actor_user_id);
        $this->assertEquals(['examinationPaperId' => $w['paper']->id, 'employeeId' => $employee->id, 'rowCount' => 1, 'unavailableCount' => 1], $viewed->metadata);

        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '47.25')]);
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '48', 1)]);
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'absent', null)], $w['electivePaper']);
        $mark = $this->markOf($w, $student);
        [$recorded, $electiveRecorded] = $this->audits($w, 'examinations.student_mark.teacher_recorded');
        [$changed] = $this->audits($w, 'examinations.student_mark.teacher_changed');
        $this->assertEquals(['studentMarkId' => $mark->id, 'examinationPaperId' => $w['paper']->id, 'studentId' => $student->id, 'version' => 1,
            'employeeId' => $employee->id, 'ownershipSource' => 'teaching_assignment'], $recorded->metadata);
        $this->assertEquals(['studentMarkId' => $mark->id, 'examinationPaperId' => $w['paper']->id, 'studentId' => $student->id, 'version' => 2,
            'employeeId' => $employee->id, 'ownershipSource' => 'teaching_assignment'], $changed->metadata);
        $this->assertSame('elective_teaching_assignment', $electiveRecorded->metadata['ownershipSource']);
        $this->assertSame([], $this->audits($w, 'examinations.student_mark.recorded'), 'teacher writes carry the teacher events, never the administrative ones');
        foreach ([$viewed, $recorded, $changed, $electiveRecorded] as $event) {
            $this->assertStringNotContainsString('47.25', json_encode($event->metadata));
            $this->assertStringNotContainsString('48.00', json_encode($event->metadata));
        }

        // A refused read or write records nothing.
        $before = count($this->audits($w, 'examinations.student_marks.teacher_viewed'));
        [$unrelated] = $this->markTeacher($w);
        $this->assertThrows(fn () => $this->teacherRead($w, $unrelated), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertCount($before, $this->audits($w, 'examinations.student_marks.teacher_viewed'));
    }

    #[Test]
    public function production_refuses_teacher_marks_whatever_the_grants_and_ownership(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '10')]);

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertThrows(fn () => $this->teacherRead($w, $teacher), TeacherStudentMarksUnavailableException::class);
            $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '11', 1)]), TeacherStudentMarksUnavailableException::class);

            // An accidental grant to an administrative role changes nothing: the block is not a capability.
            $admin = $this->createUserWithCapabilities($w['school'], ['examinations.marks.teacher', 'examinations.marks.manage']);
            $this->assertThrows(fn () => $this->teacherRead($w, $admin), TeacherStudentMarksUnavailableException::class);
        }
        $this->app['env'] = 'testing';

        // The administrative path is untouched by the teacher block.
        $this->recordMarks($w, [$this->entry($student, 'present', '12', 1)]);
        $this->assertSame(['12.00', 2], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->assertSame(1, $this->inMarksSchool($w['school'], fn () => StudentMark::query()->count()));
    }

    #[Test]
    public function ending_the_assignment_ends_future_authority_but_keeps_history(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $assignment = $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '10')]);

        app(TeachingAssignmentService::class)->end($w['school'], $assignment->id, '2026-09-14', 'reassigned', $w['assigner']);

        $this->assertThrows(fn () => $this->teacherRead($w, $teacher), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '11', 1)]), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertSame(['10.00', $teacher->id], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->recorded_by_user_id]);
    }
}
