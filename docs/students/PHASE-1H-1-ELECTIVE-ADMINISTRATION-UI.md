# SubjectOffering Roster & Elective Administration UI (Phase 1H.1)

> Informal module-local checkpoint label ("Phase 1H"), not a formal
> `docs/roadmap/MASTER-ROADMAP.md` phase — see
> `docs/students/PHASE-1H-0-ELECTIVE-ADMINISTRATION-UI-ARCHITECTURE.md`
> §1 for the precedent. This is the ONE implementation slice for Phase
> 1H, built exactly to the accepted 1H.0 architecture. After this
> checkpoint, the next step is the **Final Phase 1 Completeness Audit**
> — not another Phase 1 feature.

## 1. What this closes

The last previously-identified Students/SIS gap: a normally-authorized
staff user had no way to look at who is currently in a SubjectOffering,
or to enroll/withdraw/cancel/transfer a Student's elective
participation, even though the entire backend
(`StudentSubjectEnrollmentService`, `SubjectOfferingRosterReadService`)
has existed since Phase 1C/1F. This checkpoint adds the thin
presentation layer only — zero changes to those canonical services.

## 2. Workflow orientation (Option C, per 1H.0 §3)

A single, SubjectOffering-centric workspace: `/app/subject-offerings`
(bounded, filterable-by-AcademicYear index) →
`/app/subject-offerings/{offering}` (one Offering's detail/roster/
history/management workspace). Roster rows optionally link to the
existing Student admin detail page, gated independently by
`students.view` — hidden (not merely disabled) if the acting user
lacks it. No separate Student-centric elective panel exists.

## 3. Entry point / navigation

`DashboardController` gained one new nav flag,
`canViewSubjectOfferings` (gated by `academics.subjects.view`),
rendered in `Dashboard.vue` alongside every other module's entry link
— no duplicate top-level navigation section, matching every existing
convention in this file.

## 4. Required vs elective — the load-bearing distinction

- **Required Offering** (`is_required = true`): the workspace shows
  the authoritative implied roster (from
  `SubjectOfferingRosterReadService::currentRosterStudentIds()`, never
  recomputed) with **zero** management actions and **no** history
  panel — required membership is derived from `StudentEnrollment`
  placement only, and the Inertia response has `history: null` for
  this case (structural absence, not a hidden button).
- **Elective Offering** (`is_required = false`): roster + Enroll/
  Withdraw/Cancel/Transfer actions (when `academics.subjects.manage`
  is held) + a separate bounded participation-history panel.

This checkpoint's own
`SubjectOfferingElectiveAdministrationUiTest::a_required_offering_shows_an_implied_roster_with_no_history_and_no_management_surface`
and `::enrolling_into_a_required_offering_is_defensively_rejected`
prove both the read-only rendering and the defensive backend rejection
(a required Offering's `enroll` route still exists at the HTTP layer,
because the App controller mirrors the JSON API's full mutation
surface — the workspace simply never renders the button that would
call it, and the canonical service rejects it anyway if reached).

## 5. Roster authority — unchanged

`SubjectOfferingRosterReadService::currentRosterStudentIds()` remains
the ONLY authority for current-roster membership; the App controller
(`SubjectOfferingController::show()`) hydrates the identical safe
Student projection the JSON API's `roster()` action already returns
(`id`/`studentNumber`/`firstName`/`middleName`/`lastName`) — no
required/elective membership rule is reimplemented.

For an elective Offering only, the roster rows are additionally
enriched with the active `StudentSubjectEnrollment` id (needed to
address withdraw/cancel/transfer) via one bounded lookup keyed by the
roster ids the read service already returned — this does NOT re-decide
membership, it hydrates a display/action detail for an already-decided
set.

## 6. Participation history — a separate, new, bounded read

`StudentSubjectEnrollmentHistoryReadService::forOffering()` is a small,
new, Students-owned read boundary: every `student_subject_enrollments`
row for one Offering (`active`/`withdrawn`/`cancelled`/`transferred`),
ordered by `starts_on` descending, paginated (25/page). It is never
consulted to decide roster membership, and carries no transcript/GPA
semantics — only `id`, Student reference, `status`, `startsOn`,
`endsOn`. After a transfer, the source row shows `transferred` and the
new target row shows `active` in the SAME history list for its own
Offering — no destructive replacement anywhere.

## 7. Eligible-Students adapter (the "1D Guardian-picker lesson")

`ElectiveEnrollmentCandidateReadService::eligibleStudents()` is a
narrow, App-only (never `/api/v1`, never OpenAPI) read adapter gated
**only** by `academics.subjects.manage` — never `students.view`/
`students.manage`. It reimplements the SAME simple compatibility
predicate `StudentSubjectEnrollmentService::resolveCompatibleEnrollment()`
already expresses (School + AcademicYear + GradeLevel + Campus,
`StudentEnrollment.status = 'active'`) rather than that private method
being made `public` merely for a picker — `enroll()` remains sole
authority at submit time regardless. It excludes Students already
`active` in the exact target Offering (a UI convenience; the backend's
own unique index remains the real authority) and supports an optional
live-search `q` parameter (name/Student-number, `ilike`), bounded to
25 results by default (100 max) — matching this repo's Guardian-picker
precedent rather than a static whole-list convention, since a Grade's
Student population can exceed the small bounded lists Grade/Section
pickers use elsewhere.

Each candidate row also carries an informational
`hasCurrentGroupConflict` flag (does this Student already have an
active choice in the target Offering's ElectiveGroup?) — display-only,
never a client-side block; `ElectiveGroupConflictException` from
`enroll()` remains the real authority.

## 8. Transfer-target adapter

`ElectiveEnrollmentCandidateReadService::transferTargets()` — a
different query shape: given a source `StudentSubjectEnrollment`,
re-derives the Student's CURRENT active `StudentEnrollment` fresh
(never trusted from the source row's own placement anchor, which may
be stale) and returns compatible elective, active target Offerings in
that context, excluding the source Offering itself. Deliberately does
**not** exclude same-ElectiveGroup targets — same-group transfer is
valid and required to work (proven by
`the_transfer_targets_adapter_excludes_the_source_offering_but_includes_a_same_group_target`).

## 9. Canonical mutations only

Every mutation — enroll/withdraw/cancel/transfer — goes through
`StudentSubjectEnrollmentService`'s four existing methods, called from
the new, thin `App\Http\Controllers\App\StudentSubjectEnrollmentController`
(mirrors the exact domain-level split already established by
`SubjectOfferingController` (AcademicStructure) vs
`StudentSubjectEnrollmentController` (Students, JSON API)). No direct
model creation, no manual `elective_group_id`/`student_enrollment_id`
assignment, no generic status PATCH, no hard delete. Transfer is one
atomic POST to `/app/subject-enrollments/{enrollment}/transfer` — never
a sequential withdraw-then-enroll pair from the browser.

## 10. Lifecycle copy (Withdraw vs Cancel)

Per 1H.0 §7's resolution (not flagged as an open architecture
decision — `docs/modules/STUDENT-ENROLLMENT.md`'s "Cancellation"
section consistently applies this narrow interpretation everywhere,
including `StudentSubjectEnrollmentService`'s own docblocks):

- **Withdraw**: "The Student was actively taking this elective and has
  now left it." (a real, completed-early departure)
- **Cancel enrollment**: "This enrollment should not count as having
  been active — use this to correct an enrollment made in error. The
  record is kept for history, never deleted." (an after-the-fact
  administrative correction)

Both require the operator to pick an effective/end date and are
lifecycle-changing actions confirmed via `window.confirm()`, matching
this repo's existing destructive-action UI pattern
(`Show.vue`/`Guardians/Show.vue` precedent).

## 11. Error mapping

| Domain exception | UI meaning |
|---|---|
| `RequiredSubjectOfferingEnrollmentException` | "This Offering is required and does not support explicit enrollment." (defensive only — the action is structurally absent from the DOM for required Offerings) |
| `InactiveSubjectOfferingException` | "This Offering is not currently active and cannot accept new/transferred-in participation." |
| `IncompatibleSubjectOfferingException` | "This Student's current enrollment doesn't match this Offering's Year, Grade, or Campus." |
| `ActiveSubjectEnrollmentConflictException` | "This Student is already enrolled in this Offering." |
| `ElectiveGroupConflictException` | "This Student already has an active choice in the same elective group — withdraw or transfer that one first." |
| `InvalidSubjectEnrollmentTransitionException` | "This enrollment can no longer be withdrawn/cancelled/transferred from its current state." |
| `InvalidEnrollmentDateRangeException` | The service's own already-safe message, rendered inline |
| `CrossSchoolSubjectEnrollmentException` | Generic non-enumerating validation failure |

All translated to `ValidationException::withMessages([...])` for
Inertia's `form.errors` — no SQLSTATE, constraint name, or PII ever
surfaces.

## 12. Authorization (verified live)

- View: `academics.subjects.view` — workspace, roster, history,
  candidate/transfer-target adapters when read-only.
- Manage: `academics.subjects.manage` — enroll/withdraw/cancel/
  transfer AND both read adapters (mutation-adjacent, so gated the
  same as the mutations they support).
- **No** `students.view`/`students.manage`/`enrollments.manage`
  anywhere in this checkpoint's authorization — proven directly by
  `neither_view_nor_manage_requires_a_students_capability`.
- No new capability introduced. No role-name check anywhere — every
  gate is `AuthorizesCapability::authorizeCapability()`.

## 13. Privacy

Student fields shown: `id`, `studentNumber`, `firstName`,
`middleName`, `lastName`, Section label (display context only).
Excluded: DOB, Guardian contact, Admissions notes, HR, Documents,
Fees, or any other Sensitive/Highly Sensitive field. Cross-School: a
foreign-School Offering id 404s on the `show` route
(`a_foreign_school_offering_404s_on_the_show_route`) — never a
distinguishing error message.

## 14. Audit

No new audit event anywhere in this checkpoint.
`StudentSubjectEnrollmentService`'s four mutation methods already
record `student_subject_enrollment.created/withdrawn/cancelled/
transferred` — the App controller does not duplicate them. Read
actions (roster/history/eligible-students/transfer-targets) get no new
audit, matching this module's existing convention.

## 15. Concurrency / staleness UX

No client-side locks. Every mutation redirects back to the workspace
(`redirect("/app/subject-offerings/{$id}")`), which re-fetches
authoritative Inertia props on the next GET — no optimistic
row insertion/removal that could contradict a concurrent group
conflict or Offering status change. A losing concurrent mutation
receives one of the domain exceptions in §11, mapped inline.

## 16. ElectiveGroup configuration UI — still deferred

Unchanged from 1H.0 §12's classification: **DEFERRED, NOT PART OF
ORIGINAL PHASE 1 GAP**. This checkpoint only ever *reads*
`SubjectOffering.electiveGroup` (name, read-only) — no create/assign/
reassign screen exists or was built here.

## 17. Bounds

`eligible-students`: 25 default, 100 max. `transfer-targets`: 100 max
(matches the Phase 1G.4 whole-year-option-list precedent, since target
context is already narrowed to one School/Year/Campus/Grade). History:
25/page, standard Laravel pagination. No unbounded `Model::get()`
anywhere in this checkpoint's new code.

## 18. OpenAPI / generated types

**Unchanged.** All mutation/read surfaces this workflow needs already
existed in the JSON API; the two new adapters
(`eligible-students`/`transfer-targets`) are App-only (Inertia/fetch),
matching the Guardian-picker precedent, which was also never added to
OpenAPI. Verified: `packages/contracts/openapi/school-os-api.yaml` and
`packages/shared-types/src/generated/school-os-api.ts` are absent from
this checkpoint's diff.

## 19. Test coverage

`tests/Feature/App/SubjectOfferingElectiveAdministrationUiTest.php` —
13 tests / 71 assertions, covering: required-roster read-only
rendering + defensive enroll rejection; elective enroll through the
canonical service; view-only vs manage authorization; the
no-students-capability proof; withdraw/cancel transitions with history
preservation; atomic transfer (source `transferred` + new `active`
target row, count proof); same-ElectiveGroup transfer rejection with
source staying active; both read adapters' exclusion/inclusion rules
(already-enrolled excluded, group-conflict flagged not excluded,
same-group transfer target included, source Offering excluded);
cross-School 404; and history-vs-roster distinction (withdrawn rows
appear in history but never in the current roster).

Full regression: existing Phase 1C API tests (roster/enroll/withdraw/
cancel/transfer), Phase 1F (ElectiveGroup integrity, mutual
exclusivity, real concurrency), the complete Phase 1G rollover track
(1G.1–1G.4), StudentEnrollment, roster, and Communications
SubjectOffering-audience tests — all green, zero production changes to
any of those files.

## 20. Phase 1 closure readiness

Per 1H.0 §27 / root task §75: the next step after this checkpoint's
acceptance is the **Final Phase 1 Completeness Audit** — not another
automatically-invented Phase 1 feature. No further Phase 1H slice is
planned; the backend for enroll/withdraw/cancel/transfer/roster
already existed in full before this checkpoint, and this checkpoint
closes the only remaining presentation gap.
