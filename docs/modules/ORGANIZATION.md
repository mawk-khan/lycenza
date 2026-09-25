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

## Multi-campus defaults

See `docs/modules/ACADEMIC-STRUCTURE.md` ("Multi-campus rules") for the
full statement of which entities are School-wide vs. Campus-scoped —
Campus itself is the scoping boundary several Academic Structure
entities (Room, Section, Subject Offering) reference via a composite
foreign key back to `campuses(id, school_id)`, guaranteeing a School A
entity can never reference a School B Campus at the database level.
