<?php

namespace Tests\Feature\Architecture;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69): the daily-operations read models are reads on
 * existing authority -- GET-only routes, no write path in the new read code,
 * no new Campus adoption, no export, and no "missing register", attendance
 * rate or clock-time/pay-derived state.
 */
class DailyOperationsReadModelGuardTest extends TestCase
{
    private const ROUTES = [
        'app.attendance.registers',
        'app.attendance.students.show',
        'app.staff-attendance.monthly',
        'app.leave.monthly',
    ];

    #[Test]
    public function the_new_surfaces_are_get_only(): void
    {
        foreach (self::ROUTES as $name) {
            $route = Router::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $name);
            $this->assertDoesNotMatchRegularExpression('/export|download|csv|xlsx|pdf/i', $route->uri(), "{$name}: exports stay owner decision D-11.");
        }
    }

    #[Test]
    public function the_new_read_code_writes_nothing_but_its_read_audit(): void
    {
        $source = (string) file_get_contents(app_path('Domain/Attendance/Application/AttendanceOperationsReadService.php'));
        foreach (['->save(', '->insert(', '->update(', '->delete(', '->forceFill(', '::create(', 'DB::statement', 'AttendanceCorrectionService', 'AttendanceSubmissionService'] as $write) {
            $this->assertStringNotContainsString($write, $source, $write);
        }
        $this->assertStringNotContainsString("'missing'", $source, 'No "missing" status: no Student calendar records holidays.');
        $this->assertDoesNotMatchRegularExpression('/percent|rate\b/i', (string) preg_replace('#/\*.*?\*/#s', '', $source), 'No attendance rate (ADR 0040).');

        $staff = (string) file_get_contents(app_path('Domain/StaffAttendance/Application/StaffAttendanceReadService.php'));
        $body = substr($staff, (int) strpos($staff, 'public function monthSummary'), 3000);
        foreach (["'late'", "'early'", 'punch', 'loss', 'unpaid', 'isPaid'] as $derived) {
            $this->assertStringNotContainsString($derived, $body, "HRX-L2/L4: no {$derived} in the month summary");
        }
    }

    #[Test]
    public function no_new_campus_adoption(): void
    {
        foreach (['hr.staff_attendance.view', 'hr.leave.view'] as $key) {
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key} stays whole-School");
        }
        $this->assertTrue(CampusScopeCatalogue::isAdopted('attendance.view'));
        $this->assertTrue(CampusScopeCatalogue::isAdopted('library.circulation.view'));
        $this->assertFalse(CampusScopeCatalogue::isAdopted('library.fines.view'), 'Fines stay whole-School.');
    }

    #[Test]
    public function the_hr_report_filters_are_never_per_employee_correlated_subqueries(): void
    {
        // ORG.9C-1 query review: nested correlated EXISTS per Employee ran as
        // nested loops (~130 s at 72 employees on freshly loaded tables).
        $source = (string) file_get_contents(app_path('Domain/HR/Application/CurrentEmployeeAssignments.php'));
        foreach (['whereOnSeveralCampuses', 'whereReportingTo'] as $method) {
            $start = (int) strpos($source, "public function {$method}");
            $end = strpos($source, 'public function', $start + 1);
            $body = substr($source, $start, $end === false ? null : $end - $start);
            $this->assertStringNotContainsString("'employees.id')", $body, "{$method} must not correlate on employees.id");
            $this->assertStringContainsString("whereIn('employees.id'", $body, "{$method} filters by one uncorrelated set");
        }
    }
}
