<?php

namespace Tests\Feature\Api\Internal;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.4 section 52/53: `/internal/operations/status` is
 * cross-tenant diagnostic data, gated by a PLATFORM capability
 * (`platform.operations.view`), never a School-scoped one -- proves
 * both the "allow" and "deny" cases CLAUDE.md rule 13 requires for any
 * protected endpoint.
 */
class OperationsControllerTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_guest_is_denied(): void
    {
        $response = $this->getJson('/api/internal/operations/status');

        $response->assertUnauthorized();
    }

    #[Test]
    public function an_authenticated_user_without_the_platform_capability_is_denied(): void
    {
        [$user] = $this->createSchoolAdmin('school_admin');

        $response = $this->actingAs($user)->getJson('/api/internal/operations/status');

        $response->assertForbidden();
    }

    #[Test]
    public function a_school_scoped_role_can_never_grant_this_platform_capability(): void
    {
        // Even School Admin -- the most privileged SCHOOL role -- must
        // never see cross-tenant diagnostics (ADR "role scope trigger":
        // school roles cannot grant platform capabilities at all).
        [$user] = $this->createSchoolAdmin('school_admin');

        $response = $this->actingAs($user)->getJson('/api/internal/operations/status');

        $response->assertForbidden();
    }

    #[Test]
    public function a_platform_super_admin_can_view_operational_status(): void
    {
        $user = $this->createUser();
        $this->assignPlatformRole($user, 'platform_super_admin');

        $response = $this->actingAs($user)->getJson('/api/internal/operations/status');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['status', 'components']]);

        $components = collect($response->json('data.components'))->pluck('component');
        $this->assertTrue($components->contains('database'));
        $this->assertTrue($components->contains('redis'));
        $this->assertTrue($components->contains('outbox'));
        $this->assertTrue($components->contains('webhooks'));
        $this->assertTrue($components->contains('ai_gateway'));
    }
}
