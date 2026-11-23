<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Application\StatutoryPayrollAvailability;
use App\Http\Middleware\EnsureStatutoryPayrollDevelopmentOnly;
use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Configuration\UnsupportedEnvironmentException;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HR-PG1/PG2 (ADR 0036 amendment, rule 153): statutory Payroll is available only in
 * `local`/`testing`. These guards pin WHERE that boundary is enforced so a new route,
 * service method, reader or caller cannot silently bypass it:
 *   - the gate is an allowlist of the development environments (never "not hardened");
 *   - every route of a statutory controller -- found by controller NAMESPACE, never by
 *     URL prefix -- carries the gate, and the gate resolves before RequireSchoolContext
 *     and the School-scoped binding, membership, capability, MFA, idempotency and
 *     throttling after it (only identity, session and School resolution run first);
 *   - every public operation of every statutory application service asserts the gate
 *     as its first statement;
 *   - statutory models and services are used outside the Statutory domain only by the
 *     named, reasoned readers and callers below (no command, job, listener or outbox
 *     consumer).
 * Executable denial is proven separately (StatutoryPayrollAvailabilityEnforcementTest).
 */
class StatutoryPayrollAvailabilityArchitectureTest extends TestCase
{
    private const CONTROLLER_NAMESPACES = [
        'App\\Http\\Controllers\\App\\Payroll\\Statutory\\',
        'App\\Domain\\Payroll\\Statutory\\Http\\Controllers\\',
    ];

    /** The exact statutory route inventory at HR-PG1/PG2 (14 web + 15 API). */
    private const ROUTES = [
        'app.payroll.statutory.index', 'app.payroll.statutory.employees.search', 'app.payroll.statutory.employees.show',
        'app.payroll.statutory.employees.identifiers.reveal', 'app.payroll.statutory.employees.pf-status.store',
        'app.payroll.statutory.employees.esi-coverage.store', 'app.payroll.statutory.employees.tax-profile.store',
        'app.payroll.statutory.employees.identifiers.store', 'app.payroll.statutory.accounting.show',
        'app.payroll.statutory.accounting.update', 'app.payroll.statutory.runs.exports.show',
        'app.payroll.statutory.runs.exports.ecr', 'app.payroll.statutory.runs.exports.esi-worksheet',
        'app.payroll.statutory.runs.exports.tds-draft-statement',
        'api.v1.schools.payroll-statutory.rule-status.show', 'api.v1.schools.payroll-statutory.pf-status.show',
        'api.v1.schools.payroll-statutory.pf-status.store', 'api.v1.schools.payroll-statutory.esi-coverage.index',
        'api.v1.schools.payroll-statutory.esi-coverage.store', 'api.v1.schools.payroll-statutory.tax-profile.index',
        'api.v1.schools.payroll-statutory.tax-profile.store', 'api.v1.schools.payroll-statutory.identifiers.index',
        'api.v1.schools.payroll-statutory.identifiers.reveal', 'api.v1.schools.payroll-statutory.identifiers.store',
        'api.v1.schools.payroll-statutory.accounting-configuration.show',
        'api.v1.schools.payroll-statutory.accounting-configuration.store',
        'api.v1.schools.payroll-runs.statutory-exports.ecr', 'api.v1.schools.payroll-runs.statutory-exports.esi-worksheet',
        'api.v1.schools.payroll-runs.statutory-exports.tds-draft-statement',
    ];

    /** Middleware aliases the gate must resolve before (whenever a route carries them). */
    private const AFTER_THE_GATE = ['school-context', 'school-membership', 'capability', 'mfa', 'mfa-page', 'idempotent', 'private-no-store'];

    /** The exact 12 services / 20 public operations (constructors excluded). */
    private const OPERATIONS = [
        'Admin\\EmployeeEsiCoverageAdminService' => ['correct', 'history'],
        'Admin\\EmployeePfStatusAdminService' => ['configure', 'view'],
        'Admin\\EmployeeTaxProfileAdminService' => ['configure', 'history'],
        'Admin\\StatutoryAccountingAdministrationService' => ['configure', 'view'],
        'Admin\\StatutoryIdentifierAdminService' => ['list', 'reveal', 'set'],
        'Admin\\StatutoryRuleStatusReadService' => ['activeRuleVersions'],
        'Export\\StatutoryEcrExportService' => ['generate'],
        'Export\\StatutoryEsiContributionWorksheetExportService' => ['generate'],
        'Export\\StatutoryTdsDraftStatementExportService' => ['generate'],
        'StatutoryAccountingConfigurationService' => ['configure', 'resolveValidated'],
        'StatutoryPayrollCalculationService' => ['calculateForRun'],
        'StatutoryPayrollPostingService' => ['post', 'reverse'],
    ];

    /**
     * Files outside app/Domain/Payroll/Statutory that import a statutory model, each with
     * its independent controlling boundary. Anything new needs an explicit disposition.
     */
    private const OUTSIDE_MODEL_READERS = [
        'app/Domain/Payroll/Application/PayslipReadService.php' => 'Payslip statutory section: HR-PG3 decides its unavailable behaviour (unchanged here)',
        'app/Domain/Payroll/Application/Retention/PayrollLedgerRetentionParticipant.php' => 'E21 retention: governed by retention holds and credentials, never by availability',
        'app/Http/Controllers/App/Payroll/Statutory/StatutoryEmployeeController.php' => 'route gate + assertAvailable() at the top of its direct-read actions',
        'database/seeders/StatutoryRuleVersionSeeder.php' => 'reference rule versions: data presence, no statutory processing',
    ];

    /**
     * Files outside the Statutory domain that name a statutory TABLE in code (not a comment): retention, erasure
     * and database verification only -- governed by E21, retention holds and the verifier, never by availability.
     */
    private const OUTSIDE_RAW_TABLE_READERS = [
        'app/Domain/Payroll/Application/Retention/PayrollEmployeeRetentionService.php' => 'E21 employee retention',
        'app/Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php' => 'E21 Payroll evidence retention',
        'app/Support/Operations/DatabaseRoleVerifier.php' => 'privilege/RLS metadata verification (no data rows)',
        'app/Support/Retention/Erasure/UserReferenceCatalog.php' => 'erasure reference catalogue',
        'app/Support/Retention/ReferencingRows.php' => 'retention reference check',
        'app/Support/Retention/RetentionAnchors.php' => 'retention anchors',
        'app/Support/Retention/TenantClosureReadiness.php' => 'tenant closure readiness',
        'app/Support/Retention/TenantRetentionCatalog.php' => 'tenant retention catalogue',
    ];

    private const STATUTORY_TABLES = '/\\b(employee_pf_status|employee_esi_coverage|employee_tax_profile|employee_statutory_identifiers|payroll_statutory_[a-z_]+|payroll_(pf|esi|professional_tax|lwf|income_tax)_rule[a-z_]*|payroll_lwf_annual_charges|payroll_salary_component_statutory_classifications)\\b/';

    /** Files outside the Statutory domain that import a statutory APPLICATION SERVICE (all route-gated web controllers). */
    private const OUTSIDE_SERVICE_CALLERS = [
        'app/Http/Controllers/App/Payroll/Statutory/StatutoryAccountingConfigurationController.php',
        'app/Http/Controllers/App/Payroll/Statutory/StatutoryController.php',
        'app/Http/Controllers/App/Payroll/Statutory/StatutoryEmployeeController.php',
        'app/Http/Controllers/App/Payroll/Statutory/StatutoryExportController.php',
    ];

    #[Test]
    public function the_gate_is_an_allowlist_of_exactly_the_development_environments(): void
    {
        $this->assertSame(['local', 'testing'], StatutoryPayrollAvailability::ENVIRONMENTS);
        $this->assertSame(EnvironmentPolicy::DEVELOPMENT, StatutoryPayrollAvailability::ENVIRONMENTS);

        try {
            foreach (['local' => true, 'testing' => true, 'staging' => false, 'production' => false, '' => false, 'qa' => false, 'Production' => false, 'local ' => false] as $environment => $available) {
                $this->app['env'] = (string) $environment;
                $this->assertSame($available, StatutoryPayrollAvailability::isAvailable(), "environment '{$environment}'");
            }
        } finally {
            $this->app['env'] = 'testing';
        }

        // An unsupported or unset value never even boots (AppServiceProvider refuses it).
        foreach (['', 'qa', 'Production'] as $unsupported) {
            try {
                EnvironmentPolicy::assertSupported($unsupported);
                $this->fail("'{$unsupported}' must be refused at boot");
            } catch (UnsupportedEnvironmentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['local', 'testing'], EnvironmentPolicy::DEVELOPMENT, 'the development allowlist itself is unchanged');
    }

    #[Test]
    public function every_statutory_route_is_found_by_namespace_and_carries_the_gate(): void
    {
        $routes = $this->statutoryRoutes();

        $this->assertEqualsCanonicalizing(self::ROUTES, array_keys($routes), 'the statutory route inventory changed: classify the route and keep it gated');
        $this->assertCount(14, array_filter(array_keys($routes), fn (string $name) => str_starts_with($name, 'app.')));
        $this->assertCount(15, array_filter(array_keys($routes), fn (string $name) => str_starts_with($name, 'api.')));

        foreach ($routes as $name => $route) {
            $this->assertContains('statutory-payroll-development-only', $route->gatherMiddleware(), "{$name} must carry the statutory availability gate");
        }
    }

    #[Test]
    public function the_gate_resolves_before_school_context_binding_authorization_mfa_idempotency_and_throttling(): void
    {
        $router = app(Router::class);
        $aliases = $router->getMiddleware();
        $after = array_values(array_filter(array_map(fn (string $alias) => $aliases[$alias] ?? null, self::AFTER_THE_GATE)));
        $after[] = SubstituteBindings::class;
        $after[] = ThrottleRequests::class;
        $after[] = ThrottleRequestsWithRedis::class;

        foreach ($this->statutoryRoutes() as $name => $route) {
            $sorted = array_map(fn ($m) => is_string($m) ? Str::before($m, ':') : get_class($m), $router->gatherRouteMiddleware($route));
            $gate = array_search(EnsureStatutoryPayrollDevelopmentOnly::class, $sorted, true);
            $this->assertIsInt($gate, "{$name}: the gate resolves");

            foreach ($sorted as $index => $middleware) {
                if (in_array($middleware, $after, true)) {
                    $this->assertGreaterThan($gate, $index, "{$name}: {$middleware} must run after the statutory availability gate");
                }
            }
            $this->assertContains(SubstituteBindings::class, $sorted, "{$name}: binding is present, and after the gate");
        }
    }

    #[Test]
    public function no_statutory_route_replays_an_idempotent_response_today(): void
    {
        foreach ($this->statutoryRoutes() as $name => $route) {
            $this->assertNotContains('idempotent', $route->gatherMiddleware(), "{$name}: if `idempotent` is added, the ordering test keeps the gate first");
        }
    }

    #[Test]
    public function every_public_statutory_service_operation_asserts_availability_first(): void
    {
        $root = app_path('Domain/Payroll/Statutory/Application');
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = Str::of($file->getPathname())->after($root.'/')->replace('/', '\\')->beforeLast('.php')->toString();
            if (str_starts_with($relative, 'Exceptions\\') || $relative === 'StatutoryPayrollAvailability') {
                continue;
            }
            $found[$relative] = true;
        }
        $this->assertEqualsCanonicalizing(array_keys(self::OPERATIONS), array_keys($found), 'a new class under Statutory/Application needs its operations classified and gated');

        $count = 0;
        foreach (self::OPERATIONS as $relative => $operations) {
            $class = 'App\\Domain\\Payroll\\Statutory\\Application\\'.$relative;
            $public = array_values(array_map(fn (ReflectionMethod $m) => $m->getName(), array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $class && ! $m->isConstructor(),
            )));
            $this->assertEqualsCanonicalizing($operations, $public, "{$relative}: every public operation is inventoried");

            foreach ($operations as $operation) {
                $this->assertSame('StatutoryPayrollAvailability::assertAvailable();', $this->firstStatement(new ReflectionMethod($class, $operation)), "{$relative}::{$operation} must assert availability first");
                $count++;
            }
        }
        $this->assertSame(20, $count);
    }

    #[Test]
    public function every_statutory_controller_also_declares_the_gate_itself(): void
    {
        $controllers = [];
        foreach (self::CONTROLLER_NAMESPACES as $namespace) {
            foreach ($this->phpFiles(app_path(Str::after(str_replace('\\', '/', $namespace), 'App/'))) as $file) {
                $class = $namespace.basename($file, '.php');
                if (class_exists($class)) {
                    $controllers[] = $class;
                }
            }
        }
        $this->assertCount(11, $controllers, '4 web + 7 API statutory controllers');

        foreach ($controllers as $class) {
            $this->assertTrue(is_subclass_of($class, HasMiddleware::class), "{$class} declares controller middleware");
            $declared = array_map(fn (Middleware $m) => $m->middleware, $class::middleware());
            $this->assertContains('statutory-payroll-development-only', $declared, "{$class}: any route to it is gated, whatever its route file says");
        }
    }

    #[Test]
    public function statutory_models_are_read_outside_the_domain_only_by_the_reasoned_readers(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::OUTSIDE_MODEL_READERS), $this->importersOutsideTheDomain('Infrastructure'));
        $this->assertSame([], $this->groupedStatutoryImportsOutsideTheDomain(), 'grouped statutory imports hide what is referenced');
    }

    #[Test]
    public function statutory_tables_are_named_in_code_outside_the_domain_only_by_retention_and_verification(): void
    {
        $files = [];
        foreach ($this->phpFiles(app_path()) as $file) {
            $relative = Str::after($file, base_path().'/');
            if (str_starts_with($relative, 'app/Domain/Payroll/Statutory/')) {
                continue;
            }
            foreach ($this->codeLines($file) as $line) {
                if (preg_match(self::STATUTORY_TABLES, $line)) {
                    $files[] = $relative;
                    break;
                }
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(self::OUTSIDE_RAW_TABLE_READERS), $files);
    }

    #[Test]
    public function statutory_services_are_called_outside_the_domain_only_by_gated_web_controllers_never_by_background_work(): void
    {
        $this->assertEqualsCanonicalizing(self::OUTSIDE_SERVICE_CALLERS, $this->importersOutsideTheDomain('Application'));

        foreach (['app/Console', 'app/Jobs', 'app/Listeners', 'app/Support/Events', 'routes/console.php'] as $background) {
            foreach ($this->phpFiles(base_path($background)) as $file) {
                $this->assertDoesNotMatchRegularExpression('/^use App\\\\Domain\\\\Payroll\\\\Statutory\\\\/m', (string) file_get_contents($file), "{$file}: background work never imports the Statutory domain");
            }
        }
    }

    /** @return array<string, Route> */
    private function statutoryRoutes(): array
    {
        $routes = [];
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $controller = $route->getControllerClass();
            if ($controller !== null && Str::startsWith($controller, self::CONTROLLER_NAMESPACES)) {
                $routes[(string) $route->getName()] = $route;
            }
        }

        return $routes;
    }

    private function firstStatement(ReflectionMethod $method): string
    {
        $lines = array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $body = Str::after(implode('', $lines), '{');
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*')) {
                continue;
            }

            return $line;
        }

        return '';
    }

    /** @return list<string> repository-relative files outside the Statutory domain importing App\Domain\Payroll\Statutory\{$layer}\... */
    private function importersOutsideTheDomain(string $layer): array
    {
        $files = [];
        foreach (['app', 'routes', 'database/seeders'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $file) {
                $relative = Str::after($file, base_path().'/');
                if (str_starts_with($relative, 'app/Domain/Payroll/Statutory/')) {
                    continue;
                }
                foreach ($this->codeLines($file) as $line) {
                    if (preg_match('/\\\\?App\\\\Domain\\\\Payroll\\\\Statutory\\\\'.$layer.'\\\\(?!Exceptions\\\\|StatutoryPayrollAvailability\\b)/', $line)) {
                        $files[] = $relative;
                        break;
                    }
                }
            }
        }

        return $files;
    }

    /** @return list<string> files outside the domain with a grouped `use App\Domain\Payroll\Statutory\{...}` import */
    private function groupedStatutoryImportsOutsideTheDomain(): array
    {
        $files = [];
        foreach (['app', 'routes', 'database/seeders'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $file) {
                $relative = Str::after($file, base_path().'/');
                if (! str_starts_with($relative, 'app/Domain/Payroll/Statutory/') && preg_match('/^use \\\\?App\\\\Domain\\\\Payroll\\\\Statutory\\\\[^;]*\\{/m', (string) file_get_contents($file))) {
                    $files[] = $relative;
                }
            }
        }

        return $files;
    }

    /** @return list<string> the file's lines that are not comments */
    private function codeLines(string $file): array
    {
        return array_values(array_filter(preg_split('/\R/', (string) file_get_contents($file)), function (string $line): bool {
            $line = ltrim($line);

            return $line !== '' && ! str_starts_with($line, '*') && ! str_starts_with($line, '//') && ! str_starts_with($line, '/*') && ! str_starts_with($line, '#');
        }));
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (! is_dir($path)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
