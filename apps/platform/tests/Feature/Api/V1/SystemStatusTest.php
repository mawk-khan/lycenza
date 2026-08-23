<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    public function test_it_returns_the_versioned_envelope(): void
    {
        $response = $this->getJson('/api/v1/system/status');

        $response->assertOk();
        $response->assertHeader('X-Request-Id');
        $response->assertJsonStructure([
            'data' => ['status', 'phase', 'environment'],
            'meta' => ['apiVersion', 'requestId'],
        ]);
        $response->assertJsonPath('meta.apiVersion', 'v1');
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
