<?php

namespace Tests\Feature\StaffSelfService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25) static guards:
 * - the `staff_self_service` role is never an enforcement condition (no
 *   application code names it);
 * - every self-service route needs its `.self` capability and
 *   `private-no-store`, and none takes an Employee/User identifier;
 * - ownership is resolved only through ActingEmployeeResolver;
 * - no HRX.5 (loss-of-pay / NCP / unpaid-days) concept and no new HRX table.
 */
class StaffSelfServiceArchitectureGuardTest extends TestCase
{
    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path($dir)).' -name "*.php"', $files);
        sort($files);

        return $files;
    }

    #[Test]
    public function the_role_key_is_never_checked_by_application_code(): void
    {
        foreach ($this->phpFiles('') as $file) {
            $this->assertStringNotContainsString('staff_self_service', $this->code($file), "{$file}: the role is a capability bundle, never an authorization condition");
        }
    }

    #[Test]
    public function every_self_service_route_is_capability_gated_private_and_identifier_free(): void
    {
        $expected = ['/my/leave' => 'capability:hr.leave.self', '/my/staff-attendance' => 'capability:hr.staff_attendance.self', '/my/payslips' => 'capability:payroll.payslips.self'];
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            foreach ($expected as $prefix => $capability) {
                if (! str_contains($route->uri(), $prefix)) {
                    continue;
                }
                $seen++;
                $middleware = $route->gatherMiddleware();
                $this->assertContains($capability, $middleware, $route->uri());
                $this->assertDoesNotMatchRegularExpression('/\{(employee|user|employmentId)/i', $route->uri(), 'no route names the owner');
                if (str_starts_with($route->uri(), 'api/')) {
                    $this->assertContains('private-no-store', $middleware, $route->uri());
                }
            }
        }
        $this->assertSame(9, $seen, 'nine API self-service routes');
        foreach (['/app/my-leave', '/app/my-staff-attendance', '/app/my-payslips'] as $page) {
            $this->assertNotNull(collect(Route::getRoutes())->first(fn ($r) => '/'.$r->uri() === $page), "{$page} page exists");
        }
    }

    #[Test]
    public function ownership_comes_only_from_acting_employee_and_no_hrx5_concept_or_table_exists(): void
    {
        $selfService = [
            app_path('Domain/Leave/Application/LeaveRequestService.php'), app_path('Domain/Leave/Application/LeaveRequestReadService.php'),
            app_path('Domain/StaffAttendance/Application/StaffAttendanceReadService.php'), app_path('Domain/Payroll/Application/PayslipReadService.php'),
        ];
        foreach ($selfService as $file) {
            $this->assertStringContainsString('ActingEmployeeResolver', $this->code($file), "{$file} resolves ownership through HR's one resolver");
        }
        foreach ([...$this->phpFiles('Domain/Leave'), ...$this->phpFiles('Domain/StaffAttendance'), ...$this->phpFiles('Http/Controllers/App/SelfService')] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bncp\b|loss[_ -]?of[_ -]?pay|unpaid[_ ]?days|deductible|UnpaidDaysProvider/i', $this->code($file), "{$file}: loss-of-pay is HRX.5 (HRX-L4 open)");
        }
        $tables = array_column(DB::select("select table_name from information_schema.tables where table_schema = 'public' and (table_name like 'my\\_%' or table_name like '%self_service%' or table_name like '%portal%')"), 'table_name');
        $this->assertSame([], $tables, 'HRX.4 stores nothing of its own (ADR 0065 §25.10)');
    }
}
