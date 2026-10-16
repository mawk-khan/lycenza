<?php

namespace Tests\Feature\Observability;

use App\Support\Observability\OperationalStatus;
use App\Support\Observability\OperationalStatusService;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PPR F-D1 (ADR 0074 section 30): the readiness probe reaches Redis through
 * the same `database.redis.default` resolution as the application (REDIS_URL,
 * a tls:// / rediss:// scheme, REDIS_USERNAME, the ssl context). Before F-D1 it
 * built a plain tcp client from host/port/password only, so a TLS-only Redis
 * read as "unreachable". These tests use the suite's real (plaintext) Redis;
 * the TLS + authentication proof itself is a live, out-of-suite exercise
 * (docs/operations/STAGING-INFRASTRUCTURE-ARCHITECTURE.md section 5).
 */
class RedisReadinessConnectionTest extends TestCase
{
    #[Test]
    public function the_probe_follows_redis_url_instead_of_the_host_fields(): void
    {
        $default = config('database.redis.default');
        config(['database.redis.default' => array_merge($default, [
            'url' => "tcp://{$default['host']}:{$default['port']}",
            'host' => '127.0.0.1',
            'port' => 1,
        ])]);

        $this->assertSame(OperationalStatus::Healthy, app(OperationalStatusService::class)->redis()->status);
    }

    #[Test]
    public function a_tls_url_is_never_downgraded_to_plaintext(): void
    {
        $default = config('database.redis.default');
        config(['database.redis.default' => array_merge($default, [
            'url' => "tls://{$default['host']}:{$default['port']}",
        ])]);

        $status = app(OperationalStatusService::class)->redis();

        $this->assertSame(OperationalStatus::Unhealthy, $status->status);
        $this->assertSame('unreachable', $status->reason);
    }

    #[Test]
    public function the_probe_keeps_its_own_short_timeout(): void
    {
        config([
            'observability.readiness_check_timeout_ms' => 300,
            'database.redis.default' => array_merge(config('database.redis.default'), ['url' => null, 'host' => '10.255.255.1', 'port' => 6379]),
        ]);

        $start = microtime(true);
        $status = app(OperationalStatusService::class)->redis();

        $this->assertSame(OperationalStatus::Unhealthy, $status->status);
        $this->assertLessThan(3.0, microtime(true) - $start);
    }

    #[Test]
    public function a_tls_url_resolves_to_verified_tls_with_separate_credentials(): void
    {
        foreach (['tls', 'rediss'] as $scheme) {
            config(['database.redis.f_d1_contract' => [
                'url' => "{$scheme}://redis.staging.invalid:6380?ssl[cafile]=/run/ca/test-ca.pem",
                'host' => '127.0.0.1',
                'port' => '6379',
                'username' => 'lycenza',
                'password' => 'f-d1-contract-only',
                'database' => '0',
            ]]);

            $parameters = Redis::resolve('f_d1_contract')->client()->getConnection()->getParameters();

            $this->assertSame('tls', $parameters->scheme, $scheme);
            $this->assertSame('redis.staging.invalid', $parameters->host);
            $this->assertSame(6380, (int) $parameters->port);
            $this->assertSame('lycenza', $parameters->username);
            $this->assertSame('f-d1-contract-only', $parameters->password);
            $this->assertSame(['cafile' => '/run/ca/test-ca.pem'], $parameters->ssl, 'only the CA is set: peer and name verification stay at PHP defaults (on)');
        }
    }
}
