<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\DB;

/**
 * Phase 0C.4 section 21: operational visibility into `failed_jobs`
 * beyond the raw table. Deliberately read-only -- retrying/deleting a
 * failed job stays `php artisan queue:retry`/`queue:forget` (Laravel's
 * own tooling), not reinvented here.
 *
 * Never unserializes the stored `payload.data.command` blob (that
 * would execute arbitrary PHP unserialize()/__wakeup on our own job
 * classes for no operational benefit) -- School id and correlation id
 * are recovered via a narrow regex over the serialized string's known
 * property names (App\Support\Tenancy\TenantScoped's `contextSchoolId`
 * / `contextCorrelationId`, or a job's own public `schoolId`), the same
 * way a human skimming the raw column would. If neither pattern
 * matches, both come back null -- this is a best-effort diagnostic
 * aid, not a guaranteed decode.
 */
class FailedJobInspector
{
    /**
     * @return array<int, array{
     *     queue: string,
     *     count: int,
     *     oldest_failed_at: string|null,
     * }>
     */
    public function summaryByQueue(): array
    {
        return DB::table('failed_jobs')
            ->selectRaw('queue, count(*) as count, min(failed_at) as oldest_failed_at')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [
                'queue' => $row->queue,
                'count' => (int) $row->count,
                'oldest_failed_at' => $row->oldest_failed_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array{
     *     uuid: string,
     *     connection: string,
     *     queue: string,
     *     job_class: string|null,
     *     failed_at: string,
     *     exception_summary: string,
     *     school_id: string|null,
     *     correlation_id: string|null,
     * }>
     */
    public function recent(int $limit = 20): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->describe($row))
            ->all();
    }

    /**
     * @param  \stdClass  $row  a `failed_jobs` row
     * @return array{
     *     uuid: string,
     *     connection: string,
     *     queue: string,
     *     job_class: string|null,
     *     failed_at: string,
     *     exception_summary: string,
     *     school_id: string|null,
     *     correlation_id: string|null,
     * }
     */
    private function describe(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $jobClass = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        $command = is_array($payload) ? ($payload['data']['command'] ?? '') : '';

        // The exception column is a full stack trace (potentially many
        // KB) -- only the first line (typically "Class: message in
        // file:line") is operationally useful for a listing; the full
        // trace remains available via a direct query for anyone who
        // genuinely needs it.
        $exceptionSummary = strtok((string) $row->exception, "\n") ?: '';

        return [
            'uuid' => $row->uuid,
            'connection' => $row->connection,
            'queue' => $row->queue,
            'job_class' => $jobClass,
            'failed_at' => $row->failed_at,
            'exception_summary' => $exceptionSummary,
            'school_id' => $this->extractProperty($command, ['contextSchoolId', 'schoolId']),
            'correlation_id' => $this->extractProperty($command, ['contextCorrelationId']),
        ];
    }

    /**
     * @param  array<int, string>  $propertyNames
     */
    private function extractProperty(string $serialized, array $propertyNames): ?string
    {
        foreach ($propertyNames as $property) {
            if (preg_match('/"'.preg_quote($property, '/').'";s:\d+:"([^"]*)"/', $serialized, $matches) === 1 && $matches[1] !== '') {
                return $matches[1];
            }
        }

        return null;
    }
}
