<?php

namespace App\Support\Observability;

use App\Models\SchedulerHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0C.4 section 18: named heartbeats (`scheduler_heartbeats`) for
 * scheduled tasks (`outbox-dispatch`, ...), worker classes (`queue:{name}`)
 * and operator verifications (`verify:{check}`).
 *
 * Phase 0O.5A (ADR 0051 §11):
 * - one row per PROCESS CLASS, never per replica, written with an atomic
 *   INSERT ... ON CONFLICT DO UPDATE, so concurrent replicas/canaries
 *   cannot race each other into a unique violation;
 * - `last_error` holds a bounded error code (SafeException::code()),
 *   never exception text.
 */
class SchedulerHeartbeatRecorder
{
    public function recordSuccess(string $name): void
    {
        $now = now();

        DB::table('scheduler_heartbeats')->upsert(
            [['name' => $name, 'last_run_at' => $now, 'last_success_at' => $now, 'last_error' => null, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['last_run_at', 'last_success_at', 'last_error', 'updated_at'],
        );
    }

    public function recordFailure(string $name, string $errorCode): void
    {
        $now = now();
        $code = preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $errorCode) === 1 ? $errorCode : 'unclassified';

        DB::table('scheduler_heartbeats')->upsert(
            [['name' => $name, 'last_run_at' => $now, 'last_success_at' => null, 'last_error' => $code, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['last_run_at', 'last_error', 'updated_at'],
        );

        Log::warning('observability.heartbeat.failed', ['name' => $name, 'error_code' => $code]);
    }

    public function get(string $name): ?SchedulerHeartbeat
    {
        return SchedulerHeartbeat::query()->find($name);
    }

    /**
     * @return array<int, SchedulerHeartbeat>
     */
    public function all(): array
    {
        return SchedulerHeartbeat::query()->orderBy('name')->get()->all();
    }

    public function isStale(SchedulerHeartbeat $heartbeat, int $staleAfterSeconds): bool
    {
        if ($heartbeat->last_success_at === null) {
            return true;
        }

        return $heartbeat->last_success_at->diffInSeconds(now()) > $staleAfterSeconds;
    }
}
