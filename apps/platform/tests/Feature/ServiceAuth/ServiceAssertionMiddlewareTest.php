<?php

namespace Tests\Feature\ServiceAuth;

use App\Http\Middleware\AuthenticateServiceAssertion;
use App\Http\Middleware\DevOnlySchoolHeaderResolver;
use App\Models\SchoolDomain;
use App\Support\Ai\AiContextTokenService;
use App\Support\Observability\LogSanitizer;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\Metrics\Series;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\SignsServiceAssertions;
use Tests\TestCase;

/**
 * ADR 0053 (Phase 0O.7A): Laravel's internal AI routes over HTTP -- only a
 * per-request `ai-gateway` assertion authenticates; nothing else does, and an
 * assertion authenticates nothing else. Service authentication never sets
 * School context and never replaces the context token or domain checks.
 */
class ServiceAssertionMiddlewareTest extends TestCase
{
    use CapturesStructuredLogs, CreatesTenancyFixtures, SignsServiceAssertions;

    private const ECHO = '/api/internal/ai/tools/school-echo';

    /** @var array{kid: string, created: string, x: string, d: string} */
    private array $gatewayKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewayKey = $this->serviceKey('test-ai-gateway-1');
        $this->useServiceKeys([$this->publicJwk($this->gatewayKey)]);
        app(MetricStore::class)->flush();
    }

    /** @return array{0: string, 1: string} [context token, School id] */
    private function context(): array
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        return [app(AiContextTokenService::class)->issue($school, $user, ['school.settings.view']), $school->id];
    }

    private function metric(string $outcome): float
    {
        $key = Series::key('lycenza_service_auth_total', ['direction' => 'gateway_to_platform', 'service' => $outcome === 'success' || $outcome === 'replayed' ? 'ai-gateway' : 'unknown', 'outcome' => $outcome]);

        return app(MetricStore::class)->all()[$key] ?? 0.0;
    }

    #[Test]
    public function a_valid_assertion_passes_and_the_context_token_still_decides(): void
    {
        [$token, $schoolId] = $this->context();

        $this->internalPost(self::ECHO, ['context_token' => $token], $this->gatewaySigner($this->gatewayKey))
            ->assertOk()->assertJsonPath('result.schoolId', $schoolId);

        // Service authentication is necessary, never sufficient (section 6.3):
        $this->internalPost(self::ECHO, ['context_token' => 'forged.token'], $this->gatewaySigner($this->gatewayKey))->assertUnauthorized();
        $this->travel(61)->seconds(); // the context token (60 s) expires; a fresh assertion does not help
        $this->internalPost(self::ECHO, ['context_token' => $token], $this->gatewaySigner($this->gatewayKey))->assertUnauthorized();
        // Three requests passed service authentication; two were then refused by the context token.
        $this->assertSame(3.0, $this->metric('success'));
    }

    #[Test]
    public function every_other_credential_is_refused_with_one_uniform_body(): void
    {
        [$token, $schoolId] = $this->context();
        [$user] = $this->createSchoolAdmin('school_admin');
        $assertion = $this->gatewaySigner($this->gatewayKey)->assertion('POST', 'http://localhost'.self::ECHO, (string) json_encode(['context_token' => $token]));

        foreach ([
            'none' => null,
            'X-Service-Token only' => null,
            'shared token as Bearer' => 'Bearer dev-local-only-token',
            'the assertion as Bearer' => 'Bearer '.$assertion,
            'Sanctum personal access token' => 'Bearer '.$user->createToken('device')->plainTextToken,
            'partner key shape' => 'Bearer lyc_pk_0123456789abcdef.secretsecretsecret',
            'metrics scrape token' => 'Bearer metrics-test-token-8e1f2a3b4c5d6e7f8091a2b3c4d5e6f7',
            'the AI context token as an assertion' => 'Lycenza-Service '.$token,
            'unsigned (alg none)' => 'Lycenza-Service eyJhbGciOiJub25lIn0.e30.',
        ] as $case => $authorization) {
            $headers = $case === 'X-Service-Token only' ? ['X-Service-Token' => 'dev-local-only-token'] : [];
            $this->internalPost(self::ECHO, ['context_token' => $token], $authorization, $headers)
                ->assertUnauthorized()->assertExactJson(['error' => ['code' => 'service_authentication_failed']]);
        }

        // A signed-in browser session authenticates nothing here either.
        $this->actingAs($user)->internalPost(self::ECHO, ['context_token' => $token], null)->assertUnauthorized();
        $this->assertFalse(app(TenantContext::class)->hasSchool());
        $this->assertNotSame('', $schoolId);
    }

    #[Test]
    public function an_assertion_authenticates_no_human_or_partner_api(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $assertion = fn (string $path, string $method = 'GET') => $this->gatewaySigner($this->gatewayKey)->authorization($method, 'http://localhost'.$path, '');

        $this->withHeader('Authorization', $assertion("/api/v1/schools/{$school->id}/context"))
            ->getJson("/api/v1/schools/{$school->id}/context")->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeader('Authorization', $assertion('/api/v1/partner/probe'))
            ->getJson('/api/v1/partner/probe')->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeader('Authorization', $assertion('/app'))->get('/app')->assertRedirect();
        $this->assertGuest('web');
    }

    #[Test]
    public function the_request_binding_holds_over_http(): void
    {
        [$token] = $this->context();
        $signer = $this->gatewaySigner($this->gatewayKey);
        $body = (string) json_encode(['context_token' => $token]);
        $signed = fn () => $signer->authorization('POST', 'http://localhost'.self::ECHO, $body);

        $this->internalPost(self::ECHO, $body.' ', $signed())->assertUnauthorized();                                  // changed body
        $this->internalPost('/api/internal/ai/audit', $body, $signed())->assertUnauthorized();                        // other route
        $this->internalPost(self::ECHO.'?school_id=x', $body, $signed())->assertUnauthorized();                       // query string
        $this->internalPost('/api/internal/ai/tools/school%2Decho', $body, $signed())->assertUnauthorized();          // encoded path
        $this->internalPost(self::ECHO, $body, $signed())->assertOk();                                               // exact
    }

    #[Test]
    public function a_replayed_assertion_is_refused_and_a_store_failure_fails_closed(): void
    {
        [$token] = $this->context();
        $body = (string) json_encode(['context_token' => $token]);
        $authorization = $this->gatewaySigner($this->gatewayKey)->authorization('POST', 'http://localhost'.self::ECHO, $body);

        $this->internalPost(self::ECHO, $body, $authorization)->assertOk();
        $this->internalPost(self::ECHO, $body, $authorization)->assertUnauthorized()->assertExactJson(['error' => ['code' => 'service_authentication_failed']]);
        $this->assertSame(1.0, $this->metric('replayed'));

        // The replay store is unreachable: refused, never "authenticated without replay protection".
        config(['services.ai_gateway.replay_store' => 'broken', 'cache.stores.broken' => ['driver' => 'redis', 'connection' => 'no-such-connection']]);
        $fresh = $this->gatewaySigner($this->gatewayKey)->authorization('POST', 'http://localhost'.self::ECHO, $body);
        $this->internalPost(self::ECHO, $body, $fresh)->assertUnauthorized();
    }

    #[Test]
    public function a_route_outside_the_closed_catalog_is_refused_to_an_authenticated_service(): void
    {
        Route::middleware('service-auth')->post('/api/internal/ai/uncataloged', fn () => 'reached')->name('api.internal.ai.uncataloged');
        Route::getRoutes()->refreshNameLookups();

        $this->internalPost('/api/internal/ai/uncataloged', ['x' => 1], $this->gatewaySigner($this->gatewayKey))
            ->assertForbidden()->assertExactJson(['error' => ['code' => 'service_not_authorized']]);
        $this->assertArrayNotHasKey('api.internal.ai.uncataloged', ServiceAuthContract::ROUTE_SCOPES);
    }

    #[Test]
    public function without_a_usable_ring_nothing_authenticates(): void
    {
        [$token] = $this->context();
        $this->useServiceKeys(null);

        $this->internalPost(self::ECHO, ['context_token' => $token], $this->gatewaySigner($this->gatewayKey))->assertUnauthorized();
    }

    #[Test]
    public function service_authentication_never_establishes_school_context(): void
    {
        [$token, $schoolId] = $this->context();
        $body = (string) json_encode(['school_id' => $schoolId, 'context_token' => $token]);
        $request = Request::create(self::ECHO, 'POST', server: [
            'HTTP_AUTHORIZATION' => $this->gatewaySigner($this->gatewayKey)->authorization('POST', 'http://localhost'.self::ECHO, $body),
            'HTTP_X_SCHOOL_ID' => $schoolId,
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
        $request->setRouteResolver(fn () => (new RoutingRoute('POST', 'api/internal/ai/tools/school-echo', []))->name('api.internal.ai.tools.school-echo'));

        $seen = null;
        $response = app(AuthenticateServiceAssertion::class)->handle($request, function (Request $passed) use (&$seen) {
            $seen = [app(TenantContext::class)->hasSchool(), $passed->attributes->all()];

            return response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([false, ['service_identity' => 'ai-gateway']], $seen, 'only the service name -- no School, actor or capability');
        $this->assertFalse(app(TenantContext::class)->hasSchool());
    }

    #[Test]
    public function neither_a_verified_school_domain_nor_a_school_header_sets_context_on_a_service_route(): void
    {
        // A probe under a cataloged route name, so it passes route authorization.
        Route::middleware(['api', 'service-auth'])->withoutMiddleware([DevOnlySchoolHeaderResolver::class])
            ->post('/api/internal/ai/probe', fn () => response()->json(['hasSchool' => app(TenantContext::class)->hasSchool()]))
            ->name('api.internal.ai.tools.school-echo');
        Route::getRoutes()->refreshNameLookups();
        [, $schoolId] = $this->context();
        SchoolDomain::query()->create(['school_id' => $schoolId, 'domain' => 'localhost', 'verified_at' => now()]);
        config(['tenancy.allow_dev_header_override' => true]);

        $this->internalPost('/api/internal/ai/probe', ['x' => 1], $this->gatewaySigner($this->gatewayKey), ['X-School-Id' => $schoolId])
            ->assertOk()->assertExactJson(['hasSchool' => false]);
    }

    #[Test]
    public function logs_carry_closed_codes_and_never_the_assertion(): void
    {
        $this->captureLogs();
        [$token] = $this->context();
        $body = (string) json_encode(['context_token' => $token]);
        $authorization = $this->gatewaySigner($this->gatewayKey)->authorization('POST', 'http://localhost'.self::ECHO, $body);

        $this->internalPost(self::ECHO, $body.'x', $authorization)->assertUnauthorized();
        $output = $this->capturedOutput();
        $this->assertStringContainsString('"event_code":"service_auth.failed"', $output);
        $this->assertStringContainsString('"outcome":"body_digest_mismatch"', $output);
        $this->assertStringContainsString('"kid":"test-ai-gateway-1"', $output);
        $jws = substr($authorization, strlen('Lycenza-Service '));
        foreach ([$jws, explode('.', $jws)[2], $this->gatewayKey['d'], $this->gatewayKey['x'], $token] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        // The sanitizer is the backstop for accidental logging of a header or key.
        $sanitized = json_encode(app(LogSanitizer::class)->sanitize([
            'note' => "sent {$authorization}",
            'raw' => $jws,
            'config' => $this->privateJwk($this->gatewayKey),
            'jti' => 'abc',
            'service_signing_key' => 'x',
        ]));
        foreach ([$jws, $this->gatewayKey['d']] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $sanitized);
        }
    }
}
