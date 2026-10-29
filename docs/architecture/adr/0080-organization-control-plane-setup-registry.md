# ADR 0080: Organization Control Plane — Setup Registry and Derived Readiness (ORG.12A)

- Status: **Accepted and implemented (2026-10-28).** ORG.12A (§1–§6),
  **ORG.12B Setup & Readiness Center (§7)** and **ORG.12C Rich School &
  Campus Profiles (§8)**, all 2026-10-28; **ORG.12D Network, Franchise,
  Region & Zone Governance (§9)** and **ORG.12E Role, Access & Sensitive
  Data Administration (§10)**, 2026-10-29. ORG.12F–H are planned here but
  **not authorized**; each needs explicit owner authorization.
- Date: 2026-10-28
- Programme: **ORG.12 — Organization Control Plane expansion** (additive to
  ORG, `MASTER-ROADMAP.md` programme 13).
- Baseline: `origin/main` = `b80785bb` (SIS 10.1 closure docs). The brief
  named `6d1b5640` (ORG.LOCAL-CLOSE); the SIS programme commits published on
  top of it since are unrelated to Organization and are kept.
- Evidence: the owner-held 515-screen Organisation pack, mapped in
  `docs/organization/ORG-12-REFERENCE-CAPABILITY-MAP.md`.
- Contract: `docs/organization/ORG-12-CONTROL-PLANE-CONTRACT.md`.
- Cross-referenced, unchanged: ADR 0004 (tenancy), ADR 0045/0048 (Groups),
  ADR 0070 §9.6 (setup progress capability), ADR 0071 (role catalogue),
  ADR 0075 §10 (Data Health pattern), **ADR 0076** (ORG control plane;
  §83 points here). **ORG.12 reopens neither School tenancy (ADR 0004, ADR
  0076 §4), the Campus definition (ADR 0076 §10) nor the Group governance
  boundary (ADR 0076 §18, ADR 0045, ADR 0048).**

## 1. Context

The reference ERP's Organisation area (515 screens, 38 families) is a
cross-module administration centre: getting started, branch and year
setup, attendance, fee plans, admissions, user types, menu permissions,
branding, field masking, SMS/email/payment/device integration, vendor
invoices, support tickets, feedback and MIS. 73 % of its screens are a
user-type × menu permission machine that Lycenza deliberately does not copy
(ADR 0076 §15, §19).

Lycenza already had pieces of the convenience: the School setup progress
page (five inline flags in `SchoolSetupController::index`), the platform
Registry's advisory readiness (ADR 0076 §9.3) and Data Health (ADR 0075
§10). It lacked one place that says, per School, which configuration areas
are done, which are blocked by what, and which do not apply.

## 2. Decision

1. **The Organization Control Plane observes; it never owns.** It owns the
   setup catalogue, setup navigation metadata and readiness aggregation. It
   owns no domain record and writes nothing.
2. **One code-owned catalogue.** `App\Support\Setup\OrganizationSetupRegistry`
   (`DEFINITIONS` const, 21 areas). Not tenant-editable; no table; stable
   keys; explicit unique order with dependencies ordered first; closed
   category (`SetupCategory`) and scope (`SetupScopeLevel`) vocabularies;
   `NETWORK_FUTURE`/`PLATFORM_FUTURE` reserved and refused.
3. **Readiness is derived, never stored.** No `setup_complete` flag or
   setup-state table. Each area's `SetupReadinessProvider` lives in the
   module that owns its configuration (the Data Health pattern) and answers
   from canonical state with bounded EXISTS/aggregate queries. Exceptions to
   "domain-owned": `compliance.audit` lives with the audit infrastructure
   (`App\Support\Audit`) because Compliance is a Layer 5 consumer nothing may
   depend on; `integrations.integrations` lives in `App\Support\ApiClients`
   where the Integrations module's code lives; `portal.guardian_portal` lives
   in Identity, which owns Guardian account links.
4. **Bounded status vocabulary** (`SetupStatus`): NOT_APPLICABLE,
   NOT_CONFIGURED, PARTIALLY_CONFIGURED, CONFIGURED, BLOCKED,
   ATTENTION_REQUIRED. No production-ready status.
5. **Entitlement before evaluation.** An optional area whose modules are not
   all entitled is NOT_APPLICABLE and is never evaluated; core areas follow
   the core-module policy (ADR 0076 §14). An unentitled module never blocks
   a dependent area.
6. **Dependencies are informational.** One deterministic pass; incomplete or
   no-own-setup areas with an unsatisfied dependency are BLOCKED; areas
   configured by their own checks are ATTENTION_REQUIRED; blockers are setup
   keys only. Cycles, unknown, self and repeated dependencies are refused in
   linear time.
7. **Authorization reuses existing capabilities.** Floor
   `school.profile.view` (ADR 0070 §9.6) in the School; per-area `view`
   capabilities that are never Campus-adopted (rule 101 — readiness is not a
   declared whole-School hub, so `CampusScopeCatalogue` is unchanged).
   Platform, elevation and Group authority read nothing. No new capability,
   role, template, entitlement or route.
8. **No HTTP surface in ORG.12A.** `OrganizationSetupReadService` is an
   Application service only; the user-facing Setup & Readiness Center is
   ORG.12B.
9. **Readiness is never cached** (measured: 51 queries, ~31 ms for the full
   catalogue). The capability and entitlement readers it calls keep their
   existing tenant-namespaced 60 s caches.
10. **Readiness logic stays code-owned:** no JSON predicates, stored SQL,
    expression language, workflow DSL or School-editable rule.
11. **Active Schools only** (rule 86): the read service refuses a School that
    is not `active`, even to its administrator, so a later non-HTTP caller
    cannot read a suspended or provisioning School.

## 3. Rejected alternatives

| Alternative | Why rejected |
|---|---|
| A `setup_steps` / `school_setup_status` table | Duplicates domain state, drifts, and needs writers in every module (rule 2, §13 of the brief) |
| Tenant-editable checklist or rules | A rules engine by another name; readiness would stop meaning "the domain says so" |
| Organization reading every module's tables directly | Breaks module boundaries (rule 4) and the Compliance/Analytics consumer rule |
| Declaring a `WHOLE_SCHOOL_HUBS` entry to check adopted read keys | Changes `CampusScopeCatalogue` merely for readiness (brief stop condition); the floor + non-adopted keys is sufficient |
| A new `organization.setup.view` capability | Not technically necessary; `school.profile.view` already gates setup progress |
| Copying the reference's user types / menu matrices | Menu ≠ permission (ADR 0076 §15); rule 93 forbids runtime role writes |

## 4. Consequences

- ORG.12B builds the Setup Center on `OrganizationSetupReadService` and
  retires the five inline flags in `SchoolSetupController::index`.
- A new configurable module adds one definition, one provider and the
  architecture-test key list in the same change.
- Campus-level readiness (ORG.12B) will need providers to accept a Campus;
  the contract leaves `SetupScopeLevel::CAMPUS`/`SCHOOL_AND_CAMPUS` ready.
- Readiness of the Student records, Staff records, Student attendance and
  Admissions areas is visible to floor holders without a domain read key,
  because those keys are Campus-adopted. The answers are EXISTS facts only
  (accepted LOW, §6).

## 5. Future checkpoints

ORG.12B Setup & Readiness Center; ORG.12C Rich School & Campus profiles;
ORG.12D Network / franchise governance; ORG.12E Role, access &
sensitive-data administration (read-only explorers, no tenant roles);
ORG.12F Navigation & branding presentation (security independent);
ORG.12G Integration & device readiness hub (no secrets); ORG.12H Reports &
reference re-audit. Then ORG.REAUDIT-1 and ORG.REAUDIT-FINAL on the same
515-screen pack. Scope and boundaries: contract §13.

## 6. Security review (ORG.12A)

| # | Area | Finding | Severity | Resolution |
|---|---|---|---|---|
| S-1 | New tenant hierarchy | None created; guard on tables, columns and class names | — | `OrganizationSetupArchitectureTest` |
| S-2 | Cross-School leakage | One School per call, `withSchool()`, explicit `school_id` scoping; isolation and shared-user tests | — | `OrganizationSetupReadServiceTest` |
| S-3 | Platform access to School facts | No School capability without membership; root, Manager and Group Admin refused | — | tested |
| S-4 | Entitlement bypass | Unentitled areas never evaluated; core list from `ModuleCatalogue` | — | tested |
| S-5 | Menu-as-security | Registry ↔ navigation coupling forbidden; route is a link | — | guard |
| S-6 | Arbitrary SQL/rules | Const catalogue, no SQL, no runtime registration | — | guard |
| S-7 | Sensitive data in readiness | Fixed shape; planted names, emails, phone, address, amounts absent | — | tested |
| S-8 | Registry mutation | No writer; provider/read-service sources write-free; runtime SELECT-only proof | — | tested |
| S-9 | Role-name authorization | None; capability checks only | — | review |
| S-10 | Circular-dependency DoS | Iterative O(V+E) cycle check; 3,000-node hostile chain test | — | tested |
| L-1 | Floor-only areas | Student/Staff records, Student attendance, Admissions readiness visible to floor holders (adopted read keys cannot be checked School-wide) | LOW | accepted, guarded: EXISTS facts only; each such area names its adopted read keys (`adoptedRead`) and `OrganizationSetupArchitectureTest` refuses any system role holding the floor without them |
| L-2 | Blocker keys / NOT_APPLICABLE | A blocker may name an area the viewer cannot see; NOT_APPLICABLE areas show entitlement state to any floor holder | LOW | accepted: code-constant keys and School configuration facts, no tenant records |
| L-3 | Evaluation breadth | All applicable areas are evaluated even if the viewer sees few (needed for dependency status) | LOW | accepted: bounded (51 queries) |
| L-4 | Lifecycle | The service did not itself refuse a non-active School (the HTTP entry middlewares did) | LOW | **fixed**: `isActive()` refusal + suspended/provisioning test |
| L-5 | Students ↔ Guardians | `StudentGuardianCoverage` (Students) reads the Guardian relationship while the Guardians provider calls Students | LOW | accepted: existing precedent (`StudentProcessingAuthorizationReadService`); Students and Guardians are one core family (ADR 0076 §14) |
| L-6 | Cache wording | Evaluation warms the existing grant and entitlement caches although the docs said "nothing is cached" | LOW | **fixed**: wording corrected (readiness itself is never cached) |
| C-1 | Correctness | A future-dated `active` employment counted as current staff (ORG10-D01 pattern); a relationship to an inactive Guardian counted as coverage | — | **fixed** + tested |

An independent review of the candidate (separate reviewer, code only)
confirmed no HIGH or MEDIUM finding; its LOWs are L-1, L-2, L-4, L-5 and
L-6 above.

No HIGH or MEDIUM finding.

## 7. ORG.12B — Organization Setup & Readiness Center (implemented, 2026-10-28)

**7.1 Surface.** `GET /app/school-setup/readiness`
(`app.school-setup.readiness`, `OrganizationSetupController`), inside the
`auth` + `school-context` group, route middleware
`capability:school.profile.view`. Navigation: `setup-readiness`
("Setup & readiness", School setup group, same requirement). The School
setup page no longer computes its own five progress flags (it reads no
domain model for progress) and links to the Center instead.

**7.2 Entry authorization (decided: reuse).** `school.profile.view` stays the
floor. It is School administration state (ADR 0070 §9.6), held today by
School Admin and Principal only, never by a Campus grant of a School
configuration key, a platform role, an elevation or a Group grant. No
semantic problem was found, so no capability was added. Lifecycle: the
`school-context` middleware and the read service both refuse a School
that is not `active`.

**7.3 Presentation layer.** `App\Support\Setup\OrganizationSetupCenter`
derives everything per request from `OrganizationSetupReadService`; the
Vue page (`App/SchoolSetup/Readiness`) renders only what it receives and
holds no catalogue (`setupReadiness.ts` maps the closed status vocabulary to
words and icons).

**7.4 Progress semantics.** Two figures, never one mixed percentage:
- **Core setup** = configured core areas / applicable core areas.
- **Enabled modules** = configured enabled optional areas / enabled
  optional areas.
- Only `CONFIGURED` counts as done; `PARTIALLY_CONFIGURED`,
  `NOT_CONFIGURED`, `BLOCKED` and `ATTENTION_REQUIRED` do not;
  `NOT_APPLICABLE` is in neither denominator.
- Progress is computed over the areas the viewer can see; hidden areas are
  counted, never described.
- Overall state: getting started (no core area with its own checks is
  configured) / core in progress / core complete (enabled modules still
  incomplete) / all applicable areas configured. Never "ready".
- **Nothing is persisted:** no percentage, completion state, next action or
  category total is stored or cached.

**7.5 Action authorization.** Resolved server-side per area, separately
from readiness visibility:
- `open` (new registry field) is an any-of subset of the capabilities the
  destination page itself checks — pinned by
  `OrganizationSetupArchitectureTest` to the destination's StaffNavigation
  requirement (two reviewed exceptions: the profile page and Leave
  configuration). It may be stricter, never wider: Communications links on
  `communications.view` only, because the consumer-only
  `communications.inbox.view` stays named only by its recipient surfaces
  (ORG.9D-1R1 guard).
- **Configure** = holds an `open` capability AND every `manage` capability
  School-wide; **View** = holds an `open` capability only; **none** =
  neither (no link; "Managed in its own module by people with access").
- A NOT_APPLICABLE area never gets an action. The destination stays
  authoritative: a link is never authority, and the Center never reads
  `CampusScopeCatalogue` or the navigation builder at runtime. Whole-School
  evaluation is conservative: a Campus-only holder gets no link and keeps
  the module's own Campus-aware navigation.

**7.6 Next actions.** Deterministic, at most three, only areas the viewer can
Configure: (0) an incomplete area a visible BLOCKED/ATTENTION area waits on;
(1–3) core not configured / partial / attention; (4–6) the same for enabled
optional areas; ties by registry order. A BLOCKED area is never recommended
(its prerequisite is).

**7.7 Dependency privacy (ORG.12A L-2 closed).** The read service names a
blocker only when the viewer can see that area; any other blocker is
counted (`hiddenBlockers`) and shown as "A prerequisite setup area must be
completed first."

**7.8 Campus-read domains.** Student records, Staff records, Student
attendance and Admissions keep the ORG.12A floor-only rule (EXISTS facts,
`adoptedRead` guard). Their links follow `open` (the domain's own read
key), so a deep link still obeys the domain's authorization.

**7.9 Measured.** Full page 57 queries (51 readiness + 6 page), ~50 ms in
the isolated test stack, Setup Center payload ~11 KB (HTML ~21 KB);
constant in record volume; budget 70. No caching.

**7.10 Security review (ORG.12B).** See §7.11 for the independent review.
Checked: cross-School leakage, Platform and Group access, link
authorization bypass, disabled-module destinations, capability exposure in
the payload (none: no capability, route name or raw dependency list is
sent), dependency-name leakage (closed), sensitive values (planted values
absent from the rendered page), lifecycle bypass, menu-as-security,
direct-route access, frontend-only authorization (none: every action is
resolved on the server).

**7.11 Independent review (ORG.12B).** A separate reviewer read the commit
(code only): **no HIGH, no MEDIUM.** LOWs:

| # | Finding | Resolution |
|---|---|---|
| L-1 | A hidden prerequisite's state could be inferred from a visible blocked area plus the public dependency graph | **guarded:** `every_floor_holder_can_see_each_prerequisite_of_the_areas_it_sees` fails if any floor-holding role sees an area without seeing its prerequisites (true today for School Admin and Principal) |
| L-2 | "Not enabled" areas are listed to any floor holder, including modules whose areas the viewer could not otherwise see | accepted: module availability is School configuration (ORG.12A §9), never records |
| L-3 | Campus-adopted `open`/`manage` keys are evaluated School-wide | accepted, deliberate: link resolution is presentation, not authorization; errors are false negatives only (no link), never access (§7.5) |
| I-1 | The `open` pin follows the first navigation entry per route; two destinations are reviewed by hand | accepted: worst case an offered link answers 403 |

## 8. ORG.12C — Rich School & Campus Profiles (implemented, 2026-10-28)

**8.1 Owner decisions applied.** OD-12.1 (keep `name` as the display name;
`legal_name` already existed, optional, may equal it); OD-12.2 (neutral
institutional references only, no tax/statutory semantics); OD-12.3 (no
Campus timezone); OD-12.4 (no Campus currency); OD-12.5 (code-owned Campus
services, descriptive only); OD-12.6 (optional coordinates, no geocoder,
tracking or map provider); OD-12.7 (no theme system; logo deferred).

**8.2 Audit before design (existing fields).** School: `name`, `slug`,
`status`, `timezone` (default Asia/Kolkata), `default_locale`, `legal_name`,
`code`, `website`, `email`, `phone`, `address_line1/2`, `city`,
`state_region`, `postal_code`, `country_code` (char 2, default IN),
`education_board_id`, closure columns. Campus: `name`, `code`, `status`,
`phone`, `email`, free-text `address`. No address value object, country or
region catalogue, geographic master, School currency column (Finance is
INR-only, database-enforced: "School currency source: NONE"), logo or
School-media pipeline, or Campus timezone. Data (DDEV): 2 Schools, 2
Campuses, no Campus address/phone/email — no backfill needed.

**8.3 Schema (three migrations, rollback-proven).**
- `2027_01_12_090000_add_school_institutional_references`: `schools.registration_reference`
  varchar(100), `affiliation_reference` varchar(100), nullable.
- `2027_01_12_090100_add_structured_campus_profile`: Campus `address_line1/2`,
  `city`, `state_region`, `postal_code`, `country_code` (nullable, CHECK
  `^[A-Z]{2}$`), `latitude numeric(7,5)`, `longitude numeric(8,5)` with
  `campuses_coordinates_pair_check` and `campuses_coordinates_range_check`;
  existing `address` moved to `address_line1` (refused, not truncated, if
  longer than 255) and dropped. `down()` rebuilds `address` from the parts.
- `2027_01_12_090200_create_campus_services_table`: `campus_services`
  (`school_id`, `campus_id`, `service_code`, `created_by_user_id`,
  `created_at`), composite FK to `campuses(id, school_id)`, unique
  `(campus_id, service_code)`, CHECK of the five codes, forced RLS,
  runtime UPDATE revoked; retention class `academic_structure` (tenant
  lifetime); `created_by_user_id` classified `retain_reference_to_tombstone`.

**8.4 School profile.** `SchoolProfileService` (web + API) writes email,
phone (E.164 when changed — legacy values never block an edit), website
(http/https only), the structured address, `country_code` (two letters,
required) and the board, under whole-School `school.profile.manage`; never
`status`, timezone or the identity. Audit `school.profile_updated` names the
changed fields only (it used to copy the submitted values).

**8.5 Institutional identity (direct, assured).** `SchoolIdentityService`
is the only writer of `legal_name`, `registration_reference` and
`affiliation_reference` (none is mass assignable). Route
`PUT /app/school-setup/profile/identity` (`capability:school.profile.manage`)
→ fresh MFA (`FreshMfaRequirement`, outside the transaction) → School row
`FOR UPDATE` → `authority->requireCurrent('school.profile.manage')`
(operational School) → stale-form check (the values the form opened with
must equal the stored ones) → write → one `school.institutional_identity_changed`
event (changed fields, closed `SchoolIdentityChangeReason`, MFA time; never
values). Declared in `ApprovalWorkflowRegistry::DIRECT_ASSURED`. The v1 API
refuses these fields (422). The audit ledger is the history (no separate
legal-name history table).

**8.6 Campus profile.** `CampusAdministrationService` (one writer, web +
API): structured address, contacts, coordinates (both or neither), and the
full service set (insert/delete diff, idempotent, `campus.services_changed`
audit of added/removed codes). `school.campuses.manage` stays whole-School
(not Campus-adopted). New page `GET /app/school-setup/campuses/{campus}`
(`school.campuses.view`); the list shows name, code, city, status and
services in one eager query.

**8.7 Separation.** A Campus service never enables a module, grants a
capability or creates Transport/Hostel/Canteen/Finance records; module
entitlement code never reads services (`OrganizationProfileArchitectureTest`).
No School Group, region, zone or franchise change (ORG.12D).

**8.8 Not implemented, deliberately.** GST, PAN, VAT, EIN, any tax filing or
statutory field (RF-22 stays `LEGAL_DECISION_REQUIRED`); Campus timezone or
currency; a street/area/locality master; a geocoder; a provider map URL; a
logo upload (no media pipeline — ORG.12F); a generic metadata, custom-field
or `type/value` identifier system; Group display on the profile.

**8.9 Setup Center.** School profile readiness = an institutional contact
(email or phone) + address fundamentals (first line, city, country). The
legal name is no longer required (OD-12.1); website, references, logo,
coordinates and services never are. Campus readiness is unchanged (an
active Campus).

**8.10 Measured.** School profile view 8 queries, save 8; Campus list at
50 Campuses 8 (constant, one eager service query); Campus detail 8, save
14; Setup Center page 49. No caching.

**8.11 Independent security review (ORG.12C).** No HIGH. One MEDIUM, fixed:

| # | Finding | Resolution |
|---|---|---|
| M1 | The Campus address backfill runs over a FORCE-RLS table; on a migration role without RLS bypass it would copy nothing and then drop `address` | **fixed:** `up()` and `down()` assert `rolsuper OR rolbypassrls` first (the ORG.7G-1A pattern); guarded by a test |
| L1 | A case variant of another School's code passed validation and hit the unique index (500); the web form also accepted name/code | **fixed:** code normalized before the check, violation becomes a 422; the web form edits contact/address/board only (the API keeps name/code) |
| L2 | An API PATCH of one coordinate could break the pair CHECK (500) | **fixed:** the pair is checked on the merged state (422) |
| L3 | The Campus audit "before" could come from a pre-lock copy | **fixed:** the locked row is changed and audited |
| L4 | "Only SchoolIdentityService writes the identity" was convention | **fixed:** a source guard refuses force-writes of identity fields elsewhere |
| L5 | The identity step-up route has no per-user attempt throttle | accepted: the same project-wide gap as every FreshMfaRequirement web route (TOTP replay is already refused); a shared limiter is a separate hardening item |
| L6 | Legacy website/email could block unrelated saves; padded legacy identity could never pass the stale check; the page reads Finance's currency constant | **fixed** (unchanged values are not re-validated; stored values are trimmed for the check); the currency read is accepted (HTTP layer only) |
| -- | A School code existence oracle through the platform-wide unique code | pre-existing (the API already allowed code edits); not widened |

## 9. ORG.12D — Network, Franchise, Region & Zone Governance (implemented, 2026-10-29)

**9.1 Owner decisions applied.**
- **OD-12.8:** the existing School Group is the canonical Network. There is
  no Network, Organization, Franchise or tenant entity.
- **OD-12.9:** the relationship is classified on the membership. It is one
  of owned, franchise, managed or affiliate, and is descriptive only.
- **OD-12.10:** a membership has an optional Group → Region → Zone
  placement. Regions and Zones are owned by the Group. They are never
  tenants, authorization scopes or a hierarchy engine.
- **OD-12.11:** a safe directory with structural counts. This amends ADR
  0045 §12; see the ADR 0045 ORG.12D amendment.
- **OD-12.12:** sensitive network changes are direct and assured. They are
  not an approval workflow.

**9.2 Audit before design.**
- **Tables:** `school_groups` (name, slug, status, archived_at),
  `school_group_members` (id, Group, School, timestamps, unique pair) and
  `group_role_assignments`. There was no Region, Zone, relationship,
  network code or Network table.
- **Cardinality:** many-to-many (ADR 0045 §7). A School may belong to
  several Groups.
- **Who writes:** only the platform writes the membership, through
  `SchoolGroupGovernanceService` under `platform.school_groups.manage`.
  Only `platform_super_admin` holds that capability (ADR 0045 §5,
  invariant 6).
- **What a Group Admin may do:** view, elevate, and read the one Group-safe
  report.
- **Data:** DDEV has 1 Group and 2 memberships, with nothing to classify.
- **Not reused:** `campus_services` (ORG.12C) is a tenant table and is
  never read by Group code.

**9.3 Schema** (one migration,
`2027_01_13_090000_add_school_group_structure_and_relationships`,
rollback-proven).
- **New tables `school_group_regions` and `school_group_zones`:**
  - columns: `school_group_id`, `code` (upper-case,
    `^[A-Z0-9][A-Z0-9_-]{0,31}$`, unique per Group), `name` (not blank),
    `description`, `sort_order`, `status` (active/inactive);
  - unique `(id, school_group_id)`;
  - a Zone's optional `region_id` uses a composite FK to the regions'
    `(id, school_group_id)`, so it stays inside the Group;
  - runtime `DELETE` is revoked;
  - no `school_id`, no RLS, no `parent_id` (these are platform tables).
- **New columns on `school_group_members`:**
  - `relationship_type`, nullable, CHECKed against the four codes;
  - `region_id` and `zone_id`, each with a composite FK to the same
    Group's row (RESTRICT);
  - `network_reference`, upper-case, `^[A-Z0-9][A-Z0-9._/-]{0,39}$`, with a
    partial unique index per Group.
- **Triggers:**
  - `trg_school_group_members_placement`: a member's Region must be its
    Zone's Region;
  - `trg_school_group_zones_region_move`: a Zone that has Schools cannot
    change Region.
- **Backfill:** none. Legacy memberships stay `NULL` (unclassified), never
  guessed.

**9.4 Assurance** (`SchoolGroupGovernanceService`; the
`PlatformRoleGovernanceService` precedent, not
`ApprovalWorkflowRegistry`).

| Operation | Capability (cached floor) | Fresh re-read in the transaction | Fresh MFA | Closed reason | Stale check | Audit event |
|---|---|---|---|---|---|---|
| Add a School (must be classified) | `platform.school_groups.manage` | `holdsPlatformNow` | yes | yes | — (the unique pair) | `school_added` + relationship, reason, MFA time |
| Remove a School | same | yes | yes | yes | — | `school_removed` + reason, MFA time |
| Change the relationship or network reference | same | yes (member row `FOR UPDATE`) | yes | yes | the values the form opened with | `membership_classified` (before/after relationship, reason, MFA time; never the reference value) |
| Move Region/Zone | same | yes (row `FOR UPDATE`) | no | yes | yes | `membership_placed` (region/zone ids before/after, reason) |
| Region/Zone create/update/inactivate | same | — | no | — | — | `region_*` / `zone_*` (ids, codes, status; never names) |

- **`CapabilityResolver::holdsPlatformNow()`** share-locks the actor's
  unrevoked platform grants. A bearer token or a disabled User is always
  refused.
- **Order of checks:** capability, then fresh MFA (web), then the
  transaction (fresh authority, lock, stale check, write, audit).
- **Codes:** the frozen-code rule from ADR 0045 §11 applies. A
  Region/Zone code is fixed once a Zone or School references it.
- **Inactivating** a Region or Zone moves no School. The overview reports
  any School in inactive structure. New placements into inactive structure
  are refused.

**9.5 The directory** (`SchoolGroupDirectoryReadService`).
- **Two surfaces:**
  - Group Admin: `GET /app/groups/{group}`, under `group.schools.view`;
  - platform: `GET /app/platform/groups/{group}`, under
    `platform.school_groups.view`.
- **What it reads:** only `school_groups`, `school_group_members`,
  `school_group_regions`, `school_group_zones` and `schools`.
- **What each row shows:** id, name, code, lifecycle status, relationship,
  network reference, Region and Zone.
- **Navigation:** 25 rows per page; filters for relationship (including
  unclassified), Region, Zone and lifecycle; an escaped search on name or
  code only.
- **Overview:** member Schools by lifecycle and by relationship, Regions
  and Zones with their member counts, and attention items (unclassified,
  inactive structure, School not active). These are **counts of member
  Schools**, never of anything inside a School.
- **Never:** a TenantContext, a cache, or a Student, Staff, Guardian,
  Finance, attendance or Campus read.
- **Deferred:** Campus count and setup readiness on Group pages read
  tenant tables. They are deferred to an ADR 0048 amendment (ORG.12H).

**9.6 No escalation.** No relationship type grants anything. A Group Admin
gets no School capability, membership, lifecycle change, entitlement or
profile access. A person holding School A authority and a Group grant
still cannot select School B. The Group capability catalogue is unchanged
(three keys) and no capability was added. The platform side stays
`platform_super_admin` for every write. The Platform Manager and Auditor
read the platform Group pages (`platform.school_groups.view`) and change
nothing. Moving management to the Group side (a Network operator) is an
**owner decision for ORG.12E**, not built.

**9.7 Not implemented, deliberately.**
- No Region or Zone Manager, and no zonal or region-scoped authority.
- No recursive hierarchy, no user types and no franchise-scoped roles.
- No revenue share, royalty, billing or commercial terms.
- No cross-School business reporting and no Group impersonation.
- No Group page reads tenant data.
- No automatic School membership.
- No API route.

**9.8 Measured** (no caching):
- Group Admin page: **12 queries** at 1, 10 and 100 member Schools.
- Platform Group page: **12 queries** at 1, 10 and 100 member Schools.
- `NetworkGovernanceTest` asserts that both counts stay constant.
- Each page runs one aggregate overview query, two structure queries and
  one paginated directory query with its count.

**9.9 Independent security review (ORG.12D).** No HIGH or MEDIUM. Five
LOW, all fixed and covered by tests:

| # | Finding | Resolution |
|---|---|---|
| L1 | A duplicate School was reported as a duplicate network reference: the unique violation was matched on the exception message, which contains the whole INSERT | matched on the constraint name |
| L2 | Array-shaped query filters (`?q[]=`) caused a 500 on the directory | non-string filters are ignored (controllers and the read service) |
| L3 | Upper-case UUIDs gave a false "stale" refusal or a no-op audited move | ids are lower-cased before comparing |
| L4 | A concurrent archive could slip between the active-Group check and a write | every write re-checks the Group `FOR SHARE` inside its transaction (`requireActiveLocked`) |
| L5 | `holdsPlatformNow`'s disabled-User read was unlocked | now share-locked |

Also verified by the review:
- every write needs `platform.school_groups.manage`, checked before an MFA
  code is spent;
- no IDOR: every Region, Zone and School is scoped to the route's Group,
  backed by the composite FKs and the trigger;
- the directory search pattern is escaped;
- no tenant read, and no names or reference values in audit;
- no relationship, Region or Zone is ever read for authorization.

## 10. ORG.12E — Role, Access & Sensitive Data Administration (implemented, 2026-10-29)

**10.1 Owner decisions applied.**
- **OD-12.13:** Network-side management is authorized through a new
  code-owned `network_manager` Group role.
  - The platform appoints and removes it; a Network Manager never appoints
    another.
  - `group_admin` is unchanged.
  - It gets no School capability.
- **OD-12.14:** a Region or Zone may scope a READ-only Network grant
  (`network_viewer`). Neither ever becomes a School scope.
- **OD-12.15:** School sensitive-data policy may only be deny-only. It is
  NOT enabled in this phase (§10.8): the catalogue and explorer ship, and
  the policy store does not.

**10.2 Audit before design.**
- **School side:**
  - 17 code-owned staff role templates (ADR 0071), with capabilities
    classified (`CapabilityClasses`) and mapped to modules
    (`ModuleCatalogue`);
  - each assignment is whole School or one Campus (ORG.7A);
  - `CapabilityResolver` unions every assignment's capabilities and keeps
    no per-role path;
  - `school.roles.view` / `.manage` and the staff-account flows already
    govern assignments (RoleGrantAuthority, fresh MFA, audit).
- **Group side:**
  - one grant table (`group_role_assignments`): one active grant per
    person, Group and role; immutable once revoked; no self-grant; no
    expiry;
  - one Group role (`group_admin`), granted only by the platform;
  - no grantor rule in the database.
- **Sensitive fields:** the real families are those in §10.8. No government
  ID, immigration, medical or bank-account data is stored.

**10.3 Network roles and scope** (migration
`2027_01_14_090000_add_network_scope_to_group_role_assignments`,
rollback-proven).
- **Capabilities:** `group.network.view`, `group.network.manage`,
  `group.network.operators.manage`.
- **Roles:**
  - `network_manager`: view + manage + operators.manage. No elevation,
    no Group reporting.
  - `network_viewer`: view only.
- **Scope columns:** `group_role_assignments.region_id` / `zone_id`, with
  composite keys to the same Group's Region and Zone and at most one set.
  Both NULL means the whole Group, which every existing grant keeps.
- **Trigger `trg_group_role_assignments_network`:**
  - a scoped grant only for a role whose every capability is
    `group.network.view` (decided by capability);
  - a role carrying Network management is granted only by a holder of
    `platform.school_group_grants.manage`;
  - a read-only role is granted by that holder or by a whole-Group
    `group.network.operators.manage` holder of the same Group;
  - the scope never changes (re-scoping is revoke + grant).
- **Trust boundary:** the trigger checks the RECORDED grantor
  (`granted_by_user_id`). Which human actually acted stays an application
  boundary (rule 102, ORG.8F), as for every grant table. Revocation and
  `group_admin` grants have no database grantor rule (pre-existing).
  Rolling the migration back refuses while any scoped grant is active,
  rather than silently widening it to the whole Group.
- **Visibility (`GroupGovernanceScope`):**
  - whole Group: every membership;
  - Region: members placed in the Region directly or through its Zones;
  - Zone: that exact Zone;
  - unplaced memberships: whole Group only.
  The directory, overview counts and structure all follow the scope. A
  filter never widens it.
- **Resolver:** `GroupGovernanceAccess` is the bounded resolver: uncached,
  no School capability, no tenant read, and the only Group-layer user of
  the scope. `CapabilityResolver` is unchanged.

**10.4 Network Manager.**
- **May, for its own Group only:** create and update Regions and Zones;
  classify (fresh MFA); place; remove a School (fresh MFA). Every change
  re-reads authority in its transaction, and audits record
  `authority: platform|network`.
- **May not:**
  - add a School: that extends the Group's elevation reach into another
    tenant (ADR 0045 §5), so it stays platform-only;
  - enter a School, change lifecycle, entitlements, profile or Campus;
  - read any School record;
  - appoint a manager.

**10.5 Network role administration (`NetworkAccessService`).** Grant and
revoke take a closed `NetworkRoleChangeReason` and a fresh MFA proof.
Authority is re-read in the transaction and never applies to oneself.
Audit carries ids and codes only (`role_key`, `scope_type`, Region/Zone
id, reason, MFA time, authority). Platform Group Admin grants keep their
ADR 0045 path.

**10.6 Surfaces.**
- **Group context:**
  - `/app/groups/{group}`: a scoped directory, plus the governance
    workspace for managers;
  - `/app/groups/{group}/access`: Network roles, the viewer's own access,
    and the assignments list, shown only to operator managers;
  - `/app/groups/{group}/network/*`.
- **Platform:** role and scope choice when granting.
- **School context:** the Access Center (`/app/settings/access`, §10.7).
- **Navigation:** "Your School Groups" now also shows for Network roles.

**10.7 School Access Center (read-only).**
- **Catalogue** (`school.members.view` or `school.roles.view`):
  - role templates (purpose, modules, capability count, assignment scope,
    access-administration marker, sensitivity);
  - capabilities by module with Read/Manage, Sensitive, Approval, Fresh MFA
    and Campus-scopable markers;
  - the role × module matrix (Read/Manage/none, never menu entries);
  - the sensitive-data catalogue.
- **Users & effective access** (`school.roles.view`):
  - this School's staff, paginated and searchable;
  - per person, `CapabilityResolver`'s real answer, explained per
    capability: where it comes from (role, whole School or Campus) or every
    layer that stops it (module not enabled, Campus not adopted, Campus
    inactive, account disabled, access suspended).
- **Boundaries:**
  - each School is computed separately;
  - no Group or platform authority is shown;
  - nothing is persisted;
  - no write route: assignments stay on Staff accounts.

**10.8 Sensitive data: catalogue shipped, deny-only policy deferred.**
`SensitiveDataCatalogue` lists the real families: Guardian contact,
employee personal contact, home address, emergency contacts, date of birth,
statutory identifiers, highly sensitive HR documents and visitor phone.
Each has its gating capability and `configurable: false` with the reason.

The policy is not implementable safely yet, for these reasons (stop
conditions 13–15):
1. No family has one projection: Guardian contact and date of birth are
   built per controller, and the HR families have write echoes outside the
   workspace.
2. `CapabilityResolver` keeps no per-role path, so "deny for role R" needs
   a new resolution output.
3. Statutory identifiers are legally gated.

Turning a family on needs:
- a central projection;
- a per-role evaluation (any non-denied qualifying path permits);
- an owner decision on whether view-denial also covers edit.

**10.9 Not implemented, deliberately.**
- Tenant or Network role creation, capability editing, menu customization.
- A Region or Zone Manager with write authority; zone-scoped School
  authority.
- Per-User field overrides; Campus-level sensitive policy; an access
  simulator; new exports.
- No change to the shared fresh-MFA throttling LOW (still recorded as
  shared).

**10.10 Measured** (no new cache):
- **Effective access:**
  - simple whole-School user: 30 queries (the first request also warms
    the resolver's existing 60 s grant cache);
  - multi-role user: 22;
  - multi-Campus user: 29;
  - Group + School user: 22.
  No query per capability (the catalogue is read once).
- **Access Center catalogue:** 10 queries.
- **Group page:** 18 queries at 1, 10 and 100 Schools (ORG.12D's 12 plus
  the scope and management checks).
- **Platform Group page:** 13 at every size.
- `NetworkGovernanceTest` and `AccessCenterTest` assert these bounds.

**10.11 Independent security review (ORG.12E).** No HIGH or MEDIUM.

| # | Finding | Resolution |
|---|---|---|
| L1 | The Network Access list returned Group Admin holders too, with their raw email | **fixed:** Network-role grants only, address through `publicEmail()` |
| L2 | A Network Manager can test whether an email exists on the platform (exact match) | accepted: each probe costs a fresh MFA code (TOTP replay refused); the message for a missing or disabled account is the same |
| L3 | The legacy Group Admin grant/revoke accepted any Group role | **fixed:** they refuse Network roles, which keep their assured path |
| L4 | Rolling the migration back would silently widen scoped viewers | **fixed:** `down()` refuses while an active scoped grant exists (tested) |
| L5 | The trigger trusts the recorded grantor | documented (§10.3 trust boundary, rule 102); revoke and `group_admin` grants were never trigger-checked |
| -- | Effective access could show a non-staff membership by its UUID | **fixed:** staff memberships only (404 otherwise) |
| -- | The shared fresh-MFA web-route throttling LOW | unchanged, still recorded as shared (not fixed per route) |
