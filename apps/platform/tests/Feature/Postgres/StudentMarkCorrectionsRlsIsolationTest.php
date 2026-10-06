<?php

namespace Tests\Feature\Postgres;

use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsTenantRlsIsolation;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * RES.3 (ADR 0068 §7, §21; CLAUDE.md rule 28) at the raw PostgreSQL layer, as
 * the runtime role: forced RLS on the lock and the correction tables; no
 * runtime DELETE; no unlock; a locked paper's marks refuse every direct insert
 * and change that is not an approved correction; maker/checker, terminal
 * decisions and request immutability enforced by the database itself.
 */
class StudentMarkCorrectionsRlsIsolationTest extends TestCase
{
    use AssertsTenantRlsIsolation, CreatesStudentMarkFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * Each test runs inside DatabaseTransactions' outer transaction, so a DEFERRED constraint trigger would only
     * fire at that (never-reached) commit: SET CONSTRAINTS ALL IMMEDIATE fires the pending checks inside the op.
     */
    private function refused(callable $op, string $fragment): void
    {
        try {
            DB::connection('pgsql')->transaction(function () use ($op): void {
                $op();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail("expected a refusal containing '{$fragment}'");
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** @return array{0: array<string, mixed>, 1: StudentMark, 2: StudentMarkCorrection} a locked paper, its mark (40) and a pending request (45) */
    private function pendingWorld(): array
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);

        return [$w, $mark, $this->requestCorrection($w, $mark, 'present', '45')];
    }

    #[Test]
    public function both_tables_are_tenant_isolated_at_the_raw_sql_layer(): void
    {
        [$w] = $this->pendingWorld();
        $other = $this->createSchool();

        $this->assertTenantRlsIsolation([
            'examination_paper_mark_states' => ExaminationPaperMarkState::class,
            'student_mark_corrections' => StudentMarkCorrection::class,
        ], $w['school']->id, $other->id);
    }

    #[Test]
    public function the_runtime_role_cannot_delete_or_unlock(): void
    {
        [$w, , $correction] = $this->pendingWorld();
        $this->setSchool($w['school']->id); // after every service call: withSchool() leaves the GUC empty

        $this->refused(fn () => DB::table('examination_paper_mark_states')->where('examination_paper_id', $w['paper']->id)->delete(), 'permission denied');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->delete(), 'permission denied');
        $this->refused(fn () => DB::table('examination_paper_mark_states')->where('examination_paper_id', $w['paper']->id)
            ->update(['state' => 'open', 'locked_by_user_id' => null, 'locked_at' => null]), 'no unlock');
        $this->refused(fn () => DB::table('examination_paper_mark_states')->where('examination_paper_id', $w['paper']->id)
            ->update(['locked_at' => now()->addDay()]), 'no unlock');
        $this->refused(fn () => DB::table('examination_paper_mark_states')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'examination_paper_id' => $w['paper']->id, 'state' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]), 'examination_paper_mark_states_one_per_paper');
    }

    #[Test]
    public function a_locked_paper_refuses_direct_mark_writes_that_are_not_an_approved_correction(): void
    {
        [$w, $mark, $correction] = $this->pendingWorld();
        $late = $this->markStudent($w);
        $checker = $this->checker($w);
        $this->setSchool($w['school']->id);

        $this->refused(fn () => DB::table('student_marks')->insert(collect((array) DB::table('student_marks')->where('id', $mark->id)->first())
            ->except('retention_recorded_at')->merge(['id' => (string) Str::uuid7(), 'student_id' => $late->id, 'version' => 1])->all()), 'marks are locked');
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['value' => '41.00', 'version' => 2]), 'only through a pending correction');
        // Matching the pending request is still not enough without approving it in the same transaction (deferred check).
        $this->refused(fn () => DB::table('student_marks')->where('id', $mark->id)->update(['value' => '45.00', 'version' => 2]), 'not approved in the same transaction');
        // An approval that does not apply the change is refused.
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)
            ->update(['status' => 'approved', 'decided_by_user_id' => $checker->id, 'decided_at' => now(), 'decision_processing_authorization_id' => $mark->processing_authorization_id]),
            'must apply exactly this correction');
        $this->assertSame(['40.00', 1], [(string) DB::table('student_marks')->where('id', $mark->id)->value('value'), (int) DB::table('student_marks')->where('id', $mark->id)->value('version')]);
        $this->assertSame('pending', DB::table('student_mark_corrections')->where('id', $correction->id)->value('status'));
    }

    #[Test]
    public function maker_checker_terminal_decisions_and_request_immutability_are_database_enforced(): void
    {
        [$w, $mark, $correction] = $this->pendingWorld();
        $checker = $this->checker($w);
        $this->setSchool($w['school']->id);

        // The requester never decides, even by a raw write that applies the change correctly.
        $this->refused(function () use ($w, $mark, $correction): void {
            DB::table('student_marks')->where('id', $mark->id)->update(['value' => '45.00', 'version' => 2]);
            DB::table('student_mark_corrections')->where('id', $correction->id)->update(['status' => 'rejected', 'decided_by_user_id' => $w['admin']->id, 'decided_at' => now()]);
        }, 'student_mark_corrections_maker_checker_check');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)
            ->update(['status' => 'rejected', 'decided_by_user_id' => $w['admin']->id, 'decided_at' => now()]), 'student_mark_corrections_maker_checker_check');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->update(['proposed_value' => '46.00']), 'fields never change');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->update(['status' => 'approved']), 'must apply exactly this correction');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->update(['status' => 'rejected']), 'student_mark_corrections_decision_shape_check');
        $this->refused(fn () => DB::table('student_mark_corrections')->insert(collect((array) DB::table('student_mark_corrections')->where('id', $correction->id)->first())
            ->except('retention_recorded_at')->merge(['id' => (string) Str::uuid7(), 'requested_by_user_id' => $checker->id])->all()), 'student_mark_corrections_one_pending_per_mark');

        // Rejected by someone else: terminal.
        DB::connection('pgsql')->transaction(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)
            ->update(['status' => 'rejected', 'decided_by_user_id' => $checker->id, 'decided_at' => now()]));
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->update(['status' => 'pending', 'decided_by_user_id' => null, 'decided_at' => null]), 'never changes');
        $this->refused(fn () => DB::table('student_mark_corrections')->where('id', $correction->id)->update(['decided_at' => now()->addDay()]), 'never changes');

        // A request must start pending and match its mark's current version.
        $base = collect((array) DB::table('student_mark_corrections')->where('id', $correction->id)->first())->except('retention_recorded_at');
        $fresh = $base->merge(['id' => (string) Str::uuid7(), 'status' => 'pending', 'decided_by_user_id' => null, 'decided_at' => null]);
        $this->refused(fn () => DB::table('student_mark_corrections')->insert($fresh->merge(['status' => 'rejected', 'decided_by_user_id' => $checker->id, 'decided_at' => now()])->all()), 'starts pending');
        $this->refused(fn () => DB::table('student_mark_corrections')->insert($fresh->merge(['base_version' => 7])->all()), 'current version');
        $this->refused(fn () => DB::table('student_mark_corrections')->insert($fresh->merge(['proposed_value' => '80.01'])->all()), 'maximum marks');
        $this->refused(fn () => DB::table('student_mark_corrections')->insert($fresh->merge(['reason_code' => 'other'])->all()), 'student_mark_corrections_reason_check');
    }

    #[Test]
    public function an_open_paper_takes_no_correction_request(): void
    {
        $w = $this->marksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $mark = $this->markOf($w, $student);
        $this->setSchool($w['school']->id);

        $this->refused(fn () => DB::table('student_mark_corrections')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_mark_id' => $mark->id, 'examination_paper_id' => $w['paper']->id,
            'student_id' => $student->id, 'base_version' => 1, 'previous_status' => 'present', 'previous_value' => '40.00', 'proposed_status' => 'absent',
            'reason_code' => 'entry_error', 'requested_by_user_id' => $w['admin']->id, 'requested_at' => now(),
            'request_processing_authorization_id' => $mark->processing_authorization_id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'only for a locked paper');
    }
}
