<?php

namespace Tests\Feature\Idempotency;

use App\Models\ApiIdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 23: `php artisan platform:idempotency-prune`. Not scheduled
 * (section 23 explicitly defers that) -- these tests only prove the
 * command itself is safe to run.
 */
class IdempotencyPruneCommandTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function makeRecord(string $schoolId, string $actorId, string $key, string $status, Carbon $expiresAt): ApiIdempotencyKey
    {
        return ApiIdempotencyKey::query()->create([
            'school_id' => $schoolId,
            'actor_type' => 'user',
            'actor_id' => $actorId,
            'route_action' => 'test.action',
            'idempotency_key' => $key,
            'request_fingerprint' => str_repeat('a', 64),
            'request_method' => 'POST',
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }

    #[Test]
    public function it_deletes_only_expired_non_processing_records_across_schools(): void
    {
        [$userA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$userB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $context = app(TenantContext::class);

        $expiredCompletedA = $context->withSchool($schoolA, fn () => $this->makeRecord($schoolA->id, $userA->id, 'expired-completed', 'completed', now()->subDay()));
        $context->withSchool($schoolA, fn () => $this->makeRecord($schoolA->id, $userA->id, 'still-valid', 'completed', now()->addDay()));
        $context->withSchool($schoolA, fn () => $this->makeRecord($schoolA->id, $userA->id, 'expired-but-processing', 'processing', now()->subDay()));
        $expiredFailedB = $context->withSchool($schoolB, fn () => $this->makeRecord($schoolB->id, $userB->id, 'expired-failed', 'failed', now()->subDay()));

        Artisan::call('platform:idempotency-prune');

        $survivingA = $context->withSchool($schoolA, fn () => ApiIdempotencyKey::query()->pluck('idempotency_key')->all());
        $survivingB = $context->withSchool($schoolB, fn () => ApiIdempotencyKey::query()->pluck('idempotency_key')->all());

        $this->assertNotContains('expired-completed', $survivingA);
        $this->assertContains('still-valid', $survivingA);
        $this->assertContains('expired-but-processing', $survivingA, 'A processing record must never be pruned, even if past its retention timestamp.');
        $this->assertNotContains('expired-failed', $survivingB);
    }
}
