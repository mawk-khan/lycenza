<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.2 static scope/architecture guards. Mirrors
 * Tests\Feature\Canteen\CanteenArchitectureGuardTest's established
 * shape: cheap greps that fail loudly the moment a future change
 * silently breaks an invariant this checkpoint deliberately relies on.
 */
class AttendanceArchitectureGuardTest extends TestCase
{
    private function appPath(string $relative = ''): string
    {
        return base_path('app'.($relative === '' ? '' : '/'.$relative));
    }

    /**
     * @return list<string>
     */
    private function grep(string $pattern, string $path): array
    {
        $output = [];
        exec('grep -rn --include=*.php '.escapeshellarg($pattern).' '.escapeshellarg($path).' 2>/dev/null', $output);

        return $output;
    }

    #[Test]
    public function attendance_sessions_are_only_ever_created_by_the_submission_service(): void
    {
        // The historical wall-clock overlap invariant is enforced by
        // AttendanceSubmissionService under the Section row lock, NOT by
        // a database constraint. A second writer would silently bypass
        // it, so there must not be one.
        $hits = array_filter(
            $this->grep('AttendanceSession::query()->create', $this->appPath()),
            fn (string $line) => ! str_contains($line, 'AttendanceSubmissionService.php'),
        );

        $this->assertSame([], array_values($hits),
            'AttendanceSession may only be created by AttendanceSubmissionService: '.implode("\n", $hits));

        foreach (['AttendanceSession::create(', "table('attendance_sessions')", 'insert into attendance_sessions'] as $forbidden) {
            $this->assertSame([], $this->grep($forbidden, $this->appPath()),
                "No application code may write attendance_sessions directly ({$forbidden}).");
        }
    }

    #[Test]
    public function attendance_records_are_only_ever_written_by_the_two_attendance_services(): void
    {
        $allowed = ['AttendanceSubmissionService.php', 'AttendanceCorrectionService.php'];

        foreach (['AttendanceRecord::query()->insert', 'AttendanceRecord::query()->create', 'AttendanceRecord::create('] as $pattern) {
            $hits = array_filter(
                $this->grep($pattern, $this->appPath()),
                fn (string $line) => ! array_filter($allowed, fn ($f) => str_contains($line, $f)),
            );
            $this->assertSame([], array_values($hits),
                "AttendanceRecord writes are restricted to the Attendance services ({$pattern}): ".implode("\n", $hits));
        }

        $this->assertSame([], $this->grep("table('attendance_records')", $this->appPath()),
            'No application code may write attendance_records through the query builder.');
    }

    #[Test]
    public function no_parent_module_depends_on_attendance(): void
    {
        // Attendance depends OUTWARD on Timetable, Students/SIS,
        // Academic Structure and HR. None of them may query Attendance
        // -- that reverse edge is exactly what the immutable Session
        // snapshot exists to avoid (CLAUDE.md rule 4).
        foreach (['Timetable', 'Students', 'AcademicStructure', 'HR'] as $module) {
            // Real code dependencies only: an `use App\Domain\Attendance\...`
            // import, or a static/class reference to an Attendance
            // class. A DOCBLOCK that explains why a parent must not
            // depend on Attendance (StudentEnrollmentService's own
            // lock-order docblock names AttendanceSubmissionService as
            // the reason its lock order changed) is documentation, not
            // coupling, and must not fail this guard.
            foreach (['use App\\Domain\\Attendance', 'Attendance\\Application', 'Attendance\\Infrastructure'] as $pattern) {
                $hits = array_filter(
                    $this->grep($pattern, $this->appPath("Domain/{$module}")),
                    fn (string $line) => ! preg_match('#:\s*(\*|//)#', $line),
                );

                $this->assertSame([], array_values($hits),
                    "app/Domain/{$module} must not depend on Attendance ({$pattern}): ".implode("\n", $hits));
            }
        }
    }

    #[Test]
    public function attendance_stores_no_reason_note_or_health_field(): void
    {
        // `excused` records the generic status and never why. A reason,
        // note, medical detail or evidence reference would pull
        // Health-tier data into a Sensitive-tier table.
        $forbidden = ['reason', 'note', 'remark', 'medical', 'health', 'diagnosis', 'certificate', 'minutes_late', 'biometric'];

        $migrations = glob(base_path('database/migrations/*attendance*.php'));
        $this->assertNotEmpty($migrations);

        foreach ($migrations as $migration) {
            $source = file_get_contents($migration);
            // Strip docblock/inline comments: the explanatory prose
            // legitimately explains WHY these fields are absent.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    "'{$word}'", $code,
                    "Attendance migrations must not define a {$word} column (".basename($migration).').',
                );
                $this->assertStringNotContainsStringIgnoringCase(
                    "\$table->string('{$word}", $code,
                    "Attendance migrations must not define a {$word} column (".basename($migration).').',
                );
            }
        }
    }

    #[Test]
    public function attendance_records_carry_no_student_id_column(): void
    {
        // Student identity is derived THROUGH the StudentEnrollment --
        // a duplicate student_id would be a second, driftable answer to
        // "whose attendance is this".
        $migration = file_get_contents(
            base_path('database/migrations/2026_09_15_090200_create_attendance_records_table.php'),
        );
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $migration);

        $this->assertStringNotContainsString("uuid('student_id')", $code);
    }

    #[Test]
    public function the_status_vocabulary_is_exactly_the_four_agreed_values(): void
    {
        $this->assertSame(
            ['present', 'absent', 'late', 'excused'],
            AttendanceRecord::STATUSES,
        );
    }
}
