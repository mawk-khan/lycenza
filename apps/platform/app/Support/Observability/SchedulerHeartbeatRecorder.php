<?php

namespace App\Support\Observability;

use App\Models\SchedulerHeartbeat;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0C.4. The one shared helper every named heartbeat-emitting
 * task (scheduled command OR queue processing) uses -- centralizes the
 * inline `SchedulerHeartbeat::updateOrCreate(...)` pattern
 * `App\Console\Commands\DispatchOutboxEvents` already used ad hoc, so
 * every current and future heartbeat is recorded identically instead
 * of each command reinventing the same three lines slightly
 * differently.
 *
 * `scheduler_heartbeats` is used for BOTH scheduled-command heartbeats
 * (e.g. "outbox-dispatch") and queue-processing heartbeats (e.g.
 * "queue:default") -- one small, generically-named table (section 73)
 * rather than a second near-identical table; both are "a named
 * operational task reported it is alive," which is exactly what this
 * table's `(name, last_run_at, last_success_at, last_error)` shape
 * already models.
 */
class SchedulerHeartbeatRecorder
{
    public function recordSuccess(string $name): void
    {
        SchedulerHeartbeat::query()->updateOrCreate(
            ['name' => $name],
            ['last_run_at' => now(), 'last_success_at' => now(), 'last_error' => null],
        );
    }

    public function recordFailure(string $name, string $error): void
    {
        SchedulerHeartbeat::query()->updateOrCreate(
            ['name' => $name],
            ['last_run_at' => now(), 'last_error' => $error],
        );

        Log::warning('observability.heartbeat.failed', ['name' => $name]);
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
