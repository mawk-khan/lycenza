# Phase 0N — Multi-School Management: Closeout

**Status: COMPLETE (2026-09-25).** Every item of the roadmap scope is
built and published: a safe no-School landing, explicit temporary
platform elevation into one School, a distinct Group/Trust authority with
Group-derived elevation, platform authority and audit review, School
lifecycle with bootstrap administration, and the first Group cross-School
report. All eighteen readiness decisions (D1–D18) have a recorded
disposition (section 3). Everything not built is future scope explicitly
deferred by an ADR or gated on a recorded legal, product or security
decision (section 12). Closing the phase approves, schedules or implies
none of those.

Roadmap entry: `docs/roadmap/MASTER-ROADMAP.md`, "Phase 0N — Multi-School
Management" (*"Group/Trust cross-school administration and reporting,
building on Phase 0B's explicit-elevation model (ADR 0004) and its
structural foundation (`school_groups`, `school_group_members`)"*). Layer 6
rules: `docs/architecture/DOMAIN-MAP.md`. Readiness audit and decision
matrix: `docs/architecture/PHASE-0N-READINESS.md`. Audited on `fc6d799`.

## 1. How Phase 0N unfolded

The readiness audit found the phase blocked on eighteen decisions. Each
area then received a contract (an ADR, documentation only) before any
code, and a foundation implementing it.

| Checkpoint | Purpose | Published on `main` (merge / commit) | Status |
|---|---|---|---|
| Readiness audit | Current state, gaps, decisions D1–D18 | `e2c4031` / `52eb45d` (2026-09-24) | Complete |
| 0N.1 | Safe School Context & Platform Landing (D9, D10) | `c1e6db8` / `4d30a69` (2026-09-24) | Complete |
| 0N.2 | Cross-Tenant Elevation Contract (ADR 0044; D2–D8, D14 part, D17) | `c04ec90` / `ac546ac` (2026-09-24) | Complete |
| 0N.3 | Platform Elevation Substrate | `2e0006b` / `8049d97` (2026-09-24) | Complete |
| 0N.4 | Group/Trust Governance Contract (ADR 0045; D1, D18) | `79bb722` / `ef7287e` (2026-09-24) | Complete |
| 0N.5 | Group Authority Foundation | `2e29731` / `56dfd68` (2026-09-24) | Complete |
| 0N.6 | Platform Authority & Audit Governance Contract (ADR 0046; D12, D16) | `692132a` / `7dfd4bd` (2026-09-24) | Complete |
| 0N.7 | Platform Authority & Audit Foundation | `060c805` / `76ff191` (2026-09-25) | Complete |
| 0N.8 | School Lifecycle & Bootstrap Administration Contract (ADR 0047; D11, D13) | `9a07eca` / `f3a526f` (2026-09-25) | Complete |
| 0N.9 | School Lifecycle Foundation | `fa453cd` / `b06b504` (2026-09-25) | Complete |
| 0N.10 | Group Cross-School Reporting Contract (ADR 0048; D15) | `093947c` / `180b098` (2026-09-25) | Complete |
| 0N.11 | Group Curriculum Coverage Reporting Foundation | `fc6d799` / `374db84` (2026-09-25) | Complete |

| Area | Contract | Foundation | Status |
|---|---|---|---|
| School context | readiness §11, §17 | 0N.1 | Complete |
| Platform elevation | ADR 0044 | 0N.3 | Complete — substrate; no School route opted in |
| Group/Trust authority | ADR 0045 | 0N.5 | Complete |
| Platform authority & audit review | ADR 0046 | 0N.7 | Complete |
| School lifecycle & bootstrap | ADR 0047 | 0N.9 | Complete |
| Group cross-School reporting | ADR 0048 | 0N.11 | Complete — one report |

## 2. What was built (verified on `fc6d799`)

### School context (0N.1)

`/app` works without a selected School and shows a neutral state (no
School data) to every account without a membership, platform accounts
included. Every School web route carries `school-context`
(`RequireSchoolContext`): no valid selection → back to `/app` (JSON 409
`school_context_required`), a stale selection is cleared, nothing is
auto-selected. `TenantContextRequiredException` stays the defensive
invariant. `/api/v1` keeps its own model (the School in the URL,
`school-membership`, non-disclosing 404). Guard: `SchoolContextRouteGuardTest`
(explicit context-neutral allowlist); behaviour: `SchoolContextRequiredTest`.

### Platform elevation (ADR 0044; 0N.3)

`platform.schools.elevate`; `school_elevations` (one active per actor,
partial unique index; reason-code CHECK; lifetime ≤ 30 minutes CHECK;
finished rows immutable; runtime role cannot delete). Starting needs an
exact target, an active School, no membership, a reason code, explicit
confirmation and a fresh MFA re-verification (`MfaReverificationService`);
denials are audited. The banner shows the School and expiry; Exit, logout,
expiry sweep (`platform:expire-school-elevations`) and per-request
invalidation end it. **Elevation grants no School capability, and no School
route accepts it: zero routes declare `school-context:elevated`** (checked
on `fc6d799`); RLS is unchanged — it is ordinary one-School context.

### Group/Trust authority (ADR 0045; 0N.5)

Three scopes — `platform`, `school`, `group` (`roles_scope_check`;
`trg_role_capabilities_scope` keeps each role to its own namespace;
wrong-scope assignment triggers). `group_admin` is Group-scoped;
Group capabilities come only from an unrevoked `group_role_assignments`
grant for one named, active Group, are never cached and never pooled
across Groups; a Group grant creates no School membership or capability.
Group membership and grants are platform-governed
(`SchoolGroupGovernanceService`; `platform.school_groups.*`,
`platform.school_group_grants.manage`), history-keeping, no self-grant.
Groups never enter `TenantContext`. Group-derived School entry is the same
ADR 0044 elevation, recording its Group and grant, re-verified by the
database at start (`school_elevations_assert_group_authority`) and ended
when that authority goes.

### Platform authority and audit review (ADR 0046; 0N.7)

`platform_super_admin` is the root, provisioned out of band; the database
refuses a runtime grant or revocation of any role not marked
`runtime_assignable` (`trg_platform_role_assignments_governance`).
`platform_auditor` is the only runtime-assignable platform role and holds
only `platform.audit.view`; `platform.role_grants.manage` (root only)
grants and revokes it; no self-grant or self-revoke (CHECKs); grants keep
history (revocation, never deletion); root-reserved capabilities never sit
on a runtime-assignable role (triggers); a grant change forgets the
target's capability cache so it takes effect at once.
`/app/platform/audit-log`: `platform.audit.view` + current MFA assurance,
no School context, seven envelope fields (never metadata, IP or user
agent), 50-row keyset pages, one `platform.audit_log.viewed` per review,
no export, search or retention control; separate from the School audit-log
review.

### School lifecycle (ADR 0047; 0N.9)

`schools.status` ∈ `provisioning`/`active`/`suspended`/`archived`
(`schools_status_check`), default `provisioning`, transitions limited to
provisioning→active, active→suspended, suspended→active for every role
(`trg_schools_status_transition`); no application path into `archived`;
the runtime role has no `DELETE` on `schools`. Create, bootstrap-admin
replacement, activate, suspend (closed reason codes) and resume need the
root-reserved `platform.schools.manage`, explicit confirmation and a
fresh MFA code; refusals are audited. The bootstrap School Administrator
is a real, ordinary membership with `school_admin`; the path is open only
while `provisioning` and closes permanently at first activation; there is
no other platform membership administration. Activation needs a
non-disabled member holding `school.members.manage` and
`school.roles.manage`.

**Suspension** is enforced at execution time
(`SchoolOperationalGuard::holdOperational()`, the School row FOR SHARE in
each claim transaction): web and API refuse (per request), elevations end
in the suspension transaction (`school_suspended`) and cannot start (INSERT
trigger), webhook and communication deliveries defer without consuming an
attempt, redispatchers and the announcement publisher skip non-active
Schools, automation executions become terminal `skipped`, AI context
tokens are not minted and the internal AI endpoints refuse, Guardian
invitations are unusable, outbox dispatch continues while consumers gate
their effects. Safety work (audit, elevation expiry, pruning, telemetry,
sign-in) continues; there is no blanket queue refusal. Resume replays
nothing; a selected School must be chosen again. **Limit, stated
precisely:** a business effect cannot *begin* once the suspension is
visible to its claim, and a claim committed first is in-flight work the
suspension waits for; but an external HTTP call or message send made
after such a claim cannot be atomically undone with the status change, and
a web request admitted just before the suspension commits finishes.

### Group cross-School reporting (ADR 0048; 0N.11)

`group.reporting.view` (held by `group_admin` only), current MFA
assurance, one named active Group, one unrevoked grant. Analytics owns a
closed Group-safe registry separate from its School registry —
`curriculum.coverage` only, `countsPeople: false`, Confidential — and
`GroupSafeReportGate`. `GroupCurriculumCoverageReportService` observes each
member School in its own transaction (Group, grant and membership FOR
SHARE; School FOR SHARE), with exactly one `TenantContext` per reading,
cleared after each; the active academic year only (no fallback);
`unavailable` for provisioning/suspended/archived Schools and "no active
academic year" otherwise non-contributing; totals are summed counts with
the percentage recomputed from the sums (never averaged); a final
authority re-check; lost authority (404) or a source error (503) fails the
whole report with no partial figures. No export, cache or persistence.
`analytics.view`, `AnalyticsReadGate` and the School report are unchanged
(including its fallback-year behaviour).

## 3. D1–D18 resolution

| Decision | Resolution source | Implemented? | Remaining (outside Phase 0N) |
|---|---|---|---|
| D1 Group principal | ADR 0045 (a) | Yes, 0N.5 | — |
| D2 Platform may enter a School | ADR 0044 (a) | Yes, 0N.3 | — |
| D3 Elevation vs membership | ADR 0044 (a) | Yes, 0N.3 | — |
| D4 Elevation controls | ADR 0044 + 0N.3 amendment (30 min, reason catalog, banner, Exit) | Yes | — |
| D5 MFA for platform actions | ADR 0044 (elevation start); then per surface: audit review (ADR 0046, assurance), lifecycle (ADR 0047, fresh code), Group report (ADR 0048, assurance) | Yes, for those surfaces | Group governance, Group grants, platform-auditor grants and MFA reset have **no** MFA requirement (ADRs 0045/0046 set none); a general "MFA on every privileged platform action" policy is a future security decision, recorded in section 12 |
| D6 Second-person approval | ADR 0044 (none in v1) | By design | A later ADR may require it per elevated operation |
| D7 Excluded domains while elevated | ADR 0044 (zero source modules; per-operation opt-in) | Yes (no route opted in) | Each future elevation-safe operation needs its own ADR |
| D8 Capabilities while elevated | ADR 0044 (none) | Yes | — |
| D9 Platform landing | readiness (a) | Yes, 0N.1 | — |
| D10 No-School behaviour | readiness (a) | Yes, 0N.1 | — |
| D11 School lifecycle | ADR 0047 | Yes, 0N.9 | Archive/delete: retention/legal decision |
| D12 Platform authority | ADR 0046 | Yes, 0N.7 | Production root-provisioning path: Phase 0O operations |
| D13 Platform membership administration | ADR 0047 (bootstrap only) | Yes, 0N.9 | School administrative break-glass recovery: its own contract |
| D14 Classification of 0N records | ADRs 0044–0048 rows in `DATA-CLASSIFICATION.md` | Yes | Ordinary School memberships (beyond the bootstrap relationship) are School/Identity records no Phase 0N surface exposes; their classification belongs to the School-side membership administration unit |
| D15 Group reporting | ADR 0048 | Yes, 0N.11 (one report) | Further reports, export, persistence: ADR 0048 amendments |
| D16 Platform audit review | ADR 0046 | Yes, 0N.7 | Metadata allowlists, School-visible elevation provenance |
| D17 Auditing denials | ADR 0044 (denied elevation audited) | Yes | Refused School selection stays unaudited (decided) |
| D18 Group membership governance | ADR 0045 (platform-governed) | Yes, 0N.5 | v1 has no School consent step (platform-governed by decision); adding one would be a new product/legal decision |

No decision is silently open.

## 4. Tenancy and RLS invariants (verified on `fc6d799`)

- `TenantContext` holds exactly one School; no multi-School context exists.
- The runtime role `school_os_app` is `NOSUPERUSER`, `NOBYPASSRLS`
  (`rolsuper = f`, `rolbypassrls = f`); every tenant table has RLS enabled
  and forced (144 of 144 on the audit date — a snapshot, not a fixed
  number).
- Platform and Group tables (`school_elevations`, `school_groups`,
  `school_group_members`, `group_role_assignments`,
  `platform_role_assignments`, `platform_audit_events`) are deliberately
  platform-owned, without RLS, and never hold tenant data.
- Elevation establishes ordinary one-School context; the Group report
  enters one School at a time and clears the context before the next
  (raw-SQL proof: `Postgres\GroupReportRlsIsolationTest`).
- No `BYPASSRLS`, no superuser runtime path, no admin-connection reads in
  application code, no global tenant-table reader.

## 5. Authorization inventory (catalog `fc6d799`)

| Capability | Holders |
|---|---|
| `platform.schools.elevate` | `platform_super_admin` |
| `platform.school_groups.view` / `.manage` | `platform_super_admin` |
| `platform.school_group_grants.manage` | `platform_super_admin` |
| `platform.audit.view` | `platform_super_admin`, `platform_auditor` |
| `platform.role_grants.manage` | `platform_super_admin` (root-reserved) |
| `platform.schools.manage` | `platform_super_admin` (root-reserved) |
| `platform.schools.view` | `platform_super_admin` (reserved, unused) |
| `group.schools.view`, `group.schools.elevate`, `group.reporting.view` | `group_admin` |

Platform, Group and School scopes never cross: a role holds only its own
namespace (trigger), each assignment table accepts only its own scope
(triggers), and `CapabilityResolver` resolves each scope separately. Every
Phase 0N surface is authorized by a capability, never a role name (the
root role's name appears nowhere in application code —
`PlatformAuthorityArchitectureGuardTest`).

## 6. Data classification

| Record | Tier |
|---|---|
| Elevation records and `platform.school_elevation.*` | Highly Sensitive |
| Group details, School-to-Group membership, member-School identity in the Group view | Confidential |
| Human Group administrative grants | Sensitive |
| School platform metadata and lifecycle state | Confidential |
| Bootstrap School Administrator relationship | Sensitive |
| Platform audit review and privileged-governance evidence (incl. platform role assignments, lifecycle and Group-report events) | Highly Sensitive |
| Group curriculum-coverage report output | Confidential (strictest source tier; aggregation lowers nothing) |

No Phase 0N report or aggregate claims a tier below its source.

## 7. Audit model

Platform ledger only (`platform_audit_events`), identifiers and codes only:
`platform.school_elevation.denied/started/ended/expired/terminated`;
`platform.school_group.created/renamed/archived/school_added/school_removed`;
`platform.school_group_grant.granted/revoked`;
`platform.role_grant.granted/revoked/denied`; `platform.audit_log.viewed`;
`platform.school.created/bootstrap_admin_assigned/bootstrap_admin_replaced/activated/suspended/resumed/lifecycle_denied`;
`platform.school_group_report.viewed/failed`. Every successful privileged
Phase 0N operation writes one of these. Deliberately **not** audited (each
recorded in its ADR, no "audit every 403"): a refused School selection
(D17), a refused Group view or Group report before any read (ADR 0045
amendment; ADR 0048 §14), a refused platform audit review (ADR 0046), and
refused Group-governance actions (ADR 0045 §11 lists no denial event).
Schools see no elevation events in v1 (ADR 0046 §11).

## 7a. Surfaces

Neutral `/app`; `/app/platform/elevation`; `/app/platform/groups…`;
`/app/groups…` (Group view, Group-derived entry, Group report);
`/app/platform/roles`; `/app/platform/audit-log`; `/app/platform/schools…`;
`/app/groups/{schoolGroup}/reports/curriculum-coverage`. All
context-neutral web routes, capability-gated, none exposed through
`/api`. DDEV review guidance: `docs/development/DDEV-DEMO-REVIEW.md`
steps 13, 13a–13d (no-School landing in the persona matrix; the demo
Annexe has no academic year, so it correctly shows "No active academic
year" in the Group report — multi-School weighted aggregation is proven by
the automated suite).

## 8. Regression evidence

| Checkpoint | Full regression |
|---|---|
| `c1e6db8` (0N.1), `2e0006b` (0N.3), `2e29731` (0N.5) | complete suite green on each unit's final code |
| `060c805` (0N.7) | 5,611 tests, 42,648 assertions, 0 failures |
| `fa453cd` (0N.9) | 5,659 tests, 53,797 assertions, 0 failures (10m41s) |
| **`fc6d799` (0N.11, final)** | **5,685 tests, 55,220 assertions, 0 failures, 1 skipped (ESI-12 legal gate), 13m49s** — isolated `bin/safe-test`, real PostgreSQL, Redis and MinIO |

Also at 0N.11: AI Gateway ruff, ruff format, mypy clean, 56 pytest;
Pint, PHPStan, vue-tsc, Prettier clean; ESLint 3 pre-existing warnings;
production build succeeds (pre-existing chunk-size warning). Phase 0N
suites include real two-process race proofs (elevation, Group authority,
lifecycle, Group report) and raw-SQL RLS proofs.

**Test-environment note (not a product defect):**
`ElevatedSchoolRouteDenialTest`'s verified-domain case fails under
`ddev test` only, because DDEV sets `APP_URL=https://lycenza.ddev.site`, so
test requests never use host `localhost`. It predates 0N.9, reproduces with
those changes stashed, and passes under the canonical `bin/safe-test`.

## 9. Intentionally absent from Phase 0N

No School route accepting elevation; no platform membership
administration beyond bootstrap; no School archive/delete; no break-glass
recovery; no cross-School Compliance, Automation or AI; no cross-School
source APIs; no Group report export or persistence; no report other than
`curriculum.coverage`; no production root-provisioning command.

## 10. Analytics boundary after Phase 0N

Ordinary Analytics stays School-scoped (`analytics.view`,
`AnalyticsReadGate`). ADR 0048 authorizes **exactly one** Group-safe
cross-School exception, `curriculum.coverage`, through a separate registry
and gate; it does not make Analytics generally cross-School, and no
further report is approved. Analytics' own gates are untouched and remain
future work: minimum person-cohort size, suppression mode, threshold
scope, Student-data counsel review, teacher-linkage review, and every
person-counting report.

## 11. Material defects found

None. No missing authorization, no RLS weakness, no unimplemented ADR
requirement, no roadmap item without disposition, no unaudited successful
privileged action, and no cross-School path outside ADR 0048.

## 12. Deferred / gated future work (not incomplete Phase 0N work)

| Item | Owner / gate |
|---|---|
| School archive and delete | Retention/legal/data-preservation decision (ADR 0047 §12) |
| School administrative recovery (break-glass) | Its own security contract (ADR 0047 §6) |
| Production `platform_super_admin` provisioning path | Phase 0O operations (ADR 0046) |
| General MFA policy for all privileged platform actions (D5 remainder) | Security decision |
| Audit of refused Group-governance actions | Security decision (ADR 0045 §11 set none) |
| Additional Group-safe reports; Group report export; persisted snapshots / warehouse | ADR 0048 amendments with classification/retention decisions |
| Queued execution for large Groups | ADR 0048 §13 |
| Cross-School Compliance / Automation / AI | Their own ADRs (ADR 0042, 0043, 0023 / Phase 0M) |
| School visibility of elevation provenance; platform audit metadata allowlists | Privacy/product decisions (ADR 0046) |
| Elevation-safe School operations; second-person approval | Per-operation ADRs (ADR 0044) |
| A School consent step for Group membership (v1: platform-governed) | New product/legal decision if ever wanted (D18) |
| Classification of ordinary School memberships | School-side membership administration unit (D14 remainder) |
| Person-counting Analytics | `ANALYTICS-SMALL-COHORT-POLICY-GATE.md` |

## 13. Conclusion

Phase 0N — Multi-School Management is **COMPLETE (2026-09-25)**, audited
on `fc6d799`. The next roadmap phase is Phase 0O — External Surface and
Production Readiness, which has no readiness audit yet; Phase 0M remains
BLOCKED on its legal/compliance gate.
