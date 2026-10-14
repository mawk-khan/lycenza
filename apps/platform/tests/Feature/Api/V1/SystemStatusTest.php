<?php

namespace Tests\Feature\Api\V1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    public function test_it_returns_the_versioned_envelope(): void
    {
        $response = $this->getJson('/api/v1/system/status');

        $response->assertOk();
        $response->assertHeader('X-Request-Id');
        $response->assertJsonStructure([
            'data' => ['status'],
            'meta' => ['apiVersion', 'requestId'],
        ]);
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonPath('meta.apiVersion', 'v1');
    }

    /** @return array<string, array{string}> */
    public static function environments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging'], 'local' => ['local'], 'testing' => ['testing']];
    }

    /**
     * PPR.1 (ADR 0074 section 26.6, finding F-8): the anonymous probe never
     * discloses the deployment environment (nor the development-era phase
     * label), whatever the environment is.
     */
    #[Test]
    #[DataProvider('environments')]
    public function the_anonymous_status_never_discloses_the_environment(string $environment): void
    {
        $this->app['env'] = $environment;

        $response = $this->getJson('/api/v1/system/status')->assertOk();

        $this->assertSame(['status'], array_keys($response->json('data')));
        $body = strtolower((string) $response->getContent());
        foreach (['production', 'staging', 'local', 'testing', 'environment', 'phase', 'architectural'] as $word) {
            $this->assertStringNotContainsString($word, $body, "the anonymous status must not contain '{$word}'");
        }
    }

    public function test_unknown_api_route_returns_the_standard_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonStructure([
            'error' => ['message', 'status', 'requestId'],
        ]);
    }
}
