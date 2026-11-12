<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Support\Operations\DatabaseRoleVerifier;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * R1-01 (rule 151, `HR-R1-C25-R1-01-DECISION.md` §2), pinned executably for the whole HR family: every model of
 * the HR, Leave, Staff Attendance and WorkforceTasks domains (HR.R1-C26 widened it from 19 tables to all 56).
 *
 * - **Point 1:** no foreign domain writes a family table (Payroll included, by name).
 * - **Point 2:** no controller writes one itself.
 * - **Point 3:** every family table has exactly the writing classes `WRITERS` names; a second class is a new
 *   named operation or a duplicate writer, and either needs a decision. Payroll's `employee_esi_coverage` too.
 * - **Point 4:** the database denies the runtime role what history forbids, and every family table is
 *   classified in `DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES`, so a new table cannot keep the DELETE
 *   the schema's default privileges give it.
 *
 * Limits, honestly. A source scan sees static shapes only, with comments stripped: `Model::...->verb(`,
 * `new Model` in any form, `DB::table('literal')`, and raw SQL naming a table literally. It does not see
 * a write through a variable or a relation (`$row->update()`; `WRITE_A_LOCKED_ROW` lists the known ones),
 * `DB::table($name)` (how the retention services delete), a class or table name built at run time, or code
 * outside `app/`. The privilege checks are the runtime boundary, and one runtime role serves every domain:
 * PostgreSQL cannot tell which module wrote.
 */
class HrWriterBoundaryTest extends TestCase
{
    /** The domains whose Infrastructure models make up the HR family. */
    private const FAMILY_DOMAINS = ['HR', 'Leave', 'StaffAttendance', 'WorkforceTasks'];

    /** Domains that own these tables, and the platform mechanisms that may delete by retention. */
    private const OWNING_PATHS = ['Domain/HR/', 'Domain/Leave/', 'Domain/StaffAttendance/', 'Domain/WorkforceTasks/', 'Support/Retention/'];

    /**
     * R1-01 point 3, every family model and its writing classes (C25 §4, §5.2). One class is the rule. Several are
     * a named operation each (rules 130, 133, 139); the retention services are point 8; events written with their
     * parent operation are point 7. An empty list: no class writes it (a trigger does, or it is read only).
     */
    private const WRITERS = [
        // HR
        'Department' => ['DepartmentService'],
        // The retention service is point 8, listed because the scan sees it.
        'Employee' => ['EmployeeService', 'EmployeeRecordRetentionService'],
        // Trigger-only history (R6, rule 145); the runtime role reads it only.
        'EmployeeAccountLink' => [],
        'EmployeeAccountLinkCorrection' => ['EmployeeAccountLinkCorrectionService'],
        'EmployeeAddress' => ['EmployeeAddressService'],
        // Create, end, set primary; close at the employment's end; retention.
        'EmployeeAssignment' => ['EmployeeAssignmentService', 'EmploymentService', 'EmployeeRecordRetentionService'],
        'EmployeeCategory' => ['EmployeeCategoryService'],
        'EmployeeCertification' => ['EmployeeCertificationService'],
        'EmployeeDocument' => ['EmployeeDocumentService'],
        'EmployeeEmergencyContact' => ['EmployeeEmergencyContactService'],
        'EmployeeExperience' => ['EmployeeExperienceService'],
        'EmployeeNote' => ['EmployeeNoteService'],
        'EmployeePersonalDetail' => ['EmployeePersonalDetailService'],
        // Named moves; initial placement and primary correction (R1-01-3).
        'EmployeePlacementChange' => ['PlacementChangeService', 'EmployeeAssignmentService'],
        'EmployeeQualification' => ['EmployeeQualificationService'],
        'EmployeeReferenceCode' => ['EmployeeReferenceCodeService'],
        'EmploymentRecord' => ['EmploymentService', 'EmployeeRecordRetentionService'],
        'EmploymentSeparationCase' => ['SeparationCaseService'],
        'EmploymentSeparationEvent' => ['SeparationCaseService'],
        'EmploymentTerm' => ['EmploymentTermsService'],
        'HrEmployeeNumberCounter' => ['EmployeeNumberAllocator'],
        'Position' => ['PositionService'],
        'ReportingRelationship' => ['ReportingRelationshipService'],
        'SeparationClearanceItem' => ['SeparationCaseService'],
        'SeparationClearanceRequirement' => ['SeparationClearanceRequirementService'],
        // Leave, including the staff calendar (rule 149)
        'LeaveAccrualRun' => ['LeaveAccrualService'],
        'LeaveAccrualRunItem' => ['LeaveAccrualService'],
        'LeaveAllocationRun' => ['LeaveLedgerService'],
        'LeaveDecision' => ['LeaveRequestService'],
        'LeaveEligibilityRule' => ['LeavePolicyService'],
        'LeaveEligibilityRun' => ['LeaveEligibilityService'],
        'LeaveLedgerEntry' => ['LeaveLedgerService'],
        'LeavePolicy' => ['LeavePolicyService'],
        // Per request; the eligibility run, which only adds (rule 133).
        'LeavePolicyAssignment' => ['LeavePolicyAssignmentService', 'LeaveEligibilityService'],
        'LeaveRequest' => ['LeaveRequestService'],
        'LeaveRequestDay' => ['LeaveRequestService'],
        'LeaveSetting' => ['LeaveYearService'],
        'LeaveType' => ['LeaveTypeService'],
        'LeaveYear' => ['LeaveYearService'],
        'LeaveYearClose' => ['LeaveYearCloseService'],
        'LeaveYearCloseItem' => ['LeaveYearCloseService'],
        'LeaveYearCloseReconciliation' => ['LeaveYearCloseService'],
        'LeaveYearStartChange' => ['LeaveYearService'],
        'StaffHoliday' => ['StaffCalendarService'],
        'StaffWorkingWeekVersion' => ['StaffCalendarService'],
        // Retired and read only since HR.R1-C22.
        'StaffWorkingWeekday' => [],
        // Staff Attendance (rule 147); an approved locked-month correction is applied by its trigger (§5.4).
        'StaffAttendanceCorrection' => ['StaffAttendanceService'],
        'StaffAttendanceLockedCorrection' => ['StaffAttendanceService'],
        'StaffAttendanceMonthLock' => ['StaffAttendanceService'],
        'StaffAttendanceRecord' => ['StaffAttendanceService'],
        // WorkforceTasks (rule 139)
        'TaskCategory' => ['TaskCatalogueService'],
        // Point 7: each operation appends its own events in its own transaction.
        'TaskEvent' => ['TaskAssignmentService', 'TaskGenerationService', 'TaskWorkflowService'],
        'TaskGenerationRun' => ['TaskGenerationService'],
        'TaskTemplate' => ['TaskCatalogueService'],
        'TaskTemplateVersion' => ['TaskCatalogueService'],
        // Generated occurrences; ad hoc tasks and reassignment. Transitions write through a locked row.
        'WorkforceTask' => ['TaskAssignmentService', 'TaskGenerationService'],
        // Payroll (HR.R1-C26, WO-12): the calculation decides a period's coverage once, automatically, when none
        // is recorded (insert only); the admin service records or corrects it until a result consumes it.
        // `PayrollEmployeeRetentionService` deletes it by retention (point 8), through `DB::table($table)`.
        'EmployeeEsiCoverage' => ['EmployeeEsiCoverageAdminService', 'StatutoryPayrollCalculationService'],
    ];

    /** How the family's tables are named; every table matching it is in the allowlist or below. */
    private const FAMILY_TABLE_NAMES = '^(leave_|staff_|employee|employment|workforce_|separation_|hr_|reporting_|positions$)';

    /** Tables named like the family's that other domains own: Payroll's per-employment statutory and pay data, Identity's invitations. */
    private const NOT_FAMILY_TABLES = [
        'employee_compensation_assignments', 'employee_esi_coverage', 'employee_pf_status', 'employee_statutory_identifiers',
        'employee_tax_profile', 'staff_account_invitation_roles', 'staff_account_invitations',
    ];

    /** Writer-register models outside the family's Infrastructure directories. */
    private const OTHER_MODELS = ['EmployeeEsiCoverage' => EmployeeEsiCoverage::class];

    /** @var array<string, class-string<Model>>|null */
    private static ?array $familyModels = null;

    /** @return array<string, class-string<Model>> short name => class, every model of the HR family */
    private static function familyModels(): array
    {
        if (self::$familyModels === null) {
            self::$familyModels = [];
            foreach (self::FAMILY_DOMAINS as $domain) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path("Domain/{$domain}"), FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Infrastructure/')) {
                        continue;
                    }
                    $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], str_replace(app_path().'/', '', $file->getPathname()));
                    if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                        self::$familyModels[class_basename($class)] = $class;
                    }
                }
            }
            ksort(self::$familyModels);
        }

        return self::$familyModels;
    }

    /** @return list<string> */
    private function familyNames(): array
    {
        return array_keys(self::familyModels());
    }

    /** @return list<string> */
    private function familyTables(): array
    {
        return array_values(array_map(fn (string $model) => $this->tableOf($model), $this->familyNames()));
    }

    /** These also write, through a row they locked, which no source scan can see from the model. Listed so they stay known. */
    private const WRITE_A_LOCKED_ROW = [
        'Domain/HR/Application/Workforce/PlacementChangeService.php' => "\$locked->forceFill(['ends_on'",
        'Domain/WorkforceTasks/Application/TaskWorkflowService.php' => '->forceFill(',
    ];

    /** @var array<string, string>|null path => source, comments stripped */
    private static ?array $sources = null;

    /** @return array<string, string> path => source under app/, comments stripped (prose is not code) */
    private function sources(): array
    {
        if (self::$sources === null) {
            self::$sources = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    self::$sources[str_replace(app_path().'/', '', $file->getPathname())] = self::withoutComments((string) file_get_contents($file->getPathname()));
                }
            }
        }

        return self::$sources;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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
                if (preg_match('/\b'.$model.'::[^;]*'.$verb.'|\bnew '.$model.'\b(?![\\\\\w])/', $statement) === 1) {
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
        $models = $this->familyNames();
        $tables = $this->familyTables();
        $this->assertSame(['EmploymentRecord'], $this->writesIn("EmploymentRecord::query()->where('a', 1)->update(['status' => 'x']);", $models, $tables));
        $this->assertSame(['leave_requests'], $this->writesIn("DB::table('leave_requests as r')->where('a', 1)->delete();", $models, $tables));
        $this->assertSame(['WorkforceTask'], $this->writesIn('$t = new WorkforceTask();', $models, $tables));
        $this->assertSame(['Employee'], $this->writesIn('$e = new Employee;', $models, $tables), 'new without parentheses');
        $this->assertSame(['StaffHoliday'], $this->writesIn("StaffHoliday::query()->whereKey(\$id)->update(['status' => 'withdrawn']);", $models, $tables), 'a table the C11 list did not hold');
        $this->assertSame(['hr_departments'], $this->writesIn('DB::delete("DELETE FROM public.hr_departments WHERE id = ?", [$id]);', $models, $tables));
        $this->assertSame([], $this->writesIn("EmploymentRecord::query()->where('a', 1)->first();", $models, $tables));
        $this->assertSame([], $this->writesIn("EmploymentRecordArchive::query()->forceCreate(['a' => 1]);", $models, $tables), 'a longer name is another model');
        $this->assertSame([], $this->writesIn('$service->update($a, Employee::query()->findOrFail($id));', $models, $tables), 'a read passed into a service is no write of it');
        $this->assertSame(['EmployeePersonalDetail'], $this->writesIn("\$d = (new EmployeePersonalDetail)->forceFill(['employee_id' => \$e])", $models, $tables), 'a constructed model, however it is wrapped');
        $this->assertSame(['workforce_tasks'], $this->writesIn('DB::statement("INSERT INTO workforce_tasks (id) VALUES (1)");', $models, $tables));
        $this->assertStringNotContainsString('ORG.7E-1', self::withoutComments("<?php\n/** ORG.7E-1: a new Employee */\nclass A {}"), 'comments are stripped before the scan');
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
            $this->assertSame([], $this->writesIn($source, $this->familyNames(), $this->familyTables()), "{$path} writes an HR-owned table");
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
        $mutator = '(?:update\w*|save\w*|delete\w*|forceDelete\w*|forceFill|fill|increment|decrement|touch|push|restore)\(';

        // HR.R1-C26 (C25-N1): and a write through an HR relation of a loaded row (`$result->employmentRecord->update()`).
        return preg_match('/\$(?:employmentRecord|employmentRecords|record|records|er|employee|employees|emp|link)\??->'.$mutator.'/', $source) === 1
            || preg_match('/->(?:employmentRecord|employee|employees|employeeAssignment|accountLinks?)(?:\(\))?\??->'.$mutator.'/', $source) === 1;
    }

    #[Test]
    public function the_payroll_scan_really_sees_a_payroll_write(): void
    {
        // The negative controls: each of these, placed in a Payroll file, must fail the test below.
        $this->assertSame(['EmploymentRecord'], $this->writesIn("EmploymentRecord::query()->whereKey(\$id)->update(['status' => 'separated']);", $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertSame(['employees'], $this->writesIn("DB::table('employees')->where('id', \$id)->update(['full_name' => 'x']);", $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertSame(['employee_account_links'], $this->writesIn('DB::statement("UPDATE employee_account_links SET voided_at = now()");', $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertSame(['employment_records'], $this->writesIn('DB::delete("DELETE FROM public.employment_records WHERE id = ?", [$id]);', $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertTrue($this->payrollWritesThroughAnHrVariable("\$employmentRecord->forceFill(['ends_on' => \$d])->save();"));
        $this->assertTrue($this->payrollWritesThroughAnHrVariable('$record->delete();'));
        $this->assertTrue($this->payrollWritesThroughAnHrVariable("\$result->employmentRecord->forceFill(['ends_on' => \$d])->save();"), 'a relation-chain write');
        $this->assertTrue($this->payrollWritesThroughAnHrVariable('$run->results()->first()->employee?->update([]);'), 'a relation-chain write, nullsafe');
        // And the accepted shapes: a read, the governed reader, the D2-X08 lock, and a Payroll-owned row.
        $this->assertSame([], $this->writesIn("EmploymentRecord::query()->where('id', \$id)->lockForUpdate()->firstOrFail();", $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertSame([], $this->writesIn('$this->roster->labels($school, $ids);', $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertSame([], $this->writesIn("PayrollRunResult::query()->create(['employee_id' => \$e]);", $this->familyNames(), self::PAYROLL_READ_ONLY));
        $this->assertFalse($this->payrollWritesThroughAnHrVariable("\$run->forceFill(['status' => 'approved'])->save();"));
        $this->assertFalse($this->payrollWritesThroughAnHrVariable('$name = $result->employmentRecord->employee?->full_name;'), 'a relation read is no write');
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
            $this->assertSame([], $this->writesIn($source, $this->familyNames(), [...$this->familyTables(), ...self::PAYROLL_READ_ONLY]), "{$path} writes an HR table");
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
            $this->assertSame([], $this->writesIn($source, $this->familyNames(), $this->familyTables()), "{$path} writes an HR-owned table");
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function writers(): array
    {
        $cases = [];
        foreach (self::WRITERS as $model => $writers) {
            $cases[$model] = [$model, $writers];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('writers')]
    public function every_family_table_has_exactly_its_named_writers(string $model, array $writers): void
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

        $this->assertSame($expected, array_values(array_unique($found)), "{$model}: a new writer needs a named operation here (R1-01 point 3)");
    }

    #[Test]
    public function the_writer_register_and_the_privilege_allowlist_cover_exactly_the_family(): void
    {
        // A new model of the family fails here until it has writers named and its runtime privileges classified.
        $this->assertCount(56, $this->familyNames(), 'the HR family as of HR.R1-C26');
        $register = array_keys(self::WRITERS);
        sort($register);
        $expected = [...$this->familyNames(), ...array_keys(self::OTHER_MODELS)];
        sort($expected);
        $this->assertSame($expected, $register, 'WRITERS names every family model, and only those plus OTHER_MODELS');

        $tables = $this->familyTables();
        sort($tables);
        $classified = array_keys(DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES);
        sort($classified);
        $this->assertSame($tables, $classified, 'every family table is classified in DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES');
        foreach (DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES as $table => $privileges) {
            $this->assertMatchesRegularExpression('/^S?I?U?$/', $privileges, "{$table}: no family table holds DELETE or TRUNCATE");
        }

        // A table without a model is not in the family list above. Every table named like the family's is either
        // classified or named here as another domain's, so a model-less family table cannot keep its default DELETE.
        $named = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename ~ ?", [self::FAMILY_TABLE_NAMES]))->pluck('tablename')->all();
        $unclassified = array_values(array_diff($named, $classified, self::NOT_FAMILY_TABLES));
        $this->assertSame([], $unclassified, 'a table named like the HR family is classified in the allowlist or named in NOT_FAMILY_TABLES');
        $this->assertSame([], array_values(array_diff(self::NOT_FAMILY_TABLES, $named)), 'NOT_FAMILY_TABLES names real tables only');
    }

    private function tableOf(string $model): string
    {
        $class = self::familyModels()[$model] ?? self::OTHER_MODELS[$model];

        return (new $class)->getTable();
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
        $this->assertStringContainsString('EmployeePlacementChange', (string) file_get_contents((new ReflectionClass(EmployeeAssignmentService::class))->getFileName()));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function privileges(): array
    {
        return [
            'append-only history' => [['employment_terms', 'employee_placement_changes', 'employment_separation_events', 'workforce_task_events', 'leave_decisions', 'staff_attendance_corrections'], ['UPDATE', 'DELETE']],
            'lifecycle rows are never deleted' => [['employment_records', 'employment_separation_cases', 'separation_clearance_items', 'workforce_tasks', 'leave_requests', 'reporting_relationships', 'employee_documents', 'staff_attendance_records', 'leave_policy_assignments'], ['DELETE']],
            'the link history is read only' => [['employee_account_links'], ['INSERT', 'UPDATE', 'DELETE']],
            // HR.R1-C26 (WO-11, C12-N2, R1-01 point 4): configuration is deactivated, never deleted.
            'configuration is never deleted' => [['hr_departments', 'positions', 'employee_categories', 'hr_employee_number_counters'], ['DELETE']],
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
