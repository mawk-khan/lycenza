# Phase 5B.3 — Academic Cohort Communication Audiences

## 1. Objective

Let a School target a Grade or a Section as an Announcement audience,
resolved against Phase 1B's `student_enrollments` — the School's only
authoritative record of academic placement — without inventing a
second enrollment model, without freezing membership at scheduling
time, and without adding any new reachability mechanism beyond what
Phase 5B.1/5B.2 already established for Student/Guardian audiences.

## 2. Dependency state at the start of this checkpoint

Phase 5B.3 was attempted once before this checkpoint and stopped with
no code written: at that point, `Student` had zero connection to
`GradeLevel`/`Section`/`AcademicYear` anywhere on this branch — that
work lived only in an unmerged Phase 1B worktree. A dedicated
integration gate (`docs/students/PHASE-1B-FINAL-INTEGRATION.md`) and a
dependency-reconciliation gate
(`docs/communication-hub/PHASE-5B-DEPENDENCY-RECONCILIATION.md`) landed
Phase 1B's `StudentEnrollment`/`AcademicYear`/`GradeLevel`/`Section`
model into this branch's `main` ancestry before this checkpoint began.
This checkpoint builds on that integrated state only — it does not
touch the separate, later, deliberately-excluded Phase 1B
rollover-HTTP commit (`7b3f5c7`), which is unrelated to academic-cohort
messaging.

## 3. StudentEnrollment is the sole source of truth

**`App\Domain\Students\Infrastructure\StudentEnrollment` is the only
place Communications reads academic placement from. Communications
never maintains a second, shadow enrollment table or cache of "who is
in this Grade/Section."** `GradeAudienceResolver` and
`SectionAudienceResolver` both query `student_enrollments` directly
(scoped to `school_id` + `status = 'active'`) every time they resolve
— there is no intermediate materialized membership list anywhere in
this checkpoint's schema.

## 4. Resolved fresh at every publication, never frozen at scheduling time

**A Grade/Section audience is resolved fresh every time it is
evaluated — at draft preview, at manual "Publish Now," and at a
scheduler-driven due-publication — never fixed to whoever was enrolled
when the announcement was drafted or scheduled.** Nothing about the
resolver's query depends on when it runs; `AnnouncementService::publish()`
calls the same `CommunicationAudienceResolverRegistry::resolve()` path
for a Grade/Section audience as it does for any other audience type,
and that call always executes against the current database state. A
Student who transfers out of the targeted Grade between scheduling and
the due-publish moment is excluded; one who transfers in during that
window is included. See
`Tests\Feature\Communications\AcademicCohortAudienceResolverTest::a_student_who_leaves_the_grade_before_publication_is_excluded_at_publish_time`
and its `_joins_` counterpart.

## 5. Enrollment never implies account identity

**A Student or Guardian's academic-placement record
(`StudentEnrollment`, `Section` membership) and their IN_APP
reachability (`App\Domain\Identity\Infrastructure\StudentGuardianAccountLink`)
are governed by two completely independent mechanisms.** Being
enrolled in a targeted Grade/Section makes a Student/Guardian a member
of the resolved AUDIENCE — it grants no login, no account, and no
delivery by itself. IN_APP delivery for a Grade/Section audience
reuses `AnnouncementService::deliverInAppForLinkedDomainParty()`
completely unchanged from Phase 5B.2: an enrolled-but-unlinked party
receives zero IN_APP deliveries and a `recipient_ineligible` policy
decision, exactly like an unlinked Student/Guardian targeted directly.
See
`Tests\Feature\Communications\AcademicCohortAudienceResolverTest::an_enrolled_but_unlinked_student_in_a_grade_audience_receives_no_in_app_delivery`.

## 6. No independent "Class" entity

No `Class` model exists anywhere in this codebase, and this checkpoint
does not create one. The two academic-cohort audience types are
`Grade` (`App\Domain\AcademicStructure\Infrastructure\GradeLevel`) and
`Section` (`App\Domain\AcademicStructure\Infrastructure\Section`) —
Section is structurally the "class"-equivalent grouping in this
domain model (one specific Grade, one specific Campus, one specific
AcademicYear, a roster of currently-enrolled Students), and no
separate entity was fabricated to represent it under a different name.

## 7. GradeLevel audience: why `academic_year_id` is mandatory

`GradeLevel` is School-wide reference data — reusable across every
AcademicYear, never itself AcademicYear-scoped (Phase 0D). A
`grade_level_id` alone therefore cannot identify a specific cohort of
currently-enrolled Students: "Grade 5" means a different, disjoint set
of Students in 2026-27 than in 2027-28. `AcademicCohortSelection`
requires `academic_year_id` alongside `grade_level_id` for a `grade`
audience, and `GradeAudienceResolver` filters `student_enrollments` on
both columns together.

## 8. Section audience: `academic_year_id` as defense-in-depth

A `Section` already belongs to exactly one `AcademicYear` structurally
(`sections.academic_year_id`, Phase 0D §28's historical-safety rule —
"Grade 5 A" in 2026-27 and in 2027-28 are permanently distinct rows).
A `section_id` is therefore, by itself, already unambiguous. This
checkpoint still requires and validates `academic_year_id` for a
`section` audience anyway, purely as defense-in-depth: `syncAcademicCohort()`
cross-checks the caller-supplied `academic_year_id` against the
Section's own `academic_year_id` and rejects a mismatch with
`InvalidAcademicCohortException`, so a caller can never pair a real
Section with the wrong year by mistake. `SectionAudienceResolver` also
filters on both columns for the same reason.

## 9. Composable model, not a resolver explosion

`CommunicationAudienceType` gained exactly two new cases — `Grade` and
`Section` — not four. A naive design would have added
`GuardiansOfGrade`/`GuardiansOfSection` alongside `Grade`/`Section`,
mirroring Phase 5B.1's `Student`/`Guardian`/`GuardiansOfStudents`
three-case shape. Instead, `recipient_kind`
(`CommunicationAcademicCohortRecipientKind::Student`/`::Guardian`) is a
field on the single per-announcement
`communication_announcement_academic_cohorts` row: one cohort
DEFINITION (type, year, grade-or-section), one recipient-kind switch
that decides whether `GradeAudienceResolver`/`SectionAudienceResolver`
project the resolved Students directly or project their eligible
Guardians. This keeps the audience-type enum, the CHECK constraint list
it drives, and the resolver registry all at a fixed size regardless of
how many recipient projections a cohort audience ever needs.

## 10. Schema

Two migrations:

- Widens `communication_announcements_audience_type_check` to add
  `'grade'`/`'section'` — additive to the existing CHECK constraint,
  matching the precedent set by Phase 5B.1's own widening migration.
- Creates `communication_announcement_academic_cohorts` (RLS-enabled
  via `App\Support\Tenancy\TenantRls`): `id`, `school_id`,
  `announcement_id` (UNIQUE — exactly one cohort row per Announcement),
  `cohort_type` (`grade_level`/`section`), `academic_year_id` (always
  required), `grade_level_id`/`section_id` (nullable, exactly one set
  via `num_nonnulls(grade_level_id, section_id) = 1`), `recipient_kind`
  (`student`/`guardian`). `announcement_id`/`academic_year_id`/
  `grade_level_id`/`section_id` are all composite-FK'd against
  `(id, school_id)` on their respective parent tables (root CLAUDE.md
  rule 70) — a cross-School reference is rejected at INSERT time by the
  database itself, not merely by application logic. A CHECK constraint
  additionally ties `cohort_type` to which of `grade_level_id`/
  `section_id` is actually set, so the two can never drift apart.

Only the cohort DEFINITION is ever persisted here — no resolved
Student/Guardian id is stored on this table or anywhere else by this
checkpoint (§4).

## 11. Domain layer

- `CommunicationAcademicCohortType` (`GradeLevel`/`Section`),
  `CommunicationAcademicCohortRecipientKind` (`Student`/`Guardian`) —
  new enums.
- `CommunicationAudienceType::Grade`/`::Section` — new cases (§9).
- `CommunicationAnnouncementAcademicCohort` — the Eloquent model for
  the new table, plus a new `CommunicationAnnouncement::academicCohort(): HasOne`
  relation (parallel to the existing `domainAudienceMembers(): HasMany`
  relation Phase 5B.1 added).

## 12. Application layer

- `AcademicCohortSelection` — an immutable DTO carrying the
  caller-supplied cohort choice (`cohortType`, `academicYearId`,
  `gradeLevelId`, `sectionId`, `recipientKind`) from the HTTP layer
  into `AnnouncementService`.
- `InvalidAcademicCohortException` — thrown whenever a selection fails
  validation (see §13); deliberately generic message ("That
  Grade/Section could not be found for this School and academic
  year"), never distinguishing "doesn't exist" from "exists in another
  School" (root CLAUDE.md's existence-leakage discipline, matching
  Phase 5B.1's equivalent domain-audience exception).
- `AnnouncementService::createDraft()`/`updateDraft()` both gained a
  final `?AcademicCohortSelection $academicCohort = null` parameter.
  `createDraft()` calls `syncAcademicCohort()` unconditionally whenever
  the chosen audience type is `grade`/`section` — a null selection
  there fails closed with `InvalidAcademicCohortException` rather than
  silently creating an announcement with no resolvable audience.
  `updateDraft()` only calls it when `$academicCohort !== null`,
  matching the established "null means leave the current selection
  untouched" convention `domainAudienceMemberIds` already uses.
- `AnnouncementService::syncAcademicCohort()` — the sole write path.
  Validates that the given AcademicYear/GradeLevel-or-Section ids
  genuinely belong to the announcement's School (root CLAUDE.md rule
  19: an id alone is never authorization — the same discipline
  `SsrfSafeUrlValidator` applies to a caller-supplied URL, applied here
  to a caller-supplied foreign id), cross-checks Section↔AcademicYear
  consistency (§8), then deletes and re-inserts the single cohort row
  for the announcement — the same delete-then-insert shape
  `syncDomainAudienceMembers()`/`syncAudienceMembers()` already
  established for the other audience types.
- `GuardianProjectionResolver` — a new shared service extracted from
  Phase 5B.1's `GuardiansOfStudentsAudienceResolver`, implementing
  the Guardian-eligibility predicate (`is_primary OR is_legal_guardian`)
  and deduplication exactly once. `GuardiansOfStudentsAudienceResolver`
  was refactored to delegate to it instead of inlining the query a
  second time; `GradeAudienceResolver`/`SectionAudienceResolver` are
  the third and fourth callers. There is now exactly one implementation
  of "which Guardians are eligible for a set of Students" in this
  codebase, never triplicated.
- `GradeAudienceResolver`/`SectionAudienceResolver` — implement
  `CommunicationAudienceResolver`. Each: loads the announcement's
  single cohort row, queries `student_enrollments` for active
  enrollments matching the cohort definition, and either returns those
  Student ids directly (`recipient_kind = student`) or hands them to
  `GuardianProjectionResolver` (`recipient_kind = guardian`). Neither
  resolver writes anything — resolution is pure read, matching every
  other `CommunicationAudienceResolver` in this codebase. Both
  registered in `PlatformServiceProvider`'s
  `CommunicationAudienceResolverRegistry` binding.

## 13. Authorization and cross-School forgery resistance

Every academic-cohort authoring/search action is gated by
`communications.announce` alone — never `academics.structure.view`/
`.manage`. An announcer who cannot see the Academic Structure admin
module can still target a Grade/Section by name; the search endpoints
return only minimal safe identity (name, code, and for Section its
parent Grade's name), matching the existing Student/Guardian search
endpoints' "narrow, purpose-scoped read" precedent
(`CommunicationAudienceSearchController`).

A cross-School AcademicYear/GradeLevel/Section id — whether supplied
through the HTTP composer form or directly to
`AnnouncementService::createDraft()`/`updateDraft()` — is rejected
twice, independently: `syncAcademicCohort()`'s own existence/ownership
check (a clean `InvalidAcademicCohortException`, surfaced as a 422 at
the HTTP layer), and, even if that check were ever bypassed, the
composite `(id, school_id)` foreign keys on
`communication_announcement_academic_cohorts` at the database level.
See
`Tests\Feature\App\CommunicationAcademicCohortAudienceHubTest::a_cross_school_grade_level_id_is_rejected_with_a_clean_validation_error`
and the RLS suite's `a_cross_school_grade_level_reference_is_rejected_at_insert_time`.

## 14. HTTP surface

- `AnnouncementController::validateComposer()` — `audience_type` widened
  to accept `grade`/`section`; a new `academic_cohort` input shape
  (`academic_year_id`, `grade_level_id` xor `section_id`,
  `recipient_kind`), `required_if` the chosen audience type. All real
  validation still happens server-side in `syncAcademicCohort()` — this
  is a shape check only.
- `AnnouncementController::create()` resolves and returns the School's
  current AcademicYear (`CurrentAcademicYearResolver::tryResolve()`) so
  the composer can display it and disable the Grade/Section options
  entirely when the School has none active yet — there is no
  year-picker; a Grade/Section audience always targets the current
  year.
- `AnnouncementController::show()` extends the existing audience
  preview with a `preview.academicCohort` block (cohort type,
  Grade/Section display name, AcademicYear label, recipient kind) —
  label/metadata only. The existing `preview.domain` block (student/
  guardian counts, IN_APP-reachable count, Guardian email eligibility)
  already fires for Grade/Section audiences unchanged, since
  `GradeAudienceResolver`/`SectionAudienceResolver` populate the same
  `ResolvedAudience.studentIds`/`.guardianIds` arrays every other
  domain resolver does. No Guardian email address is ever included in
  this payload — only aggregate counts, matching Phase 5B.1's existing
  discipline.
- New routes: `GET /app/communications/audience/grade-levels/search`,
  `GET /app/communications/audience/sections/search` (requires
  `academic_year_id`) — both on `CommunicationAudienceSearchController`,
  registered before the announcements `/{thread}` wildcard, capability-
  gated as described in §13.

## 15. Composer UI

`Create.vue` gained "Grade (Academic Cohort)"/"Section (Academic
Cohort)" audience options (disabled with an explanatory note when the
School has no current AcademicYear), a search-and-select cohort picker
mirroring the existing Student/Guardian picker's exact interaction
pattern, and a Student/Guardian recipient-kind radio. `Show.vue`'s
audience preview panel displays the resolved cohort label plus a
disclaimer that recipient counts reflect current enrollment and are
re-resolved again at publication — explicitly calling out the
additional "and again at the scheduled send time" case when the
announcement is Scheduled (§4).

## 16. Approval fingerprint semantics

`CommunicationApprovalFingerprint::snapshot()` gained an
`academicCohort` key covering only the cohort DEFINITION — `cohortType`,
`academicYearId`, `gradeLevelId`, `sectionId`, `recipientKind` — read
from the single cohort row. It deliberately never includes any
resolved Student/Guardian id (those do not exist as stored state at
all, per §4/§10) and never includes account-link/reachability state.
Consequence, proven by
`Tests\Feature\Communications\AcademicCohortApprovalInvalidationTest`:
changing which Grade/Section/AcademicYear/recipient-kind is targeted
invalidates a prior approval and returns the announcement to Draft
(the same behavior Phase 5A.12/5B.1 already give a domain-audience-id
change); a Student joining or leaving the approved Grade/Section — a
pure `student_enrollments` change, entirely outside this table — never
invalidates it, mirroring Phase 5B.2's "linking/unlinking a Guardian
never invalidates approval" precedent for the identical reason:
approval binds to WHO WAS DEFINED as the target, not to whichever
concrete individuals that definition happens to resolve to right now.

## 17. Test coverage

- `Tests\Feature\Communications\AcademicCohortAudienceResolverTest` (12
  tests) — Grade/Section × Student/Guardian resolver correctness
  (excludes withdrawn/other-grade/other-year enrollments), Guardian
  dedup + eligibility, Section↔AcademicYear mismatch rejection,
  cross-School forgery rejection at the service layer, no-selection
  rejection, dynamic resolution (leaves/joins before publish),
  enrollment≠account-identity regression (both directions: unlinked →
  no delivery, linked → real delivery), and a bounded-query-count
  proof at 60-enrollment/60-Guardian scale.
- `Tests\Feature\Communications\AcademicCohortApprovalInvalidationTest`
  (4 tests) — cohort-definition changes invalidate approval;
  enrollment-membership-only changes (join or leave) do not.
- `Tests\Feature\App\CommunicationAcademicCohortAudienceHubTest` (11
  tests) — HTTP-layer draft/publish flow for both audience types,
  composer validation (missing selection, cross-School Grade/Section
  id), the two new search endpoints (same-School match, cross-School
  exclusion, cross-year exclusion for Sections, authorization denial
  for a non-`communications.announce` member, guest redirect).
- `Tests\Feature\Postgres\CommunicationAnnouncementAcademicCohortsRlsIsolationTest`
  (8 tests) — RLS enabled+forced, no-context sees zero rows,
  cross-School SELECT/INSERT rejection, cross-School GradeLevel FK
  rejection, `num_nonnulls` CHECK rejection (both directions), and the
  one-cohort-per-announcement UNIQUE constraint.

All 35 new tests pass (92 assertions in the new-tests-only run).

## 18. Regression

- Phase 1B (`tests/Feature/StudentEnrollment`) + Phase 5B.1/5B.2
  (`tests/Feature/StudentGuardianIdentity`) + `tests/Feature/AcademicStructure`
  + full Communications (`tests/Feature/Communications`) + `tests/Feature/App`
  + `tests/Feature/Postgres`, combined: **1091 tests / 3569 assertions,
  all passing.**
- Full application suite: **1384 tests / 4265 assertions** (previous
  baseline: 1349 tests / 4179 assertions — +35 tests / +86 assertions,
  exactly this checkpoint's new test count, zero tests removed or
  modified elsewhere). One run surfaced two failures, both confirmed
  pre-existing and unrelated to this diff (neither touched by this
  checkpoint's changes, both pass individually in isolation): a
  date-range-dependent flake in `StudentEnrollmentTest` (its own
  fixture hardcodes an `ends_on` of `2026-08-01` against a factory
  `starts_on` drawn randomly from the trailing six months — this fails
  whenever that random draw lands after August 1st, independent of any
  code this checkpoint touched) and a full-suite-only rate-limiter
  state leak in `LoginThrottleTest`. A repeat full run showed only the
  `LoginThrottleTest` flake recur; academic-cohort and every other
  suite passed cleanly both times.

## 19. Quality gates

`vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (zero errors,
zero new baseline entries), `npm run type-check`, `npm run lint`,
`npm run format:check`, `npm run build` — all pass.

## 20. Migration proof

Fresh-from-zero `platform:test-db-reset --force` applied both new
migrations cleanly alongside the full existing chain. `migrate:rollback --step=2`
against `pgsql_admin` rolled both back cleanly; re-running the reset
re-applied them and re-seeded successfully.

## 21. Explicitly out of scope

Not implemented by this checkpoint: SubjectOffering/teaching-group
audiences, club/house/transport/hostel/attendance/fee-status
audiences, any Student/Guardian portal or account-provisioning change,
conversation participation by academic cohort, and the Phase 1B
rollover-HTTP work committed separately as `7b3f5c7`.
