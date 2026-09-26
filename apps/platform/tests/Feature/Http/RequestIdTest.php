<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §7): an inbound X-Request-Id is honoured only when
 * it matches ^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$; anything else is
 * discarded (never truncated) and a fresh server UUID is used.
 */
class RequestIdTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'uuid' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301'],
            'ulid' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            'proxy style' => ['edge-1-67891233-abcdef012345678912345678'],
            'minimum length' => ['abcd1234'],
            'maximum length' => [str_repeat('a', 128)],
            'dots and colons' => ['svc.edge:req_0042-a'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'too short' => ['abc1234'],
            'too long' => [str_repeat('a', 129)],
            'very long' => ['canary-'.str_repeat('z', 5000)],
            'leading dash' => ['-abcdefgh'],
            'space' => ['abc defgh1'],
            'trailing newline' => ["abcdefgh1\n"],
            'crlf injection' => ["abcdefgh1\r\nX-Evil: 1"],
            'quote' => ['abcdefgh"1'],
            'unicode' => ['abcdefgh1é'],
            'slash' => ['abcd/efgh1'],
            'html' => ['<script>alert(1)</script>'],
            // `=` is outside the ADR class (e.g. AWS `Root=1-...` trace ids): replaced.
            'equals sign' => ['Root=1-67891233-abcdef012345678912345678'],
        ];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function a_conservative_identifier_is_honoured(string $id): void
    {
        $this->withHeaders(['X-Request-Id' => $id])->getJson('/api/v1/system/status')
            ->assertHeader('X-Request-Id', $id)
            ->assertJsonPath('meta.requestId', $id);
    }

    #[Test]
    #[DataProvider('refused')]
    public function anything_else_is_replaced_by_a_fresh_uuid(string $id): void
    {
        $response = $this->withHeaders(['X-Request-Id' => $id])->getJson('/api/v1/system/status');
        $echoed = (string) $response->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($echoed), 'a fresh server id');
        $this->assertNotSame($id, $echoed);
        $this->assertSame($echoed, $response->json('meta.requestId'));
        $this->assertStringNotContainsString(substr($id, 0, 8), $echoed, 'never a truncated or prefixed copy');
    }

    #[Test]
    public function the_pattern_is_the_adr_pattern(): void
    {
        $this->assertSame('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', AssignRequestId::PATTERN);
        $this->assertNull(AssignRequestId::acceptable(null));
        $this->assertNull(AssignRequestId::acceptable(''));
    }
}
