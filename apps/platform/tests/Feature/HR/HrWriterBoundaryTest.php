<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * HR.R1-C11 (R1-01): the writer boundaries existing contracts already require, pinned
 * executably. **This does not adopt the proposed one-writer definition,** which waits
 * for Architecture (`HR-R1-C10-ARCHITECTURE-INVENTORY.md` §1.8). It pins three facts:
 * no controller and no foreign domain writes an HR table; a table with several writing
 * classes has exactly the ones that are named, each a separate operation; and the
 * database denies the runtime role what history must never allow. A source scan shows
 * where the code writes today. The privilege checks are the runtime boundary.
 */
class HrWriterBoundaryTest extends TestCase
{
    /** Models whose tables HR, Leave, Staff Attendance and WorkforceTasks own. */
    private const OWNED = [
        'Employee', 'EmploymentRecord', 'EmploymentTerm', 'EmployeeAssignment', 'EmployeePlacementChange', 'ReportingRelationship', 'EmploymentSeparationCase',
        'SeparationClearanceItem', 'SeparationClearanceRequirement', 'EmployeeDocument', 'WorkforceTask', 'LeaveRequest', 'LeaveLedgerEntry', 'LeaveDecision',
        'LeavePolicyAssignment', 'StaffAttendanceRecord', 'StaffAttendanceCorrection',
    ];

    private const OWNED_TABLES = [
        'employees', 'employment_records', 'employment_terms', 'employee_assignments', 'employee_placement_changes', 'reporting_relationships', 'employment_separation_cases',
        'employment_separation_events', 'separation_clearance_items', 'separation_clearance_requirements', 'employee_documents', 'workforce_tasks', 'workforce_task_events',
        'leave_requests', 'leave_ledger_entries', 'leave_decisions', 'leave_policy_assignments', 'staff_attendance_records', 'staff_attendance_corrections',
    ];

    /** Domains that own these tables, and the platform mechanisms that may delete by retention. */
    private const OWNING_PATHS = ['Domain/HR/', 'Domain/Leave/', 'Domain/StaffAttendance/', 'Domain/WorkforceTasks/', 'Support/Retention/'];

    /**
     * Several writing classes, one named operation each (rules 130, 133, 139). These write through the model or raw
     * SQL, so a source scan can see them. The retention services are the platform's deletes, in the retention catalogue.
     */
    private const NAMED_WRITERS = [
        'EmployeeAssignment' => ['EmployeeAssignmentService', 'EmploymentService', 'EmployeeRecordRetentionService'],
        'EmployeePlacementChange' => ['PlacementChangeService', 'EmployeeAssignmentService'],
        'WorkforceTask' => ['TaskAssignmentService', 'TaskGenerationService'],
        'LeavePolicyAssignment' => ['LeavePolicyAssignmentService', 'LeaveEligibilityService'],
    ];

    /** These also write, through a row they locked, which no source scan can see from the model. Listed so they stay known. */
    private const WRITE_A_LOCKED_ROW = [
        'Domain/HR/Application/Workforce/PlacementChangeService.php' => "\$locked->forceFill(['ends_on'",
        'Domain/WorkforceTasks/Application/TaskWorkflowService.php' => '->forceFill(',
    ];

    /** @return iterable<string, string> path => source, under app/ */
    private function sources(string $root = ''): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($root), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                yield str_replace(app_path().'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
            }
        }
    }

    /**
     * The owned model or table each statement writes. Statement by statement, never one regex over a file: a nested
     * pattern hits PCRE's backtrack limit on a large source and answers false, which reads as "no writer here".
     * A write through a variable holding a model (`$row->update()`) is not seen: that is what a source scan cannot do.
     *
     * @param  list<string>  $models
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function writesIn(string $source, array $models, array $tables): array
    {
        $verb = '->(?:create|forceCreate|insert\w*|update\w*|delete|forceDelete|upsert|firstOrCreate)\(';
        $writes = [];
        foreach (explode(';', $source) as $statement) {
            foreach ($models as $model) {
                // Chained from the model: a read passed into `$service->update(...)` is no write of it.
                if (preg_match('/\b'.$model.'::[^;]*'.$verb.'|\bnew '.$model.'\(/', $statement) === 1) {
                    $writes[] = $model;
                }
            }
            foreach ($tables as $table) {
                if (preg_match('/DB::table\(\''.$table.'(?: as \w+)?\'\)[^;]*'.$verb.'|\b(?:INSERT INTO|UPDATE|DELETE FROM)\s+(?:public\.)?'.$table.'\b/', $statement) === 1) {
                    $writes[] = $table;
                }
            }
        }

        return $writes;
    }

    #[Test]
    public function the_scan_really_sees_a_writer(): void
    {
        $this->assertSame(['EmploymentRecord'], $this->writesIn("EmploymentRecord::query()->where('a', 1)->update(['status' => 'x']);", self::OWNED, self::OWNED_TABLES));
        $this->assertSame(['leave_requests'], $this->writesIn("DB::table('leave_requests as r')->where('a', 1)->delete();", self::OWNED, self::OWNED_TABLES));
        $this->assertSame(['WorkforceTask'], $this->writesIn('$t = new WorkforceTask();', self::OWNED, self::OWNED_TABLES));
        $this->assertSame([], $this->writesIn("EmploymentRecord::query()->where('a', 1)->first();", self::OWNED, self::OWNED_TABLES));
        $this->assertSame([], $this->writesIn("EmployeeCategory::query()->forceCreate(['a' => 1]);", self::OWNED, self::OWNED_TABLES), 'a longer name is another model');
        $this->assertSame([], $this->writesIn('$service->update($a, Employee::query()->findOrFail($id));', self::OWNED, self::OWNED_TABLES), 'a read passed into a service is no write of it');
        $this->assertSame(['workforce_tasks'], $this->writesIn('DB::statement("INSERT INTO workforce_tasks (id) VALUES (1)");', self::OWNED, self::OWNED_TABLES));
    }

    #[Test]
    public function no_foreign_domain_writes_an_owned_table(): void
    {
        $scanned = 0;
        foreach ($this->sources() as $path => $source) {
            $scanned++;
            foreach (self::OWNING_PATHS as $owner) {
                if (str_starts_with($path, $owner)) {
                    continue 2;
                }
            }
            $this->assertSame([], $this->writesIn($source, self::OWNED, self::OWNED_TABLES), "{$path} writes an HR-owned table");
        }
        $this->assertGreaterThan(1000, $scanned, 'the scan reached the application');
    }

    /** HR.R1-C24 (WO-3, D-2): Payroll's own code. Tables Payroll may only read, whatever the runtime role holds (C23 §8). */
    private const PAYROLL_PATHS = ['Domain/Payroll/', 'Http/Controllers/App/Payroll/'];

    private const PAYROLL_READ_ONLY = ['employees', 'employment_records', 'employee_assignments', 'employee_account_links'];

    /**
     * A write through a variable holding an HR row. The model scan above cannot see `$record->update()`; inside
     * Payroll, a mutator on a variable named for an HR row is refused by name. A heuristic: a differently named
     * variable is still unseen, and a Payroll-owned row in a variable of one of these names would be refused too
     * (`HR-R1-C24-WO3-READER-MIGRATION.md` §5).
     */
    private function payrollWritesThroughAnHrVariable(string $source): bool
    {
        return preg_match('/\$(?:employmentRecord|employmentRecords|record|records|er|employee|employees|emp|link)\??->(?:update\w*|save\w*|delete\w*|forceDelete\w*|forceFill|fill|increment|decrement|touch|push|restore)\(/', $source) === 1;
    }

    #[Test]
    public function the_payroll_scan_really_sees_a_payroll_write(): void
    {
        // The negative controls: each of these, placed in a Payroll file, must fail the test below.
        $this->assertSame(['EmploymentRecord'], $this->writesIn("EmploymentRecord::query()->whereKey(\$id)->update(['status' => 'separated']);", self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertSame(['employees'], $this->writesIn("DB::table('employees')->where('id', \$id)->update(['full_name' => 'x']);", self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertSame(['employee_account_links'], $this->writesIn('DB::statement("UPDATE employee_account_links SET voided_at = now()");', self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertSame(['employment_records'], $this->writesIn('DB::delete("DELETE FROM public.employment_records WHERE id = ?", [$id]);', self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertTrue($this->payrollWritesThroughAnHrVariable("\$employmentRecord->forceFill(['ends_on' => \$d])->save();"));
        $this->assertTrue($this->payrollWritesThroughAnHrVariable('$record->delete();'));
        // And the accepted shapes: a read, the governed reader, the D2-X08 lock, and a Payroll-owned row.
        $this->assertSame([], $this->writesIn("EmploymentRecord::query()->where('id', \$id)->lockForUpdate()->firstOrFail();", self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertSame([], $this->writesIn('$this->roster->labels($school, $ids);', self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertSame([], $this->writesIn("PayrollRunResult::query()->create(['employee_id' => \$e]);", self::OWNED, self::PAYROLL_READ_ONLY));
        $this->assertFalse($this->payrollWritesThroughAnHrVariable("\$run->forceFill(['status' => 'approved'])->save();"));
    }

    #[Test]
    public function payroll_never_writes_an_hr_table(): void
    {
        // HR.R1-C24 (WO-3, D-2, C23 §1 D2-1): Payroll consumes HR facts and never writes them. The foreign-domain scan
        // above covers this too; it is pinned here by name, with the link history, because one runtime role serves
        // every domain and the database cannot tell a Payroll write from an HR one. The retention identity's
        // SECURITY DEFINER D2-F8 is a database function, not Payroll code, and runs only as `school_os_retention`.
        $scanned = 0;
        foreach ($this->sources() as $path => $source) {
            if (! str_starts_with($path, self::PAYROLL_PATHS[0]) && ! str_starts_with($path, self::PAYROLL_PATHS[1])) {
                continue;
            }
            $scanned++;
            $this->assertSame([], $this->writesIn($source, self::OWNED, [...self::OWNED_TABLES, ...self::PAYROLL_READ_ONLY]), "{$path} writes an HR table");
            $this->assertFalse($this->payrollWritesThroughAnHrVariable($source), "{$path} writes through a variable holding an HR row");
        }
        $this->assertGreaterThan(100, $scanned, 'the scan reached Payroll');
    }

    #[Test]
    public function only_the_step_up_routes_reach_the_generic_document_writer(): void
    {
        // HR.R1-C11 (§36): the HRC-3 step-up sits at HTTP, as rule 95 allows. `DocumentService` takes no proof, so this
        // boundary holds only while the controller stays its one caller. A job or command calling it needs its own answer.
        $callers = [];
        foreach ($this->sources() as $path => $source) {
            if (preg_match('/DocumentService::class\)->(?:create|archive)\(|\$this->documents->(?:create|archive)\(/', $source) === 1) {
                $callers[] = $path;
            }
        }

        $this->assertSame(['Domain/Documents/Http/Controllers/DocumentController.php'], $callers);
    }

    #[Test]
    public function no_controller_writes_an_owned_table_itself(): void
    {
        foreach ($this->sources() as $path => $source) {
            if (! str_contains($path, 'Http/Controllers')) {
                continue;
            }
            $this->assertSame([], $this->writesIn($source, self::OWNED, self::OWNED_TABLES), "{$path} writes an HR-owned table");
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function namedWriters(): array
    {
        $cases = [];
        foreach (self::NAMED_WRITERS as $model => $writers) {
            $cases[$model] = [$model, $writers];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('namedWriters')]
    public function a_table_with_several_writers_has_exactly_the_named_ones(string $model, array $writers): void
    {
        $found = [];
        foreach ($this->sources() as $path => $source) {
            if ($this->writesIn($source, [$model], [$this->tableOf($model)]) !== []) {
                $found[] = basename($path, '.php');
            }
        }
        sort($found);
        $expected = $writers;
        sort($expected);

        $this->assertSame($expected, array_values(array_unique($found)), "{$model}: a new writer needs a named operation here");
    }

    private function tableOf(string $model): string
    {
        return (new ('App\\Domain\\'.match ($model) {
            'WorkforceTask' => 'WorkforceTasks',
            'LeavePolicyAssignment' => 'Leave',
            default => 'HR',
        }.'\\Infrastructure\\'.$model))->getTable();
    }

    #[Test]
    public function the_writers_of_a_locked_row_stay_known(): void
    {
        foreach (self::WRITE_A_LOCKED_ROW as $file => $write) {
            $this->assertStringContainsString($write, (string) file_get_contents(app_path($file)), $file);
        }
    }

    #[Test]
    public function the_primary_correction_is_written_by_the_assignment_service(): void
    {
        // The ownership map used to name PlacementChangeService alone. Pinned so that the map and the code stay together.
        $this->assertStringContainsString('EmployeePlacementChange', (string) file_get_contents((new \ReflectionClass(EmployeeAssignmentService::class))->getFileName()));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function privileges(): array
    {
        return [
            'append-only history' => [['employment_terms', 'employee_placement_changes', 'employment_separation_events', 'workforce_task_events', 'leave_decisions', 'staff_attendance_corrections'], ['UPDATE', 'DELETE']],
            'lifecycle rows are never deleted' => [['employment_records', 'employment_separation_cases', 'separation_clearance_items', 'workforce_tasks', 'leave_requests', 'reporting_relationships', 'employee_documents', 'staff_attendance_records', 'leave_policy_assignments'], ['DELETE']],
            'the link history is read only' => [['employee_account_links'], ['INSERT', 'UPDATE', 'DELETE']],
        ];
    }

    #[Test]
    #[DataProvider('privileges')]
    public function the_database_denies_the_runtime_role_what_history_forbids(array $tables, array $denied): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        foreach ($tables as $table) {
            foreach ($denied as $privilege) {
                $this->assertFalse((bool) DB::selectOne('select has_table_privilege(current_user, ?, ?) as p', ["public.{$table}", $privilege])->p, "{$table}: the runtime role holds {$privilege}");
            }
        }
    }
}
