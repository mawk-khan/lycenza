<?php

namespace Tests\Feature\Postgres;

use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkPaperNotFoundException;
use App\Domain\Examinations\Application\Exceptions\TeacherStudentMarkStudentNotFoundException;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.4 (ADR 0068 §25.8; CLAUDE.md rule 28): the teacher path adds no way
 * around School isolation. One human with an identity in two Schools
 * qualifies in each independently (no global teacher authority); another
 * School's paper and Students are the ordinary 404; and at the raw
 * PostgreSQL layer, as the runtime role, a teacher-written mark is invisible
 * and unwritable under another School's context (forced RLS, unchanged).
 */
class TeacherStudentMarkTenancyTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function a_multi_school_identity_qualifies_in_each_school_independently(): void
    {
        $a = $this->teacherMarksWorld();
        $b = $this->teacherMarksWorld();
        [$teacher, $employeeA] = $this->markTeacher($a);
        [, $employeeB] = $this->markTeacher($b, user: $teacher);
        $this->ownSection($a, $employeeA, 'a1');
        $studentA = $this->markStudent($a, 'a1');
        $studentB = $this->markStudent($b, 'a1');

        $this->teacherRecord($a, $teacher, [$this->entry($studentA, 'present', '30')]);

        // In School B the same human owns nothing: B's paper is not theirs, A's paper does not exist there.
        $this->assertThrows(fn () => $this->teacherRead($b, $teacher), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRead($b, $teacher, $a['paper']), TeacherStudentMarkPaperNotFoundException::class);
        $this->assertThrows(fn () => $this->teacherRecord($b, $teacher, [$this->entry($studentA, 'present', '1', 1)], $a['paper']), TeacherStudentMarkPaperNotFoundException::class);

        // Owning B's Section later gives B's Students only -- never A's Student id inside B's paper.
        $this->ownSection($b, $employeeB, 'a1');
        $this->assertSame([$studentB->id], $this->rowIds($this->teacherRead($b, $teacher)));
        $this->assertThrows(fn () => $this->teacherRecord($b, $teacher, [$this->entry($studentA, 'present', '1')]), TeacherStudentMarkStudentNotFoundException::class);
        $this->assertSame(['30.00', 1], [(string) $this->markOf($a, $studentA)->value, $this->markOf($a, $studentA)->version]);
    }

    #[Test]
    public function a_teacher_written_mark_is_invisible_and_unwritable_under_another_schools_context(): void
    {
        $a = $this->teacherMarksWorld();
        $b = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($a);
        $this->ownSection($a, $employee, 'a1');
        $student = $this->markStudent($a, 'a1');
        [$written] = $this->teacherRecord($a, $teacher, [$this->entry($student, 'present', '30')]);

        $this->setSchool($b['school']->id); // after every service call: withSchool() leaves the GUC empty
        $this->assertSame(0, DB::connection('pgsql')->table('student_marks')->where('id', $written['studentMarkId'])->count());
        $this->assertSame(0, DB::connection('pgsql')->table('student_mark_revisions')->where('student_mark_id', $written['studentMarkId'])->count());
        $this->assertSame(0, DB::connection('pgsql')->table('student_marks')->where('id', $written['studentMarkId'])->update(['version' => 9]));
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_marks')->where('id', $written['studentMarkId'])->delete());
            $this->fail('the runtime role never deletes a mark');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }

        $this->setSchool($a['school']->id);
        $this->assertSame(1, DB::connection('pgsql')->table('student_marks')->where('id', $written['studentMarkId'])->where('version', 1)->count());
    }
}
