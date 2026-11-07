> **HR.R1-C10 architecture inventory: R1-01, R1-02, direct reads, XRP-3, XRP-4, XRP-6.** Read-only, against `82e70a8a`, by an independent pass. Citations are that commit's. Every recommendation is a **proposal**, and every decision is **pending**. Items since corrected by C10 are in `HR-R1-0-AUDIT-REGISTER.md`, which wins where the two differ.

# HR.R1 architecture inventory A: R1-01, R1-02, direct Employee reads, XRP-3/4/6

Commit: `82e70a8a` (detached HEAD). All paths are relative to `apps/platform/` unless they start with `docs/`.

## 0. How to read this

**Method.** Read-only: grep, sed, cat, git, plus one read-only text-scan script kept in the scratchpad. No test, migration or database access. The graph tools were not used; every claim comes from reading the source, so no result depends on index coverage.


**Words used.**
- "Canonical service": the service the ownership map or a rule names.
- "True duplicate writer": two code paths performing the same operation on the same table, with neither delegating to the other. It is not the same as several entry points, or several named operations.

## 1. R1-01 one writer

### 1.1 Exact text

From `docs/hr/HR-R1-CLOSURE-CONTRACT.md:38`:

> | R1-01 | **No duplicate source of truth.** Every row of `HR-DOMAIN-OWNERSHIP-MAP.md` §1 has exactly one writer | grep writers per table; architecture guards |

C9 preflight (`:205`): "No writer outside the owning domain in a sample of 16 models. Inside HR, assignment and Employee have several sites and no guard pins it. What "one writer" means needs deciding".

### 1.2 Headline

- **Writes to HR, Leave, StaffAttendance or WorkforceTasks tables from a foreign domain: 0** (production code). The only imports of Leave/StaffAttendance/WorkforceTasks Infrastructure outside those namespaces are their own App controllers and `StaffSelfServiceController`, which use model constants and contain no write-shaped line. Nothing outside HR writes `Employee` or `EmploymentRecord` (grep of `$employee|$employment|$record...->update|save|delete|forceFill` outside HR: every hit is another domain's own `$record`).
- **Controller writes to these tables: 0.** `app/Http/Controllers/App/{HR,Leave,StaffAttendance,Payroll,TeachingAssignments}` have no write-shaped line. In `app/Domain/HR/Http/Controllers` the only hits are `$service->update(...)`.
- **True duplicate writers: 0 verified.** Five tables have more than one Application class writing them (1.5). Each is a separate named operation. Two of those need attention: the map names one writer for `employee_placement_changes` but the code has two, and `end()` + `create()` + `setPrimary()` composes a move outside the named-change path (1.6).
- **Writes that bypass the canonical service, in production paths: 0.** Bypasses exist only in the Demo seeders, which refuse to run outside local DDEV (`DemoEnvironmentGuard:40-41`).
- **R1-01 as worded cannot be evaluated literally.** Many §1 rows are not tables (see 1.4).

### 1.3 Matrix: required tables

Write-site class abbreviations: **CS** canonical service, **2S** second service in the same domain, **C** controller, **I** import, **D** seeder/demo, **T** trigger, **R** retention, **X** cross-domain writer, **M** migration only.

Runtime privileges are inferred from migrations and `DatabaseRoleVerifier`, not read from a database. Baseline: the default grant is SELECT, INSERT, UPDATE, DELETE (`infrastructure/docker/postgres/init/01-roles.sql:42-43`). `2026_11_30_090200_bound_retention_deletes.php:245-246` then revokes runtime DELETE from its `DELETES` list minus `RUNTIME_KEEPS`.

| Table | Canonical service | Production write sites | Runtime role (evident) | Notes |
|---|---|---|---|---|
| `employees` | `EmployeeService` | **CS** `EmployeeService.php:117-124` (create), `:191` (update), `:237` (archive), `:271` (restore), `:337` (unlink), `:403` (link). **T** none write it. **R** `EmployeeRecordRetentionService.php:143`. **D** `DemoDataBuilder.php:665`, `DemoSisData.php:583`. Entry points through the CS: `EmployeeOnboardingService`, `EmployeeImportService.php:146`, `EmployeeAccountLinkCorrectionService.php:127`, the HR controllers | S, I, U; DELETE revoked (`DELETES`, not in `RUNTIME_KEEPS`) | One canonical owner with many entry points. The preflight's "several sites" are all inside one class |
| `employment_records` | `EmploymentService` | **CS** `EmploymentService.php:183` (createRecord), `:244` (update), `:327` (end), `:419` (notice). **T** `hr_employment_terms_project` updates the projection columns (`2027_01_25_090000:172`). **R** `EmployeeRecordRetentionService.php:142`. **D** `DemoDataBuilder.php:672`, `DemoSisData.php:589`. **M** `2027_01_25_090000:90`. Entry points: `EmployeeLifecycleService.php:102,170`, `SeparationCaseService`, onboarding, import | S, I, U; DELETE revoked | Trigger writes a projection only. Guards `trg_employment_records_*` (`DatabaseRoleVerifier:229-231,250,263`) |
| `employment_terms` | `EmploymentTermsService` | **CS** `Workforce/EmploymentTermsService.php:99`. **T** initial row `2027_01_25_090000:197`. **M** backfill `:185`. FK cascade with its employment | S, I; UPDATE, DELETE revoked (`:191`) | Append-only. One service plus one trigger for the first row |
| `employee_assignments` | Not named by the map (`:27`). `EmployeeAssignmentService` by convention | **CS** `EmployeeAssignmentService.php:149` (createRecord), `:202` (end), `:338`, `:341` (setPrimaryRecord). **2S** `Workforce/PlacementChangeService.php:105` (close source), `:118` (new primary; row made by `createRecord()`), `EmploymentService.php:357,360` (employment end). **R** `EmployeeRecordRetentionService.php:141`. **D** `DemoDataBuilder.php:685`, `DemoSisData.php:593`. **M** `2027_01_26_090000:231` (a `down()` best-effort pointer) | S, I, U; DELETE revoked | Three services, three named operations, all rule-sanctioned (rule 130). No static pin that these are the only writers (see 1.7) |
| `employee_placement_changes` | `PlacementChangeService` (map `:25`) | **CS** `Workforce/PlacementChangeService.php:128`. **2S** `EmployeeAssignmentService.php:357` (`initial_placement`, `primary_correction`) | S, I; UPDATE, DELETE revoked (`2027_01_25_090000:365`) | **Map and code disagree.** Rule 130 sanctions `setPrimary()` as a "recorded primary correction", but map `:25` lists primary correction under `PlacementChangeService` |
| `reporting_relationships` | `ReportingRelationshipService` | **CS** only: `Reporting/ReportingRelationshipService.php:110,207` via `insert()` `:357`, `:204` (void), `:263`, `:349`. Cascades with its subordinate employment. `ReportingHierarchyService` delegates (`:7,:29`). **M** none at runtime | S, I, U; DELETE revoked (`2027_01_26_090000:197`) | Exactly one class |
| `employment_separation_cases` | `SeparationCaseService` | **CS** `Separation/SeparationCaseService.php:93`, `:411` | S, I, U; DELETE revoked (`2027_01_30_090000:363`) | Exactly one class |
| `employment_separation_events` | `SeparationCaseService` | **CS** `:202`, `:290`, `:448` | append-only (`:366`) | History written by its parent's service |
| `separation_clearance_items` | `SeparationCaseService` | **CS** `:432` (snapshot), `:289` (clear) | S, I, U; DELETE revoked (`:364`) | Exactly one class |
| `separation_clearance_requirements` | `SeparationClearanceRequirementService` | **CS** `Separation/SeparationClearanceRequirementService.php:47`, `:83` | S, I, U; DELETE revoked (`:365`) | Exactly one class |
| `workforce_tasks` | Not named; rule 139 names `TaskGenerationService` for generation | **CS** `TaskGenerationService.php:177` (raw `INSERT ... ON CONFLICT`, generated occurrences). **2S** `TaskAssignmentService.php:120` (ad hoc task), `:79` (cancel on reassign). **2S** `TaskWorkflowService.php:55`, `:86`, `:108` (transitions) | S, I, U; DELETE revoked (`2027_01_31_090000:313`) | Three classes, three operations. Rule 139: "One generator" |
| `workforce_task_events` | as above | `TaskGenerationService.php:184`, `TaskAssignmentService.php:80,131`, `TaskWorkflowService.php:58,89,109` | append-only (`:316`) | History follows the parent write |
| `workforce_task_generation_runs` | `TaskGenerationService` | **CS** `:70`, `:80`, `:93`, `:99` | S, I, U; DELETE revoked | Exactly one class |
| `workforce_task_categories`, `_templates`, `_template_versions` | `TaskCatalogueService` | **CS** `TaskCatalogueService.php:42,61`; `:75,117`; `:137` | categories, templates: DELETE revoked; versions append-only (`:315`) | Exactly one class |
| `employee_account_links` | Triggers only (rule 145) | **T** `employees_record_account_link` (`2027_02_08_090000:99,103`), **T** `employee_account_link_corrections_apply` (`2027_02_10_090100:191`). **M** cutover `2027_02_08_090000:85`. Cascades with Employee | SELECT only: INSERT, UPDATE, DELETE revoked (`2027_02_08_090000:80`); pinned by `DatabaseRoleVerifier:129-130,678` | No PHP writer. The strongest row in the table. `employee_account_link_void` was dropped (`2027_02_10_090100:77`) |
| `employee_account_link_corrections` | `EmployeeAccountLinkCorrectionService` | **CS** `AccountLinks/EmployeeAccountLinkCorrectionService.php:83`, `:166` | S, I, U; DELETE, TRUNCATE revoked (`2027_02_10_090100:74`) | Exactly one class |
| `employee_documents` | `EmployeeDocumentService` | **CS** `EmployeeDocumentService.php:122-136`, `:192`, `:223`. **R** `EmployeeRecordRetentionService.php:135`. **M** `2027_01_27_090000:145` | S, I, U; DELETE revoked (`2027_01_27_090000:178`) | Bytes are Documents' (`DocumentService::createForEmployee()`); no double ownership of the row |
| `leave_ledger_entries` | `LeaveLedgerService` | **CS** `LeaveLedgerService.php:316` only. Entry points: `LeaveRequestService.php:262,470`, `LeaveYearCloseService.php:115-119,173-177`, `Accrual/LeaveAccrualService.php:200`, `LeaveAdminController.php:238,253,265`, `LeaveLedgerController.php:73,134,160`. **R** `retention_expire_leave_employee_evidence` (`2026_11_23_090000:147,156`) | append-only (`2026_11_17_090000:375`) | The model case: one create call, many entry points, a guard test |
| `leave_requests` | `LeaveRequestService` | **CS** `LeaveRequestService.php:321`, `:569`. **R** `2026_11_23_090000:169` | S, I, U; DELETE revoked (`2026_11_19_090000:382`) | Exactly one class |
| `leave_request_days`, `leave_decisions` | `LeaveRequestService` | **CS** `:460`; `:548`. **R** `:163`, `:166` | append-only (`2026_11_19_090000:380`) | History |
| `leave_policy_assignments` | `LeavePolicyAssignmentService` | **CS** `LeavePolicyAssignmentService.php:60`, `:95`. **2S** `LeaveEligibilityService.php:185` (run, adds only, rule 133). **R** `2026_11_23_090000:176` | S, I, U; DELETE revoked (`2026_11_17_090000:378`) | Two services, two named operations, rule-sanctioned |
| `staff_attendance_records` | `StaffAttendanceService` | **CS** `StaffAttendanceService.php:258`, `:202`. **R** `2026_11_23_090000:223` | S, I, U; DELETE revoked (`2026_11_20_090000:166`) | Exactly one class, guard test |
| `staff_attendance_corrections` | `StaffAttendanceService` | **CS** `:186`. **R** `2026_11_23_090000:219` | append-only (`:165`) | Exactly one class, guard test |

### 1.4 Matrix: remaining §1 rows

HR-owned rows were grepped exhaustively. Rows owned by other domains are **lighter**: a targeted grep of each table's main model, not a full writer census.

| Map line | Row | Writer(s) found | Notes |
|---|---|---|---|
| 19 | School, Campus, profile, Network | `SchoolLifecycleService` (status), `SchoolProfileService.php:89`, **C** `SchoolSettingsController.php:66` (`$school->update($validated)`), `CampusAdministrationService.php:63,111`, D `DemoDataBuilder.php:259,372`, `DemoOrganizationData.php:206` | The controller write is the shape rule 76 cites as acceptable |
| 20 | Tenant boundary | Not a table | Cannot have a writer |
| 21 | Login identity, memberships, grants | `MembershipRoleAssignment` created in `StaffAccessService.php:426`, `StaffInvitationAcceptanceService.php:304`, `GuardianPortalRoleGrants.php:49`, and **X** `Platform/.../SchoolBootstrapAdministrationService.php:78` (rule 86 sanctioned). Invitations `StaffInvitationService.php:283`, `AccountInvitationService.php:192` | Several writers by design, each a named path (rules 86, 92) |
| 22 | Employee, employee number | see `employees`; counter: `EmployeeNumberAllocator.php:56,64` | One class |
| 23 | Employment | see `employment_records`. Map names two services; `EmployeeLifecycleService` delegates to `EmploymentService` (`:102,170`) | The map overstates: the lifecycle service writes nothing itself |
| 24, 25, 27, 31 | terms, placement changes, assignment, reporting | see 1.3 | |
| 26 | Historical placement | Reader, not a table | No writer applies |
| 28 | Department master | `DepartmentService.php:56,92,146,169`. D `DemoDataBuilder.php:633-635`, `DemoSisData.php:580` | One class. Default privileges (no REVOKE found) |
| 29 | Designation | `PositionService.php:44,73,104`. D `DemoDataBuilder.php:639`, `DemoSisData.php:581` | One class. Default privileges |
| 30 | Category / staff type | `EmployeeCategoryService.php:41,69,100`. D `DemoDataBuilder.php:630-631`, `DemoSisData.php:579`. Staff type: see terms | One class. Default privileges |
| 32 | HR profile sections (8 tables) | `EmployeePersonalDetailService.php:60`; `EmployeeAddressService.php:50,80`; `EmployeeEmergencyContactService.php:52,83,130,132`; `EmployeeNoteService.php:52,83`; `EmployeeQualificationService.php:62,101,137,159`; `EmployeeExperienceService.php:47,77`; `EmployeeCertificationService.php:49,88,124,146`; shared trait `Profile/ArchivesProfileRecords.php:44` (used only by these services). R `EmployeeRecordRetentionService.php:98,135`. D `DemoDataBuilder.php:730-746` | One service per table. DELETE revoked (`2027_01_27_090000:136`); `employee_personal_details` DELETE revoked by `bound_retention_deletes` |
| 33 | Reference codes | `Profile/EmployeeReferenceCodeService.php:64-70,104` | One class; DELETE revoked (`:181`) |
| 34 | HR document records | see 1.3 | Two owners by design: record and bytes |
| 35 | ActingEmployee | Resolver, not a table | No writer applies |
| 36 | Teacher ownership | `TeachingAssignmentService.php:154-166,221-226,292-297`; `ElectiveTeachingAssignmentService.php:115-126,175-180,246-251` | Guard: `TeachingAssignmentArchitectureGuardTest:69` |
| 37 | Work calendar | `StaffCalendarService.php:52` (overwrite), `:70`, `:88` (hard delete) | One class. Runtime DELETE remains on `staff_holidays`: `TABLES` loop leaves it outside both privilege lists at `2026_11_17_090000:375-378` (S-16, LVA-3) |
| 38, 39, 45, 49, 53, 57, 65 | not built | none | Rows with no writer. "Exactly one writer" is unsatisfiable as worded |
| 40, 41, 43, 44 | staff attendance, leave families | see 1.3. Also `LeaveTypeService.php:40,67,93`, `LeaveYearService.php:80,125,162`, `LeavePolicyService.php:107,122,235`, `LeaveYearCloseService.php:95,108,164`, `LeaveLedgerService.php:144` (allocation run), `Accrual/LeaveAccrualService.php:154,161,204,211,304`, `LeaveEligibilityService.php:179` | One class per table |
| 42 | Leave decisions by line managers | `LeaveRequestService.php:548` | Row spans Leave and Identity |
| 46 | Staff lifecycle events | **Stale row.** Says proposed `employment_lifecycle_events`, "workflow not built". The built owner is `SeparationCaseService` (rule 137) | Doc drift |
| 47 | Workforce tasks | **Stale row.** Says "proposed", "not built". Built: 1.3 | Doc drift |
| 48 | Student | `Student` writers not censused here | Out of HR scope; rules 118-122 |
| 50 | Student fee balance | `ChargeService.php:157`, others not censused | Out of HR scope |
| 51 | Inventory | `InventoryStockService.php:544` | Rule 105 |
| 52, 54 | Timetable, Transport | `TimetableScheduleService.php:159`, `TransportRouteAssignmentService.php:85`, `TransportStudentAssignmentService.php:100` | |
| 55 | Payroll calculation | `PayrollRunService.php:124,172,811,823`, `PayrollPostingService.php:188,280` | |
| 56 | Compensation | `CompensationService.php:125,218` | |
| 58 | Statutory identifiers | Admin services (`EmployeePfStatusAdminService.php:75`, `EmployeeEsiCoverageAdminService.php:101`, others not censused). **2S** `StatutoryPayrollCalculationService.php:327` writes `employee_esi_coverage` in calculation | Second service inside Payroll, noted only |
| 59 | Journal posting | `LedgerService.php:107,183` | |
| 60 | Communications | `AnnouncementService.php:192` (sample) | |
| 61 | Analytics | Blocked | No writer |
| 62 | Audit ledger | Not determined by this grep (no `::query()->create` hit on the audit models) | Written elsewhere in the audit recorder; not traced |
| 63, 64 | Retention, Exports | Mechanisms, not tables | |

### 1.5 Owner with several entry points, versus several writing classes

**One class, many entry points (no issue under any reading):** `employees`, `employment_records`, `employment_terms`, `leave_ledger_entries`, `reporting_relationships`, the separation tables, `leave_requests`, staff attendance.

**More than one writing class, separate named operations:**

| Table | Classes | Same operation twice? |
|---|---|---|
| `employee_assignments` | `EmployeeAssignmentService`, `PlacementChangeService`, `EmploymentService` | No. Set `ends_on` for three reasons, each under `EmployeePlacementLock`. `PlacementChangeService` delegates row creation to `createRecord()` but closes the source and sets `is_primary` itself |
| `employee_placement_changes` | `PlacementChangeService`, `EmployeeAssignmentService` | No. Different `change_kind` values, but see 1.6 |
| `workforce_tasks`, `workforce_task_events` | `TaskGenerationService`, `TaskAssignmentService`, `TaskWorkflowService` | No |
| `leave_policy_assignments` | `LeavePolicyAssignmentService`, `LeaveEligibilityService` | Both INSERT an assignment, one per request, one per run. Rule 133 sanctions the run |

### 1.6 Write paths worth an owner's attention (none is an unauthorized write)

1. **A move can be composed outside the named path.** Rule 130: "Transfer, promotion, designation or department change go only through `PlacementChangeService`". `EmployeeAssignmentService::end()` has no refusal for a primary assignment (`:183-204`), and its comment at `:200-202` still calls this "a transfer's source". With `create()` then `setPrimary()` the same outcome is reached, recorded as `primary_correction`, not `transfer`. Not exercised at runtime; I read the code only. Whether this is a bypass or a tolerated correction is not determinable from the code.
2. **Map line 25** assigns "primary correction" to `PlacementChangeService`; the writer is `EmployeeAssignmentService.php:357`.
3. **Demo writes bypass canonical services** for `employees`, `employment_records`, `employee_assignments`, profile tables, Departments, Positions and Categories (cites in 1.3, 1.4). Local DDEV only. `DemoModuleData` by contrast uses services.

### 1.7 Guard tests

"Pins the writer" means a static or structural test fails if a new writer appears.

| Table(s) | Guard | Strength |
|---|---|---|
| `leave_ledger_entries` | `LeaveArchitectureGuardTest:135` | Pins the writer, but its regex matches only `LeaveLedgerEntry::query()->create(`. It would miss `new LeaveLedgerEntry` or `DB::table` |
| `staff_attendance_records`, `staff_attendance_corrections` | `StaffAttendanceArchitectureGuardTest:108` | Pins the writer, wider regex (query and `->table(`) |
| `employees.user_id` (column) | `ActingEmployeeArchitectureGuardTest:69` | Pins the link column only, not the table |
| `employee_account_links` | `DatabaseRoleVerifier:129-130,678` | Privilege check: no runtime write at all. Strongest form |
| `employment_terms`, `employee_placement_changes` | `NO_RUNTIME_UPDATE` (`DatabaseRoleVerifier:118`), triggers | Pins UPDATE/DELETE, not who may INSERT |
| `employee_assignments`, `employees`, `employment_records` | `HrCampusArchitectureTest:77,112` | Pins lock order and callers of `createRecord`, `setPrimaryRecord`, `closeForEmploymentEnd`. **Does not pin table writers** |
| `reporting_relationships` | `HrCampusArchitectureTest:112` for `closeForEmploymentEnd` only | No writer pin |
| `teaching_assignments` and elective (not HR tables) | `TeachingAssignmentArchitectureGuardTest:69` | Pins the writer |
| `employee_documents` | `EmployeeDocumentTest:460,486` | Pins no `Storage::`, no `documents` writes. Not a writer pin |

**No writer guard:** `employees` (table), `employment_records`, `employee_assignments`, `employee_placement_changes` (INSERT), `employment_terms` (INSERT), `reporting_relationships`, `employment_separation_cases`, `employment_separation_events`, `separation_clearance_items`, `separation_clearance_requirements`, all six `workforce_task*` tables, `employee_account_link_corrections`, `employee_documents`, the profile tables, `leave_requests`, `leave_policy_assignments`, and the other Leave tables.

`tests/Feature/WorkforceTasks` and `tests/Feature/HR/Separation` contain no static scan (`app_path|base_path|Finder|file_get_contents`). Database triggers and privilege revokes do back many of these, but they constrain what is written, not who writes.

### 1.8 Proposal only: a definition of "one writer"

R1-01 needs to be stated per table, since §1 rows mix tables, mechanisms and unbuilt concepts. A proposal for the owner, not a decision:

> A tenant table satisfies one-writer when all of these hold.
>
> 1. **Canonical domain owner.** Exactly one bounded context owns the table. No other domain's code issues a write to it.
> 2. **Entry points invoking one service.** Controllers, API, console, imports and other services may trigger a write only by calling the owner's Application service. Any number of entry points is allowed. A controller, job or import never writes the table itself.
> 3. **Named operations.** Normally one Application class writes a table. A second class is allowed only if the table's register names it and the operation it alone performs. The same operation in two classes is a duplicate writer.
> 4. **Raw SQL privileges.** `school_os_app` holds only the privileges the named operations need, enforced by revoke plus `DatabaseRoleVerifier`. A raw-SQL write inside the named class is permitted; it is still that class's write.
> 5. **Migration-only writes.** Schema changes and one-time backfills in a migration are not writers.
> 6. **Projections.** A column maintained by a trigger from another table is not a second writer. It belongs to the source table's writer, and a direct write is refused by the database (the `employment_records` terms columns).
> 7. **Audit and history writers.** An append-only row written in the same transaction as its parent operation, or by a trigger on the parent, belongs to the parent's writer. It needs append-only privileges, not a separate register entry.
> 8. **Retention.** Deletion by the retention identity or a retention function is a platform mechanism, recorded in the retention catalogue. It is not a business writer.
> 9. **Fixtures.** Seeders that bypass the service are excluded only if guarded to local or testing.
> 10. **Rows without tables.** Mechanisms, readers and unbuilt concepts are marked not applicable. They do not pass or fail.

Under this proposal, the tables needing a register entry are `employee_assignments`, `employee_placement_changes`, `workforce_tasks`, `workforce_task_events` and `leave_policy_assignments`. Item 3 also implies a mechanical pin, since no HR guard exists today (1.7).

## 2. R1-02 Payroll coupling

### 2.1 Exact text

From `docs/hr/HR-R1-CLOSURE-CONTRACT.md:39`:

> | R1-02 | **No orphaned integration.** Every cross-domain consumer reaches HR only through an Application reader; X-1 is closed; Payroll's `EmploymentRecord` coupling is resolved or recorded as an owner-approved exception | import scan (`use App\Domain\HR\Infrastructure` outside HR) |

It has three conjuncts. **An approved Payroll exception answers only the third.** The first covers every consumer in 2.9.

### 2.2 What OM:85 and the roadmap actually say

`docs/hr/HR-DOMAIN-OWNERSHIP-MAP.md:85`:

> | Payroll | `HR\Infrastructure\EmploymentRecord` in 25 places, `Employee` in 1 | **historical drift** (Phase 9). Payroll FKs and reads target `employment_records` directly. Record only; HR.15 decides whether to introduce a reader or accept it as a recorded, owner-approved exception |

`docs/hr/HR-EXPANSION-ROADMAP.md:591`, inside the HR.15 scope list:

> - the Payroll `EmploymentRecord` coupling decision;

**The decision was assigned to HR.15 and not taken.** The ADR 0028 HR.15 amendment (`docs/architecture/adr/0028-phase-8a-hr-sequencing-and-domain-foundation.md:352-375`) does not mention it. A repo-wide grep for the coupling finds only: roadmap `:591`, map `:85`, `HR-0A-ARCHITECTURE-RECONCILIATION.md:163`, the contract, and audit decision D-2 (`HR-R1-0-AUDIT-REGISTER.md:88`, audit recommendation "Exception with an exit plan", marked "not an approval").

**The Payroll ADR says something different from the code.**
- `docs/architecture/adr/0034-phase-9-payroll-architecture.md:43`: "it reads HR's `Employee`/`EmploymentRecord` via HR's Application layer". `docs/modules/PAYROLL.md:97` says the same. As built, most reads do not.
- ADR 0034 `:96` deliberately keys compensation on `EmploymentRecord`, and `:106` approves "a parent-row lock (`EmploymentRecord` `FOR UPDATE`)". So one coupling, the lock, is a documented decision.
- The Payroll-side rationale is a docblock: `CompensationAssignmentController.php:26-29` calls it "an established cross-module read pattern".

### 2.3 How this relates to "25"

**25 is an import count, not a use count.** It is the number of `use App\Domain\HR\Infrastructure\EmploymentRecord;` lines under `app/Domain/Payroll` only. It was 25 at `7a4068fe` and is 25 now.

| Measure | Count |
|---|---|
| `use ...EmploymentRecord` in `app/Domain/Payroll` | **25** (the reported figure) |
| ...in `app/Http/Controllers/App/Payroll` (missed by the 25) | 3 |
| Files importing `EmploymentRecord`, total | 28 |
| `use ...Employee` (the reported "1") | 1 (`PayrollRunResult.php:5`) |
| Dead imports among the 28 | 1 (`StatutoryPayrollCalculationService.php:5`: no code use, only docblocks at `:54,:78`) |
| Lines containing the word `EmploymentRecord` (imports, comments, messages) | 137 |
| **Executing use sites in PHP** (this recount) | **62** |
| Additional: `employee_account_links` raw reads (not one of the three named tables) | 2 |

Caution: the "direct read" total below is also 25. That is a coincidence, not the same 25.

### 2.4 Totals by classification

A use site is one code member or line that touches an HR class or table: 32 queries + 12 typed parameters + 9 relations + 3 export traversals + 6 raw table references = 62.

| Classification | Sites |
|---|---|
| direct write | **0** |
| direct read | 25 |
| eligibility lookup | 14 |
| historical lookup | **0** |
| calculation source | 7 |
| relationship traversal | 12 |
| permitted infrastructure operation | 4 |
| **PHP total** | **62** |
| migration (separately counted) | 9 FKs, 8 Payroll-owned SQL functions (2.7) |
| test-only (separately counted) | 95 import lines across `tests/`; 27 files in `tests/Feature/Payroll` + `tests/Unit` |

"Permitted" means it runs under the E21 retention identity and its column grants (`DatabaseRoleVerifier::RETENTION_SELECTS`). No document names it as an exception to rule 4.

**Historical lookup is 0.** Payroll has no as-of read. It reads the employment row's current dates and the Employee's current name, including for past periods.

### 2.5 Per-class inventory

| Class | Member, line | Use | Classification |
|---|---|---|---|
| `Http/Controllers/App/Payroll/PayrollRunController` | `manualOverride` `:121`; `correctionDelta` `:142` | `findOrFail`, passes the model on | direct read ×2 |
| | `show` `:277`, `:294`, `:312` | Names and numbers for unresolved, results, correction candidates | direct read ×3 |
| `Http/Controllers/App/Payroll/CompensationController` | `search` `:67` | Name or number `ilike` search, `limit(20)`, returns status | direct read |
| | `show` `:95`; `store` `:154` | Resolve, then show name and number | direct read ×2 |
| `Http/Controllers/App/Payroll/Statutory/StatutoryEmployeeController` | `search` `:50`; `show` `:79` | Search; identity for the page | direct read ×2 |
| `Domain/Payroll/Http/Controllers/PayrollRunController` | `:84`, `:104` | Resolve for service | direct read ×2 |
| `.../CompensationAssignmentController` | `:35`, `:54` | Resolve for service | direct read ×2 |
| `.../Statutory/Http/Controllers/StatutoryPfStatusController` | `:24`, `:32` | Existence check, result discarded | eligibility lookup ×2 |
| `...StatutoryEsiCoverageController` | `:25`, `:39` | same | eligibility lookup ×2 |
| `...StatutoryTaxProfileController` | `:23`, `:31` | same | eligibility lookup ×2 |
| `...StatutoryIdentifierController` | `:26`, `:34`, `:42` | same | eligibility lookup ×3 |
| `Statutory/Application/Admin/EmployeePfStatusAdminService` | `assertEmploymentRecordExists` `:99` | `exists()` | eligibility lookup |
| `...EmployeeEsiCoverageAdminService` | same `:118` | `exists()` | eligibility lookup |
| `...EmployeeTaxProfileAdminService` | same `:93` | `exists()` | eligibility lookup |
| `...StatutoryIdentifierAdminService` | same `:127` | `exists()` | eligibility lookup |
| `Application/PayslipReadService` | `ownEmploymentRecordIds` `:172` | Every employment of the acting Employee, any status | eligibility lookup |
| | `assemble` `:178`, read at `:218-219` | Name and number on a payslip | direct read |
| `Application/PayrollRunService` | `calculateRegularRun` `:392` | Load per employment id (N+1) | calculation source |
| | `calculateCorrectionRun` `:488` | same | calculation source |
| | `resolveEmploymentRecord` `:666`, read `:668-673` | `starts_on`, `ends_on` decide partial period | calculation source |
| | `calculateFromManualOverride` `:701` | `id` filter | calculation source |
| | `calculateFromCorrectionDeltas` `:736` | `id` filter | calculation source |
| | `persistResult` `:809`, read `:814-815` | `id`, `employee_id` stored on the result | calculation source |
| | `recordCorrectionDelta` `:205`; `recordManualOverride` `:258` | `id` only | direct read ×2 |
| `Application/CompensationService` | `assign` `:72` | `id` only | direct read |
| | `assign` `:104` | `lockForUpdate()`. No data read; ADR 0034 `:106` approved | direct read (row lock) |
| | `resolveEffective` `:174` | `id` filter | calculation source |
| `Application/PayrollCompensationReadService` | `listAssignments` `:50` | `id` only | direct read |
| `Application/PayrollCompensationAdministrationService` | `assign` `:45` | `id` for `isEmployeeOf()` | direct read |
| `Application/PayrollRunAdministrationService` | `:72`, `:86` | `id` for `isEmployeeOf()` | direct read ×2 |
| `Application/PayrollPostingIndependence` | `changesPage` `:304`, `:305` | Joins both tables. Reads `employee_number`, `full_name`, sorts by number | direct read ×2 |
| `Application/Retention/PayrollEmployeeRetentionService` | `pruneUnit` `:54`, `:61`, `:71` | Employment ids, exists predicate, blocker list naming `employment_records` and `employee_assignments` among others | permitted infrastructure ×3 |
| `Application/Retention/PayrollEvidenceRetentionService` | `pruneEvidence` `:90` | exists predicate | permitted infrastructure |
| Statutory exports: `StatutoryEcrExportService` | `:96`, `:128` | `payrollRunResult.employee`, `full_name` | relationship traversal |
| `StatutoryEsiContributionWorksheetExportService` | `:58`, `:107` | same | relationship traversal |
| `StatutoryTdsDraftStatementExportService` | `:74`, `:112` | same | relationship traversal |
| Models: `EmployeeCompensationAssignment:74`, `PayrollRunResult:64`, `PayrollAdjustment:75`, `EmployeePfStatus:56`, `EmployeeEsiCoverage:46`, `EmployeeTaxProfile:48`, `EmployeeStatutoryIdentifier:43`, `PayrollLwfAnnualCharge:27` | `employmentRecord()` | `belongsTo(EmploymentRecord)`. **No production traversal found** of any of these 8 | relationship traversal ×8 |
| `Infrastructure/PayrollRunResult` | `employee()` `:70` | `belongsTo(Employee)`, used by the 3 exports | relationship traversal |

Separately counted (not in the 62): `PayrollSelfBeneficiaries.php:28,31` read `employee_account_links` in raw SQL.

**Compliant uses (Application layer, for comparison):** `EmploymentRoster::labels()` (`PayrollApprovalReviewService.php:364`, `PayrollHrxInputReadService.php:89`), `ActingEmployeeResolver` (`PayslipReadService`), `HrSelfAdministrationGuard::isEmployeeOf()` (2 classes), `EmployeeRetentionEligibility` (2), `EmployeeSeparation` (1).

**No use of `employee_assignments` by Payroll** except the blocker list at `PayrollEmployeeRetentionService.php:71`. Payroll does not read staff type, employment type, category, Department or Position.

### 2.6 Per-class totals

| Group | Classes | Sites |
|---|---|---|
| App controllers | `PayrollRunController` 5, `CompensationController` 3, `StatutoryEmployeeController` 2 | 10 |
| API controllers | `PayrollRunController` 2, `CompensationAssignmentController` 2, four Statutory 9 | 13 |
| Calculation and core services | `PayrollRunService` 8, `CompensationService` 3, `PayslipReadService` 2, `PayrollCompensationReadService` 1, `PayrollCompensationAdministrationService` 1, `PayrollRunAdministrationService` 2 | 17 |
| Statutory admin services | 4 classes | 4 |
| Posting independence | 1 class | 2 |
| Retention | 2 classes | 4 |
| Exports | 3 classes | 3 |
| Models | 8 classes, 9 relations | 9 |
| **Total** | | **62** |

### 2.7 SQL functions in migrations that read HR tables

From a scan of `CREATE FUNCTION` bodies. Limits: 9 migrations patch functions through `pg_get_functiondef` transforms, which this text scan does not follow, and overloads share a name.

**Payroll-owned (8):**

| Function | HR table(s) | Last definition |
|---|---|---|
| `compensation_assignments_reject_overlap` | `employment_records`, **`FOR UPDATE`** | `2026_09_15_090300:73` |
| `payroll_run_first_paid_compensation` | `employment_records`, `employee_account_links` | `2027_02_08_090000:154` |
| `payroll_run_independence_exception` | same | `2027_02_08_090000:182` |
| `payroll_run_first_paid_beneficiaries` | same | `2027_02_08_090000:197` |
| `payroll_reversal_reconciliation_subjects` | same | `2027_02_08_090000:249` |
| `payroll_reversal_affected_runs(uuid,uuid,uuid)` | same (`:42`, `:47`) | `2027_02_10_090200` (the 2-arg form wraps it) |
| `retention_assert_payroll_employee_floor` | `employees`, `employment_records`, `FOR UPDATE` (`:118,:121`) | `2026_11_15_090000:112` |
| `retention_expire_payroll_employee_evidence` | `employment_records` | `2026_11_15_090000:130` |

Also: `employees_user_link_payroll_lock` (`2027_02_07_090000:380`) is a **trigger on an HR table** taking a Payroll-named lock.

**Other domains:** `leave_requests_guard` (`employment_records`), `leave_decisions_guard` (`employees`), `staff_attendance_records_guard` (`employment_records`), `retention_lock_hrx_employee` (`employment_records`).

**HR's own:** `hr_employment_*`, `hr_employee_assignments_*`, `hr_employee_placement_changes_guard`, `hr_reporting_relationships_guard`, `hr_separation_subject_user`, `hr_employment_separation_cases_guard`, `employee_account_link_*`.

**Payroll foreign keys to HR tables: 9.** To `employment_records`: `payroll_run_results`, `payroll_adjustments`, `employee_compensation_assignments`, `employee_statutory_identifiers`, `employee_pf_status`, `employee_esi_coverage`, `employee_tax_profile`, `payroll_lwf_annual_charges`. To `employees`: `payroll_run_results`. Rule 70 requires composite FKs of this shape, so these are not candidates for removal under any option.

### 2.8 Reader versus narrow exception

**Named governed readers:**
- `EmploymentRoster`: `paged`, `pageOverlapping`, `labels`, `span`, `spans`, `covering`, `overlappingRange`. Authorizes nothing.
- `EmploymentCoverage`: `hold`, `coveringEndsOn`, `holdRecord`, `holdRecordCovering`, `holdRecordAttendable`, `holdRecordsSpanning`, `constrainToCoveredOn`.
- `WorkforceAsOfReader`: `employmentOn`, `termsOn`, `placementOn`, `placementsOn`, `coveringQuery`, others.
- `CurrentEmployeeAssignments`, `CurrentStaffMembers`, `DutyCandidateReader::search`.
- `EmployeeDirectoryService::search` (needs `hr.employees.view`, `:101`).
- `EmployeePickerReadService`, `ActingEmployeeResolver`, `HrReportLabels`, `HrSelfAdministrationGuard`, `EmployeeRetentionEligibility`, `ReportingLine`, `ReportingAsOfReader`.

**Comparison:**

| Dimension | Existing governed reader | Approved narrow exception |
|---|---|---|
| **Correctness** | Fits labels and existence. `labels()` loses rows beyond 2,000 at this commit (`EmploymentRoster.php:163`, LVA-8). `pageOverlapping` drops `draft` and has no dates | Same data as today. No behaviour change, none of today's gaps closed |
| **Historical semantics** | No gain. Payroll reads current dates and names; `WorkforceAsOfReader` answers placement and terms as of a date, which Payroll does not use. No reader gives a historical name | Same. A payslip or ECR for a past period shows the current name (`PayslipReadService:218`, `StatutoryEcrExportService:128`) |
| **Transaction consistency and locks** | `holdRecord*` are FOR SHARE and also judge status and dates, which would refuse work `CompensationService` accepts today. No reader takes FOR UPDATE. Unlocked reads match today's | Keeps the ADR 0034 `:106` lock and the trigger lock unchanged |
| **Authorization** | Roster authorizes nothing, so capability checks stay in Payroll, as today. Directory needs `hr.employees.view`, which is the wrong authority for Payroll staff | Same as today. Observed ordering issue in 5, item 12, either way |
| **Data minimization** | Better. `labels()` fixes the fields; `whereHas`/`with('employee')` load the full Employee row | Unchanged: full model loaded, fields picked in the controller |
| **Performance** | `spans()` and `labels()` are batched, against the per-employment `findOrFail` at `PayrollRunService:392,488`. Not measured | Unchanged N+1. PAY-12 measured, no target set (`docs/modules/PAYROLL.md:1356`) |
| **Migration impact** | No schema change for reader swaps. Types change: 12 service signatures | None |
| **Test complexity** | 17 Payroll test files call the changed methods; 27 import the model; a signature change touches them | None; needs a guard pinning the excepted list |

**What each Payroll read needs that the readers cannot provide:**

| Read | Needs | Available? |
|---|---|---|
| Calculation (`PayrollRunService:392,488,666-673,815`) | `id`, `employee_id`, `starts_on`, `ends_on` for an id set, any status, uncapped | **No single reader.** `spans()` has dates but no `employee_id`; `labels()` has `employee_id` but no dates and is capped |
| Compensation lock (`CompensationService:104`, trigger) | Exclusive serialization per employment | **No.** Readers are FOR SHARE and add status and date judgement. Trigger is SQL |
| Name or number search returning employment ids of any status (`CompensationController:67`, `StatutoryEmployeeController:50`) | Ended staff included; Payroll authority | **No.** `DutyCandidateReader` returns Employees covered today. Directory needs an HR capability. Roster has no term search |
| All employments of an Employee (`PayslipReadService:172`) | History, for own payslips, no HR capability | **No.** `ActingEmployeeResolver` resolves the current one; `ownedEmploymentIds()` only filters given ids |
| Existence (13 sites) | Row exists in School | **Yes:** `EmploymentRoster::span() !== null` |
| Labels on pages (`PayrollRunController:277,294,312`, `PayslipReadService:178`, exports) | Name, number by employment id | **Yes, except the 2,000 cap** (`labels()`). `show` is unpaged |
| Sorted paged names (`PayrollPostingIndependence:304-305`) | SQL join, order by `employee_number`, pagination | **Partly.** `paged()` accepts a predicate but orders by name, a visible change |
| SQL functions and guards (2.7) | Reads inside a database guard, same snapshot, at commit | **Impossible.** PHP readers cannot run in a trigger. Only an HR-owned SQL function could change who owns the text |
| Retention (`PayrollEmployeeRetentionService`, `PayrollEvidenceRetentionService`) | Employment ids under the retention identity's column grants | **No reader today.** A method on `EmployeeRetentionEligibility` is conceivable |

**Options for the owner (no recommendation):**

- **A. Recorded exception, whole surface.** Record the coupling as it stands: 62 sites, the lock, the functions, the FKs. Lowest change. Keeps ADR 0034 `:43` and `PAYROLL.md:97` inaccurate unless they are corrected too (rule 15).
- **B. Narrow exception plus readers.** Except only what readers cannot do: FKs, SQL functions, the lock, calculation facts, retention, search. Move the 13 existence sites and 9 label sites (`PayrollRunController:277,294,312`, `CompensationController:95`, `StatutoryEmployeeController:79`, `PayslipReadService:178`, 3 exports) to readers. Depends on LVA-8 for pages above 2,000.
- **C. New HR reader for everything PHP.** A new HR Application class returning `{id, employeeId, startsOn, endsOn}`, a search, an employments-of-Employee list, and a lock port. Touches 28 files and 12 signatures. **The SQL functions and FKs remain**, so an exception is needed under every option.

Whichever is chosen, a pin is missing today: no guard limits Infrastructure imports from Payroll. `PayrollHrxArchitectureGuardTest:37` pins Payroll's route to HRX only.

### 2.9 Other consumers outside Payroll

| Domain | Infrastructure use | Classification |
|---|---|---|
| **Leave** | `LeavePageSupport.php:47` (`EmploymentRecord`, controller trait) | direct read |
| | `LeaveEvidenceRetentionService.php:70` | permitted infrastructure |
| | `leave_requests_guard`, `leave_decisions_guard` | migration |
| | Otherwise Application only (`EmploymentCoverage`, `EmploymentRoster`, `ReportingLine`, `WorkforceAsOfReader`, others) | compliant |
| **StaffAttendance** | `StaffAttendanceReadService.php:121`: `employment_records.id` in the `$constrain` that `EmploymentRoster::paged()` invites (`:35`) | direct read, through a reader's documented hook |
| | `StaffAttendanceEvidenceRetentionService.php:64` | permitted infrastructure |
| | `staff_attendance_records_guard` | migration |
| **WorkforceTasks** (HR-owned sub-domain, ADR 0028 HR.13) | `TaskAssignmentService.php:97` eligibility lookup, `:116` direct read | same-domain by ADR, though a separate namespace |
| **TeachingAssignments** | `TeachingAssignmentController:163`, `ElectiveTeachingAssignmentController:71` | direct read (XRP-3) |
| | `TeachingAssignment:69`, `ElectiveTeachingAssignment:64`; `TeachingAssignmentReadService:47,76,107,145`; `AssignmentOnInactiveConfigurationCheck:50` | relationship traversal (map `:86`: accepted) |
| | `Employee::class` in `setModel`, `TeachingAssignmentService:140`, `ElectiveTeachingAssignmentService:101` | no read, exception label only |
| **Timetable** | App controller `:141`, `:241`, `:430`; API `:321` | direct read |
| | `TimetableEntry:113`, `TimetableScheduleService:284`; `$teacher` typed `:138,195,337` | relationship traversal |
| | `TimetableSeparationObligations` | own table; compliant |
| **Transport** | `TransportOperationsController:143`; `TransportRouteAssignmentController:50` | direct read |
| | `TransportRouteAssignment:71`; `TransportRouteAssignmentService:50` | relationship traversal |
| **Visitor** | `VisitorVisitController` (App) `:139`; API `:87` | direct read |
| | `VisitorVisit:77`; `VisitorVisitService:52` | relationship traversal |
| **Documents** | `DocumentListingService:168`, `DocumentService:562` | eligibility lookup (map `:86`: "candidate for an HR reader") |
| | `Document:91` | relationship traversal |
| **Attendance** | `AttendanceSession:143` | relationship traversal (map `:86`: accepted) |
| **Support/Retention** | `EmployeeErasureAdapter:55`, `StorageOrphanReaper:115` | permitted infrastructure |
| **LMS, Examinations, CurriculumDelivery, Communications, Inventory** | Application readers only | compliant |

**Import scan, the contract's stated method:** 25 files outside HR and Payroll carry `use App\Domain\HR\Infrastructure\...`. The number 25 is once again a coincidence. The first conjunct of R1-02 fails on these whatever is decided for Payroll, unless relationship-only use is declared acceptable (map `:86` accepts it for three domains only, not Timetable, Transport or Visitor).

## 3. Direct Employee reads

Scope: production `Employee::` and `EmploymentRecord::` reads outside `app/Domain/HR` and `app/Http/Controllers/App/HR`. **47 `::query()` sites: 32 Payroll (see 2.5), 15 others.** Both models carry `BelongsToSchool` (`Employee.php:51`, `EmploymentRecord.php:52`), so `SchoolScope` applies even where no explicit `school_id` predicate appears.

| Site | Purpose | Fields returned | School / Campus scope | Existing reader | Proposal |
|---|---|---|---|---|---|
| `TeachingAssignmentController:163` | Picker bootstrap | `id`, `employee_number`, `full_name` (all columns loaded, mapped) | Explicit School; whole-School capability | `DutyCandidateReader` predicate only | **Replace** (section 4) |
| `ElectiveTeachingAssignmentController:71` | same | same | same | same | **Replace** (section 4) |
| `Timetable/TimetableEntryController:141` (App) | List-page teacher filter: teachers already scheduled in the viewer's scope | `id`, `full_name`, `employee_number` | Explicit School; `$scheduled()` follows scope | None: `labels()` is keyed by employment | **Exception or a new labels-by-Employee reader.** Listing existing scheduled teachers is correct, including a separated one |
| `Timetable/TimetableEntryController:241`, `:430` (App); `Domain/.../TimetableEntryController:321` (API) | Resolve a posted id for the service | Model, `id` only; service re-checks `hold()` (`TimetableScheduleService:153,213,296`) | SchoolScope. Timetable is Campus-scoped by access class, teacher by School | `EmploymentCoverage::hold()` already decides | **Replace:** pass the id to the service, which holds it |
| `TransportOperationsController:143`; `TransportRouteAssignmentController:50` | Resolve driver | Model; service re-checks `hold()` (`TransportRouteAssignmentService:64`) | SchoolScope | as above | **Replace:** pass the id. API also presents `driver` after load (`:85-87`), relationship use |
| `VisitorVisitController:139` (App); `:87` (API) | Resolve host | Model; service re-checks (`VisitorVisitService:69`) | SchoolScope | as above | **Replace:** pass the id |
| `DocumentListingService:168`, `DocumentService:562` | Owner exists, before authorization | `id` | Explicit School | None for existence | **Reader candidate** (map `:86`): a small `exists` on an HR reader |
| `LeavePageSupport:47` | Employment options and labels for Leave pages | `id`, number, name, status | Explicit School; **`limit(1000)` with no ORDER BY before the limit** | `labels()` for the `$ids` path; nothing for the option list | **Replace** the `$ids` path. The option-list path has the XRP-3 shape: see 5, item 6 |
| `TaskAssignmentService:97`, `:116` | Employment exists; its Employee id | `id`; `employee_id` | Explicit School `:97`; SchoolScope `:116` | `EmploymentRoster::span()`, `labels()` | **Legitimate same-domain** if WorkforceTasks counts as HR (ADR 0028 HR.13) |
| Payroll, 32 sites | See 2.5 | | | | Owner decision, 2.8 |

**Legitimate same-domain:** `TaskAssignmentService` (HR-owned sub-domain). Everything in `app/Domain/HR`. `Employee::class` at `TeachingAssignmentService:140` and `ElectiveTeachingAssignmentService:101` are not reads.

**Accepted relationship pattern (not `::query()`):** map `:86` covers TeachingAssignments, Attendance and Documents. Timetable, Transport and Visitor relations are not named, and neither is Payroll's.

**Rule 3 observation.** Several of these sit in controllers, which rule 3 says stay thin: `PayrollRunController:277-316`, `CompensationController:67`, `StatutoryEmployeeController:50`, both XRP-3 controllers, `TimetableEntryController:141`.

**X-1 status.** The register row (`HR-LEGAL-SECURITY-GATE-REGISTER.md:401`) and R-HR12-1 (`:412`) show the write paths fixed by HR.12 and the pickers by HR.15A. All 4 search endpoints use `DutyCandidateReader` (`TimetableEntryController` App `:328`, API `:220`, `TransportOperationsController:121`, `VisitorVisitController:113`). What remains of "X-1" is the rule 4 half: the `findOrFail` model reads above, and map `:52,54`. The contract's preflight statement "Direct `Employee::query()` remains in Timetable, Transport and Visitor controllers" (`:206`) is **confirmed**. It omits TeachingAssignments, Documents and Leave.

## 4. XRP-3, XRP-4, XRP-6

### 4.1 XRP-3: verified, with one wording correction

- `TeachingAssignmentController.php:163-164`: `Employee::query()->where('school_id', $schoolId)->where('record_status', 'active')->orderBy('full_name')->limit(self::EMPLOYEE_OPTION_LIMIT)`. Constant at `:42` = 1000.
- `ElectiveTeachingAssignmentController.php:71-72`: same. Constant at `:38` = 1000.
- Save re-checks: `TeachingAssignmentService.php:138` (`hold`), `:145` (`coveringEndsOn`); `ElectiveTeachingAssignmentService.php:99`, `:106`.
- Outside rule 138's named pickers and HR.15A: **confirmed** (ADR 0028 `:360`, rule 138 name Timetable, Transport, Visitor).

**Wording.** Employees have no draft status. "Draft" here means an active Employee whose only employment is `draft` (`PLANNED_OR_CURRENT_STATUSES` excludes it, `EmploymentCoverage.php:45`), or one with no employment at all. Both are offered and then refused.

**Beyond the finding.** `->get()` with no column list loads every column, including work contact fields, before mapping. Not sent to the browser.

**Owner.** TeachingAssignments owns the page, controllers and the fix (map `:36`). HR owns any reader change. Rule 4 direction holds: TeachingAssignments → HR.

**Would closing the Payroll references close it? No.** Different module, different files, no shared code. Neither XRP-3 controller imports anything from Payroll.

**What ADR 0028 says.** HR.15 decision 1 (`:359-362`): "One HR reader, `DutyCandidateReader`, backs every operational staff picker (Timetable teacher web/API, Transport driver, Visitor host)". "Rejected: a driver-qualification or teaching-assignment rule in the pickers" (`:368-369`) means do not filter those pickers by teaching assignment. It does not address the Teaching Assignment pages themselves. So ADR 0028 does not cover XRP-3 either way.

**ADR 0063 is the governing contract** (`docs/architecture/adr/0063-teacher-identity-ownership-authorization-contract.md:876-882`): "An active Employee with a covering planned or current employment on `starts_on`", and "planned (`pre_joining`) employment counts for planning".

**Does `DutyCandidateReader` fit a future-dated start? No, as built.**
- `DutyCandidateReader.php:38` hard-codes `SchoolTimezone::today($school)`.
- A future joiner is not offered for a future `starts_on`, contradicting ADR 0063.
- A leaver covered today is offered for a date after their employment ends, then refused.
- **The predicate does fit:** `EmploymentCoverage::constrainToCoveredOn($employees, $date)` (`:63`) takes any date and is the read form of the same `hold()` these services call.
- The reader's `MAX_LIMIT` is 25 and it is a search, so the page's bootstrap list has to become a search picker.

**Exact interface needed.**

```php
// HR: DutyCandidateReader, same predicate, caller's date
/** @param string|null $onDate School-local Y-m-d; null = today (existing behaviour) */
public function search(School $school, ?string $term, int $limit = 20, bool $matchNumber = false, ?string $onDate = null): array;
// returns list<array{id: string, fullName: string, employeeNumber: string}>
```

TeachingAssignments adds an authorized search under `teaching.assignments.manage` (not Campus-adopted; whole-School), taking `q`, `starts_on` (`date`), and `academic_year_id`, and the two `Index.vue:432` and `Electives.vue:281` selects become search pickers. Then `options.employees`, `EMPLOYEE_OPTION_LIMIT` and the two `use Employee` lines go. The save path does not change. A new route needs its `SchoolRouteCoverage` entry (rule 98) and rule 138 / ADR 0028 need to name these pickers.

**Acceptance tests.**
1. Separated employment ended before `starts_on`: not offered.
2. Only a `draft` employment, or none: not offered.
3. `pre_joining` starting on or before `starts_on`: offered for that future date, and not offered for an earlier date.
4. `notice_period` ending before `starts_on`: not offered for that date; offered for a date inside the employment.
5. Archived identity: not offered.
6. With more than 1,000 staff, an Employee sorted past 1,000 is reachable by search.
7. Results bounded by the limit, in a stable order.
8. Parity: for each candidate on date D, `hold(D)` answers COVERED in the same state.
9. Stale choice: a candidate who separates after the search is still refused by `hold()`, and `coveringEndsOn` behaviour is unchanged.
10. Deny: no `teaching.assignments.manage` is refused, for both pages. View-only is refused.
11. Cross-School: another School's Employee is never returned (Eloquent and RLS).
12. Bad input: invalid date is a 422. A date outside the academic year has a defined answer.
13. Payload is exactly `id`, `employeeNumber`, `fullName`; update the pin at `TeachingAssignmentAdminUiTest.php:54-55` and its elective sibling.
14. A static guard: neither controller imports `Employee`.
15. The School-local date applies, not UTC, when the form date defaults.

### 4.2 XRP-4: verified

- `TimetableSeparationObligations.php:27`: `->where('t.teacher_id', $subject->employeeId)`.
- `TransportSeparationObligations.php:26`: `->where('driver_employee_id', $subject->employeeId)`.
- `SeparationSubject.php:12-13` offers both `employmentRecordId` and `employeeId`. `LeaveSeparationObligations.php:30,33,47` and `TaskSeparationObligations.php:26` use the employment.

**The limit is in the schema, not only the query.** `timetable_entries.teacher_id` and `transport_route_assignments.driver_employee_id` are FKs to `employees` (`2026_09_12_090300:100`, `2026_09_04_090300:72`). Neither table has an employment column. Timetable entries are undated weekly rows. So these sources cannot be keyed by employment without a schema change, and they could not attribute a duty to one employment of a rehired person. Neither source reads `$subject->lastDay`.

Practical effect: for an Employee with more than one employment, a duty counts against any open case. Severity not assessed here. Closing Payroll references does not affect it.

### 4.3 XRP-6: verified, and wider than the finding

Named sites confirmed:
- `CurrentStaffMembers.php:50`, `:105`: `DB::table('school_memberships')`. Also `:104` reads `users`.
- `EmployeePickerReadService.php:84-85`: `membership_role_assignments` joined to `roles`.
- `CurrentEmployeeAssignments.php:121`, `:168`: `campuses`.
- `docs/architecture/DOMAIN-MAP.md:111`: HR row's "Depends on" reads `Schools, Identity & Access`. **The word Campus does not appear in that row.**

More sites of the same kind:
- `campuses`: `CurrentEmployeeAssignments.php:200,284,320`, `EmployeePickerReadService.php:154`.
- `Campus` model: `EmployeePickerReadService.php:160`, `HrEmployeeAccess.php:158,212`, `EmployeeProfileWorkspaceService.php:199`, `EmployeeDirectoryService.php:305`, `HrReportLabels.php:104,119`, `EmployeeOperationalOverviewService.php:121`, `EmployeeImportService.php:242`, `HrDepartmentController.php:40,58`, `HrEmployeeAssignmentController.php:73,155`, API `EmployeeLifecycleController.php:112`, `EmployeeAssignmentController.php:72`, `DepartmentController.php:65`.
- Identity models: `SchoolMembership` at `ActingEmployeeResolver.php:111`, `EmployeeService.php:372`, `EmployeePickerReadService.php:72`. `User` at `ActingEmployeeResolver.php:121`, `EmployeeService.php:386`, `EmployeePickerReadService.php:115`, `EmployeeProfileWorkspaceService.php:268`, `EmployeeActivityTimelineService.php:259`, `SeparationCaseReadService.php:103`, `EmployeeAccountLinkHistory.php:69`.

The ownership map already records the Campus reference (`:27`), so the drift is in DOMAIN-MAP. Reading another module's tables is rule 4 as written; the register calls this documentation drift and P3 (`HR-R1-0-AUDIT-REGISTER.md:64`). Whether this is drift or code debt is an owner reading.

## 5. Surprises

1. **"25" means imports.** Real use sites are 62. It also misses the 3 App controllers. See 2.3.
2. **HR.15 never took the decision it was assigned.** See 2.2.
3. **ADR 0034 `:43` and `PAYROLL.md:97` state something the code does not do.** That is itself a rule 15 item, independent of the exception.
4. **A Payroll trigger takes `FOR UPDATE` on an HR row** (`compensation_assignments_reject_overlap`), on top of the service lock. A retention function does likewise on `employees`.
5. **8 of Payroll's 9 HR relations are unused in production.** One import is dead (`StatutoryPayrollCalculationService:5`). Removing those is mechanical and affects no behaviour I can see, but it would not change the assessment.
6. **`LeavePageSupport.php:47` is an XRP-3 sibling, not in the register.** `limit(1000)` with no ORDER BY before the limit, so which 1,000 are returned is undefined. Used at `LeaveAdminController.php:196,309`. It filters status only, with no date and no `record_status`. Rule 141 says never to "load a School into the browser". The later `sortBy` runs after the limit.
7. **`EmployeeAssignmentService::end()` plus `create` plus `setPrimary` reaches a move outside rule 130's named path.** See 1.6.
8. **The ownership map has stale rows:** `:46`, `:47` (built, says proposed), `:23` ("category/type not effective-dated (X-3)" against `:24`), `:25`.
9. **Payroll's retention service names HR-internal tables** in its blocker list (`PayrollEmployeeRetentionService.php:71`), including `employee_assignments` and `reporting_relationships`. An HR schema change can therefore need a Payroll edit.
10. **HR's `employees` carries a Payroll-named lock trigger** (`trg_employees_user_link_payroll_lock`) and `EmployeeService.php:325,370` take `SchoolCompensationLock`. The class lives in `App\Support`, so HR has no Payroll import. Conceptually the dependency runs HR → Payroll concern. Rule 144 records this as intended.
11. **Historic documents show current names.** Payslips and statutory exports read `employees.full_name` at render time. There is no snapshot and no name history. Whether that is right for a filed ECR is a legal and owner question, outside this inventory.
12. **Lookup before authorization on two web routes with no `capability:` middleware.** `CompensationController.php:154` runs `findOrFail` before `authorizeCapability` at `:155`. `StatutoryEmployeeController.php:79` before `:81`. A School member without the capability gets 404 or 403 depending on whether the id exists. Same School, UUID required. `app.payroll.compensation.show` and `store`, `app.payroll.statutory.employees.show` are in `SchoolRouteCoverage.php:1379,1380,1389`. The API routes carry `capability:` middleware and are not affected.
13. **`CompensationController::search` and `StatutoryEmployeeController::search` do not escape `%` or `_`** in the `ilike` term, and have no ORDER BY with their `limit(20)`. `DutyCandidateReader.php:41` does escape.
14. **R1-02's stated method measures only part of its claim.** An import scan cannot see raw SQL (`PayrollPostingIndependence`, retention) or SQL functions.

## 6. Could not determine

- **Actual runtime privileges.** No database access; inferred from migrations and `DatabaseRoleVerifier`.
- **Function bodies after `pg_get_functiondef` transforms.** 9 migrations patch functions this way; the scan reads `CREATE` text only.
- **Non-HR §1 rows.** Targeted greps, not a census. Audit-ledger writers were not traced.
- **Runtime behaviour.** Nothing was executed. The findings at 1.6 item 1, 5 item 6 and 5 item 12 come from reading code.
- **Whether the compensation lock can deadlock** with employment end or a placement writer (FOR UPDATE against their share locks). Not analysed.
- **Performance of any reader swap.** Not measured.
- **Whether 2,000+ or 1,000+ staff is a realistic School size** for the capped reads.
- **Owner intent** on: relationship-only reads outside map `:86`, whether WorkforceTasks counts as HR for rule 4, whether a composed move is a bypass, and whether R1-02's first conjunct admits exceptions at all.
- **The final state of the uncommitted tree.** It was still changing during this work and is not part of this inventory.
