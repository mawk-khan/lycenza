<?php

namespace Tests\Feature\HR;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-26: the C38-D3 Architecture exception, pinned (`docs/hr/HR-R1-C38-FORMAL-ASSESSMENT.md` §13).
 *
 * Outside HR's own code (`app/Domain/HR`, `app/Http/Controllers/App/HR`) and Payroll's (rule 150's register,
 * `PayrollHrBoundaryRegisterTest`), exactly these files may import an `App\Domain\HR\Infrastructure` model, and each
 * exactly the model named:
 * - the 19 of C38-D3 (Timetable 4, Transport 4, Visitor 4, Attendance 1, TeachingAssignments 6): `Employee`, read only;
 * - Documents' 3: `Employee`, the HR.R1-C10 owner-type port (no exception needed, recorded so it cannot grow);
 * - the HR family's 2 outside `Domain/HR` (WorkforceTasks' `TaskAssignmentService`, Leave's `LeavePageSupport`):
 *   `EmploymentRecord` (C27: family, not cross-domain).
 * A new importer, a removed one, or another HR Infrastructure model fails here: it is a C38-D3 re-review (an
 * Architecture decision), never an edit to make this pass.
 *
 * Writes: `HrWriterBoundaryTest::no_foreign_domain_writes_an_owned_table()` refuses a static or table write of any HR
 * table from these files. This register adds what that scan leaves out for the excepted files: a mutator called on any
 * name a file gives an Employee (typed parameters such as `Employee $teacher`, closure arguments, variables assigned
 * from `Employee::`, `@var`), on the models' Employee relations (`teacher`, `driver`, `hostEmployee`, `employee`), or
 * a static write on the model. Limits, honestly: an Employee passed through an untyped variable, a group `use {…}`
 * import, a fully qualified inline reference, or a write built at run time is not visible to a source scan; one runtime
 * role serves every domain, so the database cannot tell either (rule 150).
 */
class HrCrossDomainImportRegisterTest extends TestCase
{
    /** Paths relative to `app/`, with the HR Infrastructure classes each imports. */
    private const C38_D3 = [
        'Domain/Timetable/Application/TimetableScheduleService.php' => ['Employee'],
        'Domain/Timetable/Http/Controllers/TimetableEntryController.php' => ['Employee'],
        'Domain/Timetable/Infrastructure/TimetableEntry.php' => ['Employee'],
        'Http/Controllers/App/Timetable/TimetableEntryController.php' => ['Employee'],
        'Domain/Transport/Application/TransportRouteAssignmentService.php' => ['Employee'],
        'Domain/Transport/Http/Controllers/TransportRouteAssignmentController.php' => ['Employee'],
        'Domain/Transport/Infrastructure/TransportRouteAssignment.php' => ['Employee'],
        'Http/Controllers/App/TransportOperationsController.php' => ['Employee'],
        'Domain/Visitor/Application/VisitorVisitService.php' => ['Employee'],
        'Domain/Visitor/Http/Controllers/VisitorVisitController.php' => ['Employee'],
        'Domain/Visitor/Infrastructure/VisitorVisit.php' => ['Employee'],
        'Http/Controllers/App/VisitorVisitController.php' => ['Employee'],
        'Domain/Attendance/Infrastructure/AttendanceSession.php' => ['Employee'],
        'Domain/TeachingAssignments/Application/ElectiveTeachingAssignmentService.php' => ['Employee'],
        'Domain/TeachingAssignments/Application/TeachingAssignmentService.php' => ['Employee'],
        'Domain/TeachingAssignments/Infrastructure/ElectiveTeachingAssignment.php' => ['Employee'],
        'Domain/TeachingAssignments/Infrastructure/TeachingAssignment.php' => ['Employee'],
        'Http/Controllers/App/TeachingAssignments/ElectiveTeachingAssignmentController.php' => ['Employee'],
        'Http/Controllers/App/TeachingAssignments/TeachingAssignmentController.php' => ['Employee'],
    ];

    private const DOCUMENTS_PORT = [
        'Domain/Documents/Application/DocumentListingService.php' => ['Employee'],
        'Domain/Documents/Application/DocumentService.php' => ['Employee'],
        'Domain/Documents/Infrastructure/Document.php' => ['Employee'],
    ];

    private const HR_FAMILY_OUTSIDE_DOMAIN_HR = [
        'Domain/WorkforceTasks/Application/TaskAssignmentService.php' => ['EmploymentRecord'],
        'Http/Controllers/App/Leave/LeavePageSupport.php' => ['EmploymentRecord'],
    ];

    /** HR's own code and Payroll's, which rule 150's register pins. */
    private const OWN_PATHS = ['Domain/HR/', 'Http/Controllers/App/HR/', 'Domain/Payroll/', 'Http/Controllers/App/Payroll/'];

    /** The mutators of an Eloquent row or relation: calling one on an Employee is a write of it. */
    private const MUTATORS = 'save|saveQuietly|update\w*|delete|forceDelete|forceFill|fill|push|touch|increment|decrement|restore|associate|dissociate|create\w*|forceCreate';

    /** A write in a statement that starts at the Employee model: a static write, or a row fetched and then mutated. */
    private const STATIC_WRITE = '/\bEmployee::[^;]*?\b(?:create|forceCreate|insert\w*|update\w*|delete|destroy|forceDelete|upsert|firstOrCreate|updateOrCreate|updateOrInsert|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|restore)\(|\bnew Employee\b/';

    /**
     * Every name an excepted file gives an Employee: a typed parameter or closure argument (`Employee $teacher`,
     * `?Employee $host`, `fn (Employee $e)`), a variable assigned from the model (`$driver = Employee::query()...`),
     * an `@var Employee $x`, and any variable whose name contains "employee".
     *
     * @return list<string>
     */
    public static function employeeVariables(string $code): array
    {
        preg_match_all('/\??\bEmployee\s+\$(\w+)/', $code, $typed);
        preg_match_all('/\$(\w+)\s*=\s*[^;]*?\bEmployee::/', $code, $assigned);
        preg_match_all('/@var\s+\??Employee\s+\$(\w+)/', $code, $documented);
        preg_match_all('/\$(\w*employee\w*)\b/i', $code, $named);
        $names = array_values(array_unique([...$typed[1], ...$assigned[1], ...$documented[1], ...$named[1]]));
        sort($names);

        return $names;
    }

    /**
     * The relations a model of these files declares onto Employee (`belongsTo(Employee::class, ...)`), across all of
     * them, so `$entry->teacher->save()` or `$visit->hostEmployee()->update()` is seen from any file.
     *
     * @param  list<string>  $sources
     * @return list<string>
     */
    public static function employeeRelations(array $sources): array
    {
        $names = [];
        foreach ($sources as $code) {
            preg_match_all('/function\s+(\w+)\s*\(\s*\)[^{]*\{[^}]*?\b(?:belongsTo|hasOne|hasMany)\(\s*Employee::class/', $code, $m);
            array_push($names, ...$m[1]);
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> each Employee write a source shows, given its Employee names and the known relations */
    public static function employeeWrites(string $code, array $variables, array $relations): array
    {
        $hits = [];
        foreach ($variables as $name) {
            if (preg_match_all('/\$'.preg_quote($name, '/').'\b(?:\??->\w+(?:\([^()]*\))?)*?\??->(?:'.self::MUTATORS.')\(/', $code, $m)) {
                array_push($hits, ...$m[0]);
            }
        }
        foreach ($relations as $relation) {
            if (preg_match_all('/\??->'.preg_quote($relation, '/').'\b(?:\(\))?(?:\??->\w+(?:\([^()]*\))?)*?\??->(?:'.self::MUTATORS.')\(/', $code, $m)) {
                array_push($hits, ...$m[0]);
            }
        }
        if (preg_match_all(self::STATIC_WRITE, $code, $m)) {
            array_push($hits, ...$m[0]);
        }

        return $hits;
    }

    /** @return array<string, string> path relative to app/ => source */
    private function sources(): array
    {
        $root = app_path();
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> the HR Infrastructure classes a source imports */
    public static function hrInfrastructureImports(string $source): array
    {
        preg_match_all('/^use\s+App\\\\Domain\\\\HR\\\\Infrastructure\\\\(\w+)(?:\s+as\s+\w+)?;/m', $source, $m);
        $classes = array_values(array_unique($m[1]));
        sort($classes);

        return $classes;
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

    #[Test]
    public function the_scanner_sees_an_import_and_every_employee_write_shape(): void
    {
        $this->assertSame(['Employee'], self::hrInfrastructureImports("<?php\nuse App\\Domain\\HR\\Infrastructure\\Employee;\n"));
        $this->assertSame(['EmploymentRecord'], self::hrInfrastructureImports("<?php\nuse App\\Domain\\HR\\Infrastructure\\EmploymentRecord as Record;\n"));
        $this->assertSame([], self::hrInfrastructureImports("<?php\nuse App\\Domain\\HR\\Application\\EmploymentCoverage;\n"), 'an Application reader is not an Infrastructure import');

        $code = 'function f(Employee $teacher, ?Employee $host) { $driver = Employee::query()->findOrFail($id); fn (Employee $e) => $e->id; }';
        $this->assertSame(['driver', 'e', 'host', 'teacher'], self::employeeVariables($code));
        $this->assertSame(['hostEmployee', 'teacher'], self::employeeRelations([
            'public function teacher(): BelongsTo { return $this->belongsTo(Employee::class, \'teacher_id\'); }',
            'public function hostEmployee(): BelongsTo { return $this->belongsTo(Employee::class, \'host_employee_id\'); }',
        ]));
        $vars = ['driver', 'host', 'teacher'];
        $relations = ['hostEmployee', 'teacher'];
        foreach ([
            '$teacher->save();', '$host->update([]);', '$driver->forceFill([\'a\' => 1])->save();', '$entry->teacher->delete();',
            '$visit->hostEmployee()->update([]);', 'Employee::create([]);', 'Employee::query()->whereKey($id)->update([]);', 'Employee::destroy($id);',
            'Employee::updateOrCreate([], []);', '$e = new Employee();',
            '$host?->update([]);', '$entry->teacher?->update([]);', '$teacher->load(\'x\')->save();',
            'Employee::query()->findOrFail($id)->forceFill([])->save();', 'Employee::withTrashed()->find($id)->restore();',
        ] as $write) {
            $this->assertNotSame([], self::employeeWrites($write, $vars, $relations), "a write: {$write}");
        }
        foreach (['$teacher->full_name;', '$driver->id;', 'Employee::query()->findOrFail($id);', '$entry->teacher->full_name;', '$visit->hostEmployee?->full_name;', '$this->employees->paged();'] as $read) {
            $this->assertSame([], self::employeeWrites($read, $vars, $relations), "a read: {$read}");
        }
    }

    #[Test]
    public function exactly_the_recorded_files_import_hr_infrastructure_and_each_only_its_recorded_model(): void
    {
        $found = [];
        foreach ($this->sources() as $path => $source) {
            foreach (self::OWN_PATHS as $own) {
                if (str_starts_with($path, $own)) {
                    continue 2;
                }
            }
            $classes = self::hrInfrastructureImports($source);
            if ($classes !== []) {
                $found[$path] = $classes;
            }
        }
        ksort($found);
        $expected = [...self::C38_D3, ...self::DOCUMENTS_PORT, ...self::HR_FAMILY_OUTSIDE_DOMAIN_HR];
        ksort($expected);

        $this->assertSame($expected, $found, 'a new, removed or widened HR Infrastructure importer is a C38-D3 re-review (Architecture), never an edit here');
        $this->assertCount(19, self::C38_D3, 'C38-D3 excepts exactly 19 files');
    }

    #[Test]
    public function the_excepted_files_only_read_the_employee(): void
    {
        $paths = array_keys([...self::C38_D3, ...self::DOCUMENTS_PORT]);
        $raw = array_combine($paths, array_map(fn (string $p) => (string) file_get_contents(app_path($p)), $paths));
        $codes = array_map(fn (string $source) => self::withoutComments($source), $raw);
        $relations = self::employeeRelations($codes);
        $this->assertSame(['driver', 'employee', 'hostEmployee', 'teacher'], array_values(array_intersect(['driver', 'employee', 'hostEmployee', 'teacher'], $relations)), 'the scan found the models\' Employee relations');

        $writes = [];
        foreach ($codes as $path => $code) {
            // Names come from the raw source, so an `@var Employee $x` docblock still names $x; writes from the code alone.
            foreach (self::employeeWrites($code, self::employeeVariables($raw[$path]), $relations) as $hit) {
                $writes[] = "{$path} writes an Employee: {$hit}";
            }
        }
        $this->assertSame([], $writes, implode("\n", $writes));
        // The names these files actually give an Employee were seen, so the check is not blind to them.
        $this->assertContains('teacher', self::employeeVariables($codes['Domain/Timetable/Application/TimetableScheduleService.php']));
        $this->assertContains('driver', self::employeeVariables($codes['Domain/Transport/Application/TransportRouteAssignmentService.php']));
        $this->assertContains('host', self::employeeVariables($codes['Domain/Visitor/Application/VisitorVisitService.php']));
    }
}
