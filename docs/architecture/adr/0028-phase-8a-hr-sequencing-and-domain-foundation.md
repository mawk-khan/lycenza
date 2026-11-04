# ADR 0028: Phase 8A HR Resequencing and Employee/Employment/Assignment Domain Foundation

- Status: Accepted
- Date: 2026-08-23 (Phase 8A.0)
- Number note: until 2026-09-23 a second, unrelated ADR (searchable
  encrypted PII, Phase 1A.3) also carried number 0028; it is now
  ADR 0041. References to "ADR 0028" about HR resequencing, Employee/
  Employment/Assignment, or the `employee_documents` reconciliation
  obligation mean this ADR.

## Context

`docs/roadmap/MASTER-ROADMAP.md` sequences the HR domain as **"Phase
0J — HR and Payroll,"** documented as coming after Phase 0E (Documents/
Communications), 0F (Students/SIS/Guardians/Admissions), 0G (Finance/
Fees), 0H (Academic Operations), and 0I (LMS). None of those five
phases have been started.

A separate initiative brief requested starting HR now, under an
external "Phase 8A" label, on a dedicated branch
(`feature/phase-8a-hr-employee-records`), with checkpoints 8A.0–8A.16.
This is a genuine deviation from the documented roadmap order, and root
`CLAUDE.md` rule 15 requires that deviation be written down rather than
silently drifted into.

Separately, the brief requires locking in a foundational domain
decision before any HR schema is written: whether "Employee" can be a
single flat record (`employees.joined_at`/`left_at`) or needs to be
split into Employee / Employment / Assignment layers to support rehire,
multi-campus/multi-position staff, and historical integrity.

## Decision

### 1. Resequencing is accepted, labeled explicitly, and bounded

Phase 8A proceeds now, on `feature/phase-8a-hr-employee-records`
(branched from `main` at `6edbe2d`, Phase 0D), under its own "8A.x"
checkpoint numbering, entirely separate from — and not a rename of —
the roadmap's internal `0A`–`0O` sequence. `docs/roadmap/MASTER-ROADMAP.md`
gets a short annotation under its existing Phase 0J entry pointing here
and to `docs/modules/HR.md`, rather than being restructured to insert
HR earlier in the `0`-prefixed sequence.

This is judged safe because `docs/architecture/DOMAIN-MAP.md`'s
dependency table already scopes HR's real technical dependencies
narrowly — **Schools and Identity & Access only**, both complete since
Phase 0B/0D — and several Layer 3 modules already list HR as *their*
dependency (HR sits below them in the dependency graph regardless of
which calendar phase built it first).

**Scope boundary**: Phase 8A covers Employee/Employment/Assignment
records only — the "HR" half of the roadmap's "HR and Payroll" phase.
Payroll (salary structures, payroll runs, PF/ESI/TDS statutory
compliance, all flagged **[LEGAL REVIEW REQUIRED]** in
`docs/security/DATA-CLASSIFICATION.md`) remains out of scope for every
Phase 8A checkpoint and is not renamed or resequenced by this ADR.

**Accepted cost**: Phase 0E (Documents) does not exist yet, so Phase
8A.7 (Employee Documents) cannot reuse a general Documents module. It
instead builds a narrow, HR-scoped `employee_documents` metadata table
on top of `App\Support\Tenancy\TenantStoragePath` (which already exists
specifically for this kind of future need, with zero callers before
Phase 8A). When Phase 0E is eventually built, it will need its own
reconciliation step to decide whether `employee_documents` gets
absorbed into a general `documents` table — deliberately not decided
by this ADR, to avoid speculatively designing Phase 0E's schema from
within Phase 8A.

### 2. Employee / Employment / Assignment is a three-table split, not a flat record

```
Employee          — the personnel identity, one row per real person, ever
  EmploymentRecord — one row per legal engagement span (rehire = second row)
    EmployeeAssignment — one row per (Campus, Department, Position, manager, date range) within one Employment
```

Rejected: `employees.joined_at`/`employees.left_at` as the only
temporal model. It cannot represent a second employment span after a
gap (rehire) or a mid-employment position/department/campus change
without either overwriting history or requiring a later migration to
retrofit exactly this split — the same class of mistake `Section`
already avoided in Academic Structure (Phase 0D) by never mutating or
reusing a prior year's row.

`EmployeeAssignment.manager_assignment_id` (self-referencing, nullable)
is the chosen reporting-hierarchy mechanism, not
`manager_employee_id` — an Employee may hold several concurrent
Assignments (e.g. teaching duties and a coordinator duties), each
needing its own manager. Pointing at the manager's specific Assignment,
not the manager's Employee record, is what makes "who does this
capacity report to" answerable per-Assignment rather than forcing one
manager per person.

Full entity model, field-level detail, capability names, privacy tiers,
and the checkpoint-by-checkpoint roadmap are recorded in
`docs/modules/HR.md` (this ADR is the decision record; that document is
the living operational reference, matching the `ACADEMIC-STRUCTURE.md`/
`ORGANIZATION.md` precedent).

## Rationale

- **Resequencing over waiting**: waiting for Phases 0E/0F/0G/0H/0I to
  complete first would block real product need with no corresponding
  technical requirement — `DOMAIN-MAP.md`'s own dependency table proves
  HR doesn't need any of them. Blocking on documentation order alone,
  when the actual dependency graph permits earlier work, would be
  process for its own sake.
- **Not renaming to the `0`-prefixed scheme**: the "Phase 8A" branch and
  checkpoint numbering already exists in the originating brief and in
  the branch name; renaming would create churn (branch rename, checkpoint
  renumbering) with no architectural benefit — the roadmap file
  annotation achieves the actual goal (no silent drift, rule 15) without
  that cost.
- **Employee/Employment/Assignment split over a flat record**: the cost
  of the extra tables (a few more joins for the common "current
  Employee summary" read) is small and paid once; the cost of *not*
  having them is a breaking schema migration the first time a real
  School rehires someone or reassigns a teacher mid-year — a
  near-certain event for any real school, not a hypothetical edge case,
  so this isn't the "no speculative frameworks" rule 2 situation (that
  rule targets infrastructure without a real, current need; rehire and
  multi-assignment staff are both explicitly named, present
  requirements in the originating brief, not imagined future ones).
- **`manager_assignment_id` over `manager_employee_id`**: rejected the
  simpler `manager_employee_id` specifically because the brief's own
  worked example (teaching duties vs. coordinator duties, two different
  managers) cannot be represented by a single per-Employee manager
  pointer. Per-Assignment is the minimum structure that actually
  satisfies the stated requirement — not an over-engineered graph
  subsystem, which was explicitly rejected (see `docs/modules/HR.md`,
  "Reporting hierarchy strategy").

## Alternatives considered

1. **Rename everything to `0J`-prefixed checkpoints and reorder
   `MASTER-ROADMAP.md`.** Rejected: more churn than benefit; see
   Rationale above. Revisit only if a future contributor finds the
   dual numbering scheme genuinely confusing in practice.
2. **Build Phase 0E (Documents) first, in full, as a prerequisite for
   8A.7.** Rejected for Phase 8A: that would mean designing a
   general cross-module Documents system's schema and API from inside
   an HR checkpoint, which is itself a form of speculative,
   premature design (the opposite problem root CLAUDE.md rule 2
   warns about) — better to ship a narrow, honestly-scoped
   `employee_documents` table now and let Phase 0E's eventual design
   reconcile it later with full cross-module context.
3. **Flat `employees.joined_at`/`left_at` with a JSON `assignment_history`
   column instead of relational tables.** Rejected: loses queryability
   (no indexed "all employees currently reporting to X," no database-
   enforced primary-assignment-uniqueness invariant), and works against
   every existing convention in this codebase (Academic Structure has
   no JSON-blob history columns anywhere).
4. **`manager_employee_id` with a documented limitation that dual-
   reporting isn't supported yet.** Rejected: the brief explicitly
   describes dual-reporting as a real, near-term scenario for this
   product (teacher + coordinator), not a hypothetical; shipping a
   model known not to fit a named requirement, when a small structural
   change (`manager_assignment_id`) fits it for free, isn't a
   reasonable simplification.

## Consequences

- `docs/modules/HR.md` is the binding reference for every Phase 8A
  checkpoint's schema/API/authorization/privacy decisions; a checkpoint
  that needs to deviate from it updates that document in the same PR
  (mirrors how `ACADEMIC-STRUCTURE.md` is treated as living, not
  frozen).
- `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0J entry is annotated (not
  rewritten) pointing to this ADR and to `docs/modules/HR.md`.
- Every Phase 8A migration from 8A.1 onward follows the composite-FK
  `(id, school_id)` / `TenantRls::enable()` / partial-unique-index
  patterns already established in Phase 0D — no new tenancy mechanism
  is introduced by this ADR.
- Phase 0E, whenever it is eventually built, inherits an explicit,
  written obligation (this ADR) to reconcile `employee_documents`
  rather than silently ignoring it or duplicating its concerns.

## HR.1A amendment (2026-11-03): effective-dated workforce foundation

**Status:** accepted by the HR.1A checkpoint (owner-authorized HR
programme, `docs/hr/HR-0A-ARCHITECTURE-RECONCILIATION.md` HR-AD-01..03).

The three-table split (§2) stands. HR.1A adds the following.

1. **Employment terms are effective-dated and append-only** (`employment_terms`).
   - The type, staff type and category columns on `employment_records`
     become a database-maintained projection of the latest term. A direct
     update of them is refused. Before HR.1A they were overwritten in place
     (X-3).
2. **Overlap is database-enforced** for employments of one Employee and for
   primary assignments of one employment.
   - Each check is a trigger serialized by a transaction advisory lock on
     the owning key.
   - This supersedes `docs/modules/HR.md`'s earlier "not database-constrained"
     overlap policy.
   - `btree_gist` remains unused.
3. **A placement change is a named, dated, reasoned operation**
   (`employee_placement_changes`).
   - It closes the source primary assignment and opens a new one; it never
     edits an assignment's placement.
   - `setPrimary()` is reclassified as a primary-designation correction and
     is recorded as such.
4. **Staff type is a closed HR classification** on the employment's terms.
   - It is distinct from Category, Department, Position and role.
   - It grants nothing.

**Alternatives rejected:**
- Overwrite in place with audit-only history: the audit holds field names,
  never values, so history would not be answerable.
- Drop the projection columns: too disruptive for every reader and fixture,
  for no integrity gain once a direct update is refused.
- `EXCLUDE USING gist`: the repository's standing choice against
  `btree_gist`.
- Staff type on the Category: it would make a School label a classification
  rule.

## HR.3A amendment (2026-11-03): effective-dated reporting relationships

**Status:** accepted by the HR.3A checkpoint (owner-authorized HR
programme; `docs/hr/HR-0A-ARCHITECTURE-RECONCILIATION.md` X-2 and S-14).

**Supersedes:** the 8A.5 decision recorded in `docs/modules/HR.md` that a
reporting line is a live, mutable
`employee_assignments.manager_assignment_id` pointer whose history lives
only in the audit trail.

1. **A reporting line is a dated relationship between two EMPLOYMENTS**
   (`reporting_relationships`), not an assignment pointer.
   - **Why employment-level:** a transfer or promotion (a new assignment
     of the same employment) must not end or rewrite the line. HR.1A had
     to re-point reports on every placement change and refuse future-dated
     changes of managers. Both workarounds are removed.
   - **One primary line per subordinate per day.** The 8A.5 rationale for
     "a manager per assignment" (different managers for different duties)
     was never used by any reader: `ReportingLine` always took the primary
     assignment's manager. Secondary or functional lines remain a future,
     owner-decided addition.
2. **The legacy pointer is removed (Option A), not kept as a projection.**
   With future-dated lines, a "current" projection would drift as days
   pass with no write to refresh it, and a daily job is speculative
   infrastructure (CLAUDE.md rule 2). One source of truth remains.
3. **The database enforces it.** One trigger, serialized by a per-School
   transaction advisory lock, enforces:
   - no overlap;
   - never oneself or the same Employee;
   - within both employments;
   - a date-aware loop check;
   - immutable parties and start, the end only narrowing, and final voids.

   A second trigger refuses an employment date change that a live line
   would outlive. `btree_gist` remains unused.
4. **Changes are named and reasoned:** assign, change, remove, correct
   (void + replacement) and employment-end closure. A change never rewrites
   a later line.
5. **The cut-over fabricates no history.** Each current pointer becomes a
   line starting on the cut-over date, and ambiguous legacy data refuses the
   migration. Rollback is lossy by design: only lines current at rollback
   become pointers.
6. **A manager's departure leaves the reports without a manager.** They are
   never silently re-parented.

**Alternatives rejected:**
- A projection pointer kept in sync: it drifts with future-dated lines.
- Assignment-level dated lines: a transfer would end the line.
- `EXCLUDE USING gist`: the standing choice against `btree_gist`.
- Fixed L1/L2/L3 manager columns: depth is a traversal, not a column.
- Reconstructing pre-cut-over history from the audit trail: the audit
  carries ids of overwritten pointers but not every period reliably. A
  partial reconstruction would look authoritative and would not be.

## HR.2A amendment (2026-11-03): staff profile & employment records foundation

**Status:** accepted by the HR.2A checkpoint (owner-authorized HR
programme; roadmap HR.2; decides OD-HR-13 as written in the HR.2A brief
§40).

1. **One composed profile.** `employees` stays the person-within-HR
   identity. Every other profile fact keeps its owner (HR.1A employment and
   terms, assignments, HR.3A reporting, the 8A ancillary tables, Documents).
   No profile table duplicates an employment, placement, manager, role or
   pay fact.
2. **Reference codes are a closed, School-unique, never-reused record**
   (`employee_reference_codes`), never an identity and never generated.
3. **Ancillary profile records are archived, never deleted** (OD-HR-13
   decided: archive). The runtime role loses DELETE on them, and an archived
   row is frozen evidence. Addresses keep a current value plus correction
   history, not effective dates.
4. **The self-administration guard covers every profile write** (S-3). The
   self-service read of one's own record (OD-HR-3) stays an owner decision.
5. **Editable profile records carry a stale-form token**
   (`EmployeeProfileVersion`), required over HTTP.
6. **Experience totals are derived** (`ExperienceCalculator`), never stored.
7. **Contact values stay typed columns.** A generic contact-method or
   key/value model was rejected: the types are few and fixed, and typed
   columns validate better.

**Alternatives rejected:**
- Keep hard deletes: they destroy relied-upon evidence (S-16).
- Effective-dated addresses: they fabricate dates for legacy rows, and no
  consumer needs a dated address.
- School-configurable reference kinds: integrations need a fixed contract
  per kind.
- A stored "total experience": it drifts from its components.

## HR.12 amendment (2026-11-04): separation cases, notice and offboarding

**Owner decisions (2026-11-04):** HR-061 = HR records, another person
approves; notice = `notice_period` plus manual finalization on or after the
planned last day; clearance blocks completion, never the employment end,
with no waivers; School access is flagged, never changed automatically; the
direct end keeps `deceased` only, assured; reasons are a closed core set.

**Decision.**
1. A separation is a case (`employment_separation_cases`), not a status
   edit. Recording changes nothing; approval (another person, fresh MFA)
   moves the employment to `notice_period`; finalization calls the existing
   `EmploymentService::end()` with the terminal status the closed reason
   maps to (`retirement` → `retired`, otherwise `separated`).
   `EmploymentService` gains `startNoticePeriod()`/`endNoticePeriod()`,
   called only by the case service; the HR.1A status vocabulary is
   unchanged and no second status system exists.
2. `EmployeeLifecycleService::separate()` (and its four web/API routes)
   accepts only `deceased`, with a `FreshMfaProof`, a fresh authority read
   (`ApprovalWorkflowRegistry::DIRECT_ASSURED`), a web confirmation, never a
   future date and never over a case in progress. Every other terminal end
   is refused with `SEPARATION_USE_CASE`.
3. Clearance requirements are School configuration with closed
   dimensions (responsibility, optional staff type, mandatory); a case
   snapshots the applicable ones at approval. Other modules' obligations are
   their owners' answers (`SeparationObligationSource`), read only; owners
   with no staff obligation record are NOT_INTEGRATED and never shown as
   clear.
4. X-1 (HR.0A) write paths are fixed: a new Timetable teacher, Transport
   driver or Visitor host needs `EmploymentCoverage::hold()` = COVERED on the
   School-local today. The listing pickers stay for HR.15.

**Rejected:** a generic workflow engine or approval table (rule 102); an
`employees.status` shortcut; automatic suspension of School access; a
waiver path; a scheduler; notice-length calculation; manual attestation of
Library, Inventory or Finance clearance; backfilling cases for historical
separations.

**Consequences.** Four tenant tables with forced RLS and triggers
(migration `2027_01_30_090000_create_hr_separation_cases`); four
capabilities (ADR 0071 §31); the employment, its cases, timeline and items
are one retention unit (HR evidence, 8 years after final separation);
rollback refuses while any case exists. Residuals: R-HR12-1 (the X-1
pickers still list separated Employees by name), R-HR12-2 (no
domain-evidence clearance for Library, Inventory, Finance), R-HR10-1 is now
**detected** (flagged on the case) but still corrected manually.

## HR.15 amendment (2026-11-04): cross-domain workforce integration (partial)

**Owner decisions (2026-11-04):** Inventory staff custody, Library staff
borrowing and Finance staff dues are deferred (NOT_INTEGRATED, never clear);
operational pickers require employment only.

**Decision.**
1. One HR reader, `DutyCandidateReader`, backs every operational staff
   picker (Timetable teacher web/API, Transport driver, Visitor host), with
   the predicate `EmploymentCoverage::constrainToCoveredOn()` -- the read form
   of `hold()`. Consumers authorize their own capability; saves keep `hold()`.
2. Separation obligations gain RETAINED (kept access: never clear, never
   revoked, non-blocking) and UNAVAILABLE (an owner that failed: blocking).
   Each owner is asked in its own savepoint; `SeparationObligationSource`
   gains `owner()`.

**Rejected:** a driver-qualification or teaching-assignment rule in the
pickers (no owner contract); a person reference on Inventory movements; an
HR-owned shadow of any owner's data; treating a failed read as zero.

**Consequences.** No migration. R-HR12-1 closed. Residual R-HR15-1: Identity
grants are not locked by HR, so a role granted in the same instant a case
completes can be missed (the case stays readable and the grant visible).

## HR.13 amendment (2026-11-04): staff tasks

**Owner decisions:** new keys `hr.tasks.view/.configure/.manage/.team/.self`
and one add-on role `team_task_manager` (ADR 0071 §32); per-template
self / reviewed completion; scheduled, calendar-neutral recurrence (today
only, never a backlog); bounded remarks and feedback, no attachments.

**Decision.** A new HR-owned sub-domain `App\Domain\WorkforceTasks` with six
tenant tables (migration `2027_01_31_090000_create_workforce_tasks`). A task
belongs to an EMPLOYMENT. Templates have immutable versions. One generator
(`TaskGenerationService`) traverses the complete roster, matches HR.1A
effective facts (`WorkforceAsOfReader::placementsOn`) and inserts
idempotently. Manager authority is `hr.tasks.team` plus the CURRENT HR.3A
line; administrators use `hr.tasks.manage`. HR gains `PositionReference`;
Academic Structure gains `AcademicContextReference` (labels only).

**Rejected:** a generic workflow engine; an EAV or expression-based rule
engine; a work calendar inside tasks; backlog generation; ratings (OD-HR-7);
attachments; extending `line_manager`.

**Consequences.** Task history is HR evidence (goes with its employment);
configuration is tenant-lifetime. Rollback refuses while tasks exist.
