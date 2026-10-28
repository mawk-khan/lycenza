# ADR 0080: Organization Control Plane — Setup Registry and Derived Readiness (ORG.12A)

- Status: **Accepted and implemented (2026-10-28).** ORG.12A. Later ORG.12
  checkpoints (B–H) are planned here but **not authorized**; each needs
  explicit owner authorization.
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
9. **No caching** (measured: 51 queries, ~31 ms for the full catalogue).
10. **Readiness logic stays code-owned:** no JSON predicates, stored SQL,
    expression language, workflow DSL or School-editable rule.

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
| L-1 | Floor-only areas | Student/Staff records, Student attendance, Admissions readiness visible to floor holders (adopted read keys cannot be checked School-wide) | LOW | accepted: EXISTS facts only, floor = School Admin/Principal |
| L-2 | Blocker keys | A blocker may name an area the viewer cannot see | LOW | accepted: keys are code constants, no tenant data |
| L-3 | Evaluation breadth | All applicable areas are evaluated even if the viewer sees few (needed for dependency status) | LOW | accepted: bounded (51 queries) |

No HIGH or MEDIUM finding.
