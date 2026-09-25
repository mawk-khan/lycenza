<?php

namespace Tests\Feature\Webhooks;

use App\Models\Role;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.3 section 90: authorization matrix for the webhook
 * management API, plus the basic CRUD/tenant-isolation behaviors
 * (section 53) not already covered by WebhookRlsIsolationTest's
 * raw-SQL proofs.
 */
class WebhookEndpointApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/webhook-endpoints")->assertUnauthorized();
    }

    #[Test]
    public function a_member_without_the_view_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal'); // no integrations.webhooks.*

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/webhook-endpoints")
            ->assertForbidden();
    }

    #[Test]
    public function the_view_capability_alone_allows_reads_but_not_writes(): void
    {
        $role = Role::query()->create(['key' => 'webhook-viewer-'.uniqid(), 'name' => 'Webhook Viewer', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['integrations.webhooks.view']);

        $school = $this->createSchool();
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role->key);

        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $client->getJson("/api/v1/schools/{$school->id}/webhook-endpoints")->assertOk();
        $client->postJson("/api/v1/schools/{$school->id}/webhook-endpoints", ['name' => 'x', 'url' => 'https://8.8.8.8/hook'])
            ->assertForbidden();
    }

    #[Test]
    public function a_disabled_user_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $user->forceFill(['is_disabled' => true])->save();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/webhook-endpoints")
            ->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_membership_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->update(['status' => 'suspended']);

        $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$school->id}/webhook-endpoints")
            ->assertNotFound();
    }

    #[Test]
    public function school_admin_can_create_view_and_manage_an_endpoint(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->withHeader('Idempotency-Key', 'create-key-001')
            ->postJson("/api/v1/schools/{$school->id}/webhook-endpoints", [
                'name' => 'My ERP',
                'url' => 'https://8.8.8.8/hooks/school-os',
            ])
            ->assertCreated();

        $endpointId = $create->json('data.id');
        $this->assertNotEmpty($create->json('data.secret'), 'The plaintext secret must be returned once at creation.');

        $show = $client->getJson("/api/v1/schools/{$school->id}/webhook-endpoints/{$endpointId}")->assertOk();
        $this->assertArrayNotHasKey('secret', $show->json('data'), 'The secret must never be returned again after creation.');

        $client->postJson("/api/v1/schools/{$school->id}/webhook-endpoints/{$endpointId}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $client->postJson("/api/v1/schools/{$school->id}/webhook-endpoints/{$endpointId}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function rotating_the_secret_returns_a_new_one_time_secret_and_audits(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $client = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        $create = $client->withHeader('Idempotency-Key', 'create-key-002')
            ->postJson("/api/v1/schools/{$school->id}/webhook-endpoints", ['name' => 'x', 'url' => 'https://8.8.8.8/hook'])
            ->assertCreated();
        $originalSecret = $create->json('data.secret');
        $endpointId = $create->json('data.id');

        $rotate = $client->withHeader('Idempotency-Key', 'rotate-key-001')
            ->postJson("/api/v1/schools/{$school->id}/webhook-endpoints/{$endpointId}/rotate-secret")
            ->assertOk();

        $this->assertNotSame($originalSecret, $rotate->json('data.secret'));

        // TenantContext was already cleared when the HTTP request
        // finished (EnsureSchoolMembershipContext's finally block) --
        // re-establish it explicitly to read this School's own
        // RLS-protected audit rows.
        $auditCount = $this->context()->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'integrations.webhook_endpoint.secret_rotated')
                ->count(),
        );
        $this->assertSame(1, $auditCount);
    }

    #[Test]
    public function school_a_cannot_see_or_manage_school_bs_endpoint(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $endpointB = $this->context()->withSchool($schoolB, fn () => WebhookEndpoint::query()->create([
            'school_id' => $schoolB->id,
            'name' => 'school b endpoint',
            'url' => 'https://8.8.8.8/hook',
            'secret_encrypted' => 'secret',
            'status' => 'active',
        ]));

        $this->withHeader('Authorization', 'Bearer '.$this->token($userA))
            ->getJson("/api/v1/schools/{$schoolA->id}/webhook-endpoints/{$endpointB->id}")
            ->assertNotFound();
    }

    #[Test]
    public function creating_an_endpoint_with_a_ssrf_unsafe_url_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->withHeader('Idempotency-Key', 'ssrf-attempt-001')
            ->postJson("/api/v1/schools/{$school->id}/webhook-endpoints", [
                'name' => 'evil',
                'url' => 'http://169.254.169.254/latest/meta-data/',
            ])
            ->assertUnprocessable();

        // This project's custom error envelope nests field errors
        // under error.errors, not Laravel's stock top-level `errors`
        // key -- assertJsonValidationErrors() assumes the stock shape.
        $this->assertNotEmpty($response->json('error.errors.url'));
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
