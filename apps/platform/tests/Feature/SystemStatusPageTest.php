<?php

namespace Tests\Feature;

use Tests\TestCase;

class SystemStatusPageTest extends TestCase
{
    public function test_home_page_renders_inertia_system_status(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Request-Id');
        $response->assertInertia(fn ($page) => $page
            ->component('SystemStatus')
            ->has('plannedModules')
            ->where('phase', '0A - Architectural Foundation')
        );
    }

    public function test_request_id_is_echoed_back_when_supplied(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'test-request-id-123'])->get('/');

        $response->assertHeader('X-Request-Id', 'test-request-id-123');
    }
}
