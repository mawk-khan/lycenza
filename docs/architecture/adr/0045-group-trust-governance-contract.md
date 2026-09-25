# ADR 0045: Group/Trust Governance Contract (Phase 0N.4)

- Status: Accepted; foundation implemented in Phase 0N.5 (see
  "Implementation amendment" at the end — zero School routes opted in)
- Date: 2026-09-24 (Phase 0N.4); amended 2026-09-24 (Phase 0N.5)
- See also: ADR 0047 (School lifecycle: creating a School adds it to no
  Group; suspending one never changes its Group membership, the Group view
  may still show its status, and Group-derived elevation into it is refused);
  ADR 0048 (D15: amends §4 with a third Group capability,
  `group.reporting.view`, for `group_admin`, and §14 with exactly one
  Group-safe cross-School report, `curriculum.coverage`, read one School at
  a time through Analytics; built in Phase 0N.11 -- `group_admin` now holds
  `group.schools.view`, `group.schools.elevate`, `group.reporting.view`)

## Context

ADR 0004 places a School Group / Trust *above* the tenant boundary: "a
group admin's cross-school access is an explicit, granted, audited
elevation, never a default." Phase 0B created only the structural tables.
`docs/architecture/PHASE-0N-READINESS.md` left two decisions open for
groups: **D1** (who a Group/Trust administrator is) and **D18** (who
governs which Schools belong to a Group). ADR 0044 (Phase 0N.2) and its
Phase 0N.3 substrate built temporary platform elevation into one School,
with no School route accepting it.

**On 2026-09-24 the product owner approved D1 and D18** (section 1). This
ADR turns them into an implementation contract. It changes no code, adds
no migration and seeds nothing. It does **not** resolve D11 (School
lifecycle), D12 (School creation, platform-role governance), D13
(platform membership administration), D15 (group / cross-School
reporting) or D16 (platform audit review).

### What exists today (verified on `2e0006b`, schema and DDEV)

| Table / field | Current meaning | Constraint | Sufficient for Phase 0N? |
|---|---|---|---|
| `school_groups.id` | UUIDv7 key (`App\Models\SchoolGroup`) | PK | Yes |
| `school_groups.name` | display name | NOT NULL | Yes |
| `school_groups.slug` | identifier | UNIQUE | Yes |
| `school_groups.created_at/updated_at` | timestamps | nullable | Yes |
| *(no status column)* | a Group cannot be deactivated, only deleted | — | **No** — deletion cascades (below); an archive state is needed (section 8) |
| `school_group_members.id` | UUID key | PK | Yes |
| `school_group_members.school_group_id` | the Group | FK `school_groups`, **ON DELETE CASCADE** | **No** — deleting a Group silently erases its membership (section 13) |
| `school_group_members.school_id` | a member School | FK `schools`, **ON DELETE CASCADE** | Acceptable only while School deletion stays out of scope (D11) |
| `unique(school_group_id, school_id)` | no duplicate pair | UNIQUE | Yes |
| *(no unique on `school_id` alone)* | **a School may belong to several Groups** | — | Yes (section 7) |
| *(no status / added_by / removed_at)* | membership is current state only; removal is a row delete | — | Yes, with history in the platform audit ledger (section 11) |
| *(no human assignment of any kind)* | nothing links a User to a Group | — | **No** — the Group principal must be added (section 3) |

Other facts relied on:

- Both tables are platform-owned with **no RLS** (`relrowsecurity =
  false`), like `school_memberships`. DDEV holds **0** Groups and **0**
  Group memberships. No route, service, capability, seed or factory
  touches them; `SchoolGroup`'s docblock refers to a `SchoolGroupMember`
  class that does not exist. The `school_group_members` migration
  docblock already says membership "grants no access by itself" and that
  group access would come from "platform_role_assignments / a future
  group-scoped role" — this ADR chooses the latter.
- `roles.scope` is a free-text column with **no CHECK constraint**; the
  seeded values are `platform` and `school`. Scope separation is enforced
  only by the `BEFORE INSERT OR UPDATE` triggers
  `trg_platform_role_assignments_scope` and
  `trg_membership_role_assignments_scope` (CLAUDE.md rule 25).
- `capabilities.namespace` holds `platform` (11 keys, including
  `platform.schools.elevate` and the service-identity keys `ai.tools.invoke`,
  `ai.audit.write`) and `school` (137).
- `platform_role_assignments` has `user_id`, `role_id`,
  `granted_by_user_id`, `granted_at` and no revocation history (a revoke
  is a delete).
- `CapabilityResolver::can()` dispatches `platform.*` keys to platform
  resolution and everything else to School resolution; both caches have a
  60-second TTL.
- `school_elevations` (ADR 0044, Phase 0N.3) records a platform-authorized
  elevation only: `actor_user_id`, `school_id`, `reason_code`, status and
  times; its validity re-checks `platform.schools.elevate` on every
  request.

None of this contradicts the owner's decisions.

## Decision

### 1. Owner decisions (2026-09-24)

| # | Decision |
|---|---|
| **D1** | A Group/Trust administrator is a **distinct Group-scoped principal**: not a platform role, not a School role, not an ordinary School membership, not a hidden membership in every member School. A human may hold Group authority for one or more explicitly identified Groups. Group scope is distinguishable from platform scope, School scope and elevated School context. **Group authorization grants no School capability.** Entry into a member School reuses ADR 0044 elevation — no separate impersonation mechanism, no School membership. |
| **D18** | Which Schools belong to a Group is **platform-governed** in v1. A Group Admin cannot add, remove or move a School, broaden the boundary, create a Group, or archive/delete one. |

### 2. Three authorization scopes

| | Platform | Group | School |
|---|---|---|---|
| Principal | platform operator | Group administrator | School member |
| Assignment | `platform_role_assignments` | `group_role_assignments` (new, section 3) | `school_memberships` + `membership_role_assignments` |
| Role scope | `platform` | `group` (new) | `school` |
| Capability namespace | `platform.*` | `group.*` (new) | module namespaces (`students.*`, `hr.*`, …) |
| Tenant meaning | none — outside every tenant | none — above the tenant boundary; names a set of Schools, reads none of their tenant rows | exactly one School (`TenantContext`, RLS) |
| Allowed operations | platform actions; Group governance (section 5); platform elevation (ADR 0044) | its own Group's metadata; Group-derived elevation (section 9) | that School's modules, per capability |
| Audit ledger | `platform_audit_events` | `platform_audit_events` | `school_audit_events` |

Scopes never merge: a `platform.*` capability is never satisfied by a
Group or School grant, a `group.*` capability never by a platform or
School grant, and a School capability never by a platform or Group grant.
Holding several scopes (a platform operator who is also a Group
administrator and a member of some School) gives each authority only
through its own path. An ordinary member of several Schools holds no
Group authority (Phase 0N readiness section 3): their access stays
membership-based with explicit School selection.

### 3. The human Group grant

One new platform-owned table, `group_role_assignments` (named after the
two existing assignment tables), no RLS:

| Field | Notes |
|---|---|
| `id` | UUIDv7 |
| `user_id` | FK `users`, restrict delete |
| `school_group_id` | FK `school_groups`, restrict delete |
| `role_id` | FK `roles`; a trigger requires `roles.scope = 'group'` (the pattern of CLAUDE.md rule 25) |
| `granted_by_user_id` | FK `users`; **CHECK `granted_by_user_id <> user_id`** — nobody grants themselves Group authority |
| `granted_at` | |
| `revoked_at`, `revoked_by_user_id` | set once on revocation; the row is kept (an elevation references the grant that authorized it, section 9) |
| `created_at`, `updated_at` | |

- One active grant per `(user_id, school_group_id, role_id)`: partial
  unique index where `revoked_at IS NULL`.
- A revoked grant is immutable and never reactivated (a trigger, as on
  `school_elevations`); re-granting inserts a new row. The runtime role
  cannot `DELETE`.
- A human with authority over two Groups holds two grants — one per
  Group. A grant never names a School and is never copied to one.
- The implementation adds a CHECK on `roles.scope IN ('platform',
  'school', 'group')` so the new scope cannot drift, and keeps the two
  existing triggers unchanged.

### 4. Group capabilities — deliberately two

Following the dotted `namespace.resource.action` convention, namespace
`group`, held through one system role `group_admin` (scope `group`):

| Capability | Meaning |
|---|---|
| `group.schools.view` | see the assigned Group's own metadata and its member Schools' identity metadata (section 12) |
| `group.schools.elevate` | start an ADR 0044 elevation into a School that is currently a member of that Group (section 9) |

No `group.*.manage` capability exists in v1: a Group Admin changes
nothing about the Group, its membership or its grants. `CapabilityResolver`
gains a Group side (`groupCapabilities(User, SchoolGroup)`, cache key
naming both user and Group) and `can()` dispatches `group.*` keys to it;
`schoolCapabilities()` and `platformCapabilities()` are untouched and never
consult a Group grant.

Not seeded here.

### 5. Governance — who changes what (platform-governed, v1)

Platform capabilities (convention: `platform.<resource>.<action>`, like
`platform.schools.manage`, `platform.feature_flags.manage`,
`platform.service_identities.manage`), not seeded here, recommended for
`platform_super_admin` at implementation:

| Capability | Covers |
|---|---|
| `platform.school_groups.view` | list Groups and their membership (platform audience) |
| `platform.school_groups.manage` | create a Group, rename it, archive it; add a School to a Group; remove a School from a Group |
| `platform.school_group_grants.manage` | grant a human a Group role; revoke it |

- **Grants.** Creating, revoking and changing a Group grant is a platform
  operation. "Changing the role" of a grant is revoke + new grant (two
  audited events, no in-place edit), and "assigning another Group" is a
  new grant for that Group. A Group Admin can do none of these —
  including for **other** humans in their own Group: least privilege, and
  it keeps the set of people who can enter a Group's Schools under
  platform control. Self-grants are refused by the database (section 3).
- **Boundary (D18).** Adding a School to a Group and removing one are
  platform operations. **Moving** is not its own operation: it is remove +
  add, run in one transaction by the platform operator, producing both
  audit events — so there is no path that "moves" without the removal's
  termination effects (section 10).
- The boundary capability and the grant capability are separate so that
  "which Schools" and "which people" can later be held by different
  operators. Who may hold these platform capabilities is D12 (platform
  role governance) and is not decided here; this ADR only says they are
  platform capabilities, never Group or School ones.
- **Platform Super Admin is not an automatic Group Admin.** Platform
  governance capabilities let an operator manage the Group layer; they
  never satisfy a `group.*` check. A platform operator who wants to enter
  a School uses **platform** elevation (`platform.schools.elevate`) or
  holds a real Group grant given by another operator (no self-grant).

### 6. Target selection and exactness

Group-derived elevation keeps ADR 0044's exact target: the actor names
the School exactly **and** the Group whose authority they use. When
`group.schools.view` and its classification (section 13a) allow the
member list, choosing a School from the actor's own Group's member list is
an exact choice (by id) — it is not a platform directory, because it
lists only Schools the actor's Group grant already covers. No search, no
cross-Group list, no School outside the Group.

### 7. A School may belong to several Groups

The schema deliberately allows it (uniqueness is on the pair only) and no
repository document says otherwise, so v1 **preserves multiple
membership**. Consequences:

- **Authority is per Group, never pooled.** An actor with grants in two
  Groups that both contain School X starts an elevation under exactly one
  of them, chosen explicitly; the elevation records that one Group and
  grant (section 9). Losing that Group's authority ends it even if the
  other Group would still allow entry — no silent fallback (section 10).
- **Attribution** is therefore always a single Group and grant.
- **Duplicate authority** is harmless: one active elevation per actor
  (ADR 0044) still applies across all authority sources.

### 8. Group lifecycle (only what D18 needs)

`school_groups` gains `status` ∈ {`active`, `archived`}. Archiving is a
platform operation (`platform.school_groups.manage`): it ends every
Group-derived authority immediately (section 10); it keeps the Group, its
grants (revoked by the archive) and its history. **No application path
deletes a Group**, and `school_group_members.school_group_id` changes to
restrict-on-delete (section 13). Reactivating an archived Group is not
defined in v1. Group lifecycle is independent of School lifecycle (D11):
archiving a Group changes no School's `status`, membership or data.

### 9. Group-derived elevation — the same primitive

Not a second subsystem. `school_elevations` gains the authority that
started it, set at start and immutable (added to the ADR 0044 transition
trigger):

| New field | Platform-derived | Group-derived |
|---|---|---|
| `authority_type` | `platform` | `group` |
| `school_group_id` (FK restrict) | NULL | the authorizing Group |
| `group_role_assignment_id` (FK restrict) | NULL | the authorizing grant |

A CHECK ties them together (platform → both NULL; group → both set).
Existing rows are `platform`.

Start chain for a Group-derived elevation, then ADR 0044 unchanged:

1. active, authenticated, not disabled human;
2. an active (unrevoked) `group_role_assignments` row for the named Group;
3. that Group is `active`;
4. the grant's role holds `group.schools.elevate`;
5. the exact target School is currently a member of that Group (a
   `school_group_members` row) and is active;
6. the actor is not a member of the target School;
7. no active elevation for the actor (any authority);
8. an approved reason code (the same four), explicit confirmation, a
   fresh MFA re-verification;
9. 30-minute fixed expiry, the same banner, Exit, audit, and **zero
   School capabilities** — every School route still refuses elevated
   context unless it opted in (CLAUDE.md rule 83; none has).

**Per-request validity** checks the authority the elevation was started
under, and only that one: for `group`, the grant is unrevoked, the Group
is active, the role still holds `group.schools.elevate`, and the School is
still a member of that Group — plus every ADR 0044 check (expiry, actor,
School active, no membership, MFA factor, no ordinary selection). For
`platform`, exactly today's checks. There is no fallback between
authorities.

### 10. Removal, revocation and termination

| Event | Effect (same transaction where it is an in-app action) |
|---|---|
| School removed from a Group | the membership row is deleted (history in the audit ledger, section 11); every **active** elevation with `school_group_id` = that Group and `school_id` = that School is terminated (`school_left_group`); future Group-derived starts fail at step 5 |
| Group grant revoked | every active elevation with that `group_role_assignment_id` is terminated (`group_authority_revoked`); the actor's Group capability cache is forgotten |
| Group archived | all its unrevoked grants are revoked; every active elevation with that `school_group_id` is terminated (`group_inactive`) |
| `group.schools.elevate` removed from the role | caught per request (`group_authority_revoked`); an in-app role change must forget the affected Group capability caches |
| Actor disabled, School ineligible, MFA factor revoked, membership conflict, expiry, exit, logout | as ADR 0044 |

- New end reasons `school_left_group`, `group_authority_revoked` and
  `group_inactive` extend ADR 0044's closed catalog (and its CHECK) as
  `terminated` reasons.
- Grant, Group-status and Group-membership state are read **uncached** in
  the per-request validity check (a few indexed lookups); only the role's
  capability goes through the 60-second resolver cache, and every in-app
  change path forgets it. Removal is therefore immediate for every in-app
  path.
- No removal alters ordinary School memberships, School roles or School
  data, and a grant is never copied to or left behind on a School.

### 11. Audit

All in `platform_audit_events` through `AuditRecorder::platform()` — no
new ledger. Identifiers and codes only.

| Event | Subject | Metadata allowlist |
|---|---|---|
| `platform.school_group.created` | Group | — |
| `platform.school_group.renamed` | Group | — (names are not copied into audit) |
| `platform.school_group.archived` | Group | `revoked_grant_count`, `terminated_elevation_count` |
| `platform.school_group.school_added` | Group | `school_id` |
| `platform.school_group.school_removed` | Group | `school_id`, `terminated_elevation_count` |
| `platform.school_group_grant.granted` | the grant | `school_group_id`, `user_id`, `role_key` |
| `platform.school_group_grant.revoked` | the grant | `school_group_id`, `user_id`, `terminated_elevation_count` |
| `platform.school_elevation.*` (ADR 0044) | target School | ADR 0044's allowlist plus `authority_type`, and for Group authority `school_group_id`, `group_role_assignment_id` |

Because Group membership rows (`school_group_members`) are deleted on
removal, the pair of
`school_added` / `school_removed` events is the membership history; the
elevation record itself carries the authorizing Group and grant, so which
Group authorized an elevation is provable without the membership row.
Group-derived denials add outcome codes `group_grant_missing`,
`group_inactive`, `group_capability_missing` and `school_not_in_group`.
School-ledger rows written under a Group-derived elevation carry
`elevation_id` exactly as today.

### 12. Group context and the Group directory boundary

- **TenantContext is untouched** and stays exactly one School. A Group is
  never placed in it, no Group page sets `app.current_school_id`, and no
  Group request reads a tenant table. There is no ambient "current Group":
  a Group page is bound to its `{schoolGroup}` route parameter and
  authorized per request by `group.schools.view` on an active grant for
  that Group — the same route-bound shape as `/api/v1/schools/{school}`.
- **Minimum Group view** (when built): the Group's name; its member
  Schools' id, name and `schools.status`; the actor's own grant. Read only
  from platform tables (`school_groups`, `school_group_members`,
  `schools`). Never counts, students, staff, finance or any tenant-domain
  record, and never another Group's data. Whether the Group view also
  lists the **other humans** holding grants in that Group is not needed
  in v1 and is not built.

### 13. Foreign keys and history

| FK / behaviour | Risk | Contract |
|---|---|---|
| `school_group_members.school_group_id` ON DELETE CASCADE | deleting a Group erases its membership silently | change to RESTRICT; revoke `DELETE` on `school_groups` from the runtime role; Groups are archived, never deleted |
| `school_group_members.school_id` ON DELETE CASCADE | deleting a School erases its Group memberships | left as is: School deletion is D11 and already unsafe (all School FKs cascade, readiness section 13); `school_elevations` already restricts it |
| Membership removal = row delete | no state history in the table | accepted: history is the append-only audit pair (section 11); elevation provenance is on the elevation row |
| `group_role_assignments` FKs | — | restrict (a grant referenced by an elevation must survive) |
| `school_elevations` new FKs | — | restrict |

### 13a. Data classification — an implementation gate

Already decided and unchanged: elevation records, Group-derived included,
are **Highly Sensitive** (D14, ADR 0044); audit records keep their v1
Highly Sensitive fail-safe treatment. **Not yet decided** and required
before the implementation ships any Group view or grant listing
**[PRODUCT/SECURITY REVIEW REQUIRED]**:

| Record | Starting point for the review (not a decision) | Why |
|---|---|---|
| Group metadata (`school_groups`) | Confidential | organisational, not personal |
| Group-to-School membership | Confidential | reveals a trust's composition; commercially sensitive across tenants |
| Human Group grants | Sensitive, security-relevant | links an identifiable person to privileged authority (same reasoning as platform role information, readiness section 9) |
| Member School identity in a Group view (id, name, status) | Confidential | the same School-metadata question readiness section 9 left open |

Until recorded, implementation applies the strictest applicable
controls and ships no Group view.

### 14. Exclusions

- **No cross-School reporting.** Group scope authorizes no query across
  several Schools' tenant rows, no Analytics / Compliance / Automation /
  AI across Schools, no database bypass and no single SQL statement over
  tenant data. Any Group reporting needs its own ADR (D15, ADR 0040 §4,
  ADR 0042, ADR 0043).
- **No School lifecycle, creation or platform administration.** A Group
  role cannot create, suspend or archive a School (D11), create Schools or
  grant platform roles (D12), or administer School memberships (D13).
- **No platform audit review** (D16).

### 15. Frozen invariants

1. Group is a distinct authorization scope (`group` roles, `group.*`
   capabilities, `group_role_assignments`).
2. Group authority is explicit: a grant naming one Group, given by
   someone else, audited, revocable, never inferred from membership.
3. Group authority grants no School capability.
4. A Group never enters TenantContext.
5. TenantContext remains one School.
6. A Group Admin cannot change Group membership, Groups or grants in v1.
7. The platform governs Group boundaries and grants.
8. Group-derived School entry is ADR 0044 elevation, recording the Group
   and grant, with every ADR 0044 control.
9. Removing a School from a Group, revoking a grant or archiving a Group
   ends derived entry immediately and terminates active derived
   elevations.
10. No cross-School tenant query.
11. No RLS bypass.
12. Ordinary multi-School membership is not Group authority.
13. Audit attribution preserves Group and grant provenance.
14. Source modules remain authoritative (CLAUDE.md rule 83).
15. No fallback between authority sources; no self-granted authority.

## Alternatives considered

1. **A platform role limited to named Groups** (readiness D1(b)).
   Rejected by D1: it disguises Group authority as platform authority,
   and platform capabilities are global by construction.
2. **A School role held in every member School.** Rejected by D1: a
   hidden membership fan-out, the implicit access ADR 0004 forbids.
3. **Reuse `school_group_members` for humans.** Rejected: it relates
   Schools to Groups; people and Schools must not share one table.
4. **Group Admins manage their own Group's grants or membership.**
   Rejected by D18 and least privilege: a Group Admin could widen who can
   enter Schools, or which Schools.
5. **One School per Group** (unique `school_id`). Rejected: the schema
   deliberately allows several and no document forbids it; authority is
   attributed per Group instead.
6. **A separate Group elevation subsystem.** Rejected: one primitive with
   an authority source keeps MFA, expiry, banner, audit and the
   zero-capability rule identical.

## Consequences

- D1 and D18 are recorded. Phase 0N stays **BLOCKED** on D11, D12, D13,
  D15 and D16, and the Group implementation additionally waits on the
  classification gate in section 13a before any Group view ships.
- ADR 0044 gains a cross-reference: its elevation record will carry an
  authority source; its invariants are unchanged.
- The implementation amends CLAUDE.md rule 25 (three role scopes, each
  trigger-enforced) at that time.

## Proposed implementation checkpoint (not started)

**Phase 0N.5 — Group Authority Foundation** (number/title for the owner
to confirm):

1. `roles.scope` CHECK (platform/school/group); `group_admin` role;
   `group.schools.view`, `group.schools.elevate`;
   `platform.school_groups.view`, `platform.school_groups.manage`,
   `platform.school_group_grants.manage` (to `platform_super_admin`).
2. Migrations: `group_role_assignments` (scope trigger, no-self-grant
   CHECK, partial unique, immutable revocation, no DELETE);
   `school_groups.status`; `school_group_members.school_group_id` →
   RESTRICT and no `DELETE` on `school_groups`; `school_elevations`
   authority columns, CHECK, trigger and new end reasons — all with
   working `down()`.
3. `CapabilityResolver` Group side and `can()` dispatch, with guard tests
   that no School or platform check consults a Group grant.
4. Platform governance actions (create/rename/archive Group, add/remove
   School by exact identifier, grant/revoke) with their audit events and
   termination effects — no School directory.
5. Group-derived elevation through `SchoolElevationService` and the
   existing flow; per-request validity by authority; zero School routes
   opted in.
6. Group view only if section 13a is decided; otherwise deferred.
7. Tests: allow/deny for every step of section 9; removal, revocation
   and archive terminating derived elevations; no fallback to platform
   authority; multiple-Group attribution; no self-grant; a Group Admin
   refused every governance action; RLS raw-SQL proof unchanged; DDEV
   review.

## Implementation amendment (Phase 0N.5, 2026-09-24)

**Owner classification decisions (section 13a, approved for v1):** Group
details **Confidential**; School-to-Group membership **Confidential**; human
Group administrative grants **Sensitive**; member-School identity in the
Group view **Confidential**. Group-derived elevation records and their
audit evidence stay **Highly Sensitive**. These authorize only the
platform/Group metadata below — no tenant record, people count, financial,
HR/Payroll, Student, Analytics, Compliance, Automation or cross-School data.

**Capability set verified before coding.** Section 5 and the proposed
checkpoint both name the same three platform capabilities —
`platform.school_groups.view`, `platform.school_groups.manage`,
`platform.school_group_grants.manage` — and AUTHORIZATION.md's
`platform.school_groups.*` covers the first two. No inconsistency; nothing
invented. All three were seeded to `platform_super_admin`, as section 5
recommended.

**What was built** (no existing School route accepts elevated context):

| Contract | Implementation |
|---|---|
| Scopes (§2) | `roles_scope_check` (`platform`/`school`/`group`; every existing row was `platform` or `school`); `capabilities_group_namespace_check` (a `group.*` key ⇔ namespace `group`); trigger `trg_role_capabilities_scope`: a role holds only its own scope's capabilities (all existing rows already did) |
| Group role and capabilities (§4) | system role `group_admin` (scope `group`) with exactly `group.schools.view`, `group.schools.elevate` |
| Grant (§3) | `group_role_assignments` (`App\Models\GroupRoleAssignment`), no RLS: `trg_group_role_assignments_guard` (role scope `group`; the Group must be active; not created already revoked; the only change is one revocation, after which the row is immutable), `group_role_assignments_no_self_grant`, `group_role_assignments_revocation_check`, partial unique `group_role_assignments_one_active`, `unique(id, school_group_id)` for the elevation FK, restrict-on-delete FKs, `DELETE` revoked |
| Group lifecycle (§8, §13) | `school_groups.status` (`active`/`archived`, CHECK); `school_group_members.school_group_id` now RESTRICT; `DELETE` on `school_groups` revoked; the School-side cascade left for D11 |
| Resolver (§4) | `CapabilityResolver::groupCapabilities()` / `canInGroup()` / `groupsWith()`; `can()` returns false for any `group.*` key |
| Governance (§5) | `App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService` (create, rename, archive, add/remove School, grant/revoke); pages `/app/platform/groups…` (`SchoolGroupAdminController`) |
| Group view (§12) | `/app/groups`, `/app/groups/{schoolGroup}` (`SchoolGroupController`): Group id/name/status and member Schools' id/name/status only |
| Elevation provenance (§9) | `school_elevations.authority_type`, `school_group_id`, `group_role_assignment_id`; `school_elevations_authority_check`; composite FK `school_elevations_group_grant_fk` → `group_role_assignments(id, school_group_id)`; the ADR 0044 transition trigger now also freezes the authority columns; end reasons `school_left_group`, `group_authority_revoked`, `group_inactive` |
| Group-derived start (§9) | `SchoolElevationService::start(…, ?SchoolGroup)` and `/app/groups/{schoolGroup}/elevation…` — the same record, pointer, context, banner (now naming the Group), audit, expiry, Exit and sweep |
| Audit (§11) | the seven `platform.school_group*` events; `platform.school_elevation.*` metadata now always carries `authority_type`, plus `school_group_id` and `group_role_assignment_id` for Group authority |

**Refinements made while implementing** (none widens access):

1. **Group capabilities are fully uncached**, not only the grant, Group
   and membership state (section 10 allowed a cached role capability with
   cache-forgetting on change). One indexed join per check; a revoked
   grant, an archived Group or a capability removed from the role stops
   counting on the very next check, even for an out-of-band change.
2. **Database-enforced start-time authority.** A BEFORE INSERT trigger on
   `school_elevations` (`school_elevations_assert_group_authority`)
   re-reads the Group (active), the grant (unrevoked, this actor's, this
   Group's) and the membership row **FOR SHARE**. So a removal,
   revocation or archive racing a start either waits for the start to
   commit and then terminates it, or commits first and makes the start
   fail — proven both ways with two real processes. The loser gets a 409
   `group_authority_changed` refusal audited with the matching outcome.
3. **Denial outcomes** as section 11: `group_grant_missing` (also for
   anyone without a grant — an ordinary multi-School member, a Platform
   Super Admin), `group_inactive`, `group_capability_missing`,
   `school_not_in_group`; the last reads "That School cannot be entered."
   like the other target refusals. A GET of a Group page or its start page
   without authority is a 404, not audited (viewing is not an attempt).
4. **Target selection** (section 6): the Group view lists the Group's own
   member Schools; entering one passes its exact id, and the Group comes
   from the route — the explicit choice when a School is in several of the
   actor's Groups.
5. **Grant visibility**: only holders of
   `platform.school_group_grants.manage` see the grant list; a Group Admin
   sees no grants (least privilege; grants are Sensitive).
6. **Notice after an eager end**: when a governance action already ended
   the elevation, the actor's next request shows that end reason rather
   than a generic "ended".
7. **Demo**: `group.admin@example.test` — a Group-only person with no School
   membership (elevation is refused into a School the actor belongs to) —
   holds `group_admin` in "Lycenza Demo Trust" (both demo Schools), granted
   by the Platform Admin.

**Bootstrap limitation (D12, unchanged):** the platform governance
capabilities are held by `platform_super_admin`, which is assigned only by
seeding; who may grant platform roles remains D12.

CLAUDE.md rule 25 is amended accordingly (three role scopes, each
database-enforced) and rule 84 records the Group-scope invariants.
