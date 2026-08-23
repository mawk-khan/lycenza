<?php

namespace Tests\Feature\Idempotency;

use App\Models\ApiIdempotencyKey;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Idempotency\IdempotencyOutcome;
use App\Support\Idempotency\IdempotencyStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Direct tests of App\Support\Idempotency\IdempotencyGuard -- the
 * primitive App\Http\Middleware\EnsureIdempotent is a thin HTTP adapter
 * over. Covers the parts of section 37/16/17/39 that are cleanest to
 * prove against the guard directly rather than through a full HTTP
 * round trip: in-progress detection, abandoned-claim reclaim, failure
 * classification, and the crash-window's exact documented boundary.
 */
class IdempotencyGuardTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function guard(): IdempotencyGuard
    {
        return app(IdempotencyGuard::class);
    }

    #[Test]
    public function a_second_claim_attempt_while_still_within_the_in_flight_window_is_refused(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $first = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k1', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $first->outcome);

            $second = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k1', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::InProgress, $second->outcome);
        });
    }

    #[Test]
    public function an_abandoned_processing_claim_older_than_the_in_flight_timeout_is_reclaimed(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $first = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k2', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $first->outcome);

            // Simulate a worker that crashed mid-request: the claim row
            // is stuck 'processing' well past the in-flight timeout.
            $timeout = (int) config('idempotency.in_flight_timeout_seconds');
            DB::table('api_idempotency_keys')->where('id', $first->record->id)
                ->update(['created_at' => now()->subSeconds($timeout + 5)]);

            $retry = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k2', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $retry->outcome);
            $this->assertSame($first->record->id, $retry->record->id);
        });
    }

    #[Test]
    public function a_different_fingerprint_for_the_same_scope_and_key_is_a_conflict(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k3', 'POST', str_repeat('a', 64), 3600);

            $result = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k3', 'POST', str_repeat('b', 64), 3600);
            $this->assertSame(IdempotencyOutcome::Conflict, $result->outcome);
        });
    }

    #[Test]
    public function a_completed_claim_replays_on_the_same_fingerprint(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $claim = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k4', 'POST', str_repeat('a', 64), 3600);
            $this->guard()->complete($claim->record, new Response(json_encode(['data' => ['value' => 1]]), 200, ['Content-Type' => 'application/json']));

            $replay = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k4', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::Replay, $replay->outcome);
            $this->assertSame(IdempotencyStatus::Completed->value, $replay->record->status);
            $this->assertSame(200, $replay->record->response_status);
            $this->assertSame(['data' => ['value' => 1]], $replay->record->response_body);
        });
    }

    #[Test]
    public function a_deterministic_failure_is_recorded_and_replays_the_same_rejection(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $claim = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k5', 'POST', str_repeat('a', 64), 3600);
            $this->guard()->failDeterministically($claim->record, 422, ['error' => ['message' => 'rejected', 'status' => 422]]);

            $retry = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k5', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::Replay, $retry->outcome);
            $this->assertSame(IdempotencyStatus::Failed->value, $retry->record->status);
            $this->assertSame(422, $retry->record->response_status);
        });
    }

    #[Test]
    public function releasing_an_unclassified_failure_does_not_poison_the_key(): void
    {
        // Section 16/17: a crash/transient failure must not permanently
        // block every future retry of the same logical request.
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $claim = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k6', 'POST', str_repeat('a', 64), 3600);
            $this->guard()->release($claim->record);

            $this->assertNull(ApiIdempotencyKey::query()->find($claim->record->id));

            $retry = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k6', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $retry->outcome);
        });
    }

    #[Test]
    public function an_expired_completed_record_is_treated_as_gone_and_a_fresh_claim_succeeds(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user): void {
            $claim = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k7', 'POST', str_repeat('a', 64), 3600);
            $this->guard()->complete($claim->record, new Response('{}', 200));

            DB::table('api_idempotency_keys')->where('id', $claim->record->id)->update(['expires_at' => now()->subDay()]);

            $fresh = $this->guard()->claim($school->id, 'user', $user->id, 'test.action', 'k7', 'POST', str_repeat('c', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $fresh->outcome);
            $this->assertNotSame($claim->record->id, $fresh->record->id);
        });
    }
}
