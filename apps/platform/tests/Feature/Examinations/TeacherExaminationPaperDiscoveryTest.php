<?php

namespace Tests\Feature\Examinations;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarksUnavailableException;
use App\Domain\Examinations\Application\Marks\TeacherExaminationPaperDiscoveryService;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.4A (ADR 0068 §26): the teacher's "My examination papers" discovery
 * list -- only papers whose Offering the teacher owns on the paper's
 * `scheduled_on` (required: a TeachingAssignment of some Section; elective:
 * the TCH-E assignment), active, in a year that is not closed; locked papers
 * listed read-only. No Student, mark, basis or correction data; the same
 * block, capability, ActingEmployee and `mfa` as the RES.4 marks routes; every
 * listed paper opens on the marks surface, and nothing else does.
 */
class TeacherExaminationPaperDiscoveryTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    private function papers(array $w, User $teacher): array
    {
        return app(TeacherExaminationPaperDiscoveryService::class)->papers($w['school'], $teacher);
    }

    /** @return list<string> */
    private function paperIds(array $w, User $teacher): array
    {
        return array_column($this->papers($w, $teacher), 'id');
    }

    private function as(User $user, School $school, bool $mfa = true): static
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $mfa ? session(['mfa_verified_at' => now()->toIso8601String()]) : session()->forget('mfa_verified_at');

        return $this;
    }

    /** A second required Offering (another Subject) in the same grade, with a paper on 2026-09-17. @return array{0: \App\Domain\AcademicStructure\Infrastructure\SubjectOffering, 1: ExaminationPaper} */
    private function otherRequired(array $w): array
    {
        $offering = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => true, 'status' => 'active']);

        return [$offering, $this->createExaminationPaper($w['examination'], $offering, ['scheduled_on' => '2026-09-17', 'max_marks' => '40.00'])];
    }

    #[Test]
    public function a_required_subject_teacher_discovers_only_papers_of_offerings_they_own_on_the_date(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        [$otherOffering, $otherPaper] = $this->otherRequired($w);

        $rows = $this->papers($w, $teacher);
        $subject = $this->inMarksSchool($w['school'], fn () => $w['required']->subject()->firstOrFail());
        $this->assertSame([$w['paper']->id], array_column($rows, 'id'), 'not the elective paper, not another Offering\'s paper');
        $this->assertSame([
            'id' => $w['paper']->id,
            'examination' => ['id' => $w['examination']->id, 'name' => $w['examination']->name],
            'subjectOfferingId' => $w['required']->id,
            'subject' => ['name' => $subject->name, 'code' => $subject->code],
            'gradeLevelName' => 'Grade 5',
            'scheduledOn' => '2026-09-15',
            'maxMarks' => '80.00',
            'marksState' => 'open',
            'entryAvailable' => true,
            'marksUrl' => "/app/my-examination-papers/{$w['paper']->id}/marks",
        ], $rows[0], 'ids, names, date, maximum and marks state only -- no Student, roster, count or mark');

        // Owning a2 instead also reaches the Offering-wide paper (any owned Section); another Offering stays hidden.
        [$a2Teacher, $a2Employee] = $this->markTeacher($w);
        $this->ownSection($w, $a2Employee, 'a2');
        $this->assertSame([$w['paper']->id], $this->paperIds($w, $a2Teacher));

        app(TeachingAssignmentService::class)->create($w['school'], $employee->id, $w['a1']->id, $otherOffering->id, '2026-06-01', null, $w['assigner']);
        $this->assertSame([$otherPaper->id, $w['paper']->id], $this->paperIds($w, $teacher), 'newest first');
    }

    #[Test]
    public function an_elective_teacher_discovers_the_owned_elective_paper_only(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownElective($w, $employee);
        $this->assertSame([$w['electivePaper']->id], $this->paperIds($w, $teacher));

        // Another elective's teacher sees nothing here; an elected Student never makes a paper discoverable.
        [$other, $otherEmployee] = $this->markTeacher($w);
        $this->ownElective($w, $otherEmployee, $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']));
        $this->elect($w, $this->markStudent($w, 'a1'));
        $this->assertSame([], $this->paperIds($w, $other));
    }

    #[Test]
    public function ownership_is_judged_on_each_papers_date(): void
    {
        $w = $this->teacherMarksWorld();
        $cases = [
            'co-teacher' => [['2026-06-01', null], true],
            'short cover over the date' => [['2026-09-14', '2026-09-16'], true],
            'ends on the date (inclusive)' => [['2026-06-01', '2026-09-15'], true],
            'ended the day before' => [['2026-06-01', '2026-09-14'], false],
            'starts the day after' => [['2026-09-16', null], false],
        ];
        [$first, $firstEmployee] = $this->markTeacher($w);
        $this->ownSection($w, $firstEmployee, 'a1');
        foreach ($cases as $label => [[$from, $to], $listed]) {
            [$teacher, $employee] = $this->markTeacher($w);
            $this->ownSection($w, $employee, 'a1', $from, $to);
            $this->assertSame($listed ? [$w['paper']->id] : [], $this->paperIds($w, $teacher), $label);
        }
        $this->assertSame([$w['paper']->id], $this->paperIds($w, $first));
    }

    #[Test]
    public function locked_papers_are_listed_read_only_and_inactive_papers_and_closed_years_are_not(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);

        $this->lockMarks($w);
        $rows = collect($this->papers($w, $teacher))->keyBy('id');
        $this->assertSame(['locked', false], [$rows[$w['paper']->id]['marksState'], $rows[$w['paper']->id]['entryAvailable']]);
        $this->assertSame(['open', true], [$rows[$w['electivePaper']->id]['marksState'], $rows[$w['electivePaper']->id]['entryAvailable']]);

        $this->inMarksSchool($w['school'], fn () => ExaminationPaper::query()->whereKey($w['electivePaper']->id)->update(['status' => 'inactive']));
        $this->assertSame([$w['paper']->id], $this->paperIds($w, $teacher), 'an inactive paper is not offered');

        app(AcademicYearService::class)->close($w['year'], $w['admin']);
        $this->assertSame([], $this->paperIds($w, $teacher), 'a closed year is not offered (the RES.4 marks read refuses it too)');
    }

    #[Test]
    public function ending_an_assignment_removes_the_paper_and_keeps_the_marks(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $assignment = $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '33')]);

        app(TeachingAssignmentService::class)->end($w['school'], $assignment->id, '2026-09-10', 'reassigned', $w['assigner']);

        $this->assertSame([], $this->paperIds($w, $teacher));
        $this->assertSame(['33.00', $teacher->id], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->recorded_by_user_id]);
    }

    #[Test]
    public function the_identity_capability_and_block_are_required_and_other_schools_never_appear(): void
    {
        $w = $this->teacherMarksWorld();
        [$unlinked, $unlinkedEmployee] = $this->markTeacher($w, linked: false);
        $this->ownSection($w, $unlinkedEmployee, 'a1');
        $this->assertThrows(fn () => $this->papers($w, $unlinked), ActingEmployeeUnavailableException::class);
        $this->assertThrows(fn () => $this->papers($w, $w['admin']), AuthorizationException::class); // administrative marks keys do not imply discovery

        [$unrelated] = $this->markTeacher($w);
        $this->assertSame([], $this->papers($w, $unrelated), 'an eligible teacher who owns nothing gets an empty list');

        // A teacher of another School owning there sees only that School's papers.
        $other = $this->teacherMarksWorld();
        [$foreign, $foreignEmployee] = $this->markTeacher($other);
        $this->ownSection($other, $foreignEmployee, 'a1');
        $this->assertSame([$other['paper']->id], $this->paperIds($other, $foreign));
        $this->assertThrows(fn () => $this->papers($w, $foreign), AuthorizationException::class); // no membership, so no capability, in School A

        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertThrows(fn () => $this->papers($other, $foreign), TeacherStudentMarksUnavailableException::class);
        }
        $this->app['env'] = 'testing';
    }

    #[Test]
    public function listing_is_audited_with_ids_and_a_count_and_refusals_record_nothing(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);
        $audits = fn () => $this->inMarksSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'examinations.examination_papers.teacher_listed')->get()->all());

        $this->papers($w, $teacher);
        [$listed] = $audits();
        $this->assertSame($teacher->id, $listed->actor_user_id);
        $this->assertEquals(['employeeId' => $employee->id, 'paperCount' => 2], $listed->metadata);

        [$unlinked] = $this->markTeacher($w, linked: false);
        $this->assertThrows(fn () => $this->papers($w, $unlinked), ActingEmployeeUnavailableException::class);
        $this->assertCount(1, $audits());
    }

    #[Test]
    public function the_query_count_does_not_grow_with_papers_or_assignments(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $count = function () use ($w, $teacher): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->papers($w, $teacher);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->papers($w, $teacher); // warm the capability cache: only the discovery's own queries are compared
        $one = $count();

        $this->ownSection($w, $employee, 'a2');
        $this->ownElective($w, $employee);
        foreach (range(1, 3) as $i) {
            [$offering] = $this->otherRequired($w);
            app(TeachingAssignmentService::class)->create($w['school'], $employee->id, $w['a1']->id, $offering->id, '2026-06-01', null, $w['assigner']);
        }
        $this->assertCount(5, $this->papers($w, $teacher));
        $this->assertSame($one, $count(), 'no N+1 across papers, Sections or assignments');
    }

    #[Test]
    public function over_http_it_is_session_json_behind_mfa_and_every_listed_paper_opens(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $this->ownElective($w, $employee);
        $student = $this->markStudent($w, 'a1');
        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '71.25')]);
        $other = $this->teacherMarksWorld();

        $this->getJson('/app/my-examination-papers')->assertUnauthorized();

        $response = $this->as($teacher, $w['school'])->getJson('/app/my-examination-papers')->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('71.25', (string) $response->getContent(), 'no mark value');
        foreach (['fullName', 'rollNumber', 'studentId', 'unavailableCount', 'rows', 'pendingCorrection'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", (string) $response->getContent());
        }
        foreach ($response->json('data') as $row) {
            $this->getJson($row['marksUrl'])->assertOk()->assertJsonPath('data.paper.id', $row['id']);
        }
        $this->getJson("/app/my-examination-papers/{$other['paper']->id}/marks")->assertNotFound();

        $this->as($teacher, $w['school'], mfa: false)->getJson('/app/my-examination-papers')->assertUnauthorized()->assertJsonPath('error.code', 'mfa_step_up_required');
        [$noFactor, $noFactorEmployee] = $this->markTeacher($w, mfa: false);
        $this->ownSection($w, $noFactorEmployee, 'a1');
        $this->as($noFactor, $w['school'])->getJson('/app/my-examination-papers')->assertForbidden();
        [$roleless, $rolelessEmployee] = $this->markTeacher($w, roleKey: null);
        $this->ownSection($w, $rolelessEmployee, 'a1');
        $this->as($roleless, $w['school'])->getJson('/app/my-examination-papers')->assertForbidden();
        [$unlinked, $unlinkedEmployee] = $this->markTeacher($w, linked: false);
        $this->ownSection($w, $unlinkedEmployee, 'a1');
        $this->as($unlinked, $w['school'])->getJson('/app/my-examination-papers')->assertForbidden()->assertJsonPath('error.code', 'HR_ACTING_EMPLOYEE_UNAVAILABLE');

        $this->withoutMiddleware(PreventRequestForgery::class);
        $admin = $this->createUserWithCapabilities($w['school'], ['examinations.marks.teacher', 'examinations.marks.manage']);
        $this->enrollActiveMfaFactor($admin);
        foreach (['production', 'staging'] as $environment) {
            foreach ([$teacher, $admin] as $user) {
                $this->as($user, $w['school']);
                $this->app['env'] = $environment;
                $this->getJson('/app/my-examination-papers')->assertForbidden()->assertJsonPath('error.code', 'TEACHER_STUDENT_MARKS_UNAVAILABLE');
                $this->app['env'] = 'testing';
            }
        }

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $this->assertDoesNotMatchRegularExpression('#^api/.*(my-examination-papers|examination-papers/[^/]+/marks)#', $route->uri(), 'no bearer-token discovery or marks route');
        }
    }
}
