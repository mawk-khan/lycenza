# ADR 0046: Platform Authority & Audit Governance Contract (Phase 0N.6)

- Status: Accepted; foundation implemented in Phase 0N.7 (see
  "Implementation amendment" at the end)
- Date: 2026-09-24 (Phase 0N.6); amended 2026-09-25 (Phase 0N.7)
- See also: ADR 0047 (answers §12: what School creation, activation,
  suspension and resume mean under the root-reserved
  `platform.schools.manage`; lifecycle audit events join the platform
  ledger)

## Context

Everything privileged built in Phase 0N — elevation into a School
(ADR 0044), School Group governance and Group grants (ADR 0045) — rests on
`platform_super_admin`, and all of it is recorded in `platform_audit_events`,
which nobody can review. `docs/architecture/PHASE-0N-READINESS.md` left two
decisions open for this: **D12** (who may create Schools and govern
platform authority) and **D16** (platform audit review; also ADR 0042 §13
item 2). **On 2026-09-24 the product owner approved D12 and D16**
(section 1). This ADR is the implementation contract. It changes no code,
adds no migration and seeds nothing. D11 (School lifecycle), D13
(platform-level School membership administration) and D15 (Group /
cross-School reporting) stay open.

### Platform authority today (verified on `2e29731`, code and DDEV)

| Platform capability | Seeded? | Held by | Runtime check sites | Used? |
|---|---|---|---|---|
| `platform.schools.view` | yes | `platform_super_admin` | none | **unused** |
| `platform.schools.manage` | yes | `platform_super_admin` | `DashboardController` nav flag `canManagePlatformSchools` only (never rendered); docblock examples in `EnsureCapability`, `AuthorizesCapability` | **unused** (ADR 0044 §6 reserved it for School create/configure, D11) |
| `platform.schools.elevate` | yes | `platform_super_admin` | `SchoolElevationService`, `ResolvePlatformElevation`, the elevation start route | used |
| `platform.school_groups.view` | yes | `platform_super_admin` | `/app/platform/groups…` route middleware, `SchoolGroupGovernanceService`, `DashboardController` | used |
| `platform.school_groups.manage` | yes | `platform_super_admin` | `SchoolGroupGovernanceService` (create, rename, archive, add/remove School) | used |
| `platform.school_group_grants.manage` | yes | `platform_super_admin` | `SchoolGroupGovernanceService` (grant, revoke), grant list visibility | used |
| `platform.feature_flags.view` / `.manage` | yes | `platform_super_admin` | none (flags change only by migration/seeding) | **unused** |
| `platform.service_identities.view` / `.manage` | yes | `platform_super_admin` | none (identities are issued by `ServiceIdentityIssuer` from seeding, audited as `platform.service_identity.*`) | **unused** |
| `platform.operations.view` | yes | `platform_super_admin` | `GET /api/internal/operations/status`, the local/testing-only `/internal/mfa-demo/ping` | used |
| `platform.users.mfa.reset` | yes | `platform_super_admin` | `MfaAdminController` → `MfaAdminResetService` | used |
| `ai.tools.invoke`, `ai.audit.write` | yes (namespace `platform`) | **service identities only** (`ServiceIdentitySeeder`), no role | `VerifyAiGatewayServiceToken`, internal AI controllers | used (machine principals) |
| `platform.audit.view` | **no — does not exist** | — | — | — |

- `platform_super_admin` holds **12** `platform.*` capabilities; it is the
  only platform role (`roles`: `platform_super_admin` `platform`,
  `group_admin` `group`, the rest `school`). `platform_auditor` does not
  exist.
- `compliance.platform.view` (ADR 0042 §4) is reserved, unseeded, for
  **cross-School/Group Compliance** (ADR 0042 §13 item 9) — not for
  platform audit review, which ADR 0042 left as a separate open item
  (§13 item 2). No existing key covers platform audit review.

### Platform role assignments today

`platform_role_assignments`: `id`, `user_id` (FK `users` **ON DELETE
CASCADE**), `role_id` (FK `roles` **ON DELETE CASCADE**),
`granted_by_user_id` (nullable, FK `users` ON DELETE SET NULL),
`granted_at`, timestamps; **UNIQUE `(user_id, role_id)`** (total, not
partial); trigger `trg_platform_role_assignments_scope` (BEFORE INSERT OR
UPDATE: role scope must be `platform`). The runtime role holds `SELECT`,
`INSERT`, `UPDATE`, **`DELETE`** on it. Consequences: a revocation would be
a hard delete with no history and no revoker; re-granting after a revoke
needs a delete; a self-grant is structurally possible; deleting a user or
role silently erases the evidence. **No application code writes the
table** — only `DemoDataBuilder` (local demo) and test fixtures; there is
**no production provisioning path** at all. So `platform_super_admin` has
no runtime assignment path, and nothing contradicts D12.

### Platform audit ledger today

`platform_audit_events` (append-only: the runtime role holds `INSERT`,
`SELECT` only; ADR 0017): `id` (UUIDv7), `occurred_at`, `actor_user_id`
(nullable, FK users ON DELETE SET NULL), `event_type`, `subject_type`,
`subject_id`, `ip_address`, `user_agent`, `request_id`, `metadata`
(jsonb), `created_at`. Indexes: primary key, `event_type`,
`(subject_type, subject_id)`, `occurred_at`. Writers:
`AuditRecorder::platform()` only. Current event types: `auth.*` (sign-in,
failure, disabled-user denial, logout, MFA enrollment/challenge/disable/
reset/recovery codes), `school_context.activated`,
`platform.service_identity.issued/enabled/disabled`,
`platform.school_elevation.denied/started/ended/expired/terminated`, and
the seven `platform.school_group*` events. **No reader exists.** The
School ledger has its own review (`/app/compliance/audit-log`,
`AuditLogReviewService`, `SchoolAuditEventReader`: `school.audit.view`,
seven envelope columns, 50 per page, keyset `occurred_at DESC, id DESC`,
access event `compliance.audit_log.viewed` with `paged` and
`resultCount`, no `mfa` middleware) — the precedent this ADR mirrors.

## Decision

### 1. Owner decisions (2026-09-24)

**D12 — platform authority and School creation**

1. `platform_super_admin` is the **root / bootstrap** role: it exists
   through trusted bootstrap, seeding or provisioning only; it is **not**
   runtime-grantable or runtime-revocable through the ERP application; no
   screen or API promotes anyone to it; no ordinary platform role carries
   equivalent root authority. A root-role editor is not built; runtime
   governance of the root role itself needs a new ADR.
2. **School creation** (when built) is **Platform Super Admin only** in v1.
   What creating, activating, suspending or archiving a School *means*
   stays D11; School creation is not implemented here.
3. The root may eventually grant and revoke **explicitly approved non-root
   platform roles** — code-registered, marked runtime-assignable, narrower
   than root, auditable. No generic role builder, no free-form platform
   role editor.
4. **No self-grant, no escalation**: no self-grant; no self-revocation that
   would undermine governance or audit; nobody grants the root role; nobody
   grants capabilities they are not authorized to administer; School or
   Group authority never yields platform authority.
5. A dedicated non-root role **`platform_auditor`** exists only to review
   the platform audit ledger, with exactly the capability that job needs.
6. Platform Super Admin grants and revokes `platform_auditor` (explicit,
   platform-scoped, audited, history-preserving). `platform_auditor` grants
   nothing; no delegation chain.

**D16 — platform audit review**

7. The platform audit review surface and its records are **Highly
   Sensitive** for v1 (not necessarily the permanent per-event tier).
8. Review requires **`platform.audit.view`**, held initially by
   `platform_super_admin` and `platform_auditor`; no School or Group role.
9. The viewer is **context-neutral**: no `active_school_id`, no
   `TenantContext`, no elevation, no School tenant table, no School ledger.
10. Schools get **no** platform-audit surface for elevation events in v1;
    the School audit-log review keeps its envelope and
    `school_audit_events.elevation_id` stays unrendered.
11. **Empty metadata allowlist** in v1: first-class envelope fields only.
12. Every successful review writes **exactly one** access event
    (identifiers/codes only), never one per displayed row.
13. v1 functionality: bounded pagination, deterministic order, the
    approved envelope — **no** export, CSV, metadata expansion, free-text
    search, cross-ledger join, School audit browsing, deletion, retention
    or legal-hold controls.

### 2. The root role

- **Provisioning source.** Only trusted, out-of-application provisioning
  run by someone with infrastructure access: a seeder in local/demo, and —
  for production — an operator console command (not HTTP) that writes the
  assignment and a platform audit event
  `platform.role_grant.provisioned` (actor null, subject the assignment,
  metadata `role_key`, `user_id`, `method: console`). Production has **no
  provisioning path today**; this command is a production-readiness
  prerequisite (Phase 0O), not part of the next checkpoint.
- **Why excluded from runtime grant/revoke.** The root role holds the
  governance capability itself (section 4); a runtime path to it would let
  one compromised root session mint more roots, or remove the others, with
  no out-of-band check.
- **Recovery.** Losing every root account is recovered only by the same
  trusted provisioning path, never in-app. A root account's MFA can be
  reset by another root (`platform.users.mfa.reset`); disabling a root
  account is an operator action.
- **No path through other scopes.** A School role or Group role can never
  hold a platform capability (`trg_role_capabilities_scope`,
  `roles_scope_check`, CLAUDE.md rule 25), assignment triggers keep roles
  in their own tables, and no Group or School operation writes
  `platform_role_assignments`. Elevation grants no capability (ADR 0044).

### 3. Runtime-assignable (non-root) platform roles

- **Designation, not existence.** A platform role row existing is never
  enough to grant it. The code catalog (`CapabilityAndRoleSeeder`'s role
  definitions) marks a role `runtime_assignable`, persisted to a new
  `roles.runtime_assignable` boolean (default `false`), and the grant
  service additionally accepts only keys in a code allowlist. Database
  guards: `runtime_assignable` may be true only for `is_system` roles of
  scope `platform`, and never for `platform_super_admin`.
- **Narrower than root.** A runtime-assignable role may never hold a
  **root-reserved** capability: `platform.role_grants.manage` (section 4)
  and `platform.schools.manage` (School creation, D12 decision 2) — a
  trigger on `role_capabilities` enforces it. So no delegation chain can
  form and School creation stays root-only.
- **v1 catalog of runtime-assignable roles: `platform_auditor` only.**

### 4. Platform-role governance

- **Capability `platform.role_grants.manage`** — "Grant and revoke
  runtime-assignable platform roles" — defined by this contract, not
  seeded; root-reserved (held only by `platform_super_admin`). Checked as a
  capability, never by role name (CLAUDE.md rule 24). One narrow rule, no
  higher-order check: only the root holds it, and it reaches only roles
  marked runtime-assignable.
- **Revoking another person's role** needs the same capability and a
  runtime-assignable role; the root role is never revoked in-app.
- **Schema changes required** (implementation checkpoint):
  `platform_role_assignments` gains `revoked_at`, `revoked_by_user_id`
  (set once, then immutable — the `group_role_assignments` pattern); the
  total `UNIQUE (user_id, role_id)` becomes a partial unique index on
  active rows; `DELETE` revoked from the runtime role; user and role FKs
  become RESTRICT; CHECK `granted_by_user_id IS NULL OR granted_by_user_id
  <> user_id` (no self-grant; NULL means provisioned); a trigger: a row
  with a grantor (a runtime grant) must reference a `runtime_assignable`
  role, and a revocation must too — so the database itself refuses a
  runtime grant or revoke of the root role.
- **Service rules** (in addition): the actor holds
  `platform.role_grants.manage`; the target is an exact user (email or
  UUID); not oneself (grant or revoke); the role is in the code allowlist
  and `runtime_assignable`; one active grant per (user, role).

### 5. `platform_auditor`

A **system** platform role (`is_system = true`, scope `platform`,
`runtime_assignable = true`) holding exactly **`platform.audit.view`**
(defined by this contract; `platform_super_admin` gains it too). Its
assignment is an ordinary `platform_role_assignments` row with a grantor.
It does **not** receive `platform.schools.elevate`, any
`platform.school_group*` capability, School creation or lifecycle
authority, membership administration, any Group or School role, or
`platform.role_grants.manage`. Holding it grants no School context, no
elevation, no Group view, no School audit-log payload and no export.

### 6. Platform-role governance audit

`platform_audit_events`, identifiers and codes only (no capability arrays):

| Event | Subject | Metadata |
|---|---|---|
| `platform.role_grant.granted` | the assignment | `user_id`, `role_key` |
| `platform.role_grant.revoked` | the assignment | `user_id`, `role_key` |
| `platform.role_grant.denied` | the target user when known | `outcome_code` (`capability_missing`, `self_grant`, `self_revoke`, `role_not_assignable`, `already_granted`), `role_key` if valid |
| `platform.role_grant.provisioned` | the assignment | `role_key`, `user_id`, `method` (section 2; operator console only) |

Denied role-governance attempts are audited (privilege-escalation
signals, rare by construction) — the same reasoning D17 applied to
elevation. Plain input validation (unknown email) is not.

### 7. Platform audit review (read contract)

- **Reader** `App\Support\Audit\PlatformAuditEventReader` beside
  `AuditRecorder` and `SchoolAuditEventReader`; read-only; reads
  `platform_audit_events` only, on the normal runtime connection, with no
  `TenantContext` (the ledger has no RLS and no `school_id`).
- **Fixed envelope DTO** (a `PlatformAuditEventEntry`, never a model):

| Field | First-class? | Classification concern | Show in v1? | Reason |
|---|---|---|---|---|
| `id` | yes | none | **yes** | stable reference for an investigation |
| `occurred_at` | yes | none | **yes** | when |
| `event_type` | yes | none (codes) | **yes** | what |
| `actor_user_id` | yes | links a person to privileged activity | **yes (id only, no name/email)** | who; matches the School review |
| `subject_type` | yes | class name | **yes (basename)** | what it acted on |
| `subject_id` | yes | an identifier | **yes** | which one |
| `request_id` | yes | an identifier | **yes** | correlates with logs |
| `ip_address` | yes | personal data | **no** | not needed to review governance; showing it needs its own decision |
| `user_agent` | yes | personal data, free text | **no** | as above |
| `metadata` | jsonb | arbitrary per event | **never** (allowlist empty) | owner decision 11 |
| `created_at` | yes | none | no | duplicates `occurred_at` |

  The seven shown fields are exactly the School review's envelope.
- **Paging:** 50 per page; keyset on `(occurred_at, id)` descending with
  the School reader's opaque cursor format; an invalid cursor is refused.
  Offset paging is excluded (every review appends an event).
- **Index:** the existing `occurred_at` index serves the order; the `id`
  tie-break is resolved within a timestamp. Recommendation (not
  required now): add `(occurred_at DESC, id DESC)` if `EXPLAIN` on a
  production-sized ledger shows a sort — DDEV holds a handful of rows, so
  there is no evidence yet.

### 8. Viewer authorization and access auditing

- `platform.audit.view`, checked in the review service and on the route
  (`capability:platform.audit.view,platform`) — never inferred from School
  Admin, Principal, Group Admin, elevation or any other platform
  capability.
- **Access event `platform.audit_log.viewed`** (the School precedent is
  `compliance.audit_log.viewed`), written once per successful page load,
  after the page is read, metadata **`paged`** (bool) and **`resultCount`**
  (int) only. It is an ordinary ledger row: it appears on a later page or
  refresh, and viewing it writes one more — never one per displayed row.
  A refused review (403) is not audited (no universal "audit every 403").
- **MFA** is not required by D16; the implementation follows the School
  audit-log precedent (none). Requiring the existing `mfa` middleware on
  this route is an owner option, recorded, not decided.

### 9. UI

`GET /app/platform/audit-log` (context-neutral, beside
`/app/platform/groups`; `app.platform.audit-log` in the Phase 0N.1
allowlist): a Highly Sensitive notice, the seven columns, "Older" paging.
No metadata drawer, payload JSON, export, search, retention control or
School selector. Dashboard link only for holders of `platform.audit.view`.

### 10. What reviewers will and will not see

- **Covered** events: authentication and MFA, School selection, service
  identities, every elevation lifecycle event including denials (D17),
  every Group governance and Group grant event, and — once built —
  platform-role governance (section 6).
- **Known gaps** (implementation prerequisites for completeness, not
  fixed here):
  1. With the metadata allowlist empty, a reviewer sees *that* an
     elevation was denied or a School removed from a Group, not the
     outcome code, reason or authority — a per-event allowlist
     (e.g. `outcome_code`, `reason_code`, `authority_type`) is a later
     owner decision.
  2. Denied Group governance attempts (403 before the service) are not
     audited — unchanged, outside D17.
  3. Platform-role grants have no events until section 6 is built;
     bootstrap/demo assignments have none.
  4. Feature-flag changes have no runtime path and no event (flags change
     by migration/seeding only).
- **Not visible, by design:** anything in `school_audit_events`, School
  tenant data, request bodies, secrets, tokens, prompts, before/after
  snapshots.

### 11. Separation of the two ledgers

School Compliance audit log ≠ platform audit review: two ledgers, two
readers, two capabilities (`school.audit.view`, `platform.audit.view`),
two access events, no generic "all audit events" reader, no join. The
School review stays School-scoped under RLS; the platform review stays
platform-scoped. School administrators see no elevation lifecycle events
and no `elevation_id` in v1; disclosing elevation provenance to Schools
needs an explicit privacy/product decision.

### 12. School creation depends on D11

D12 answers **who** (Platform Super Admin, through the root-reserved
`platform.schools.manage`). D11 must answer **what** creation, activation,
suspension and archive mean, and their provisioning consequences. School
creation stays blocked until D11.

### 13. Frozen invariants

1. `platform_super_admin` is root/bootstrap.
2. The root role is not runtime-grantable or -revocable in v1.
3. Runtime platform roles are code-approved, `runtime_assignable`,
   non-root roles only; `platform_auditor` is the only one in v1.
4. No self-grant (database CHECK and service) and no self-revoke.
5. Platform, Group and School scopes stay separate (CLAUDE.md rules 25,
   84).
6. School creation authority is Platform Super Admin only
   (`platform.schools.manage`, root-reserved).
7. School lifecycle semantics remain D11.
8. Audit review is capability-based (`platform.audit.view`).
9. Audit review establishes no School context.
10. Platform audit records and the review surface are Highly Sensitive.
11. Metadata is not exposed in v1.
12. Every review is itself audited, exactly once.
13. School and platform audit ledgers remain separate.
14. `platform_auditor` gains no operational platform power.
15. Audit viewing grants no elevation, Group or School authority.
16. Root-reserved capabilities (`platform.role_grants.manage`,
    `platform.schools.manage`) are never held by a runtime-assignable
    role.

## Alternatives considered

1. **A generic platform RBAC editor.** Rejected (D12 decision 3): any role
   row or capability set becomes grantable, including root-equivalent ones.
2. **Runtime grant of `platform_super_admin` with two-person approval.**
   Rejected for v1 (decision 1); needs its own ADR.
3. **Reuse `compliance.platform.view` for platform audit review.** Rejected:
   ADR 0042 reserves it for cross-School Compliance (§13 item 9);
   one key per permission (the ADR 0042 §4 rule).
4. **Show metadata or IP/user agent.** Rejected for v1 (decision 11; personal
   data); a per-event allowlist is a later decision.
5. **Hard-delete revocations (today's behaviour).** Rejected: no history,
   and an audit event alone cannot reconstruct who held what without it.

## Consequences

- D12 and D16 are recorded; Phase 0N stays **BLOCKED** on D11, D13, D15.
- ADR 0042 §13 item 2 (platform audit review) is resolved by this ADR.
- The implementation checkpoint amends CLAUDE.md (root role, runtime-
  assignable platform roles) and AUTHORIZATION.md at that time.

## Proposed implementation checkpoint (not started)

**Phase 0N.7 — Platform Authority & Audit Foundation** (number/title for
the owner to confirm):

1. Seed `platform.audit.view` (root and `platform_auditor`),
   `platform.role_grants.manage` (root only), the `platform_auditor` system
   role; `roles.runtime_assignable` with its guards; the root-reserved
   capability trigger.
2. Migrate `platform_role_assignments` to history-keeping grants (section
   4: revocation columns, partial unique, no DELETE, RESTRICT FKs, no
   self-grant CHECK, runtime-assignable trigger), with working `down()`.
3. `PlatformRoleGovernanceService`: grant/revoke `platform_auditor` by exact
   person, with the four events; a minimal platform page to do it.
4. `PlatformAuditEventReader` (section 7) and `/app/platform/audit-log`
   (sections 8–9) with `platform.audit_log.viewed`.
5. Tests: every anti-escalation rule at service and database level
   (including raw-SQL root grants and self-grants), auditor can review and
   do nothing else, root cannot be granted or revoked in-app, envelope
   never contains metadata/IP/user agent, keyset paging and one access
   event per view, no TenantContext, School audit review unchanged, a
   concurrent duplicate-grant race; DDEV review.

Not in it: School creation (D11), the production provisioning command
(Phase 0O), metadata allowlists, MFA on review unless the owner decides it.

## Implementation amendment (Phase 0N.7, 2026-09-25)

**Owner implementation decisions:** platform audit review **requires the
existing MFA** — an enrolled factor and current assurance
(`mfa_verified_at` within `mfa.assurance_window_minutes`) — but **no fresh
code per page, cursor or refresh** (unlike starting an elevation, this is a
read). The metadata allowlist stays **empty** (no denial reason codes).
`platform_super_admin` stays out-of-band only; the provisioning console
command is not built. `platform_auditor` (exactly `platform.audit.view`)
is the only runtime-assignable role, and `platform.role_grants.manage`
(root only) grants and revokes it.

**What was built:**

| Contract | Implementation |
|---|---|
| Catalog (§4–5) | `platform.audit.view` (root + `platform_auditor`), `platform.role_grants.manage` (root only); system role `platform_auditor`; `platform_super_admin` now holds 14 capabilities; catalog 155 |
| Runtime-assignable marker (§3) | `roles.runtime_assignable` (default false; seeded from the code catalog); `roles_runtime_assignable_check` (system platform roles only, never `platform_super_admin`); triggers `trg_role_capabilities_root_reserved` and `trg_roles_runtime_assignable_root_reserved` keep `platform.role_grants.manage` and `platform.schools.manage` off any runtime-assignable role |
| History-keeping grants (§4) | `platform_role_assignments.revoked_at` / `revoked_by_user_id`; partial unique `platform_role_assignments_one_active`; `platform_role_assignments_no_self_grant`, `_no_self_revoke`, `_revocation_check`; trigger `trg_platform_role_assignments_governance` (a grant with a grantor, and any revocation, must name a runtime-assignable role; not created revoked; revocation the only change; revoked rows immutable); `DELETE` revoked from the runtime role; user/role/grantor FKs RESTRICT. `CapabilityResolver::platformCapabilities()` ignores revoked grants |
| Governance (§4, §6) | `App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService` (grant/revoke; code allowlist `RUNTIME_ASSIGNABLE = ['platform_auditor']`; forgets the target's capability cache so a change takes effect at once); `/app/platform/roles` (`PlatformRoleAdminController`, auditor only, exact email or id); events `platform.role_grant.granted/revoked/denied` |
| Audit review (§7–9) | `App\Support\Audit\PlatformAuditEventReader` (+ `PlatformAuditEventEntry`, `PlatformAuditEventPage`), `App\Domain\Platform\Application\Audit\PlatformAuditLogReviewService`, `/app/platform/audit-log` (`PlatformAuditLogController`), access event `platform.audit_log.viewed` |

**Refinements made while implementing:**

1. **MFA check placement.** The review service checks the capability, then
   `MfaChallengeService::userHasActiveFactor()` / `hasValidAssurance()` —
   the exact calls `RequireMfa` makes — and throws RequireMfa's own
   exceptions, so the codes and statuses are unchanged (`403
   mfa_required_not_enrolled`, `401 mfa_step_up_required`). JSON callers get
   RequireMfa's JSON body; a browser gets an `App/Platform/MfaRequired` page
   with the same status instead of raw JSON (RequireMfa itself is
   unchanged). Assurance is re-established only by signing in with a code.
2. **Refused grant/revoke requests reach the service** (no capability
   middleware on the two POST routes) so that every refusal — including
   `capability_missing` — is audited as `platform.role_grant.denied`; the
   GET page keeps the route middleware. Outcome codes: `capability_missing`,
   `self_grant`, `self_revoke`, `role_not_assignable`, `already_granted`;
   `role_key` is recorded only for an existing platform role (never free
   input). An unknown or disabled person is a plain validation error.
3. **The page cannot choose a role**: the controller always grants
   `platform_auditor`; a client-sent `role` is ignored.
4. **Database refusal ordering**: marking the root role runtime-assignable
   is refused by the root-reserved-capability trigger before the CHECK
   (either refuses).
5. **Rollback** of the history migration keeps only active grants (the old
   total unique cannot hold revoked history) — a deliberate, documented
   loss on `down()` only.
6. **Demo**: `platform.auditor@example.test` holds `platform_auditor`,
   granted by the Platform Admin (a real runtime grant); like every demo
   account it has no MFA factor until enrolled.
7. **Index**: none added. DDEV holds a few dozen rows; the existing
   `occurred_at` index serves the keyset order.

CLAUDE.md rule 85 records the platform authority invariants.

## Implementation cross-reference (Phase 0O.1, 2026-09-25)

Section 2's production provisioning path is built as the operator console
command `php artisan platform:provision-root {user} [--force]`
(`App\Console\Commands\ProvisionPlatformRoot`,
`App\Domain\Platform\Application\Roles\PlatformRootProvisioningService`):

- console only — no HTTP route or UI (guard-tested);
- one existing, enabled account by exact email or id; unknown, disabled
  and malformed identifiers are refused without echoing them; no account
  is created;
- the operator types the account's email to confirm; a non-interactive
  run needs `--force` (trusted automation only);
- runs on the migration/admin connection (`pgsql_admin`) and refuses when
  that connection is the runtime role;
- one transaction writes the assignment (grantor NULL) and
  `platform.role_grant.provisioned` (actor null, subject the assignment,
  metadata `role_key`, `user_id`, `method: console`);
- idempotent: an existing active root assignment is reported as already
  provisioned with no row or event; concurrent runs are settled by the
  partial unique index `platform_role_assignments_one_active` (a
  two-process race test proves one row and one event);
- the root role is identified structurally (the system platform role that
  is not runtime-assignable and holds `platform.role_grants.manage`), so
  the rule-85 guard against naming it in application code still holds.

No trigger was changed or bypassed. **Residual, recorded honestly:** the
database distinguishes a runtime grant only by a non-NULL grantor, so a
NULL-grantor insert of the root role is still accepted from any
connection, including the runtime role (test fixtures rely on this). The
application's only code path that writes one is this console service
(guard-tested); a stronger database-level separation would need the
fixtures and the runtime role's grants reworked and is not part of 0O.1.
Creating the first platform account in production is also outside this
command (`docs/architecture/PRODUCTION-RELEASE.md` §5).
