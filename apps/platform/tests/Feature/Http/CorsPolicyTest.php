<?php

namespace Tests\Feature\Http;

use App\Support\Http\CorsOriginList;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 section 10): deny-by-default CORS -- exact
 * configured origins only, never `*`, never credentials, a fixed method
 * and header list; malformed configuration fails loudly.
 */
class CorsPolicyTest extends TestCase
{
    private function preflight(string $origin): TestResponse
    {
        return $this->call('OPTIONS', '/api/v1/system/status', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);
    }

    #[Test]
    public function the_committed_policy_is_closed(): void
    {
        $cors = config('cors');
        $this->assertSame(['api/*'], $cors['paths']);
        $this->assertSame([], $cors['allowed_origins'], 'Empty by default: no cross-origin access.');
        $this->assertSame([], $cors['allowed_origins_patterns']);
        $this->assertFalse($cors['supports_credentials']);
        $this->assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $cors['allowed_methods']);
        $this->assertSame(['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id'], $cors['allowed_headers']);
        $this->assertSame(['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'], $cors['exposed_headers']);
    }

    #[Test]
    public function with_the_default_empty_allowlist_no_origin_is_allowed(): void
    {
        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/system/status')
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    #[Test]
    public function an_exact_configured_origin_is_allowed_and_nothing_else(): void
    {
        config(['cors.allowed_origins' => CorsOriginList::parse('https://partner.example.com')]);

        $allowed = $this->preflight('https://partner.example.com');
        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://partner.example.com');
        $allowed->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->assertStringNotContainsString('*', (string) $allowed->headers->get('Access-Control-Allow-Methods'));

        // An unapproved origin is never echoed back (with a single configured
        // origin the library always names THAT origin, which a browser at
        // any other origin rejects) and never `*`.
        foreach (['https://evil.example', 'https://sub.partner.example.com', 'http://partner.example.com', 'https://partner.example.com.evil.example'] as $origin) {
            $allow = $this->preflight($origin)->headers->get('Access-Control-Allow-Origin');
            $this->assertNotSame($origin, $allow);
            $this->assertContains($allow, [null, 'https://partner.example.com']);
        }

        // With several origins, an unapproved one gets no header at all.
        config(['cors.allowed_origins' => CorsOriginList::parse('https://partner.example.com, https://other.example.com')]);
        $this->preflight('https://evil.example')->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->preflight('https://other.example.com')->assertHeader('Access-Control-Allow-Origin', 'https://other.example.com');
        config(['cors.allowed_origins' => CorsOriginList::parse('https://partner.example.com')]);

        $this->withHeader('Origin', 'https://partner.example.com')->getJson('/api/v1/system/status')
            ->assertHeader('Access-Control-Allow-Origin', 'https://partner.example.com')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    #[Test]
    public function origin_configuration_is_parsed_strictly(): void
    {
        $this->assertSame([], CorsOriginList::parse(null));
        $this->assertSame([], CorsOriginList::parse(' , '));
        $this->assertSame(['https://a.example', 'https://b.example:8443', 'http://localhost:5173'], CorsOriginList::parse(' https://A.example ,https://b.example:8443, http://localhost:5173, https://a.example'));

        foreach (['*', 'https://*.example.com', 'https://a.example/path', 'http://a.example', 'a.example', 'https://user:pass@a.example', 'https://a.example?x=1', '/^https:.*$/'] as $bad) {
            try {
                CorsOriginList::parse($bad);
                $this->fail("Accepted an unsafe origin: {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
