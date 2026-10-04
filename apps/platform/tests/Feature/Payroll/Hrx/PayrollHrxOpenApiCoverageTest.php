<?php

namespace Tests\Feature\Payroll\Hrx;

use App\Domain\Payroll\Http\Controllers\PayrollHrxInputController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.10): the HRX-input operations are documented exactly as
 * they are live, and nowhere in the API -- administrative or self-service --
 * is there an NCP, loss-of-pay, deduction or wage-reduction operation
 * (HRX-L4 open).
 */
class PayrollHrxOpenApiCoverageTest extends TestCase
{
    private function contract(): string
    {
        $path = base_path('../../packages/contracts/openapi/school-os-api.yaml');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function the_hrx_input_operations_are_documented_exactly_as_live(): void
    {
        $live = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (is_string($action) && explode('@', $action, 2)[0] === PayrollHrxInputController::class) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $live[] = $method.' '.preg_replace('/\{[^}]+\}/', '{param}', '/'.preg_replace('#^api/v1/#', '', $route->uri()));
                }
            }
        }
        sort($live);

        $documented = [];
        $current = null;
        foreach (explode("\n", $this->contract()) as $line) {
            if (preg_match('#^  (/\S+):$#', $line, $m)) {
                $current = str_contains($m[1], '/hrx-inputs') ? preg_replace('/\{[^}]+\}/', '{param}', $m[1]) : null;

                continue;
            }
            if ($current !== null && preg_match('#^    (get|post|put|patch|delete):$#', $line, $m)) {
                $documented[] = strtoupper($m[1]).' '.$current;
            }
        }
        sort($documented);

        $this->assertSame([
            'GET /schools/{param}/payroll-runs/{param}/hrx-inputs',
            'POST /schools/{param}/payroll-runs/{param}/hrx-inputs/difference-checks',
        ], $live);
        $this->assertSame($live, $documented);
    }

    #[Test]
    public function no_ncp_loss_of_pay_or_wage_deduction_operation_exists_anywhere(): void
    {
        preg_match_all('#^  (/\S+):$#m', $this->contract(), $m);
        foreach ($m[1] as $path) {
            $this->assertDoesNotMatchRegularExpression('#ncp|loss-of-pay|lop|unpaid-days|wage-deduction|deductions?\b|non-payable#i', $path, "{$path}: no HRX-L4 operation");
            if (str_contains($path, '/my/')) {
                $this->assertDoesNotMatchRegularExpression('#payroll-run|hrx|absence-evidence|correction#', $path, "{$path}: no self-service payroll evidence or correction");
            }
        }
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('#ncp|loss-of-pay|unpaid-days|wage-deduction#i', $route->uri());
        }
    }
}
