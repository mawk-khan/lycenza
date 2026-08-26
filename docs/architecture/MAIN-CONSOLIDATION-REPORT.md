# Main Consolidation Report — 2026-08-26

## 1. Purpose

A repository-wide gate was requested to reconcile every completed/accepted
School OS checkpoint (Phase 1A/1B/1C Student-Guardian-Enrollment, Phase
5A/5B Communications, Phase 8A HR/Employee Records) into canonical `main`,
per the standard ancestry-classification + non-mutating-merge-analysis +
full-verification process. This report covers the ancestry audit and the
verification half of that gate (fresh migration, quality gates, full and
focused regression suites, route/capability/schema reconciliation,
infrastructure safety) run against `main` as it stood at the start of this
gate.

## 2. Starting / ending state

- Canonical repository: `/home/wajidkhan/sites/lycenza`
- Local `main`: `0b556aaa906244ee55b3322e6cedf64a12cbfb73`
- `origin/main`: `0b556aaa906244ee55b3322e6cedf64a12cbfb73` (identical, 0 ahead / 0 behind)
- **No branch was merged during this gate** — ancestry audit found nothing
  in ACCEPTED + UNMERGED state, so `main`'s tip is unchanged
  (`0b556aa` → `0b556aa`). No `integration/main-consolidation` branch was
  created, per the gate's own rule against fabricating history when
  nothing needs merging.
- Nothing was pushed. No branches or worktrees were deleted or modified.

## 3. Worktree inventory (`git worktree list`)

| Path | Branch | HEAD | Clean? |
|---|---|---|---|
| `lycenza` (canonical) | `feature/phase-5b-student-guardian-communication-audiences` | `e94ed95` | clean (untracked `docker-compose.override.yml` only, expected) |
| `lycenza-main-merge` | `main` | `0b556aa` | clean |
| `lycenza-phase-0e-documents-foundation` | `feature/phase-0e-documents-foundation` | `1b2c00f` | clean |
| `lycenza-phase-1c-integration` | `integration/phase-1c-student-subject-enrollment` | `f9ca925` | clean |
| `lycenza-phase-1c-refresh` | `integration/phase-1c-student-subject-enrollment-refresh` | `295d10a` | clean |
| `lycenza-phase-1d-admissions-foundation` | `feature/phase-1d-admissions-foundation` | `8b28422` | clean |
| `lycenza-phase-5b-integration` | `integration/phase-5b-student-guardian-communications` | `ec9093c` | clean |
| `lycenza-phase-8a-hr-employee-records` | `feature/phase-8a-hr-employee-records` | `7985340` | clean |

Several worktree paths named in the original gate prompt
(`lycenza-phase-1b-student-enrollment`, `lycenza-student-subject-enrollment`,
`lycenza-phase-5c-subject-offering`) no longer exist — presumably cleaned up
after their branches were already merged into `main` in prior sessions. The
prompt's assumed inventory did not match actual Git state; this report is
built from the real registered worktrees/branches, not the assumed list.

## 4. Ancestry / classification matrix

| Branch | HEAD | Ancestor of `main`? | Classification | Action |
|---|---|---|---|---|
| `feature/phase-0e-documents-foundation` | `1b2c00f` | Yes | ALREADY IN MAIN | none |
| `feature/phase-5a-communication-hub` | `085cb17` | Yes | ALREADY IN MAIN | none |
| `feature/phase-5b-student-guardian-communication-audiences` | `e94ed95` | Yes | ALREADY IN MAIN | none |
| `feature/phase-8a-hr-employee-records` | `7985340` | Yes | ALREADY IN MAIN | none |
| `integration/phase-1b-student-enrollment` | `ba00009` | Yes | ALREADY IN MAIN | none |
| `integration/phase-1c-student-subject-enrollment` | `f9ca925` | Yes | ALREADY IN MAIN | none |
| `integration/phase-5a-communication-hub` | `4cfcd61` | Yes | ALREADY IN MAIN | none |
| `integration/phase-5b-student-guardian-communications` | `ec9093c` | Yes | ALREADY IN MAIN | none |
| `integration/phase-1c-student-subject-enrollment-refresh` | `295d10a` | **No** | **SUPERSEDED** | do not merge — see §11 |
| `feature/phase-1d-admissions-foundation` | `8b28422` | **No** | ACCEPTED-BUT-OUT-OF-SCOPE (schema-only, not requested for this gate) | left unmerged — user's explicit choice, see §14 |

Also confirmed as ancestors of `main`: `7b3f5c76b2a6ddb46f4a950ca63e9f78232f6451`
(Phase 1B rollover-endpoints commit), `ec9093c7c70340c3df5d438153140110b820c231`,
`a26ead7d1a9d200a1866d665c5f1150ca422ea76`, `5fe6f4f5dde5b96a052916940868a70d97a8f242`
(all three reference SHAs named in the gate prompt).

## 5. Phase 1 final status

Every branch representing Phase 1A (Student/Guardian identity), Phase 1B
(Enrollment/Academic Placement, including the rollover-endpoints commit
`7b3f5c7`), and Phase 1C (Student Subject Enrollment / Elective Placement)
is already an ancestor of `main`. No newly merged commits were required.

## 6. Phase 1C contract proof

`App\Domain\Students\Application\SubjectOfferingRosterReadService`
(`apps/platform/app/Domain/Students/Application/SubjectOfferingRosterReadService.php`)
confirmed on the current `main` tip to implement exactly the documented
contract:

- **Required offering**: roster = every Student with a current `active`
  `StudentEnrollment` matching the offering's AcademicYear/GradeLevel/Campus
  — no `StudentSubjectEnrollment` row consulted (`impliedRosterQuery()`).
- **Elective offering**: roster requires an explicit `active`
  `StudentSubjectEnrollment` row, re-validated at read time against a
  still-compatible current active `StudentEnrollment`
  (`explicitRosterQuery()`), so a Student who moved Grade/Section after
  enrolling drops out of the roster automatically.
- An inactive `SubjectOffering` always reports an empty roster for both
  offering types.

`tests/Feature/StudentSubjectEnrollment/SubjectOfferingRosterReadServiceTest.php`
passes as part of the 968/968 focused StudentSubjectEnrollment+related run
(§9).

## 7. Phase 5A/5B/5C status

Phase 5A (Communication Hub foundation) and Phase 5B (Student/Guardian
communication audiences, account links, academic cohorts) are both
ancestors of `main`.

**Correction to the gate prompt's assumption**: Phase 5C.1 (SubjectOffering
communication audiences) and 5C.2 (audience composer UI) are **already
implemented and merged into `main`** — `8bf1e92 Merge Phase 5C subject
offering communication audiences` (merging `29b86d1`, `a23901d`) is an
ancestor of `main`, built on Phase 1C's `SubjectOfferingRosterReadService`
exactly as the roster contract in §6 was designed to unblock. The gate
prompt's belief that "Phase 5C.1 produced no implementation, no feature
commit" reflects an earlier state of the repository, not its current one.

## 8. Phase 8A status

`feature/phase-8a-hr-employee-records` (`7985340`) is an ancestor of
`main`. No further unmerged HR commits exist outside `main`.

## 9. Verification results

All verification below ran against `main` (`0b556aa`) unchanged — no
integration branch was created since nothing needed merging.

### 9.1 Fresh migration proof

`php artisan platform:test-db-reset --force` (canonical command, run with
explicit test-database credentials) against `school_os_test`:
**107 migrations applied, 0 failures, 0 ordering/collision issues.** RLS
setup completed as part of migration (no separate failures). Full canonical
seed set ran (`CapabilityAndRoleSeeder`, `ServiceIdentitySeeder`,
`EducationBoardSeeder`).

### 9.2 Quality gates

| Gate | Result |
|---|---|
| Pint (`--test`) | PASS — 974 files, 0 issues |
| PHPStan (`--memory-limit=512M`) | PASS — 509 files, 0 errors |
| vue-tsc (`npm run type-check`) | PASS — 0 errors |
| ESLint (`npm run lint`) | PASS — 0 errors, 2 pre-existing warnings (`vue/no-v-html` in `Pagination.vue`) |
| Prettier (`npm run format:check`) | PASS — all files formatted |
| Vite build (`npm run build`) | PASS — 673 modules, built in 1.44s |

### 9.3 Full regression suite

**2443 tests, 8525 assertions, 0 failures, 0 errors, 0 skips** (`vendor/bin/phpunit`,
`APP_ENV=testing`, `DB_DATABASE=school_os_test`, `CACHE_STORE=array`,
`SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`,
memory_limit raised to 2G for this single-process run — see §12 methodology
note).

### 9.4 Focused suites

| Suite | Result |
|---|---|
| `tests/Feature/Postgres` (cross-tenant/RLS, all modules) | 257 tests, 472 assertions, 0 failures |
| `tests/Feature/AcademicStructure` | 44 tests, 174 assertions, 0 failures |
| `tests/Feature/App` (session/UI-layer, incl. account-link, student/guardian admin UI, communications hubs) | 258 tests, 1762 assertions, 0 failures |
| `tests/Feature/HR` | 649 tests, 2099 assertions, 0 failures |
| `tests/Feature/Documents` + `tests/Feature/Communications` + `tests/Feature/StudentEnrollment` + `tests/Feature/StudentSubjectEnrollment` + `tests/Feature/StudentGuardianIdentity` + `tests/Feature/Api` (combined) | 968 tests, 3392 assertions, 0 failures |

### 9.5 Domain schema verification (post fresh-migration)

Confirmed present via the migration run: `students`, `guardians`,
`student_guardian_relationships`, `guardian_contacts`, `student_enrollments`,
`student_subject_enrollments`, `academic_years`, `grade_levels`, `sections`,
`subjects`, `subject_offerings`, `student_guardian_account_links`, the full
`communication_*` table set (Phase 5A/5B, incl. academic-cohort and
subject-offering audience tables), the full HR/`employee*`/`positions`/
`hr_departments` table set (Phase 8A), and `documents` (Phase 0E). No
unexpected gaps.

### 9.6 Route reconciliation

`php artisan route:list --json`: **240 routes, 0 duplicate route names.**
(Student/Guardian `account-link.*` routes for Students and Guardians are
correctly namespaced `app.students.account-link.*` /
`app.guardians.account-link.*` at the route-group level — not a collision.)

### 9.7 Capability reconciliation

28 distinct capability keys, 0 duplicates, 3 roles. Consistent with this
codebase's stated convention (rule 24/47) of reusing existing capability
keys across modules (e.g. Guardian account-link reuses `guardians.manage`)
rather than minting a new key per feature.

### 9.8 Migration inventory

107 migration files, chronologically ordered `0001_01_01` → `2026_08_29`,
no duplicate filenames, no timestamp collisions found.

## 10. Infrastructure safety

`docker compose config` was inspected before touching Compose (fixed
project name `school-os`, service/container/volume names are stable
regardless of which worktree Compose is invoked from, since `name:
school-os` is hardcoded in `docker-compose.yml`). No `docker compose down
-v` or Redis flush was run at any point.

**One recreation incident occurred and is disclosed here rather than
hidden**: the first `docker compose up -d postgres redis minio` invocation
(from the `lycenza-main-merge` worktree) recreated the `school-os-postgres-1`
container — Compose detected a config-hash difference because the
`postgres` service's init-script bind mount
(`./infrastructure/docker/postgres/init`) resolves to a different absolute
host path depending on which worktree directory Compose is run from,
exactly the risk this gate's brief warned about. **No data loss occurred**:
the named volume `school-os_postgres_data` was preserved (recreation
replaces the container, not the volume), verified directly afterward —
`school_os` (51 tables) and `school_os_test` (91 tables at that point, later
107 after the fresh-migration reset) were both intact. `redis`/`minio`
containers were not recreated (no relative bind mounts in those service
definitions). No further recreation occurred for the remainder of this
gate. Final state: `school-os-postgres-1`/`school-os-redis-1`/`school-os-minio-1`
all `Up ... (healthy)` on their expected ports (5432/6379/9000-9001), same
named volumes as at the start. An unrelated, pre-existing
`school-os-phase1d1-postgres-1`/`-redis-1` pair (Phase 1D's own isolated
`docker-compose.phase1d1.yml`, ports 60432/60379) was present throughout
and was not touched.

## 11. Special case — `integration/phase-1c-student-subject-enrollment-refresh`

This branch's single commit not in `main` (`295d10a`) is a duplicate
"Merge Phase 1C student subject enrollment foundation" merge commit, made
from a stale point *before* Phase 5C and Phase 0E landed. `git diff --stat
main integration/phase-1c-student-subject-enrollment-refresh` shows it
would **delete** the Phase 5C (SubjectOffering communication audiences),
Phase 0E (Documents), and their combined ~11,900 lines if merged.
Classification: **SUPERSEDED**. Correctly excluded — merging it would be a
regression, not an integration.

## 12. Methodology note — environment trap encountered and corrected

Mid-verification, an initial `docker compose run --rm platform php artisan
test` invocation produced 32+ apparent test failures (419 CSRF mismatches,
"No School tenant context is set" exceptions, and a `Premature end of PHP
process` crash) across `AccountLinkHubTest`, `StudentAdminUiTest`,
`GuardianAdminUiTest`, and `SchoolSwitchTest`. Root cause, confirmed via
`php artisan tinker`: the platform container's `env_file:
./apps/platform/.env` sets ambient `APP_ENV=local`, `DB_DATABASE=school_os`,
`SESSION_DRIVER=redis`, etc. — and `phpunit.xml`'s `<env>` block does not
override already-set environment variables (no `force="true"`), so the
suite was silently running against the **development** environment/database
rather than `school_os_test` with `APP_ENV=testing`. This is precisely the
failure mode root CLAUDE.md rule 52 documents. Because `app()->environment('testing')`
was consequently false, `TestDatabaseGuard` never engaged — its condition
never fired, so no abort occurred (it does not currently guard against this
specific ambient-`env_file` case for a plain `php artisan test` invocation
outside `composer test`'s controlled shell). No data was corrupted
(`DatabaseTransactions` still rolled back every write against whichever
database was actually targeted). Re-running with every `phpunit.xml`
environment value passed explicitly via `docker compose run -e ...`
(mirroring `composer test`'s intended environment exactly) made all of the
above pass cleanly (§9.3, §9.4) — **these were not product regressions**,
they were an artifact of this session's first test-invocation method.
Separately, the container's default `memory_limit=128M` was insufficient
for a single-process, non-parallelized 2443-test run (`brianium/paratest`
is not installed in this environment's `vendor/`); this was worked around
for this diagnostic run only via `-d memory_limit=2G`, not by changing any
committed configuration.

**Flag for follow-up (not fixed as part of this verification-only gate)**:
a plain `docker compose run --rm platform php artisan test` from a
developer's shell — without composer/CI's controlled environment — will
silently target the development database. `TestDatabaseGuard` protects
`APP_ENV=testing` sessions from targeting the wrong *database*, but does
not currently prevent a session from failing to reach `testing` mode at
all when Docker's `env_file` pre-sets conflicting values. Worth an ADR-note
or a Docker-Compose-level fix (e.g. an explicit test-mode compose override)
in a future session — flagged, not actioned, since it is a tooling
observation outside this gate's scope of "verify `main`," not a change to
committed product code.

## 13. Final verdict

- Every ACCEPTED completed branch belonging in `main` (Phase 1A/1B/1C,
  5A/5B/5C.1-5C.2, 8A, 0E) is confirmed already integrated.
- The one non-ancestor branch beyond Phase 1D is confirmed SUPERSEDED and
  correctly excluded.
- Phase 1D (`feature/phase-1d-admissions-foundation`) is confirmed
  genuinely new, clean, schema-only work — explicitly left unmerged per
  direct instruction, as it was not part of the requested Phase 1A–1C/5A–5B/8A
  scope and is not yet feature-complete by its own commit message.
- Fresh migration: PASS (107/107, 0 issues).
- Focused regressions: PASS (all listed suites, 0 failures).
- Full regression: PASS (2443/2443 tests, 8525 assertions, 0 failures, 0 errors).
- Quality gates: PASS (Pint/PHPStan/vue-tsc/ESLint/Prettier/build all clean).
- No tracked dirty files were introduced; only this report file was added
  (untracked, not committed — left for review).
- Infrastructure: one container recreation occurred (§10), no data loss,
  fully disclosed, infra healthy at gate end.

## MAIN CONSOLIDATION — PASS

## 14. Next action

`main` requires no further integration for the work this gate was
convened to reconcile. Recommend proceeding to the **MAIN REMOTE
PUBLICATION GATE** when the user is ready to review this report — not
executed here, per the "do not push" instruction for this gate.

Separately, and outside this gate's scope: `feature/phase-1d-admissions-foundation`
remains unmerged by explicit user instruction (schema-only Admissions
foundation, not part of the Phase 1A–1C/5A–5B/8A checkpoint set this gate
covered) — no action taken, flagged for a future, separate integration
decision once that module is further along.
