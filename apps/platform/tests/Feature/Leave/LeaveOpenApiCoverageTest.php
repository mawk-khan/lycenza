<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Http\Controllers\LeaveConfigurationController;
use App\Domain\Leave\Http\Controllers\LeaveLedgerController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §16): bidirectional proof that the OpenAPI contract's
 * Leave paths exactly match the live `/api/v1` routes of the two Leave
 * controllers. Mirrors Tests\Feature\Examinations\GradeScaleOpenApiCoverageTest.
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

        $this->assertCount(24, $live, 'Expected exactly 24 live Leave API routes -- update this pin (and the contract) deliberately.');
        $this->assertCount(24, $documented);
    }

    #[Test]
    public function the_contract_documents_no_request_approval_attendance_or_self_service_operation(): void
    {
        foreach ($this->documentedOperations() as $operation) {
            $this->assertDoesNotMatchRegularExpression('#/requests|approv|attendance|/me\b|self|consum|payslip#i', $operation, 'HRX.2-HRX.5 operations are not part of HRX.1');
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
            if (! in_array($controller, [LeaveConfigurationController::class, LeaveLedgerController::class], true)) {
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
