<?php

namespace App\Support\Tenancy;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative runtime tenant-context abstraction. Bound as
 * a `scoped()` container instance (see AppServiceProvider) so it is
 * automatically fresh per HTTP request and per queue job -- but nothing
 * here relies on that alone: every code path that establishes context
 * (HTTP middleware, queue job middleware, console commands) is
 * responsible for calling clear() when its unit of work ends, and the
 * queue job middleware does so in a `finally` block. See
 * docs/architecture/adr/0022-tenant-context-propagation.md and
 * docs/architecture/TENANCY.md.
 *
 * Deliberately NOT a static/global -- it is resolved from the
 * container so it cannot leak between requests/jobs the way a static
 * property could in a long-running worker or a future Octane worker.
 */
class TenantContext
{
    private ?School $school = null;

    private ?Campus $campus = null;

    private ?User $actor = null;

    private ?string $requestId = null;

    private ?string $correlationId = null;

    private ?string $traceId = null;

    private ?string $spanId = null;

    public function set(School $school, ?Campus $campus = null): void
    {
        if ($campus !== null && $campus->school_id !== $school->id) {
            throw new CampusSchoolMismatchException($campus->id, $school->id, $campus->school_id);
        }

        $this->school = $school;
        $this->campus = $campus;

        DB::statement('SELECT set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        Context::add('school_id', $school->id);
        if ($campus !== null) {
            Context::add('campus_id', $campus->id);
        } else {
            Context::forget('campus_id');
        }
    }

    public function setActor(?User $actor): void
    {
        $this->actor = $actor;
        $actor ? Context::add('actor_id', $actor->id) : Context::forget('actor_id');
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
        $requestId ? Context::add('request_id', $requestId) : Context::forget('request_id');
    }

    public function school(): ?School
    {
        return $this->school;
    }

    public function schoolId(): ?string
    {
        return $this->school?->id;
    }

    public function campus(): ?Campus
    {
        return $this->campus;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Section 34: the same workflow's request_id, correlation_id, and
     * causation_id must propagate consistently across HTTP -> event ->
     * outbox -> queue -> handler -> notification/webhook/AI call,
     * never a fresh, unrelated ID minted at every layer.
     *
     * Set explicitly for a fresh top-level unit of work (see
     * ResolveSchoolContext); when never set, falls back to requestId
     * so a bare `event(new SomeEvent())` call from ordinary request
     * code still gets a sensible correlation id without every caller
     * needing to think about it.
     */
    public function setCorrelationId(?string $correlationId): void
    {
        $this->correlationId = $correlationId;
        $correlationId ? Context::add('correlation_id', $correlationId) : Context::forget('correlation_id');
    }

    public function correlationId(): ?string
    {
        return $this->correlationId ?? $this->requestId;
    }

    /**
     * Phase 0C.4 section 33/41: W3C-Trace-Context-compatible trace/span
     * identity (App\Support\Observability\TraceContext), set by
     * App\Http\Middleware\AssignTraceContext for inbound HTTP and
     * propagated the same way request_id/correlation_id already are --
     * one abstraction for request-scoped context, not a second,
     * parallel one just for tracing.
     */
    public function setTraceContext(?string $traceId, ?string $spanId): void
    {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $traceId ? Context::add('trace_id', $traceId) : Context::forget('trace_id');
        $spanId ? Context::add('span_id', $spanId) : Context::forget('span_id');
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function hasSchool(): bool
    {
        return $this->school !== null;
    }

    /**
     * Fail-closed accessor: use this (not school()) anywhere School
     * context is required for the operation to be meaningful.
     */
    public function requireSchool(): School
    {
        return $this->school ?? throw new TenantContextRequiredException;
    }

    /**
     * Runs $callback with School context temporarily set to $school,
     * regardless of what context (if any) was already active, then
     * restores the previous School/Campus exactly as it was. Used by
     * services (e.g. CapabilityResolver) that must authoritatively
     * inspect one specific School's RLS-protected data without
     * depending on -- or permanently disturbing -- whatever context the
     * calling request/job already has. This is the one sanctioned way
     * to read a different School's RLS-protected rows than the
     * request's ambient context: it is explicit, named, and scoped to
     * exactly the callback's duration.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withSchool(School $school, callable $callback): mixed
    {
        $previousSchool = $this->school;
        $previousCampus = $this->campus;

        try {
            $this->set($school);

            return $callback();
        } finally {
            if ($previousSchool !== null) {
                $this->set($previousSchool, $previousCampus);
            } else {
                $this->clear();
            }
        }
    }

    /**
     * Clears School/Campus context and the Postgres RLS session
     * variable. Does NOT clear actor/requestId -- those are cleared
     * separately (clearAll()) since a request may legitimately clear
     * School context (e.g. switching schools, section 22) while
     * keeping the same authenticated actor.
     */
    public function clear(): void
    {
        $this->school = null;
        $this->campus = null;

        DB::statement('RESET '.TenantRls::SESSION_VAR);

        Context::forget(['school_id', 'campus_id']);
    }

    /**
     * Full reset -- used at the end of a queue job or console command's
     * unit of work so nothing can leak into the next one processed by
     * the same long-running worker process.
     */
    public function clearAll(): void
    {
        $this->clear();
        $this->actor = null;
        $this->requestId = null;
        $this->correlationId = null;
        $this->traceId = null;
        $this->spanId = null;
        Context::forget(['actor_id', 'request_id', 'correlation_id', 'trace_id', 'span_id']);
    }
}
