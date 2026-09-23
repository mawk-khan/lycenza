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
