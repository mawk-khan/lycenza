<?php

namespace Tests\Feature\StaffSelfService;

use App\Domain\Leave\Http\Controllers\MyLeaveController;
use App\Domain\Payroll\Http\Controllers\MyPayslipController;
use App\Domain\StaffAttendance\Http\Controllers\MyStaffAttendanceController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.8): bidirectional proof that the OpenAPI contract's
 * Staff Self-Service paths exactly match the live `/api/v1` routes, that
 * own attendance has no write, and that no HRX.5 or device operation leaked
 * in. Mirrors the HRX.1-HRX.3 coverage tests.
 */
class StaffSelfServiceOpenApiCoverageTest extends TestCase
{
    private const CONTROLLERS = [MyLeaveController::class, MyStaffAttendanceController::class, MyPayslipController::class];

    #[Test]
    public function every_live_self_service_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live self-service route(s) missing from the OpenAPI contract');
        $this->assertSame([], array_values(array_diff($documented, $live)), 'OpenAPI self-service operation(s) with no matching live route');
        $this->assertSame([
            'GET /schools/{param}/my/leave',
            'GET /schools/{param}/my/leave/requests',
            'GET /schools/{param}/my/leave/requests/{param}',
            'GET /schools/{param}/my/payslips',
            'GET /schools/{param}/my/payslips/{param}/{param}',
            'GET /schools/{param}/my/staff-attendance',
            'POST /schools/{param}/my/leave/requests',
            'POST /schools/{param}/my/leave/requests/{param}/cancel',
            'POST /schools/{param}/my/leave/requests/{param}/withdraw',
        ], $live, 'exactly nine operations -- update this pin (and the contract) deliberately');
    }

    #[Test]
    public function own_attendance_is_read_only_and_no_hrx5_device_or_decision_operation_exists(): void
    {
        foreach ($this->documentedOperations() as $operation) {
            if (str_contains($operation, '/my/staff-attendance')) {
                $this->assertStringStartsWith('GET ', $operation, 'own attendance is read only');
            }
            if (str_contains($operation, '/my/payslips')) {
                $this->assertStringStartsWith('GET ', $operation, 'own payslips are read only');
            }
            $this->assertDoesNotMatchRegularExpression('#ncp|loss-of-pay|unpaid|deduct|fingerprint|clock|punch|device|biometric|kiosk|geo|approve|reject|allocat|adjust|year-close#i', $operation);
            $this->assertStringStartsNotWith('DELETE ', $operation);
            $this->assertStringStartsNotWith('PATCH ', $operation);
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
            if (! is_string($action) || ! in_array(explode('@', $action, 2)[0], self::CONTROLLERS, true)) {
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
            if (preg_match('#^  (/schools/\{schoolId\}/my/(leave|staff-attendance|payslips)\S*):$#', $line, $m)) {
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
