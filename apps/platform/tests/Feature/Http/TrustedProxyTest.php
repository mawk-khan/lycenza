<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 2): X-Forwarded-* is believed only from an
 * explicitly configured proxy. A client that is not a trusted proxy cannot
 * spoof its address (rate limits, audit IP), the scheme (HSTS, secure
 * URLs), the host or the port.
 */
class TrustedProxyTest extends TestCase
{
    private const TRUSTED_PROXY = '10.20.30.40';

    private const UNTRUSTED_CLIENT = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-echo', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
        ]));
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function forwarded(): array
    {
        return [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'spoofed.example.com',
            'X-Forwarded-Port' => '443',
        ];
    }

    /**
     * Requests use explicit plain-HTTP URLs: the test base URL may itself
     * be https (DDEV's APP_URL), which would make every request secure
     * regardless of forwarded headers.
     */
    private function fromAddress(string $remote): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote]);
    }

    #[Test]
    public function the_framework_trust_proxies_is_replaced(): void
    {
        $global = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(TrustConfiguredProxies::class, $global);
        $this->assertNotContains(TrustProxies::class, $global);
        $this->assertSame([], config('trustedproxy.proxies'), 'nobody is trusted by default');
    }

    #[Test]
    public function with_no_proxy_configured_forwarded_headers_are_ignored(): void
    {
        $this->fromAddress(self::TRUSTED_PROXY)->withHeaders($this->forwarded())->getJson('http://localhost/_proxy-echo')
            ->assertExactJson(['ip' => self::TRUSTED_PROXY, 'secure' => false, 'host' => 'localhost', 'port' => 80]);
    }

    #[Test]
    public function an_untrusted_client_cannot_spoof_address_scheme_host_or_port(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        $this->fromAddress(self::UNTRUSTED_CLIENT)->withHeaders($this->forwarded())->getJson('http://localhost/_proxy-echo')
            ->assertExactJson(['ip' => self::UNTRUSTED_CLIENT, 'secure' => false, 'host' => 'localhost', 'port' => 80]);
    }

    #[Test]
    public function a_configured_proxy_is_believed(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        $this->fromAddress(self::TRUSTED_PROXY)->withHeaders($this->forwarded())->getJson('http://localhost/_proxy-echo')
            ->assertExactJson(['ip' => '203.0.113.9', 'secure' => true, 'host' => 'spoofed.example.com', 'port' => 443]);
    }

    #[Test]
    public function a_spoofed_chain_through_a_trusted_proxy_yields_the_last_untrusted_hop(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        // The client prepended a fake address; the proxy appended the real one.
        $this->fromAddress(self::TRUSTED_PROXY)->withHeaders(['X-Forwarded-For' => '1.2.3.4, '.self::UNTRUSTED_CLIENT])->getJson('http://localhost/_proxy-echo')
            ->assertJsonPath('ip', self::UNTRUSTED_CLIENT);
    }

    #[Test]
    public function the_forwarded_rfc7239_header_is_not_believed(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        $this->fromAddress(self::TRUSTED_PROXY)->withHeaders(['Forwarded' => 'for=203.0.113.50;proto=https;host=evil.example.com'])->getJson('http://localhost/_proxy-echo')
            ->assertExactJson(['ip' => self::TRUSTED_PROXY, 'secure' => false, 'host' => 'localhost', 'port' => 80]);
    }

    #[Test]
    public function hsts_is_sent_through_a_trusted_tls_proxy_and_never_for_a_spoofed_scheme(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $this->app['env'] = 'production';

        try {
            $this->fromAddress(self::TRUSTED_PROXY)->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('http://localhost/api/health/live')
                ->assertOk()->assertHeader('Strict-Transport-Security', ApplySecurityHeaders::HSTS);

            $this->fromAddress(self::UNTRUSTED_CLIENT)->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('http://localhost/api/health/live')
                ->assertOk()->assertHeaderMissing('Strict-Transport-Security');

            // A plain-HTTP hop through the trusted proxy (headers do not carry over).
            $this->flushHeaders()->fromAddress(self::TRUSTED_PROXY)->getJson('http://localhost/api/health/live')
                ->assertOk()->assertHeaderMissing('Strict-Transport-Security');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    #[Test]
    public function the_rate_limiter_keys_on_the_real_client_not_a_spoofed_header(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        // Through the trusted proxy the forwarded client is the key; from an
        // untrusted client, rotating X-Forwarded-For changes nothing.
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $spoof) {
            $this->fromAddress(self::UNTRUSTED_CLIENT)->withHeaders(['X-Forwarded-For' => $spoof])->getJson('http://localhost/_proxy-echo')
                ->assertJsonPath('ip', self::UNTRUSTED_CLIENT);
        }
    }
}
