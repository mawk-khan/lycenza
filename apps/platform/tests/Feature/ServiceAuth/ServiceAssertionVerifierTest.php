<?php

namespace Tests\Feature\ServiceAuth;

use App\Support\ServiceAuth\Base64Url;
use App\Support\ServiceAuth\ServiceAssertionSigner;
use App\Support\ServiceAuth\ServiceAssertionVerifier;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceAuthenticationException;
use App\Support\ServiceAuth\ServiceKeyConfigException;
use App\Support\ServiceAuth\ServiceKeyRing;
use App\Support\ServiceAuth\ServiceSigningKey;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GeneratesServiceKeys;
use Tests\TestCase;

/**
 * ADR 0053 (Phase 0O.7A): the Laravel receiver's assertion verification and
 * the platform signer, against runtime-generated keys. Time is controlled
 * with travelTo(); nothing sleeps.
 */
class ServiceAssertionVerifierTest extends TestCase
{
    use GeneratesServiceKeys;

    private const PATH = '/api/internal/ai/audit';

    private const BODY = '{"context_token":"t","agent":"a","action":"tool.invoke"}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /** @param  array<string, string>  $key */
    private function signer(array $key, string $issuer = ServiceAuthContract::AI_GATEWAY, string $audience = ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI, bool $enforceAge = true): ServiceAssertionSigner
    {
        return new ServiceAssertionSigner(ServiceSigningKey::fromJwk($this->privateJwk($key), $this->now()), $issuer, $audience, $enforceAge);
    }

    /** @param  list<array<string, string>>  $public */
    private function verifier(array $public, bool $enforceAge = true): ServiceAssertionVerifier
    {
        return new ServiceAssertionVerifier(ServiceKeyRing::fromJson($this->ring($public), $this->now()), ServiceAuthContract::AI_GATEWAY, ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI, $enforceAge);
    }

    private function reason(ServiceAssertionVerifier $verifier, ?string $authorization, string $method = 'POST', string $path = self::PATH, string $query = '', string $body = self::BODY): string
    {
        try {
            $verifier->verify($authorization, $method, $path, $query, $body);
        } catch (ServiceAuthenticationException $e) {
            return $e->reason;
        }

        return 'accepted';
    }

    /**
     * A hand-built JWS with a VALID Ed25519 signature, for shape cases.
     *
     * @param  array<string, string>  $key
     */
    private function forge(array $key, string $header, array $claims): string
    {
        $input = Base64Url::encode($header).'.'.Base64Url::encode((string) json_encode($claims));
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair((string) Base64Url::decode($key['d'])));

        return 'Lycenza-Service '.$input.'.'.Base64Url::encode(sodium_crypto_sign_detached($input, $secret));
    }

    /** @return array<string, mixed> */
    private function claims(array $overrides = []): array
    {
        $iat = $this->now()->getTimestamp();

        return array_filter([
            'ver' => 1, 'iss' => 'ai-gateway', 'sub' => 'ai-gateway', 'aud' => ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI,
            'iat' => $iat, 'nbf' => $iat, 'exp' => $iat + 60, 'jti' => Base64Url::encode(random_bytes(16)),
            'htm' => 'POST', 'htp' => self::PATH, 'bsh' => Base64Url::encode(hash('sha256', self::BODY, true)),
            ...$overrides,
        ], fn ($value) => $value !== null);
    }

    private function header(string $kid = 'ai-gateway-a', array $extra = []): string
    {
        return (string) json_encode(['alg' => 'EdDSA', 'typ' => 'lycenza-service+jwt', 'kid' => $kid, ...$extra]);
    }

    // --- format -------------------------------------------------------------------

    #[Test]
    public function a_fresh_assertion_verifies_and_carries_only_the_closed_claims(): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $signer = $this->signer($key);
        $first = $signer->authorization('POST', 'https://erp.internal'.self::PATH, self::BODY, 'req-1');
        $second = $signer->authorization('POST', 'https://erp.internal'.self::PATH, self::BODY);

        $this->assertNotSame($first, $second, 'a fresh assertion (jti) per request');
        $verified = $this->verifier([$this->publicJwk($key)])->verify($first, 'POST', self::PATH, '', self::BODY);
        $this->assertSame(['ai-gateway', 'ai-gateway-a'], [$verified->service, $verified->kid]);

        [$header, $payload] = explode('.', substr($first, strlen('Lycenza-Service ')));
        $this->assertSame(['alg' => 'EdDSA', 'typ' => 'lycenza-service+jwt', 'kid' => 'ai-gateway-a'], json_decode((string) Base64Url::decode($header), true));
        $claims = json_decode((string) Base64Url::decode($payload), true);
        $this->assertSame(['ver', 'iss', 'sub', 'aud', 'iat', 'nbf', 'exp', 'jti', 'htm', 'htp', 'bsh', 'rid'], array_keys($claims));
        $this->assertSame(60, $claims['exp'] - $claims['iat']);
        foreach (['school_id', 'actor_id', 'user_id', 'capabilities', 'membership', 'elevation', 'context_token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $claims);
        }
    }

    /** @return array<string, array{string}> */
    public static function badHeaders(): array
    {
        return [
            'alg none' => ['{"alg":"none","typ":"lycenza-service+jwt","kid":"ai-gateway-a"}'],
            'alg HS256' => ['{"alg":"HS256","typ":"lycenza-service+jwt","kid":"ai-gateway-a"}'],
            'alg Ed25519' => ['{"alg":"Ed25519","typ":"lycenza-service+jwt","kid":"ai-gateway-a"}'],
            'typ JWT' => ['{"alg":"EdDSA","typ":"JWT","kid":"ai-gateway-a"}'],
            'missing kid' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt"}'],
            'jku' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt","kid":"ai-gateway-a","jku":"https://attacker.example/keys"}'],
            'x5u' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt","kid":"ai-gateway-a","x5u":"https://attacker.example/c"}'],
            'jwk' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt","kid":"ai-gateway-a","jwk":{"kty":"OKP"}}'],
            'crit' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt","kid":"ai-gateway-a","crit":["exp"]}'],
            'duplicate alg' => ['{"alg":"EdDSA","alg":"EdDSA","typ":"lycenza-service+jwt","kid":"ai-gateway-a"}'],
            'bad kid' => ['{"alg":"EdDSA","typ":"lycenza-service+jwt","kid":"AI Gateway!"}'],
        ];
    }

    #[Test]
    #[DataProvider('badHeaders')]
    public function the_header_is_exactly_alg_typ_kid(string $header): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $this->assertSame('malformed', $this->reason($this->verifier([$this->publicJwk($key)]), $this->forge($key, $header, $this->claims())));
    }

    #[Test]
    public function the_scheme_is_dedicated_and_the_token_bounded(): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $verifier = $this->verifier([$this->publicJwk($key)]);
        $token = $this->signer($key)->assertion('POST', 'https://erp.internal'.self::PATH, self::BODY);

        $this->assertSame('missing', $this->reason($verifier, null));
        $this->assertSame('missing', $this->reason($verifier, 'Bearer '.$token));
        $this->assertSame('missing', $this->reason($verifier, $token));
        $this->assertSame('malformed', $this->reason($verifier, 'Lycenza-Service '.$token.'='));
        $this->assertSame('malformed', $this->reason($verifier, 'Lycenza-Service  '.$token));
        $this->assertSame('malformed', $this->reason($verifier, 'Lycenza-Service '.str_repeat('a', 2049)));
        $this->assertSame('malformed', $this->reason($verifier, 'Lycenza-Service a.b'));
        $this->assertSame('accepted', $this->reason($verifier, 'lycenza-service '.$token));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badClaims(): array
    {
        return [
            'a School id' => [['school_id' => '0199aaaa-0000-7000-8000-000000000001'], 'malformed'],
            'unknown claim' => [['scope' => 'ai.audit.write'], 'malformed'],
            'missing jti' => [['jti' => null], 'malformed'],
            'ver 2' => [['ver' => 2], 'malformed'],
            'ver as bool' => [['ver' => true], 'malformed'],
            'iat as string' => [['iat' => '1'], 'malformed'],
            'sub differs from iss' => [['sub' => 'platform'], 'unknown_service'],
            'wrong issuer (platform)' => [['iss' => 'platform', 'sub' => 'platform'], 'unknown_service'],
            'uncataloged issuer' => [['iss' => 'lycenza', 'sub' => 'lycenza'], 'unknown_service'],
            'generic audience' => [['aud' => 'lycenza'], 'wrong_audience'],
            'the Gateway audience' => [['aud' => 'lycenza-ai-gateway'], 'wrong_audience'],
        ];
    }

    #[Test]
    #[DataProvider('badClaims')]
    public function the_claim_set_is_closed_and_bound_to_the_catalog(array $overrides, string $reason): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $this->assertSame($reason, $this->reason($this->verifier([$this->publicJwk($key)]), $this->forge($key, $this->header(), $this->claims($overrides))));
    }

    #[Test]
    public function time_rules(): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $verifier = $this->verifier([$this->publicJwk($key)]);
        $t = $this->now()->getTimestamp();
        $forge = fn (array $o) => $this->forge($key, $this->header(), $this->claims($o));

        $this->assertSame('lifetime_exceeded', $this->reason($verifier, $forge(['exp' => $t + 121])));
        $this->assertSame('not_yet_valid', $this->reason($verifier, $forge(['iat' => $t + 31, 'nbf' => $t + 31, 'exp' => $t + 91])));
        $this->assertSame('expired', $this->reason($verifier, $forge(['iat' => $t - 100, 'nbf' => $t - 100, 'exp' => $t - 30])));
        $this->assertSame('malformed', $this->reason($verifier, $forge(['nbf' => $t - 1])), 'nbf before iat');
        $this->assertSame('malformed', $this->reason($verifier, $forge(['exp' => $t])), 'exp not after nbf');
        $this->assertSame('accepted', $this->reason($verifier, $forge(['iat' => $t + 29, 'nbf' => $t + 29, 'exp' => $t + 89])), 'within the 30 s skew');
        $this->assertSame('accepted', $this->reason($verifier, $forge(['iat' => $t - 80, 'nbf' => $t - 80, 'exp' => $t - 20])), 'expired by less than the skew');

        $signed = $this->signer($key)->authorization('POST', 'https://erp.internal'.self::PATH, self::BODY);
        $this->travel(89)->seconds();
        $this->assertSame('accepted', $this->reason($verifier, $signed));
        $this->travel(1)->seconds();
        $this->assertSame('expired', $this->reason($verifier, $signed), 'exp (60 s) + skew (30 s)');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function mismatches(): array
    {
        return [
            'wrong method' => [['method' => 'PUT'], 'request_mismatch'],
            'wrong route' => [['path' => '/api/internal/ai/tools/school-echo'], 'request_mismatch'],
            'trailing slash' => [['path' => self::PATH.'/'], 'request_mismatch'],
            'percent-encoded path' => [['path' => '/api/internal/ai/%61udit'], 'request_mismatch'],
            'empty segment' => [['path' => '/api/internal//ai/audit'], 'request_mismatch'],
            'dot segment' => [['path' => '/api/internal/ai/./audit'], 'request_mismatch'],
            'query string' => [['query' => '?x=1'], 'request_mismatch'],
            'empty query' => [['query' => '?'], 'request_mismatch'],
            'changed body' => [['body' => self::BODY.' '], 'body_digest_mismatch'],
            'reserialized body' => [['body' => '{"context_token": "t", "agent": "a", "action": "tool.invoke"}'], 'body_digest_mismatch'],
            'empty body' => [['body' => ''], 'body_digest_mismatch'],
        ];
    }

    #[Test]
    #[DataProvider('mismatches')]
    public function the_assertion_is_bound_to_method_path_and_exact_body(array $request, string $reason): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $signed = $this->signer($key)->authorization('POST', 'https://erp.internal'.self::PATH, self::BODY);

        $this->assertSame($reason, $this->reason($this->verifier([$this->publicJwk($key)]), $signed,
            $request['method'] ?? 'POST', $request['path'] ?? self::PATH, $request['query'] ?? '', $request['body'] ?? self::BODY));
    }

    #[Test]
    public function an_empty_body_is_the_digest_of_empty_bytes_and_the_signer_refuses_ambiguous_targets(): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $signed = $this->signer($key)->authorization('POST', 'https://erp.internal'.self::PATH, '');
        $this->assertSame('accepted', $this->reason($this->verifier([$this->publicJwk($key)]), $signed, body: ''));

        foreach (['https://erp.internal/api/internal/ai/audit?x=1', 'https://erp.internal/api/internal/ai/audit/', 'https://erp.internal/api/internal/ai/%61udit', 'https://erp.internal/api//audit'] as $url) {
            try {
                $this->signer($key)->assertion('POST', $url, self::BODY);
                $this->fail("{$url} must be refused");
            } catch (ServiceKeyConfigException $e) {
                $this->assertSame('request_target_invalid', $e->violation);
            }
        }
    }

    // --- keys and rings -------------------------------------------------------------

    #[Test]
    public function signing_key_validation(): void
    {
        $key = $this->serviceKey('ai-gateway-a');
        $other = $this->serviceKey('ai-gateway-b');
        foreach ([
            'wrong curve' => $this->privateJwk($key, ['crv' => 'X25519']),
            'wrong type' => $this->privateJwk($key, ['kty' => 'EC']),
            'bad base64url' => $this->privateJwk($key, ['d' => 'not*base64url*at*all*not*base64url*at*all*x']),
            'wrong size' => $this->privateJwk($key, ['d' => substr($key['d'], 0, -2)]),
            'x of another key' => $this->privateJwk($key, ['x' => $other['x']]),
            'bad kid' => $this->privateJwk($key, ['kid' => 'Bad Kid']),
            'impossible date' => $this->privateJwk($key, ['created' => '2026-02-30']),
            'future date' => $this->privateJwk($key, ['created' => '2027-01-01']),
            'extra member' => (string) json_encode([...json_decode($this->privateJwk($key), true), 'use' => 'sig']),
            'duplicate member' => str_replace('"kty":"OKP"', '"kty":"OKP","kty":"OKP"', $this->privateJwk($key)),
        ] as $case => $jwk) {
            try {
                ServiceSigningKey::fromJwk($jwk, $this->now());
                $this->fail("{$case} must be refused");
            } catch (ServiceKeyConfigException $e) {
                $this->assertSame('signing_key_invalid', $e->violation, $case);
            }
        }
        $this->assertStringNotContainsString($key['d'], print_r(ServiceSigningKey::fromJwk($this->privateJwk($key), $this->now()), true), 'no key material in a dump');
    }

    #[Test]
    public function ring_validation(): void
    {
        [$a, $b, $c] = [$this->serviceKey('ai-gateway-a'), $this->serviceKey('ai-gateway-b'), $this->serviceKey('ai-gateway-c')];
        $soon = $this->now()->addHours(2)->format('Y-m-d\TH:i:s\Z');
        $exactly24h = $this->now()->addHours(24)->format('Y-m-d\TH:i:s\Z');
        $this->assertCount(2, ServiceKeyRing::fromJson($this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $exactly24h])]), $this->now())->keys());

        foreach ([
            'verification_keys_missing' => '[]',
            'verification_keys_invalid' => '{}',
            'verification_keys_too_many' => $this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $soon]), $this->publicJwk($c, ['not_after' => $soon])]),
            'verification_keys_duplicate_kid' => $this->ring([$this->publicJwk($a), $this->publicJwk($a, ['not_after' => $soon])]),
            'verification_keys_private_material' => $this->ring([[...$this->publicJwk($a), 'd' => $a['d']]]),
            'verification_key_transition_expired' => $this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $this->now()->subSecond()->format('Y-m-d\TH:i:s\Z')])]),
            'verification_key_transition_too_long' => $this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $this->now()->addHours(24)->addSecond()->format('Y-m-d\TH:i:s\Z')])]),
            'verification_keys_steady_key' => $this->ring([$this->publicJwk($a), $this->publicJwk($b)]),
        ] as $violation => $json) {
            try {
                ServiceKeyRing::fromJson($json, $this->now());
                $this->fail("{$violation} expected");
            } catch (ServiceKeyConfigException $e) {
                $this->assertSame($violation, $e->violation);
            }
        }
        foreach (['wrong curve' => ['crv' => 'X25519'], 'short key' => ['x' => substr($a['x'], 0, -1)], 'bad not_after' => ['not_after' => '2026-10-01 13:00:00']] as $case => $override) {
            try {
                ServiceKeyRing::fromJson($this->ring([$this->publicJwk($a, $override)]), $this->now());
                $this->fail($case);
            } catch (ServiceKeyConfigException $e) {
                $this->assertSame('verification_keys_invalid', $e->violation, $case);
            }
        }
        $duplicateMember = str_replace('"kty":"OKP"', '"kty":"OKP","kty":"OKP"', $this->ring([$this->publicJwk($a)]));
        $this->expectException(ServiceKeyConfigException::class);
        ServiceKeyRing::fromJson($duplicateMember, $this->now());
    }

    #[Test]
    public function unknown_kid_and_a_substituted_key_are_refused(): void
    {
        $a = $this->serviceKey('ai-gateway-a');
        $this->assertSame('unknown_kid', $this->reason($this->verifier([$this->publicJwk($a)]), $this->signer($this->serviceKey('ai-gateway-z'))->authorization('POST', 'https://x'.self::PATH, self::BODY)));
        $impostor = [...$this->serviceKey('ai-gateway-a'), 'kid' => 'ai-gateway-a'];
        $this->assertSame('bad_signature', $this->reason($this->verifier([$this->publicJwk($a)]), $this->signer($impostor)->authorization('POST', 'https://x'.self::PATH, self::BODY)));
    }

    #[Test]
    public function keys_older_than_90_days_neither_sign_nor_verify_in_production_mode(): void
    {
        $old = $this->serviceKey('ai-gateway-old', $this->now()->subDays(91)->toDateString());
        try {
            $this->signer($old)->assertion('POST', 'https://x'.self::PATH, self::BODY);
            $this->fail('an expired signing key must not sign');
        } catch (ServiceKeyConfigException $e) {
            $this->assertSame('signing_key_expired', $e->violation);
        }
        $lenient = $this->signer($old, enforceAge: false)->authorization('POST', 'https://x'.self::PATH, self::BODY);
        $this->assertSame('key_expired', $this->reason($this->verifier([$this->publicJwk($old)]), $lenient));
        $this->assertSame('accepted', $this->reason($this->verifier([$this->publicJwk($old)], enforceAge: false), $lenient), 'local/testing only');

        $exactly90 = $this->serviceKey('ai-gateway-90', $this->now()->subDays(90)->toDateString());
        $this->assertSame('accepted', $this->reason($this->verifier([$this->publicJwk($exactly90)]), $this->signer($exactly90)->authorization('POST', 'https://x'.self::PATH, self::BODY)));
    }

    // --- rotation ---------------------------------------------------------------------

    #[Test]
    public function routine_rotation_with_rollback_in_flight_assertions_and_automatic_expiry(): void
    {
        $old = $this->serviceKey('ai-gateway-20260801-1', '2026-09-01');
        $new = $this->serviceKey('ai-gateway-20261001-1', '2026-10-01');
        $call = fn (array $key, ServiceAssertionVerifier $verifier) => $this->reason($verifier, $this->signer($key)->authorization('POST', 'https://x'.self::PATH, self::BODY));

        // 1. steady: only the old key is trusted.
        $steady = $this->verifier([$this->publicJwk($old)]);
        $this->assertSame('accepted', $call($old, $steady));
        $this->assertSame('unknown_kid', $call($new, $steady));

        // 2. EVERY receiver replica stages the new key (transitional, <= 24 h) BEFORE
        //    any caller switches: there is never a moment without an accepted key.
        $staged = [$this->publicJwk($old), $this->publicJwk($new, ['not_after' => $this->now()->addHours(24)->format('Y-m-d\TH:i:s\Z')])];
        $replicas = [$this->verifier($staged), $this->verifier($staged), $this->verifier($staged)];
        $inFlight = $this->signer($old)->authorization('POST', 'https://x'.self::PATH, self::BODY);
        foreach ($replicas as $replica) {
            $this->assertSame('accepted', $call($old, $replica));
        }

        // 3. callers switch to the new key; the in-flight old assertion still verifies.
        foreach ($replicas as $replica) {
            $this->assertSame('accepted', $call($new, $replica));
        }
        $this->assertSame('accepted', $this->reason($replicas[0], $inFlight));

        // 4. planned-rotation rollback: callers go back to the still-trusted old key.
        foreach ($replicas as $replica) {
            $this->assertSame('accepted', $call($old, $replica));
        }

        // 5. promote: new steady, old transitional just long enough for in-flight calls.
        $this->travel(5)->minutes();
        $retiring = $this->verifier([$this->publicJwk($new), $this->publicJwk($old, ['not_after' => $this->now()->addMinutes(3)->format('Y-m-d\TH:i:s\Z')])]);
        $this->assertSame('accepted', $call($new, $retiring));
        $this->assertSame('accepted', $call($old, $retiring));
        $this->travel(3)->minutes();
        $this->assertSame('key_expired', $call($old, $retiring), 'not_after passed: refused although still configured');
        $this->assertSame('accepted', $call($new, $retiring));

        // 6. removed.
        $final = $this->verifier([$this->publicJwk($new)]);
        $this->assertSame('unknown_kid', $call($old, $final));
        $this->assertSame('accepted', $call($new, $final));
    }

    #[Test]
    public function emergency_revocation_removes_the_key_at_once_with_no_overlap(): void
    {
        $compromised = $this->serviceKey('ai-gateway-a');
        $replacement = $this->serviceKey('ai-gateway-b');
        $stolen = $this->signer($compromised)->authorization('POST', 'https://x'.self::PATH, self::BODY);
        $this->assertSame('accepted', $this->reason($this->verifier([$this->publicJwk($compromised)]), $stolen));

        // Restarted receiver: the compromised key removed, never kept as "previous".
        $after = $this->verifier([$this->publicJwk($replacement)]);
        $this->assertSame('unknown_kid', $this->reason($after, $stolen), 'refused at once, its exp notwithstanding');
        $this->assertSame('accepted', $this->reason($after, $this->signer($replacement)->authorization('POST', 'https://x'.self::PATH, self::BODY)));
    }
}
