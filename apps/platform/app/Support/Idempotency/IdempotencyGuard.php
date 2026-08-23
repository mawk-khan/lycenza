<?php

namespace App\Support\Idempotency;

use App\Models\ApiIdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONLY sanctioned writer of api_idempotency_keys rows (section 30).
 * PostgreSQL's unique constraint on
 * (school_id, actor_type, actor_id, route_action, idempotency_key) --
 * not an application-level existence check -- is what makes claim()
 * safe under real concurrency: two simultaneous callers both attempt an
 * INSERT for the same tuple; Postgres allows exactly one to succeed,
 * and the loser observes UniqueConstraintViolationException and falls
 * back to reading the winner's row. See
 * tests/Feature/Idempotency/ConcurrencyTest.php and
 * docs/architecture/RELIABILITY.md.
 *
 * Crash window (section 17): claim() and complete()/failDeterministically()
 * are, by DEFAULT, two separate statements -- a process that crashes
 * between committing the underlying business action and calling
 * complete() leaves the record 'processing' until the in-flight timeout
 * elapses, at which point a retry safely reclaims it (handleProcessing()
 * below) rather than the key being poisoned forever. This base
 * primitive guarantees "effectively-once side effects under the
 * documented in-flight timeout window," NOT exactly-once -- a
 * concurrent retry that arrives WHILE the original request is still
 * genuinely running (within the in-flight timeout) is correctly
 * refused (IdempotencyOutcome::InProgress), but a retry that arrives
 * AFTER the timeout while the original process is simply slow (not
 * crashed) could theoretically race the original to completion. A
 * critical endpoint that cannot tolerate this MUST call
 * completeWithin() inside the SAME database transaction as its own
 * authoritative state change (see that method's docblock) -- that
 * closes the crash window completely for that endpoint, because the
 * business action and the idempotency completion either both commit or
 * both roll back atomically.
 */
class IdempotencyGuard
{
    private const ALLOWED_RESPONSE_HEADERS = ['Content-Type'];

    public function claim(
        string $schoolId,
        string $actorType,
        string $actorId,
        string $routeAction,
        string $idempotencyKey,
        string $requestMethod,
        string $fingerprint,
        int $ttlSeconds,
    ): ClaimResult {
        $existing = $this->findLive($schoolId, $actorType, $actorId, $routeAction, $idempotencyKey);

        if ($existing !== null) {
            return $this->resolveExisting($existing, $fingerprint);
        }

        try {
            $record = ApiIdempotencyKey::query()->create([
                'school_id' => $schoolId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'route_action' => $routeAction,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'request_method' => $requestMethod,
                'status' => IdempotencyStatus::Processing->value,
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);

            return ClaimResult::claimed($record);
        } catch (UniqueConstraintViolationException) {
            // Lost the race between the SELECT above and this INSERT --
            // the database constraint is the authoritative guarantee,
            // not the SELECT. Re-read whichever row the winner created.
            $record = $this->findLive($schoolId, $actorType, $actorId, $routeAction, $idempotencyKey);

            if ($record === null) {
                // Narrow window: the winner's row already expired/was
                // pruned between our failed INSERT and this re-read.
                // Safe to retry the claim exactly once.
                return $this->claim($schoolId, $actorType, $actorId, $routeAction, $idempotencyKey, $requestMethod, $fingerprint, $ttlSeconds);
            }

            return $this->resolveExisting($record, $fingerprint);
        }
    }

    /**
     * Marks a claimed record completed from OUTSIDE the controller's
     * own transaction -- the default path used by
     * App\Http\Middleware\EnsureIdempotent after $next() returns. See
     * this class's crash-window docblock for what this default path
     * does and does not guarantee.
     */
    public function complete(ApiIdempotencyKey $record, Response $response): void
    {
        $record->forceFill([
            'status' => IdempotencyStatus::Completed->value,
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->safeHeaders($response),
            'response_body' => $this->decodeBody($response),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * For controllers that opt into the STRONGER crash-window guarantee
     * (section 17): call this INSIDE the same DB::transaction() as the
     * action's own authoritative state change (e.g.
     * App\Http\Controllers\Api\V1\Internal\IdempotencyDemoController),
     * so the business row and the idempotency completion either both
     * commit or both roll back together -- eliminating the "commits
     * then crashes before marking completed" window entirely for that
     * one endpoint.
     */
    public function completeWithin(ApiIdempotencyKey $record, int $status, array $body, array $headers = []): void
    {
        $record->forceFill([
            'status' => IdempotencyStatus::Completed->value,
            'response_status' => $status,
            'response_headers' => array_intersect_key($headers, array_flip(self::ALLOWED_RESPONSE_HEADERS)),
            'response_body' => $body,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Section 16: an expected, DETERMINISTIC application failure
     * (validation rejected, domain rule rejected, authorization denied)
     * is recorded and WILL be replayed verbatim on retry -- re-running
     * the same logical request would deterministically reject it again
     * anyway, so replaying the stored rejection is both safe and avoids
     * repeating wasted work.
     */
    public function failDeterministically(ApiIdempotencyKey $record, int $status, array $body): void
    {
        $record->forceFill([
            'status' => IdempotencyStatus::Failed->value,
            'response_status' => $status,
            'response_body' => $body,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Section 16/17: an UNCLASSIFIED failure (worker crash, database
     * disconnection, dependency timeout, or any exception this guard
     * does not recognize as deterministic) releases the claim entirely
     * rather than permanently poisoning the key -- the next retry gets
     * a genuinely fresh claim attempt. We would rather risk a retry
     * re-running an action whose outcome is uncertain than permanently
     * block every future retry over a transient infrastructure blip.
     */
    public function release(ApiIdempotencyKey $record): void
    {
        $record->delete();
    }

    private function findLive(string $schoolId, string $actorType, string $actorId, string $routeAction, string $idempotencyKey): ?ApiIdempotencyKey
    {
        $record = ApiIdempotencyKey::query()
            ->where('school_id', $schoolId)
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId)
            ->where('route_action', $routeAction)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($record === null) {
            return null;
        }

        // A terminal row whose retention window has passed is treated
        // as if it never existed (IdempotencyStatus's docblock) -- it
        // is deleted here so the unique constraint doesn't block a
        // fresh claim reusing the same key after expiry.
        if ($record->expires_at->isPast() && $record->status !== IdempotencyStatus::Processing->value) {
            $record->delete();

            return null;
        }

        return $record;
    }

    private function resolveExisting(ApiIdempotencyKey $record, string $fingerprint): ClaimResult
    {
        if ($record->request_fingerprint !== $fingerprint) {
            return ClaimResult::conflict();
        }

        return match ($record->statusEnum()) {
            IdempotencyStatus::Completed, IdempotencyStatus::Failed, IdempotencyStatus::Expired => ClaimResult::replay($record),
            IdempotencyStatus::Processing => $this->handleProcessing($record),
        };
    }

    /**
     * Section 15/17: a 'processing' row younger than the in-flight
     * timeout is a genuine concurrent duplicate -- refused immediately
     * (IdempotencyOutcome::InProgress), never an unbounded wait inside
     * an HTTP worker. A 'processing' row OLDER than the timeout is
     * presumed abandoned (crashed worker, dropped connection) and is
     * reclaimed via a conditional UPDATE: Postgres's row-level locking
     * means at most one concurrent reclaim attempt can actually affect
     * the row, because a second UPDATE targeting the same row blocks
     * until the first commits, then re-evaluates its WHERE clause
     * against the now-changed row and affects zero rows.
     */
    private function handleProcessing(ApiIdempotencyKey $record): ClaimResult
    {
        $timeoutSeconds = (int) config('idempotency.in_flight_timeout_seconds');
        $staleThreshold = now()->subSeconds($timeoutSeconds);

        if ($record->created_at->gt($staleThreshold)) {
            return ClaimResult::inProgress();
        }

        $reclaimed = DB::table('api_idempotency_keys')
            ->where('id', $record->id)
            ->where('status', IdempotencyStatus::Processing->value)
            ->where('created_at', '<=', $staleThreshold)
            ->update(['created_at' => now(), 'updated_at' => now()]);

        if ($reclaimed === 1) {
            return ClaimResult::claimed($record->fresh());
        }

        // Someone else reclaimed it first, or it completed in between.
        $fresh = $this->findLive(
            $record->school_id, $record->actor_type, $record->actor_id,
            $record->route_action, $record->idempotency_key,
        );

        return $fresh === null
            ? ClaimResult::inProgress()
            : $this->resolveExisting($fresh, $record->request_fingerprint);
    }

    private function safeHeaders(Response $response): array
    {
        $headers = [];
        foreach (self::ALLOWED_RESPONSE_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        return $headers;
    }

    private function decodeBody(Response $response): ?array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
