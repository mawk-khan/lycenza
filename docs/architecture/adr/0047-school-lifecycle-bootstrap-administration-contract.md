# ADR 0047: School Lifecycle & Bootstrap Administration Contract (Phase 0N.8)

- Status: Accepted; foundation implemented in Phase 0N.9 (see
  "Implementation amendment" at the end)
- Date: 2026-09-25 (Phase 0N.8); amended 2026-09-25 (Phase 0N.9)

## Context

`docs/architecture/PHASE-0N-READINESS.md` left three Phase 0N decisions
open after ADR 0046: **D11** (School lifecycle and what suspension stops),
**D13** (platform-level School membership administration) and **D15**
(Group / cross-School reporting). ADR 0046 answered *who* may create a
School (Platform Super Admin, through the root-reserved
`platform.schools.manage`) and left *what* creation, activation and
suspension mean to D11 (ADR 0046 §12). **On 2026-09-25 the product owner
decided D11 for create/activate/suspend/resume and D13 for v1** (section
1). This ADR is the implementation contract. It changes no code, adds no
migration, seeds nothing and modifies no membership. Archive and delete
stay out of scope behind a legal gate (section 12). D15 is untouched
(section 17).

### School lifecycle today (verified on `060c805`, code and DDEV)

| Fact | Evidence |
|---|---|
| `schools.status` is the platform tenant-lifecycle column: `varchar`, `NOT NULL`, default `'active'`, **no CHECK constraint** | DDEV catalog; `School` model docblock; `docs/modules/ORGANIZATION.md` documents the values `active` / `suspended` / `archived` |
| The only lifecycle predicate is `School::isActive()` (`status === 'active'`) | `app/Models/School.php` |
| No application code writes `schools.status`; Schools come only from `SchoolFactory` (`'active'`, `suspended()` state) and `DemoDataBuilder` (`'active'`). DDEV holds 2 Schools, both `active` | code search; DDEV |
| Required columns: `name`, `slug` (unique). `timezone` (`Asia/Kolkata`), `default_locale` (`en`), `country_code` (`IN`) have defaults; `legal_name`, `code` (unique, nullable, `NormalizesCode`), `website`, `email`, `phone`, address fields and `education_board_id` are nullable. `id` is a UUIDv7 (`GeneratesUuidV7`) | DDEV `information_schema`; model |
| Domains are optional: `school_domains` (`domain`, `type` default `platform_subdomain`, `is_primary`, `verified_at`) — `VerifiedSchoolDomain` resolves only verified rows | DDEV; `App\Support\Tenancy\VerifiedSchoolDomain` |
| `schools` has no RLS (it is the tenant root); `membership_role_assignments` has **forced** RLS; `school_memberships` has none | `pg_class` |
| `school_memberships`: `status` default `'invited'`, **no CHECK**; values in use `invited`, `active`, `suspended`; `UNIQUE (user_id, school_id)` | DDEV; `SchoolMembership::active()` |
| `membership_role_assignments`: `school_id`, `school_membership_id`, `role_id`, `assigned_by_user_id`, `assigned_at`; wrong-scope roles refused by trigger (CLAUDE.md rule 25); no revocation columns | DDEV |
| System School roles: `school_admin`, `principal` (plus `demo.*` demo roles). `school_admin` holds 111 capabilities, including every `school.*` administrative key (`school.members.manage`, `school.roles.manage`, `school.profile.manage`, `school.settings.manage`, `school.audit.view`, …) | DDEV `role_capabilities` |
| School capabilities require an **active membership** but ignore School status (`CapabilityResolver`); School status is enforced at the entry points listed below | `app/Support/Authorization/CapabilityResolver.php` |
| 148 foreign keys reference `schools`; **147 are `ON DELETE CASCADE`**. The runtime role `school_os_app` holds `SELECT, INSERT, UPDATE, DELETE` on `schools` (and `DELETE` on `school_memberships`, `membership_role_assignments`). No application code deletes a School; **53 test files** tear down with `$school->delete()` on the runtime connection | DDEV catalog / `has_table_privilege`; code search |
| `platform.schools.manage` is seeded, held only by `platform_super_admin`, **root-reserved** by the 0N.7 triggers, and checked nowhere except the unused `DashboardController` flag `canManagePlatformSchools` | ADR 0046 amendment; code search |

### Where School status is enforced today

Enforced (fails closed for a non-`active` School): `ResolveSchoolContext`
(verified-domain and session paths set no context), `RequireSchoolContext`
(no valid School → web redirect to `/app` clearing the stale
`active_school_id`, JSON 409 `school_context_required`),
`SchoolSwitchController` (selection refused), `Api\EnsureSchoolMembershipContext`
and `Api\V1\SchoolContextController` (404, non-disclosing),
`DevOnlySchoolHeaderResolver`, `SchoolElevationService` (start refused
`target_inactive`; an active elevation is ended lazily on its next request
with `school_ineligible`), and the Group view (`canEnter` requires
`active`).

**Not enforced anywhere:** queued jobs (`SetTenantContextForJob` loads the
School by id only), outbox consumers, the redispatch/publish commands that
walk every School, AI context-token minting (`AiGatewayClient::invokeTool()`
/ `complete()`), the internal AI endpoints (`AiToolController`,
`AiCompletionAuthorizationController`), and the public Guardian invitation
acceptance route (`/invitations/{school}/{token}`,
`GuardianAccountActivationService`). The Dashboard lists a user's active
memberships without regard to School status (selecting a non-active School
is then refused).

### Background substrates (audited)

| Substrate | Job / command | Status model (DB CHECK) | Retry model |
|---|---|---|---|
| Outbox | `platform:outbox-dispatch` (every minute, global), `ProcessOutboxEventJob` (`$tries = 5`) | `pending`, `dispatched`, `failed` | queue retries; consumers idempotent |
| Webhooks | `WebhookFanoutConsumer` → `DeliverWebhookJob` (`$tries = 1`); `platform:webhook-deliveries-redispatch` (per School: `retrying` due, stale `delivering`) | `pending`, `delivering`, `delivered`, `retrying`, `failed`, `abandoned` | domain retry via `next_attempt_at`, lease |
| Communications | `ProcessCommunicationDeliveryJob` (`$tries = 1`); `platform:communication-deliveries-redispatch` (per School: `queued` due, stale `sending`); `scheduleRetry()` = `queued` + `next_attempt_at` | `pending`, `queued`, `sending`, `accepted`, `sent`, `delivered`, `read`, `failed`, `bounced`, `rejected`, `expired`, `cancelled` | domain retry, lease |
| Announcements | `communications:publish-scheduled` (per School) | `draft`, `pending_approval`, `approved`, `rejected`, `scheduled`, `published`, `cancelled` | next run |
| Automation | `AutomationTriggerConsumer` → `RunAutomationExecutionJob` (`$tries = 1`); `automation:executions-redispatch` (per School: `pending` due, stale `running`) | `pending`, `running`, `succeeded`, `skipped`, `failed`, `abandoned`; `outcome_code` (precedent: `skipped` / `automation_disabled_for_school`) | domain retry, lease |
| In-app notice | `NotifyActorOfSettingChangeConsumer` → Communications `in_app` | as Communications | — |
| Maintenance | `platform:expire-school-elevations` (every minute), `platform:idempotency-prune`, `platform:webhook-deliveries-prune` (daily), scheduler heartbeat | — | — |
| Demo | `RecordSchoolAuditPingJob` (Phase 0B; writes a School audit row only) | — | — |

There is **no inbound payment-provider callback route** today (payments
are admin/API reads), and no signed download URL bypasses School-context
routes (downloads stream through School routes).

## Decision

### 1. Owner decisions (2026-09-25)

1. **Creation** is Platform Super Admin only (ADR 0046 / D12). A new School
   starts **not operational**; creation establishes only the School record
   and the bootstrap administration relationship — no activation, no
   Group membership, no cross-School access, no elevation, no tenant-domain
   business records.
2. **Lifecycle** v1: `provisioning → active → suspended → active`.
   Operations CREATE, ACTIVATE, SUSPEND, RESUME. **No ARCHIVE, no
   DELETE.** No transition deletes data.
3. **Archive/delete** out of scope, gated on a retention/legal decision
   (section 12). No application School-delete; the implementation adds a
   database guard (section 12).
4. **Activation requires** an active user with an active membership and
   School Admin authority in that exact School. Platform authority is not a
   substitute; creating a School does not make its creator School Admin.
5. **Bootstrap exception:** before first activation only, the Platform
   Super Admin may establish or replace the bootstrap School Admin — a
   real, ordinary membership and ordinary School Admin role. The path
   closes permanently at first activation.
6. **Bootstrap target:** an exact, existing, enabled user; no user
   provisioning is built here. With no suitable user, activation stays
   blocked until one is provisioned through an approved Identity path.
7. **One coherent transaction** for School creation, bootstrap membership,
   role assignment and audit; no automatic activation.
8. **Replacement** before activation only; ends the previous relationship
   by normal Identity semantics, keeps history, deletes no evidence.
9. **D13 (broader): ongoing platform membership administration is NOT
   authorized in v1.** After activation no platform surface adds/removes
   members, grants/revokes School roles, appoints School Admins or manages
   Teacher/Student/Guardian memberships.
10. **Loss of every School Admin after activation** does not reopen the
    bootstrap path; it is a future *School administrative recovery /
    break-glass* decision with its own security contract.
11. **Activation is explicit**, separate and privileged: pre-active state,
    active bootstrap admin, School eligible, platform capability, fresh
    MFA, explicit confirmation. No invented setup requirements.
12. **Fresh MFA** (`MfaReverificationService`) for ACTIVATE, SUSPEND,
    RESUME, and — evaluated here — CREATE and bootstrap replacement
    (section 7).
13. **Suspension is reversible** and deletes, revokes, removes or archives
    nothing; RESUME recreates nothing.
14. **Suspension reason codes** (closed): `security_incident`,
    `administrative_hold`, `school_requested`. No free text.
15. **Suspension stops** web, API, elevation, Automation, AI,
    Communications, webhooks and School business background work
    (section 8).
16. **Platform safety and maintenance continue** (section 9).
17. **In-flight work re-checks at execution time**; no infinite retry
    (section 8).
18. Communications/webhooks keep records but perform no external delivery
    while suspended, using existing statuses.
19. **Resume** requires `suspended`, authorization, fresh MFA and
    confirmation; no global replay; per-substrate behaviour (section 10).
20. **Groups:** creation adds no Group; suspension removes no Group
    membership; the Group view may show the School's approved metadata and
    status; Group-derived elevation into it is denied.
21. **Elevation** into a suspended School is denied; an active one is
    terminated immediately with a lifecycle-specific reason.
22. **Sessions:** no global session enumeration if every request fails
    closed; the stale `active_school_id` is cleared; no School data on the
    next request; Phase 0N.1 behaviour preserved.
23–31. Classification, audit, database safety, identifiers, authorization,
    the 0N.9 checkpoint, the D15 boundary and readiness — sections 11–18.

### 2. Lifecycle model

The existing `schools.status` column is the lifecycle — **no second
column**. It gains one value:

| Status | Meaning | Operational (`isActive()`) | Reached by |
|---|---|---|---|
| `provisioning` | **new** — created, not yet activated; bootstrap administration open | no | CREATE only |
| `active` | operational | yes | ACTIVATE (from `provisioning`), RESUME (from `suspended`) |
| `suspended` | non-operational, reversible | no | SUSPEND (from `active`) |
| `archived` | **existing documented value, kept for compatibility; no application transition into or out of it** | no | nothing in v1 (legal gate, section 12) |

`provisioning` is chosen because no existing value means "created, never
operational" (`suspended` would wrongly imply a prior active period and
would make "has this School ever been activated?" unanswerable; `archived`
is terminal and legally gated). Because the predicate every entry point
already uses is `isActive()`, a `provisioning` School is non-operational
through exactly the checks that already make a `suspended` School
non-operational — no new check is needed for web/API/elevation.

Database contract (Phase 0N.9):

1. `schools_status_check`: `status IN ('provisioning', 'active',
   'suspended', 'archived')`.
2. **Column default becomes `provisioning`** (fail closed: a School
   inserted without an explicit status is never operational).
   `SchoolFactory` and `DemoDataBuilder` already set `active` explicitly;
   the one raw `schools` insert in tests is reviewed.
3. Transition trigger (`BEFORE UPDATE OF status`, runtime-relevant):
   permits only `provisioning → active`, `active → suspended`,
   `suspended → active` (and unchanged status). Every other change —
   into `provisioning`, into or out of `archived`, `provisioning →
   suspended` — is refused. Therefore `provisioning` can never be
   re-entered, which is what makes the bootstrap path close
   **permanently** without an extra column (section 4).
4. Inserts are not trigger-restricted (fixtures and the demo insert
   `active` Schools); the creation service always inserts `provisioning`,
   and an architecture guard test asserts no other application code
   creates a School.

### 3. Creation (CREATE)

Input: `name` and `slug` (the only required columns; `slug` unique,
lowercase, the existing URL-safe convention), optionally `code`
(`NormalizesCode`, unique) — nothing else. **No domain is required**:
`school_domains` is optional in the current architecture and domain
verification is not part of lifecycle. No random extra identifiers (the
UUIDv7 `id` is the identity). Profile fields remain School-side
(`school.profile.manage`, ORGANIZATION.md).

The transaction (one `DB::transaction()`), in order:

1. Authorize (`platform.schools.manage`), verify fresh MFA (section 7),
   validate input, resolve the bootstrap user (section 4).
2. Insert the School with `status = 'provisioning'`.
3. Inside `TenantContext::withSchool($school, …)` (the sanctioned way to
   write the forced-RLS `membership_role_assignments`): create the
   bootstrap user's `school_memberships` row (`status = 'active'`) and one
   `membership_role_assignments` row for the system role `school_admin`
   (`assigned_by_user_id` = the platform actor).
4. Platform audit `platform.school.created` and
   `platform.school.bootstrap_admin_assigned` (section 13).

`set_config(..., false)` inside the outer transaction is itself
transactional in PostgreSQL, so a rollback leaves neither the School, the
membership, the role assignment nor the audit rows. **Fallback** (only if
the implementation finds `withSchool` cannot compose inside the outer
transaction): two transactions, School + audit first, then the bootstrap
relationship; the intermediate state is a `provisioning` School with no
administrator, which is non-operational by construction and cannot be
activated (section 5), so no operational School without an admin can
exist either way.

Creation writes **nothing else**: no Group membership, no elevation, no
Campus, Academic Year, setting, feature-flag or module record, no
outbox event (no subscriber could exist for a new School), no School audit
row (section 13).

A duplicate submission fails on the `slug` unique constraint (a clean
422 after `Rule::unique()`), so CREATE needs no `idempotent` middleware
(CLAUDE.md rule 29 evaluated): it is a context-neutral web action, never
an API endpoint.

### 4. Bootstrap School Admin

- **Identifier:** the exact email (case-insensitive, compared lowercased)
  or the user's UUID — the resolution `PlatformRoleGovernanceService`
  already uses for platform grants (0N.7). No search, no suggestions.
  Unknown or disabled (`users.is_disabled`) → plain validation error. No
  user is created or invited.
- **Not the acting platform operator.** Nominating oneself is refused
  (the ADR 0046 no-self-grant principle applied to School authority);
  creating a School never makes its creator School Admin.
- **Real and ordinary:** an ordinary `school_memberships` row and an
  ordinary `school_admin` assignment — the same rows a seeded School Admin
  has. No flag, no hidden or "platform" membership, no elevation, no
  platform-to-School role mapping (CLAUDE.md rules 83, 85). The platform
  actor gets no membership.
- **Open only while `provisioning`.** Every bootstrap write first locks the
  School row (`SELECT … FOR UPDATE`) and requires `status =
  'provisioning'`; since the database never lets a School return to
  `provisioning` (section 2), the path is **permanently closed** after
  first activation, including after suspend/resume.
- **Replacement** (before activation only; fresh MFA): in one transaction
  under the School row lock — set the previous bootstrap membership's
  `status` to `suspended` (the existing "not active" membership value;
  the membership and its role-assignment row stay as history and grant
  nothing, because capabilities require an active membership), then
  create or reactivate the new user's membership with a `school_admin`
  assignment, then audit `platform.school.bootstrap_admin_replaced`.
  Nothing is deleted. Re-nominating a previously replaced user reactivates
  their existing membership row (`UNIQUE (user_id, school_id)` allows no
  second row), adding a `school_admin` assignment only if none exists. A
  School may briefly hold more than one active School Admin only if the
  operator keeps the previous one — v1 replacement always ends the
  previous one; adding extra admins is the School's own job after
  activation.
- **Never an active School without an admin:** replacement is refused
  unless the School is `provisioning`, so an active School is never
  touched by this path.
- **Ownership:** the only platform code allowed to write
  `school_memberships` / `membership_role_assignments` is the bootstrap
  application service (e.g.
  `App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService`),
  enforced by an architecture guard test extending the 0N.7 one that keeps
  `PlatformRoleGovernanceService` away from those models.

### 5. Activation (ACTIVATE)

Preconditions, all checked under the School row lock in one transaction:

1. `status = 'provisioning'` (conditional update `WHERE status =
   'provisioning'`; a second submission is refused, not repeated).
2. **At least one qualifying administrator:** a user who is not disabled,
   with an `active` membership in this exact School whose assigned roles
   grant **both `school.members.manage` and `school.roles.manage`** — the
   capabilities that let the School administer itself afterwards. This is
   a capability test, not a role-name test (CLAUDE.md rule 24); the
   bootstrap `school_admin` role satisfies it.
3. The School is otherwise eligible under the current schema: `name` and
   `slug` present (already `NOT NULL`). **No other setup requirement** —
   no Campus, Academic Year, domain, board or profile field — is invented.
4. Actor holds `platform.schools.manage`; fresh MFA; explicit
   confirmation (section 7).

Effect: `provisioning → active`, audit `platform.school.activated`.
Nothing else changes; the School's own admins take over from here.

### 6. D13 — platform membership administration (v1)

**Bootstrap exception only; ongoing platform membership administration
is not authorized.** After activation no platform surface adds or removes
School members, grants or revokes School roles, changes membership status,
appoints additional School Admins or manages Teacher/Student/Guardian
memberships. Those stay with the School/Identity administration model
(`school.members.*`, `school.roles.*`; the School-side UI is its own
future unit, readiness §14). The lifecycle UI offers no membership action
once a School is `active` or `suspended`. Recovering a School that has
lost every admin is a **future School administrative recovery /
break-glass decision** needing its own security contract; until then it is
an out-of-band operational matter, never a reopening of the bootstrap
path.

### 7. Authorization, MFA and confirmation

- **Capability: `platform.schools.manage` for all six operations** (CREATE,
  bootstrap assign/replace, ACTIVATE, SUSPEND, RESUME) and the page that
  lists Schools for them. Narrower keys (`platform.schools.create /
  .activate / .suspend`) were evaluated and rejected for v1: the
  capability is root-reserved by database trigger (0N.7), so only the
  root could hold any of them — splitting adds catalog without adding
  separation. If a future non-root platform role must suspend Schools
  (e.g. a security-operations role), a later ADR introduces a narrower,
  non-root-reserved key then. **Nothing is seeded now.**
  `platform.schools.view` stays reserved (no directory beyond the
  lifecycle page).
- Checked through the capability resolver (`canPlatform`) — never the
  `platform_super_admin` name (CLAUDE.md rules 24, 85).
- **Fresh MFA for every lifecycle and bootstrap operation**, including
  CREATE (it creates a tenant and grants School authority) and bootstrap
  replacement (a permission grant). Final rule: *every state-changing
  School lifecycle or bootstrap action requires a current in-session TOTP
  re-verification through `MfaReverificationService::reverify()`,
  immediately before the action, on an actor with an active factor
  (`hasActiveFactor()`); stale login assurance is not enough.* No new MFA
  mechanism. Reads (the School list) need the ordinary MFA assurance used
  for platform audit review (0N.7 refinement 1), not a fresh code.
- **Explicit confirmation:** the ADR 0044 pattern — a review page showing
  the School (name, slug), its current and target status and, for SUSPEND,
  the chosen reason code, then a separate confirm POST carrying the MFA
  code.
- Context-neutral web routes under `/app/platform/schools…`: no
  `school-context` middleware, no `TenantContext` except the scoped
  `withSchool` bootstrap write, **no API route**, a per-actor rate limiter
  (like the elevation limiter). No School route is enabled for elevation
  (CLAUDE.md rule 83).
- Concurrency: every operation locks the School row and uses conditional
  transitions, so racing operations serialize and a duplicate submission
  is refused as an invalid transition (no `idempotent` middleware needed).

### 8. Suspension (SUSPEND)

Precondition `status = 'active'`; reason code required from the closed
set; fresh MFA; confirmation. In **one transaction** under the School row
lock: `active → suspended`; terminate every active elevation into the
School (Platform- and Group-derived) with the new end reason
**`school_suspended`** (added to `school_elevations_end_check` and
`ElevationEndReason`), each with its existing
`platform.school_elevation.terminated` audit; audit
`platform.school.suspended`. Suspension deletes, revokes, removes and
archives nothing — memberships, roles, Group membership, audit, Finance and
Payroll records are untouched.

The core rule: **the execution-time check is the authority.** Every
business job and consumer re-reads the School's status when it runs (never
trusting the state at dispatch), and each substrate has one defined
outcome — *deferred* (kept, resumes naturally), *skipped* (terminal
evidence, never replayed) or *not created*. `SetTenantContextForJob` gets
**no** blanket refusal: it also carries platform-safety work, and a blanket
exception would turn deferrals into failures and retries.

| Subsystem | While suspended | Existing state used | Loop safety |
|---|---|---|---|
| Web School routes | No context (`ResolveSchoolContext`); `RequireSchoolContext` clears the stale `active_school_id` and redirects to `/app` (JSON 409) — **already enforced** | — | — |
| School selection | Refused (`SchoolSwitchController`) — already enforced. Dashboard: **0N.9** shows non-active Schools as unavailable (no select control, no reason code) | — | — |
| API | 404 non-disclosing (`EnsureSchoolMembershipContext`, `SchoolContextController`, dev header resolver) — already enforced | — | — |
| Elevation | New Platform/Group elevation refused (`target_inactive`, already); active ones **terminated eagerly** in the suspension transaction (`school_suspended`); the lazy per-request check stays as backstop and records `school_suspended` when the School is suspended (`school_ineligible` otherwise) | `terminated` | — |
| Guardian invitation acceptance (public) | **0N.9:** treated as unusable — the same non-disclosing "invalid or expired" response; no account, link or membership created | invitation unchanged | — |
| AI | **0N.9:** `AiGatewayClient` mints no context token for a non-active School (`AiGatewayAuthorizationException`); `AiToolController` and `AiCompletionAuthorizationController` refuse a non-active School (a token minted just before suspension lives ≤ 60 s). AI audit write-back (`AiAuditController`) continues — it is evidence | — | — |
| Outbox | Dispatch continues (it records and routes; no external effect). Consumers gate their effects (rows below); a consumer that declines returns normally, so no outbox retry | `dispatched` | no throw → no retry |
| Webhooks | `WebhookFanoutConsumer` still records delivery rows (evidence of what was due). `DeliverWebhookJob` re-checks **before** the HTTP call: non-active → **deferred**: `status = 'retrying'`, lease cleared, `next_attempt_at` set, `attempts` unchanged, no `webhook_delivery_attempts` row, no HTTP. `platform:webhook-deliveries-redispatch` **skips non-active Schools** | `retrying` | redispatcher skips → no loop; no attempt consumed |
| Communications | `ProcessCommunicationDeliveryJob` re-checks before the provider call: non-active → **deferred** exactly like its existing `scheduleRetry()`: `status = 'queued'`, lease cleared, `next_attempt_at` set, `attempts` unchanged, no `failure_code`. `platform:communication-deliveries-redispatch` **skips non-active Schools**. `CommunicationDeliveryFactory` creates no delivery for a non-active School | `queued` | same |
| In-app notice | `NotifyActorOfSettingChangeConsumer` returns without sending for a non-active School (the early return it already takes for an unknown School) | not created | — |
| Announcements | `communications:publish-scheduled` **skips non-active Schools**; `scheduled` announcements stay `scheduled` (**held**) | `scheduled` | skipped per run |
| Automation | `AutomationTriggerConsumer` creates no execution for a non-active School. `RunAutomationExecutionJob` re-checks: an execution reaching it while suspended becomes **skipped** with `outcome_code = 'school_suspended'` — terminal evidence, never replayed (the existing `automation_disabled_for_school` precedent). `automation:executions-redispatch` keeps walking suspended Schools so every pending execution reaches that terminal state once | `skipped` | terminal → no loop |
| Payments | No inbound provider callback exists. Any future one must **record** provider evidence even for a suspended School (money has moved at the provider) and defer business effects — its own ADR (CLAUDE.md rule 34) | — | — |
| Documents / downloads | Only through School-context routes — covered by the web row; no signed URL bypass exists | — | — |
| Demo `RecordSchoolAuditPingJob` | Allow-listed (writes a School audit row only; Phase 0B demo) | — | — |

**Deferred work is bounded, not an infinite loop:** while suspended,
deferred webhook/communication rows are never re-dispatched (the
redispatchers skip the School) and each in-flight job defers at most once
without consuming a delivery attempt; the existing attempt limits and
abandonment rules apply unchanged after resume.

A guard test (0N.9) asserts every `TenantScoped` business job and every
School-walking business command performs the execution-time status check,
with the allowlist in section 9.

### 9. What continues while suspended (platform safety / maintenance)

| Continues | Why |
|---|---|
| Platform audit recording | evidence of the suspension itself, elevation termination, denials |
| School audit writes for permitted actions (AI audit write-back; audit rows produced by an operation already under way) | evidence is never suppressed |
| Elevation expiry and termination (`platform:expire-school-elevations`, lazy checks) | security cleanup |
| Retention pruning (`platform:idempotency-prune`, `platform:webhook-deliveries-prune`) under their existing retention rules | maintenance; only what is already legally allowed |
| Outbox dispatch (records only) | consistency; effects are gated by consumers |
| Scheduler heartbeat, `OperationalStatusService`, metrics, failed-job tooling | health telemetry |
| Sign-in, logout, MFA, account security, password flows; context-neutral `/app`; other Schools the user belongs to | user-level, not School business |
| Group governance (a suspended School stays in its Groups) and the platform audit log | platform governance |

### 10. Resume (RESUME)

Precondition `status = 'suspended'`; authorization, fresh MFA and
confirmation; `suspended → active`; audit `platform.school.resumed`. No
data is recreated, **no global "replay everything"** action exists, and no
elevation is restored (a new one must be started).

| Substrate | After resume |
|---|---|
| Webhooks (`retrying`, stale `delivering`) | **resume naturally** — the redispatcher picks them up; SSRF is re-checked at send time; receivers already assume at-least-once, possibly late, delivery (CLAUDE.md rule 39) |
| Communications (`queued`, stale `sending`) | **resume naturally** via the redispatcher |
| Scheduled announcements | **resume naturally** — past-due `scheduled` ones publish on the next run |
| Automation executions skipped while suspended | **remain terminal** (`skipped`) |
| Elevations terminated by suspension | **remain terminal** |
| Invitations, AI requests refused while suspended | nothing to replay; the actor retries |
| Terminal records of every substrate | **remain terminal** |

Consequence to note (not a new decision): a long suspension can make a
naturally resumed announcement or message late. v1 accepts this; a
staleness/expiry policy would be a later product decision using the
existing `expired`/`cancelled` statuses, not a new state.

### 11. Groups and sessions

- Creation adds no Group membership; SUSPEND/RESUME never change Group
  membership (ADR 0045 keeps Group governance platform-owned).
- The Group view may keep showing a member School's id, name and status
  (already Confidential, owner-approved v1); `canEnter` already requires
  `active`, so Group-derived elevation into a suspended or provisioning
  School is refused.
- Sessions: no enumeration. Every request re-validates the School
  (`ResolveSchoolContext` / `RequireSchoolContext`), so the next request
  after suspension returns no School data, clears the stale
  `active_school_id` and lands on `/app` (Phase 0N.1 behaviour); the API
  returns 404. A bootstrap admin of a `provisioning` School likewise cannot
  select it.

### 12. Archive, delete and database safety

**No School-delete or archive application action in v1.** Deletion today
would cascade through 147 foreign keys, erasing School audit evidence,
the Finance journal, Payroll, Documents, Student/Guardian data and the
elevation/Group history. Before any archive or delete is designed, a
separate **retention / legal / data-preservation decision** must cover at
least audit evidence, the Finance journal, Payroll records, Documents,
Student/Guardian data, Group/elevation history and statutory records
(PHASE-0L-CLOSEOUT §6.2 **[LEGAL REVIEW REQUIRED]**). This ADR claims
nothing about the legality of hard deletion.

*Correction (E21.2, 2026-10-01, verified in the migrations):*
- The "147 foreign keys" count is out of date: most tenant tables'
  `school_id` foreign keys CASCADE, and their number grows with each
  module.
- `school_elevations.school_id` and `school_domains.school_id` are
  **RESTRICT**. So a School with any elevation or domain row cannot be
  deleted even administratively, and elevation and domain history do not
  cascade away.
- The runtime role cannot delete a School at all: DELETE is revoked, and
  there is no `archived` transition.
- Tenant closure is now defined by E21-D11
  (`docs/security/E21-RETENTION-DETERMINATION.md`): freeze, archive, retain
  per category, controlled purge. Raw School deletion is never the closure
  workflow.

Smallest database safeguard (Phase 0N.9), evaluated:

| Option | Verdict |
|---|---|
| **`REVOKE DELETE ON schools FROM school_os_app`** | **Chosen.** One grant change; exactly the 0N.7 precedent (`DELETE` revoked on `platform_role_assignments`); the runtime role (every request and worker) can no longer start the cascade; `pgsql_admin` keeps it for tests/operations; reversible `down()` |
| `BEFORE DELETE` trigger refusing every delete | rejected for v1: also blocks the admin connection and the 53 test teardowns with no gain over the grant |
| Change the 147 cascades to `RESTRICT` | rejected here: large, and choosing per-table behaviour *is* the retention decision above |

Consequences: the 53 test teardowns that call `$school->delete()` on the
runtime connection move to the admin connection (the pattern
`SchoolElevationConcurrencyTest` and `GroupAuthorityConcurrencyTest`
already use); `SchoolElevationDatabaseInvariantsTest`'s runtime-delete
assertion then expects a permission error. Plus an architecture guard
test: no application code deletes a School. `school_memberships` and
`membership_role_assignments` `DELETE` grants are not changed here (they
belong to the School's own future membership administration).

### 13. Audit

Platform ledger only (`AuditRecorder::platform()`), subject = the School;
identifiers and codes only — never names, slugs, emails or free text:

| Event | Metadata |
|---|---|
| `platform.school.created` | `status` (`provisioning`) |
| `platform.school.bootstrap_admin_assigned` | `user_id`, `membership_id` |
| `platform.school.bootstrap_admin_replaced` | `previous_user_id`, `user_id`, `membership_id` |
| `platform.school.activated` | `from`, `to` |
| `platform.school.suspended` | `from`, `to`, `reason_code` |
| `platform.school.resumed` | `from`, `to` |
| `platform.school.lifecycle_denied` | `operation`, `outcome_code` (`capability_missing`, `invalid_transition`, `bootstrap_closed`, `admin_missing`, `self_nomination`) |

The denial event is an engineering addition to the owner's list,
following ADR 0046 refinement 2 (every refused privileged request is
audited); an unknown or disabled target is a plain validation error, not a
denial. MFA re-verification failures are recorded however
`MfaReverificationService` already records them; no MFA event is added.
The platform auditor sees these events through the 0N.7 envelope
(metadata stays hidden, ADR 0046).

**No School-ledger duplication** (the existing rule: platform governance
evidence → platform ledger; School-domain action evidence → School
ledger). Lifecycle and bootstrap are platform governance; a suspended
School's users cannot reach their ledger anyway; and ADR 0046 §11 already
keeps elevation provenance out of School review. The membership and role
rows themselves are the School-side record of the bootstrap admin.
Disclosing lifecycle history to a School is a later product decision.

### 14. Data classification

Audited against `docs/security/DATA-CLASSIFICATION.md`: no row covered
School metadata, lifecycle state or the bootstrap relationship; the Group
row already treats member-School id/name/status as **Confidential**, and
the platform audit row already covers "future School creation/lifecycle
actions" as **Highly Sensitive**. Decided (no lower tier than any existing
row):

| Record | Tier |
|---|---|
| School platform metadata and lifecycle state (`schools` row: name, slug, code, status, board; organisational contact fields) | **Confidential** — an individual's personal contact detail entered in a contact field is Sensitive by the ordinary rules |
| Bootstrap School Admin relationship (which user is the bootstrap admin, their membership/role assignment, replacement history) | **Sensitive** — links an identifiable person to administrative authority in a School |
| Lifecycle / bootstrap audit evidence (`platform.school.*`) | **Highly Sensitive** (the existing ADR 0046 platform audit treatment) |

### 15. Invariants

1. `schools.status` is the only lifecycle state; v1 values `provisioning`,
   `active`, `suspended`, `archived`; only `active` is operational.
2. The database permits only `provisioning → active`, `active →
   suspended`, `suspended → active`; `provisioning` is never re-entered;
   nothing transitions into or out of `archived` in v1.
3. Creation never activates, never adds a Group, never elevates, never
   creates tenant-domain business records.
4. No School is activated without a non-disabled user holding an active
   membership whose roles grant `school.members.manage` and
   `school.roles.manage` in that exact School.
5. The bootstrap path is open only while `provisioning`, writes real
   ordinary rows only, never to the acting operator, and closes
   permanently at first activation.
6. No ongoing platform membership administration; losing all admins is a
   future break-glass decision.
7. All lifecycle and bootstrap actions need `platform.schools.manage`
   (root-reserved), fresh MFA re-verification and explicit confirmation;
   never a role-name check.
8. Suspension deletes, revokes and removes nothing; RESUME recreates
   nothing and replays nothing globally.
9. Execution time is authoritative: every School business effect re-checks
   School status when it runs; deferral never consumes a delivery attempt
   and never loops.
10. Platform safety and maintenance never stop for a suspended School.
11. Active elevations end in the suspension transaction
    (`school_suspended`); no elevation into a non-active School.
12. No application School delete; the runtime role cannot delete a School.
13. Lifecycle evidence is platform-ledger only, identifiers and codes only.
14. Nothing here authorizes any cross-School read (section 17).

### 16. Proposed Phase 0N.9 — School Lifecycle Foundation (not implemented)

1. **Schema:** `schools_status_check`, default `provisioning`, the
   transition trigger (section 2); `school_suspended` added to
   `school_elevations_end_check`; `REVOKE DELETE ON schools` from the
   runtime role; all with working `down()`.
2. **Services** (`app/Domain/Platform/Application/Schools/`): a lifecycle
   service (create, activate, suspend, resume) and the bootstrap
   administration service (assign, replace) — row lock, conditional
   transitions, fresh MFA, audit, denial audit; eager elevation
   termination via `SchoolElevationService`.
3. **UI:** `/app/platform/schools` (list for `platform.schools.manage`:
   id, name, slug, code, status; bootstrap admin email for `provisioning`
   Schools only), create form, review/confirm pages; Dashboard marks
   non-active Schools unavailable. No API route.
4. **Enforcement** (section 8): `DeliverWebhookJob`,
   `ProcessCommunicationDeliveryJob`, `RunAutomationExecutionJob`,
   `CommunicationDeliveryFactory`, the three consumers, the webhook and
   communication redispatchers and the announcement publisher; AI minting
   and the two internal AI endpoints; Guardian invitation acceptance.
5. **Tests:** every transition allowed/refused at service and raw-SQL
   level; bootstrap assign/replace/closure (including after resume);
   self-nomination, disabled/unknown user; activation without a qualifying
   admin; capability and MFA allow/deny for each operation; the runtime
   role cannot delete a School (and the 53 teardowns moved); per-substrate
   suspension behaviour (deferred / skipped / not created, no attempt
   consumed, no loop) and resume behaviour; elevation termination;
   sessions/API; Group view; AI and invitation refusal; audit events and
   metadata; architecture guards (single School creator, single platform
   membership writer, no School delete, every business job checks status);
   DDEV review.

Not in it: archive/delete, break-glass recovery, School-side membership
administration, a School directory beyond the lifecycle page, Group
reporting (D15), user provisioning.

### 17. D15 boundary

Lifecycle and bootstrap administration authorize **no** cross-School
Analytics, Compliance, Automation or AI and no Group tenant-data query.
The lifecycle page reads only the `schools` row and, for a `provisioning`
School, its bootstrap membership; it counts nothing and reads no
tenant-domain table. D15 stays open.

### 18. Readiness

D11 is decided for create/activate/suspend/resume (archive/delete
explicitly excluded behind the legal gate — not a remaining 0N lifecycle
decision). D13 is resolved for v1: bootstrap exception only, ongoing
platform membership administration not authorized. **Phase 0N's only
remaining decision is D15.**

## Alternatives considered

1. **A second lifecycle column** (`lifecycle_state`, `activated_at`).
   Rejected: the existing `status` column, a CHECK and a transition
   trigger express the whole lifecycle; "ever activated" is implied by
   `status <> 'provisioning'` because `provisioning` cannot be re-entered.
2. **Reusing `suspended` as the initial state.** Rejected: it conflates
   "never operational" with "paused", and would reopen the bootstrap path
   on every suspension.
3. **Auto-activation on creation or on bootstrap assignment.** Rejected by
   the owner (activation is a separate privileged act).
4. **Platform-side membership administration after activation.**
   Rejected (D13): least privilege; the School owns its membership.
5. **Blanket refusal in `SetTenantContextForJob`.** Rejected: it cannot
   distinguish business from safety work and would turn holds into failed
   jobs and retries.
6. **Cancelling/failing pending communications and webhooks at
   suspension.** Rejected: destroys work a reversible suspension should
   keep; deferral uses existing statuses.
7. **Dual-recording lifecycle events in the School ledger.** Rejected
   (section 13).
8. **Narrow lifecycle capabilities now.** Rejected (section 7).
9. **A `BEFORE DELETE` trigger or `RESTRICT` foreign keys.** Rejected for
   v1 (section 12).

## Consequences

- School creation, activation and suspension become possible without
  seeders, with the School's own admin in place before it is operational.
- Suspension becomes real across every substrate, not only web/API.
- Deferred messages and webhooks may arrive late after a long suspension
  (section 10).
- 53 test teardowns change connection in 0N.9.
- Break-glass recovery, archive/delete and a School-facing lifecycle
  history stay open, each behind its own decision.

## References

ADR 0004, ADR 0017, ADR 0021, ADR 0025, ADR 0026, ADR 0027,
ADR 0037, ADR 0043, ADR 0044, ADR 0045, ADR 0046;
`docs/architecture/PHASE-0N-READINESS.md` (§13, §14, D11, D13);
`docs/modules/ORGANIZATION.md`; `docs/architecture/RELIABILITY.md`;
`docs/architecture/TENANCY.md`; `docs/security/AUTHORIZATION.md`;
`docs/security/DATA-CLASSIFICATION.md`; CLAUDE.md rules 21, 24, 25, 29,
34, 39, 48, 59, 83–85.

## Implementation amendment (Phase 0N.9, 2026-09-25)

Implemented as specified; archive, delete, break-glass recovery and
platform membership administration beyond the bootstrap exception are
not built.

| Contract | Implementation |
|---|---|
| Lifecycle (§2) | `App\Support\Tenancy\SchoolStatus` (enum); `School::isActive()` / `isProvisioning()` / `isSuspended()` / `lifecycleStatus()`; migration `2026_10_18_090000_add_school_lifecycle_constraints`: `schools_status_check`, default `provisioning`, trigger `trg_schools_status_transition` (only provisioning→active, active→suspended, suspended→active, for every role) |
| Delete safety (§12) | `REVOKE DELETE ON schools` from `school_os_app`; the 55 test files that tore down Schools on the runtime connection use `TestCase::deleteSchoolAsAdmin()` (the admin connection) |
| Services (§3–8, §10) | `App\Domain\Platform\Application\Schools\`: `SchoolLifecycleService` (create, activate, suspend, resume), `SchoolBootstrapAdministrationService` (establish, replace; the only platform code writing School memberships), `SchoolLifecycleAuthority` (shared checks, MFA, denial audit), `SchoolSuspensionReason`, `SchoolLifecycleOperation`, `SchoolLifecycleAudit`, `SchoolLifecycleDeniedException` |
| Surface (§7, §16) | `/app/platform/schools` (`PlatformSchoolAdminController`; pages `App/Platform/Schools/{Index,Create,Show,Action}`), limiter `platform-school-lifecycle` (8/min per actor); Dashboard link and "unavailable" Schools |
| Elevation (§8) | end reason `school_suspended` (`ElevationEndReason`, `school_elevations_end_check`); eager termination in the suspension transaction; trigger `school_elevations_assert_school_active` (target School read FOR SHARE at INSERT) |
| Execution-time enforcement (§8) | `App\Support\Tenancy\SchoolOperationalGuard` (`isOperational()`, `holdOperational()` FOR SHARE, `requireOperational()`); `SchoolNotOperationalException` (a non-disclosing 404 if it ever surfaces in a request) |

**Refinements made while implementing:**

1. **The linearization point is the School row.** Lifecycle changes lock
   it FOR UPDATE; every business effect's claim reads it FOR SHARE inside
   its own claim transaction (`holdOperational()`), never across an
   external call. A claim that commits before a suspension is in-flight
   work the suspension waits for; a claim after it sees `suspended`.
   Proven with two real processes (`SchoolLifecycleConcurrencyTest`).
   Web and API requests are admitted by the existing per-request check:
   a request admitted before a suspension commits finishes (and any
   delivery, automation or AI effect it starts is still refused by the
   checks above); every later request is refused.
2. **Per-substrate outcomes, as built:** webhook and communication
   claims defer (`retrying` / `queued`, `next_attempt_at = now`, lease
   cleared, `attempts` unchanged, no attempt row); their redispatchers
   and the announcement publisher walk only `active` Schools;
   `CommunicationDeliveryFactory` refuses a new delivery (the publish or
   approval rolls back; a held announcement keeps its schedule, no
   backoff); `AutomationTriggerConsumer` creates no execution and an
   execution reaching run time becomes `skipped` / `school_suspended`;
   `NotifyActorOfSettingChangeConsumer` sends nothing; the Guardian
   invitation is unusable (show and accept) and left intact; AI tokens
   are not minted (`AiGatewayClient`) and both internal AI endpoints
   answer `403 school_unavailable`. `WebhookFanoutConsumer` is unchanged:
   it records the delivery rows and the delivery job defers them.
3. **The MFA form field is `mfa_code`** (a School also has a `code`).
   A missing factor is shown on the form (and audited
   `mfa_not_enrolled`); `capability_missing` and `actor_disabled` are 403.
4. **Denial outcome codes:** `actor_disabled`, `capability_missing`,
   `invalid_transition`, `admin_missing`, `bootstrap_closed`,
   `bootstrap_target_conflict` (the named account already administers
   the provisioning School), `self_nomination`, `mfa_not_enrolled`,
   `mfa_verification_failed`; metadata `operation` + `outcome_code` only.
5. **Replacement** suspends every active membership of the provisioning
   School (in practice exactly one) and records the first as
   `previous_user_id`; re-nominating an earlier administrator reactivates
   their one membership row.
6. **Transitions are refused for every database role**, the admin
   connection included: `archived` is reachable only by insert (fixtures)
   until the legal decision says otherwise. A test that needs an archived
   or provisioning School creates it that way.
7. **The column default is `provisioning`**; `SchoolFactory`,
   `DemoDataBuilder` and the one raw test insert already set `active`
   explicitly, and `SchoolFactory::provisioning()` exists for lifecycle
   tests. DDEV demo Schools stay `active`.
8. **Viewing** the lifecycle pages needs `platform.schools.manage` only
   (Confidential metadata; the bootstrap administrator, Sensitive, is
   shown only while `provisioning`) -- like the Group pages, no MFA
   assurance for reads; every change needs a fresh code.

## Note — Phase 0O.12A (ADR 0059, 2026-09-28)

- **Qualifying administrator amendment.** A User who has no established
  local credential (the credential-less state ADR 0059 §4 introduces) never
  counts as a qualifying administrator. Activation refuses with a new
  bounded outcome, `admin_not_activated`. This is not an invented setup
  step: an administrator who can never sign in would strand an active
  School, because this bootstrap path closes for good. MFA is still not
  required for activation.
- **Unchanged:**
  - `create` still requires an existing, enabled, non-self target;
  - the bootstrap service is still the only platform writer of memberships
    and School role assignments;
  - D13 still forbids ongoing platform membership administration.
- **New account path.** The new operator console command (ADR 0059 §5)
  creates only a credential-less User. School authority still comes from
  this ADR's create or replace path.

### Implementation note — Phase 0O.12B (2026-09-28)

**Built:**
- `admin_not_activated`: a credential-less administrator does not qualify
  (`App\Support\Authorization\SchoolAdministrators`);
- the bootstrap-admin replacement now also revokes the replaced
  administrator's School role grant (`revocation_reason =
  membership_suspended`), because grants keep history (ADR 0059 owner
  amendment).

**Unchanged:**
- the path, its capability and fresh MFA;
- D13: no ongoing platform membership administration. Staff access after
  activation is the School's own (ADR 0059 flow B and off-boarding).
