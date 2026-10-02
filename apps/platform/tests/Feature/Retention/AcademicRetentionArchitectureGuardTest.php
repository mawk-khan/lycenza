<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\TenantRetentionCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3D (E21.2G A1/A2): the year-bound academic expiries stay narrow.
 * - The clock is the authoritative Academic Year's end only: never
 *   `created_at`/`updated_at`, a status or the School's current year.
 * - A closed list: three row services (through RetentionBatch), the LMS
 *   unit (through its two floored functions), one command. No generic
 *   academic delete gateway.
 * - LMS Documents go only through the parent seam; owner/audience only
 *   with their resource.
 * - Syllabus and examination configuration are tenant lifetime: nothing
 *   expires them.
 */
class AcademicRetentionArchitectureGuardTest extends TestCase
{
    private const FILES = [
        'Domain/AcademicStructure/Application/Retention/AcademicYearRetention.php',
        'Domain/CurriculumDelivery/Application/Retention/CurriculumDeliveryRetentionService.php',
        'Domain/Attendance/Application/Retention/AttendanceSessionRetentionService.php',
        'Domain/Timetable/Application/Retention/TimetableEntryRetentionService.php',
        'Support/Retention/LmsResourceRetention.php',
        'Support/Retention/RetentionBatch.php',
        'Console/Commands/PruneAcademicOperations.php',
    ];

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> app-relative files whose code contains $needle */
    private function filesContaining(string $needle): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path()).' -name "*.php"', $files);
        $hits = [];
        foreach ($files as $file) {
            if (str_contains($this->code($file), $needle)) {
                $hits[] = substr($file, strlen(app_path()) + 1);
            }
        }
        sort($hits);

        return $hits;
    }

    #[Test]
    public function the_clock_is_the_academic_year_end_only(): void
    {
        foreach (self::FILES as $file) {
            $code = $this->code(app_path($file));
            foreach (['created_at', 'updated_at', 'published', 'archived', 'started_on', 'completed_on', 'attendance_date', 'due_on'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not date retention by {$forbidden}");
            }
        }
        $year = $this->code(app_path('Domain/AcademicStructure/Application/Retention/AcademicYearRetention.php'));
        $this->assertStringNotContainsString("'status'", $year, 'never the School\'s current or active year');
        $this->assertStringContainsString("'y.ends_on', '<'", $year);
    }

    #[Test]
    public function the_expiry_is_a_closed_list_with_no_generic_gateway(): void
    {
        $this->assertSame([
            'Console/Commands/PruneAcademicOperations.php',
            'Domain/Attendance/Application/Retention/AttendanceSessionRetentionService.php',
            'Domain/CurriculumDelivery/Application/Retention/CurriculumDeliveryRetentionService.php',
            'Domain/Timetable/Application/Retention/TimetableEntryRetentionService.php',
            'Support/Retention/LmsResourceRetention.php',
        ], array_values(array_filter($this->filesContaining('AcademicYearRetention'), fn ($f) => ! str_ends_with($f, 'AcademicYearRetention.php'))));

        $this->assertSame([
            'Domain/Attendance/Application/Retention/AttendanceSessionRetentionService.php',
            'Domain/CurriculumDelivery/Application/Retention/CurriculumDeliveryRetentionService.php',
            'Domain/Timetable/Application/Retention/TimetableEntryRetentionService.php',
            // E21.3E: the operational residual row services.
            'Domain/Transport/Application/Retention/DriverAssignmentRetentionService.php',
            'Support/Retention/AutomationExecutionRetention.php',
        ], array_values(array_filter($this->filesContaining('RetentionBatch'), fn ($f) => ! str_ends_with($f, 'RetentionBatch.php'))));

        $this->assertSame(['Support/Retention/LmsResourceRetention.php', 'Support/Retention/RetentionExpiry.php'], $this->filesContaining('lmsResource('));

        foreach (['CurriculumDeliveryRetentionService', 'AttendanceSessionRetentionService', 'TimetableEntryRetentionService', 'LmsResourceRetention;'] as $service) {
            $this->assertSame(['Console/Commands/PruneAcademicOperations.php'], array_values(array_filter($this->filesContaining($service), fn ($f) => ! str_ends_with($f, '/'.rtrim($service, ';').'.php'))), "{$service} is reached only by its command");
        }

        $this->assertStringNotContainsString('--table', (string) file_get_contents(app_path('Console/Commands/PruneAcademicOperations.php')));
    }

    #[Test]
    public function lms_owner_audience_and_documents_leave_only_with_their_resource(): void
    {
        $code = $this->code(app_path('Support/Retention/LmsResourceRetention.php'));
        $this->assertStringNotContainsString('section_audiences\')->delete', $code);
        $this->assertStringNotContainsString("'owner_employee_id' =>", $code, 'the owner is never nulled');
        $this->assertStringContainsString('purgeWithOwner($kind', $code, 'Documents go through the parent seam');
        $this->assertStringNotContainsString("table('documents')", $code);
    }

    #[Test]
    public function syllabus_and_examination_configuration_are_tenant_lifetime(): void
    {
        $this->assertSame(TenantRetentionCatalog::TENANT_LIFETIME, TenantRetentionCatalog::CATEGORIES['academic_configuration'][0]);
        $this->assertSame(['syllabus_units', 'examinations', 'examination_papers'], TenantRetentionCatalog::CATEGORIES['academic_configuration'][2]);

        $retention = [];
        exec('find '.escapeshellarg(app_path()).' -path "*Retention*" -name "*.php"', $retention);
        $retention = array_merge($retention, glob(app_path('Console/Commands/Prune*.php')) ?: []);
        foreach ($retention as $file) {
            $code = $this->code($file);
            foreach (["table('syllabus_units", "table('examinations", "table('examination_papers", 'SyllabusUnit::', 'Examination::', 'ExaminationPaper::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: no E21 age-based purge of syllabus or examination configuration");
            }
        }
    }
}
