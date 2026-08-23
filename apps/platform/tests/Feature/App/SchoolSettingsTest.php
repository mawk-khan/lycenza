<?php

namespace Tests\Feature\App;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 39: proves capability-gated read vs. write and server-side
 * denial for an unauthorized route -- not the final School Admin UI.
 */
class SchoolSettingsTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_school_admin_can_view_and_update_settings(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/settings')->assertInertia(fn ($page) => $page
            ->component('App/SchoolSettings')
            ->where('canManage', true)
        );

        $response = $this->put('/app/settings', [
            'name' => 'Renamed School',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
        ]);

        $response->assertRedirect('/app/settings');
        $this->assertSame('Renamed School', $school->fresh()->name);
    }

    #[Test]
    public function a_principal_can_view_but_not_update_settings(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/settings')->assertInertia(fn ($page) => $page
            ->component('App/SchoolSettings')
            ->where('canManage', false)
        );

        $response = $this->put('/app/settings', [
            'name' => 'Should Not Apply',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
        ]);

        $response->assertForbidden();
        $this->assertSame($school->name, $school->fresh()->name);
    }

    #[Test]
    public function a_member_with_no_role_is_denied_entirely(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        // No role assigned at all.
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->get('/app/settings')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        $this->get('/app/settings')->assertRedirect('/login');
    }
}
