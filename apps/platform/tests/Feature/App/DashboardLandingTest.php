<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 section 7.2): the signed-in landing shows the product and
 * the active School, keeps School selection and elevation state, and takes
 * its overview from the one shared navigation -- it no longer carries its
 * own `nav`/`platform`/`groups` flag maps.
 */
class DashboardLandingTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    #[Test]
    public function the_landing_names_the_product_and_the_active_school(): void
    {
        $school = $this->createSchool(['name' => 'Greenfield Public School']);
        $user = $this->createUserWithCapabilities($school, ['students.view']);
        $this->activate($user, $school);

        $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Dashboard')
            ->where('product.name', 'Lycenza')
            ->where('activeSchool.name', 'Greenfield Public School')
            ->where('navigation.school.name', 'Greenfield Public School')
            ->where('navigation', self::nav(shown: ['students']))
            ->missing('nav')
            ->missing('platform')
            ->missing('groups')
            ->has('memberships', 1)
            ->has('platformElevation'));
    }

    #[Test]
    public function without_a_school_the_landing_still_offers_the_selection(): void
    {
        $a = $this->createSchool(['name' => 'Alpha']);
        $user = $this->createUserWithCapabilities($a, ['students.view']);

        $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn ($page) => $page
            ->where('activeSchool', null)
            ->where('memberships.0.schoolName', 'Alpha')
            ->where('navigation', self::navExactly([])));
    }

    #[Test]
    public function the_landing_page_has_one_heading_and_no_development_framing(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/App/Dashboard.vue'));

        $this->assertSame(1, substr_count($source, '<h1'));
        $this->assertStringContainsString('No modules are available to your account in this School.', $source);
        $this->assertStringNotContainsString('Phase 0B', $source);
        $this->assertStringNotContainsString('available under elevated access yet', $source);
    }
}
