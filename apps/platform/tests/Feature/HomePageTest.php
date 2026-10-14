<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 section 7.1): the public `/` is a neutral, login-directed
 * product entry -- no phase, roadmap, module inventory, environment, request
 * id or School data -- and sends a signed-in visitor to /app.
 */
class HomePageTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures;

    #[Test]
    public function a_guest_sees_the_product_and_the_way_in_and_nothing_else(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Home')
            ->where('product.name', 'Lycenza')
            ->missing('environment')
            ->missing('requestId')
            ->missing('phase')
            ->missing('plannedModules')
            ->where('navigation', null));

        $html = $response->getContent();
        foreach (['Phase 0A', 'Planned modules', 'not yet implemented', 'Environment', 'Request ID', config('app.env').'</'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, $leak);
        }
        // The request id stays an observability response header only.
        $response->assertHeader('X-Request-Id');
        $this->assertStringNotContainsString((string) $response->headers->get('X-Request-Id'), $html);

        $source = (string) file_get_contents(resource_path('js/Pages/Home.vue'));
        $this->assertStringContainsString('href="/login"', $source);
        $this->assertStringContainsString('Sign in', $source);
    }

    #[Test]
    public function a_signed_in_visitor_is_sent_to_the_app_landing(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, []);

        $this->actingAs($user)->get('/')->assertRedirect(route('app.dashboard'));
    }

    #[Test]
    public function a_school_custom_host_serves_the_same_neutral_page(): void
    {
        $school = $this->createSchool(['name' => 'Northfield Academy']);
        $this->createSchoolDomain($school, 'erp.northfield.org');

        $response = $this->get('http://erp.northfield.org/')->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Home')->where('product.name', 'Lycenza'));
        $this->assertStringNotContainsString('Northfield Academy', $response->getContent());
    }

    #[Test]
    public function the_request_id_is_still_echoed_as_a_header(): void
    {
        $this->withHeaders(['X-Request-Id' => 'test-request-id-123'])->get('/')
            ->assertHeader('X-Request-Id', 'test-request-id-123');
    }
}
