<?php

namespace Tests\Feature\Configuration;

use App\Support\Configuration\ProductionConfigurationGuard;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PPR F-D1-2 (ADR 0050 implementation amendment 2026-10-16): the guard judges
 * Redis transport on its own reading of the connection array, so it must agree
 * with what Laravel and Predis actually do with the same array. Each case is
 * resolved through the real RedisManager (no connection is opened) and the
 * guard's TLS verdict and ssl context must match the Predis parameters.
 */
class HardenedRedisTransportParityTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function connections(): array
    {
        return [
            'tls url' => [['url' => 'tls://redis.internal.example:6379']],
            'rediss url' => [['url' => 'rediss://redis.internal.example:6379']],
            'upper-case TLS url' => [['url' => 'TLS://redis.internal.example:6379']],
            'redis url' => [['url' => 'redis://redis.internal.example:6379']],
            'tcp url' => [['url' => 'tcp://redis.internal.example:6379']],
            'url driver beats scheme' => [['url' => 'redis://redis.internal.example:6379', 'scheme' => 'tls']],
            'tls url beats scheme' => [['url' => 'tls://redis.internal.example:6379', 'scheme' => 'tcp']],
            'host and port only' => [['host' => 'redis.internal.example', 'port' => 6379]],
            'scheme on the host' => [['host' => 'tls://redis.internal.example', 'port' => 6379]],
            'explicit tls scheme' => [['host' => 'redis.internal.example', 'scheme' => 'tls']],
            'explicit rediss scheme' => [['host' => 'redis.internal.example', 'scheme' => 'rediss']],
            'url with ssl options' => [['url' => 'tls://redis.internal.example:6379?ssl[cafile]=/run/ca.pem&ssl[verify_peer_name]=0']],
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    #[Test]
    #[DataProvider('connections')]
    public function the_guard_reads_a_connection_exactly_as_predis_receives_it(array $connection): void
    {
        config(['database.redis.parity' => $connection]);
        $parameters = Redis::resolve('parity')->client()->getConnection()->getParameters();

        $violations = (new ProductionConfigurationGuard(new Repository([
            'database' => ['redis' => ['client' => 'predis', 'parity' => $connection]],
        ])))->violations();

        $this->assertSame(
            in_array($parameters->scheme, ProductionConfigurationGuard::SECURE_REDIS_SCHEMES, true),
            ! in_array('redis_tls_required', $violations, true),
            "Predis scheme {$parameters->scheme}",
        );

        $ssl = (array) ($parameters->ssl ?? []);
        $this->assertSame(
            array_key_exists('verify_peer_name', $ssl) && ! $ssl['verify_peer_name'],
            in_array('redis_tls_verification_disabled', $violations, true),
        );
    }
}
