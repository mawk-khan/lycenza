<?php

namespace Tests\Feature\Ai;

use App\Support\Ai\AiGatewayAuthorizationException;
use App\Support\Ai\AiGatewayClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 28: outbound half of the AI tool boundary. Proves a context
 * token is minted ONLY after a real capability check passes, and that
 * an actor entitled only to School A can never cause a token to be
 * minted for School B (ADR 0023).
 */
class AiGatewayClientTest extends TestCase
{
    use CreatesTenancyFixtures;

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
        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Service-Token', 'dev-local-only-token')
                && ! $request->hasHeader('Authorization');
        });
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
}
