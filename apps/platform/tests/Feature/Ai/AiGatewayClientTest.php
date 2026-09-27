<?php

namespace Tests\Feature\Ai;

use App\Support\Ai\AiContextTokenService;
use App\Support\Ai\AiGatewayAuthorizationException;
use App\Support\Ai\AiGatewayClient;
use App\Support\ServiceAuth\ServiceAssertionVerifier;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceKeyRing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\SignsServiceAssertions;
use Tests\TestCase;

/**
 * Section 28: outbound half of the AI tool boundary. Proves a context
 * token is minted ONLY after a real capability check passes, and that
 * an actor entitled only to School A can never cause a token to be
 * minted for School B (ADR 0023).
 */
class AiGatewayClientTest extends TestCase
{
    use CreatesTenancyFixtures, SignsServiceAssertions;

    /** @var array{kid: string, created: string, x: string, d: string} */
    private array $platformKey;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR 0053: Laravel signs with its own runtime-generated `platform` key.
        $this->platformKey = $this->serviceKey('test-platform-1');
        $this->useServiceKeys(platformKey: $this->platformKey, baseUrl: 'http://gateway.test');
    }

    /** What the Gateway does: verify the `platform` assertion over the exact request. */
    private function gatewayAccepts(Request $request, string $path): bool
    {
        $gateway = new ServiceAssertionVerifier(
            ServiceKeyRing::fromJson($this->ring([$this->publicJwk($this->platformKey)]), CarbonImmutable::now('UTC')),
            ServiceAuthContract::PLATFORM, ServiceAuthContract::AUDIENCE_AI_GATEWAY,
        );

        return $gateway->verify($request->header('Authorization')[0] ?? null, 'POST', $path, '', $request->body())->service === 'platform';
    }

    #[Test]
    public function it_mints_a_token_and_calls_the_gateway_when_the_actor_holds_the_capability(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        Http::fake([
            '*/v1/tools/invoke' => Http::response(['result' => ['schoolName' => $school->name]], 200),
        ]);

        $result = app(AiGatewayClient::class)->invokeTool(
            $user,
            $school,
            'school.settings.view',
            'phase0b-proof-agent',
            'school.echo',
        );

        $this->assertSame($school->name, $result['schoolName']);

        Http::assertSent(function ($request) use ($school) {
            return $request['school_id'] === $school->id
                && $request['tool'] === 'school.echo'
                && ! empty($request['context_token']);
        });

        // Real cross-process verification (docker containers, no
        // mocks) caught this exact header/body-shape pair diverging
        // from services/ai's actual contract twice during this
        // checkpoint -- assert both explicitly so a regression fails
        // here, not only in a live environment.
        // ADR 0053: a per-request `platform` assertion, verifiable by the
        // Gateway over these exact bytes; never the retired shared token.
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Service-Token')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Lycenza-Service ')
            && $this->gatewayAccepts($request, '/v1/tools/invoke'));
    }

    #[Test]
    public function an_empty_payload_is_sent_as_a_json_object_not_an_array(): void
    {
        // PHP's empty array json_encodes as `[]`; the AI Gateway's
        // Pydantic contract requires a JSON object (`{}`) even when
        // empty -- a real bug this checkpoint caught via a live,
        // unmocked round trip (see the Final Report).
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        Http::fake(['*/v1/tools/invoke' => Http::response(['result' => []], 200)]);

        app(AiGatewayClient::class)->invokeTool($user, $school, 'school.settings.view', 'phase0b-proof-agent', 'school.echo');

        Http::assertSent(fn ($request) => str_contains($request->body(), '"payload":{}'));
    }

    #[Test]
    public function it_refuses_to_mint_a_token_when_the_actor_lacks_the_capability_in_that_school(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        // No membership at all -- no capability.

        Http::fake();

        $this->expectException(AiGatewayAuthorizationException::class);

        try {
            app(AiGatewayClient::class)->invokeTool(
                $user,
                $school,
                'school.settings.view',
                'phase0b-proof-agent',
                'school.echo',
            );
        } finally {
            // The whole point: no HTTP call happens at all when
            // authorization fails -- no token is ever minted for an
            // unauthorized actor/School pair.
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function an_actor_entitled_only_to_school_a_cannot_obtain_a_token_for_school_b(): void
    {
        [$user, $schoolA] = $this->createSchoolAdmin('school_admin');
        $schoolB = $this->createSchool();
        // $user has NO membership in School B.

        Http::fake();

        $threw = false;
        try {
            app(AiGatewayClient::class)->invokeTool(
                $user,
                $schoolB,
                'school.settings.view',
                'phase0b-proof-agent',
                'school.echo',
            );
        } catch (AiGatewayAuthorizationException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected AiGatewayAuthorizationException for School B.');
        Http::assertNothingSent();

        // Sanity: the SAME actor legitimately can for School A.
        Http::fake(['*/v1/tools/invoke' => Http::response(['result' => []], 200)]);
        app(AiGatewayClient::class)->invokeTool($user, $schoolA, 'school.settings.view', 'phase0b-proof-agent', 'school.echo');
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_completion_mints_a_token_for_exactly_that_capability_and_sends_no_school_id(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        Http::fake(['*/v1/complete' => Http::response(['text' => 'ok', 'provider' => 'null', 'model' => 'null-echo-1'], 200)]);

        $result = app(AiGatewayClient::class)->complete($user, $school, 'school.settings.view', 'phase0b-proof-agent', 'hello');

        $this->assertSame(['text' => 'ok', 'provider' => 'null', 'model' => 'null-echo-1'], $result);
        Http::assertSent(function ($request) use ($school, $user) {
            $claims = app(AiContextTokenService::class)->verify($request['context_token']);

            return array_keys($request->data()) === ['agent', 'context_token', 'prompt']
                && $this->gatewayAccepts($request, '/v1/complete')
                && $claims?->schoolId === $school->id
                && $claims->actorId === $user->id
                && $claims->capabilities === ['school.settings.view'];
        });
    }

    #[Test]
    public function a_completion_is_refused_before_any_token_or_request_without_the_capability(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        Http::fake();

        try {
            app(AiGatewayClient::class)->complete($user, $school, 'school.settings.view', 'phase0b-proof-agent', 'hello');
            $this->fail('Expected refusal.');
        } catch (AiGatewayAuthorizationException) {
            Http::assertNothingSent();
        }
    }
}
