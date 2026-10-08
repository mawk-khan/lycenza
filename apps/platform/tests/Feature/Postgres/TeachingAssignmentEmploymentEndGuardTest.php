<?php

namespace Tests\Feature\Postgres;

use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * S7 (ADR 0063 §47): the two shapes an employment end adds to both teaching
 * ownership tables, proven at the raw PostgreSQL layer as the runtime role
 * under the School's RLS context -- and ONLY those, ONLY with end_reason
 * `employment_ended`:
 * - a void row: ends_on = starts_on - 1 (never earlier, never another reason);
 * - an ended row brought back earlier (never later, never another reason,
 *   never with another identity).
 */
class TeachingAssignmentEmploymentEndGuardTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

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

    /** @return array<string, array{0: string}> */
    public static function tables(): array
    {
        return ['required' => ['teaching_assignments'], 'elective' => ['elective_teaching_assignments']];
    }

    /** @return array{0: array<string, mixed>, 1: string, 2: string} the world, an open row starting 2026-10-05, an ended row (2026-06-01 .. 2026-11-30) */
    private function rows(string $table): array
    {
        $w = $this->teacherMarksWorld();
        [, $employee] = $this->markTeacher($w);
        if ($table === 'teaching_assignments') {
            $future = $this->ownSection($w, $employee, 'a1', '2026-10-05')->id;
            $ended = $this->ownSection($w, $employee, 'a2', '2026-06-01');
            app(TeachingAssignmentService::class)->end($w['school'], $ended->id, '2026-11-30', 'reassigned', $w['assigner']);
        } else {
            $future = $this->ownElective($w, $employee, null, '2026-10-05')->id;
            $second = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false, 'status' => 'active']);
            $ended = $this->ownElective($w, $employee, $second, '2026-06-01');
            app(ElectiveTeachingAssignmentService::class)->end($w['school'], $ended->id, '2026-11-30', 'reassigned', $w['assigner']);
        }
        $this->setSchool($w['school']->id); // after every fixture call: withSchool() leaves the GUC empty

        return [$w, $future, $ended->id];
    }

    private function ending(string $endsOn, string $reason, string $actorId): array
    {
        return ['ends_on' => $endsOn, 'ended_at' => now(), 'ended_by_user_id' => $actorId, 'end_reason' => $reason];
    }

    #[Test]
    #[DataProvider('tables')]
    public function a_void_row_needs_the_employment_end_reason_and_the_day_before_its_start(string $table): void
    {
        [$w, $future] = $this->rows($table);
        $row = fn () => DB::table($table)->where('id', $future);

        $this->refused(fn () => $row()->update(['ends_on' => '2026-10-04']), 'the only permitted change is ending'); // the history trigger first
        $copy = collect((array) $row()->first())->except(['id', 'retention_recorded_at'])->all();
        // An open row in the void shape, no reason: a NULL reason must not make the CHECK NULL (accepted).
        $this->refused(fn () => DB::table($table)->insert([...$copy, 'id' => (string) Str::uuid7(), 'starts_on' => '2026-11-01', 'ends_on' => '2026-10-31']), "{$table}_date_range_check");
        $this->refused(fn () => $row()->update($this->ending('2026-10-04', 'completed', $w['assigner']->id)), "{$table}_date_range_check");
        $this->refused(fn () => $row()->update($this->ending('2026-10-03', 'employment_ended', $w['assigner']->id)), "{$table}_date_range_check");

        $this->assertSame(1, $row()->update($this->ending('2026-10-04', 'employment_ended', $w['assigner']->id)));
        $this->assertSame('2026-10-05', (string) $row()->value('starts_on'), 'the start is never rewritten');
        $this->refused(fn () => $row()->update(['ends_on' => '2026-10-10', 'end_reason' => 'employment_ended']), 'an ended assignment is immutable');
    }

    #[Test]
    #[DataProvider('tables')]
    public function an_ended_row_is_brought_back_only_earlier_and_only_by_an_employment_end(string $table): void
    {
        [$w, , $ended] = $this->rows($table);
        $row = fn () => DB::table($table)->where('id', $ended);

        $this->refused(fn () => $row()->update($this->ending('2026-09-20', 'completed', $w['assigner']->id)), 'an ended assignment is immutable');
        $this->refused(fn () => $row()->update($this->ending('2026-12-15', 'employment_ended', $w['assigner']->id)), 'an ended assignment is immutable');
        $this->refused(fn () => $row()->update($this->ending('2026-11-30', 'employment_ended', $w['assigner']->id)), 'an ended assignment is immutable');
        $this->refused(fn () => $row()->update(['ends_on' => '2026-09-20', 'end_reason' => 'employment_ended', 'starts_on' => '2026-06-02']), 'the only permitted change is ending');
        $this->refused(fn () => $row()->update(['ends_on' => '2026-09-20', 'end_reason' => 'employment_ended', 'ended_at' => null, 'ended_by_user_id' => null]), 'the only permitted change is ending');

        $this->assertSame(1, $row()->update($this->ending('2026-09-20', 'employment_ended', $w['assigner']->id)));
        $this->assertSame(['2026-09-20', 'employment_ended'], [(string) $row()->value('ends_on'), $row()->value('end_reason')]);
    }

    #[Test]
    #[DataProvider('tables')]
    public function another_school_cannot_reach_the_rows(string $table): void
    {
        [$w, $future] = $this->rows($table);
        $other = $this->teacherMarksWorld();
        $this->setSchool($other['school']->id);

        $this->assertSame(0, DB::table($table)->where('id', $future)->update($this->ending('2026-10-04', 'employment_ended', $w['assigner']->id)));
        $this->setSchool($w['school']->id);
        $this->assertNull(DB::table($table)->where('id', $future)->value('ends_on'));
    }
}
