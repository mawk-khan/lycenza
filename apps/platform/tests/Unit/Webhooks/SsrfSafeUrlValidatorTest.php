<?php

namespace Tests\Unit\Webhooks;

use App\Support\Webhooks\SsrfRejectedException;
use App\Support\Webhooks\SsrfSafeUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0C.3 section 70/93/71: production URL policy rejection matrix,
 * plus the double-guarded local/testing override and its inability to
 * leak into a production-configured environment.
 */
class SsrfSafeUrlValidatorTest extends TestCase
{
    private SsrfSafeUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SsrfSafeUrlValidator;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function prohibitedUrls(): array
    {
        return [
            'loopback dotted-quad' => ['http://127.0.0.1/'],
            'loopback second address in the /8' => ['http://127.0.0.2/'],
            'loopback IPv6' => ['http://[::1]/'],
            'RFC1918 10/8' => ['http://10.0.0.1/'],
            'RFC1918 172.16/12' => ['http://172.16.0.1/'],
            'RFC1918 192.168/16' => ['http://192.168.1.1/'],
            'link-local / cloud metadata' => ['http://169.254.169.254/'],
            'unspecified address' => ['http://0.0.0.0/'],
            'credentials embedded in URL' => ['https://user:pass@example.test/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.test/'],
            'ftp scheme' => ['ftp://example.test/'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/plain;base64,SGVsbG8='],
            'malformed URL' => ['not a url at all'],
            'missing host' => ['https:///path'],
        ];
    }

    #[Test]
    #[DataProvider('prohibitedUrls')]
    public function production_validation_rejects_prohibited_destinations(string $url): void
    {
        $this->expectException(SsrfRejectedException::class);

        $this->validator->assertSafeAndResolve($url);
    }

    #[Test]
    public function a_hostname_that_resolves_to_a_prohibited_address_is_rejected(): void
    {
        // localhost conventionally resolves to 127.0.0.1 in any
        // standard resolver configuration (including this container's).
        $this->expectException(SsrfRejectedException::class);

        $this->validator->assertSafeAndResolve('http://localhost/');
    }

    #[Test]
    public function an_ipv4_mapped_ipv6_loopback_address_is_rejected(): void
    {
        $this->expectException(SsrfRejectedException::class);

        $this->validator->assertSafeAndResolve('http://[::ffff:127.0.0.1]/');
    }

    #[Test]
    public function a_genuinely_public_https_url_is_accepted(): void
    {
        // 8.8.8.8 (a real, well-known public address) is never
        // resolved further (it's already a literal IP) and is not in
        // any private/reserved range -- proves the validator doesn't
        // reject everything.
        $ip = $this->validator->assertSafeAndResolve('https://8.8.8.8/webhook');

        $this->assertSame('8.8.8.8', $ip);
    }

    #[Test]
    public function the_local_test_override_is_refused_without_the_environment_guard_even_if_the_config_flag_is_set(): void
    {
        config(['webhooks.allow_loopback_for_tests' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(SsrfRejectedException::class);

        $this->validator->assertSafeAndResolve('http://127.0.0.1:9999/');
    }

    #[Test]
    public function the_local_test_override_is_refused_in_local_testing_env_without_the_config_flag(): void
    {
        config(['webhooks.allow_loopback_for_tests' => false]);

        $this->expectException(SsrfRejectedException::class);

        $this->validator->assertSafeAndResolve('http://127.0.0.1:9999/');
    }

    #[Test]
    public function the_local_test_override_permits_loopback_only_with_both_the_flag_and_the_environment(): void
    {
        config(['webhooks.allow_loopback_for_tests' => true]);
        // Already running in the `testing` environment (phpunit.xml).

        $ip = $this->validator->assertSafeAndResolve('http://127.0.0.1:9999/');

        $this->assertSame('127.0.0.1', $ip);
    }
}
