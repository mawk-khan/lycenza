<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 sections 4, 6, 11) static guards, in the shape of
 * AttendanceArchitectureGuardTest: cheap greps that fail loudly the moment
 * a change breaks a boundary this checkpoint relies on.
 */
class ActingEmployeeArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function grep(string $pattern, string $path, bool $regex = false): array
    {
        $output = [];
        exec('grep -rn '.($regex ? '-E ' : '-F ').'--include=*.php '.escapeshellarg($pattern).' '.escapeshellarg(base_path($path)).' 2>/dev/null', $output);

        return $output;
    }

    #[Test]
    public function capability_resolution_never_depends_on_hr_employee_identity(): void
    {
        // Capabilities and ActingEmployee are independent facts a future
        // owned-resource check combines; the resolver must never start
        // requiring (or granting from) an Employee.
        $hits = array_filter(
            $this->grep('App\\Domain\\HR', 'app/Support/Authorization'),
            fn (string $line) => ! preg_match('#^[^:]+:\d+:\s*(\*|//)#', $line),
        );

        $this->assertSame([], array_values($hits), 'app/Support/Authorization must not use HR: '.implode("\n", $hits));
    }

    #[Test]
    public function no_module_outside_hr_resolves_a_users_employee_on_its_own(): void
    {
        // The one sanctioned User -> Employee resolution is
        // App\Domain\HR\Application\ActingEmployeeResolver. Every file
        // outside HR that uses the Employee model must stay clear of its
        // `user_id` column (and raw SQL must not name employees.user_id).
        $users = array_unique(array_map(
            fn (string $line) => explode(':', $line, 2)[0],
            $this->grep('use App\Domain\HR\Infrastructure\Employee;', 'app'),
        ));
        $outsideHr = array_filter($users, fn (string $file) => ! str_starts_with($file, base_path('app/Domain/HR/')));
        $this->assertNotEmpty($outsideHr, 'Guard sanity: Employee is used outside HR (Timetable, Attendance, Transport, ...).');

        $hits = [];
        foreach ($outsideHr as $file) {
            foreach (["'user_id'", '"user_id"', 'userId('] as $needle) {
                if (str_contains((string) file_get_contents($file), $needle)) {
                    $hits[] = "{$file} ({$needle})";
                }
            }
        }
        $hits = array_merge($hits, array_filter(
            $this->grep('employees.user_id', 'app'),
            fn (string $line) => ! str_starts_with($line, base_path('app/Domain/HR/')) && ! preg_match('#^[^:]+:\d+:\s*(\*|//)#', $line),
        ));

        $this->assertSame([], array_values($hits), 'Only HR may resolve an Employee from a User: '.implode("\n", $hits));
    }

    #[Test]
    public function the_employee_user_link_is_written_only_by_employee_service(): void
    {
        $writes = array_filter(
            $this->grep("'user_id' =>", 'app/Domain/HR/Application'),
            fn (string $line) => ! str_contains($line, '/EmployeeService.php:')
                // The import passes a row's user_id INTO EmployeeService::create().
                && ! str_contains($line, '/EmployeeImportService.php:'),
        );

        $this->assertSame([], array_values($writes), 'employees.user_id is written only by EmployeeService: '.implode("\n", $writes));
        $this->assertSame([], $this->grep("forceFill(['user_id'", 'app'), 'No application code force-fills a User link.');
    }
}
