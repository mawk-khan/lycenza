<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request gets a stable request id, echoed back on the response and
 * available to logs/audit entries. See docs/architecture/API.md
 * ("Request IDs").
 *
 * Phase 0O.5A (ADR 0051 §7): an inbound `X-Request-Id` is untrusted input.
 * It is honoured only when it matches PATTERN (ASCII, 8-128 characters,
 * a conservative class that UUIDs, ULIDs and typical proxy ids satisfy);
 * anything else is DISCARDED -- never truncated, escaped or logged -- and
 * a fresh server UUID is used instead. The value used is what the
 * response echoes and what logs, audit envelopes (`varchar(255)`) and AI
 * context tokens carry.
 */
class AssignRequestId
{
    /** `D`: `$` must not accept a trailing newline. */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = self::acceptable($request->headers->get('X-Request-Id')) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    public static function acceptable(?string $candidate): ?string
    {
        return is_string($candidate) && preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
