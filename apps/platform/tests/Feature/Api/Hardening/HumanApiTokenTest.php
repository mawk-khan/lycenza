<?php

namespace Tests\Feature\Api\Hardening;

use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Api\ApiScope;
use App\Support\Api\HumanApiTokenService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 section 2): human API tokens -- Sanctum, always
 * expiring, closed scopes, authority re-checked on every request, issued
 * only with a fresh MFA code from the person's own Account/Security page,
 * revoked effective on the next request, never platform/Group authority.
 */
class HumanApiTokenTest extends TestCase
{
    use CreatesMfaFixtures, CreatesTenancyFixtures;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** A fresh guard per request: tests reuse one application instance. */
    private function bearer(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @return array{0: User, 1: School, 2: list<string>} */
    private function adminWithMfa(): array
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->enrollActiveMfaFactor($user);

        return [$user, $school, $this->issueRecoveryCodes($user, 6)];
    }

    private function issueViaPage(User $user, string $code, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/app/account/api-tokens', [
            'name' => 'Nightly export',
            'scopes' => [ApiScope::READ],
            'mfa_code' => $code,
            ...$body,
        ]);
    }

    #[Test]
    public function create_token_is_safe_by_construction(): void
    {
        $user = $this->createUser();

        $token = $user->createToken('default')->accessToken;
        $this->assertSame([ApiScope::READ, ApiScope::WRITE], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $token->expires_at->timestamp, 5);

        foreach ([['*'], [], ['api.read', 'admin'], ['all']] as $abilities) {
            try {
                $user->createToken('bad', $abilities);
                $this->fail('A wildcard or unknown scope must be refused: '.json_encode($abilities));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([now()->addDays(91), now()->subMinute()] as $expiry) {
            try {
                $user->createToken('bad', [ApiScope::READ], $expiry);
                $this->fail('An expiry beyond 90 days or in the past must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(90, (int) round($user->createToken('max', [ApiScope::READ], now()->addDays(90))->accessToken->expires_at->diffInDays(now(), true)));
    }

    #[Test]
    public function issuing_needs_an_enrolled_factor_and_a_fresh_code_and_shows_the_token_once(): void
    {
        $noMfa = $this->createUser();
        $this->issueViaPage($noMfa, '123456')->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');

        [$user, $school, $codes] = $this->adminWithMfa();
        $this->issueViaPage($user, 'not-a-code')->assertStatus(422)->assertJsonPath('error.errors.mfa_code.0', 'That code is not valid.');
        $this->issueViaPage($user, $codes[0], ['lifetime_days' => 91])->assertStatus(422)->assertJsonPath('error.errors.lifetime_days.0', 'A token lasts between 1 and 90 days.');
        $this->issueViaPage($user, $codes[1], ['scopes' => ['*']])->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count());

        $response = $this->issueViaPage($user, $codes[2])->assertCreated();
        $plaintext = $response->json('token');
        $this->assertStringStartsWith($response->json('id').'|lyc_pat_', $plaintext);

        $row = PersonalAccessToken::query()->findOrFail($response->json('id'));
        $this->assertSame([ApiScope::READ], $row->abilities);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $row->expires_at->timestamp, 5);

        // The page lists metadata only -- never the value or its hash.
        $page = $this->actingAs($user)->get('/app/account/api-tokens')->assertOk();
        $this->assertStringNotContainsString(explode('|', $plaintext)[1], $page->getContent());
        $this->assertStringNotContainsString($row->token, $page->getContent());

        // Audit: identifiers and codes only.
        $event = PlatformAuditEvent::query()->where('event_type', HumanApiTokenService::ISSUED)->sole();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertEquals(['token_id' => (string) $row->id, 'scopes' => [ApiScope::READ], 'expires_at' => $row->expires_at->toIso8601String()], $event->metadata); // jsonb: key order is not kept
        foreach ([$plaintext, explode('|', $plaintext)[1], $row->token, 'Nightly export'] as $canary) {
            $this->assertStringNotContainsString($canary, json_encode($event->metadata));
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($canary, $line);
            }
        }

        // It works on the approved School route.
        $this->bearer($plaintext)->getJson("/api/v1/schools/{$school->id}/campuses")->assertOk();
    }

    #[Test]
    public function the_token_scope_is_enforced_without_implication(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $readOnly = $user->createToken('r', [ApiScope::READ])->plainTextToken;
        $writeOnly = $user->createToken('w', [ApiScope::WRITE])->plainTextToken;

        $body = ['name' => 'North Campus', 'code' => 'NORTH'];
        $this->bearer($readOnly)->withHeader('Idempotency-Key', 'k-'.bin2hex(random_bytes(6)))
            ->postJson("/api/v1/schools/{$school->id}/campuses", $body)
            ->assertForbidden()->assertJsonPath('error.code', 'API_SCOPE_INSUFFICIENT');

        $this->bearer($writeOnly)->getJson("/api/v1/schools/{$school->id}/campuses")
            ->assertForbidden()->assertJsonPath('error.code', 'API_SCOPE_INSUFFICIENT');

        $this->bearer($writeOnly)->withHeader('Idempotency-Key', 'k-'.bin2hex(random_bytes(6)))
            ->postJson("/api/v1/schools/{$school->id}/campuses", $body)->assertCreated();

        // A scope never grants a capability: a member without it is still refused.
        $member = $this->createUser();
        $this->createMembership($member, $school);
        $this->bearer($member->createToken('m')->plainTextToken)->withHeader('Idempotency-Key', 'k-'.bin2hex(random_bytes(6)))
            ->postJson("/api/v1/schools/{$school->id}/campuses", ['name' => 'South', 'code' => 'SOUTH'])
            ->assertForbidden()->assertJsonPath('error.code', null);
    }

    #[Test]
    public function expiry_revocation_disabling_and_legacy_rows_are_a_generic_401(): void
    {
        [$user, $school, $codes] = $this->adminWithMfa();
        $url = "/api/v1/schools/{$school->id}/campuses";

        $plaintext = $user->createToken('t', [ApiScope::READ])->plainTextToken;
        $this->bearer($plaintext)->getJson($url)->assertOk();

        // Expiry: usable on day 29, dead at 30 days.
        $this->travel(29)->days();
        $this->bearer($plaintext)->getJson($url)->assertOk();
        $this->travel(2)->days();
        $this->bearer($plaintext)->getJson($url)->assertUnauthorized()->assertJsonPath('error.message', 'Unauthenticated.');
        $this->travelBack();

        // Revocation from the Account page: the very next request fails.
        $fresh = $user->createToken('t2', [ApiScope::READ]);
        $this->bearer($fresh->plainTextToken)->getJson($url)->assertOk();
        $this->actingAs($user)->deleteJson('/app/account/api-tokens/'.$fresh->accessToken->id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->bearer($fresh->plainTextToken)->getJson($url)->assertUnauthorized();
        $this->assertSame(['token_id' => (string) $fresh->accessToken->id], PlatformAuditEvent::query()->where('event_type', HumanApiTokenService::REVOKED)->sole()->metadata);

        // Nobody can revoke someone else's token.
        $other = $this->createUser();
        $theirs = $other->createToken('x', [ApiScope::READ]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->deleteJson('/app/account/api-tokens/'.$theirs->accessToken->id)->assertStatus(422);
        $this->assertNotNull(PersonalAccessToken::query()->find($theirs->accessToken->id));

        // A disabled account's token no longer authenticates (it did before 0O.3).
        $live = $user->createToken('t3', [ApiScope::READ])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->bearer($live)->getJson($url)->assertOk();
        $user->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->bearer($live)->getJson($url)->assertUnauthorized()->assertJsonPath('error.message', 'Unauthenticated.');

        // Rows that could only predate 0O.3 (wildcard, or no expiry) never authenticate.
        $active = $this->createUser();
        $this->createMembership($active, $school);
        foreach ([['*'], [ApiScope::READ]] as $i => $abilities) {
            $secret = 'lyc_pat_legacy'.bin2hex(random_bytes(10));
            $id = DB::table('personal_access_tokens')->insertGetId([
                'tokenable_type' => User::class, 'tokenable_id' => $active->id, 'name' => 'legacy',
                'token' => hash('sha256', $secret), 'abilities' => json_encode($abilities),
                'expires_at' => $i === 0 ? now()->addDay() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->app['auth']->forgetGuards();
            $this->bearer($id.'|'.$secret)->getJson("/api/v1/schools/{$school->id}/context")->assertUnauthorized();
        }
    }

    #[Test]
    public function authority_is_rechecked_per_request_not_snapshotted(): void
    {
        [$user, $a] = $this->createSchoolAdmin('school_admin');
        $b = $this->createSchool();
        $this->assignSchoolRole($this->createMembership($user, $b), 'school_admin');
        $token = $user->createToken('t', [ApiScope::READ])->plainTextToken;

        $this->bearer($token)->getJson("/api/v1/schools/{$a->id}/context")->assertOk();
        $this->bearer($token)->getJson("/api/v1/schools/{$b->id}/context")->assertOk();

        // Membership in A ends: A is a non-disclosing 404, B still works.
        SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $a->id)->update(['status' => 'suspended']);
        $this->app['auth']->forgetGuards();
        $this->bearer($token)->getJson("/api/v1/schools/{$a->id}/context")->assertNotFound()->assertJsonPath('error.message', 'Not found.');
        $this->bearer($token)->getJson("/api/v1/schools/{$b->id}/context")->assertOk();

        // B is suspended: the same 404, no disclosure.
        $b->update(['status' => 'suspended']);
        $this->app['auth']->forgetGuards();
        $this->bearer($token)->getJson("/api/v1/schools/{$b->id}/context")->assertNotFound();
    }

    #[Test]
    public function a_bearer_token_never_carries_platform_or_group_authority(): void
    {
        $root = $this->createPlatformRoot();
        $resolver = app(CapabilityResolver::class);
        $this->assertTrue($resolver->canPlatform($root, 'platform.operations.view'));

        $token = $root->createToken('ops', [ApiScope::READ])->plainTextToken;
        $this->bearer($token)->getJson('/api/internal/operations/status')->assertForbidden();

        $root->withAccessToken(PersonalAccessToken::findToken($token));
        $this->assertTrue(CapabilityResolver::isBearerAuthenticated($root));
        $this->assertSame([], $resolver->platformCapabilities($root));
        $this->assertFalse($resolver->canPlatform($root, 'platform.role_grants.manage'));
        $this->assertCount(0, $resolver->groupsWith($root, 'group.schools.view'));
    }
}
