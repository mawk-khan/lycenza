<?php

namespace Tests\Feature\Idempotency;

use App\Models\ApiIdempotencyKey;
use App\Models\PlatformIdempotencyDemoCounter;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Idempotency\IdempotencyOutcome;
use App\Support\Idempotency\IdempotencyStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C.2 section 17/39: proves the EXACT boundary of
 * App\Support\Idempotency\IdempotencyGuard's crash-window guarantee --
 * what the DEFAULT (claim() then a separate complete() call) path does
 * NOT eliminate, contrasted with what completeWithin() (used by
 * App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController, the
 * self-participating pattern) DOES eliminate. Neither test claims
 * exactly-once semantics where they are not actually proven.
 */
class IdempotencyCrashWindowTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_default_path_can_rerun_a_business_action_whose_worker_crashed_before_marking_completion(): void
    {
        // Documents a REAL, ACCEPTED limitation of the base primitive
        // (docs/architecture/RELIABILITY.md): if a caller performs its
        // business side effect and then the process dies BEFORE calling
        // IdempotencyGuard::complete(), the record is left 'processing'.
        // Once the in-flight timeout elapses, a retry reclaims the key
        // as a fresh execution -- because the guard, on its own, cannot
        // know whether the earlier attempt's business action actually
        // ran. A caller that cannot tolerate this MUST use
        // completeWithin() inside its own transaction (see the second
        // test below).
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guard = app(IdempotencyGuard::class);
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user, $guard): void {
            $claim = $guard->claim($school->id, 'user', $user->id, 'test.crash-action', 'crash-key', 'POST', str_repeat('a', 64), 3600);
            $this->assertSame(IdempotencyOutcome::New, $claim->outcome);

            // The business action "runs" and commits for real...
            $counter = PlatformIdempotencyDemoCounter::query()->firstOrCreate(['school_id' => $school->id], ['value' => 0]);
            $counter->increment('value');

            // ...then the worker "crashes": complete() is never called.
            // Simulate enough elapsed time for the in-flight timeout.
            $timeout = (int) config('idempotency.in_flight_timeout_seconds');
            DB::table('api_idempotency_keys')->where('id', $claim->record->id)
                ->update(['created_at' => now()->subSeconds($timeout + 5)]);

            $retry = $guard->claim($school->id, 'user', $user->id, 'test.crash-action', 'crash-key', 'POST', str_repeat('a', 64), 3600);

            // Reclaimed as a FRESH execution, not a replay -- this is
            // the documented gap: a caller using ONLY the default path
            // would run the business action again here.
            $this->assertSame(IdempotencyOutcome::New, $retry->outcome);
            $this->assertSame($claim->record->id, $retry->record->id);

            $counter->increment('value');
            $this->assertSame(2, $counter->fresh()->value, 'Demonstrates the duplicated side effect the default path does not prevent on its own.');
        });
    }

    #[Test]
    public function a_self_participating_transaction_prevents_a_split_between_the_business_action_and_completion(): void
    {
        // The STRONGER guarantee (section 17): when completeWithin() is
        // called inside the SAME transaction as the business action
        // (exactly what IdempotencyDemoController does), the two either
        // commit together or roll back together -- there is no
        // observable state where the counter incremented but the
        // idempotency record is still 'processing'.
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $guard = app(IdempotencyGuard::class);
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school, $user, $guard): void {
            $claim = $guard->claim($school->id, 'user', $user->id, 'test.atomic-action', 'atomic-key', 'POST', str_repeat('a', 64), 3600);

            try {
                DB::transaction(function () use ($school, $claim, $guard): void {
                    $counter = PlatformIdempotencyDemoCounter::query()->firstOrCreate(['school_id' => $school->id], ['value' => 0]);
                    $counter->increment('value');

                    $guard->completeWithin($claim->record, 200, ['data' => ['value' => $counter->fresh()->value]]);

                    // Simulate a crash AFTER both writes but BEFORE commit.
                    throw new RuntimeException('simulated crash before commit');
                });
            } catch (RuntimeException) {
                // expected
            }

            // Neither write survived -- proves they were genuinely
            // atomic, not two independent statements that could split.
            $counter = PlatformIdempotencyDemoCounter::query()->find($school->id);
            $this->assertNull($counter, 'The business action must have rolled back.');

            $record = ApiIdempotencyKey::query()->find($claim->record->id);
            $this->assertSame(IdempotencyStatus::Processing->value, $record->status, 'The completion write must have rolled back with it.');
        });
    }
}
