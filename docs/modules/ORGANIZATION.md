# School OS — Organization Module (School, Campus)

Phase 0D. This document covers the two foundational organizational
entities extended (not newly created) in this checkpoint: **School**
and **Campus**. Both existed since Phase 0B (`docs/architecture/DOMAIN-MAP.md`
Layer 1); this checkpoint adds genuine administration around them, not
a parallel abstraction.

## School operational configuration vs. platform tenant lifecycle

`schools.status` (Phase 0B: `active`/`suspended`/`archived`) is the
**platform tenant-lifecycle** column — it decides whether the tenant
itself is allowed to operate at all, and is a platform-administration
concern, not something a School's own staff configure. Phase 0D adds a
**separate, deliberately non-overlapping** set of organizational
profile fields to the same `schools` table:

```
legal_name, code, website, email, phone,
address_line1, address_line2, city, state_region, postal_code,
country_code, education_board_id
```

`App\Http\Controllers\Api\V1\SchoolProfileController` and
`App\Http\Controllers\App\SchoolSetupController` both validate an
explicit field whitelist that **excludes `status`** — a request that
includes `status` in its payload has it silently ignored (Laravel's
default `validate()` behavior for a field not in the rules array),
never applied. There is no code path reachable through the
`school.profile.manage` capability that can change `status`; that
remains an explicit, separate, platform-administered action (not built
in this checkpoint — no UI or API exists yet for platform staff to
change a School's lifecycle state beyond what Phase 0B already
established).

**Phase 0N.8/0N.9 (ADR 0047, built in 0N.9):** the lifecycle is
`provisioning → active → suspended → active` on this same column (a new
`provisioning` value for a created, never-activated School; `archived`
kept with no application transition until a retention/legal decision).
CREATE, ACTIVATE, SUSPEND and RESUME are platform actions under
`platform.schools.manage` with fresh MFA; the profile fields above stay
School-owned and are not part of creation beyond `name`, `slug` and an
optional `code`. The `status` exclusion from every profile whitelist is
unchanged.

`name` (Phase 0B) continues to serve as the School's **display name**;
`legal_name` was added as the distinct formal/registered name, rather
than introducing a redundant `display_name` column duplicating `name`.

## Government identifiers — deliberately deferred

Section 7 of this checkpoint's brief explicitly named government
identifiers (UDISE, board-registration numbers, etc.) as needing a
careful, separate design once actually required — collection/masking/
storage of such identifiers is flagged
**[LEGAL REVIEW REQUIRED]** in `docs/security/DATA-CLASSIFICATION.md`
("Government/statutory identifiers"). None are modeled in this
checkpoint.

## Campus

`App\Models\Campus` (Phase 0B) gained `phone`, `email`, `address`
columns and a full CRUD API/UI. Campus remains a **sub-tenant
dimension, not a separate isolation boundary** (ADR 0004) — every
Campus row is still RLS-protected exactly like any other tenant-owned
table, and `campuses.code` is unique **within a School**, never
globally (`unique(school_id, code)`), proven by
`AcademicStructureCrossRelationTest::same_campus_grade_and_subject_codes_are_allowed_across_different_schools`.

Codes are normalized to uppercase on write (`App\Support\NormalizesCode`,
applied to `Campus`, `School`, and every Academic Structure entity with
a `code` column) so `"main"`/`"MAIN"`/`"Main"` are treated as the same
code for uniqueness purposes (section 65) — this project deliberately
does not add the PostgreSQL `citext` extension for this; a single
shared mutator trait was judged sufficient. Controllers additionally
normalize inbound `code` values before running a `Rule::unique()`
check (`App\Support\NormalizesCodeInput`), so a duplicate submission
returns a clean `422` validation error rather than a raw database
constraint-violation exception.

**Campus is the canonical Branch (ADR 0076 §10, ORG.1).** There is no
Branch entity; "Branch" may be a UI label for Campus. Campus is data, not
authority: no School grant is Campus-scoped today. ADR 0076 §11 contracts
an optional Campus scope on School role assignments for ORG.7, where NULL
keeps meaning the whole School. No new "Organisation" entity sits above
School (ADR 0076 §4). Platform onboarding, lifecycle and module
entitlements are platform-governed (ADR 0076 §6–§7, §12); School profile
fields stay School-owned.

## Multi-campus defaults

See `docs/modules/ACADEMIC-STRUCTURE.md` ("Multi-campus rules") for the
full statement of which entities are School-wide vs. Campus-scoped —
Campus itself is the scoping boundary several Academic Structure
entities (Room, Section, Subject Offering) reference via a composite
foreign key back to `campuses(id, school_id)`, guaranteeing a School A
entity can never reference a School B Campus at the database level.

## Platform School Registry (ORG.3, ADR 0076 §9.2, §29)

The platform's read view of every School (`/app/platform/schools`, under
`platform.schools.view`) is a control plane, never a School data browser.
**Platform Registry metadata is not tenant business data.**

| Shown | Source | How it is read |
| --- | --- | --- |
| identity (name, legal name, slug, code, time zone, locale), lifecycle and closure | `schools` (platform table) | directly; search, filters and keyset pagination run here |
| custom-domain state (never hostnames) | `school_domains` (platform table) | batched for the page |
| School Group membership | `school_group_members` (platform table) | batched for the page |
| Campus counts (and, on the overview, Campus names/codes) | Campuses (FORCE RLS) | `CampusStructureSummary`, inside that one School's TenantContext |
| active academic-year name | Academic Structure (FORCE RLS) | `CurrentAcademicYearResolver::tryResolve()`, one School at a time |
| administrator readiness (yes / not activated / missing) | Identity (role grants, FORCE RLS) | `SchoolAdministrators::readiness()`, the one definition |
| readiness and warnings | derived | `SchoolReadiness`, computed when read, never stored, advisory |
| recent platform governance events | `platform_audit_events` | only through `PlatformAuditLogReviewService::reviewSchool()` (`platform.audit.view` + current MFA), envelope only |

Never shown: students, guardians, staff HR, payroll, fees, ledger,
payments, marks, attendance, documents or `school_audit_events`. Campus
appears as structure only; it is never authority (ADR 0076 §10-§11).

**Who reads it (ORG.4, ADR 0076 §30):**
- the Platform Admin (root) and the Platform Auditor read the Registry;
  since ORG.6 (ADR 0076 §32) the Auditor also reads each School's module
  entitlement state there, read-only;
- the Platform Manager reads it and onboards Schools: create into
  `provisioning`, then set the bootstrap administrator while provisioning;
- activation and every later lifecycle change stay with the Platform Admin;
- no School role ever reaches it.

## School module entitlements (ORG.5, ADR 0076 §12, §31)

Which product modules a School may use is platform configuration, never a
School setting, a role, a navigation toggle or a commercial plan.

- **Core modules** are always available and are never stored or
  disableable: Identity & Access, Organization (this module), Academic
  Structure, Student Records (Students and Guardians, one family), Staff
  Records, Compliance & Audit, and Documents.
- **Optional modules** (22) are enabled or disabled per School by the
  Platform Admin or Platform Manager on the School overview (*Modules*),
  with a reason, confirmation and a fresh MFA code.
  - Dependencies are enforced, and nothing cascades.
  - A closed School cannot be changed.
- **Storage.** `school_module_entitlements` holds one row per (School,
  optional module).
  - It is under FORCE RLS; the runtime role may not DELETE.
  - A missing row means disabled.
  - Existing Schools were backfilled with every optional module enabled.
    A School onboarded after ORG.5 starts core-only.
- **Activation and readiness** never require an optional module.
- **Disabling** hides and refuses the module everywhere: navigation, web,
  API, services and background work. It deletes nothing, and enabling
  again restores access with the same roles.
- **School administrators** see only the effect; they have no entitlement
  control. The Platform Auditor sees the state read-only (ORG.6).

## Campus-scoped staff access (ORG.7A, ADR 0076 §11, §33)

- **Scope on grants.** A School role may be granted for the entire School
  (the meaning of every grant before ORG.7A) or for one active Campus.
  Several Campuses are several grants.
- **Effect.** A Campus grant works only in product areas that have adopted
  Campus scope: today Visitor visits and Inventory stock. Its other
  permissions stay unavailable from that grant until their area adopts
  Campus scope.
- **Only whole-School administrators grant.** Staff access, School
  settings and configuration are never Campus-scoped, and the School
  administrator role is never Campus-scoped.
- **Inactivating a Campus** deletes and revokes nothing. Its scoped grants
  confer no work until it is reactivated, and new grants cannot target it.
- **ORG.7B (ADR 0076 §34)** reviewed HR, Admissions, Communications, Fees,
  Payments and Canteen and deferred them. Each needs an owner decision, or
  Student-Campus semantics from the SIS wave, before a Campus grant can
  work there.
