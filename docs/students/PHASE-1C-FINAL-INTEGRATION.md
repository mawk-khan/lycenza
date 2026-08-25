# Phase 1C — Final Integration Report

Student Subject Enrollment / Elective Placement Foundation, integrated
locally into `main`. This is a Git/QA integration record, not a design
document — see
`docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md` for
the architecture itself.

## 1. Source branch/SHA

`feature/phase-1c-student-subject-enrollment` at
`a26ead7d1a9d200a1866d665c5f1150ca422ea76`.

## 2. Target main SHA

At the start of this gate, local `main` was **not** the previously
reported `ec9093c7c70340c3df5d438153140110b820c231` — it had advanced
to `cde5dcfb5deac839560901ef7e6d1297136a1e77`, which contains an
unrelated, already-integrated **Phase 8A — HR and Employee Records**
body of work (18 commits, `f0c4c6e`..`7985340`, plus an
"Integrate Phase 8A HR and employee records" merge commit `cde5dcf`)
that landed on `main` outside this checkpoint's own history. `ec9093c7`
remains an ancestor of `cde5dcf`. `origin/main` was unaffected and
remained at `ec9093c7c70340c3df5d438153140110b820c231` throughout.
Per this gate's own instruction ("do not assume the previous conflict
analysis remains valid" if main moved), integration proceeded against
the actual current `main` (`cde5dcf`), not the stale reference.

## 3. Merge-base

`ec9093c7c70340c3df5d438153140110b820c231` — the Phase 1C branch's
merge-base with `main` is unchanged by Phase 8A landing on `main`,
since Phase 1C branched before Phase 8A existed.

## 4. Integration branch

`integration/phase-1c-student-subject-enrollment`, in a dedicated
worktree (`lycenza-phase-1c-integration`), created from actual current
`main` (`cde5dcf`) with a verified identical initial HEAD.

## 5. Conflicts

A non-mutating `git merge-tree` analysis (against the true merge-base)
predicted a clean merge (single resulting tree hash, no conflict
markers). The actual `--no-ff` merge confirmed this: two files
auto-merged cleanly (`apps/platform/routes/api.php`,
`apps/platform/tests/Concerns/CreatesTenancyFixtures.php`), zero
textual conflicts. Both auto-merged files were re-verified with `php
-l`. No conflict touched `Student.php`, `StudentController.php`,
`Students/Show.vue`, `SubjectOffering.php`, or the capability seeder —
Phase 1C did not modify any of them.

## 6. Reconciliations

None needed beyond the automatic merge — no manual conflict resolution
was required.

## 7. Required-vs-elective architecture

Confirmed by independent re-audit of the actual committed code (not
taken from the prior condensed summary): `subject_offerings.is_required`
is authoritative. A **required** offering (`is_required = true`) never
gets a `student_subject_enrollments` row — its roster is derived
implicitly from every Student whose current active `StudentEnrollment`
matches the offering's AcademicYear + GradeLevel + Campus. An
**elective** offering (`is_required = false`) requires an explicit
active `StudentSubjectEnrollment` row; `StudentSubjectEnrollmentService::enroll()`
rejects enrollment into a required offering outright
(`RequiredSubjectOfferingEnrollmentException`).

## 8. StudentSubjectEnrollment schema

Table `student_subject_enrollments`: `id` (UUIDv7), `school_id`,
`student_id`, `subject_offering_id`, `academic_year_id` (denormalized
off `subject_offering_id`, mirroring `student_enrollments`'ows own
denormalization rationale), `status` (active|withdrawn|cancelled|
transferred), `starts_on`, `ends_on`, timestamps. Composite
tenant-safe FKs `(student_id, school_id)` → `students(id, school_id)`
cascade-on-delete, `(subject_offering_id, school_id)` →
`subject_offerings(id, school_id)` restrict-on-delete,
`(academic_year_id, school_id)` → `academic_years(id, school_id)`
restrict-on-delete. `TenantRls::enable()` applied (RLS + FORCE RLS
confirmed live, see §13). CHECK `ends_on IS NULL OR ends_on >=
starts_on`. Partial unique index (see §14) is the sole database-level
duplicate-active-membership guard.

## 9. SubjectOfferingRosterReadService contract

`App\Domain\Students\Application\SubjectOfferingRosterReadService::
currentRosterStudentIds(SubjectOffering $offering): array` and
`currentRosterCount(SubjectOffering $offering): int` — confirmed as
the exact, only public contract. Both dispatch on `$offering->is_required`:
required → a single `StudentEnrollment` query (implied roster);
elective → a single `StudentSubjectEnrollment` query joined with a
`whereExists` against `student_enrollments` (explicit roster,
re-validated). Zero references to any Communications type anywhere in
this class — the dependency direction is Communications → Academic
roster read model, never the reverse, confirmed by grep.

## 10. Compatibility revalidation

Confirmed live in both the read service and the write service: the
elective roster query's `whereExists` clause re-checks the Student's
**current** active `StudentEnrollment` (AcademicYear + GradeLevel +
Campus) on every read — never a cached/denormalized compatibility
flag. `StudentSubjectEnrollmentService::assertCompatible()` performs
the identical check at write time. A Student who is promoted/
transferred out of the compatible placement after enrolling
disappears from the current roster automatically, with zero coupling
between `StudentSubjectEnrollmentService` and `StudentEnrollmentService`.

## 11. StudentEnrollment relationship

`StudentEnrollment` remains the sole authoritative source for
AcademicYear/GradeLevel/Campus/Section placement. Nothing in Phase 1C
writes to `student_enrollments`, and `StudentSubjectEnrollment` only
ever reads it (read-only `whereExists`/`assertCompatible` checks).

## 12. Lifecycle/history

`withdraw()`/`cancel()` transition an active row to a terminal status
via a locked, atomic update (`lockForUpdate()` + conditional
`UPDATE ... WHERE status = 'active'`, never a blind save) and audit
the transition. `transfer()` (elective switch) is a single DB
transaction: the source row is atomically marked `transferred` with
`ends_on` set, and a brand-new row is created for the target offering
— the old row is never overwritten, preserving full history.

## 13. RLS

Verified directly against PostgreSQL on the freshly-reset integration
test database:

```
relname                     | relrowsecurity | relforcerowsecurity
student_subject_enrollments | t              | t
```

`tests/Feature/Postgres/StudentSubjectEnrollmentIntegrityTest.php`
(8 tests / 11 assertions, re-run clean on the integrated tree) proves
cross-School SELECT/INSERT/UPDATE/DELETE are all rejected at the raw
`school_os_app` runtime-role level, independent of Eloquent.

## 14. Unique/index constraints

Confirmed the exact index definition directly from PostgreSQL:

```
CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_offering
  ON student_subject_enrollments (student_id, subject_offering_id)
  WHERE status = 'active'
```

Plus `student_subject_enrollments_id_school_id_unique` (enables future
composite-FK children), and non-unique indexes on
`(school_id, student_id)`, `subject_offering_id`, `academic_year_id`,
`status`.

## 15. Audit/authorization

Every write path (`enroll`, `withdraw`, `cancel`, `transfer`) calls
`AuditRecorder::school()` with Student id, SubjectOffering id, and
lifecycle state — no unnecessary Student personal data. The HTTP
boundary (`StudentSubjectEnrollmentController`) reuses the existing
`academics.subjects.view`/`academics.subjects.manage` capabilities
(no new capability pair invented, no role-name check) via
`AuthorizesCapability`. The four mutation routes carry the
`idempotent` middleware (consequential, plausibly-retryable
mutations); the roster GET does not (read-only).

## 16. Migrations

104 total migrations across the fully unified history (baseline +
Phase 1A + Phase 1B + Phase 5A + Phase 5B + Phase 1C + the
independently-integrated Phase 8A HR work already on `main`) applied
cleanly, fresh-from-zero, on the disposable `school_os_test` database
via the canonical `platform:test-db-reset --force` path. No
pre-existing migration file was edited.

Targeted rollback/reapply proof: rather than a blind rollback of
however many migrations happened to run after Phase 1C's, the exact
count of migrations at-or-after `2026_08_24_100000_create_student_
subject_enrollments_table` in dependency order (13, all
Communications-domain migrations with no structural dependency on this
table) was computed and rolled back via `migrate:rollback --step=13
--database=pgsql_admin`, confirming Phase 1C's own `down()` (disables
RLS, drops the table) ran cleanly as the last step, then reapplied
forward via `migrate --database=pgsql_admin` — all 13 DONE, no errors.
(An initial attempt to invoke the migration's `up()`/`down()` directly
via `artisan tinker` was abandoned once it hit
`SQLSTATE[42501]: Insufficient privilege` — `TenantRls::disable()`'s
`DROP POLICY` requires the `pgsql_admin` connection per ADR 0021; the
standard `migrate:rollback --database=pgsql_admin` path already
handles this correctly, confirming rule 54's discipline is what
actually matters here, not a hand-rolled shortcut.)

## 17. Focused tests

- Phase 1C (36 tests, incl. RLS): **36 tests / 57 assertions**,
  re-run clean on the integrated tree, matching the reported figures
  exactly.
- RLS suite specifically (subset of the above): 8 tests / 11
  assertions.

## 18. Phase 1B regression

**231 tests / 850 assertions**, clean — StudentEnrollment placement,
lifecycle, active-uniqueness, RLS, and placement reads are all
unaffected by Phase 1C.

## 19. Academic Structure regression

**143 tests / 347 assertions**, clean — SubjectOffering, Subject,
GradeLevel, Section, AcademicYear, Campus all unaffected. No
SubjectOffering schema change was made; only the pre-existing
`is_required` column is consumed. No TeachingGroup model was created.

## 20. Communication regression

**338 tests / 748 assertions**, clean — exactly matching the
previously reported figure with zero change, confirming Phase 1C
introduced zero Communication-domain code and zero Communication
regression.

## 21. Full regression

Two consecutive fully clean runs on the exact final integration tip
(`ac5a1c0`, pre-local-main-merge): **2145 tests / 6536 assertions, 0
failures, 0 errors, 0 skips**, both times. (This total is
substantially larger than the previously reported 1420/4332 Phase-1C-
branch figure because the actual current `main` also carries the
independently-integrated Phase 8A HR body of work, which was not part
of any prior baseline tracked by this checkpoint sequence.)
`CACHE_STORE=array` and `QUEUE_CONNECTION=sync` were passed explicitly
on every test invocation — the container's real `.env` otherwise
resolves `CACHE_STORE=redis`/`REDIS_PORT=26379` (the host-mapped port,
invalid for in-container Compose networking), which would silently
override `phpunit.xml`'s isolation and previously caused
`LoginThrottleTest` flakiness; `REDIS_PORT=6379` (the container-internal
port) was required once any Redis-touching test ran, distinct from the
host-side `.env` value used for `artisan serve`/host tooling.

## 22. Quality gates

Pint: PASS (913 files). PHPStan: PASS, 0 errors, no baseline file
exists. Prettier: PASS. `vue-tsc --noEmit`: PASS. ESLint: PASS, 0
errors, 2 pre-existing warnings (`vue/no-v-html` in
`Pagination.vue`, unrelated to this diff, pre-existing before Phase
1C). `npm run build`: PASS.

## 23. Shared-Docker incident / process safeguard

The integration worktree initially had neither `docker-compose.override.yml`
nor `apps/platform/.env`/`services/ai/.env` (all untracked local
files). Before any `docker compose` command, `docker compose config`
was run and diffed against the canonical directory's output to confirm
project identity (`school-os`), ports (25432/26379), volumes, and
environment were identical — only bind-mount *source paths* differed
(correctly pointing at this worktree's own code, which is the entire
point of testing the integration branch). The canonical
`docker-compose.override.yml` was copied in before any container
command ran, so ports never diverged from the expected 25432/26379 at
any point in this session.

One narrow, structural side-effect was observed and is worth recording
precisely, since it is a *different* failure mode from the original
incident, not a repeat of it: this repository's `docker-compose.yml`
binds `postgres`'s init-scripts directory
(`./infrastructure/docker/postgres/init`) with a path relative to the
invoking working directory. Because that resolves differently between
the canonical directory and any worktree, Compose sees this single
field as changed and recreates the `postgres` container on the first
invocation from a new worktree — even with an identical override file
and identical ports. This was observed once in this session: Compose
reported `Container school-os-postgres-1 Recreate`, the container
restarted within seconds, ports and health remained exactly as
expected (`25432`, healthy), and `\l` confirmed the named volume
(`school-os_postgres_data`) was reused, not recreated — no data loss,
no port misconfiguration, unlike the original incident (which was
caused by a genuinely *missing* override file producing the wrong
published port). No further recreation occurred on subsequent
invocations from the same worktree in this session. `redis` was never
recreated at any point. **Process safeguard for future sessions**: a
worktree's first `docker compose` invocation against the shared
Postgres/Redis containers may trigger one brief, safe Postgres
container recreation due to this bind-path difference alone — this is
expected, non-destructive, and distinct from the port-misconfiguration
failure mode; always verify `docker compose config` and an identical
override file first regardless, since that is what prevents the
*actually* dangerous variant of this incident.

## 24. Phase 5C.1 readiness

**READY.** `SubjectOfferingRosterReadService::currentRosterStudentIds()`/
`currentRosterCount()` is the authoritative, independently-testable
contract: `SubjectOffering → SubjectOfferingRosterReadService →
current Student IDs`, requiring zero knowledge of required-vs-elective
semantics, `StudentSubjectEnrollment`'s schema, or how compatibility
is derived.

## 25. Final verdict

**PHASE 1C INTEGRATED LOCALLY — PASS**

All GO conditions held: architecture matched the reported semantics
(re-audited independently, not merely re-read from the prior summary);
no unexpected dependency entered the branch; the merge succeeded with
zero conflicts; fresh migration succeeded (104 total); required/
elective/placement-compatibility semantics were verified by the actual
passing test suite; RLS passed; Phase 1B, Academic Structure, and
Communication regressions all passed with zero unexplained changes;
the full suite passed cleanly twice in a row; all quality gates
passed; the tracked working tree was clean throughout (only the
untracked `docker-compose.override.yml` present, in every worktree);
and shared infrastructure remained healthy (ports/volumes correct,
one benign Postgres recreation explained in §23, no data loss). Local
`main` was fast-forwarded/merged to include Phase 1C; `origin/main`
was left untouched; nothing was pushed.
