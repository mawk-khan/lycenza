<?php

namespace Tests\Feature\Attendance;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.4 (ADR 0063 sections 8, 11) static guards for the second owned
 * adopter: the dependency direction (Attendance -> HR ActingEmployee and
 * TeachingAssignments ownership, never the reverse), identity and
 * ownership only through the published primitives, and no authority from
 * the timetable's or the session's teacher_id.
 */
class TeacherAttendanceArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function grep(string $pattern, string $path): array
    {
        $output = [];
        exec('grep -rnH -F --include=*.php '.escapeshellarg($pattern).' '.escapeshellarg(base_path($path)).' 2>/dev/null', $output);

        return array_values(array_filter($output, fn (string $line) => ! preg_match('#^[^:]+:\d+:\s*(\*|//)#', $line)));
    }

    /** @return list<string> */
    private function tierTwoFiles(): array
    {
        return [
            'app/Domain/Attendance/Application/TeacherAttendanceAccess.php',
            'app/Domain/Attendance/Application/TeacherAttendanceGuard.php',
            'app/Domain/Attendance/Application/TeacherAttendanceScope.php',
        ];
    }

    #[Test]
    public function hr_teaching_assignments_and_capability_resolution_never_depend_on_attendance(): void
    {
        foreach (['app/Domain/HR', 'app/Domain/TeachingAssignments', 'app/Support/Authorization'] as $path) {
            $this->assertSame([], $this->grep('App\\Domain\\Attendance', $path), "{$path} must not depend on Attendance.");
        }
    }

    #[Test]
    public function the_owned_decision_uses_the_published_primitives_and_never_a_teacher_id(): void
    {
        foreach ($this->tierTwoFiles() as $file) {
            foreach (['teacher_id', 'TimetableEntry', 'Employee::query', "'user_id'", 'teaching_assignments'] as $forbidden) {
                $this->assertSame([], $this->grep($forbidden, $file), basename($file)." must not use {$forbidden}.");
            }
        }

        $this->assertNotSame([], $this->grep('ActingEmployeeResolver', 'app/Domain/Attendance/Application/TeacherAttendanceAccess.php'));
        $this->assertNotSame([], $this->grep('TeachingOwnership', 'app/Domain/Attendance/Application/TeacherAttendanceGuard.php'));

        // Nothing in Attendance filters or authorizes by a teacher column.
        foreach (["where('teacher_id'", "->where('attendance_sessions.teacher_id'", 'teacher_id ==', 'teacher_id ==='] as $pattern) {
            $this->assertSame([], $this->grep($pattern, 'app/Domain/Attendance'));
            $this->assertSame([], $this->grep($pattern, 'app/Http/Controllers/App/Attendance'));
        }
    }

    #[Test]
    public function every_owned_route_is_gated_by_the_owned_capability_and_documented(): void
    {
        $yaml = (string) file_get_contents(base_path('../../packages/contracts/openapi/school-os-api.yaml'));
        $live = 0;

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/schools/{school}/my/attendance-')) {
                $this->assertContains('capability:attendance.teacher', $route->gatherMiddleware(), $route->uri());
                $this->assertContains('private-no-store', $route->gatherMiddleware(), $route->uri());
                $this->assertNotContains('idempotent', $route->gatherMiddleware(), 'An owned submit is never replayed past the identity/ownership check.');
                $live += count(array_diff($route->methods(), ['HEAD']));
            }
        }

        $this->assertSame(6, $live, 'Six owned Attendance operations.');
        foreach (['listMyAttendanceSessions', 'submitMyAttendanceSession', 'listMyScheduledClasses', 'previewMyAttendanceRoster', 'getMyAttendanceSession', 'correctMyAttendanceRecord'] as $operationId) {
            $this->assertStringContainsString("operationId: {$operationId}\n", $yaml);
        }
    }
}
