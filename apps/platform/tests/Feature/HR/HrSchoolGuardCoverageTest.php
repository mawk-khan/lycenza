<?php

namespace Tests\Feature\HR;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-1, rule 86): a structural pin, so that a new writer has to answer the
 * School question. It scans Application classes of the HR family. A class that writes
 * either names `SchoolOperationalGuard`, or is listed below with the reason it need not.
 * A source scan sees files, not paths, so this proves a class was considered, not that
 * every path is covered: `HrProfileSchoolCheckTest` and `HrExecutionTimeSchoolCheckTest`
 * prove the paths by calling them. Coverage reaching the guard indirectly is listed too.
 */
class HrSchoolGuardCoverageTest extends TestCase
{
    private const ROOTS = ['Domain/HR/Application', 'Domain/Leave/Application', 'Domain/StaffAttendance/Application', 'Domain/WorkforceTasks/Application'];

    private const WRITE = '/::query\(\)[^;]*->(?:create|forceCreate|update|insert\w*|delete|updateOrCreate|upsert|firstOrCreate)\(|->forceFill\(|->save\(\)|->update\(|->delete\(\)|DB::table\([^;]*->(?:insert\w*|update|delete|upsert)\(|updateOrCreate\(/';

    /** Writes, and rightly names no guard. */
    private const NOT_A_BUSINESS_EFFECT = [
        'Domain/HR/Application/Retention/EmployeeRecordRetentionService.php' => 'platform retention: it must keep running for a suspended School (ADR 0047 section 8)',
        'Domain/HR/Application/EmployeeNumberAllocator.php' => 'a counter, called only after EmployeeService::createRecord() has checked',
        'Domain/Leave/Application/Retention/LeaveEvidenceRetentionService.php' => 'platform retention',
        'Domain/StaffAttendance/Application/Retention/StaffAttendanceEvidenceRetentionService.php' => 'platform retention',
    ];

    /** Writes through a trusted path that holds the School before it gets here. */
    private const CHECKED_BY_THE_CALLER = [
        'Domain/HR/Application/Profile/ArchivesProfileRecords.php' => 'used only by classes that hold `$operational`, and it calls the guard itself',
    ];

    /** @return iterable<string, string> */
    private function applicationClasses(): iterable
    {
        foreach (self::ROOTS as $root) {
            if (! is_dir(app_path($root))) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($root), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    yield str_replace(app_path().'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
                }
            }
        }
    }

    #[Test]
    public function every_writing_class_names_the_guard_or_says_why_not(): void
    {
        $unanswered = [];
        foreach ($this->applicationClasses() as $path => $source) {
            if (preg_match(self::WRITE, $source) !== 1 || isset(self::NOT_A_BUSINESS_EFFECT[$path]) || isset(self::CHECKED_BY_THE_CALLER[$path])) {
                continue;
            }
            if (! str_contains($source, 'SchoolOperationalGuard') && ! str_contains($source, 'ApprovalDecisionAuthority')) {
                $unanswered[] = $path;
            }
        }

        $this->assertSame([], $unanswered, 'A writer of the HR family answers rule 86: take SchoolOperationalGuard, or say why not here.');
    }

    #[Test]
    public function the_listed_exemptions_still_exist(): void
    {
        foreach ([...array_keys(self::NOT_A_BUSINESS_EFFECT), ...array_keys(self::CHECKED_BY_THE_CALLER)] as $path) {
            $this->assertFileExists(app_path($path), 'An exemption outlived its class.');
        }
    }

    /** @return list<string> */
    private static function guardedFirst(): array
    {
        return [
            'EmployeeAddressService', 'EmployeeEmergencyContactService', 'EmployeeNoteService', 'EmployeeQualificationService', 'EmployeeExperienceService',
            'EmployeeCertificationService', 'EmployeePersonalDetailService', 'DepartmentService', 'PositionService', 'EmployeeCategoryService',
            'Profile/EmployeeReferenceCodeService', 'Profile/ArchivesProfileRecords',
        ];
    }

    #[Test]
    public function the_school_is_the_first_thing_each_transaction_asks(): void
    {
        foreach (self::guardedFirst() as $class) {
            $source = (string) file_get_contents(app_path("Domain/HR/Application/{$class}.php"));
            $transactions = preg_match_all('/DB::transaction\(function \(\)[^{]*\{\n/', $source);
            $guardedFirst = preg_match_all('/DB::transaction\(function \(\)[^{]*\{\n(?:\s*\/\/[^\n]*\n)*\s*\$this->operational->requireOperational\(/', $source);

            $this->assertGreaterThan(0, $transactions, "{$class} has a transaction");
            $this->assertSame($transactions, $guardedFirst, "{$class}: rule 86 comes before anything else in the transaction");
        }
    }

    #[Test]
    public function a_document_upload_is_refused_before_its_bytes_are_stored_and_held_where_they_are_recorded(): void
    {
        // DocumentService::store() writes the object before its transaction and deletes it on failure, so behaviour
        // cannot tell a refused upload from a stored-then-removed one. The order is pinned here instead.
        $source = (string) file_get_contents(app_path('Domain/HR/Application/EmployeeDocumentService.php'));
        $register = substr($source, (int) strpos($source, 'public function register('), (int) strpos($source, 'public function update(') - (int) strpos($source, 'public function register('));

        $this->assertSame(2, substr_count($register, '$this->operational->requireOperational('));
        $this->assertLessThan(strpos($register, '->createForEmployee('), strpos($register, '$this->operational->requireOperational('), 'the first check comes before the bytes');
        $this->assertGreaterThan(strpos($register, '->createForEmployee('), strrpos($register, '$this->operational->requireOperational('), 'the check that holds is inside the closure');
    }

    #[Test]
    public function the_task_writers_ask_the_school_for_every_path(): void
    {
        foreach (['TaskCatalogueService', 'TaskWorkflowService'] as $class) {
            $source = (string) file_get_contents(app_path("Domain/WorkforceTasks/Application/{$class}.php"));
            $this->assertStringContainsString('$this->operational->requireOperational($school->id);', $source);
            $this->assertStringNotContainsString('DB::transaction($work)', $source, 'the transaction helper is where the check lives');
        }
        $source = (string) file_get_contents(app_path('Domain/WorkforceTasks/Application/TaskAssignmentService.php'));
        $this->assertSame(2, substr_count($source, '$this->operational->requireOperational($school->id);'));
    }
}
