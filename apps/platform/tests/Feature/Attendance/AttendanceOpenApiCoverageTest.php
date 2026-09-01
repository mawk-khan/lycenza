<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Http\Controllers\AttendanceSessionController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.2 (OpenAPI contract, MANDATORY in this first implementation
 * branch -- not a later correction pass like Timetable needed).
 * Bidirectional proof that `packages/contracts/openapi/school-os-api.yaml`'s
 * Attendance paths exactly match the live `/api/v1` routes registered
 * for AttendanceSessionController: zero live-but-undocumented
 * operations, zero documented-but-nonexistent ones.
 *
 * Deliberately the SAME narrow, regex-based approach
 * Tests\Feature\Timetable\TimetableOpenApiCoverageTest already
 * established (no YAML parser is installed, and CLAUDE.md rule 2
 * forbids adding a package for one test) -- see that class's docblock
 * for the exact formatting assumptions this relies on.
 */
class AttendanceOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_attendance_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveAttendanceOperations();
        $documented = $this->documentedAttendanceOperations();

        $liveButUndocumented = array_values(array_diff($live, $documented));
        $documentedButNonexistent = array_values(array_diff($documented, $live));

        $this->assertSame([], $liveButUndocumented,
            'Live Attendance route(s) missing from the OpenAPI contract: '.implode(', ', $liveButUndocumented));
        $this->assertSame([], $documentedButNonexistent,
            'OpenAPI Attendance path(s)/operation(s) with no matching live route: '.implode(', ', $documentedButNonexistent));

        // Pinned so a silent drift (a route AND its doc entry quietly
        // removed together, which the diffs above cannot catch) fails
        // loudly too.
        $this->assertCount(6, $live, 'Expected exactly 6 live Attendance routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(6, $documented);
    }

    #[Test]
    public function the_contract_documents_the_provenance_and_period_label_semantics(): void
    {
        // These are not decorative: a future reader who takes
        // timetableEntryId or periodName as historical truth would
        // reintroduce exactly the defect the snapshot design exists to
        // prevent, so the contract must say so explicitly.
        $yaml = file_get_contents($this->contractPath());

        $this->assertStringContainsString('PROVENANCE ONLY: the TimetableEntry from which this register', $yaml);
        $this->assertStringContainsString('IMMUTABLE historical wall-clock start', $yaml);
        $this->assertStringContainsString('CURRENT human-facing label, resolved through periodId', $yaml);
        $this->assertStringContainsString('PROVENANCE: the placement that qualified this Student', $yaml);
    }

    #[Test]
    public function the_contract_exposes_no_sensitive_student_or_employee_field(): void
    {
        $yaml = $this->attendanceSection();

        foreach (['dateOfBirth', 'date_of_birth', 'guardian', 'workEmail', 'work_phone', 'address', 'medical', 'diagnosis'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden.':', $yaml,
                "The Attendance contract must not define a {$forbidden} property.",
            );
        }

        // And no free-text reason of any kind.
        foreach (['reason:', 'note:', 'remark:', 'minutesLate:'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $yaml);
        }
    }

    private function contractPath(): string
    {
        $path = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($path, 'OpenAPI contract file not found at expected path.');

        return $path;
    }

    /**
     * The Attendance schema block only, so the minimization assertions
     * above cannot be satisfied (or broken) by another module's text.
     */
    private function attendanceSection(): string
    {
        $yaml = file_get_contents($this->contractPath());
        $start = strpos($yaml, '    AttendanceStatus:');
        $end = strpos($yaml, '    TimetableEntry:');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($yaml, $start, $end - $start);
    }

    /**
     * @return list<string>
     */
    private function liveAttendanceOperations(): array
    {
        $operations = [];

        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action)) {
                return;
            }

            [$controller] = explode('@', $action, 2) + [null];
            if ($controller !== AttendanceSessionController::class) {
                return;
            }

            $path = '/'.preg_replace('#^api/v1/#', '', $route->uri());
            $path = preg_replace('/\{[^}]+\}/', '{param}', $path);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $operations[] = "{$method} {$path}";
            }
        });

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function documentedAttendanceOperations(): array
    {
        $lines = file($this->contractPath(), FILE_IGNORE_NEW_LINES);
        $operations = [];
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('#^  (/schools/\{schoolId\}/attendance-\S*):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }

            if ($currentPath !== null && preg_match('/^\S/', $line)) {
                $currentPath = null;

                continue;
            }

            // A new path key for ANOTHER module also ends the block.
            if ($currentPath !== null && preg_match('#^  /#', $line)) {
                $currentPath = null;

                continue;
            }

            if ($currentPath !== null && preg_match('#^    (get|post|patch|put|delete):$#', $line, $m)) {
                $operations[] = strtoupper($m[1])." {$currentPath}";
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }
}
