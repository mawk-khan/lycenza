<?php

namespace Tests\Unit\Ai;

use App\Models\School;
use App\Models\User;
use App\Support\Ai\AiContextTokenService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiContextTokenServiceTest extends TestCase
{
    private function fakeSchool(string $id): School
    {
        $school = new School;
        $school->id = $id;

        return $school;
    }

    private function fakeUser(string $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    #[Test]
    public function it_issues_a_token_verifiable_with_the_same_key(): void
    {
        $service = new AiContextTokenService('signing-key-a');
        $school = $this->fakeSchool('school-a-id');
        $actor = $this->fakeUser('actor-1');

        $token = $service->issue($school, $actor, ['school.settings.view'], 'req-1');
        $claims = $service->verify($token);

        $this->assertNotNull($claims);
        $this->assertSame('school-a-id', $claims->schoolId);
        $this->assertSame('actor-1', $claims->actorId);
        $this->assertTrue($claims->hasCapability('school.settings.view'));
        $this->assertFalse($claims->hasCapability('school.settings.manage'));
        $this->assertSame('req-1', $claims->requestId);
    }

    #[Test]
    public function it_rejects_a_token_signed_with_a_different_key(): void
    {
        $issuer = new AiContextTokenService('signing-key-a');
        $verifier = new AiContextTokenService('signing-key-b');

        $token = $issuer->issue($this->fakeSchool('school-a'), $this->fakeUser('actor-1'), ['x']);

        $this->assertNull($verifier->verify($token));
    }

    #[Test]
    public function it_rejects_a_tampered_payload_even_with_a_structurally_valid_signature(): void
    {
        $service = new AiContextTokenService('signing-key-a');
        $token = $service->issue($this->fakeSchool('school-a'), $this->fakeUser('actor-1'), ['x']);

        [$payloadB64, $signature] = explode('.', $token, 2);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

        // Attacker (e.g. a compromised services/ai) flips school_id but
        // cannot produce a valid signature without the key.
        $payload['school_id'] = 'school-b';
        $tamperedPayloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $tamperedToken = "{$tamperedPayloadB64}.{$signature}";

        $this->assertNull($service->verify($tamperedToken));
    }

    #[Test]
    public function it_rejects_an_expired_token(): void
    {
        $service = new AiContextTokenService('signing-key-a');

        $this->travelTo(now()->subMinutes(5));
        $token = $service->issue($this->fakeSchool('school-a'), $this->fakeUser('actor-1'), ['x']);
        $this->travelBack();

        $this->assertNull($service->verify($token));
    }

    #[Test]
    public function it_rejects_malformed_input(): void
    {
        $service = new AiContextTokenService('signing-key-a');

        $this->assertNull($service->verify(null));
        $this->assertNull($service->verify(''));
        $this->assertNull($service->verify('not-a-token'));
        $this->assertNull($service->verify('..'));
    }
}
