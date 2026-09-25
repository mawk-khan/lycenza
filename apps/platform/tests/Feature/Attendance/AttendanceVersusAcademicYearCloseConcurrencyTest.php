<?php

namespace Tests\Feature\Attendance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Phase 0H.2 final-integration gate: the PERMANENT regression proof for
 * the AcademicYear lock mode.
 *
 * AttendanceSubmissionService originally took `lockForUpdate()` on the
 * AcademicYear, which produced a real, reproducible deadlock (SQLSTATE
 * 40P01) against a concurrent StudentEnrollmentService::enroll(): every
 * INSERT into `student_enrollments` takes an implicit FOR KEY SHARE
 * lock on its referenced `academic_years` row, so Attendance held that
 * row exclusively while waiting for the Section, while enroll() held
 * the Section and waited for the AcademicYear key-share. The fix was to
 * downgrade to `sharedLock()` (FOR SHARE), which is compatible with the
 * FK's FOR KEY SHARE while still conflicting with the FOR NO KEY UPDATE
 * that AcademicYearService::close()'s status UPDATE takes.
 *
 * That second half is the invariant THIS test pins permanently: the
 * "no new register once the year is closed" rule is only sound while
 * Attendance's shared lock genuinely still blocks a concurrent close.
 * A future well-meaning change to a weaker lock (or to no lock at all)
 * would silently break it, and only a real two-process race can catch
 * that -- a sequential test would never observe the interleaving.
 *
 * Genuinely separate OS processes against real PostgreSQL, same pattern
 * as AttendanceConcurrencyTest.
 */
class AttendanceVersusAcademicYearCloseConcurrencyTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        foreach ($this->schools as $school) {
            $this->deleteSchoolAsAdmin($school);
        }
        $this->schools = [];

        parent::tearDown();
    }

    #[Test]
    public function attendance_submission_and_a_concurrent_academic_year_close_serialize_coherently(): void
    {
        // Repeated rounds: an interleaving-dependent defect can hide
        // behind a single lucky pass, and each round is cheap.
        for ($round = 0; $round < 4; $round++) {
            $w = $this->attendanceWorld();
            $this->schools[] = $w['school'];

            $enrollment = $this->enrollStudent($w['section'], '1', '2026-06-01');

            $submit = new Process(['php', __DIR__.'/../../Support/submit-attendance-register.php',
                $w['school']->id, $w['entry']->id, self::MONDAY, $w['actor']->id, "{$enrollment->id}:present"]);
            $close = new Process(['php', __DIR__.'/../../Support/close-academic-year.php',
                $w['school']->id, $w['year']->id, $w['actor']->id]);

            $submit->start();
            $close->start();
            $submit->wait();
            $close->wait();

            $submitOutput = $submit->getOutput();
            $closeOutput = $close->getOutput();
            $context = "Round {$round}. submit={$submitOutput} close={$closeOutput}";

            // Nothing may deadlock, in either direction.
            foreach ([$submitOutput, $closeOutput] as $output) {
                $this->assertStringNotContainsString('Deadlock', $output, $context);
                $this->assertStringNotContainsString('40P01', $output, $context);
            }

            $year = $this->inSchool($w['school'], fn () => AcademicYear::query()->findOrFail($w['year']->id));
            $sessions = $this->inSchool($w['school'], fn () => AttendanceSession::query()->count());
            $records = $this->inSchool($w['school'], fn () => AttendanceRecord::query()->count());

            if (str_starts_with($submitOutput, 'submitted:')) {
                // BRANCH 1 -- Attendance won the shared lock first. It
                // legitimately submitted under a then-active year, and
                // the close ran afterwards. Both succeed; the register
                // is complete.
                $this->assertStringStartsWith('closed:', $closeOutput,
                    "Close must still succeed after Attendance commits. {$context}");
                $this->assertSame('closed', $year->status, $context);
                $this->assertSame(1, $sessions, $context);
                $this->assertSame(1, $records, "A submitted register must be complete, never partial. {$context}");
            } else {
                // BRANCH 2 -- close won. Attendance MUST have observed
                // the closed year and refused; it may never create a
                // Session after seeing a non-active year.
                $this->assertStringContainsString('AcademicYearNotActiveException', $submitOutput,
                    "A losing submission must fail with the typed closed-year error, not something else. {$context}");
                $this->assertStringStartsWith('closed:', $closeOutput, $context);
                $this->assertSame('closed', $year->status, $context);
                $this->assertSame(0, $sessions,
                    "No Session may exist once Attendance observed a closed year. {$context}");
                $this->assertSame(0, $records,
                    "No orphan records may survive a refused submission. {$context}");
            }
        }
    }
}
