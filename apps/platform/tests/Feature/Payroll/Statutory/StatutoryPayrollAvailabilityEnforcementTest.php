<?php

namespace Tests\Feature\Payroll\Statutory;

use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryRuleStatusReadService;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollUnavailableException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR-PG1/PG2 (rule 153): executable proof that statutory Payroll is refused outside
 * `local`/`testing` on every channel, before any protected work.
 *   - HTTP: all 29 statutory routes, for a fully capable actor, an actor with no
 *     capability and a platform root, with a valid, an unknown and another School's
 *     identifier: the same fixed 403, never cached, and NO query touches a Payroll,
 *     Employee, employment or statutory table before the refusal.
 *   - Services: all 20 public statutory operations invoked directly (as a command,
 *     worker or console would): refused with ZERO database queries, so nothing is read,
 *     calculated, posted, reversed, exported, revealed or audited.
 *   - In `testing` the same route and service still work (additive only).
 * The environment is switched with `$this->app['env']`, the established technique
 * (PortalArchitectureGuardTest); `UNSUPPORTED` values are proven at the boot policy
 * (StatutoryPayrollAvailabilityArchitectureTest).
 */
class StatutoryPayrollAvailabilityEnforcementTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const CAPABILITIES = ['payroll.statutory.view', 'payroll.statutory.manage', 'payroll.statutory.identifiers.view',
        'payroll.statutory.identifiers.manage', 'payroll.statutory.exports.generate'];

    /** A protected read or write: Payroll, Employee/employment, statutory, compensation or ledger data. */
    private const PROTECTED_TABLES = '/\b(payroll_\w+|employees?\w*|employment_\w+|employee_\w+|salary_\w+|journal_\w+|ledger_\w+|audit_events)\b/i';

    private School $a;

    private School $b;

    /** @var array<string, array{record: string, run: string, name: string}> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createSchool();
        $this->b = $this->createSchool();
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $school) {
            $name = 'Gate Person '.strtoupper($key).Str::random(6);
            $record = $this->createEmploymentRecord($this->createEmployee($school, ['full_name' => $name]), ['starts_on' => '2025-01-01']);
            $this->ids[$key] = ['record' => $record->id, 'run' => $this->draftRun($school)->id, 'name' => $name];
        }
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function draftRun(School $school): PayrollRun
    {
        $preparer = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);

        return app(TenantContext::class)->withSchool($school, function () use ($school, $preparer): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse('2026-09-01'), null, $preparer), $preparer);

            return app(PayrollRunAdministrationService::class)->createRun($period, $preparer);
        });
    }

    #[Test]
    public function every_statutory_route_refuses_in_production_before_any_protected_query_whatever_the_actor_or_identifier(): void
    {
        $actors = [
            'fully capable' => $this->createUserWithCapabilities($this->a, self::CAPABILITIES),
            'no capability' => $this->createUserWithCapabilities($this->a, []),
            'platform root' => $this->createPlatformRoot(),
        ];
        $variants = [
            'valid' => $this->ids['a'],
            'unknown' => ['record' => (string) Str::uuid(), 'run' => (string) Str::uuid(), 'name' => ''],
            'another School' => $this->ids['b'],
        ];
        $routes = $this->statutoryRoutes();
        $this->assertCount(29, $routes);
        $statutoryRowsBefore = $this->statutoryRowCounts();
        $requests = 0;
        $observed = 0;
        $inertiaVersion = (string) app(HandleInertiaRequests::class)->version(request());
        // HR-PG1/PG2 review (MAJOR): a web route is also called as JSON (the Statutory page's own employee
        // search sends Accept: application/json) and as an Inertia visit -- each must be the same fixed 403.
        $webChannels = [
            'browser' => [],
            'web json' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
            'inertia' => ['X-Inertia' => 'true', 'X-Inertia-Version' => $inertiaVersion, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'],
        ];

        foreach ($actors as $who => $actor) {
            $token = $actor->createToken('hr-pg1')->plainTextToken;
            $this->actingAs($actor)->post("/app/schools/{$this->a->id}/activate"); // a real session School (in testing)
            $this->app['auth']->forgetGuards();

            foreach ($routes as $name => [$method, $uri]) {
                $api = str_starts_with($uri, 'api/');
                foreach ($api ? ['api' => []] : $webChannels as $channel => $headers) {
                    $bodies = [];
                    foreach ($variants as $variant => $id) {
                        $url = '/'.strtr($uri, ['{school}' => $this->a->id, '{employmentRecord}' => $id['record'], '{payrollRun}' => $id['run'], '{identifierType}' => 'pan']);

                        $queries = [];
                        DB::listen(function ($query) use (&$queries): void {
                            $queries[] = $query->sql;
                        });
                        $this->app['env'] = 'production';
                        $this->withoutMiddleware(PreventRequestForgery::class);
                        $response = $api
                            ? $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $url, ['value' => 'X'])
                            : $this->sendWeb($actor, $channel, $headers, $method, $url);
                        $this->app['env'] = 'testing';
                        $this->flushHeaders();
                        $this->app['auth']->forgetGuards();
                        DB::flushQueryLog();
                        $this->app['events']->forget(QueryExecuted::class);
                        $requests++;

                        $label = "{$who} / {$name} / {$channel} / {$variant}";
                        if ($channel === 'web json') {
                            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), "{$label}: answered as JSON, so the JSON channel really ran");
                        } elseif (! $api) {
                            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'), "{$label}: answered as a page");
                            $this->assertStringContainsString(StatutoryPayrollUnavailableException::MESSAGE, (string) $response->getContent(), "{$label}: the fixed refusal, not some other 403");
                        }
                        $this->assertSame(403, $response->status(), "{$label}: refused");
                        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$label}: never cached");
                        $content = (string) $response->getContent();
                        foreach (array_merge(...array_map(fn ($v) => array_filter([$v['record'], $v['run'], $v['name']]), array_values($this->ids))) as $secret) {
                            $this->assertStringNotContainsString($secret, $content, "{$label}: discloses nothing");
                        }
                        $observed += count($queries);
                        foreach ($queries as $sql) {
                            $this->assertDoesNotMatchRegularExpression(self::PROTECTED_TABLES, $sql, "{$label}: a protected query ran before the refusal");
                        }
                        if ($api) {
                            $this->assertSame([StatutoryPayrollUnavailableException::CODE, StatutoryPayrollUnavailableException::MESSAGE, 403],
                                [$response->json('error.code'), $response->json('error.message'), $response->json('error.status')], $label);
                            $bodies[] = json_encode(array_diff_key((array) $response->json('error'), ['requestId' => true]));
                        } else {
                            if ($channel === 'web json') {
                                $this->assertSame(StatutoryPayrollUnavailableException::MESSAGE, $response->json('message'), $label);
                            }
                            $bodies[] = $content;
                        }
                    }
                    $this->assertCount(1, array_unique($bodies), "{$who} / {$name} / {$channel}: the refusal is identical for a valid, unknown or another School's identifier");
                }
            }
        }

        $this->assertSame((14 * 3 + 15) * 3 * 3, $requests);
        $this->assertGreaterThan(0, $observed, 'positive control: the listener did capture the (unprotected) authentication and School queries');
        $this->assertSame($statutoryRowsBefore, $this->statutoryRowCounts(), 'no statutory row was written');
    }

    #[Test]
    public function the_api_refusal_for_another_schools_url_is_the_same_fixed_refusal(): void
    {
        $actor = $this->createUserWithCapabilities($this->a, self::CAPABILITIES);
        $token = $actor->createToken('hr-pg1-foreign')->plainTextToken;
        $this->app['env'] = 'production';
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$this->b->id}/payroll-runs/{$this->ids['b']['run']}/statutory-exports/ecr");
        $this->app['env'] = 'testing';

        $response->assertStatus(403)->assertJsonPath('error.code', StatutoryPayrollUnavailableException::CODE);
    }

    #[Test]
    public function every_public_statutory_operation_refuses_directly_in_hardened_environments_with_no_database_access(): void
    {
        $actor = $this->createUserWithCapabilities($this->a, self::CAPABILITIES);
        $run = app(TenantContext::class)->withSchool($this->a, fn () => PayrollRun::query()->findOrFail($this->ids['a']['run']));
        $operations = 0;

        foreach (['staging', 'production'] as $environment) {
            foreach ($this->operations() as [$class, $method]) {
                $arguments = array_map(fn (\ReflectionParameter $p) => $this->argumentFor($p, $actor, $run), (new ReflectionMethod($class, $method))->getParameters());
                $service = app($class);
                $queries = 0;
                DB::listen(function () use (&$queries): void {
                    $queries++;
                });
                $this->app['env'] = $environment;
                try {
                    $service->{$method}(...$arguments);
                    $this->fail("{$environment}: {$class}::{$method} must refuse");
                } catch (StatutoryPayrollUnavailableException $e) {
                    $this->assertSame([403, StatutoryPayrollUnavailableException::CODE], [$e->getStatusCode(), $e->errorCode()]);
                } finally {
                    $this->app['env'] = 'testing';
                    $this->app['events']->forget(QueryExecuted::class);
                }
                $this->assertSame(0, $queries, "{$environment}: {$class}::{$method} touched the database before refusing");
                $operations++;
            }
        }
        $this->assertSame(40, $operations);

        // Positive control: the same listener does see a gated operation's queries in `testing`.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        app(TenantContext::class)->withSchool($this->a, fn () => app(StatutoryRuleStatusReadService::class)->activeRuleVersions($this->a, $actor));
        $this->app['events']->forget(QueryExecuted::class);
        $this->assertGreaterThan(0, $queries, 'positive control: the listener captures queries when the operation is available');
    }

    #[Test]
    public function in_testing_the_same_route_and_service_still_work(): void
    {
        $actor = $this->createUserWithCapabilities($this->a, self::CAPABILITIES);
        $token = $actor->createToken('hr-pg1-positive')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$this->a->id}/payroll-statutory/rule-status")->assertOk();
        $this->assertIsArray(app(TenantContext::class)->withSchool($this->a, fn () => app(StatutoryRuleStatusReadService::class)->activeRuleVersions($this->a, $actor)));
    }

    /**
     * Web requests send their headers through get()/post()/json(): a raw call() ignores withHeaders(), which would
     * silently turn every channel into a plain browser request.
     */
    private function sendWeb(User $actor, string $channel, array $headers, string $method, string $url): TestResponse
    {
        $this->actingAs($actor);
        $handled = null;
        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) use (&$handled): void {
            $handled = $event->request;
        });

        $response = match (true) {
            $channel === 'web json' => $this->json($method, $url, ['value' => 'X'], $headers),
            $method === 'GET' => $this->get($url, $headers),
            default => $this->post($url, ['value' => 'X'], $headers),
        };
        $this->app['events']->forget(RequestHandled::class);

        // The request the kernel actually handled carried the channel's headers (no channel silently degrades).
        $this->assertNotNull($handled, "{$channel}: the request was handled");
        foreach ($headers as $header => $value) {
            $this->assertSame($value, $handled->headers->get($header), "{$channel}: {$header} was sent");
        }

        return $response;
    }

    /** @return array<string, array{0: string, 1: string}> name => [method, uri] */
    private function statutoryRoutes(): array
    {
        $routes = [];
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $controller = $route->getControllerClass();
            if ($controller !== null && Str::startsWith($controller, ['App\\Http\\Controllers\\App\\Payroll\\Statutory\\', 'App\\Domain\\Payroll\\Statutory\\Http\\Controllers\\'])) {
                $routes[(string) $route->getName()] = [collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first(), $route->uri()];
            }
        }

        return $routes;
    }

    /** @return list<array{0: class-string, 1: string}> */
    private function operations(): array
    {
        $root = 'App\\Domain\\Payroll\\Statutory\\Application\\';
        $map = [
            'Admin\\EmployeeEsiCoverageAdminService' => ['correct', 'history'], 'Admin\\EmployeePfStatusAdminService' => ['configure', 'view'],
            'Admin\\EmployeeTaxProfileAdminService' => ['configure', 'history'], 'Admin\\StatutoryAccountingAdministrationService' => ['configure', 'view'],
            'Admin\\StatutoryIdentifierAdminService' => ['list', 'reveal', 'set'], 'Admin\\StatutoryRuleStatusReadService' => ['activeRuleVersions'],
            'Export\\StatutoryEcrExportService' => ['generate'], 'Export\\StatutoryEsiContributionWorksheetExportService' => ['generate'],
            'Export\\StatutoryTdsDraftStatementExportService' => ['generate'], 'StatutoryAccountingConfigurationService' => ['configure', 'resolveValidated'],
            'StatutoryPayrollCalculationService' => ['calculateForRun'], 'StatutoryPayrollPostingService' => ['post', 'reverse'],
        ];
        $operations = [];
        foreach ($map as $class => $methods) {
            foreach ($methods as $method) {
                $operations[] = [$root.$class, $method];
            }
        }

        return $operations;
    }

    private function argumentFor(\ReflectionParameter $parameter, User $actor, PayrollRun $run): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        return match (true) {
            $name === School::class => $this->a,
            $name === User::class => $actor,
            $name === PayrollRun::class => $run,
            $name === 'array' => ['value' => 'X'],
            $name === 'bool' => true,
            $name === 'string' && str_contains($parameter->getName(), 'employmentRecord') => $this->ids['a']['record'],
            $name === 'string' && str_contains(strtolower($parameter->getName()), 'date') || str_contains($parameter->getName(), 'period') || str_contains($parameter->getName(), 'fiscal') => '2026-04-01',
            $type?->allowsNull() => null,
            default => 'X',
        };
    }

    /** @return array<string, int> */
    private function statutoryRowCounts(): array
    {
        $counts = [];
        foreach (['employee_pf_status', 'employee_esi_coverage', 'employee_tax_profile', 'employee_statutory_identifiers',
            'payroll_statutory_calculation_results', 'payroll_statutory_run_postings', 'payroll_statutory_accounting_configurations'] as $table) {
            $counts[$table] = DB::connection('pgsql_admin')->table($table)->count();
        }

        return $counts;
    }
}
