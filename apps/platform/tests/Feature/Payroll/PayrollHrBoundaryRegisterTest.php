<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\EmploymentRoster;
use App\Support\Tenancy\TenantContext;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C24 (WO-3): the D-2 register, pinned (`docs/hr/HR-R1-C23-D2-DECISION.md` §3, as shipped in §3.5's
 * 22 / 26 / 12 split, D2-N09 removed). Payroll reads HR through HR's readers; every other reference is a named
 * exception or a no-change site, and nothing else may appear.
 *
 * What it pins, per Payroll file, with comments stripped:
 * - the HR classes it imports (Application readers and Infrastructure models alike);
 * - how many times it names an HR model statically, type-hints one, eager-loads or filters an HR relation,
 *   names an HR table in a string, or calls an HR reader.
 * A new site changes a count and fails here; a moved one changes two. Either needs an Architecture decision
 * (D-2 re-review trigger, C23 §10) and this register updated in the same change.
 *
 * In the database: the 9 Payroll → HR foreign keys with their delete rule, and the 8 Payroll functions that read
 * HR tables with their security mode, `search_path` and the runtime role's EXECUTE.
 *
 * Since HR.R1-C26 (C25-N1) it also counts array-form eager loads (`with([...])`), HR relations reached through a
 * loaded row (`lazy`), and `new`, `instanceof` and return types naming an HR model (`type`).
 *
 * Writes are not decided here: `HrWriterBoundaryTest::payroll_never_writes_an_hr_table()` does that, and the
 * database denies what history forbids. Limits, honestly:
 * - the `lazy` count knows the relation names `employmentRecord`, `employee(s)` and `employeeAssignment`, from any
 *   receiver including `$this` (HR.R1-C28, C27-N3), except `$this->employees`, an injected service. It does not
 *   count `assignment`, Payroll's own `CompensationAssignmentValue` relation. An HR row reached through another
 *   relation name, or held in a plain variable, is not counted;
 * - the `type` count knows `HR_MODELS` only, and a parameter type is the `hint` count; a ternary's
 *   `: Employee::...` also counts as a type (it fails closed, never open);
 * - the array form is read up to its first `]`, so an HR relation named after a nested array inside it
 *   (`with(['run' => fn ($q) => $q->select(['id']), 'employmentRecord'])`) is not counted;
 * - a source scan cannot see a write or a reference built at run time (a table name assembled from parts, a
 *   model class held in a variable);
 * - the `sql` count knows the eight HR tables in HR_TABLES only: a raw read of another HR table (`departments`,
 *   `employee_categories`, ...) is not counted, though any HR model import is;
 * - the function pin finds Payroll functions by name (`payroll`, `compensation`) and by four HR tables, so a
 *   differently named function would escape it;
 * - the `reader` count matches the method name on any object, so an unrelated `->paged(` changes it too;
 * - one runtime role serves every domain, so PostgreSQL cannot tell a Payroll write from an HR one (C23 §8).
 * `HR-R1-C24-WO3-READER-MIGRATION.md` §5 records the same limits.
 */
class PayrollHrBoundaryRegisterTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const PAYROLL_PATHS = ['Domain/Payroll', 'Http/Controllers/App/Payroll'];

    private const HR_TABLES = 'employment_records|employees|employee_assignments|employee_account_links|employment_terms|employee_placement_changes|reporting_relationships|employment_separation_cases';

    private const HR_MODELS = 'EmploymentRecord|Employee|EmployeeAssignment|EmploymentTerm|ReportingRelationship';

    private const ROSTER = 'App\\Domain\\HR\\Application\\EmploymentRoster';

    private const RECORD = 'App\\Domain\\HR\\Infrastructure\\EmploymentRecord';

    /**
     * The register (C23 §3). Each entry names its ids; the counts are what the code holds today.
     *
     * @var array<string, array{ids: string, imports: list<string>, refs: array<string, int>}>
     */
    private const REGISTER = [
        // Governed readers: the 22 moves (§3.1), and the readers that were already compliant (§2).
        'Http/Controllers/App/Payroll/PayrollRunController.php' => ['ids' => 'R01-R03 labels(); X14, X15 id for a typed service', 'imports' => [self::ROSTER, self::RECORD], 'refs' => ['model' => 2, 'reader' => 3]],
        'Http/Controllers/App/Payroll/CompensationController.php' => ['ids' => 'R04 labels(); X09 search (its two name reads counted as lazy since C26); X16 id for assign()', 'imports' => [self::ROSTER, self::RECORD], 'refs' => ['model' => 2, 'hint' => 1, 'relation' => 2, 'lazy' => 2, 'reader' => 1]],
        'Http/Controllers/App/Payroll/Statutory/StatutoryEmployeeController.php' => ['ids' => 'R05 labels(); X10 search (its two name reads counted as lazy since C26)', 'imports' => [self::ROSTER, self::RECORD], 'refs' => ['model' => 1, 'hint' => 1, 'relation' => 2, 'lazy' => 2, 'reader' => 1]],
        'Domain/Payroll/Application/PayslipReadService.php' => ['ids' => 'R06 labels(); X11 own employments; ActingEmployeeResolver (compliant)', 'imports' => ['App\\Domain\\HR\\Application\\ActingEmployeeResolver', self::ROSTER, 'App\\Domain\\HR\\Application\\Exceptions\\ActingEmployeeUnavailableException', self::RECORD], 'refs' => ['model' => 1, 'reader' => 1]],
        'Domain/Payroll/Statutory/Application/Export/StatutoryEcrExportService.php' => ['ids' => 'R07 labels(), once per export', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Application/Export/StatutoryEsiContributionWorksheetExportService.php' => ['ids' => 'R08 labels(), once per export', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Application/Export/StatutoryTdsDraftStatementExportService.php' => ['ids' => 'R09 labels(), once per export', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Http/Controllers/RequiresSchoolEmployment.php' => ['ids' => 'R10-R18 span() !== null, one 404 (D2-3)', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Http/Controllers/StatutoryPfStatusController.php' => ['ids' => 'R10, R11', 'imports' => [], 'refs' => ['reader' => 2]],
        'Domain/Payroll/Statutory/Http/Controllers/StatutoryEsiCoverageController.php' => ['ids' => 'R12, R13', 'imports' => [], 'refs' => ['reader' => 2]],
        'Domain/Payroll/Statutory/Http/Controllers/StatutoryTaxProfileController.php' => ['ids' => 'R14, R15', 'imports' => [], 'refs' => ['reader' => 2]],
        'Domain/Payroll/Statutory/Http/Controllers/StatutoryIdentifierController.php' => ['ids' => 'R16-R18', 'imports' => [], 'refs' => ['reader' => 3]],
        'Domain/Payroll/Statutory/Application/Admin/EmployeePfStatusAdminService.php' => ['ids' => 'R19 span() !== null', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Application/Admin/EmployeeEsiCoverageAdminService.php' => ['ids' => 'R20 span() !== null', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Application/Admin/EmployeeTaxProfileAdminService.php' => ['ids' => 'R21 span() !== null', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Statutory/Application/Admin/StatutoryIdentifierAdminService.php' => ['ids' => 'R22 span() !== null', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],
        'Domain/Payroll/Application/PayrollApprovalReviewService.php' => ['ids' => 'compliant: EmploymentRoster', 'imports' => [self::ROSTER], 'refs' => ['reader' => 4]],
        'Domain/Payroll/Application/PayrollHrxInputReadService.php' => ['ids' => 'compliant: EmploymentRoster', 'imports' => [self::ROSTER], 'refs' => ['reader' => 1]],

        // Narrow exceptions (§3.2): 26 after §3.5 (X24 left with the id-taking listAssignments()).
        'Domain/Payroll/Application/PayrollRunService.php' => ['ids' => 'X01, X02 findOrFail; X03-X06, X21, X22 type hints', 'imports' => [self::RECORD], 'refs' => ['model' => 2, 'hint' => 6]],
        'Domain/Payroll/Application/CompensationService.php' => ['ids' => 'X08 FOR UPDATE; X07, X23 type hints', 'imports' => [self::RECORD], 'refs' => ['model' => 1, 'hint' => 2]],
        'Domain/Payroll/Application/PayrollPostingIndependence.php' => ['ids' => 'X12, X13 SQL join', 'imports' => [], 'refs' => ['sql' => 2]],
        'Domain/Payroll/Application/PayrollCompensationAdministrationService.php' => ['ids' => 'X25 id for isEmployeeOf(); HrSelfAdministrationGuard (compliant)', 'imports' => ['App\\Domain\\HR\\Application\\HrSelfAdministrationGuard', self::RECORD], 'refs' => ['hint' => 1]],
        'Domain/Payroll/Application/PayrollRunAdministrationService.php' => ['ids' => 'X26, X27; HrSelfAdministrationGuard (compliant)', 'imports' => ['App\\Domain\\HR\\Application\\HrSelfAdministrationGuard', self::RECORD], 'refs' => ['hint' => 2]],
        'Domain/Payroll/Http/Controllers/PayrollRunController.php' => ['ids' => 'X17, X18', 'imports' => [self::RECORD], 'refs' => ['model' => 2]],
        'Domain/Payroll/Http/Controllers/CompensationAssignmentController.php' => ['ids' => 'X19 (keeps its 404), X20', 'imports' => [self::RECORD], 'refs' => ['model' => 2]],
        'Domain/Payroll/Application/PayrollSelfBeneficiaries.php' => ['ids' => 'A1 employee_account_links (rule 145)', 'imports' => [], 'refs' => ['sql' => 2]],

        // No change (§3.3): 12 after D2-N09 was removed.
        'Domain/Payroll/Infrastructure/EmployeeCompensationAssignment.php' => ['ids' => 'N01', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Infrastructure/PayrollRunResult.php' => ['ids' => 'N02 (N09 removed)', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Infrastructure/PayrollAdjustment.php' => ['ids' => 'N03', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Statutory/Infrastructure/EmployeePfStatus.php' => ['ids' => 'N04', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Statutory/Infrastructure/EmployeeEsiCoverage.php' => ['ids' => 'N05', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Statutory/Infrastructure/EmployeeTaxProfile.php' => ['ids' => 'N06', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Statutory/Infrastructure/EmployeeStatutoryIdentifier.php' => ['ids' => 'N07', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Statutory/Infrastructure/PayrollLwfAnnualCharge.php' => ['ids' => 'N08', 'imports' => [self::RECORD], 'refs' => ['model' => 1]],
        'Domain/Payroll/Application/Retention/PayrollEmployeeRetentionService.php' => ['ids' => 'N10-N12 retention identity (E21)', 'imports' => ['App\\Domain\\HR\\Application\\Retention\\EmployeeRetentionEligibility'], 'refs' => ['sql' => 9]],
        'Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php' => ['ids' => 'N13 retention identity (E21)', 'imports' => ['App\\Domain\\HR\\Application\\Retention\\EmployeeRetentionEligibility', 'App\\Domain\\HR\\Application\\Retention\\EmployeeSeparation'], 'refs' => ['sql' => 4]],
    ];

    /** D2-FK1 to FK9: Payroll table, HR table, delete rule (r = RESTRICT, c = CASCADE). All composite with `school_id`. */
    private const FOREIGN_KEYS = [
        ['employee_compensation_assignments', 'employment_records', 'r'],
        ['employee_esi_coverage', 'employment_records', 'c'],
        ['employee_pf_status', 'employment_records', 'c'],
        ['employee_statutory_identifiers', 'employment_records', 'c'],
        ['employee_tax_profile', 'employment_records', 'c'],
        ['payroll_adjustments', 'employment_records', 'r'],
        ['payroll_lwf_annual_charges', 'employment_records', 'r'],
        ['payroll_run_results', 'employees', 'r'],
        ['payroll_run_results', 'employment_records', 'r'],
    ];

    /** D2-F1 to F8: name => [SECURITY DEFINER, search_path, volatility, runtime role may EXECUTE]. */
    private const FUNCTIONS = [
        'compensation_assignments_reject_overlap' => [false, null, 'v', true],
        'payroll_reversal_affected_runs' => [false, 'pg_catalog, public, pg_temp', 's', true],
        'payroll_reversal_reconciliation_subjects' => [false, 'pg_catalog, public, pg_temp', 's', true],
        'payroll_run_first_paid_beneficiaries' => [false, 'pg_catalog, public, pg_temp', 's', true],
        'payroll_run_first_paid_compensation' => [false, 'pg_catalog, public, pg_temp', 's', true],
        'payroll_run_independence_exception' => [false, 'pg_catalog, public, pg_temp', 's', true],
        'retention_assert_payroll_employee_floor' => [false, 'pg_catalog, pg_temp', 'v', false],
        'retention_expire_payroll_employee_evidence' => [true, 'pg_catalog, pg_temp', 'v', false],
    ];

    /**
     * The HR references of one PHP source, comments stripped. Public for the scanner's own proofs below.
     *
     * @return array{imports: list<string>, refs: array<string, int>}
     */
    public static function fingerprint(string $source): array
    {
        $code = '';
        $strings = [];
        $imports = [];
        $inUse = false;
        $depth = 0;
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $inUse = $token === ';' ? false : $inUse;
                $depth += match ($token) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $code .= $token;

                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $inUse = $inUse || ($token[0] === T_USE && $depth === 0);
            if ($inUse && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_contains($token[1], '\\HR\\')) {
                $imports[] = ltrim($token[1], '\\');
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && ! preg_match('#^["\']?/#', $token[1])) {
                $strings[] = $token[1];
            }
            $code .= $token[1];
        }

        $refs = array_filter([
            'model' => preg_match_all('/\b(?:'.self::HR_MODELS.')::/', $code),
            'hint' => preg_match_all('/\b(?:'.self::HR_MODELS.')\s+\$/', $code),
            'relation' => preg_match_all("/->(?:with|whereHas|has|load|whereDoesntHave|orWhereHas)\\(\\s*[\'\"][\\w.]*(?:employee|employmentRecord)/", $code)
                // HR.R1-C26 (C25-N1): the array form, each HR relation named in it.
                + array_sum(array_map(
                    fn (string $list) => preg_match_all('/[\'"][\\w.]*(?:employee|employmentRecord)/', $list),
                    preg_match_all('/->(?:with|whereHas|has|load|whereDoesntHave|orWhereHas)\\(\\s*\\[([^\\]]*)\\]/', $code, $lists) > 0 ? $lists[1] : [],
                )),
            // HR.R1-C26 (C25-N1): an HR relation reached through a loaded row (`$result->employmentRecord->employee`).
            // HR.R1-C28 (C27-N3): `$this->employmentRecord->` inside a Payroll model counts too; only `$this->employees`,
            // the injected HR retention reader (`PayrollEmployeeRetentionService`), is a service and not a relation.
            'lazy' => preg_match_all('/(?:->(?:employmentRecord|employee|employeeAssignment)|(?<!\\$this)->employees)\\??(?=->)/', $code),
            // HR.R1-C26 (C25-N1): constructing, testing or returning an HR model.
            'type' => preg_match_all('/\\bnew\\s+(?:'.self::HR_MODELS.')\\b(?![\\\\\\w])|\\binstanceof\\s+(?:'.self::HR_MODELS.')\\b(?![\\\\\\w])|\\)\\s*:\\s*\\??(?:'.self::HR_MODELS.')\\b(?![\\\\\\w])/', $code),
            'sql' => array_sum(array_map(fn (string $s) => preg_match_all('/\b(?:'.self::HR_TABLES.')\b/', $s), $strings)),
            'reader' => preg_match_all('/->(?:labels|span|spans|paged|pageOverlapping|requireEmployment)\(/', $code),
            // A fully qualified HR name outside the imports is a reference the import list would not show.
            'fqn' => preg_match_all('/\\\\?App\\\\Domain\\\\HR\\\\/', (string) preg_replace('/^use [^;]+;$/m', '', $code)),
        ]);
        $imports = array_values(array_unique($imports));
        sort($imports);

        return ['imports' => $imports, 'refs' => $refs];
    }

    /** @return array<string, array{imports: list<string>, refs: array<string, int>}> */
    private function scan(): array
    {
        $found = [];
        foreach (self::PAYROLL_PATHS as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $print = self::fingerprint((string) file_get_contents($file->getPathname()));
                if ($print['imports'] !== [] || $print['refs'] !== []) {
                    $found[str_replace(app_path().'/', '', $file->getPathname())] = $print;
                }
            }
        }
        ksort($found);

        return $found;
    }

    #[Test]
    public function the_scanner_sees_what_it_pins_and_ignores_comments(): void
    {
        $this->assertSame(['model' => 1], self::fingerprint("<?php EmploymentRecord::query()->where('a', 1)->first();")['refs']);
        $this->assertSame(['model' => 1, 'fqn' => 1], self::fingerprint('<?php \\App\\Domain\\HR\\Infrastructure\\Employee::query()->get();')['refs']);
        $this->assertSame(['hint' => 1], self::fingerprint('<?php function f(Employee $e) {}')['refs']);
        $this->assertSame(['relation' => 1], self::fingerprint("<?php \$q->with('payrollRunResult.employee');")['refs']);
        $this->assertSame(['sql' => 1], self::fingerprint("<?php DB::table('employment_records')->count();")['refs']);
        $this->assertSame(['sql' => 1], self::fingerprint('<?php DB::select("SELECT 1 FROM employee_account_links");')['refs']);
        $this->assertSame(['reader' => 1], self::fingerprint('<?php $this->roster->labels($school, $ids);')['refs']);
        $this->assertSame(['App\\Domain\\HR\\Application\\EmploymentRoster'], self::fingerprint("<?php\nnamespace X;\nuse App\\Domain\\HR\\Application\\EmploymentRoster;\nclass Y {}")['imports']);
        $this->assertSame([], self::fingerprint("<?php\n// EmploymentRecord::query()->update([]);\n/** Employee \$e, employment_records */\nclass Z {}")['refs'], 'comments are not code');
        $this->assertSame([], self::fingerprint("<?php return redirect('/app/payroll/statutory/employees/1');")['refs'], 'a URL is not a table');
        $this->assertSame([], self::fingerprint('<?php EmployeeCategory::query()->get(); function g(EmployeeNumber $n) {}')['refs'], 'a longer name is another class');
        // HR.R1-C26 (C25-N1): the shapes the C24 scan did not count.
        $this->assertSame(['relation' => 2], self::fingerprint("<?php \$q->with(['run', 'employmentRecord.employee', 'payrollRunResult.employee']);")['refs'], 'array-form eager load');
        $this->assertSame(['relation' => 1], self::fingerprint('<?php $q->with(["run", "employmentRecord.employee"]);')['refs'], 'array-form eager load, double quotes');
        $this->assertSame(['lazy' => 2], self::fingerprint('<?php $n = $result->employmentRecord->employee?->full_name;')['refs'], 'lazy relation access');
        $this->assertSame([], self::fingerprint('<?php $this->employees->purgeSeparatedBefore($s, $d);')['refs'], 'an injected service is no relation');
        // HR.R1-C28 (C27-N3): a relation reached from inside a Payroll model.
        $this->assertSame(['lazy' => 2], self::fingerprint('<?php return $this->employmentRecord->employee?->full_name;')['refs'], 'a relation of $this is still a relation');
        $this->assertSame(['lazy' => 1], self::fingerprint('<?php $n = $this->employee->full_name;')['refs']);
        $this->assertSame(['type' => 3], self::fingerprint('<?php $e = new Employee(); if ($x instanceof EmploymentRecord) {} function h(): ?EmployeeAssignment {}')['refs'], 'new, instanceof and a return type');
        $this->assertSame([], self::fingerprint('<?php $r = new EmploymentRecordView(); function k(): EmployeeNumber {}')['refs'], 'a longer name is still another class');
    }

    #[Test]
    public function every_payroll_reference_to_hr_is_in_the_register(): void
    {
        $register = [];
        foreach (self::REGISTER as $path => $entry) {
            $imports = $entry['imports'];
            sort($imports);
            $register[$path] = ['imports' => $imports, 'refs' => $entry['refs']];
        }
        ksort($register);
        $found = $this->scan();

        $this->assertSame(array_keys($register), array_keys($found), 'a Payroll file gained or lost an HR reference: D-2 re-review (C23 §10)');
        foreach ($register as $path => $expected) {
            $this->assertSame($expected, $found[$path], "{$path} (".self::REGISTER[$path]['ids'].') differs from the register');
        }
    }

    #[Test]
    public function payroll_reads_hr_through_infrastructure_models_only_where_the_register_says(): void
    {
        // No Payroll file imports an HR model other than EmploymentRecord; the 22 moved sites hold none.
        foreach ($this->scan() as $path => $print) {
            foreach ($print['imports'] as $import) {
                if (str_contains($import, '\\Infrastructure\\')) {
                    $this->assertSame(self::RECORD, $import, $path);
                }
            }
        }
        foreach (['Domain/Payroll/Statutory/Application/Export', 'Domain/Payroll/Statutory/Application/Admin', 'Domain/Payroll/Statutory/Http/Controllers'] as $moved) {
            foreach ($this->scan() as $path => $print) {
                if (str_starts_with($path, $moved)) {
                    $this->assertNotContains(self::RECORD, $print['imports'], "{$path} moved to the reader (WO-3)");
                }
            }
        }
    }

    /** @return list<string> every table a Payroll model owns, and every `payroll_` table */
    private function payrollTables(): array
    {
        $tables = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Payroll'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Infrastructure/')) {
                continue;
            }
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], str_replace(app_path().'/', '', $file->getPathname()));
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $tables[] = (new $class)->getTable();
            }
        }
        foreach (DB::select("select tablename from pg_tables where schemaname = 'public' and tablename like 'payroll\\_%'") as $row) {
            $tables[] = $row->tablename;
        }

        return array_values(array_unique($tables));
    }

    #[Test]
    public function the_nine_foreign_keys_stand_as_approved(): void
    {
        $tables = $this->payrollTables();
        $this->assertContains('employee_compensation_assignments', $tables, 'the Payroll models were found');
        $rows = DB::select("
            select cl.relname as tbl, rf.relname as ref, c.confdeltype as del,
                   (select array_agg(a.attname::text order by a.attname) from pg_attribute a where a.attrelid = c.conrelid and a.attnum = any(c.conkey)) as cols
            from pg_constraint c
            join pg_class cl on cl.oid = c.conrelid
            join pg_class rf on rf.oid = c.confrelid
            join pg_namespace n on n.oid = cl.relnamespace and n.nspname = 'public'
            where c.contype = 'f' and rf.relname in ('employees', 'employment_records', 'employee_assignments', 'employee_account_links')
              and cl.relname = any (?::text[])
            order by 1, 2", ['{'.implode(',', $tables).'}']);

        $this->assertSame(self::FOREIGN_KEYS, array_map(fn ($r) => [$r->tbl, $r->ref, $r->del], $rows), 'D2-FK1 to FK9: none removed, none weakened, none added (rule 97, C23 §3.4)');
        foreach ($rows as $row) {
            $this->assertStringContainsString('school_id', $row->cols, "{$row->tbl} -> {$row->ref} is same-School (rule 70)");
        }
    }

    #[Test]
    public function the_runtime_role_cannot_reach_another_schools_employment_through_a_moved_site(): void
    {
        // C23 §11 raw-role proof: the readers the moved sites use add an explicit School filter, and forced RLS
        // answers the same without it. Another School's id is simply absent, as an unknown one is.
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $own = app(TenantContext::class)->withSchool($schoolA, fn () => $this->createEmploymentRecord($this->createEmployee($schoolA))->id);
        $foreign = app(TenantContext::class)->withSchool($schoolB, fn () => $this->createEmploymentRecord($this->createEmployee($schoolB))->id);
        $roster = app(EmploymentRoster::class);

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        app(TenantContext::class)->withSchool($schoolA, function () use ($own, $foreign) {
            $this->assertSame([$own], array_column(DB::select('select id from employment_records where id in (?, ?)', [$own, $foreign]), 'id'), 'RLS hides the other School, with no School filter in the SQL');
            $this->assertSame([], DB::select('select 1 from employees e join employment_records r on r.employee_id = e.id where r.id = ?', [$foreign]));
        });
        $this->assertNull($roster->span($schoolA, $foreign));
        $this->assertSame([], $roster->labels($schoolA, [$foreign]));
        $this->assertSame([$own], array_column($roster->labels($schoolA, [$own, $foreign]), 'employmentRecordId'));
        $this->assertNotNull($roster->span($schoolA, $own));
    }

    #[Test]
    public function the_eight_functions_keep_their_security_mode(): void
    {
        $rows = DB::select("
            select p.proname, p.prosecdef, array_to_string(p.proconfig, ';') as config, p.provolatile,
                   has_function_privilege('school_os_app', p.oid, 'EXECUTE') as app_executes
            from pg_proc p join pg_namespace n on n.oid = p.pronamespace and n.nspname = 'public'
            where p.prosrc ~ '\\m(employment_records|employees|employee_assignments|employee_account_links)\\M'
              and p.proname ~ '(payroll|compensation)'
            order by p.proname");

        $found = [];
        foreach ($rows as $row) {
            $found[$row->proname] = [(bool) $row->prosecdef, $row->config === null ? null : str_replace('search_path=', '', $row->config), $row->provolatile, (bool) $row->app_executes];
        }
        $this->assertSame(self::FUNCTIONS, $found, 'D2-F1 to F8 (C23 §3.4): a new Payroll function reading HR, or a changed mode, is a D-2 re-review');
        $this->assertSame(['retention_expire_payroll_employee_evidence'], array_keys(array_filter($found, fn (array $f) => $f[0])), 'D2-F8 is the one DEFINER (E21.3F)');
        $this->assertFalse($found['retention_expire_payroll_employee_evidence'][3], 'D2-F8 runs for the retention identity, never the runtime role');
    }
}
