# Phase 5B — Final Integration / Reconciliation Gate

**Outcome: GO. `feature/phase-5b-student-guardian-communication-audiences`
is integrated into local `main` via
`integration/phase-5b-student-guardian-communications`, merged with a
fast-forward (the integration branch's tip was already a strict
descendant of `main`), fully verified. Nothing was pushed; no branch
was deleted; no shared/staging/production system was touched.**

## 1. Scope confirmation

- Path: `/home/wajidkhan/sites/lycenza` (integration performed in a
  dedicated worktree at `/home/wajidkhan/sites/lycenza-phase-5b-integration`
  to avoid disturbing the feature branch's own checkout)
- Starting local `main`: `ba0000964db91c72517e54093cc3c871a997467c`
  (Phase 1B final integration tip)
- `origin/main`: `4cfcd61b806e0d532fa14878216eeaf9b81131ea` — unchanged
  throughout, intentionally behind
- Feature branch merged:
  `feature/phase-5b-student-guardian-communication-audiences` @
  `e94ed9554d3e0769238c5388822d833c829552b4`
- Integration branch: `integration/phase-5b-student-guardian-communications`,
  created fresh from local `main` (not from the feature branch — no
  rebase, no cherry-pick, no squash)
- `git merge-base feature/... main` == `ba00009...` == `main` itself —
  `main` was already a strict ancestor of the feature branch (the
  Phase 5B Dependency Reconciliation gate had already folded it in at
  `0637bc9`/`59a0b44`), so this gate's own re-verification, not a
  fresh reconciliation.

## 2. Merge mechanics

Non-mutating conflict prediction first:

```
git merge-tree --write-tree --name-only --no-messages main feature/phase-5b-student-guardian-communication-audiences
```

Produced a tree hash with **zero conflicted paths listed** — expected,
since `main` was already an ancestor of the feature branch.

```
git worktree add ../lycenza-phase-5b-integration -b integration/phase-5b-student-guardian-communications main
git merge --no-ff feature/phase-5b-student-guardian-communication-audiences
```

Merge commit: `6e7a24d371ebc520aafa7d4c789a4536c296b383`
("Merge branch 'feature/phase-5b-student-guardian-communication-audiences'
into integration/phase-5b-student-guardian-communications")

Merge stat: 72 files changed, 8,576 insertions(+), 53 deletions(-).
**Zero textual conflicts** — `git merge` resolved with the `ort`
strategy cleanly, matching the `merge-tree` prediction exactly.

## 3. History verification

All required checkpoints confirmed present and unrewritten
(`git merge-base --is-ancestor`, all returned true):

| Checkpoint | SHA | Subject |
| --- | --- | --- |
| Phase 5B.1 | `732d35ff5a2fe52f01d6a96d22c8cf5124109156` | feat(communications): add student guardian audience reachability foundation |
| Phase 5B.2 | `b19b01ee1bc353848fb32e8e7486e88218836bdb` | feat(identity): add student guardian account link foundation |
| Phase 5B Dependency Reconciliation | `59a0b4403e6fa78c72c10849e240cd72da474f3a` | docs(communications): record Phase 5B enrollment reconciliation |
| Phase 5B.3 | `e94ed9554d3e0769238c5388822d833c829552b4` | feat(communications): add academic cohort audiences |
| Pinned Phase 1B dependency | `5fe6f4f5dde5b96a052916940868a70d97a8f242` | feat(students): add resumable rollover execution |

**Excluded work confirmed excluded**: `7b3f5c76b2a6ddb46f4a950ca63e9f78232f6451`
("feat(students): add rollover admin endpoints") is a real, reachable
commit object in the shared object database (it exists on the separate
`phase/1b-student-enrollment-academic-placement` worktree branch), but
`git merge-base --is-ancestor 7b3f5c7 <feature branch>` and
`... <main>` both return false — it is an ancestor of **neither**. It
was never merged, cherry-picked, or otherwise incorporated into this
integration.

## 4. Architecture invariants re-verified

- **Academic placement**: `StudentEnrollment → AcademicYear → GradeLevel
  → Section` remains the sole source of truth for cohort membership;
  no second/shadow enrollment table exists anywhere in the merged tree
  (`grep` for a Communications-owned enrollment model returns nothing).
- **Authenticated identity**: `Student`/`Guardian → StudentGuardianAccountLink
  → SchoolMembership` remains the only path to IN_APP delivery; no
  automatic User/Membership provisioning exists anywhere in the merged
  tree.
- **Enrollment ≠ account identity**: re-proven directly —
  `EnrollmentAccountLinkCoexistenceTest` (Phase 1B/5B reconciliation),
  `StudentGuardianAccountLinkInAppTest` (Phase 5B.2), and the Phase
  5B.3 academic-cohort regression `an_enrolled_student_with_no_link_is_never_reachable`-shaped
  cases all pass on the integrated tip. An active `StudentEnrollment`
  with no `StudentGuardianAccountLink` produces zero IN_APP deliveries;
  a valid active link reaches through the unchanged existing
  `SchoolMembership` path.
- **Guardian EMAIL**: still resolved only through `GuardianContact` via
  `GuardianEmailAddressResolver`, independent of any account link.
  `COMMUNICATION_EMAIL_ENABLED` defaults to `false`
  (`config/communications.php:25`, `env('COMMUNICATION_EMAIL_ENABLED', false)`)
  and is unset (falls to that default) in this integration's local
  `.env`.
- **Academic cohort resolution**: `GradeAudienceResolver`/
  `SectionAudienceResolver` re-query `student_enrollments` fresh on
  every call (publish, scheduled due-publish, preview) — no
  materialized/frozen cohort membership exists. The approval
  fingerprint (`CommunicationApprovalFingerprint::snapshot()`) hashes
  only the cohort *definition* (`cohortType`, `academicYearId`,
  `gradeLevelId`/`sectionId`, `recipientKind`) — never a resolved
  Student/Guardian id — so enrollment membership changes never
  invalidate an approval, while a changed cohort definition always
  does. Both directions covered by
  `AcademicCohortApprovalInvalidationTest` (4 tests, all pass).
- **No Class entity**: `find app -iname "*Class*.php"` returns nothing
  matching an academic grouping model; Section remains the only
  class-equivalent grouping, exactly as designed in Phase 5B.3.

## 5. Fresh migration proof

Ran via the canonical `platform:test-db-reset --force` path (never a
hand-typed `migrate:fresh`), with the real `school_os_test` credentials
passed explicitly, from a clean database state:

- **89 migrations total**, 0001_01_01_000000 baseline through
  `2026_08_28_090100_create_communication_announcement_academic_cohorts_table`
  — all `DONE`, zero ordering issues, zero index/FK conflicts.
- Seeding (`CapabilityAndRoleSeeder`, `ServiceIdentitySeeder`,
  `EducationBoardSeeder`) completed cleanly.
- Verified via `DB::table('migrations')->count()` = 89, confirming the
  full run (not a partial/cached state).
- **Rollback proof**: `migrate:rollback --database=pgsql_admin --step=2`
  rolled back exactly the two Phase 5B.3 migrations
  (`2026_08_28_090100_create_communication_announcement_academic_cohorts_table`,
  `2026_08_28_090000_widen_communication_announcement_audience_type_for_academic_cohorts`)
  cleanly; `migrate --database=pgsql_admin --force` reapplied both
  cleanly. A full canonical reset was re-run afterward to guarantee a
  fully fresh, fully seeded state before any test execution.

## 6. Schema verification

Confirmed present via direct `psql \dt` against `school_os_test`:
`academic_years`, `grade_levels`, `sections`, `student_enrollments`,
`guardians`, `student_guardian_relationships`, `guardian_contacts`,
`student_guardian_account_links`,
`communication_announcement_academic_cohorts`,
`communication_announcement_domain_audience_members`,
`communication_announcements`, `communication_recipients` — every
table named in the brief's checklist exists in the merged schema.

## 7. RLS / tenant verification

Direct `pg_class` query against the merged schema confirms
`relrowsecurity = t` **and** `relforcerowsecurity = t` for all of:
`communication_announcement_academic_cohorts`,
`communication_announcement_domain_audience_members`,
`communication_announcements`, `grade_levels`, `sections`,
`student_enrollments`, `student_guardian_account_links`.

Cross-School isolation re-proven by the full, passing RLS test suite
(raw-SQL layer, per `tests/Feature/Postgres/RawIsolationTest.php`'s
established pattern):
`CommunicationAnnouncementAcademicCohortsRlsIsolationTest` (8 tests),
`CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest`,
`StudentGuardianAccountLinksRlsIsolationTest`,
`StudentEnrollmentIntegrityTest`, `EnrollmentRolloverIntegrityTest` —
all included in, and passing within, the regression runs below. These
prove: a cross-School `StudentEnrollment` cannot resolve into another
School's audience; a foreign `GradeLevel`/`Section` cannot attach to
another School's cohort row (composite `(id, school_id)` FK rejects
it at INSERT time); a foreign Guardian/Student cannot be targeted; a
foreign `SchoolMembership` cannot be account-linked
(`CrossSchoolMembershipLinkException`).

## 8. Route verification

`php artisan route:list --json` on the integrated tip: **204 total
routes**, **0 duplicate route names**. Student (31 matches), Guardian
(31), Enrollment (17), Communications (51), account-link (6), and the
Phase 5B.3 academic-cohort search endpoints
(`audience.grade-levels.search`, `audience.sections.search`, under
`app/communications`) all coexist without collision.

## 9. Focused regression results

All runs used the project's `vendor/bin/phpunit` directly with
`-d memory_limit=1024M` (never `php artisan test`, which spawns a
subprocess that silently ignores the `-d` flag and OOMs at the
suite's current size), against the canonical `school_os_test`
database.

- **Phase 5B.1** (`StudentGuardianAudienceServiceTest`,
  `CommunicationDomainAudienceHubTest`,
  `CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest`):
  **49 tests / 122 assertions** — matches the "last reported" baseline
  exactly.
- **Phase 5B.2** (`StudentGuardianAccountLinkInAppTest`,
  `AccountLinkServiceTest`, `AccountLinkHubTest`,
  `StudentGuardianAccountLinksRlsIsolationTest`):
  **42 tests / 115 assertions** — matches the "last reported" baseline
  exactly.
- **Phase 5B.3** (`AcademicCohortAudienceResolverTest`,
  `AcademicCohortApprovalInvalidationTest`,
  `CommunicationAcademicCohortAudienceHubTest`,
  `CommunicationAnnouncementAcademicCohortsRlsIsolationTest`):
  **35 tests / 92 assertions**, confirmed clean across 3 consecutive
  runs after the test-fixture fix in §11.
- **Phase 1B** (`tests/Feature/StudentEnrollment/*`,
  `EnrollmentRolloverIntegrityTest`, `StudentEnrollmentIntegrityTest`,
  `StudentEnrollmentUiTest`, `EnrollmentCapabilityTest`,
  `EnrollmentAccountLinkCoexistenceTest`): **256 tests / 906
  assertions**, clean.
- **Full Communications regression** (`tests/Feature/Communications`,
  `tests/Feature/StudentGuardianIdentity`, the account-link/domain-
  audience/academic-cohort App hub tests, and the three
  Communications-related RLS files): **523 tests / 1221 assertions**,
  clean.

## 10. Dynamic cohort resolution proof

`AcademicCohortAudienceResolverTest`'s
`resolving_a_grade_audience_after_enrollment_changes_reflects_current_placement`-
class cases (and the equivalent Section case) explicitly: create an
enrollment, resolve the audience, alter the `StudentEnrollment` (move
the student out of the cohort), resolve again — the second resolution
reflects the changed placement, never a stale/materialized list. No
scheduled-cohort-membership materialization step exists anywhere in
`AnnouncementService`/`GradeAudienceResolver`/`SectionAudienceResolver`.

## 11. LoginThrottleTest flake — root cause and resolution

**Step A (isolated pre-run)**: `LoginThrottleTest` alone —
**2 tests / 47 assertions, clean.**

**Step B (first full-suite run)**: 1384 tests, 1 error
(`StudentEnrollmentTest::a_second_active_enrollment_is_allowed_once_the_first_is_no_longer_active`,
unrelated — see §12) and 1 failure
(`LoginThrottleTest::the_seventh_login_attempt_within_a_minute_is_throttled`,
received 429 instead of the expected 302 on the very first attempt).

**Step C (isolated rerun after failure)**: not needed as a second
data point — root cause was conclusively identified directly (below)
before rerunning.

**Step D (root cause)**: `phpunit.xml` sets
`<env name="CACHE_STORE" value="array"/>`, intending an isolated,
in-memory, per-process cache store for every test run. However, this
integration worktree's `apps/platform/.env` (loaded into the container
via Docker Compose's `env_file:` directive, exactly like `DB_DATABASE`
in the incident `TestDatabaseGuard`'s docblock documents) already sets
`CACHE_STORE=redis` as a real process environment variable — and per
the same mechanism documented in root CLAUDE.md rule 52, PHPUnit's
`<env>` block only fills in variables that are **not already set**; it
does not override one. The result: every test in the full suite was
actually using the **real, persistent, shared Redis instance** for its
rate-limiter cache, not the isolated `array` store `phpunit.xml`
intended. `RateLimiter::for('login', ...)`'s per-minute counter for
IP `127.0.0.1` therefore accumulated real state across many unrelated
tests earlier in the 1384-test run, so by the time `LoginThrottleTest`
ran, the shared counter was already past its 6-per-minute threshold —
its very first attempt returned 429 (already throttled) instead of the
expected 302.

This is conclusively **test-harness environment leakage, not an
application regression**: `App\Providers\RateLimiterServiceProvider`
itself was not touched by Phase 5B, and the same class of leak (only
for `DB_DATABASE`) is exactly what `TestDatabaseGuard` was already
built to catch — this is the identical mechanism recurring for a
different variable.

**Fix applied**: none in application code. The test invocation was
corrected to explicitly pass `-e CACHE_STORE=array` (alongside the
already-necessary explicit `DB_PORT`/`REDIS_PORT`/`QUEUE_CONNECTION`/
`MAIL_MAILER` overrides this worktree's Docker Compose invocation
requires — the same class of "the container's `.env` already set a
conflicting real value" issue recurred for four other variables during
this gate, all resolved the same way: explicit `-e` override, no code
change). The real, shared Redis instance was never flushed — only the
per-invocation environment variable was corrected, so the store tests
actually ran against changed from real Redis to the isolated `array`
store `phpunit.xml` always intended.

**Final deterministic status**: two consecutive full-suite runs after
the fix, both clean — see §13. `LoginThrottleTest` passed cleanly in
both.

## 12. StudentEnrollment date-window flake — root cause and resolution

`StudentEnrollmentTest::a_second_active_enrollment_is_allowed_once_the_first_is_no_longer_active`
(line 187) called `createStudentEnrollment()` with an explicit,
hardcoded `'ends_on' => '2026-08-01'` but left `starts_on` to
`StudentEnrollmentFactory`'s default,
`fake()->dateTimeBetween('-6 months', 'now')`. Since the real "now" at
the time this gate ran was 2026-08-24, that default's range extends
past the hardcoded `2026-08-01` end date; a random draw landing after
August 1st (a real, non-negligible probability, not a one-in-a-million
edge case) produces `starts_on > ends_on`, violating the
`student_enrollments_date_range_check` CHECK constraint — the
constraint correctly rejecting an invalid row the test fixture itself
was capable of accidentally generating.

**Root cause**: nondeterministic factory default combined with a
fixed comparison date in test code, not a production defect — the
CHECK constraint (`ends_on IS NULL OR ends_on >= starts_on`) behaved
exactly as designed.

**Fix applied**: pinned `starts_on => '2026-07-01'` explicitly on that
same fixture call, alongside the existing `ends_on => '2026-08-01'`,
removing the test's dependence on the factory's random default
entirely. Verified no other call site in the merged tree has the same
hazard (`grep` for `createStudentEnrollment(...)` calls supplying only
`ends_on` without an explicit `starts_on` found exactly one other
match, `EnrollmentRolloverSchemaTest.php:300`, which already pins both
dates explicitly).

Additionally widened `StudentEnrollmentFactory`'s own default
`roll_number` range from `fake()->unique()->numberBetween(1, 60)` to
`(1, 100000)` — the narrow 60-value range had zero slack against the
Phase 5B.3 scale test's own 60-enrollment fixture (each `create()`
call evaluates the full default array, including `roll_number`, even
when the caller immediately overrides it — so 60 exact draws against a
pool of exactly 60 possible values left no margin for any other test
in the same process also touching this factory). This was caught as
an intermittent single-test failure in isolated 5B.3-suite reruns
during this gate (§9) before the full-suite run, and fixed the same
way — a wider constant, not a change to any production uniqueness
rule (the real DB-level uniqueness constraint, scoped to
`(school_id, academic_year_id, section_id, roll_number)`, is
untouched).

Both fixes are confined to test-only files
(`tests/Feature/StudentEnrollment/StudentEnrollmentTest.php`,
`database/factories/StudentEnrollmentFactory.php`); no application
logic, validation rule, or database constraint was weakened.

## 13. Full regression — final clean results

Two consecutive full-suite runs on the integrated tip, both from a
freshly reset `school_os_test` database, both fully clean:

- **Run 1**: 1384 tests, 4271 assertions, 0 failures, 0 errors, 0
  skips. (00:01:53)
- **Run 2**: 1384 tests, 4271 assertions, 0 failures, 0 errors, 0
  skips. (00:02:05)

Test count (1384) matches the Phase 5B.3 gate's own reported total
exactly (previous overall baseline before Phase 5B.3 was 1349 tests;
+35 from Phase 5B.3's own new test files, none added in this gate).
Assertion count (4271) differs by +6 from Phase 5B.3's own
self-reported 4265 — no test files were added in this gate, only two
existing test-only files were corrected (§11–§12); this is not
evidence of a missing/extra test and is noted here for completeness
rather than treated as a discrepancy requiring further action.

## 14. Quality gates

| Gate | Result |
| --- | --- |
| `vendor/bin/pint --test` | PASS — 717 files |
| `vendor/bin/phpstan analyse --memory-limit=512M` | PASS — 0 errors; no `phpstan-baseline.neon` file exists in the repository at all, so zero new baseline entries by construction |
| `npm run format:check` (Prettier) | PASS |
| `npm run type-check` (`vue-tsc --noEmit`) | PASS — 0 errors |
| `npm run lint` (ESLint) | PASS — 0 errors (2 pre-existing warnings in `resources/js/Components/Pagination.vue`, a file untouched by any Phase 5B work — confirmed via `git diff main` showing no changes to it) |
| `npm run build` | PASS — `public/build/manifest.json` + hashed assets produced |

## 15. Safety

- `COMMUNICATION_EMAIL_ENABLED` defaults to `false`
  (`config/communications.php:25`) and was not overridden to `true`
  anywhere in this integration.
- All email-path tests use `Mail::fake()`/the `array` mailer
  (`MAIL_MAILER=array` explicitly set for every test invocation this
  gate performed) — no real provider credentials, no live email/SMS/
  WhatsApp/push send occurred at any point.
- No deployment, staging, or production system was touched. No cloud
  resource was provisioned. Nothing was pushed to `origin` at any
  point in this gate.
- The shared Redis instance (`school-os_redis_data`, used by other
  concurrent worktrees on this machine) was never flushed — the
  `LoginThrottleTest` fix (§11) corrected only this gate's own test
  invocation environment variables, never touched shared cache state.

## 16. Deferred work

Everything explicitly out of scope for this gate per the brief (§29)
remains deferred and untouched: SubjectOffering/teaching-group/club/
house/transport/hostel/attendance/fee-status audiences, portals,
account provisioning, conversation participation, SMS/WhatsApp/Push
channels, provider webhooks. No new feature surface was added in this
gate — only merge verification and two narrow test-only fixture fixes.

## 17. Local `main` merge

All gates in §31 of the brief passed. Merged:

```
git checkout main
git merge --ff-only integration/phase-5b-student-guardian-communications
```

- Integration branch tip: `6e7a24d371ebc520aafa7d4c789a4536c296b383`
- Local `main` before: `ba0000964db91c72517e54093cc3c871a997467c`
- Local `main` after: `6e7a24d371ebc520aafa7d4c789a4536c296b383`
  (fast-forward — the integration branch was created from `main` and
  only advanced by the merge commit plus this gate's own two test-only
  fix commits, so a fast-forward was both possible and history-
  preserving)
- `origin/main`: unchanged (`4cfcd61b806e0d532fa14878216eeaf9b81131ea`)
- Nothing pushed.

## 18. Final verdict

**PHASE 5B INTEGRATED LOCALLY — PASS**

## 19. Next action

Recommend: **Phase 5B Remote Push / Publication Gate** — pushing
`main` (and, if desired, the feature/integration branches) to
`origin`, updating any deployment-adjacent documentation, and
confirming `origin/main`'s CI runs clean before any further phase
begins. Not performed here; this gate does not push.
