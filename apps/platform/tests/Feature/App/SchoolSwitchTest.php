<?php

namespace Tests\Feature\App;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 22: access switching / School selection.
 */
class SchoolSwitchTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_member_can_activate_a_school_they_belong_to(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $response = $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response->assertRedirect('/app');
        $this->assertSame($school->id, session('active_school_id'));
    }

    #[Test]
    public function a_user_cannot_activate_a_school_they_do_not_belong_to(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        // Deliberately no membership created.

        $response = $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response->assertSessionHasErrors('school');
        $this->assertNull(session('active_school_id'));
    }

    #[Test]
    public function a_user_cannot_activate_a_school_where_their_membership_is_suspended(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school, status: 'suspended');

        $response = $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response->assertSessionHasErrors('school');
    }

    #[Test]
    public function a_guest_cannot_activate_a_school(): void
    {
        $school = $this->createSchool();

        $this->post("/app/schools/{$school->id}/activate")->assertRedirect('/login');
    }

    #[Test]
    public function activating_a_school_actually_establishes_tenant_context_on_the_next_request(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);

        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $response = $this->get('/app');

        $response->assertInertia(fn ($page) => $page
            ->component('App/Dashboard')
            ->where('activeSchool.id', $school->id)
        );
    }
}
