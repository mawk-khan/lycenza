# ADR 0044: Cross-Tenant Elevation Contract (Phase 0N.2)

- Status: Accepted (contract only — nothing described here is built)
- Date: 2026-09-24 (Phase 0N.2)

## Context

ADR 0004 says a Platform Super Admin reaches School data only through "a
separate, explicitly audited path (not a 'bypass RLS' flag …)", and a
group admin's cross-school access is "an explicit, granted, audited
elevation, never a default". Nothing specified that path.
`docs/architecture/PHASE-0N-READINESS.md` (sections 5–10, 15) recorded
the open decisions; Phase 0N.1 (`c1e6db8`) made School context an
explicit prerequisite of every School-scoped web route
(`App\Http\Middleware\RequireSchoolContext`) and gave `/app` a neutral
landing, but deliberately built no way in for a non-member.

**On 2026-09-24 the product owner approved D2, D3, D4, D5, D6, D7, D8,
D14 and D17** (the decisions are restated in section 1). This ADR turns
them into an implementation contract for **one** thing: a platform actor
temporarily establishing **one** School's tenant context. It changes no
code, adds no migration and seeds no capability. It does **not** resolve
D1 (group principal), D11 (School lifecycle), D12 (platform role
grants), D13 (platform membership administration), D15 (cross-School
reporting), D16 (platform audit review) or D18 (group governance).

### What the current code already guarantees (verified on `c1e6db8`)

- **One School per unit of work.** `App\Support\Tenancy\TenantContext::set()`
  issues `set_config('app.current_school_id', …)`; 144 tables have RLS
  enabled and forced; the runtime role `school_os_app` is `NOSUPERUSER
  NOBYPASSRLS` (ADR 0021, CLAUDE.md rule 26).
- **Platform and School capabilities never merge.**
  `App\Support\Authorization\CapabilityResolver::schoolCapabilities()`
  returns `[]` unless an **active `school_memberships` row** exists for
  that user and School; `platformCapabilities()` reads only
  `platform_role_assignments`; `can()` dispatches `platform.*` to the
  platform side and returns `false` for a School capability without a
  School. Both are cached for 60 seconds
  (`CapabilityResolver::CACHE_TTL_SECONDS`). Database triggers keep role
  scopes apart (CLAUDE.md rule 25).
- **Web School context** comes from `ResolveSchoolContext` (verified
  domain, or `session('active_school_id')` re-validated against an
  active membership and `School::isActive()` on every request), and
  `RequireSchoolContext` (alias `school-context`) additionally requires
  an active membership, an active School and a non-disabled account
  before route model binding, `capability:`/`mfa` and the controller.
  In local/testing only, `DevOnlySchoolHeaderResolver` may set context
  from `X-School-Id` for a member.
- **`/api/v1`** takes the School from the URL; `school-membership`
  (`App\Http\Middleware\Api\EnsureSchoolMembershipContext`) requires an
  active membership and returns 404 otherwise. The `api` middleware
  group has no session (`statefulApi()` is not enabled in
  `bootstrap/app.php`).
- **MFA** (ADR 0037): assurance is **session-scoped** —
  `MfaChallengeService::establishAssurance()` writes
  `session('mfa_verified_at')`, and `hasValidAssurance()` checks it
  against `config('mfa.assurance_window_minutes')` (default **60**,
  `config/mfa.php`) through `App\Support\Auth\AssuranceFreshness`.
  Assurance is established in exactly one place: login stage 2
  (`MfaChallengeController::store()`). There is **no signed-in
  re-verification route**; once the window passes, only a fresh sign-in
  restores assurance. `RequireMfa` (alias `mfa`) is per-route and
  answers only in JSON: no active factor → `403
  mfa_required_not_enrolled`, stale or missing assurance → `401
  mfa_step_up_required`.
- **Audit**: `AuditRecorder::platform()` writes `platform_audit_events`
  (append-only, no RLS; columns `actor_user_id`, `event_type`,
  `subject_type`/`subject_id`, `ip_address`, `user_agent`, `request_id`,
  `metadata`); `AuditRecorder::school()` writes `school_audit_events`
  (RLS, append-only). Existing platform event names follow
  `namespace.resource.verb` / `namespace.verb`: `school_context.activated`,
  `platform.service_identity.issued`, `auth.login_succeeded`,
  `auth.mfa_reset_by_admin`.
- **Sessions**: Redis driver, `SESSION_LIFETIME=120` minutes.
  `SchoolSwitchController` regenerates the session on selection;
  Phase 0N.1 clears a stale selection and rotates Inertia's history key.
- **Queued jobs** capture School, Campus, actor, request and correlation
  ids (`TenantScoped`) and re-establish only those
  (`SetTenantContextForJob`).
- **No platform-role administration, password-reset or password-change
  flow exists.** Platform roles change only by seeding or direct
  database writes; `MfaAdminResetService::reset()` revokes a target's
  factors and recovery codes and touches no session.

### Fit check (the approved decisions against the architecture)

The approved model is implementable **without** `BYPASSRLS`, without a
second tenant-context system, without a School membership, without a
second authorization engine and without any platform-to-School role
mapping. No approved decision contradicts a hard invariant. Four facts
shape the contract rather than block it:

1. **Some School routes are gated by membership or context alone, not by
   a School capability.** Signed in as a School member with **no role**
   (DDEV `teacher@example.test`, Demo School selected), 6 School pages
   return 200: `/app/school-setup` (`SchoolSetupController::index()` —
   no capability check; it reads whether the School has a campus, an
   active academic year, grade levels and subjects),
   `/app/communications/preferences` (resolves the actor's own
   membership), and the `/app/finance`, `/app/hr`, `/app/payroll`,
   `/app/payroll/statutory` hubs (capability flags only). If an elevated
   context simply satisfied `school-context`, the first would show
   School-owned facts to an elevated actor — contrary to D7. Hence
   section 7: **elevated context is refused structurally on every School
   route that has not opted in**, rather than relying on each
   controller's own capability check.
2. **MFA denials by `RequireMfa` happen before any controller**, so
   they cannot be audited as denied elevation attempts (D17), and they
   are JSON-only. Hence section 9: the start action performs the same
   two checks through `MfaChallengeService` itself.
3. **No signed-in MFA re-verification exists.** Elevation is therefore
   only startable within 60 minutes of an MFA sign-in unless a
   re-verification endpoint is added (section 9, implementation choice).
4. **Platform capabilities are cached for 60 seconds.** Revocation by an
   out-of-band database change is therefore seen within at most 60
   seconds — the same bound every capability has today
   (`AUTHORIZATION.md`, "Revocation"). Section 11 requires in-app
   revocation paths to be immediate.

## Decision

### 1. Owner decisions (2026-09-24)

| # | Decision |
|---|---|
| **D2** | A Platform Admin **may** enter a School, **only** through explicit temporary elevation. No implicit School access, no automatic elevation, no database bypass, no capability inherited from being Platform Super Admin. |
| **D3** | Elevation is its **own mechanism**, not membership. It never creates or hides a membership, attaches a School role, impersonates a School member or changes membership history. A platform actor and a School member stay distinct principals. |
| **D4** | Starting requires an authenticated platform actor, an explicit target School, explicit confirmation, a required **reason code**, a **bounded lifetime**, a persistent visible banner and an explicit Exit. One School at a time; no nesting. Terminates on explicit exit, logout, session termination, expiry, the School becoming ineligible, and revocation of the elevation authorization. |
| **D5** | Starting requires the **existing** MFA mechanism (ADR 0037). No second MFA system. |
| **D6** | **No** second-person approval to establish elevated context in v1. This grants no permission for high-impact actions; those stay governed by their source modules, and a later ADR may require approval for a particular elevated operation. |
| **D7** | Elevation alone authorizes **zero** source modules (Students, Guardians, Documents, HR, Payroll, Finance, Payments, Compliance, Analytics, Automation, LMS, Communications, operational modules, any School-owned domain). Each keeps its own capability checks, MFA, legal gates, classification and audit. |
| **D8** | **Elevation establishes tenant context; it does not grant School capabilities.** A platform role never becomes a School role; membership-derived School capabilities stay authoritative for ordinary School routes. No "platform admin = all School capabilities", no `platform.schools.manage` = every School permission, no hidden membership. Future elevation-safe operations need an explicit contract/ADR and an explicit authorization check. |
| **D14** | v1 treatment: the elevation record (target School, platform actor, reason code, timestamps, expiry, termination reason), elevation audit history and denied-elevation audit records are **Highly Sensitive**. Not necessarily the permanent classification of every future Phase 0N record. |
| **D17** | Denied elevation attempts are audited in the **platform** ledger, as are start, explicit end, expiry and forced termination — identifiers, reason code, outcome code and safe timestamps only; no School data, no request bodies, no free text. |

### 2. The elevation principal

An elevated request is **not** "a School user with a fake role". It is a
request with these distinct parts:

| Part | Source | Meaning |
|---|---|---|
| Platform actor | the authenticated `users` row | the human; unchanged identity, no membership |
| Elevation | one persistent elevation record (section 4) | the grant being exercised: id, target School, reason code, `started_at`, `expires_at`, status |
| Target School | the record's `school_id` | the single School established in `TenantContext` |
| Authority to hold it | `platform.schools.elevate` (section 6), re-checked each request | why the actor may be elevated at all |
| Correlation | request id, correlation id (unchanged), plus the elevation id | links platform lifecycle events, source-module audit rows and logs |

Downstream code must be able to tell **ordinary membership context**
from **platform-elevated context**. The implementation adds a read-only,
request-scoped **elevation reference** (elevation id, actor id, School
id, `expires_at`) alongside the School in the request's tenant context —
set only by the web elevation resolver, cleared with the rest of the
context — and a single predicate ("is this request elevated?"). It is
observable by:

- **authorization** — `RequireSchoolContext` (section 7) and any future
  elevation-safe operation (section 8);
- **UI** — the banner's shared prop (section 12);
- **audit** — `AuditRecorder` stamps the elevation id automatically
  (section 13);
- **logs** — added to structured log context like `school_id` (never a
  metric label, CLAUDE.md rule 63).

A request is **either** a membership context **or** an elevated context,
never both (section 7).

### 3. Tenant context

Elevation establishes exactly one School through the existing
`TenantContext::set()` — the same `app.current_school_id` GUC, the same
`SchoolScope`, the same forced RLS policies. PostgreSQL sees an elevated
request exactly as it sees an ordinary School request. There are no
multiple School ids in `TenantContext`, no cross-School query, no RLS
bypass, no `pgsql_admin` use and no global School read. The School
comes from the server-side elevation record, never from request input
after the start action. A verified-domain School that differs from the
elevation's School yields **no** School context (fail closed), and
`DevOnlySchoolHeaderResolver` must not change the School of an elevated
request.

### 4. Persistent elevation record and session state

A **persistent record is required**: session state alone cannot support
revocation from outside the session, forced termination, expiry that
survives a lost session, one-active-per-actor enforcement or an audit
trail. The session holds only a pointer.

**Session side:** one key holding the elevation id (for example
`platform_elevation_id`), mutually exclusive with `active_school_id`.
Sessions are server-side (Redis), so a client cannot forge the key; a
value is honoured only if the record validates (below).

**Server side — one platform-owned table** (name chosen at
implementation, for example `school_elevations`), fields:

| Field | Notes |
|---|---|
| `id` | UUIDv7 (ADR 0019) |
| `actor_user_id` | FK `users`; the platform actor |
| `school_id` | FK `schools`; **restrict on delete** (elevation evidence must not cascade away with a School; School deletion is out of scope, D11) |
| `reason_code` | closed catalog (section 5); never free text |
| `status` | `active`, `ended`, `expired`, `terminated` (section 10) |
| `started_at`, `expires_at` | `expires_at` fixed at start, never extended |
| `ended_at`, `end_reason` | set once, on leaving `active`; `end_reason` from a closed catalog (section 10) |
| `start_request_id` | correlation to the start request |
| `created_at`, `updated_at` | |

Rules the migration must enforce in the database, not only in code:

- **one active elevation per actor** — a partial unique index on
  `actor_user_id WHERE status = 'active'`, the same pattern as
  `academic_years_one_active_per_school` (CLAUDE.md rules 64–65, 30);
  the constraint violation is the authoritative "already elevated"
  answer;
- **terminal states are immutable and nothing re-activates** — a
  trigger allowing only `active → ended | expired | terminated` with
  `ended_at`/`end_reason` written once, and rejecting any change to
  `actor_user_id`, `school_id`, `reason_code`, `started_at`,
  `expires_at` (the append-only discipline of ADR 0017, adapted to a
  record with one transition).

It is **not** RLS-protected and has no `TenantRls::enable()`: it must be
read before any School context exists, and it is platform-owned — the
same deliberate exception as `school_memberships`,
`platform_role_assignments` and `platform_audit_events`
(PHASE-0N-READINESS.md section 6). No School-scoped route or School
capability may read it. Classification: Highly Sensitive (section 14).

**Validity on every request** (all must hold, else no elevated context):
record exists; `actor_user_id` is the authenticated user; `status =
'active'`; `expires_at > now()`; the actor is not disabled; the actor
still holds `platform.schools.elevate`; the School exists and
`School::isActive()`; the actor has **no** active membership in that
School; the session has no `active_school_id`. A failed check clears the
session key and, where the record is still `active`, performs the
matching transition (section 10) with its audit event.

### 5. Reason codes and duration — values still to be chosen

- **Reason code:** a closed, versioned catalog in code, validated on
  start, stored as a code and audited as a code. **The catalog's values
  are a product/security decision to be recorded before
  implementation**; this ADR does not choose them. Free-text
  justification is out of scope and needs its own classification and
  storage decision (D17).
- **Duration:** **no repository policy defines an elevation lifetime.**
  The value is a product/security decision that must be recorded before
  implementation; this ADR deliberately does not pick one. Frozen
  constraints: it is a single configured maximum; `expires_at` is an
  absolute timestamp set at start; there is no sliding renewal and no
  extension — continuing requires a new start (new reason, new MFA
  check, new record). The owner may additionally decide whether
  `expires_at` is capped at the end of the actor's MFA assurance window.
  Existing reference values (not a choice): MFA assurance window 60
  minutes, session lifetime 120 minutes, password-confirmation window 10
  minutes.

### 6. Starting an elevation: its own authorization

Two separate questions, never collapsed into one check:

1. **May this platform actor start (and keep) an elevation?** — a
   platform capability, checked at start and on every elevated request.
2. **Once elevated, may this actor perform this source-module
   operation?** — the source module's own authorization (sections 7–8).
   Holding the answer to (1) never answers (2).

**Recommended capability: `platform.schools.elevate`** (platform
namespace, dotted `namespace.resource.action` convention), held by
`platform_super_admin` when seeded by the implementation checkpoint — not
seeded here. `platform.schools.manage` is **not** reused: its seeded
label is "Manage schools (platform)", the readiness audit mapped it to
School create/configure/lifecycle (D11), and it is held by the same role
today with no route; reusing it would make every future holder of School
administration also able to enter School context. A separate
capability keeps the grant auditable and separately revocable, and
lets a future Group/Trust principal (D1) receive an equivalent grant
without School administration rights. `platform.schools.view` is not
reused either (it is the unbuilt directory, D9(b)/D14).

Start order: authentication → `platform.schools.elevate` → MFA
(section 9) → input validation (target, reason code, confirmation) →
target eligibility → conflict checks → insert the record and write
`platform.school_elevation.started` in **one transaction** → regenerate
the session, clear `active_school_id`, store the elevation id. The start
action is a platform route in the context-neutral group (Phase 0N.1
allowlist), protected by CSRF and its own named rate limiter (one
limiter per risk profile, `RateLimiterServiceProvider` convention). It
does not need the `idempotent` middleware (CLAUDE.md rule 29): a
duplicate submission hits the partial unique index and is refused as
`already_elevated`, never a second elevation.

**Explicit confirmation** is a two-step UI: the confirm screen (a GET,
itself gated by the capability) names the target School, the reason
code and the expiry; the start POST carries the explicit confirmation.
Because there is no approval step (D6) and no server-side "pending"
state, a separate "requested" record or event is not needed: a request
that is not confirmed has no security effect.

**Target selection.** No School directory exists (D9(b), and its
classification is outside D14). v1 targets a School by an explicit
identifier entered by the actor; a School picker is a separate decision.

### 7. Effective capabilities while elevated; School routes default-deny

- Elevation adds **no** School capability. `CapabilityResolver` is not
  changed: `schoolCapabilities()` stays membership-only, so every
  existing `capability:` middleware, `AuthorizesCapability` check and
  `canInSchool()` call keeps denying an elevated actor, and every
  Dashboard nav flag stays `false`.
- **Structural default-deny.** `RequireSchoolContext` will accept a
  valid elevation as a way to establish School context **only on routes
  that explicitly opt in** (a route parameter or equivalent route
  metadata, covered by the Phase 0N.1 route guard). On every other
  School route an elevated request is refused **before** route model
  binding and the controller with **403** (`school_elevation_not_permitted`
  for JSON). This closes the fit-check finding: membership-only pages
  such as `/app/school-setup` never render for an elevated actor. In the
  first implementation **no** School route opts in.
- **Mutual exclusion.** A request is either membership context (today's
  checks) or elevated context (section 4 validity), never both: starting
  requires no active membership in the target School, and an elevated
  actor who becomes a member of it mid-elevation is terminated
  (`membership_conflict`). Ordinary School selection is refused while an
  elevation is active (section 12).

### 8. Future source-module integration (not built)

An operation becomes usable while elevated only when **all** of these
exist:

1. its own ADR or ADR amendment naming the operation, why a platform
   actor needs it, its data-classification review (D7) and whether it
   needs second-person approval (D6);
2. the route explicitly opts in to elevated context (section 7);
3. an explicit authorization check requiring **both** a valid elevation
   for the current School **and** a narrowly scoped platform capability
   for that operation (a new `platform.*` key per operation or small
   group, checked through the existing `CapabilityResolver::can()` —
   no second engine, no School-role mapping);
4. the module's own validation, MFA (`mfa` where it already applies),
   legal gates and audit, unchanged;
5. tests: allowed when elevated with the capability; denied when
   elevated without it, when merely a platform admin, when elevated into
   another School, and for an ordinary member lacking the School
   capability.

No blanket "elevated read-only" mode, no module-wide opt-in, and no
Layer 5 derived surface (Analytics, Compliance, Automation, AI) reaches
School data through elevation.

### 9. MFA

- **Trigger point:** the start action (and the confirm screen may show
  the same precondition). Not re-required on every elevated request;
  a future elevation-safe operation that composes `mfa` still requires
  fresh assurance on its own.
- **Mechanism:** the start Application service calls
  `MfaChallengeService::userHasActiveFactor()` and `hasValidAssurance()`
  — the exact checks `RequireMfa` performs — and answers with the
  existing error codes: no active factor → **403
  `mfa_required_not_enrolled`**; stale or missing assurance → **401
  `mfa_step_up_required`** (freshness = the existing
  `mfa.assurance_window_minutes`, default 60; no new interval). Doing
  it in the service rather than composing the `mfa` middleware is what
  lets the denial be audited (D17). No second MFA system: same factors,
  same session key, same freshness helper, same codes.
- **Not enrolled:** refused and audited; the actor enrolls at
  `/app/account/security` (existing, context-neutral) and signs in again
  to obtain assurance. Platform accounts are not exempted because no
  platform action requires MFA today.
- **Challenge failure:** a failed TOTP or recovery code is handled where
  it happens today (login stage 2, `auth.mfa_challenge_failed`); no
  elevation is started without valid assurance.
- **Smallest prerequisite:** none is strictly required — a fresh sign-in
  restores assurance. Recommended for the implementation checkpoint: a
  signed-in re-verification endpoint that reuses
  `MfaChallengeService::verifyTotp()` / recovery-code consumption and
  `establishAssurance()`, an actor-keyed limiter and the existing MFA
  audit actions. It re-establishes the same session assurance (within
  ADR 0037's assurance-window model, which deferred only per-action
  step-up beyond that model), so it is not a second MFA system.
- **Web rendering:** the start flow must present both MFA outcomes as
  page-level messages (or be JS-driven like the existing `mfa` routes);
  `RequireMfa`'s JSON-only behaviour is not changed.

### 10. Lifecycle

Four states; no others are needed.

| From | To | Cause (`end_reason`) | Audit event |
|---|---|---|---|
| — | `active` | start | `platform.school_elevation.started` |
| `active` | `ended` | explicit Exit (`exited`), logout (`logout`) | `platform.school_elevation.ended` |
| `active` | `expired` | `expires_at` reached (`expired`) | `platform.school_elevation.expired` |
| `active` | `terminated` | `actor_disabled`, `capability_revoked`, `school_ineligible`, `membership_conflict`, `mfa_factor_revoked` | `platform.school_elevation.terminated` |

- `expires_at` is authoritative: a record past it is never honoured even
  if its status still reads `active`. The transition (and its single
  audit event) is written when first detected — on the next request, or
  by a scheduled sweep so expiry is recorded even when no request comes.
- Terminal states are final (section 4 trigger). An expired or ended
  elevation never reactivates; continuing requires a new start.
- **Session termination without logout** (expiry, deletion, sign-out
  elsewhere) cannot be observed server-side: the session's pointer
  disappears with it, so the elevation is unusable immediately, and the
  record reaches `expired` at `expires_at`. Until then it still
  occupies the actor's one active slot: from a new session the actor
  sees that an elevation is active and may **end it explicitly**
  (`exited`); a new start before that is refused (`already_elevated`).

### 11. Invalidation

Immediate (checked on every elevated request, section 4):

- **explicit exit, logout** — `LoginController::destroy()` ends the
  session's elevation before invalidating the session;
- **expiry**;
- **actor disabled** (`users.is_disabled`);
- **elevation authorization revoked** — `platform.schools.elevate` no
  longer held. Seen within the existing 60-second capability cache
  bound for an out-of-band database change; any **in-app** revocation
  path (a future platform-role administration, D12) must forget the
  actor's platform capability cache and terminate the actor's active
  elevation in the same transaction;
- **target School ineligible** (`School::isActive()` false). Any future
  School lifecycle action (D11) must terminate active elevations into
  that School eagerly;
- **session ends** (pointer lost, section 10);
- **membership conflict** (section 7).

Also required: **MFA factor reset by an administrator**
(`MfaAdminResetService`) or **self-disable of MFA** terminates the
actor's active elevation (`mfa_factor_revoked`) — the precondition it
was started under no longer exists. **Password change or reset:** no
such flow exists; when one is built it must terminate active
elevations. **Platform-role removal** is covered by capability
revocation above.

### 12. UI contract

- While elevated, **every** signed-in page shows a persistent banner
  (the shared layout, `AccountLayout.vue`, from a shared Inertia prop)
  identifying: that the account is **elevated**, the **target School**
  (name), the **expiry**, and an **Exit** action (POST, CSRF, works even
  if the elevation has just become invalid). Visual design is not
  specified.
- The shared prop carries only the elevation id, School name and expiry
  — no other School data.
- Normal School switching is visually and technically distinct: the
  `/app` School list and `POST /app/schools/{school}/activate` are
  refused while an elevation is active ("Exit elevation first"); exit
  before any ordinary context change. Starting an elevation clears an
  ordinary selection, and the confirm screen says so.
- Exit and every termination regenerate the session, clear the pointer,
  rotate Inertia's history key (the Phase 0N.1 pattern) so Back cannot
  redraw elevated pages, and land on `/app` with a notice.
- When the elevation lapses mid-use, Phase 0N.1 behaviour applies: page
  requests return to `/app`, mutations get 409 `school_context_required`.

### 13. Audit contract

**Platform ledger** (`platform_audit_events` via `AuditRecorder::platform()`):

| Event | Subject | Metadata allowlist |
|---|---|---|
| `platform.school_elevation.denied` | the target School when it exists, else none | `outcome_code`, `reason_code` (if a valid code was given), `elevation_id` (only for `elevation_reference_invalid`) |
| `platform.school_elevation.started` | target School | `elevation_id`, `reason_code`, `expires_at` |
| `platform.school_elevation.ended` | target School | `elevation_id`, `end_reason` (`exited`, `logout`) |
| `platform.school_elevation.expired` | target School | `elevation_id`, `expires_at` |
| `platform.school_elevation.terminated` | target School | `elevation_id`, `end_reason` |

`actor_user_id`, `request_id` and `occurred_at` are the envelope;
`ip_address`/`user_agent` are recorded on `started` and `denied`, as
`auth.*` events already do. Nothing else: no School data, no request
body, no free text, no School name. No separate `requested` event
(section 6). Input validation failures (unknown reason code, missing
confirmation) are ordinary `422`s and not audited, like validation
everywhere else.

**Source-module audit linkage.** Every School-ledger row written while a
request is elevated must carry the elevation id, so a later review can
connect an action to the elevation without duplicating source data. It
belongs in the **envelope** — a nullable `elevation_id` column on
`school_audit_events`, filled automatically by `AuditRecorder::school()`
from the request's elevation reference — not in `metadata`: metadata is
per-caller (a module could forget it) and the School audit-log review
(ADR 0042, Phase 0L.4) serializes envelope columns only. It lands with
the substrate even though v1 has no elevation-safe operation. A future
elevation-safe operation that dispatches a queued job must carry the
elevation id as an **audit attribution field only** — a job never
resolves, re-validates or extends an elevation.

**Open, not decided here:** whether the School itself should see
elevation lifecycle events or the elevation id in its own audit-log
review (readiness section 8, "Both ledgers?"). It exposes platform staff
identity to School administrators; it belongs with D16 / ADR 0042 §13.
v1 writes lifecycle events to the platform ledger only.

### 14. Classification (D14)

The elevation record and every field in section 4, the five platform
audit events above, denied-elevation records, and the `elevation_id`
envelope value are **Highly Sensitive** for v1 (`DATA-CLASSIFICATION.md`).
Retention follows the repository-wide retention gate ([LEGAL REVIEW
REQUIRED]).

### 15. Denials

Every denial fails closed: no elevation record, no `TenantContext`, no
School data in the response or the audit row.

| Case | Response | Audit `outcome_code` |
|---|---|---|
| Lacks `platform.schools.elevate` | 403 | `capability_missing` |
| No active MFA factor | 403 `mfa_required_not_enrolled` | `mfa_not_enrolled` |
| MFA assurance missing or stale | 401 `mfa_step_up_required` | `mfa_assurance_required` |
| Target School does not exist | one uniform refusal ("That School cannot be entered.") | `target_not_found` |
| Target School not active | same uniform refusal | `target_inactive` |
| Malformed target identifier | same uniform refusal | `target_malformed` (no subject) |
| Actor is a member of the target | refusal ("use School selection") | `actor_is_member` |
| An elevation is already active (any School) | 409 | `already_elevated` |
| Unknown reason code, no confirmation | 422 | not audited (validation) |
| Session points at an expired record | no context; Phase 0N.1 landing | the `expired` transition, once |
| Session points at a record that does not exist, belongs to another actor or is terminal | no context; pointer cleared | `elevation_reference_invalid` |

### 16. Phase 0N.1 interaction

Phase 0N.1 is preserved. `RequireSchoolContext` will accept a valid
elevation as one legitimate source of School context, on opted-in routes
only (section 7); a user without a School selection and a Platform Admin
without an active elevation still land on `/app` exactly as today; the
start and exit routes join the context-neutral allowlist in
`SchoolContextRouteGuardTest`. No Phase 0N.1 code changes in this ADR.

### 17. API boundary

Elevation is a **web session flow only**.

- **`/api/v1`**: unchanged and excluded. `school-membership` stays
  membership-only (an elevated platform admin's token still gets 404),
  and elevation is never resolved on an `api` route. The `api` group has
  no session today; if `statefulApi()` is ever enabled, elevation must
  still not be resolved there (a guard test asserts it).
- **Internal APIs** (`/api/internal/*`: AI Gateway tools and audit,
  operations status): excluded. `AiContextTokenService` must never mint
  an AI context token from an elevated context — cross-School and
  elevated AI stay outside scope (ADR 0023; Phase 0M is BLOCKED).
- No new token type, no elevation header, no elevation query parameter.

### 18. Exclusions

- **Not cross-School reporting.** One elevated request sees one School.
  No cross-School Analytics (ADR 0040 §4), Compliance (ADR 0042),
  Automation (ADR 0043) or AI. D15 stays open.
- **Not group administration.** The primitive is "an actor with an
  elevation authority establishes one School"; a future Group/Trust
  principal (D1) might reuse it with a different authority source
  limited to member Schools, but no group field, principal or grant is
  defined here and group governance (D18) stays separate.
- **Not School lifecycle or platform administration.** Elevation grants
  no authority to create, suspend, archive or delete Schools, grant
  platform roles or administer memberships (D11–D13), and
  `platform.schools.elevate` does not imply `platform.schools.manage` or
  the reverse.

### 19. Frozen invariants

1. One School per elevated request, through the existing `TenantContext`.
2. RLS stays enabled and forced on every tenant table.
3. No `BYPASSRLS`, superuser or `pgsql_admin` use for elevation.
4. Elevation is not membership; no membership or role row is ever
   created, hidden or altered by it.
5. Elevation grants no School capability by itself; `schoolCapabilities()`
   stays membership-only.
6. Source modules stay authoritative; School routes refuse elevated
   context unless they explicitly opt in.
7. Elevation is temporary: absolute expiry, no extension, no
   reactivation.
8. Elevation is visible on every signed-in page.
9. Start, end, expiry and termination are audited in the platform
   ledger; School-ledger rows written while elevated carry the elevation
   id.
10. Denied attempts are audited.
11. No hidden or automatic elevation — only an explicit, confirmed,
    MFA-assured, reason-coded start by the actor.
12. No cross-School query mode.
13. At most one active elevation per actor, enforced by the database.
14. A request is membership context or elevated context, never both.
15. Web session only; never `/api/v1`, internal APIs or AI.

## Alternatives considered

1. **Grant a time-boxed real membership** (readiness D3(b)).
   Rejected by D3: it rewrites membership history, makes a platform
   actor indistinguishable from a School member and would inherit every
   membership-only page.
2. **Map `platform_super_admin` to a School role while elevated.**
   Rejected by D8: a hidden platform→School mapping is the implicit
   bypass ADR 0004 forbids, and it would silently widen every time a
   School role gains a capability.
3. **Session-only elevation, no table.** Rejected: no revocation from
   outside the session, no forced termination, no one-per-actor
   guarantee, and expiry would depend on a session that may be lost.
4. **Reuse `platform.schools.manage`.** Rejected (section 6).
5. **Let elevation satisfy `school-context` everywhere and rely on each
   controller's capability check.** Rejected by the fit check:
   `/app/school-setup` and other membership-only pages have no
   capability check.
6. **Compose the existing `mfa` middleware on the start route.**
   Rejected as the only control: its denials occur before the
   controller and could not be audited (D17). The same service checks
   are used instead.
7. **An RLS "platform" policy or a separate database role for elevated
   requests.** Rejected: ADR 0021 / CLAUDE.md rule 26 — platform power
   is an application-layer grant, never a database privilege.

## Consequences

- Phase 0N's elevation decisions (D2–D8, D14, D17) are recorded;
  `PHASE-0N-READINESS.md` stays **BLOCKED** on D1, D11, D12, D13, D15,
  D16 and D18, and on the implementation-time values below.
- Before implementation the owner must record: the **elevation
  duration** and whether it is capped by MFA assurance (section 5); the
  **reason-code catalog** (section 5); **re-verification endpoint vs.
  re-login** for stale MFA (section 9); and the **target-selection**
  UX without a School directory (section 6).
- The implementation checkpoint amends, in the same change: CLAUDE.md
  rule 20 and `TENANCY.md` (a third trusted source of web School
  context: a session elevation pointer re-validated against an active
  elevation record), `TENANCY.md`'s list of School-referencing tables
  without RLS, `AUTHORIZATION.md` (implemented, not planned), and the
  Phase 0N.1 route-guard allowlist.
- Every future elevation-safe operation needs its own ADR/amendment
  (section 8); none exists after the first implementation.

## Proposed implementation checkpoint (not started)

**Phase 0N.3 — Platform Elevation Substrate** (number and title for the
owner to confirm). Proves the substrate only, with **zero** source-module
elevated permissions:

1. Seed `platform.schools.elevate` to `platform_super_admin`.
2. Migration: the elevation table (section 4: partial unique index,
   transition trigger, restrict-on-delete FK, documented no-RLS
   exception) and the nullable `school_audit_events.elevation_id`
   envelope column; both with working `down()`.
3. An Application service for start / exit / expire / terminate with the
   reason-code and end-reason catalogs and the configured duration.
4. MFA through `MfaChallengeService` (section 9), plus the re-verification
   endpoint if chosen.
5. Web-only resolution of the session pointer into the request's tenant
   context with the elevation reference; `RequireSchoolContext` accepts
   it only on opted-in routes (none) and returns 403 on all others;
   `DevOnlySchoolHeaderResolver` cannot alter an elevated request.
6. Start (confirm + POST) and exit routes in the context-neutral group,
   named rate limiter, allowlist update.
7. Invalidation: per-request validity, logout, MFA reset / self-disable,
   and a scheduled expiry sweep.
8. Banner (shared prop + `AccountLayout.vue`), `/app` elevated state,
   ordinary selection refused while elevated.
9. The five platform audit events and `AuditRecorder::school()`
   stamping `elevation_id`.
10. Tests: every denial row in section 15 (allow and deny, audit rows
    and metadata allowlist); two real processes racing two starts
    (exactly one active); no reactivation after expiry; each
    invalidation cause; an elevated actor gets 403 on **every** School
    route (route-guard probe) and still has zero School capabilities;
    no membership or role row created; raw-SQL RLS test that an elevated
    request sees exactly one School; `/api/v1` unchanged (404); a
    Platform Admin without elevation and ordinary users unchanged from
    Phase 0N.1; DDEV browser review of start, banner, exit, expiry.
