# SubjectOffering / Elective Enrollment Administrative UI Architecture (Phase 1H.0)

> Informal module-local checkpoint label ("Phase 1H"), not a formal
> `docs/roadmap/MASTER-ROADMAP.md` phase — matching the established
> precedent that 1A/1B/1C/1F/1G's fine-grained numbering also lives
> only in `docs/students/`, never the master roadmap (see
> `docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
> §1). This checkpoint is **architecture/inventory only** — zero
> production PHP, migrations, routes, Vue, OpenAPI, or capability
> changes. It closes the last previously-identified Students/SIS gap
> before the final Phase 1 completeness audit (§14).

## 1. Purpose and boundary

Phase 1G (subject rollover) is accepted and end-to-end complete. The
one remaining planned staff-facing SIS gap is **SubjectOffering roster
/ elective enrollment administration** — a normally-authorized staff
user has no way, today, to look at who is currently in a
SubjectOffering or to enroll/withdraw/cancel/transfer a Student's
elective participation, even though the entire backend
(`StudentSubjectEnrollmentService`, `SubjectOfferingRosterReadService`,
the JSON API) has existed since Phase 1C and was extended for
group-exclusivity in Phase 1F. This checkpoint designs — and does not
yet build — the thin presentation layer that closes that gap.

Explicitly out of scope (root task §51/52/65-70, confirmed unnecessary
by the inventory below): general Student elective tab, manual
enroll/withdraw/transfer UI outside this workflow, ElectiveGroup CRUD
administration, rollover changes, teacher/timetable/attendance/exams/
grades/fees/portals, and any elective "rule engine" (min/max choices,
prerequisites, credits, preference ranking) beyond Phase 1F's existing
max-one-per-group.

## 2. Existing backend inventory (read directly from code, not prior docs)

| # | Question | Answer | Evidence |
|---|---|---|---|
| A | SubjectOffering list/read API | **YES** | `SubjectOfferingController::index/show/store/update`, `routes/api.php` lines 241-250 |
| B | SubjectOffering roster API | **YES** | `StudentSubjectEnrollmentController::roster()`, `GET /subject-offerings/{subjectOffering}/roster` |
| C | StudentSubjectEnrollment enroll API | **YES** | `StudentSubjectEnrollmentController::store()`, `POST /students/{student}/subject-enrollments` |
| D | withdraw API | **YES** | `StudentSubjectEnrollmentController::withdraw()`, `POST /subject-enrollments/{enrollment}/withdraw` |
| E | cancel API | **YES** | `StudentSubjectEnrollmentController::cancel()`, `POST /subject-enrollments/{enrollment}/cancel` |
| F | transfer API | **YES** | `StudentSubjectEnrollmentController::transfer()`, `POST /subject-enrollments/{enrollment}/transfer` |
| G | App/Inertia routes for any of the above | **NO** | No `app/subject-offerings` or `app/subject-enrollments` prefix exists in `routes/web.php`; only `/app/enrollment-rollovers/.../subject-mappings` (Phase 1G.4, a *different* workflow — configuring future-year mapping intent, not managing current-year rosters) |
| H | Vue roster page | **NO** | `grep -rl subject-offerings apps/platform/resources/js/Pages` returns only `EnrollmentRollovers/SubjectMappingsPanel.vue` (an Offering *picker*, not a roster) and the Communications composer's audience picker |
| I | Vue elective-management UI | **NO** | No enroll/withdraw/cancel/transfer form exists anywhere in `resources/js/Pages` |

**Conclusion**: the entire mutation and read API this workflow needs
already exists, fully tested, unchanged since Phase 1C/1F. Phase 1H is
**App/Inertia + Vue only**, plus one narrow adapter endpoint (§8). This
resolves API option **A/C-hybrid**: existing mutation API is fully
sufficient (option A) but a narrow workflow-specific Student-picker
adapter is still needed (option C) — never option D (no new full
elective administration API).

`SubjectOfferingController::index` also confirms there is no existing
App/Vue page listing `SubjectOffering` at all (`SchoolSetup/Subjects.vue`
lists `Subject` — the Subject *catalog* — never `SubjectOffering`, and
neither Sections nor SubjectOfferings have a School-Setup Vue page
today). The workspace this checkpoint designs is genuinely new
navigation surface, not an extension of an existing SubjectOffering
page.

## 3. Chosen workflow orientation — Option C

Compared per root task §71:

- **A (SubjectOffering-centric only)**: matches the task's own §8
  workflow narrative verbatim ("open a SubjectOffering; view roster;
  enroll/withdraw/cancel/transfer") and matches every mutation method's
  actual shape (`enroll(Student, SubjectOffering, ...)`,
  `withdraw/cancel(StudentSubjectEnrollment, ...)`,
  `transfer(StudentSubjectEnrollment, SubjectOffering, ...)` — all keyed
  by Offering/enrollment-row, never by "a Student's electives list").
- **B (Student-centric only)**: would require a *new* read boundary
  ("all current elective participation for Student X") that does not
  exist today and is not needed by any of the four write actions —
  pure additional surface for no workflow benefit.
- **D (both full surfaces)**: duplicates authorization/query surface
  for a gap that a single-sided workspace fully closes.

**Chosen: C — SubjectOffering-centric workspace, with roster rows
optionally linking out to the existing Student admin detail page**
(gated independently by `students.view`, hidden if the acting user
lacks it — §10 of root task). No separate Student-centric elective
panel is built. This is the smallest surface that satisfies the
completion criterion in root task §56, matches the Phase 1G.4
rollover-workspace precedent (extend/build one coherent workspace, not
a parallel one per entity), and requires zero new read model beyond
what `SubjectOfferingRosterReadService` already guarantees.

## 4. Required vs elective roster

Backed directly by `StudentSubjectEnrollmentController::roster()` and
`SubjectOfferingRosterReadService` (`PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`
§9): the roster response already includes `meta.isRequired`. The
workspace uses this flag to render two fully distinct UI modes:

- **Required Offering**: roster list only, **zero** action buttons
  (enroll/withdraw/cancel/transfer are structurally absent from the
  DOM, never merely disabled-with-a-tooltip, so there is no risk of an
  operator being invited to attempt an action the backend will reject
  anyway). Explanatory copy: "Membership is derived automatically from
  Student placement (enrollment, Grade, Campus). To change who is
  here, update the Student's enrollment instead."
- **Elective Offering**: roster list plus the four actions (§6).

Membership itself is never recomputed in Vue or the controller — both
modes render exactly the `student` array `SubjectOfferingRosterReadService`
(via the controller) returned, unmodified.

## 5. Roster read authority — no new service needed

`SubjectOfferingRosterReadService::currentRosterStudentIds()`/
`currentRosterCount()` remain the sole membership authority, called
exactly once by the existing `StudentSubjectEnrollmentController::roster()`
action (no controller/Vue re-derivation). The controller already
hydrates a safe Student projection (`id`, `student_number`,
`first_name`, `middle_name`, `last_name`) — sufficient for the roster
table (root task §11). **No additional read/projection service is
required for current-roster display.**

A **new, additive** read need does exist for history (§9) — bounded by
a bounded, filtered query directly analogous to
`SubjectOfferingRosterReadService`'s own shape, described below.

## 6. Elective actions and exact service contracts (read from code, not assumed)

| Action | Service method | Signature | Notes |
|---|---|---|---|
| Enroll | `StudentSubjectEnrollmentService::enroll()` | `enroll(Student $student, SubjectOffering $offering, string $startsOn, ?User $actor)` | Resolves the compatible `StudentEnrollment` internally (§7); rejects required Offerings, inactive Offerings, incompatible placement, duplicate active membership, and elective-group conflicts. |
| Withdraw | `::withdraw()` | `withdraw(StudentSubjectEnrollment $enrollment, string $endedOn, ?User $actor)` | Terminal transition only from `active`. |
| Cancel | `::cancel()` | `cancel(StudentSubjectEnrollment $enrollment, string $endedOn, ?User $actor)` | Same mechanics as withdraw, distinguished only by intent (§7). |
| Transfer | `::transfer()` | `transfer(StudentSubjectEnrollment $sourceEnrollment, SubjectOffering $targetOffering, string $effectiveDate, ?User $actor)` | Atomic: source → `transferred`, new `active` row for target, same or different ElectiveGroup both allowed (§8). |

All four already have a complete, tested JSON API boundary
(`StudentSubjectEnrollmentController`). Phase 1H adds a **thin App
controller mirroring that JSON controller's exact shape** — same
pattern `App\Http\Controllers\App\EnrollmentRolloverSubjectMappingController`
already establishes for Phase 1G.4 (delegates to the identical
Application-service methods, translates the same domain exceptions to
`ValidationException` for Inertia's `form.errors`, redirects back to
the workspace on success). **No business logic is duplicated — only
the HTTP binding layer is mirrored**, exactly like every other
API/App controller pair in this codebase.

## 7. Withdraw vs Cancel — resolved, not blocking

Root task §23/50 requires "ARCHITECTURE DECISION REQUIRED" if the
repository does not clearly distinguish withdraw/cancel semantics.
`docs/modules/STUDENT-ENROLLMENT.md` ("Cancellation") states explicitly:
*"No existing code or documentation anywhere in this repository defines
`cancelled` vs `withdrawn` semantics"* for the **StudentEnrollment**
sibling service — but immediately adopts, and consistently applies
everywhere including `StudentSubjectEnrollmentService`'s own docblocks
(§10, §12 in this checkpoint's file), the following **narrow,
already-implemented interpretation**:

- **Withdrawn**: the participation WAS operational, and the Student
  subsequently left it (a real, completed-early departure).
- **Cancelled**: the participation should not continue as an
  operational membership; retained purely as an administrative
  historical record (an after-the-fact correction, not a real
  departure).

This is a genuinely narrow interpretation (the repo is explicit that
it invented no elaborate cancellation-reason/approval workflow), but it
**is** consistently documented and consistently implemented identically
across both `StudentEnrollmentService` and `StudentSubjectEnrollmentService`
today — it is not merely absent. Per root task §23's own worked
example ("withdraw = participation ended after being active; cancel =
participation treated as cancelled/error"), this qualifies as "clearly
distinguish" for UI-copy purposes. **Resolved, not flagged as an open
architecture decision**:

- **Withdraw** button/label: "Withdraw" — help text: "The Student was
  actively taking this elective and has now left it."
- **Cancel** button/label: "Cancel enrollment" — help text: "This
  enrollment should not count as having been active — use this to
  correct an enrollment made in error. The record is kept for history,
  never deleted."

If product later wants a richer cancellation-reason/approval workflow,
that is a new, separate business decision — not something this
checkpoint should invent copy around beyond the above.

## 8. Student picker — new narrow adapter required (the "1D Guardian-picker lesson")

`enroll()` takes a `Student`, not a `StudentEnrollment` — the service
resolves the compatible `StudentEnrollment` internally
(`resolveCompatibleEnrollment()`, matching School + Plan's/Offering's
AcademicYear + GradeLevel + Campus, Section deliberately ignored). The
picker therefore needs to surface Students who currently have a
compatible active `StudentEnrollment` for the target Offering's
context.

**Existing candidate reused, and rejected**: `StudentController::index`
(`/app/students`) is gated by `students.view` (verified directly:
`app/Http/Controllers/App/StudentController.php` line 49), an unrelated
capability this workflow has no reason to require — identical to the
Phase 1D Admissions checkpoint's Guardian-picker problem
(`docs/admissions/PHASE-1D-6-ADMINISTRATIVE-UI.md` §4): reusing the
generic endpoint would force granting `students.view` to every operator
who should only need `academics.subjects.manage`.

**Decision, following the identical 1D precedent**: add one new, narrow
App-only adapter endpoint — e.g. `GET /app/subject-offerings/{subjectOffering}/eligible-students`
— gated **only** by `academics.subjects.manage`, reusing the *exact*
compatibility query `resolveCompatibleEnrollment()` already expresses
(same School + AcademicYear + GradeLevel + Campus as the target
Offering, `StudentEnrollment.status = 'active'`), narrowed further to
exclude Students already `active` in this exact Offering (§8 of root
task's "already enrolled" rule — a read-only UI convenience; the
backend's own unique index remains the real authority). No new
capability, no fuzzy matching, no Guardian/contact fields — projection
limited to `id`, `student_number`, `first_name`, `middle_name`,
`last_name`, current Section label (display-only, not selectable/not
sent back to the server). This is App-only (Inertia), not `/api/v1` —
matching the Guardian-picker precedent, which was also App-only.

## 9. Transfer target picker

Reuses the identical shape: same School + Plan's-equivalent (here,
simply the *source* Offering's own) AcademicYear + GradeLevel + Campus,
elective-only (`is_required = false`), active. Section is not filtered.
Same or different ElectiveGroup are both valid candidates (root task
§19-20) — the picker does **not** pre-filter by group; the canonical
`transfer()` call remains sole authority for conflict rejection. This
can be served by a second, equally narrow read from the same
`academics.subjects.manage`-gated adapter (or a second action on it) —
no separate service.

## 10. ElectiveGroup conflict UX

Both pre-detection and server authority apply (root task §17 option C):
the Student picker (§8) and transfer target picker (§9) may
**optionally** display the Student's current ElectiveGroup membership
(if any) as read-only context inline in the list (e.g. "already in
Group: Languages"), sourced from the roster/eligible-Students
projection joining the Student's own current active
`StudentSubjectEnrollment.elective_group_id` — this is informational
only, never a client-side block. The **server remains the sole
authority**: `ElectiveGroupConflictException` from `enroll()`/`transfer()`
is what actually prevents the conflicting action, surfaced as a normal
Inertia validation error. Same-group transfer (Group X → Group X) is
explicitly allowed by the picker and the canonical service (root task
§20) — never pre-blocked.

## 11. ElectiveGroup display (read-only)

Where an Offering has a group, the roster/detail view shows the
`ElectiveGroup.name`/`.code` (already available via `SubjectOffering::electiveGroup()`)
as plain read-only text. Ungrouped Offerings display "Ungrouped" (or
this repo's existing equivalent copy convention) — never implying
ungrouped is an error state.

## 12. ElectiveGroup configuration UI — classification: **DEFERRED, NOT PART OF ORIGINAL PHASE 1 GAP**

Evidence: `docs/students/PHASE-1F-3-ELECTIVE-GROUP-CONFIGURATION-SERVICE.md`
§12 ("Deferred") explicitly lists "Administrative API/UI, capabilities,
OpenAPI, Vue" as deferred **from Phase 1F itself**, and `ElectiveGroupService`
has **zero** HTTP surface today (`grep -rn ElectiveGroup routes/`
returns nothing — no API, no App route exists for creating a group or
assigning/reassigning an Offering to one). This checkpoint's own PURPOSE
section names the gap narrowly as "SubjectOffering roster / elective
enrollment administration" — group *configuration* was never part of
that originally-identified gap; it is Academic Structure configuration,
not roster/enrollment administration, and root task §51-52 explicitly
forbid building it here. **No group-config UI is designed or built in
Phase 1H** — the roster workspace only ever *reads* group name/code
(§11), consistent with root task §28.

## 13. Authorization (verified live, not assumed)

- View: `academics.subjects.view` — confirmed live in
  `CapabilityAndRoleSeeder.php` line 69 and already used by
  `StudentSubjectEnrollmentController::roster()`.
- Manage: `academics.subjects.manage` — confirmed live, line 70, used
  by `store()`/`withdraw()`/`cancel()`/`transfer()`.
- Role grants (verified in `CapabilityAndRoleSeeder.php` lines 317 and
  378 — both capabilities are granted together, in both places the
  seeder grants them): granted to the same role set that already
  receives "Subjects and Subject Offerings" management today (no
  production auth change in 1H.0; the exact role list is whatever the
  live seeder currently assigns at those two grant sites — this
  checkpoint does not alter it).
- **No new capability** is introduced anywhere in this checkpoint's
  design — the one new adapter endpoint (§8/§9) is gated by the
  existing `academics.subjects.manage` only.
- **No role-name check** anywhere in the design — every gate is
  `AuthorizesCapability::authorizeCapability()`, matching every existing
  controller in this module.
- View vs Manage: unchanged from existing convention — `view` may
  inspect Offering/roster/history; only `manage` may mutate. The
  existing controllers already enforce this split; the App controller
  mirrors it exactly (no implicit manage→view assumption beyond what
  the existing capability system already grants).

## 14. Privacy / read projection

Student fields shown: `id`, `student_number`, `first_name`,
`middle_name`, `last_name`, current Section/Grade label (display
context only, sourced from the Student's own compatible
`StudentEnrollment`, already-approved projection shape). Excluded:
DOB, Guardian contact, Admissions notes, HR, Documents, Fees, or any
other Sensitive/Highly Sensitive field. Cross-School: every lookup
(Offering, mapping enrollment row, eligible-Student adapter) resolves
through `School`-scoped `TenantContext`/composite-FK precedent exactly
like every other controller in this module — a foreign-School id
produces `findOrFail`'s standard 404-equivalent, never a distinguishing
error message (root task §33).

## 15. Audit

No new audit event. `StudentSubjectEnrollmentService`'s four mutation
methods already record `student_subject_enrollment.created/withdrawn/
cancelled/transferred` with Student id, SubjectOffering id,
AcademicYear id, and lifecycle end date only (`PHASE-1C-...FOUNDATION.md`
§19) — the App controller must not add a second, duplicate audit
event. Read actions (roster/eligible-Students/history) get no new
audit, matching this module's existing convention (the roster read
today is unaudited).

## 16. Error mapping

| Domain exception | UI meaning |
|---|---|
| `RequiredSubjectOfferingEnrollmentException` | "This Offering is required and doesn't support explicit enrollment." (should be unreachable via UI since the action is structurally absent for required Offerings — defensive mapping only) |
| `InactiveSubjectOfferingException` | "This Offering is not currently active and cannot accept new/transferred-in participation." |
| `IncompatibleSubjectOfferingException` | "This Student's current enrollment doesn't match this Offering's Year/Grade/Campus." |
| `ActiveSubjectEnrollmentConflictException` | "This Student is already enrolled in this Offering." |
| `ElectiveGroupConflictException` | "This Student already has an active choice in the same elective group — withdraw/transfer that one first." |
| `CrossSchoolSubjectEnrollmentException` | Generic non-enumerating validation failure (never reveals the foreign School) |
| `InvalidSubjectEnrollmentTransitionException` | "This enrollment can no longer be withdrawn/cancelled/transferred from its current state." |
| `InvalidEnrollmentDateRangeException` | Inline date-field validation message (already carries a safe, non-SQL message from the service itself) |

All translate to `ValidationException::withMessages([...])` for Inertia
inline rendering, mirroring `EnrollmentRolloverSubjectMappingController`
(App)'s established catch-and-translate pattern — no SQLSTATE,
constraint name, or PII ever surfaces.

## 17. Concurrency / staleness UX

No client-side locks. Server authority is absolute — a losing
concurrent mutation receives one of the domain exceptions in §16,
mapped inline; the page reloads via Inertia's standard post-redirect-GET
flow, which naturally re-fetches the authoritative roster (root task
§43). No optimistic row insertion/removal that could contradict a
concurrent group conflict or Offering status change.

## 18. Inactive Offering behavior

Roster: empty (already guaranteed by `SubjectOfferingRosterReadService`'s
existing short-circuit, `PHASE-1C-...FOUNDATION.md` §29 — "Roster
contract"). Enroll/transfer-in: rejected by `InactiveSubjectOfferingException`
(already enforced server-side; the workspace additionally disables the
"Enroll" control when `Offering.status !== 'active'`, purely as a UX
courtesy, never as the actual gate). Exit actions
(withdraw/cancel/transfer-**out**): remain fully available — `assertOfferingIsActive()`
is deliberately never called for the SOURCE side (§7 of the service
docblocks) — the workspace must render these controls even when the
Offering itself is inactive, so existing participants are never
stranded. Reactivation restores ordinary roster/enroll behavior with
no UI-side reconstruction needed.

## 19. History

Included as a **secondary, bounded panel** on the same workspace (not a
new page, not a full transcript module) — root task §12/49 option B/C
hybrid. Fields: status (`withdrawn`/`cancelled`/`transferred`),
`starts_on`, `ends_on`, Offering/Subject context. This requires one
small, new, Students-owned bounded read boundary
(e.g. `StudentSubjectEnrollmentHistoryReadService` or a narrow method
addition — exact naming decided at implementation time) that queries
`student_subject_enrollments` filtered to the Offering, ordered by
`starts_on` descending, paginated per this repo's existing default
(§20 below) — never queried directly from the controller/Vue. After a
transfer, the source row displays `transferred` and the target row
displays `active` in the same history list, making the atomic
switch visible without any destructive replacement in the UI.

## 20. Bounds / pagination

All new/extended reads follow this repository's existing default
picker/list convention (verified against the Phase 1G.4 precedent:
`limit(100)` on picker option lists) — the roster endpoint already
returns the full authoritative set per Offering (bounded by
construction, since roster size for one Offering is realistically
small — proven bounded in `SubjectOfferingRosterReadServiceTest`'s
60-Student scale test); the new eligible-Students adapter and the
history panel must each apply an explicit page size (25 default / 100
max, matching this repo's stated convention) rather than an unbounded
`get()`.

## 21. Entry point / navigation

No existing SubjectOffering Vue page exists to extend (§2). Following
the Phase 1G.4 precedent that an operational SIS workflow (Enrollment
Rollovers) gets its **own** top-level `/app/...` route prefix distinct
from School Setup (reference-data CRUD) and from Students admin
(Student-identity CRUD) — `routes/web.php` line 352,
`Route::prefix('app/enrollment-rollovers')` — this checkpoint proposes
an analogous **`/app/subject-offerings`** prefix: a bounded, filterable
list (by AcademicYear, mirroring the existing API's own
`academic-years/{academicYear}/subject-offerings` scoping) plus a
per-Offering detail/roster/history workspace at
`/app/subject-offerings/{offering}`. This is a genuinely new page, not
a School-Setup or Students-admin extension, because it is an
operational roster-management workflow, not reference-data
configuration or Student-identity management.

## 22. OpenAPI / generated types

**Not required.** All mutation/read surfaces this workflow needs
already exist in the JSON API (unchanged, no OpenAPI edit needed there).
The one new surface (§8/§9's eligible-Students/transfer-target adapter)
is App-only (Inertia), matching the Guardian-picker precedent, which
was also never added to OpenAPI. If implementation later decides the
adapter should be `/api/v1`-exposed for some other reason, that
decision must revisit this section — current design keeps it App-only.

## 23. Test plan (for the future implementation checkpoint(s), not built here)

- **Read**: required-roster rendering, elective-roster rendering,
  inactive-Offering-empty-roster, reactivation restores roster,
  School A/B isolation (404-equivalent), `academics.subjects.view`
  required, `academics.subjects.manage` absent still allows view,
  safe projection (no PII fields ever present), bounded eligible-
  Students/history results.
- **Enroll**: valid compatible Student, same-Offering duplicate
  rejected, same-group conflict rejected, different groups both
  succeed, ungrouped succeeds, inactive Offering rejected, required
  Offering rejected (defensive — UI structurally prevents reaching
  this), incompatible Campus/Grade rejected, Section mismatch allowed,
  stale-placement (StudentEnrollment became inactive between picker
  load and submit) rejected safely.
- **Withdraw/Cancel**: valid action, history preserved, roster updated,
  ElectiveGroup slot released, invalid-lifecycle (already terminal)
  rejected, failed action produces no audit/false-success state.
- **Transfer**: same group, different group, grouped↔ungrouped both
  directions, occupied-target-group rolls back cleanly (source stays
  active), inactive target rejected, context mismatch rejected, history
  shows both rows correctly, concurrent conflict handled safely.
- **UI (Inertia feature tests, this repo's existing pattern — no new
  frontend test framework)**: page props shape, required-vs-elective
  action visibility, capability-flag-driven control visibility, picker
  bounds, domain-error inline rendering, post-mutation refresh,
  cross-School 404/non-enumeration for the workspace route itself.
- **RLS**: re-run existing `StudentSubjectEnrollmentIntegrityTest` and
  `SubjectOffering` RLS coverage unchanged; no new table is introduced
  by this design, so no new RLS proof is required unless implementation
  decides history needs a materialized/derived table (current design:
  it does not — history reads the existing table).

## 24. Implementation slices (proposed, smallest coherent sequence)

Because the entire mutation/read backend already exists and is fully
tested, this is **not** a 4-slice sequence like Phase 1G's. Proposed:

- **1H.1 — SubjectOffering Roster & Elective Administration UI**: the
  new `/app/subject-offerings` App controller (mirroring the existing
  JSON controller's four mutation actions + roster read + list/show),
  the one new eligible-Students/transfer-target adapter endpoint, the
  small new bounded history read, the Vue workspace (list + detail +
  roster table + four action forms + history panel), and the full test
  matrix in §23.

A second slice is not manufactured — if implementation discovers the
history panel or the eligible-Students adapter is non-trivial enough to
warrant separating backend-adapter work from the Vue workspace, that
split can be made naturally at that time (e.g. "1H.1 — Roster/Adapter
Backend Surface" then "1H.2 — Administrative UI"), but nothing in this
inventory currently justifies pre-declaring two checkpoints.

## 25. Phase 1G boundary

Zero rollover files touched or planned for touching. No shared
component justified a cross-cutting refactor during this architecture
pass (the Guardian-picker/adapter pattern is *conceptually* reused, not
*code* reused — a new, independent adapter is written, not an import
from the rollover module).

## 26. Documentation cross-reference

`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md` §27
("Deferred work") is updated with one narrow sentence pointing its
"dedicated Vue/Inertia admin UI" deferred item at this document —
no other text in that file changes.

## 27. Phase 1 closure

Per root task §75: the next step after this architecture is implemented
is the **Final Phase 1 Completeness Audit** — re-reading the original
planned Phase 1 inventory and classifying every item COMPLETE/EXPLICITLY
DEFERRED/NOT REQUIRED/MISSING. This checkpoint does not perform that
audit and does not implement 1H.1 itself.
