<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Support\Idempotency\Exceptions\IdempotencyKeyConflictException;
use App\Support\Idempotency\Exceptions\IdempotencyRequestInProgressException;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Idempotency\IdempotencyKeyValidator;
use App\Support\Idempotency\IdempotencyMetrics;
use App\Support\Idempotency\IdempotencyOutcome;
use App\Support\Idempotency\IdempotencyStatus;
use App\Support\Idempotency\RequestFingerprint;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reusable opt-in idempotency primitive (Phase 0C.2). Usage:
 *
 *   Route::post(...)->middleware(['auth:sanctum', 'school-membership', 'idempotent']);
 *
 * MUST run after authentication and after School/actor context has
 * been established (section 12/19) -- it fails closed
 * (TenantContextRequiredException) if TenantContext has no School,
 * exactly like every other tenant-scoped primitive in this codebase.
 * It is deliberately NOT applied globally (section 4) -- only routes
 * that explicitly opt in via this middleware alias get idempotency
 * behavior.
 *
 * See App\Support\Idempotency\IdempotencyGuard for the actual claim/
 * replay/conflict/in-progress database logic and
 * docs/architecture/RELIABILITY.md for the full design writeup,
 * including exactly what crash-window guarantee this default path
 * provides.
 */
class EnsureIdempotent
{
    public function __construct(
        private readonly IdempotencyKeyValidator $validator,
        private readonly RequestFingerprint $fingerprint,
        private readonly IdempotencyGuard $guard,
        private readonly IdempotencyMetrics $metrics,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Authentication and tenant/membership context must already be
        // established by earlier middleware -- this fails closed rather
        // than silently trusting client-supplied identity (section 12,
        // section 19 of the original tenancy checkpoint).
        $school = $this->context->requireSchool();
        $actor = $this->context->actor();

        if ($actor === null) {
            throw new AuthorizationException('Authentication required before idempotency evaluation.');
        }

        $actorId = $actor->id;

        $key = $this->validator->validate($request->header('Idempotency-Key'));
        $routeAction = $request->route()?->getName() ?? $request->path();
        $actorType = 'user';

        $fingerprint = $this->fingerprint->compute($request, $routeAction, $school->id, $actorType, $actorId);
        $ttlSeconds = (int) config('idempotency.default_ttl_hours') * 3600;

        $claim = $this->guard->claim(
            $school->id, $actorType, $actorId, $routeAction, $key,
            strtoupper($request->method()), $fingerprint, $ttlSeconds,
        );

        $this->logOutcome($claim->outcome, $school->id, $actorId, $routeAction, $request);
        $this->metrics->increment($claim->outcome);

        // IdempotencyGuard::claim() only ever produces New/Replay/
        // Conflict/InProgress -- IdempotencyOutcome::Failed exists for
        // other observability call sites, never as a claim() result.
        return match ($claim->outcome) {
            IdempotencyOutcome::Replay => $this->replay($claim->record, $request),
            IdempotencyOutcome::Conflict => throw new IdempotencyKeyConflictException,
            IdempotencyOutcome::InProgress => throw new IdempotencyRequestInProgressException,
            IdempotencyOutcome::New => $this->executeAndFinalize($request, $next, $claim->record),
            IdempotencyOutcome::Failed => throw new LogicException('Unreachable: claim() never returns Failed.'),
        };
    }

    private function executeAndFinalize(Request $request, Closure $next, ApiIdempotencyKey $record): Response
    {
        $request->attributes->set('idempotency_record', $record);

        try {
            $response = $next($request);
        } catch (ValidationException $e) {
            $this->guard->failDeterministically($record, $e->status, [
                'error' => ['message' => $e->getMessage(), 'status' => $e->status, 'errors' => $e->errors()],
            ]);
            throw $e;
        } catch (AuthorizationException $e) {
            $this->guard->failDeterministically($record, 403, [
                'error' => ['message' => $e->getMessage(), 'status' => 403, 'errors' => null],
            ]);
            throw $e;
        } catch (Throwable $e) {
            // Unclassified (transient infra, unexpected 500, ...) --
            // never poison the key over a failure we can't prove was
            // deterministic. See IdempotencyGuard::release()'s docblock.
            $this->guard->release($record);
            throw $e;
        }

        // A self-participating controller (section 17) may already have
        // called IdempotencyGuard::completeWithin() inside its own
        // transaction -- don't clobber that with a second write.
        if ($record->fresh()->status === IdempotencyStatus::Processing->value) {
            $this->guard->complete($record, $response);
        }

        return $response;
    }

    private function replay(ApiIdempotencyKey $record, Request $request): Response
    {
        $body = $record->response_body ?? [];

        // Best-effort requestId refresh (section 20): this codebase's
        // convention is a top-level `meta.requestId` field. A stored
        // body without that shape is replayed byte-for-byte unchanged.
        if (isset($body['meta']) && is_array($body['meta'])) {
            $body['meta']['originalRequestId'] = $body['meta']['requestId'] ?? null;
            $body['meta']['requestId'] = $request->attributes->get('request_id');
        }

        $response = response()->json($body, $record->response_status ?? 200);

        foreach ($record->response_headers ?? [] as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set('Idempotency-Replayed', 'true');
        $response->headers->set('X-Request-Id', (string) $request->attributes->get('request_id'));

        return $response;
    }

    private function logOutcome(IdempotencyOutcome $outcome, string $schoolId, string $actorId, string $routeAction, Request $request): void
    {
        // Section 27: never the request/response body, only diagnostic
        // scope metadata. The key itself is not logged at all here --
        // the fingerprint/key pair already lives in the durable record.
        Log::info('idempotency.outcome', [
            'outcome' => $outcome->value,
            'school_id' => $schoolId,
            'actor_id' => $actorId,
            'route_action' => $routeAction,
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $this->context->correlationId(),
        ]);
    }
}
