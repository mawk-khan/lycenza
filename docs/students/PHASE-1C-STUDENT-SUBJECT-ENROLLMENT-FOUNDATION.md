# Student Subject Enrollment / Elective Placement Foundation (Phase 1C.1)

## 1. Roadmap placement

`docs/roadmap/MASTER-ROADMAP.md` sequences work at the coarse Phase
0A-0O level and does not itself enumerate the finer-grained checkpoint
numbering (`1A`, `1B`, `1B.1`-`1B.7B`, `5A`, `5A.1`-`5A.12`, `5B`,
`5B.1`-`5B.3`, ...) that this project's own `docs/students/` and
`docs/communication-hub/` checkpoint documents use in practice — that
finer numbering lives entirely in those module-level documents, not in
the master roadmap.

Within that established informal sequence, `docs/modules/STUDENT-ENROLLMENT.md`
records Phase 1A as Student/Guardian identity and Phase 1B (with
sub-checkpoints 1B.1 through 1B.7B) as Grade/Section/AcademicYear
academic placement — both owned by `app/Domain/Students`. This
checkpoint is a **new, distinct top-level checkpoint in that same
domain's sequence — Phase 1C** — because Subject-level placement is a
genuinely different concern from Grade/Section placement (it
supplements, never replaces, `StudentEnrollment`; see section 26), not
a Phase 1B sub-checkpoint. It is filed under `docs/students/` (not
`docs/academic-structure/`, which does not exist as a directory in this
repository) because the write model, ownership, and dependency
direction all belong to `app/Domain/Students`, matching `StudentEnrollment`'s
own precedent — see section 7.

Master roadmap placement: this remains inside Phase 0F ("People") in
`docs/roadmap/MASTER-ROADMAP.md`, which already covers Students/SIS at
the coarse level; no roadmap text changed as a result (see
`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md`'s own
history — the master roadmap has never been edited for any 1A/1B/5A/5B
sub-checkpoint either, so not editing it here preserves, rather than
breaks, existing convention).

## 2. Objective

Create the Academic-domain foundation that answers "which Students are
actually assigned/enrolled in this SubjectOffering?" — a dependency
discovered missing during the Phase 5C.1 Communication checkpoint
(`docs/communication-hub/` -- that checkpoint correctly stopped rather
than inventing a shadow relationship). This checkpoint is **not**
Communication feature work: no `CommunicationAudienceResolver` was
added, `AnnouncementService` was not touched, and Phase 5C.1 itself was
not resumed.

## 3. Dependency discovered by Phase 5C.1

Phase 5C.1's audit found: `subject_offerings` (Phase 0D) is a
Grade-level offering *definition* (Subject × GradeLevel × Campus ×
AcademicYear) with `is_required` as its only per-Student-relevant flag
— it never had, and did not gain in Phase 0D, any table containing both
a Student id and a SubjectOffering id. Communications correctly refused
to infer a roster from Section/GradeLevel proxying.

## 4. Existing SubjectOffering semantics

`app/Domain/AcademicStructure/Infrastructure/SubjectOffering.php` /
`subject_offerings`: `school_id`, `academic_year_id`, `campus_id`,
`grade_level_id`, `subject_id`, `is_required` (bool), `sequence`,
`weekly_periods_target`, `status`. AcademicYear-specific by
construction (Phase 0D section 38) — never a global Grade→Subject map.
No teacher/staff assignment exists on this model today (out of scope
for this checkpoint — see section 27).

## 5. Existing StudentEnrollment semantics

`app/Domain/Students/Infrastructure/StudentEnrollment.php` /
`student_enrollments` (Phase 1B.1): `student_id`, `academic_year_id`,
`campus_id`, `grade_level_id`, `section_id` (all denormalized off
`section_id`, server-derived), `status`
(active|completed|withdrawn|transferred|cancelled), `starts_on`/`ends_on`.
Partial unique index guarantees at most one active row per Student per
AcademicYear. This checkpoint's `StudentSubjectEnrollment` reads this
table at write AND read time but never writes to it, and
`StudentEnrollmentService` was not modified.

## 6. Chosen Student-level subject enrollment model

Evaluated three shapes (brief's own framing): (A) an explicit row for
every offering including mandatory ones; (B) Grade-level implicit
curriculum + explicit Student choices/overrides for electives only;
(C) a first-class `TeachingGroup` roster entity. **Chose (B)**: the
existing `subject_offerings.is_required` column is direct evidence the
Academic domain already models "some offerings apply to every Student
in the Grade, some don't" — building (A) would create a large, purely
derivable duplication of `student_enrollments` for every mandatory
Subject (rejected per CLAUDE.md rule 2 and the brief's own explicit
instruction); (C) would introduce a second, more generic grouping
concept than the product currently needs (rejected per the brief's
"remain foundational, not a generic LMS/course engine" instruction,
section 17).

## 7. Why this model is Academic-owned, not Communications-owned

`StudentSubjectEnrollment` and its read model
(`SubjectOfferingRosterReadService`) live in `app/Domain/Students`
(not `app/Domain/AcademicStructure`) because the roster query joins
`student_enrollments` (Students-owned) with `student_subject_enrollments`
(this checkpoint, Students-owned) — placing it in AcademicStructure
would make AcademicStructure depend on Students, inverting the
established dependency direction (`StudentEnrollment` already
references AcademicStructure entities, never the reverse — root
CLAUDE.md rule 4). The dependency direction for a *future* Phase 5C.1
attempt is therefore: **Communications → this Academic/Students roster
read model**, never the reverse — this checkpoint has zero references
to any Communications type, and Communications must never be depended
on by Academic code (brief section 23).

## 8. Mandatory vs elective semantics

A `is_required = true` SubjectOffering **never** gets an explicit
`StudentSubjectEnrollment` row — attempting one throws
`RequiredSubjectOfferingEnrollmentException`. Every Student whose
current active `StudentEnrollment` matches that offering's
AcademicYear/GradeLevel/Campus is implicitly enrolled, derived only at
read time. A `is_required = false` (elective) offering's roster is
exclusively the Students with an active `StudentSubjectEnrollment` row
— "no row" means "not enrolled in this elective", never "eligible but
unenrolled" (no ambiguity, per the brief's explicit instruction not to
leave this undefined).

## 9. Current roster semantics

One authoritative method,
`SubjectOfferingRosterReadService::currentRosterStudentIds()`/
`currentRosterCount()`, branches on `is_required`:
- **Required**: `student_enrollments` WHERE `status = 'active'` AND
  `academic_year_id`/`grade_level_id`/`campus_id` match the offering.
- **Elective**: `student_subject_enrollments` WHERE `status = 'active'`
  AND `subject_offering_id` matches, **re-validated** via a live
  `whereExists` join back to the Student's current active
  `student_enrollments` row (never trusted from write time — see
  section 26).

## 10. Lifecycle / history

`StudentSubjectEnrollmentService` mirrors `StudentEnrollmentService`'s
shape exactly: `enroll()` (create, active), `withdraw()`/`cancel()`
(terminal transitions, historical row preserved, never deleted),
`transfer()` (elective switch — atomic two-row operation: source marked
`transferred` with a derived `ends_on`, a brand-new `active` row
created for the target offering). No terminal status transitions again.
A duplicate active membership in the identical offering is rejected by
a PostgreSQL partial unique index
(`student_subject_enrollments_one_active_per_offering`), translated to
`ActiveSubjectEnrollmentConflictException` — never check-then-insert.

## 11. AcademicYear

`academic_year_id` is a required, denormalized column (mirroring
`student_enrollments`' own denormalization off `section_id`) — always
derived server-side from the caller-supplied `SubjectOffering`, never
accepted as independent caller input. History cannot bleed across
years because every `StudentSubjectEnrollment` row is permanently tied
to the one AcademicYear its SubjectOffering belongs to.

## 12. GradeLevel/Campus/Section compatibility

`StudentSubjectEnrollmentService::assertCompatible()` requires the
Student to have a CURRENT active `StudentEnrollment` whose
`academic_year_id`/`grade_level_id`/`campus_id` all match the chosen
SubjectOffering — checked at write time (`IncompatibleSubjectOfferingException`
on mismatch) and **independently re-derived at read time** (section 9's
`whereExists` join) so a later incompatible `StudentEnrollment` change
is caught without touching this table at all (section 26).
`section_id` is deliberately not part of this compatibility check —
SubjectOffering is Section-agnostic by design (Phase 0D), so a Student
in any compatible Section of the matching Grade/Campus/Year is
eligible.

## 13. SubjectOffering relationship

Exactly one `SubjectOffering` per `StudentSubjectEnrollment` row
(`subject_offering_id`, composite `(id, school_id)` FK, restrict on
delete — `SubjectOffering` has no delete endpoint per CLAUDE.md rule
73).

## 14. Teacher/teaching-group decision

No teacher/staff assignment exists on `SubjectOffering` today and none
was added here — out of scope (brief section 18/27). No distinct
`TeachingGroup` entity was created; `SubjectOffering` itself is the
canonical grouping this checkpoint resolves against (section 6).

## 15. Write service

`App\Domain\Students\Application\StudentSubjectEnrollmentService` — the
ONLY sanctioned write path (`enroll()`, `withdraw()`, `cancel()`,
`transfer()`). Authorization-neutral, matching every other Application
service in this codebase; the controller enforces
`academics.subjects.manage` before ever reaching it.

## 16. Roster read model

`App\Domain\Students\Application\SubjectOfferingRosterReadService` —
the literal future Phase 5C.1 dependency contract: `SubjectOffering →
current eligible Student roster`, returning Student ids/counts only.
Set-based (a single query per method — proven bounded in the
query-count/scale test, section 21), never a per-Student loop.

## 17. Authorization

Reuses the existing `academics.subjects.view`/`academics.subjects.manage`
capabilities (already granted to `school_admin`/`principal` for
"Subjects and Subject Offerings") rather than inventing a new
capability pair — Student-level subject placement is a direct extension
of that same concern, not a new one. No Communication capability was
touched or granted here. Role-name checks are forbidden throughout
(root CLAUDE.md rule 24) — every check goes through
`AuthorizesCapability::authorizeCapability()`.

## 18. RLS

`student_subject_enrollments` uses `App\Support\Tenancy\TenantRls::enable()`
(RLS enabled AND forced) — proven in
`StudentSubjectEnrollmentIntegrityTest` (raw SQL, `school_os_app`
runtime role): no School context sees zero rows, School A cannot
read/write School B's row, and composite FKs reject a cross-School
Student/SubjectOffering/AcademicYear reference at INSERT time even
under `pgsql_admin` (bypassing RLS's own WITH CHECK).

## 19. Audit

`AuditRecorder::school()` records `student_subject_enrollment.created`/
`.withdrawn`/`.cancelled`/`.transferred` with Student id, SubjectOffering
id, AcademicYear id, and lifecycle end date only — never a roster
listing, never unrelated Student personal information.

## 20. Performance/indexes

`(school_id, student_id)`, `subject_offering_id`, `academic_year_id`,
`status` indexes on the new table. The roster read query is a single
`SELECT ... WHERE ... [EXISTS (...)]` per call regardless of roster
size — proven bounded (< 5 queries) for a 60-Student elective roster in
`SubjectOfferingRosterReadServiceTest::roster_resolution_stays_bounded_for_a_realistic_offering_size`.

## 21. Assessment/timetable compatibility

Searched `app/Domain/AcademicStructure`, `app/Domain/Students`, and
`docs/modules/` for any existing Assessment/Gradebook/Timetable code
assuming "every Student in a Grade/Section takes every SubjectOffering"
— **none exists yet** (no Assessment/Gradebook/Timetable module has
been built as of this checkpoint; Phase 0H "Academic Operations" in
`docs/roadmap/MASTER-ROADMAP.md` remains unstarted). No compatibility
change was required. Documented here as a forward note: a future
Assessment/Timetable module targeting a SubjectOffering's Students
should consume `SubjectOfferingRosterReadService`, exactly like a
future Phase 5C.1 attempt would, rather than re-deriving the
required-vs-elective distinction itself.

## 22. Rollover behavior

Not addressed here, deliberately deferred — `student_subject_enrollments`
is scoped to one AcademicYear via its `academic_year_id` column exactly
like `student_enrollments` is, so a future year-rollover checkpoint
(mirroring Phase 1B.7A's `EnrollmentRolloverPlan` machinery) can extend
to Subject-level placement without a redesign, but doing so is out of
scope for this foundational checkpoint. **Architecture defined in
`docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md`
(Phase 1G.0, informal module-local checkpoint label) — not yet
implemented as of this writing.**

## 23. Tests

- `tests/Feature/Postgres/StudentSubjectEnrollmentIntegrityTest.php` —
  8 tests: RLS enabled/forced, no-context isolation, cross-School read/
  write denial, cross-School create denial (RLS WITH CHECK), and three
  composite-FK cross-School reference rejections (Student, SubjectOffering,
  AcademicYear).
- `tests/Feature/StudentSubjectEnrollment/StudentSubjectEnrollmentServiceTest.php` —
  12 tests: valid enrollment, required-offering rejection, four
  incompatibility dimensions (no enrollment / wrong grade / wrong year /
  wrong campus), cross-School rejection, duplicate-active rejection,
  withdraw/cancel history preservation, terminal-status re-transition
  rejection, elective switch (source preserved historical, target
  active, no ambiguous duplicate).
- `tests/Feature/StudentSubjectEnrollment/SubjectOfferingRosterReadServiceTest.php` —
  8 tests: required-offering implicit roster (correct inclusion/
  exclusion/withdrawal), required offering never touches
  `student_subject_enrollments`, elective explicit roster, withdrawn
  elective exclusion, dynamic exclusion on incompatible
  `StudentEnrollment` change for BOTH required and elective offerings
  (with an explicit assertion the elective row itself is never
  mutated), deduplication, and the bounded query-count scale test.
- `tests/Feature/StudentSubjectEnrollment/StudentSubjectEnrollmentApiTest.php` —
  8 tests: guest denial, `academics.subjects.view`/`.manage`
  independence, no-membership 404-not-403, cross-School forgery
  rejection (both on enroll and on roster lookup), and a response-shape
  test proving no grade/attendance/fee/medical field ever appears in
  the roster payload.

**36 new tests, 57 new assertions total**, stable across 3 consecutive
isolated runs and across two consecutive full-suite runs (full suite:
1420 tests / 4332 assertions, up from the prior 1384/4271 baseline —
exactly +36 tests matching the new test count).

## 24. Safety

`COMMUNICATION_EMAIL_ENABLED` untouched (still `false` by default,
`.env.example` unchanged). No SMS/WhatsApp/Push/provider work. No
staging/production changes, no deployment. No destructive database
operation was run outside the canonical `platform:test-db-reset --force`
path and explicit, positively-verified `pgsql_admin` migrate/rollback
invocations against `school_os_test` only (root CLAUDE.md rules 50-54).

## 25. Migration proof

Fresh-from-zero `platform:test-db-reset --force` (89+1 = 90 total
migrations, all `DONE`, zero ordering/FK/RLS issues). Additionally
proved the new migration's `down()` specifically: rolled back 13
migrations (through and including `2026_08_24_100000_create_student_subject_enrollments_table`)
via `migrate:rollback --database=pgsql_admin`, confirmed
`student_subject_enrollments` was dropped cleanly (`\dt` returns no
relation), then reapplied all 13 forward cleanly — full suite re-run
clean afterward (1420 tests / 4332 assertions).

## 26. Exact contract exposed for future Phase 5C.1

```php
App\Domain\Students\Application\SubjectOfferingRosterReadService

public function currentRosterStudentIds(SubjectOffering $offering): array; // string[] Student ids
public function currentRosterCount(SubjectOffering $offering): int;
```

Both methods are School+AcademicYear-scoped by construction (via the
`$offering` argument's own `school_id`/`academic_year_id`), branch
internally on `is_required` so a caller never needs to know
required-vs-elective semantics, and are resolved fresh on every call —
**never cached, never a materialized list**. A future Communication
resolver hands `$offering->id` (or the `SubjectOffering` model) to this
service and receives Student ids only; it then performs the identical
Guardian-projection / Guardian-eligibility-predicate / IN_APP / EMAIL
reachability steps `GradeAudienceResolver`/`SectionAudienceResolver`
already established in Phase 5B.3, unmodified. **Membership in a
SubjectOffering does not create authenticated account identity — IN_APP
still requires an explicit, currently-active `StudentGuardianAccountLink`,
exactly like Grade/Section academic-cohort audiences already require**
(proven directly relevant here by
`SubjectOfferingRosterReadServiceTest`'s dynamic-exclusion tests, which
demonstrate the roster and `StudentEnrollment` stay independently
correct with zero coupling to any account-link/reachability code).

## 27. Deferred work

- Timetable-based inferred rosters, clubs, houses, transport, hostel
  groups, attendance-derived audiences, fee-status audiences — none
  implemented (explicitly out of scope).
- Guardian/Student portals, account provisioning, conversation
  participation — untouched.
- Elective-group mutual exclusivity (e.g. "French OR Spanish, never
  both") — no such grouping concept exists in `Subject`/`SubjectOffering`
  yet; `student_subject_enrollments_one_active_per_offering` only
  prevents a duplicate active row in the SAME offering, not membership
  across two related offerings. A future checkpoint introducing an
  elective-group concept on `Subject`/`SubjectOffering` would need to
  add its own exclusivity enforcement. **Architecture defined in
  `docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md`
  (Phase 1F.0, informal module-local checkpoint label) — not yet
  implemented as of this writing.**
- Teacher/staff assignment to a SubjectOffering, and any
  teacher-scoped authorization restriction on the roster endpoints —
  not built; current authorization is School-wide
  `academics.subjects.view`/`.manage`, matching current product reality
  (no teacher-assignment data exists to restrict against).
- A dedicated Vue/Inertia admin UI for roster management (Subject
  Offering detail → roster, add/remove/switch-elective actions) was
  **not built in this checkpoint** — the JSON API surface
  (`StudentSubjectEnrollmentController`) is complete and tested, but no
  frontend page consumes it yet, mirroring Phase 0D's own precedent of
  shipping a tested API ahead of its UI page (`docs/roadmap/MASTER-ROADMAP.md`
  Phase 0D's "Deliberately deferred" note). A future checkpoint can add
  the page following the same pattern `StudentEnrollmentController`'s
  existing Inertia pages already establish. **Architecture defined in
  `docs/students/PHASE-1H-0-ELECTIVE-ADMINISTRATION-UI-ARCHITECTURE.md`
  (Phase 1H.0, informal module-local checkpoint label) — not yet
  implemented as of this writing.**
- Year-rollover integration (section 22, architecture now defined in
  Phase 1G.0 — see section 22 above).

## 28. Next recommendation

With this checkpoint's contract in place, the natural next step is a
**second Phase 5C.1 attempt** — the original checkpoint should now be
re-run, consuming `SubjectOfferingRosterReadService::currentRosterStudentIds()`/
`currentRosterCount()` exactly as section 26 describes, reusing
`GuardianProjectionResolver` and the existing IN_APP/EMAIL reachability
pipeline unchanged. This checkpoint deliberately does not start that
work itself.

## 29. Phase 1C.1A — SubjectOffering eligibility guard (correction)

Discovered during Phase 1C's fresh-integration gate: this checkpoint's
original write path (`StudentSubjectEnrollmentService::enroll()`/
`transfer()`) never consulted `SubjectOffering.status`, contradicting
this codebase's own established "deactivate, never delete"
reference-entity convention (`docs/modules/ACADEMIC-STRUCTURE.md`,
"Reference-data lifecycle") and the identical precedent
`App\Domain\HR\Application\EmployeeAssignmentService` already enforces
for Position/Department. An inactive `SubjectOffering` could silently
accept brand-new Student participation.

**The corrected rule**: a `SubjectOffering` must be active
(`isActive()`) to become the TARGET of new participation.

- `enroll()`: rejects an inactive offering with
  `InactiveSubjectOfferingException` (422), checked immediately after
  the cross-School check and before the required/elective check (a
  foreign-School offering's status must never leak through exception
  ordering).
- `transfer()`: the same check applies to the TARGET offering only —
  never the source. A Student already actively participating in an
  offering that later becomes inactive can still be moved OUT of it.
- `withdraw()`/`cancel()`: **never gated** on offering status — a
  terminal transition against an existing membership must always
  remain possible regardless of the offering's current status.

**Roster contract**: `SubjectOfferingRosterReadService::currentRosterStudentIds()`/
`currentRosterCount()` return `[]`/`0` for an inactive offering, for
BOTH required and elective offerings, short-circuiting before any
query runs — an inactive offering must never surface as a
Communications audience (section 26's own consumer contract). This is
a read-time current-eligibility gate only: no `StudentSubjectEnrollment`
row is deleted, mutated, or auto-withdrawn when an offering becomes
inactive, and reactivating the offering restores the ordinary roster
derivation with no other state change required.

No migration, no new capability, and no change to
required-vs-elective semantics were needed — this is purely an
application-layer eligibility check plus a read-side short-circuit.
