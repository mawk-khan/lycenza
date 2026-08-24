<?php

namespace App\Support\Tenancy;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * Deliberately does NOT use a plain try/finally -- see
     * restoreAfterFailure()'s docblock and
     * docs/architecture/TENANCY.md ("Root cause: aborted-transaction
     * cleanup") for why the exception path and the success path need
     * different database-restoration handling.
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
            $result = $callback();
        } catch (Throwable $e) {
            $this->restoreAfterFailure($previousSchool, $previousCampus);

            throw $e;
        }

        $this->restoreOnSuccess($previousSchool, $previousCampus);

        return $result;
    }

    /**
     * Restores context after $callback returned normally (no exception
     * propagating) -- the ordinary set()/clear() call is expected to
     * succeed here, exactly as before this checkpoint. If it doesn't --
     * SQLSTATE 25P02, "current transaction is aborted" -- the callback
     * must have caught and swallowed its own database exception
     * internally without rolling back or rethrowing it, leaving the
     * connection poisoned while reporting success. TenantContext never
     * silently pretends that restore succeeded in that case; it raises
     * a clear, dedicated exception naming exactly what happened (see
     * TenantContextPoisonedConnectionException's docblock) rather than
     * letting a cryptic raw "RESET app.current_school_id failed" reach
     * the caller, and never attempts its own ROLLBACK/COMMIT here --
     * TenantContext does not own the callback's transaction (whichever
     * code caught the original exception is the only code that could
     * safely decide to roll back or retry).
     */
    private function restoreOnSuccess(?School $previousSchool, ?Campus $previousCampus): void
    {
        try {
            if ($previousSchool !== null) {
                $this->set($previousSchool, $previousCampus);
            } else {
                $this->clear();
            }
        } catch (QueryException $e) {
            if ($this->isAbortedTransactionError($e)) {
                throw new TenantContextPoisonedConnectionException($e);
            }

            throw $e;
        }
    }

    /**
     * Restores context after $callback's own exception is already
     * propagating. The PHP-side state (the actual authorization/RLS-
     * relevant fields -- $school/$campus -- plus the request Context
     * facade entries) is ALWAYS restored first and unconditionally,
     * regardless of whether the database-side GUC restore below
     * succeeds -- a database failure must never leave PHP still
     * believing the old (now-exited) School is active.
     *
     * The database-side restore is then attempted, but a SQLSTATE
     * 25P02 failure from THAT attempt is deliberately swallowed here,
     * and ONLY here: it means the callback's own failure already
     * aborted the connection's current transaction, which is expected
     * and safe, not a new problem to report. Proven directly (see the
     * reproduction in tests/Feature/Tenancy/TenantContextAbortedTransactionTest.php
     * and docs/architecture/TENANCY.md): PostgreSQL reverts a
     * session-level `set_config(..., false)` value to whatever it was
     * before the transaction started as soon as that transaction is
     * eventually rolled back -- by whichever code actually owns it
     * (Laravel's DB::transaction() wrapper for a sanctioned service's
     * own mutation, or the test framework's DatabaseTransactions
     * wrapper) -- so no explicit RESET/set_config from TenantContext is
     * even necessary once that rollback happens; attempting one INSIDE
     * the still-aborted window merely fails loudly with the same
     * SQLSTATE and, before this fix, replaced the real original
     * exception with that unrelated failure. TenantContext never
     * issues its own ROLLBACK/COMMIT (it does not own the transaction,
     * CLAUDE.md-equivalent rule for this checkpoint) -- it only avoids
     * letting this one, specific, already-understood secondary failure
     * mask the real error the caller needs to see and translate
     * (e.g. UniqueConstraintViolationException -> a domain exception).
     *
     * Any OTHER database exception from this restore attempt (not
     * SQLSTATE 25P02) is a genuinely different, unexpected problem and
     * is never swallowed -- it replaces the original exception exactly
     * like it always has, so a real double-failure is never hidden.
     */
    private function restoreAfterFailure(?School $previousSchool, ?Campus $previousCampus): void
    {
        $this->school = $previousSchool;
        $this->campus = $previousCampus;

        if ($previousSchool !== null) {
            Context::add('school_id', $previousSchool->id);
            $previousCampus !== null ? Context::add('campus_id', $previousCampus->id) : Context::forget('campus_id');
        } else {
            Context::forget(['school_id', 'campus_id']);
        }

        try {
            if ($previousSchool !== null) {
                DB::statement('SELECT set_config(?, ?, false)', [TenantRls::SESSION_VAR, $previousSchool->id]);
            } else {
                DB::statement('RESET '.TenantRls::SESSION_VAR);
            }
        } catch (QueryException $cleanupException) {
            if ($this->isAbortedTransactionError($cleanupException)) {
                return;
            }

            throw $cleanupException;
        }
    }

    private function isAbortedTransactionError(QueryException $e): bool
    {
        return $e->getCode() === '25P02';
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

    /**
     * clearAll()'s counterpart for a caller with a Throwable already
     * propagating (e.g. SetTenantContextForJob's `catch` block, after
     * a queued job's own handler failed) -- see restoreAfterFailure()'s
     * docblock for the full rationale. PHP-side state is always
     * restored unconditionally; a SQLSTATE 25P02 from the database-side
     * GUC reset is swallowed (proven safe -- the job's own failure
     * already aborted the transaction, which reverts the GUC
     * automatically once whatever code owns it rolls back), any other
     * database error propagates normally.
     */
    public function clearAllAfterFailure(): void
    {
        $this->restoreAfterFailure(null, null);
        $this->actor = null;
        $this->requestId = null;
        $this->correlationId = null;
        $this->traceId = null;
        $this->spanId = null;
        Context::forget(['actor_id', 'request_id', 'correlation_id', 'trace_id', 'span_id']);
    }

    /**
     * Best-effort variant of clearAll() for a caller that cannot know
     * whether it is running after the preceding unit of work succeeded
     * or failed -- e.g. Tests\TestCase::tearDown(), which runs
     * unconditionally regardless of test outcome, and must run BEFORE
     * the test framework tears down the application container (making
     * withSchool()'s richer, exception-aware restoreOnSuccess()/
     * restoreAfterFailure() split unusable there -- see
     * docs/architecture/TENANCY.md).
     *
     * Always clears PHP-side state exactly like clearAll(). The
     * database-side GUC reset is skipped -- WITH a logged warning,
     * never silently -- ONLY when the connection is already in
     * PostgreSQL's aborted-transaction state (SQLSTATE 25P02): proven
     * safe (docs/architecture/TENANCY.md "Root cause"), because
     * whichever transaction caused that state will revert the GUC
     * automatically the moment it is eventually rolled back by
     * whatever code actually owns it (DatabaseTransactions' own
     * rollback, for the test-suite caller this method exists for).
     * Any OTHER database error is never swallowed -- it propagates
     * normally, exactly like clear()/clearAll() always have.
     */
    public function clearAllTolerantly(): void
    {
        $this->school = null;
        $this->campus = null;
        $this->actor = null;
        $this->requestId = null;
        $this->correlationId = null;
        $this->traceId = null;
        $this->spanId = null;
        Context::forget(['school_id', 'campus_id', 'actor_id', 'request_id', 'correlation_id', 'trace_id', 'span_id']);

        try {
            DB::statement('RESET '.TenantRls::SESSION_VAR);
        } catch (QueryException $e) {
            if (! $this->isAbortedTransactionError($e)) {
                throw $e;
            }

            Log::warning('tenancy.context.reset_skipped_aborted_transaction', [
                'sqlstate' => $e->getCode(),
            ]);
        }
    }
}
