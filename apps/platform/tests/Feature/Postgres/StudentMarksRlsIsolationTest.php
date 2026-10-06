<?php

namespace Tests\Feature\Postgres;

use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkRevision;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsTenantRlsIsolation;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.2 (ADR 0068 §6.1, §19.3 a; CLAUDE.md rule 28) at the raw PostgreSQL
 * layer, as the runtime role: forced RLS isolation; no runtime DELETE of a
 * mark or a revision, no runtime UPDATE of a revision; history written only by
 * a mark write and never skipped (even by a raw UPDATE); the value, version
 * and identity rules enforced by the database itself.
 */
class StudentMarksRlsIsolationTest extends TestCase
{
    use AssertsTenantRlsIsolation, CreatesStudentMarkFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function refused(callable $op, string $fragment): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail("expected a refusal containing '{$fragment}'");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** @return array{0: array<string, mixed>, 1: StudentMark} */
    private function markedWorld(): array
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);

        return [$w, $this->markOf($w, $student)];
    }

    #[Test]
    public function both_tables_are_tenant_isolated_at_the_raw_sql_layer(): void
    {
        [$w] = $this->markedWorld();
        $other = $this->createSchool();

        $this->assertTenantRlsIsolation([
            'student_marks' => StudentMark::class,
            'student_mark_revisions' => StudentMarkRevision::class,
        ], $w['school']->id, $other->id);
    }

    #[Test]
    public function the_runtime_role_cannot_delete_marks_or_rewrite_or_forge_history(): void
    {
        [$w, $mark] = $this->markedWorld();
        $this->setSchool($w['school']->id);

        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->delete(), 'permission denied');
        $this->refused(fn () => DB::table('student_mark_revisions')->where('student_mark_id', $mark->id)->delete(), 'permission denied');
        $this->refused(fn () => DB::table('student_mark_revisions')->where('student_mark_id', $mark->id)->update(['new_value' => '1.00']), 'permission denied');
        $this->refused(fn () => DB::table('student_mark_revisions')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_mark_id' => $mark->id, 'revision' => 9,
            'new_status' => 'absent', 'student_enrollment_id' => $mark->student_enrollment_id, 'eligibility_source' => 'required',
            'processing_authorization_id' => $mark->processing_authorization_id, 'recorded_by_user_id' => $w['admin']->id, 'recorded_at' => now(),
        ]), 'history is written only by a mark write');
    }

    #[Test]
    public function a_raw_update_still_writes_history_and_the_database_enforces_value_version_and_identity(): void
    {
        [$w, $mark] = $this->markedWorld();
        $otherStudent = $this->markStudent($w);
        $this->setSchool($w['school']->id); // after every service call: withSchool() leaves the GUC empty

        // Even a raw write (bypassing the service) cannot skip the value history.
        DB::connection('pgsql')->transaction(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'absent', 'value' => null, 'version' => 2]));
        $history = DB::table('student_mark_revisions')->where('student_mark_id', $mark->id)->orderBy('revision')->get(['revision', 'previous_status', 'previous_value', 'new_status', 'new_value']);
        $this->assertSame([[1, null, null, 'present', '40.00'], [2, 'present', '40.00', 'absent', null]], $history->map(fn ($r) => [(int) $r->revision, $r->previous_status, $r->previous_value, $r->new_status, $r->new_value])->all());

        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'present', 'value' => '80.50', 'version' => 3]), "exceeds the paper's maximum marks");
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'present', 'value' => '-1.00', 'version' => 3]), 'student_marks_value_shape_check');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'exempt', 'value' => '5.00', 'version' => 3]), 'student_marks_value_shape_check');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'present', 'value' => null, 'version' => 3]), 'student_marks_value_shape_check');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['status' => 'absent', 'version' => 7]), 'advances the version by exactly one');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['student_id' => $otherStudent->id, 'version' => 3]), 'never change');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['eligibility_source' => 'elective', 'version' => 3]), 'student_marks_source_shape_check');
        $this->refused(fn () => DB::table('student_marks')->insert(collect((array) DB::table('student_marks')->where('id', $mark->id)->first())
            ->except('retention_recorded_at')->merge(['id' => (string) Str::uuid7(), 'version' => 1])->all()), 'student_marks_one_per_student_paper');
    }
}
