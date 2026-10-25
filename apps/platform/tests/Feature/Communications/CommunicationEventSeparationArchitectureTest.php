<?php

namespace Tests\Feature\Communications;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75; OD-9.3): a School calendar Event is information,
 * never Attendance, closure, timetable, leave, payroll or exam truth. No
 * code in those domains (or their controllers) may read Communications
 * Events -- a future Student academic calendar or closure model needs its
 * own owner contract, never a dependency on this table.
 *
 * ORG.9D-3R1 (ADR 0076 §76): consuming the calendar
 * (`communications.events.view`) is separate from producing it. The consumer
 * capability opens the calendar only and never satisfies a management,
 * approval or Hub check; the Staff audience stays on HR's canonical
 * CurrentStaffMembers.
 */
class CommunicationEventSeparationArchitectureTest extends TestCase
{
    private const GUARDED = [
        'app/Domain/Attendance', 'app/Domain/StaffAttendance', 'app/Domain/Timetable', 'app/Domain/Leave',
        'app/Domain/Payroll', 'app/Domain/Examinations', 'app/Domain/AcademicStructure', 'app/Domain/CurriculumDelivery',
        'app/Http/Controllers/App/Attendance', 'app/Http/Controllers/App/Timetable', 'app/Http/Controllers/App/Leave',
    ];

    private const NEEDLES = ['communication_events', 'CommunicationEvent', 'Communications\\Application\\Events'];

    #[Test]
    public function attendance_timetable_leave_payroll_and_exams_never_read_calendar_events(): void
    {
        $base = base_path();
        $offenders = [];
        foreach (self::GUARDED as $dir) {
            if (! is_dir("{$base}/{$dir}")) {
                continue;
            }
            foreach ((new Finder)->files()->in("{$base}/{$dir}")->name('*.php') as $file) {
                foreach (self::NEEDLES as $needle) {
                    if (str_contains($file->getContents(), $needle)) {
                        $offenders[] = $dir.'/'.$file->getRelativePathname().' -> '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'A calendar Event must never become Attendance, closure, timetable, leave, payroll or exam truth.');
        $this->assertDirectoryExists("{$base}/app/Domain/Attendance", 'The guard must keep pointing at real code.');
    }

    #[Test]
    public function the_consumer_capability_opens_the_calendar_only_and_never_a_management_check(): void
    {
        $base = base_path();
        $holders = [];
        foreach ((new Finder)->files()->in("{$base}/app")->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'communications.events.view')) {
                $holders[] = 'app/'.$file->getRelativePathname();
            }
        }
        sort($holders);
        $this->assertSame([
            'app/Domain/Communications/Application/Events/CommunicationEventCalendar.php',
            'app/Domain/Communications/Http/Controllers/CommunicationCalendarController.php',
            'app/Support/Authorization/CapabilityClasses.php',
            'app/Support/Navigation/StaffNavigation.php',
        ], $holders, 'Only the staff calendar read, its navigation entry and the classification name the consumer capability.');
        $this->assertSame(1, substr_count((string) file_get_contents("{$base}/app/Domain/Communications/Application/Events/CommunicationEventCalendar.php"), "'communications.events.view'"), 'Checked once, in forStaff().');

        $middleware = fn (string $name): array => array_values(array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'capability:')));
        $this->assertSame(['capability:communications.events.view'], $middleware('app.communications.calendar'));
        $this->assertSame(['capability:portal.communications.view'], $middleware('app.portal.calendar.index'), 'The portal keeps its own Guardian capability.');
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, 'app.communications.events.')) {
                $this->assertSame(['capability:communications.announce'], $middleware($name), "{$name} stays management.");
            }
            if ($name !== 'app.communications.calendar') {
                $this->assertNotContains('capability:communications.events.view', $route->gatherMiddleware(), "{$name} never accepts the consumer capability.");
            }
        }
    }

    #[Test]
    public function the_staff_audience_stays_on_the_canonical_hr_resolver(): void
    {
        $code = (string) file_get_contents(base_path('app/Domain/Communications/Application/Events/CommunicationEventCalendar.php'));
        $this->assertStringContainsString('use App\\Domain\\HR\\Application\\CurrentStaffMembers;', $code);
        foreach (['HR\\Infrastructure', 'Employee', 'EmploymentRecord', "'teacher'", 'hasRole('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "Staff identity comes from CurrentStaffMembers only, never {$forbidden}.");
        }
    }
}
