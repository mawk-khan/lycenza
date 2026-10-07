<?php

namespace Tests\Feature\Attendance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.4 (ADR 0063 sections 8, 11) static guards for the second owned
 * adopter: the dependency direction (Attendance -> HR ActingEmployee and
 * TeachingAssignments ownership, never the reverse), identity and
 * ownership only through the published primitives, and no authority from
 * the timetable's or the session's teacher_id.
 *
 * E33 / TCH-L1 (ADR 0063 section 43) adds the production controls: MFA on
 * every session route, the bearer surface development-only, every owned
 * read audited, and no StudentMark authority on the teacher role.
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

    #[Test]
    public function every_session_route_needs_the_capability_and_mfa_and_every_bearer_route_is_development_only(): void
    {
        $web = 0;
        $api = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'app/my-attendance')) {
                $this->assertContains('capability:attendance.teacher', $middleware, $route->uri());
                $this->assertContains('mfa-page', $middleware, $route->uri().' needs MFA (E33 condition 5).');
                $web += count(array_diff($route->methods(), ['HEAD']));
            }
            if (str_starts_with($route->uri(), 'api/v1/schools/{school}/my/attendance-')) {
                $this->assertContains('teacher-attendance-api', $middleware, $route->uri().': a bearer token carries no MFA (ADR 0049).');
                $api += count(array_diff($route->methods(), ['HEAD']));
            }
        }
        $this->assertSame(5, $web, 'Five My Attendance pages and posts.');
        $this->assertSame(6, $api);

        // The bearer gate is the double guard (config flag AND local/testing), defaulting to off.
        $gate = (string) file_get_contents(app_path('Http/Middleware/EnsureTeacherAttendanceApiDevelopmentOnly.php'));
        $this->assertStringContainsString("config('attendance.teacher_api_development_enabled')", $gate);
        $this->assertStringContainsString("app()->environment(['local', 'testing'])", $gate);
        $this->assertStringContainsString("env('TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED', false)", (string) file_get_contents(config_path('attendance.php')));
    }

    #[Test]
    public function every_owned_read_is_audited_after_the_ownership_decision(): void
    {
        $actions = [
            'app/Http/Controllers/App/Attendance/MyAttendanceController.php' => ['index' => 'sessionsListed', 'take' => 'classesListed', 'show' => 'sessionViewed'],
            'app/Domain/Attendance/Http/Controllers/TeacherAttendanceController.php' => ['index' => 'sessionsListed', 'show' => 'sessionViewed', 'scheduledClasses' => 'classesListed', 'rosterPreview' => 'rosterViewed'],
        ];
        foreach ($actions as $file => $methods) {
            $code = (string) file_get_contents(base_path($file));
            foreach ($methods as $method => $event) {
                $this->assertMatchesRegularExpression('/function '.$method.'\(.*?\n    \}/s', $code);
                preg_match('/function '.$method.'\(.*?\n    \}/s', $code, $body);
                $this->assertStringContainsString('$audit->'.$event.'(', $body[0], "{$file}::{$method} must audit the read (E33 condition 6).");
                $this->assertMatchesRegularExpression('/\$(access|this)->scope\(/', $body[0], "{$file}::{$method} must decide ownership first.");
            }
        }
        $this->assertStringContainsString('$audit->rosterViewed(', (string) file_get_contents(base_path('app/Http/Controllers/App/Attendance/MyAttendanceController.php')));

        // Read-audit metadata never names a Student.
        $audit = (string) file_get_contents(app_path('Domain/Attendance/Application/TeacherAttendanceReadAudit.php'));
        foreach (["'studentId'", "'studentEnrollmentId'", "'fullName'", "'rollNumber'", "'status'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $audit);
        }
    }

    #[Test]
    public function the_teacher_role_holds_exactly_its_owned_capabilities_and_no_administrative_marks(): void
    {
        $keys = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')
            ->where('roles.key', 'teacher')->where('roles.is_system', true)->orderBy('capability_key')->pluck('capability_key')->all();
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'examinations.marks.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $keys,
            'Attendance authority never brings StudentMark authority (E33 determination section 10): the owned marks key is its own RES.4 grant (ADR 0068 §25, development only).');
    }

    #[Test]
    public function the_complete_teacher_attendance_surface_is_exactly_the_pinned_eleven_routes(): void
    {
        // Found by controller and by capability, not only by URI prefix, so no alternate route escapes the gates.
        $surface = [];
        foreach (Route::getRoutes() as $route) {
            $action = (string) $route->getActionName();
            $middleware = $route->gatherMiddleware();
            if (str_contains($action, 'MyAttendanceController') || str_contains($action, 'TeacherAttendanceController') || in_array('capability:attendance.teacher', $middleware, true)) {
                $surface[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
                $this->assertContains('capability:attendance.teacher', $middleware, $route->uri());
                $this->assertTrue(
                    str_starts_with($route->uri(), 'app/my-attendance') ? in_array('mfa-page', $middleware, true) : in_array('teacher-attendance-api', $middleware, true),
                    $route->uri().' escapes the MFA / development-only policy.',
                );
            }
        }
        sort($surface);
        $this->assertSame([
            'GET api/v1/schools/{school}/my/attendance-sessions',
            'GET api/v1/schools/{school}/my/attendance-sessions/roster-preview',
            'GET api/v1/schools/{school}/my/attendance-sessions/scheduled-classes',
            'GET api/v1/schools/{school}/my/attendance-sessions/{attendanceSession}',
            'GET app/my-attendance',
            'GET app/my-attendance/take',
            'GET app/my-attendance/{attendanceSession}',
            'POST api/v1/schools/{school}/my/attendance-records/{attendanceRecord}/correct',
            'POST api/v1/schools/{school}/my/attendance-sessions',
            'POST app/my-attendance',
            'POST app/my-attendance/records/{attendanceRecord}/correct',
        ], $surface);

        // Production refuses to boot with the development flag on (a third layer behind the middleware's double guard).
        $this->assertStringContainsString("'teacher_attendance_api_development_enabled'", (string) file_get_contents(app_path('Support/Configuration/ProductionConfigurationGuard.php')));
    }
}
