<?php

namespace Tests\Feature\Idempotency;

use App\Models\PlatformIdempotencyDemoCounter;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.2 (API idempotency foundation). Exercises the `idempotent`
 * middleware end to end over real local HTTP against the tiny
 * infrastructure-only demonstration endpoint (section 18/47). See
 * IdempotencyRealConcurrencyTest for the mandatory real-concurrency
 * proof (section 38) and IdempotencyCrashWindowTest for the
 * failure-classification/crash-window proofs (section 16/17/39).
 */
class IdempotencyDemoEndpointTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function increment(string $token, string $school, string $key, array $body = []): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/schools/{$school}/idempotency-demo/increment", $body);
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $school = $this->createSchool();

        $this->withHeader('Idempotency-Key', 'test-key-001')
            ->postJson("/api/v1/schools/{$school->id}/idempotency-demo/increment")
            ->assertUnauthorized();
    }

    #[Test]
    public function a_disabled_user_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;
        $user->forceFill(['is_disabled' => true])->save();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $this->increment($token, $school->id, 'test-key-002')->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_membership_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        SchoolMembership::query()
            ->where('user_id', $user->id)->where('school_id', $school->id)
            ->update(['status' => 'suspended']);

        $this->increment($token, $school->id, 'test-key-003')->assertNotFound();
    }

    #[Test]
    public function an_actor_without_the_required_capability_is_denied(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal'); // no school.settings.manage
        $token = $user->createToken('test-device')->plainTextToken;

        $this->increment($token, $school->id, 'test-key-004')->assertForbidden();
    }

    #[Test]
    public function a_missing_idempotency_key_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/schools/{$school->id}/idempotency-demo/increment");

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    #[Test]
    public function an_oversized_key_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->increment($token, $school->id, str_repeat('a', 300));

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    #[Test]
    public function a_malformed_key_is_rejected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->increment($token, $school->id, "not a valid key!\n");

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    #[Test]
    public function a_new_request_executes_the_action_exactly_once(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->increment($token, $school->id, 'new-request-key');

        $response->assertOk();
        $response->assertJsonPath('data.value', 1);
        $this->assertNull($response->headers->get('Idempotency-Replayed'));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => PlatformIdempotencyDemoCounter::query()->find($school->id)->value,
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_replay_with_the_same_key_and_payload_returns_the_stored_result_without_rerunning(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $first = $this->increment($token, $school->id, 'replay-key', ['note' => 'same']);
        $first->assertOk();
        $this->assertSame(1, $first->json('data.value'));

        $second = $this->increment($token, $school->id, 'replay-key', ['note' => 'same']);
        $second->assertOk();
        $second->assertHeader('Idempotency-Replayed', 'true');
        // The stored value, NOT re-incremented -- proves the action did
        // not run a second time.
        $this->assertSame(1, $second->json('data.value'));

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => PlatformIdempotencyDemoCounter::query()->find($school->id)->value,
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function a_replay_does_not_duplicate_the_business_audit_event(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $this->increment($token, $school->id, 'audit-once-key')->assertOk();
        $this->increment($token, $school->id, 'audit-once-key')->assertOk();

        $auditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'platform.idempotency_demo.counter_incremented')->count(),
        );
        $this->assertSame(1, $auditCount);
    }

    #[Test]
    public function an_actor_who_loses_authorization_cannot_obtain_a_replayed_response(): void
    {
        // Section 19: authorization is re-evaluated on EVERY request,
        // including a would-be replay -- a stale, no-longer-authorized
        // actor must not be able to fetch a previously stored
        // successful response merely by knowing the old key. The
        // `capability:` middleware runs BEFORE `idempotent` in this
        // route's chain (routes/api.php), so a disabled actor is
        // rejected before the guard is ever consulted.
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $this->increment($token, $school->id, 'now-revoked-key')->assertOk();

        $user->forceFill(['is_disabled' => true])->save();

        // Same Sanctum RequestGuard-memoization quirk as the School
        // A/B test above -- without this, the second call would
        // silently reuse the PRE-disable in-memory User object.
        Auth::forgetGuards();

        // Phase 0O.3 (ADR 0049 section 2): a disabled account's token no longer authenticates at all -- 401, still before idempotency.
        $this->increment($token, $school->id, 'now-revoked-key')->assertUnauthorized();
    }

    #[Test]
    public function the_same_key_with_a_different_payload_is_rejected_as_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $token = $user->createToken('test-device')->plainTextToken;

        $this->increment($token, $school->id, 'conflict-key', ['note' => 'first'])->assertOk();

        $response = $this->increment($token, $school->id, 'conflict-key', ['note' => 'second']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        // The conflicting request must never have executed.
        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => PlatformIdempotencyDemoCounter::query()->find($school->id)->value,
        );
        $this->assertSame(1, $count);
    }

    #[Test]
    public function school_a_and_school_b_can_reuse_the_identical_literal_key_independently(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $tokenA = $userA->createToken('test-device')->plainTextToken;
        $tokenB = $userB->createToken('test-device')->plainTextToken;

        $responseA = $this->increment($tokenA, $schoolA->id, 'shared-literal-key');

        // Sanctum's RequestGuard memoizes the resolved user for its
        // own lifetime -- without this, the second call below would
        // silently re-authenticate as user A (Laravel testing-client
        // quirk, not an application bug: the guard instance persists
        // across sequential $this->postJson() calls within one test).
        Auth::forgetGuards();

        $responseB = $this->increment($tokenB, $schoolB->id, 'shared-literal-key');

        $responseA->assertOk();
        $responseB->assertOk();
        $this->assertSame(1, $responseA->json('data.value'));
        $this->assertSame(1, $responseB->json('data.value'));
        $this->assertNull($responseA->headers->get('Idempotency-Replayed'));
        $this->assertNull($responseB->headers->get('Idempotency-Replayed'));
    }
}
