# Phase 1B — Student Enrollment / Academic Placement Final Integration

**Outcome: GO. `phase/1b-student-enrollment-academic-placement` is
integrated into local `main` via `integration/phase-1b-student-enrollment`,
fast-forward merged, fully verified. Nothing was pushed; no branch was
deleted; the Phase 5B feature branch was not touched.**

## 1. Source branch and SHA

- Worktree: `/home/wajidkhan/sites/lycenza-phase-1b-student-enrollment`
- Branch: `phase/1b-student-enrollment-academic-placement`
- Merged commit: `5fe6f4f5dde5b96a052916940868a70d97a8f242` ("feat(students):
  add resumable rollover execution")
- **Uncommitted work observed in that worktree, explicitly excluded
  from this integration** (git operates on commits, not working-tree
  state, so none of this was merged): modifications to
  `EnrollmentRolloverExecutionService.php`, `CapabilityAndRoleSeeder.php`,
  `routes/api.php`, plus untracked
  `EnrollmentRolloverReadService.php`, three new rollover HTTP
  controllers, and one new API test file — an in-progress HTTP-exposure
  layer for the already-committed, already-tested rollover *service*
  logic. This was left completely untouched (not stashed, not
  committed, not discarded) — see §16.

## 2. Target main SHA

`4cfcd61b806e0d532fa14878216eeaf9b81131ea` (unchanged from the Phase
5A publication gate; `origin/main` identical).

## 3. Merge-base

`e0c4e1b90f2be87ba4903fba39237dfaa6b3e315` — the same Phase 1A tip
both the Phase 5A and Phase 1B lines independently branched from.

## 4. Integration branch

`integration/phase-1b-student-enrollment`, created from `main` at
`4cfcd61`, in the primary worktree (`/home/wajidkhan/sites/lycenza`) —
the Phase 5B feature branch worktree state was checked out away from
only long enough to create this branch; no commit was added to
`feature/phase-5b-student-guardian-communication-audiences`.

## 5. Conflicts

Exactly 4 — identical shape to the Phase 5A integration gate, because
both lines independently extended the same shared anchor points from
the same Phase 1A base:

| File | Resolution |
| --- | --- |
| `DashboardController.php` | Kept both `canViewCommunications` and `canViewEnrollments` nav flags |
| `Dashboard.vue` | Kept both nav-prop entries and both `<li>` links |
| `CapabilityAndRoleSeeder.php` (3 sub-regions: capability definitions, `school_admin` grants, `principal` grants) | Kept both `communications.*` and `enrollments.*` key groups in all three regions |
| `routes/web.php` | Kept both the `app/communications` route group and the new `app/enrollments` route group as siblings |

## 6. Reconciliations

`.env.example` and all other overlapping files auto-merged cleanly
(no markers). A repo-wide sweep after resolution found zero leftover
conflict markers.

## 7. Enrollment schema

`student_enrollments` (new): `student_id`, `academic_year_id`,
`campus_id`, `grade_level_id`, `section_id` (the latter three
deliberately denormalized off `section_id` — see the migration's own
docblock — required because a partial unique index cannot reference a
joined table's column, and to give each parent its own composite-FK
protection independent of `Section`'s), `roll_number`, `status`
(`active`/`completed`/`withdrawn`/`transferred`/`cancelled`),
`starts_on`/`ends_on`. RLS-enabled. Consistency between `section_id`
and the denormalized trio is guaranteed by construction —
`StudentEnrollmentService` is the sole write path and always derives
them server-side from the caller-chosen Section, never accepts them as
independent input.

Three companion tables (`enrollment_rollover_plans`,
`enrollment_rollover_mappings`, `enrollment_rollover_items`) implement
year-end bulk promotion — additional functionality layered on top of
the core placement table, not required for the specific
"current-placement" dependency this gate exists to unblock.

## 8. Current-placement semantics

`student_enrollments_one_active_per_student_year` — a PostgreSQL
partial unique index on `(student_id, academic_year_id) WHERE status =
'active'` — is the database-enforced "at most one active Enrollment
per Student per AcademicYear" invariant (the
`academic_years_one_active_per_school` precedent, applied here).
Combined with `status_index`/`grade_level_id_index`/`section_id_index`,
the deterministic query "which Students currently belong to Grade X /
Section Y" is:

```sql
select student_id from student_enrollments
where grade_level_id = ? and academic_year_id = ? and status = 'active'
```

— exactly what a future Phase 5B.3 Grade/Section audience resolver
needs, with no ambiguity and no derived/inferred state.

## 9. Grade/Section relationships

`grade_level_id`/`section_id`/`campus_id`/`academic_year_id` are all
composite-FK'd against `(id, school_id)` on their respective
`AcademicStructure` parent tables (`grade_levels`, `sections`,
`campuses`, `academic_years`) — cross-School reference is rejected at
INSERT time, independent of RLS, matching the established pattern
every other tenant-owned child table in this codebase already uses.

## 10. RLS

`student_enrollments` (and the three rollover tables) are RLS-enabled.
`Tests\Feature\Postgres\StudentEnrollmentIntegrityTest` (8 tests) and
`Tests\Feature\Postgres\EnrollmentRolloverIntegrityTest` (12 tests) —
both included in the focused run below — passed cleanly, proving
cross-School isolation and composite-FK rejection at the raw-SQL
level.

## 11. Fresh migration proof

Ran `platform:test-db-reset --force` against the disposable
`school_os_test` database. All **78 migrations** (73 already on `main`
+ 5 new Phase 1B migrations) applied successfully in filename-sorted
order, including two same-timestamp-prefix collisions
(`2026_08_23_130000_create_student_enrollments_table` alongside a
Communications migration; `2026_08_24_090000_add_student_composite_unique_to_student_enrollments_table`
alongside `..._add_subject_index_to_school_audit_events_table`) —
both resolved harmlessly by filename order, same as every other
collision this integration lineage has already proven safe. Seeding
completed cleanly. Schema verified directly via `psql \d
student_enrollments`: every column, constraint, and FK matches the
migration exactly.

## 12. Focused tests

**253 tests, 897 assertions, 0 failures** —
`StudentEnrollmentTest`, `StudentEnrollmentServiceTest`,
`StudentEnrollmentReadServiceTest`, `StudentEnrollmentLifecycleTest`,
`StudentEnrollmentApiTest`, `StudentEnrollmentUiTest`,
`StudentEnrollmentIntegrityTest` (RLS), `EnrollmentCapabilityTest`,
and the full committed rollover suite (`EnrollmentRolloverDryRunServiceTest`,
`EnrollmentRolloverExecutionServiceTest`,
`EnrollmentRolloverItemExecutionServiceTest`,
`EnrollmentRolloverSchemaTest`,
`EnrollmentRolloverExecutionConcurrencyTest`,
`EnrollmentRolloverItemExecutionConcurrencyTest`,
`EnrollmentRolloverIntegrityTest`).

## 13. Full regression

**1255 tests, 3933 assertions, 0 failures** — the entire unified suite
(baseline + Phase 1A + Phase 5A Communications + Phase 1B Enrollment)
on the merged integration tip.

## 14. Quality gates

| Gate | Result |
| --- | --- |
| `vendor/bin/pint --test` | PASS — 673 files |
| `vendor/bin/phpstan analyse --memory-limit=512M` | PASS — 361 files, 0 errors, no baseline |
| `npm run format:check` | PASS |
| `npm run type-check` (`vue-tsc --noEmit`) | PASS |
| `npm run lint` (ESLint) | PASS — 0 errors, 2 pre-existing unrelated warnings (`Pagination.vue`, confirmed present on `main` before this merge) |
| `npm run build` | PASS |

`route:list`: 193 named routes, zero duplicates.

## 15. Phase 5B.3 dependency readiness

**Yes.** `student_enrollments` gives Communications exactly the
authoritative, deterministic "current Students in this Grade/Section
for this AcademicYear" query Phase 5B.3 needs, with real RLS and
composite-FK tenant protection already proven. Phase 5B.3 can now
build its Grade/Class/Section audience resolvers against this real
schema instead of stopping, as it correctly did last checkpoint.

## 16. Remaining Phase 1B follow-ups

- The rollover feature's HTTP/API exposure layer (controllers,
  routes, capability-seeder grants, one API test) was left
  **uncommitted** in the source worktree and is **not** part of this
  integration. It should be finished and committed as its own
  checkpoint, independent of this gate.
- Everything else observed in the committed history (core placement,
  lifecycle transitions, transfers, rollover plan/dry-run/execution
  service layer, admin UI, RLS, capability tests) is complete and
  fully tested as integrated here.
