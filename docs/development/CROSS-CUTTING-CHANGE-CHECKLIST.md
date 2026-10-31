# Cross-cutting change checklist

**Purpose.** Several first CI candidates failed on a guard that was not
updated locally (SIS 11.4: classified-capability count; SIS 11.5: the
user-reference count). This is the one place that lists every secondary
guard, pin and catalogue a change must touch. Introduced by SIS 11.H1; the
source audit is `docs/sis/SIS-11-R1-MID-EXPANSION-AUDIT.md` §8.1.

**Paths** are under `apps/platform/`. **Run** the named tests with
`ddev test <paths>`. Use `ddev test --reset-db` when the seeder or a
migration changed.

## Before every candidate push
- `ddev test tests/Feature/Identity`. Since SIS 11.H1 it passes under DDEV
  (forced test `APP_URL`); any failure is real.
- Larastan on every touched controller and service.
- The real-object-storage tests skip locally with a stated reason
  (`Tests\Concerns\RequiresObjectStorage`). CI runs them against versitygw
  and **fails** them if storage is missing, so a local skip is never
  evidence that they pass.

## 1. A School capability is added, renamed or removed
- **Seeder:** `database/seeders/CapabilityAndRoleSeeder.php` (definition
  and role grants).
- **Counts:** `tests/Feature/Students/SisResidualHardeningArchitectureTest.php`
  (School count, total count).
- **Classification:** `tests/Feature/Identity/Staff/StaffRoleGrantAuthorityTest.php`
  (classified count and its message) and
  `app/Support/Authorization/CapabilityClasses.php` `CLASSES`.
  - **Grant right:** for a grant-right key, also `CapabilityClasses::GRANT_RIGHTS`
    and `StaffRoleGrantAuthorityTest::EXPECTED_GRANT_RIGHTS`.
- **Module:** `ModuleCatalogue` / `ModuleCatalogueArchitectureTest`, unless a
  prefix rule already maps the key.
- **Approval-shaped keys** (`.approve`, `.request`, `.decide`, `.review`,
  `.authorise`, `.verify`, `.publish`, `.sign_off`):
  `app/Support/Approval/ApprovalWorkflowRegistry.php` (`WORKFLOWS`, or
  `NOT_WORKFLOWS` with the reason) and `ApprovalWorkflowRegistryTest`.
- **Campus-adopted keys:**
  - `CampusScopeCatalogue` (`ADOPTED`, `WHOLE_SCHOOL_OPERATIONS`);
  - `tests/Feature/Authorization/Campus/CampusAdoptionImpactTest.php`
    (`HOLDERS`, the `*_CAMPUS_CONFERRED` lists);
  - run `CampusScopeAdoptionArchitectureTest` early.
- **Docs:** the School Administrator Guide when an operator sees it, plus
  the module documentation.
- **Regression:** the authorization allow/deny tests for the new key.

## 2. A role's capability set changes
- `tests/Feature/Authorization/StaffRoleCatalogueSnapshotTest.php` (snapshot,
  holder lists, role count).
- `CampusAdoptionImpactTest` `HOLDERS`.
- `ProductionStaffRolesTest` (roles, sensitivity labels).
- `StaffRolePresentation` (new role).

## 3. A column referencing `users` is added
- `app/Support/Retention/Erasure/UserReferenceCatalog.php` (treatment:
  usually `RETAIN_REFERENCE`).
- `tests/Feature/Identity/UserReferenceCatalogTest.php`: **both** the live
  total and the per-treatment count.
- **Decision-shaped** (`verified_by`, `decided_by`, `approved_by`, …):
  also item 1's approval registry.
- **Retention and anonymization:** decide RESTRICT (history) vs SET NULL,
  per rule 97.

## 4. A table is added or changed
- **RLS:** `TenantRls::enable()` / `disable()`, plus
  `tests/Feature/Postgres/ElevationRlsIsolationTest.php` (RLS table count
  and its comment).
- **Runtime privileges:** REVOKE DELETE on history; the retention identity
  gets only what a purge reads (item 6).
- **Keys:**
  - tenant composite keys `(x, school_id)` (rule 70);
  - indexes for every key and filter;
  - FK delete behaviour (RESTRICT for history, cascade only with its owner).
- **Retention:** `app/Support/Retention/TenantRetentionCatalog.php` (live
  closure-readiness comparison).
- **Student-rooted references** (`students`, `applicants`, `documents`, …):
  - `tests/Feature/Retention/StudentRetentionClassificationTest.php`;
  - the purging service's `ReferencingRows` exclusions or a participant's
    `tables()`;
  - `ResidualRetentionClassificationTest`.
- **Rollback:** `.claude/skills/rollback-proof/run.sh <n>` (IDENTICAL plus
  `platform:verify-database` failed=0).

## 5. A route or route parameter is added
- **Every School route:** `capability:` middleware or a
  `tests/Support/Authorization/SchoolRouteCoverage.php` entry (C2–C6).
- **Route classification:** `RouteUniverse`, `PlatformRouteCoverage`.
- **Parameters:** `app/Support/Routing/WebRouteParameters.php` (UUID /
  NOT_UUID). Run `WebRouteParametersGuardTest`.
- **Adopted keys:** `CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS` when the
  route is whole-School.
- **API:** the OpenAPI contract
  (`packages/contracts/openapi/school-os-api.yaml`), then
  `npm run generate && npm run type-check` in `packages/shared-types`, plus
  the module's API route-count pins (e.g. `LeaveOpenApiCoverageTest`).

## 6. The retention identity must read a new table
- `app/Support/Operations/DatabaseRoleVerifier.php` `RETENTION_TABLE_READS`.
- A GRANT in the migration, and a REVOKE in `down()`.
- `tests/Feature/Retention/RetentionIdentityTest.php` (exact and
  column-level grants).
- Retention tests run with `CommitsRetentionFixtures` (a separate session).

## 7. A read model handles Campus scope
- Take the viewer's resolved `CampusScope` (`CampusScopeResolver`). Never
  construct one by hand.
- Run `CampusScopeAdoptionArchitectureTest` and the coverage guard early.

## 8. A Documents owner type is added
- **Owner registry:** `DocumentOwner`, the `documents_exactly_one_owner_check`
  arm, and `Document::$fillable`.
- **Retention maps:** `DocumentParentRetention::OWNER_COLUMNS` and
  `DocumentRetentionEligibility::OWNER_RETENTION`.
- **Tests:**
  - `tests/Feature/Documents/StorageOrphanRetentionTest.php` (owner-map
    literal, seam callers);
  - `DocumentReadServiceStructuralTest` (public methods);
  - the `documents` parent in `StudentRetentionClassificationTest`.
- **Authorization:**
  - the narrow entry points decide their capability floor themselves (see
    `ApplicantDocumentFloor`);
  - tenant binding by composite key;
  - IDOR and download tests;
  - an ADR 0012 amendment.

## 9. A report or export entry is added
- `StudentReportRegistry` (`pages()`, `RENDERED`, `EXPORTERS`).
- `StudentReportRegistryTest` (ordered keys, routes, sort pins),
  `StudentReportsTest` (hub lists), `StudentReportExportTest`.
- Organization setup/report architecture tests, when a setup area changes.

## 10. A mutation that can be edited concurrently
- **Version token:** reuse a version digest of the written fields
  (`StudentProfileVersion`, `GuardianProfileVersion`, `SectionVersion`,
  `StudentGuardianRelationshipVersion`).
- **Check:** compare it under the row lock; refuse with a 409 domain
  exception (web: `refusal`; API: the error envelope).
- **Required** on the endpoint (a missing token is a 422), and documented
  in the OpenAPI contract.
