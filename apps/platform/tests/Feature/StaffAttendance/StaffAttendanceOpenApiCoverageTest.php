<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Http\Controllers\StaffAttendanceController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24): bidirectional proof that the OpenAPI contract's
 * Staff Attendance paths exactly match the live `/api/v1` routes, and that
 * no HRX.4/HRX.5 or device operation leaked into it. Mirrors
 * Tests\Feature\Leave\LeaveOpenApiCoverageTest.
 */
class StaffAttendanceOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_staff_attendance_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live Staff Attendance route(s) missing from the OpenAPI contract');
        $this->assertSame([], array_values(array_diff($documented, $live)), 'OpenAPI Staff Attendance operation(s) with no matching live route');
        $this->assertSame([
            'GET /schools/{param}/staff-attendance/history',
            'GET /schools/{param}/staff-attendance/records/{param}',
            'GET /schools/{param}/staff-attendance/register',
            'POST /schools/{param}/staff-attendance/records',
            'POST /schools/{param}/staff-attendance/records/{param}/corrections',
            'POST /schools/{param}/staff-attendance/register',
        ], $live, 'exactly six operations -- update this pin (and the contract) deliberately');
    }

    #[Test]
    public function the_contract_documents_no_self_service_payroll_device_or_delete_operation(): void
    {
        $contract = (string) file_get_contents($this->contractPath());
        $this->assertStringNotContainsString('hr.staff_attendance.self', $contract);
        foreach ($this->documentedOperations() as $operation) {
            $this->assertDoesNotMatchRegularExpression('#/me\b|/my\b|self|payslip|payroll|ncp|loss-of-pay|clock|punch|device|biometric|kiosk|geo#i', $operation, 'HRX.4/HRX.5 and device attendance are not part of HRX.3');
            $this->assertStringStartsNotWith('DELETE ', $operation, 'attendance evidence is corrected, never deleted');
            $this->assertStringStartsNotWith('PATCH ', $operation, 'attendance evidence changes only by correction');
        }
    }

    private function contractPath(): string
    {
        $path = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($path);

        return $path;
    }

    /** @return list<string> */
    private function liveOperations(): array
    {
        $operations = [];
        collect(Route::getRoutes())->each(function ($route) use (&$operations): void {
            $action = $route->getAction('controller');
            if (! is_string($action) || explode('@', $action, 2)[0] !== StaffAttendanceController::class) {
                return;
            }
            $path = preg_replace('/\{[^}]+\}/', '{param}', '/'.preg_replace('#^api/v1/#', '', $route->uri()));
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[] = "{$method} {$path}";
                }
            }
        });
        sort($operations);

        return array_values(array_unique($operations));
    }

    /** @return list<string> */
    private function documentedOperations(): array
    {
        $operations = [];
        $currentPath = null;
        foreach (file($this->contractPath(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('#^  (/schools/\{schoolId\}/staff-attendance\S*):$#', $line, $m)) {
                $currentPath = preg_replace('/\{[^}]+\}/', '{param}', $m[1]);

                continue;
            }
            if ($currentPath !== null && (preg_match('/^\S/', $line) || preg_match('#^  /#', $line))) {
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
