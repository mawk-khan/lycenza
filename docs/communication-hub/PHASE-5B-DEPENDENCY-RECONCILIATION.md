# Phase 5B — Dependency Reconciliation Gate

**Outcome: READY. `main` (with the pinned Phase 1B enrollment
integration) is merged into `feature/phase-5b-student-guardian-communication-audiences`,
fully verified. Nothing was pushed; no branch was deleted; Phase 5B.3
audience resolvers were not implemented.**

## 1. Why reconciliation was required

Phase 5B.3 (Class/Section/Grade Communication Audiences) discovered
that Student had no relationship to academic placement anywhere on
the Phase 5B branch — that work existed only on a separate,
independently-advancing branch. A dedicated integration gate merged
the Phase 1B enrollment foundation into local `main`. This gate brings
that updated `main` into Phase 5B so 5B.3 can build against real
enrollment data, without yet writing any cohort-resolver code.

## 2. Phase 5B starting SHA

`b19b01ee1bc353848fb32e8e7486e88218836bdb` (Phase 5B.2 tip — confirmed
via `git log`, both 5B.1 `732d35f` and 5B.2 `b19b01e` verified as
ancestors before merging).

## 3. Local main dependency SHA

`ba0000964db91c72517e54093cc3c871a997467c` — confirmed unmoved from
the Phase 1B Integration Gate's own report before this merge began.

## 4. Pinned Phase 1B source included in main

`5fe6f4f5dde5b96a052916940868a70d97a8f242` — confirmed an ancestor of
`main` (`git merge-base --is-ancestor`).

## 5. Explicitly excluded concurrent work

`7b3f5c7...` (the Phase 1B source branch's own subsequent "add
rollover admin endpoints" commit) — confirmed **not** an ancestor of
`main` before this merge, and this gate merged `main` (not the Phase
1B source branch), so it remains excluded from Phase 5B exactly as
required.

## 6. Merge-base

`4cfcd61b806e0d532fa14878216eeaf9b81131ea` (the Phase 5A publication
tip both Phase 1B's integration and Phase 5B originally branched from).

## 7. Conflicts

Exactly 3 — fewer than the 4 seen in every prior integration in this
lineage, because `DashboardController.php`/`Dashboard.vue`/
`CapabilityAndRoleSeeder.php` had already reconciled Communications
and Enrollment additions cleanly (Phase 5B's copies of those files
already included the Phase 5A Communications nav/capability entries
from when Phase 5B first branched off post-Phase-5A `main`; Phase 1B's
Enrollment additions to those same files were new, disjoint insertions
Git could auto-merge without conflict):

| File | Conflict shape | Resolution |
| --- | --- | --- |
| `StudentController.php` | Both lines modified `show()`'s method signature and its Inertia-payload construction | Kept both injected services (`AccountLinkService` + `StudentEnrollmentReadService`); switched to main's `$props = [...]` array-then-mutate pattern (required by the enrollment conditional block) with the `accountLink` key added alongside `student` |
| `Students/Show.vue` | Both lines added `<script>` interfaces, `Props` fields, and composer functions | Kept both interface sets, both `Props` fields, both function blocks; the `<template>` sections had already auto-merged cleanly into non-overlapping regions |
| `routes/web.php` | Both lines added routes inside the same `app/students` prefix group | Kept both the account-link routes and the enrollment `create`/`store` routes as siblings |

## 8. Reconciliations

No file was resolved by discarding either side. Every conflict was a
genuine "both lines extended the same anchor point" case, resolved by
keeping both additions — the same additive discipline every prior
integration gate in this project has used.

## 9. Student model combined architecture

```text
Student
├── guardianRelationships / guardians()      (Phase 1A)
├── enrollments()                             (Phase 1B — HasMany<StudentEnrollment>)
```

No Phase 5B commit ever added a relationship directly to the `Student`
model — `AccountLinkService::activeLinkForStudent()` queries
`student_guardian_account_links` independently, keyed by
`student_id`, not via an Eloquent relation on `Student` itself. Both
domains reach the same `Student` row without either needing to know
about the other's schema.

## 10. StudentEnrollment architecture

Unchanged from the Phase 1B Integration Gate's own report:
`student_enrollments` (`student_id` → `academic_year_id`/`campus_id`/
`grade_level_id`/`section_id`, `status`, partial unique
"one active per Student per AcademicYear" index). Confirmed present
and query-able on the reconciled branch (§17).

## 11. Account-link architecture

Unchanged from Phase 5B.2: `student_guardian_account_links` (Student/
Guardian → `SchoolMembership`, explicit, RLS-enabled, one active link
per domain identity, one active persona link per membership). Untouched
by this merge.

## 12. Guardian architecture

No regression: `StudentGuardianRelationship`/`GuardianContact` (Phase
1A) and the Guardian account-link/reachability work (Phase 5B.1/5B.2)
are all present, unmodified by this merge — Phase 1B never touches
`Guardian` at all (confirmed: Phase 1B's diff contains zero references
to `App\Domain\Guardians`).

## 13. Capability compatibility

`students.manage`/`guardians.manage` (Phase 5B.2's reused account-link
capabilities) are untouched by Phase 1B — Phase 1B added its own
independent `enrollments.view`/`enrollments.manage` pair, never
redefining or re-granting the existing Student/Guardian identity
capabilities. `school_admin`/`principal` combined grants include all
four capability families (`communications.*`, `students.*`,
`guardians.*`, `enrollments.*`) with no key colliding or being
silently dropped.

## 14. Route compatibility

201 named routes, zero duplicates (`php artisan route:list`). No
academic-audience routes were added — only the pre-existing Phase 1B
`app/enrollments`/`app/students/{student}/enrollments/*` routes and
the pre-existing Phase 5B `app/students/{student}/account-link/*`/
Communications routes.

## 15. Migration compatibility

All migrations from every line coexist: baseline, Phase 1A, Phase 5A
(27), Phase 1B (5), Phase 5B.1 (6), Phase 5B.2 (2) — 87 total. No
historical migration was modified. No new migration was needed for
this reconciliation (nothing about combining the two lines' schemas
required a corrective migration).

## 16. RLS

`student_enrollments`/`enrollment_rollover_*` (Phase 1B) and
`student_guardian_account_links`/`communication_announcement_domain_audience_members`/
widened Communications tables (Phase 5B) are all independently
RLS-enabled, verified via their own existing suites (§20). No shared
RLS policy/session-GUC conflict — both lines use the identical
`App\Support\Tenancy\TenantRls`/`TenantContext` machinery Phase 1B's
own `TenantContextAbortedTransactionTest` hardening (already on `main`
before this merge) strengthens for both domains equally.

## 17. Fresh migration result

Ran `platform:test-db-reset --force` against the disposable
`school_os_test` database. All **87 migrations** applied successfully
in filename-sorted order. Schema verified directly: `student_enrollments`,
`enrollment_rollover_*`, `student_guardian_account_links`, and
`communication_announcement_domain_audience_members` all present and
correctly constrained.

## 18. Phase 5B.1 regression

**49 tests, 122 assertions, 0 failures** — Student/Guardian audience
resolution, GuardianContact EMAIL, reachability, snapshot immutability,
RLS.

## 19. Phase 5B.2 regression

**42 tests, 115 assertions, 0 failures** — account links, IN_APP
reachability, deduplication, unlink lifecycle, audit, RLS, multi-school.

## 20. Phase 1B regression

**261 tests, 923 assertions, 0 failures** — enrollment core/lifecycle/
read-service/API/UI/RLS, the full committed rollover suite, and
`TenantContextAbortedTransactionTest`.

## 21. Full Communication regression

**477 tests, 1393 assertions, 0 failures** (name-filtered subset
covering every Communications-domain test class).

## 22. Full application regression

**1349 tests, 4179 assertions, 0 failures** — the entire reconciled
suite (baseline + Phase 1A + Phase 5A + Phase 1B + Phase 5B.1 + Phase
5B.2 + this gate's 3 new coexistence tests).

## 23. Quality gates

| Gate | Result |
| --- | --- |
| `vendor/bin/pint --test` | PASS — 703 files |
| `vendor/bin/phpstan analyse --memory-limit=512M` | PASS — 376 files, 0 errors, no baseline |
| `npm run format:check` | PASS |
| `npm run type-check` | PASS |
| `npm run lint` | PASS — 0 errors, 2 pre-existing unrelated warnings |
| `npm run build` | PASS |

## 24. Phase 5B.3 readiness

**Yes.** `student_enrollments` (via `StudentEnrollmentService`/
`StudentEnrollmentReadService`) gives Phase 5B.3 the exact
authoritative, deterministic current-placement query it needs:

```php
StudentEnrollment::query()
    ->where('grade_level_id', $gradeLevelId)
    ->where('academic_year_id', $academicYearId)
    ->where('status', 'active')
    ->pluck('student_id');
```

No Communications-owned enrollment shadow table was created or is
needed.

## 25. Architecture invariants (proven directly, `EnrollmentAccountLinkCoexistenceTest`)

> Academic enrollment establishes Student placement, not authenticated
> account identity. An enrolled Student without an explicit account
> link remains unavailable for IN_APP communication.

Proven by `an_enrolled_student_with_no_account_link_remains_in_app_unreachable`
— publishing a `student`-audience Announcement to an actively-enrolled
but unlinked Student produces **zero** deliveries.

> Phase 5B reconciliation consumes the Phase 1B implementation already
> integrated into local `main`. It does not merge the independently
> advancing Phase 1B source branch directly.

Confirmed in §5 above — `7b3f5c7` is not reachable from Phase 5B's new
history.

Also proven: a Student can carry both an active Enrollment and an
active account link simultaneously with no FK/RLS conflict
(`a_student_can_have_both_an_active_enrollment_and_an_account_link_with_no_conflict`),
and cross-School isolation holds across both combined relationships
(`a_school_cannot_reach_across_the_tenant_boundary_through_the_combined_enrollment_and_link_relationships`).

## 26. Safety

No Phase 5B.3 feature code (no Grade/Class/Section audience resolver,
no cohort selector UI). `COMMUNICATION_EMAIL_ENABLED=false` unchanged.
No provider credentials, no live sends. No staging/production changes.
Nothing pushed. No branch deleted.

## 27. Next recommendation

Resume **Phase 5B.3 — Class / Section / Grade Communication
Audiences** against this reconciled branch. Not started here.
