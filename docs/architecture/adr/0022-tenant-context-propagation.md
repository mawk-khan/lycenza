# ADR 0022: TenantContext as the Single Runtime Tenant Abstraction

- Status: Accepted
- Date: 2026-08-22 (Phase 0B)

## Context

ADR 0004 and `docs/architecture/TENANCY.md` established the tenancy
*model* (School as boundary, three isolation layers) but explicitly left
"how the Postgres session variable actually gets set, and how it's kept
from leaking across requests/jobs in a long-running worker" as an open
implementation question for Phase 0B. That question needs one
authoritative answer used everywhere -- HTTP requests, queue jobs,
console commands, cache keys, log context, and the AI Gateway boundary
-- or different code paths will each invent slightly different (and
differently buggy) tenant-context handling.

## Decision

**`App\Support\Tenancy\TenantContext`** (bound `scoped()` in the
container -- fresh per HTTP request and per queue job) is the single
object every layer of the system asks "what School is this?". It owns:

- The in-memory current School/Campus/actor/request-id.
- The PostgreSQL session variable (`app.current_school_id`, via
  `set_config(..., false)` on `set()`, `RESET` on `clear()`/`clearAll()`)
  that Postgres RLS policies read (ADR 0021, `TenantRls`).
- Propagation of `school_id`/`campus_id`/`actor_id`/`request_id` into
  Laravel's `Context` facade, so every log line automatically carries
  them without each log call remembering to add them (section 27).

**Fail-closed by construction**: `requireSchool()` throws
`TenantContextRequiredException` rather than returning null -- any code
that needs a School and doesn't have one errors loudly, per root
`CLAUDE.md` rule 5 and `docs/architecture/TENANCY.md`.

**Explicit lifecycle, not implicit:**

- **HTTP**: `App\Http\Middleware\ResolveSchoolContext` sets context from
  trusted domain/session resolution (`docs/architecture/TENANCY.md`,
  "School domain resolution") early in the middleware pipeline (pinned
  via Laravel's middleware *priority* list, not array position, to run
  after session start but before `SubstituteBindings` -- see that
  middleware's docblock for why array `prepend`/`append` position alone
  couldn't express this ordering) and clears it in `terminate()`.
- **Queue jobs**: the `TenantScoped` trait captures context into the
  job's own serialized payload at dispatch time; `SetTenantContextForJob`
  (a Laravel queue job middleware) sets it before `handle()` runs and
  clears it in a `finally` block after -- proven safe for a
  long-running worker processing many Schools' jobs in sequence, not
  just asserted (see the Final Report's test results for the "Job
  School A then Job School B" and "tenant job then central job" cases,
  section 25).
- **A privileged, self-contained read of a *specific* School's
  RLS-protected data regardless of ambient context**
  (`TenantContext::withSchool()`) -- used by `CapabilityResolver` so
  resolving School B's capabilities doesn't depend on (or corrupt)
  whatever context an unrelated request/job already had active.

## Rationale

- One object, one set of rules, used everywhere, means "is tenant
  context handled correctly here" has one answer to audit instead of N
  different context-passing conventions across HTTP/queue/CLI.
- Binding it `scoped()` (Laravel's per-request/per-job container reset,
  designed originally for Octane) is a real defense-in-depth layer, but
  this ADR deliberately does NOT rely on it alone -- the explicit
  `finally`-block clearing in `SetTenantContextForJob` and
  `ResolveSchoolContext::terminate()` is what this checkpoint actually
  tests, because `scoped()`'s guarantees are an implementation detail
  of Laravel's container we shouldn't need to trust blindly for a
  security-relevant guarantee.
- Session-level (not `SET LOCAL`/transaction-level) Postgres GUC
  assignment is required because a request or job's unit of work can
  span multiple transactions/statements; the tradeoff is that the
  connection MUST be explicitly reset when the unit of work ends, which
  is exactly what `clear()`/`clearAll()` do and why they're mandatory,
  not optional cleanup.

## Alternatives considered

1. **A static/global holder for "current School."** Rejected outright
   per this checkpoint's brief (section 9) and root `CLAUDE.md`'s
   general aversion to mutable globals -- a static leaks across
   requests in exactly the long-running-worker scenario this ADR exists
   to prevent, and is actively hostile to future Octane adoption.
2. **Rely on `SET LOCAL` (transaction-scoped) instead of session-level
   `set_config`.** Rejected: a request/job's tenant-scoped work isn't
   guaranteed to happen inside one single transaction, and Laravel's own
   transaction handling (nested transactions, `DB::transaction()`
   retries) would make a `SET LOCAL` value's lifetime unpredictable
   relative to the application code that set it.
3. **Derive tenant context from route parameters directly in each
   controller**, no shared service. Rejected: this is exactly the "each
   module invents its own tenant handling" outcome this ADR exists to
   prevent, and gives queue jobs/console commands no equivalent
   mechanism at all.

## Consequences

- Every future tenant-scoped code path (a new module's controller, job,
  or console command) depends on `TenantContext` rather than inventing
  its own resolution -- this is now a root `CLAUDE.md` mandatory rule.
- `CapabilityResolver`, `AuditRecorder`, `TenantCache`, and the AI
  Gateway boundary (ADR 0023) all depend on `TenantContext` as their
  one source of truth for "which School."
- Testing tenant-context-leak scenarios (section 25/32) becomes
  possible in a single PHPUnit process specifically because
  `TenantContext` is deterministic and inspectable, not because of any
  Postgres-specific magic -- see ADR 0024's test-infrastructure notes
  for how the test suite itself doubles as a long-running-worker
  analogue.

## Future extraction/evolution path

If/when Laravel Octane (or any persistent-worker HTTP runtime) is
adopted, `ResolveSchoolContext`'s `terminate()` cleanup and
`TenantContext`'s `scoped()` binding are already the correct shape for
that model -- no redesign needed, only verification that Octane's
request-teardown hooks actually invoke Laravel's standard middleware
`terminate()` lifecycle (they do, by Octane's own design contract).
