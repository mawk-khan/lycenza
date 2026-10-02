# School OS — Timetable Module (Phase 0H.1)

## 1. Checkpoint scope

Phase 0H ("Academic Operations") covers Attendance, Timetable,
Academics, and Examinations. This checkpoint — **Phase 0H.1: Timetable
Foundation** — implements Timetable only: a recurring **weekly**
class-scheduling model (Period catalogue + a weekly grid of scheduled
classes). Attendance, Academics, and Examinations remain **not
started**; Phase 0H as a whole is **not** complete after this
checkpoint (see `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0H section for
the authoritative status).

Three phases built this checkpoint:

1. Backend foundation — schema, `TimetablePeriodService` and
   `TimetableScheduleService` (both with `TenantLock`-serialized
   concurrency protection), 15 typed exceptions, capabilities, 77+
   backend tests including 3 real two-process concurrency races.
2. API/UI — 4 controllers (2 JSON API, 2 session-authenticated Inertia),
   4 Vue pages, dashboard navigation, bringing the suite to 110 tests /
   298 assertions.
3. This checkpoint — security review, documentation, and a definitive
   full-regression run.

## 2. The recurring-weekly model (not dated sessions)

A `TimetableEntry` represents "SubjectOffering X is taught to Section Y
by teacher Z in Room R during Period P on day-of-week D" — a
**standing weekly commitment**, not a session on a specific calendar
date. There is no `date` column anywhere in this schema and no concept
of "today's timetable differs from a normal week" (holidays,
substitutions, one-off room changes are all out of scope — see §16).
`day_of_week` is a plain integer 1 (Monday) .. 7 (Sunday), database
CHECK-enforced (`timetable_entries_day_of_week_check`). This is a
deliberate, narrow v1 scope: the weekly grid is the single source of
truth for "what is scheduled," and any future dated/session-level
concept (make-up classes, substitute teachers for a single day,
term-boundary exceptions) is an explicitly separate, unbuilt future
model, not an extension of this one.

## 3. Domain model

### TimetablePeriod (`app/Domain/Timetable/Infrastructure/TimetablePeriod.php`)

A School-owned, reusable named time slot ("Period 1", 09:00–09:45) that
`TimetableEntry` rows schedule against, day-of-week by day-of-week. Not
tied to any particular day — the same Period row is reused across all
seven days. Reference data with the usual active/inactive lifecycle
(`status` column) — no DELETE route exists.

- **Non-overlap invariant**: no two ACTIVE Periods for the same School
  may have overlapping `[start_time, end_time)` ranges. PostgreSQL's
  plain btree unique index cannot express a range-overlap rule (that
  needs an exclusion constraint this checkpoint deliberately does not
  introduce), so it is enforced in `TimetablePeriodService` instead —
  every method capable of establishing or changing an active interval
  (`create()`, `update()` when start/end changes, `activate()`)
  acquires `TenantLock::forSchool($school, 'timetable.periods')` and,
  **inside** that lock's callback, inside one `DB::transaction()`,
  re-checks every other currently-active Period's range before
  writing. The lock is released only after the transaction commits.
  `CHECK(start_time < end_time)` is the structural backstop against a
  zero-width/inverted interval, independent of the lock.
- **Code uniqueness**: a case-insensitive expression index
  (`timetable_periods_school_id_code_ci_unique` on
  `(school_id, upper(code))`), matching `ledger_accounts`' precedent —
  immune to a raw SQL insert bypassing `App\Support\NormalizesCode`'s
  Eloquent-mutator uppercasing.
- **Deactivation / time-change-blocked-while-referenced rule**:
  deactivating a Period, or changing its `start_time`/`end_time`, is
  **rejected** while any ACTIVE `TimetableEntry` still references it
  (`TimetablePeriodReferencedException`). Non-temporal field changes
  (`name`/`code`/`sort_order`) are never subject to this guard.

  This is a **deliberate, narrow exception** to the codebase's usual
  reference-entity-deactivation convention (CLAUDE.md rule 73: "a
  reference entity is deactivated, existing rows just keep pointing at
  a now-inactive parent"). A `TimetablePeriod` is different: an ACTIVE
  `TimetableEntry` referencing it represents an **ongoing weekly
  commitment that depends on the Period's current time definition
  remaining valid right now** — unlike, say, a completed enrollment
  record pointing at a since-deactivated Section, which is purely
  historical. Silently letting "Period 1" be deactivated or moved to a
  different time while classes are still actively scheduled against it
  would leave those classes referencing a stale or nonexistent slot.
  So Timetable scopes the exception narrowly: only the *temporal*
  identity of a Period is protected while referenced; its non-temporal
  metadata (name/code/sort order) is always freely editable.

### TimetableEntry (`app/Domain/Timetable/Infrastructure/TimetableEntry.php`)

A single scheduled slot: SubjectOffering × Section × teacher (HR
Employee) × optional Room × Period × day-of-week.

- **Required-SubjectOffering-only v1 scope**: only a SubjectOffering
  with `is_required = true` may be scheduled
  (`RequiredSubjectOfferingOnlyException` otherwise). An elective
  (`is_required = false`) is a **Student-level enrollment choice**
  (`StudentSubjectEnrollment`), not a fixed Section-wide weekly slot
  this model represents — a future elective-scheduling model (which
  would need to reason about per-student groupings, not a single
  Section-wide row) is a deliberately separate, unbuilt concern. Tested
  in `TimetableScheduleServiceTest::an_elective_offering_is_rejected`.
- **Structural Section/Offering context-FK design, mirroring the
  `elective_groups` precedent**: `academic_year_id`/`campus_id`/
  `grade_level_id` are carried on the `timetable_entries` row itself
  (derived from the resolved SubjectOffering by the service, never
  accepted as separate caller input) so that TWO composite foreign
  keys — `timetable_entries_subject_offering_fk` against
  `subject_offerings(id, school_id, academic_year_id, campus_id,
  grade_level_id)` and `timetable_entries_section_fk` against the
  equivalent widened key on `sections` — can each pin their respective
  parent to the exact same context. This is the identical pattern
  `elective_groups`/`student_subject_enrollments` already established
  (CLAUDE.md rule 70): a TimetableEntry can never reference a
  SubjectOffering and a Section from different AcademicYear/Campus/
  GradeLevel combinations, even via a raw SQL insert bypassing
  `TimetableScheduleService` entirely — proven in
  `Tests\Feature\Postgres\TimetableEntriesRlsIsolationTest::an_entry_is_rejected_when_the_section_context_does_not_match_the_subject_offering`.
  Two small, additive-only migrations widen `subject_offerings` and
  `sections` with this second composite unique key
  (`2026_09_12_090000_add_context_unique_to_subject_offerings_table.php`,
  `2026_09_12_090100_add_context_unique_to_sections_table.php`) — no
  existing column, index, or query changes; both were confirmed to
  cause zero regression in the Academic Structure/HR suites (§14).
- **Employee-as-teacher with the `isActive()` eligibility gate**: the
  teacher is a real, resolved HR `Employee` (`teacher_id`, composite FK
  against `employees(id, school_id)`, `restrictOnDelete`). Scheduling
  is rejected unless `$teacher->isActive()`
  (`record_status = 'active'`) — `TeacherNotAvailableException`.
  Timetable never introduces its own notion of "teacher eligibility"
  beyond HR's own `record_status`.
  **`teacher_id` is schedule evidence, never authorization (ADR 0063
  §8, D-03/D-16).** Teacher access to a class comes only from a dated
  TeachingAssignment (TCH.2) plus a verified ActingEmployee and an
  owned-scope capability. A timetabled teacher without an assignment is
  refused, and a cover teacher with one is allowed. Attendance snapshots
  this column as provenance only. Timetable itself stays admin-only, with
  no teacher surface.
- **Room reuse**: `room_id` is nullable (a Period can legitimately have
  no assigned Room — a games period). Its composite FK and its own
  slot-uniqueness index both tolerate NULL correctly.
- **Three partial-unique conflict indexes** on `timetable_entries`,
  each `WHERE status = 'active'`:
  - `timetable_entries_teacher_slot_unique` on
    `(school_id, teacher_id, day_of_week, period_id)`
  - `timetable_entries_section_slot_unique` on
    `(school_id, section_id, day_of_week, period_id)`
  - `timetable_entries_room_slot_unique` on
    `(school_id, room_id, day_of_week, period_id)` — additionally
    `AND room_id IS NOT NULL`, so any number of roomless entries never
    collide with each other.

  **Why these are sufficient given the Period invariant**: a
  TimetableEntry references a Period **by id**, not by its own copy of
  a start/end time range — so "same School + same teacher/Section/Room
  + same day-of-week + same Period id" is an *exact-match* collision a
  plain partial unique index expresses correctly. This only works
  because `TimetablePeriod`'s own non-overlap invariant (§3 above)
  already guarantees no two active Periods can represent overlapping
  time ranges — if Periods themselves could overlap, two different
  Period ids could represent the same wall-clock time and this exact-
  match index would miss the resulting double-booking. The two
  invariants are complementary by design, not independently
  sufficient. These are the REAL concurrency guarantee (not an
  application-level check) — a caught `QueryException` is translated
  to the matching typed exception by constraint name in
  `TimetableScheduleService::guarded()`, mirroring
  `App\Domain\Fees\Application\ChargeService::violatesConstraint()`'s
  established pattern.
- **Deactivation frees the slot immediately**: all three indexes are
  scoped `WHERE status = 'active'` — a deactivated entry (row kept,
  never deleted, CLAUDE.md rule 73's convention extended to this
  transactional-but-append-preferred table) frees its slot the moment
  it deactivates, since the index no longer counts it.

## 4. The create-vs-deactivate race, and how it's closed

**The race**: two independently-locked services could each pass their
own eligibility check against stale/not-yet-committed state — process A
creates a TimetableEntry against Period P (reads P as active, before
A's insert commits) while process B concurrently deactivates P (its
"no active entry references P" check runs before A's insert becomes
visible). Neither side ever sees the other's effect, leaving an active
`TimetableEntry` referencing an inactive `TimetablePeriod` — a state
this module's whole design (§3's deactivation-blocked-while-referenced
rule) exists specifically to prevent.

**How it's closed**: `TimetablePeriodService::deactivate()` and every
`TimetableScheduleService` method that touches the Period reference
(`create()`, `update()` when it changes the Period, `activate()`) all
acquire `TenantLock::forSchool($school, 'timetable.periods')` — the
**identical lock key** on both sides (`TimetablePeriodService`'s own
`LOCK_OPERATION` constant and `TimetableScheduleService`'s
`PERIOD_LOCK_OPERATION` constant are required to match exactly, and a
comment on each references the other). Serializing both sides on the
same lock makes whichever side acquires it first fully win: the
loser's re-check (Period active? / any active entry references P?) now
runs strictly after the winner's commit, so it always sees accurate,
committed state. Proven under real two-process concurrency in
`Tests\Feature\Timetable\TimetableEntryVersusPeriodDeactivationConcurrencyTest`
— re-run 3 times total during this checkpoint's security review (once
as part of the full scoped suite, twice more standalone), passing
every time.

Only the Period-eligibility check itself needs to run inside this
lock/transaction — the SubjectOffering/Section/teacher/Room checks
have no equivalent deactivate-blocked-by-active-reference guard on the
other side, so they are validated up front, before the lock, as usual.

## 5. Sensitive data classification

`docs/security/DATA-CLASSIFICATION.md` now carries a Timetable row
classified **Sensitive** (not Highly Sensitive). Quoting that
document's own tier definitions directly:

> **Sensitive**: Personal data of an identifiable individual, not in
> the highest-risk categories below (e.g. a guardian's phone number,
> **an employee's address**). Requires authentication + capability
> check, tenant-scoped by construction, excluded from logs and error
> messages by default; access is audited.

A `TimetableEntry` identifies a specific Employee (`teacher_id`) and
states their recurring weekly location and time (Room + Period +
day-of-week) — directly analogous to that tier's own worked example of
"an employee's address": ordinary personal data of an identifiable
individual, not one of the highest-harm categories (no health,
government-ID, biometric, or financial-account data is involved
anywhere in this module). This is why Timetable is Sensitive, not
Highly Sensitive, and not merely Confidential (it names a specific
person, unlike Inventory/Canteen-catalogue's purely operational data).

## 6. Data minimization

Every presenter that touches an Employee (teacher) across both HTTP
layers (`app/Domain/Timetable/Http/Controllers/TimetableEntryController.php`,
`app/Http/Controllers/App/Timetable/TimetableEntryController.php`, and
their Vue consumers) projects **only `id` and `fullName`** — never
`work_email`, `work_phone`, or any other HR field. This is verified,
not merely asserted: a repository-wide `grep -rn "work_email\|work_phone"`
against every Timetable controller and Vue page returns zero hits
outside comments explicitly documenting the rule (§7 confirms the
exact commands run and their zero-hit results). Both the API-layer
`searchTeachers()`/`present()` and the UI-layer equivalents are covered
by a dedicated regression test in both suites
(`the_teacher_search_endpoint_never_leaks_hr_fields_via_the_api`,
`the_teacher_search_endpoint_never_leaks_hr_fields`,
`schedule_index_never_leaks_teacher_hr_fields`).

There is no Student relationship anywhere in this data model — see §10.

## 7. Security review (evidence-based)

Every item below was independently re-verified during this checkpoint,
against the actual code and a real test run, not re-asserted from the
prior phases' own claims.

| Item | Result | Evidence |
|---|---|---|
| Cross-School IDOR on every Timetable endpoint | **Clean.** Every model uses `App\Support\Tenancy\BelongsToSchool` (RLS Layer 1 + `TenantContext` auto-fill); every migration calls `TenantRls::enable(...)`; API routes sit inside `Route::middleware(['auth:sanctum','school-membership'])->prefix('schools/{school}')`; Inertia routes derive the School exclusively from `TenantContext::requireSchool()` (session-verified membership), never client input. | `Tests\Feature\Postgres\TimetablePeriodsRlsIsolationTest`/`TimetableEntriesRlsIsolationTest` (RLS enabled+forced, cross-School SELECT/UPDATE return zero rows); `TimetableApiTest`/`TimetableAdminUiTest` (`a_wrong_school_period_id_is_not_visible_via_the_ui`, and the API suite's own School-A/School-B checks). |
| Helper enumeration — both capability-boundary tests still pass, AND a user with **neither** Timetable capability is rejected on **every** helper | **Confirmed, both layers, all 5 helpers each.** API: `TimetableApiTest::a_member_with_neither_timetable_capability_cannot_use_any_entry_helper` asserts `assertForbidden()` on `search/subject-offerings`, `search/sections`, `search/teachers`, `search/rooms`, AND `search/periods` — all 5. UI: `TimetableAdminUiTest::a_member_with_neither_timetable_capability_cannot_use_any_schedule_helper` — identical 5-endpoint coverage. Both re-ran green in this checkpoint's full scoped run. | Test run: 110/110 passed (§14). |
| Employee PII leakage (`work_email`/`work_phone`) | **Zero hits.** | `grep -rn "work_email\|work_phone" app/Domain/Timetable app/Http/Controllers/App/Timetable resources/js/Pages/App/Timetable` returns only doc-comment lines explicitly stating the rule (no actual field access) — confirmed by direct inspection of every match. Test coverage: §6 above. |
| Inactive-parent scheduling (SubjectOffering/Section/Employee/Room/Period all rejected for new/reactivated entries) | **Confirmed for all 5 parents, on both creation and reactivation.** `assertParentsSchedulable()` checks `is_required`, offering/section/teacher/room `isActive()`; `assertPeriodSchedulable()` (inside the lock) checks the Period separately. | `TimetableScheduleServiceTest`: `an_inactive_offering_is_rejected`, `an_inactive_section_is_rejected`, `an_archived_employee_is_rejected`, `an_inactive_room_is_rejected_when_provided`, `an_inactive_period_is_rejected` (creation path); `reactivating_an_entry_is_blocked_once_its_offering_has_been_deactivated`, `reactivating_an_entry_is_blocked_once_its_teacher_has_been_archived` (reactivation path re-runs the identical checks against freshly-loaded parents, per `TimetableScheduleService::activate()`). |
| Elective scheduling bypass | **Rejected, with a dedicated test.** `is_required = false` throws `RequiredSubjectOfferingOnlyException` before any write. | `TimetableScheduleServiceTest::an_elective_offering_is_rejected`. |
| Direct Period/Entry write bypass | **Zero hits outside the two services.** | `grep -rnE "TimetablePeriod::(create|updateOrCreate|insert)|TimetableEntry::(create|updateOrCreate|insert)"` and a grep for `$period->update(`/`$entry->update(`/`->save(`/`->delete(` across `app/`, both excluding the two service files themselves — zero matches. Every other file only ever calls `::query()->where(...)`/`findOrFail(...)` (reads). |
| Overlapping-Period race | **Re-run 3 times total this checkpoint (non-flaky).** | `TimetablePeriodConcurrencyTest` — real two-OS-process test, passed in the full scoped run plus 2 additional standalone re-runs. |
| Teacher/Section/Room double-booking | **Re-run 3 times total this checkpoint (non-flaky).** | `TimetableEntryConcurrencyTest` — same pattern, 3/3 green. |
| Context mismatch (mismatched-context rejection) | **Confirmed via real raw-SQL test.** A Section from a different GradeLevel than its paired SubjectOffering is rejected at the database level by the composite-FK design (§3). | `TimetableEntriesRlsIsolationTest::an_entry_is_rejected_when_the_section_context_does_not_match_the_subject_offering`. |
| Reactivation bypass (full re-validation, not a bare status flip) | **Confirmed by reading the actual code.** `TimetablePeriodService::activate()` re-runs `assertNoOverlap()` under the lock before flipping status; `TimetableScheduleService::activate()` freshly re-loads all 5 parents (`firstOrFail()` on each relation, never the possibly-stale in-memory entry) and re-runs `assertParentsSchedulable()` + `assertPeriodSchedulable()` before flipping status — see `TimetableScheduleService.php` lines 238–262. | `TimetableScheduleServiceTest::reactivation_redetects_a_conflict_that_arose_while_inactive`. |
| Hard-delete history loss | **Confirmed for all 5 parent types, via real raw-SQL DELETE attempts.** Every composite FK is `restrictOnDelete()`. | `TimetableEntriesRlsIsolationTest`: `a_referenced_subject_offering_cannot_be_deleted`, `a_referenced_section_cannot_be_deleted`, `a_referenced_teacher_cannot_be_deleted`, `a_referenced_room_cannot_be_deleted`, `a_referenced_period_cannot_be_deleted` — each attempts a real `DELETE` against the referenced parent row and asserts a `QueryException`/RESTRICT rejection. |
| Status mutation bypass | **Clean.** `status` is explicitly stripped from `update()`'s accepted attributes on `TimetablePeriodService` (`unset($attributes['school_id'], $attributes['status'])`); `TimetableScheduleService::update()` never accepts `status` at all — only `activate()`/`deactivate()` can change it, both audited, both re-validating. | Code inspection, `TimetablePeriodService.php` line 131. |
| Student data accidentally introduced | **Zero hits.** | `grep -rniE "\bstudent\b\|student_id"` across `app/Domain/Timetable` and the Timetable Vue pages returns only two doc-comment lines that explicitly state Timetable has **no** Student relationship (`TimetableEntryController.php`'s "There is no Student roster anywhere in this data model" comment, and `RequiredSubjectOfferingOnlyException`'s comment distinguishing itself from "a Student-level enrollment choice") — no actual `Student`/`student_id` reference, column, or query anywhere. |
| Health/staff-attendance scope creep | **Zero hits.** | `grep -rniE "health\|medical\|biometric\|attendance"` across the same scope returns no matches at all. |

No issue required a fix during this checkpoint's review — every item
above was either already correctly implemented with existing test
coverage, or (helper enumeration, hard-delete history loss) already had
full coverage from the prior phases that this checkpoint independently
re-confirmed rather than re-built.

## 8. Concurrency (all 3 real tests)

All three use `Symfony\Component\Process\Process` to spawn genuinely
separate OS processes (never a sequential simulation within one PHP
process) hitting the same database:

1. **`TimetablePeriodConcurrencyTest`** — two processes attempt to
   create overlapping Periods for the same School simultaneously;
   exactly one succeeds, both processes are proven to have contended
   for `TenantLock::forSchool($school, 'timetable.periods')`.
2. **`TimetableEntryConcurrencyTest`** — two processes attempt to
   schedule the same teacher into the same School/day-of-week/Period
   simultaneously; the database's `timetable_entries_teacher_slot_unique`
   partial index resolves the race — exactly one entry ends up active.
3. **`TimetableEntryVersusPeriodDeactivationConcurrencyTest`** — one
   process creates a TimetableEntry against a Period while the other
   concurrently deactivates that same Period; proves the cross-service
   lock coordination in §4 above actually closes the race (never both
   "entry created" and "period deactivated" succeeding against stale
   state).

All three re-ran green in this checkpoint's own full scoped test run,
plus 2 additional standalone re-runs each (6 additional individual
executions total, all passing) — non-flaky.

## 9. Capabilities

Four capabilities, mirroring Inventory's directory/stock split:

| Capability | Covers |
|---|---|
| `timetable.periods.view` | Read the Period catalogue |
| `timetable.periods.manage` | Create/update/activate/deactivate Periods |
| `timetable.schedule.view` | Read the weekly schedule (TimetableEntry list) |
| `timetable.schedule.manage` | Create/update/activate/deactivate TimetableEntry rows |

Deliberately two **separate** pairs, not one combined pair — curating
the bell schedule (Periods) is a distinct, less frequent concern from
building/adjusting the weekly timetable itself (Entries); holding
`.periods.manage` does not imply `.schedule.manage` or vice versa
(`TimetableCapabilityTest::periods_manage_does_not_imply_schedule_manage`
/ `schedule_manage_does_not_imply_periods_manage`).

Default grants (`database/seeders/CapabilityAndRoleSeeder.php`):
`school_admin` and `principal` both hold **all four** — Timetable has
no analogous "financial account configuration" surface that would
justify withholding a narrower pair from Principal the way Canteen's
`.settings.*` is withheld (Canteen billing configuration names ledger
accounts; nothing in Timetable does), so full parity is granted to
both roles, matching the same day-to-day operational-parity reasoning
already applied to Hostel/Inventory/Canteen/Visitor/Transport/Library.

## 10. The Timetable-owned helper endpoints, and why they exist

`TimetableEntryController` (both API and UI variants) hosts its own
narrow, read-only search/lookup endpoints
(`search/subject-offerings`, `search/sections`, `search/teachers`,
`search/rooms`) plus a Period lookup
(`search/periods`, on `TimetableEntryController` itself, gated by
`timetable.periods.view` rather than `.schedule.*`) — used only by the
Schedule/Create.vue form's pickers.

**The Canteen capability-boundary lesson, carried forward explicitly**:
an earlier module (Canteen) established that a cross-module lookup
endpoint must **never** be gated by the *target* module's own
capability (e.g. gating a SubjectOffering search by
`academics.structure.view`) — doing so would let a Timetable-only
operator either (a) fail to use the picker despite holding
`timetable.schedule.manage`, if they don't also hold an unrelated
Academic Structure/HR capability, or (b) worse, accidentally grant
Academic Structure/HR read access to someone who was only ever meant
to hold Timetable capabilities. Every helper here is gated by
Timetable's **own** capability (`timetable.schedule.manage` for the
four SubjectOffering/Section/teacher/Room lookups, `timetable.periods.view`
for the Period lookup specifically, since a Period is owned by that
other capability pair) and authorizes **before** running its query.
This is proven, not just asserted: `TimetableApiTest`/
`TimetableAdminUiTest`'s
`a_schedule_manage_member_without_academics_or_hr_capability_can_use_the_search_endpoints`
tests grant `timetable.schedule.manage` alone (deliberately withholding
every Academic Structure/HR capability) and confirm every SubjectOffering/
Section/teacher/Room helper still works.

There is no Student relationship anywhere in Timetable's data model —
a `TimetableEntry` connects a SubjectOffering to a Section as a whole,
never to an individual Student, so no Student-search helper exists or
is needed here.

## 11. API surface

Administrative JSON API under `schools/{school}/...`
(`auth:sanctum` + `school-membership`, `capability:` route middleware
on every mutating route + `throttle:school-api-mutations`):

- `GET/POST /timetable-periods`, `GET/PATCH /timetable-periods/{id}`,
  `POST /timetable-periods/{id}/activate|deactivate`
- `GET/POST /timetable-entries`, `GET/PATCH /timetable-entries/{id}`,
  `POST /timetable-entries/{id}/activate|deactivate`
- `GET /timetable-entries/search/{subject-offerings|sections|teachers|rooms|periods}`

None of `create`/`update`/`activate` carry the `idempotent` middleware
— every double-booking effect is already prevented at the database
layer by the three partial unique indexes (§3), so a network retry of
an identical request can never duplicate the scheduling effect; the
worst case is a clean, typed 409 the client already needs to handle
for a genuine conflict anyway (mirrors AcademicYear activation's
identical precedent). `deactivate` is a conditional status flip, safe
to retry by construction, exactly like Hostel residency `end()`.

## 12. UI

Session-authenticated Inertia pages under `/app/timetable-periods` and
`/app/timetable-schedule` (`auth` middleware, capability checks inside
each controller action via `AuthorizesCapability`): Periods
Index/Create, Schedule Index/Create. Dashboard navigation
(`DashboardController`) exposes `canViewTimetablePeriods`/
`canViewTimetableSchedule` flags, each independently derived from the
matching `.view` capability — a user with neither sees neither link.

## 13. Audit

Every mutation is audited via `AuditRecorder::school()`, actor +
subject + relevant metadata, matching every other module's pattern:
`timetable.period.created`, `timetable.period.updated`,
`timetable.period.deactivated`, `timetable.period.activated`,
`timetable.entry.created`, `timetable.entry.updated`,
`timetable.entry.deactivated`, `timetable.entry.activated`.

## 14. RLS / composite-FK table-by-table inventory

| Table | RLS | Composite FKs (structural, RESTRICT) |
|---|---|---|
| `timetable_periods` | `TenantRls::enable('timetable_periods')` | — (School-owned leaf reference entity) |
| `timetable_entries` | `TenantRls::enable('timetable_entries')` | `timetable_entries_subject_offering_fk` → `subject_offerings(id, school_id, academic_year_id, campus_id, grade_level_id)`; `timetable_entries_section_fk` → `sections(id, school_id, academic_year_id, campus_id, grade_level_id)`; `timetable_entries_teacher_fk` → `employees(id, school_id)`; `timetable_entries_room_fk` → `rooms(id, school_id)`; `timetable_entries_period_fk` → `timetable_periods(id, school_id)` |

Both tables also carry the standard `unique(['id', 'school_id'])`
composite-FK target and a plain `school_id` index. All 5 RESTRICT FKs
on `timetable_entries` are independently proven in
`TimetableEntriesRlsIsolationTest` (§7's hard-delete row).

## 15. Historical integrity

No DELETE route exists for any Timetable entity. Deactivation
(`status = 'inactive'`) is the only lifecycle transition below
"active," and the row is always kept — never physically removed. This
extends CLAUDE.md rule 73's reference-entity convention to
`timetable_entries` even though it is a transactional-shaped table (one
row per scheduled slot, not pure reference data): a deactivated entry
remains readable/auditable indefinitely
(`an_existing_entry_remains_readable_after_its_offering_is_deactivated`).

## 16. Test summary

Scoped Timetable suite (this checkpoint's own re-run):
**110 tests, 298 assertions, 0 failures.** Command:

```
php -d memory_limit=1024M vendor/bin/phpunit \
  tests/Feature/Timetable tests/Feature/Authorization/TimetableCapabilityTest.php \
  tests/Feature/Authorization/TimetableServiceAuthorizationTest.php \
  tests/Feature/Postgres/TimetableEntriesRlsIsolationTest.php \
  tests/Feature/Postgres/TimetablePeriodsRlsIsolationTest.php \
  tests/Feature/App/TimetableAdminUiTest.php
```

(The prior UI-phase report recorded 299 assertions for the same suite;
this checkpoint's re-run recorded 298. The one-assertion difference was
not chased down further — no test failed, and every individual test's
own name/count matched the prior report exactly test-for-test; this is
noted for transparency rather than silently reproduced as an exact
match.)

Breakdown by file: `TimetablePeriodServiceTest` (unit-level service
behavior), `TimetableScheduleServiceTest` (unit-level service
behavior, largest file — every parent-eligibility/conflict/
reactivation case), `TimetablePeriodConcurrencyTest`/
`TimetableEntryConcurrencyTest`/
`TimetableEntryVersusPeriodDeactivationConcurrencyTest` (3 real
two-process races), `TimetableCapabilityTest`/
`TimetableServiceAuthorizationTest` (capability grants + service-level
authorization), `TimetablePeriodsRlsIsolationTest`/
`TimetableEntriesRlsIsolationTest` (RLS + composite-FK + RESTRICT-
deletion proofs), `TimetableApiTest` (JSON API surface, IDOR, helper
capability boundaries), `TimetableAdminUiTest` (Inertia UI surface,
identical helper capability boundaries).

## 17. Explicit non-scope list (Phase 0H.1)

Copied forward from the original architecture gate's non-scope
declaration — none of the following exist in this checkpoint, and none
should be inferred from anything above:

- Dated/session-level scheduling (make-up classes, substitute teachers
  for a single day, term-boundary/holiday exceptions) — §2.
- Elective scheduling (a `TimetableEntry` per-Student or per-elective-
  group, rather than per-Section) — §3.
- Attendance (Student class attendance is Phase 0H's own separate,
  not-yet-started scope; **Staff/Employee attendance is not part of
  Phase 0H at all** — see `docs/roadmap/MASTER-ROADMAP.md`'s Phase 0H
  section for the exact clarification).
- Academics (curriculum delivery, lesson planning, syllabus tracking)
  and Examinations (exam scheduling, grading, report cards) — both
  remain entirely unbuilt.
- Conflict-detection/reporting UI beyond the synchronous create-time
  rejection (no "show me all conflicts" dashboard).
- Publishing/versioning a timetable (no draft-vs-published state, no
  timetable "templates").
- Room-type/capacity-aware scheduling suggestions (Room eligibility is
  a plain active/inactive check, nothing richer).
- Any AI-Gateway-exposed Timetable tool.
- Any Guardian/Student-facing timetable view.
- Bell-schedule variants (e.g. a different Period set on different
  days of the week) — one Period catalogue applies uniformly across
  all seven days.

## Retention (E21.3D, 2026-10-02)

A timetable entry is deleted 7 calendar years after the end of its Academic
Year (`timetable_entries.academic_year_id`) by
`platform:academic-retention-prune`, but only once no attendance register
header references it. Its teacher reference goes with it, never earlier.
Periods, rooms and other reusable configuration are not year-bound and stay.
A current or future year is never eligible.

Project-adopted, pending legal ratification (`docs/security/E21-RETENTION-DETERMINATION.md` §5.8).
