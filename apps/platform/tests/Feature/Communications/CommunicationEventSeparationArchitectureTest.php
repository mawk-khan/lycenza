<?php

namespace Tests\Feature\Communications;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75; OD-9.3): a School calendar Event is information,
 * never Attendance, closure, timetable, leave, payroll or exam truth. No
 * code in those domains (or their controllers) may read Communications
 * Events -- a future Student academic calendar or closure model needs its
 * own owner contract, never a dependency on this table.
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
}
