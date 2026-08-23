<?php

namespace App\Http\Middleware;

use App\Support\Observability\TraceContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request (web, api, and the AI Gateway's internal calls) gets a
 * W3C-Trace-Context-compatible trace/span identity (section 41/42),
 * mirroring App\Http\Middleware\AssignRequestId's exact pattern --
 * parses an inbound `traceparent` if present (continuing a trace a
 * caller started), otherwise starts a new one; echoes the resulting
 * `traceparent` back on the response for diagnostic visibility. Never
 * trusts `traceparent` for anything beyond diagnostics -- it carries
 * no School/actor identity and is never consulted for authorization
 * (section 42).
 */
class AssignTraceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $trace = TraceContext::fromHeader($request->headers->get('traceparent'));

        $request->attributes->set('trace_context', $trace);
        app(TenantContext::class)->setTraceContext($trace->traceId, $trace->spanId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('traceparent', $trace->toHeader());

        return $response;
    }
}
