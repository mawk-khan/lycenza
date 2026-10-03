<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Http\Controllers\LeaveConfigurationController;
use App\Domain\Leave\Http\Controllers\LeaveLedgerController;
use App\Domain\Leave\Http\Controllers\LeaveRequestController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §16): bidirectional proof that the OpenAPI contract's
 * Leave paths exactly match the live `/api/v1` routes of the three Leave
 * controllers (HRX.2 adds LeaveRequestController). Mirrors Tests\Feature\Examinations\GradeScaleOpenApiCoverageTest.
 */
class LeaveOpenApiCoverageTest extends TestCase
{
    #[Test]
    public function every_live_leave_route_is_documented_and_vice_versa(): void
    {
        $live = $this->liveOperations();
        $documented = $this->documentedOperations();

        $this->assertSame([], array_values(array_diff($live, $documented)), 'Live Leave route(s) missing from the OpenAPI contract');
        $this->assertSame([], array_values(array_diff($documented, $live)), 'OpenAPI Leave operation(s) with no matching live route');

        $this->assertCount(40, $live, 'Expected exactly 40 live Leave API routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(40, $documented);
    }

    #[Test]
    public function the_contract_documents_no_attendance_self_service_or_payroll_operation(): void
    {
        foreach ($this->documentedOperations() as $operation) {
            $this->assertDoesNotMatchRegularExpression('#attendance|/me\b|/my\b|self|payslip|payroll|ncp|loss-of-pay#i', $operation, 'HRX.3-HRX.5 operations are not part of HRX.1/HRX.2');
        }
        $deletes = array_values(array_filter($this->documentedOperations(), fn (string $o) => str_starts_with($o, 'DELETE ')));
        $this->assertSame(['DELETE /schools/{param}/leave/calendar/holidays/{param}'], $deletes, 'only a holiday is removable; ledger, policies and assignments never are');
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
            if (! is_string($action)) {
                return;
            }
            [$controller] = explode('@', $action, 2) + [null];
            if (! in_array($controller, [LeaveConfigurationController::class, LeaveLedgerController::class, LeaveRequestController::class], true)) {
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
            if (preg_match('#^  (/schools/\{schoolId\}/leave\S*):$#', $line, $m)) {
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
