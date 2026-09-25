<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 -- four of the five MANDATORY real-concurrency proofs
 * (the fifth, opposite-direction Section transfer, lives with the
 * Students/SIS suite it actually exercises:
 * Tests\Feature\Students\OppositeDirectionTransferConcurrencyTest).
 *
 * Every race below uses GENUINELY separate OS processes via
 * Symfony\Process::start() against real PostgreSQL -- never two
 * sequential calls in one PHP process. Mirrors
 * TimetableEntryConcurrencyTest's established pattern exactly,
 * including the empty $connectionsToTransact (a test transaction would
 * hide this test's own rows from the child processes) and the
 * School-cascade cleanup in tearDown().
 */
class AttendanceConcurrencyTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school); // cascades every tenant-owned row
        }
        $this->schools = [];

        parent::tearDown();
    }

    private function world(array $periodTimes = ['start_time' => '09:00:00', 'end_time' => '10:00:00']): array
    {
        $w = $this->attendanceWorld($periodTimes);
        $this->schools[] = $w['school'];

        return $w;
    }

    private function script(string $name): string
    {
        return __DIR__.'/../../Support/'.$name;
    }

    /**
     * @param  list<Process>  $processes
     * @return list<string>
     */
    private function runConcurrently(array $processes): array
    {
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        return array_map(fn (Process $p) => $p->getOutput(), $processes);
    }

    // ---------------------------------------------------------------
    // Race 1 -- two processes submit the SAME TimetableEntry + date.
    // ---------------------------------------------------------------

    #[Test]
    public function two_real_concurrent_submissions_for_the_same_class_and_date_leave_exactly_one_register(): void
    {
        $w = $this->world();
        $a = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $b = $this->enrollStudent($w['section'], '2', '2026-06-01');

        $records = "{$a->id}:present,{$b->id}:absent";
        $script = $this->script('submit-attendance-register.php');

        $outputs = $this->runConcurrently([
            new Process(['php', $script, $w['school']->id, $w['entry']->id, self::MONDAY, $w['actor']->id, $records]),
            new Process(['php', $script, $w['school']->id, $w['entry']->id, self::MONDAY, $w['actor']->id, "{$a->id}:absent,{$b->id}:present"]),
        ]);

        $submitted = array_values(array_filter($outputs, fn ($o) => str_starts_with($o, 'submitted:')));
        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'submitted:')));

        $this->assertCount(1, $submitted, 'Exactly one concurrent submission may win. Outputs: '.json_encode($outputs));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('AlreadySubmitted', $rejected[0]);

        $sessions = $this->inSchool($w['school'], fn () => AttendanceSession::query()->get());
        $this->assertCount(1, $sessions, 'Exactly one authoritative Session row must exist.');

        // And exactly one complete set of records -- never a merged or
        // partially-written register.
        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $sessions->first()->id)->count()));
        $this->assertSame(2, $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()));
    }

    // ---------------------------------------------------------------
    // Race 2 -- two processes correct the SAME record with the SAME
    //           expected_status.
    // ---------------------------------------------------------------

    #[Test]
    public function two_real_concurrent_corrections_with_the_same_expected_status_leave_exactly_one_winner(): void
    {
        $w = $this->world();
        $enrollment = $this->enrollStudent($w['section'], '1', '2026-06-01');

        $service = app(AttendanceSubmissionService::class);
        $session = $this->inSchool($w['school'], fn () => $service->guarded(
            fn () => DB::transaction(fn () => $service->submit(
                $w['school'], $w['entry']->id, self::MONDAY,
                $this->registerPayload([$enrollment->id => 'absent']), $w['actor'],
            ))
        ));
        $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)->firstOrFail());

        $script = $this->script('correct-attendance-record.php');
        $outputs = $this->runConcurrently([
            new Process(['php', $script, $w['school']->id, $record->id, 'absent', 'present', $w['actor']->id]),
            new Process(['php', $script, $w['school']->id, $record->id, 'absent', 'excused', $w['actor']->id]),
        ]);

        $corrected = array_values(array_filter($outputs, fn ($o) => str_starts_with($o, 'corrected:')));
        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'corrected:')));

        $this->assertCount(1, $corrected, 'Exactly one CAS correction may win. Outputs: '.json_encode($outputs));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('AttendanceRecordStatusChangedException', $rejected[0]);

        $final = $this->inSchool($w['school'], fn () => $record->fresh());
        $this->assertContains($final->status, ['present', 'excused']);
        $this->assertSame('corrected:'.$final->status, $corrected[0], 'The surviving status must be the winner\'s.');
        $this->assertNotNull($final->corrected_at);
    }

    // ---------------------------------------------------------------
    // Race 3 -- Attendance submission vs a concurrent SIS membership
    //           mutation affecting the SAME Section.
    // ---------------------------------------------------------------

    #[Test]
    public function attendance_submission_and_a_concurrent_enrollment_serialize_on_the_shared_section_lock(): void
    {
        $w = $this->world();
        $existing = $this->enrollStudent($w['section'], '1', '2026-06-01');

        // A Student who is NOT yet placed. The submitting process was
        // handed a roster computed BEFORE this enrollment -- a stale
        // payload, exactly the real-world case.
        $newcomer = $this->createStudentFor($w['school']);

        $outputs = $this->runConcurrently([
            new Process(['php', $this->script('submit-attendance-register.php'),
                $w['school']->id, $w['entry']->id, self::MONDAY, $w['actor']->id, "{$existing->id}:present"]),
            new Process(['php', $this->script('enroll-student-placement.php'),
                $w['school']->id, $newcomer->id, $w['section']->id, '2', '2026-06-01', $w['actor']->id]),
        ]);

        [$submitOutput, $enrollOutput] = $outputs;

        $this->assertStringStartsWith('enrolled:', $enrollOutput, 'The SIS mutation must always succeed; Attendance never blocks it.');

        $sessions = $this->inSchool($w['school'], fn () => AttendanceSession::query()->get());

        if (str_starts_with($submitOutput, 'submitted:')) {
            // Attendance won the Section lock: its register was complete
            // as of the roster it derived, and holds exactly one record.
            $this->assertCount(1, $sessions);
            $this->assertSame(1, $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()));
        } else {
            // SIS won: the stale payload no longer matches the roster,
            // so the register is refused ENTIRELY -- never partially.
            $this->assertStringContainsString('RegisterDoesNotMatchRosterException', $submitOutput);
            $this->assertCount(0, $sessions, 'A rejected submission must leave no Session at all.');
            $this->assertSame(0, $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()));
        }

        // Either way the membership change landed exactly once.
        $this->assertSame(2, $this->inSchool($w['school'], fn () => StudentEnrollment::query()
            ->where('section_id', $w['section']->id)->where('status', 'active')->count()));
    }

    // ---------------------------------------------------------------
    // Race 5 -- Attendance submission vs a concurrent TimetableEntry
    //           mutation. (Race 4 lives in the Students suite.)
    // ---------------------------------------------------------------

    #[Test]
    public function attendance_submission_and_a_concurrent_timetable_entry_mutation_never_produce_a_torn_snapshot(): void
    {
        $w = $this->world();
        $enrollment = $this->enrollStudent($w['section'], '1', '2026-06-01');

        // A complete alternative context the entry can legitimately be
        // repointed to, all on the SAME day-of-week so the submission
        // stays weekday-valid whichever version it sees.
        $otherSubject = $this->createSubject($w['school'], ['code' => 'SCI']);
        $otherOffering = $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $otherSubject, [
            'is_required' => true, 'status' => 'active',
        ]);
        $otherSection = $this->createSection($w['year'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'B']);
        $otherTeacher = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $otherPeriod = $this->createTimetablePeriod($w['school'], [
            'start_time' => '14:00:00', 'end_time' => '15:00:00', 'code' => 'P9',
        ]);
        $otherEnrollment = $this->enrollStudent($otherSection, '1', '2026-06-01');

        $outputs = $this->runConcurrently([
            new Process(['php', $this->script('submit-attendance-register.php'),
                $w['school']->id, $w['entry']->id, self::MONDAY, $w['actor']->id, "{$enrollment->id}:present"]),
            new Process(['php', $this->script('mutate-timetable-entry.php'),
                $w['school']->id, $w['entry']->id, $otherOffering->id, $otherSection->id,
                $otherTeacher->id, $otherPeriod->id, '1', $w['actor']->id]),
        ]);

        [$submitOutput, $mutateOutput] = $outputs;

        $this->assertStringStartsWith('mutated:', $mutateOutput, 'The Timetable mutation must always succeed; Attendance never blocks it.');

        $session = $this->inSchool($w['school'], fn () => AttendanceSession::query()->first());

        if (str_starts_with($submitOutput, 'submitted:')) {
            $this->assertNotNull($session);

            // The snapshot must be internally coherent: EVERY field from
            // ONE version of the entry, never a mix of both.
            $old = [
                'section_id' => $w['section']->id,
                'subject_offering_id' => $w['offering']->id,
                'teacher_id' => $w['teacher']->id,
                'period_id' => $w['period']->id,
                'period_start_time' => '09:00:00',
            ];
            $new = [
                'section_id' => $otherSection->id,
                'subject_offering_id' => $otherOffering->id,
                'teacher_id' => $otherTeacher->id,
                'period_id' => $otherPeriod->id,
                'period_start_time' => '14:00:00',
            ];
            $actual = [
                'section_id' => $session->section_id,
                'subject_offering_id' => $session->subject_offering_id,
                'teacher_id' => $session->teacher_id,
                'period_id' => $session->period_id,
                'period_start_time' => $session->period_start_time,
            ];

            $this->assertTrue(
                $actual === $old || $actual === $new,
                'The Session snapshot must match exactly ONE coherent TimetableEntry version, never a mix. Got: '.json_encode($actual),
            );

            // And the record's structural context must agree with the
            // Session it belongs to -- the composite FKs guarantee this,
            // but assert it explicitly here too.
            $record = $this->inSchool($w['school'], fn () => AttendanceRecord::query()->firstOrFail());
            $this->assertSame($session->section_id, $record->section_id);
            $this->assertSame($session->id, $record->attendance_session_id);
        } else {
            // The submission lost and its (now-stale) payload no longer
            // matches the repointed Section's roster -- rejected whole.
            $this->assertStringContainsString('RegisterDoesNotMatchRosterException', $submitOutput);
            $this->assertNull($session);
        }

        // Never a partially-written register, in either branch.
        $this->assertSame(
            $session === null ? 0 : 1,
            $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count()),
        );
        $this->assertNotNull($otherEnrollment->id);
    }
}
