# HR.R1-C12: evidence appendix (2026-11-07)

**Evidence for `HR-R1-C12-DECISION-DOCKET.md`. Nothing here is a decision, and a proposed disposition is not an approval.** Baseline `0b77270c`. Code citations are that commit's. Catalogue facts were read on the local DDEV database at that commit, by read-only queries. That database holds synthetic data, and its privileges come from the same migrations.

## A. Payroll to HR, per reference (D-2)

Recounted at the baseline: 62 executing PHP sites, the C10 total, with the same split. Two exports and one relation moved by a few lines since C10. No read changed: the only Payroll difference touching these models since `82e70a8a` adds `CsvCell::safe()` to two exports.

| Proposed | Sites | Note |
|---|---:|---|
| Governed reader | 22 | 13 existence, 9 labels. 21 need no signature change. `CompensationController:95` depends on `listAssignments()` taking an id, which would also remove one id-only site |
| Narrow exception | 27 | 7 calculation, 1 lock, 3 search and own employments, 2 sorted names, 14 id-only (7 queries, 7 type hints) |
| No change | 13 | 9 relations, 4 retention |
| Separately authorized migration | 0 | |
| **Total** | **62** | |

**Writes: 0.** **Historical lookups: 0.** Payroll has no as-of read of HR. Its history is its own result rows, bound by the foreign keys in A.2.

"Class" is the C10 inventory's class. "Kind" uses the vocabulary of the C12 brief.

| Site | Class | Kind | Proposed | Reason |
|---|---|---|---|---|
| App `PayrollRunController:121` | direct read | read | **Narrow exception** | Resolves an id, then passes the model to a typed service. Reads `id` only. Lookup runs before capability (C12-N3) |
| App `PayrollRunController:142` | direct read | read | **Narrow exception** | Resolves an id, then passes the model to a typed service. Reads `id` only. Lookup runs before capability (C12-N3) |
| App `PayrollRunController:277` | direct read | read | **Governed reader** | `labels()`: unresolved list. Independent swap |
| App `PayrollRunController:294` | direct read | read | **Governed reader** | `labels()`: result names. Independent swap |
| App `PayrollRunController:312` | direct read | read | **Governed reader** | `labels()`: correction candidates. Independent swap |
| App `CompensationController:67` | direct read | read | **Narrow exception** | Search with ended staff, Payroll authority. No reader |
| App `CompensationController:95` | direct read | read | **Governed reader** | `labels()`. **Depends on** `listAssignments()` taking an id, because the page passes this model on. Lookup runs before capability (C12-N3) |
| App `CompensationController:154` | direct read | read | **Narrow exception** | Resolves an id for `assign()`. Lookup runs before capability (C12-N3) |
| App `StatutoryEmployeeController:50` | direct read | read | **Narrow exception** | Search with ended staff. No reader |
| App `StatutoryEmployeeController:79` | direct read | read | **Governed reader** | `labels()`. The services already take an id. Lookup before capability is C12-N3 |
| API `PayrollRunController:84` | direct read | read | **Narrow exception** | Resolves an id for a typed service |
| API `PayrollRunController:104` | direct read | read | **Narrow exception** | Resolves an id for a typed service |
| API `CompensationAssignmentController:35` | direct read | read | **Narrow exception** | Resolves an id for a typed service |
| API `CompensationAssignmentController:54` | direct read | read | **Narrow exception** | Resolves an id for a typed service |
| API `StatutoryPfStatusController:24` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryPfStatusController:32` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryEsiCoverageController:25` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryEsiCoverageController:39` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryTaxProfileController:23` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryTaxProfileController:31` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryIdentifierController:26` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryIdentifierController:34` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| API `StatutoryIdentifierController:42` | eligibility | eligibility | **Governed reader** | `span() !== null`. The result is discarded today. Stays a 404, but its body names the HR model today: docket §4 |
| `EmployeePfStatusAdminService:99` | eligibility | eligibility | **Governed reader** | `span() !== null` replaces `exists()` |
| `EmployeeEsiCoverageAdminService:118` | eligibility | eligibility | **Governed reader** | `span() !== null` replaces `exists()` |
| `EmployeeTaxProfileAdminService:93` | eligibility | eligibility | **Governed reader** | `span() !== null` replaces `exists()` |
| `StatutoryIdentifierAdminService:127` | eligibility | eligibility | **Governed reader** | `span() !== null` replaces `exists()` |
| `PayslipReadService:172` | eligibility | eligibility | **Narrow exception** | Every employment of the acting Employee, any status. No reader, and no HR capability applies |
| `PayslipReadService:178` | direct read | read | **Governed reader** | `labels()`. Independent. Shows the current name, as today |
| `PayrollRunService:392` | calculation | calculation | **Narrow exception** | Provenance path. Loads the model. The risk of touching it is the reason |
| `PayrollRunService:488` | calculation | calculation | **Narrow exception** | As above, correction run |
| `PayrollRunService:666` | calculation | calculation | **Narrow exception** | `starts_on`, `ends_on` decide a partial period |
| `PayrollRunService:701` | calculation | calculation | **Narrow exception** | Manual override calculation |
| `PayrollRunService:736` | calculation | calculation | **Narrow exception** | Correction delta calculation |
| `PayrollRunService:809` | calculation | calculation | **Narrow exception** | `id`, `employee_id` stored on the result |
| `CompensationService:174` | calculation | calculation | **Narrow exception** | Resolves the effective compensation |
| `PayrollRunService:205` | direct read | read | **Narrow exception** | `recordCorrectionDelta`: `id` only |
| `PayrollRunService:258` | direct read | read | **Narrow exception** | `recordManualOverride`: `id` only |
| `CompensationService:72` | direct read | read | **Narrow exception** | `assign`: `id` only |
| `CompensationService:104` | direct read | infrastructure | **Narrow exception** | `FOR UPDATE`, approved by ADR 0034:106. Readers are FOR SHARE and judge status |
| `PayrollCompensationReadService:50` | direct read | read | **Narrow exception** | `id` only |
| `PayrollCompensationAdministrationService:45` | direct read | read | **Narrow exception** | `id` for `isEmployeeOf()` |
| `PayrollRunAdministrationService:72` | direct read | read | **Narrow exception** | `id` for `isEmployeeOf()` |
| `PayrollRunAdministrationService:86` | direct read | read | **Narrow exception** | `id` for `isEmployeeOf()` |
| `PayrollPostingIndependence:304` | direct read | read | **Narrow exception** | SQL join, ordered by number, paged. `paged()` would change the visible order |
| `PayrollPostingIndependence:305` | direct read | read | **Narrow exception** | SQL join, ordered by number, paged. `paged()` would change the visible order |
| `PayrollEmployeeRetentionService:54` | permitted infrastructure | retention | **No change** | Employment ids, under the retention identity |
| `PayrollEmployeeRetentionService:61` | permitted infrastructure | retention | **No change** | Exists predicate, under the retention identity |
| `PayrollEmployeeRetentionService:71` | permitted infrastructure | retention | **No change** | Blocker list naming HR tables, under the retention identity |
| `PayrollEvidenceRetentionService:90` | permitted infrastructure | retention | **No change** | Exists predicate, under the retention identity |
| `StatutoryEcrExportService:96, 128` | relationship | relationship | **Governed reader** | `labels()` built once before the row loop. The TRR-1 formula guard and C10-N7 stay as they are |
| `StatutoryEsiContributionWorksheetExportService:59, 108` | relationship | relationship | **Governed reader** | `labels()` built once before the row loop. The TRR-1 formula guard and C10-N7 stay as they are |
| `StatutoryTdsDraftStatementExportService:75, 113` | relationship | relationship | **Governed reader** | `labels()` built once before the row loop. The TRR-1 formula guard and C10-N7 stay as they are |
| `EmployeeCompensationAssignment:66` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `PayrollRunResult:64` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `PayrollAdjustment:75` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `EmployeePfStatus:56` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `EmployeeEsiCoverage:46` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `EmployeeTaxProfile:48` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `EmployeeStatutoryIdentifier:43` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `PayrollLwfAnnualCharge:27` `employmentRecord()` | relationship | relationship | **No change** | Unused in production. Reads through a key the FK binds |
| `PayrollRunResult:70` `employee()` | relationship | relationship | **No change** | Used by the three exports today. Unused once they move |

### A.2 Foreign keys, from the live catalogue (9)

All are same-School composite keys (rule 70). **Proposed for all nine: approved structural FK.** The split matters for history: five refuse deletion, and four are per-employment statutory facts that leave with their employment.

| Table | Column | To | On delete |
|---|---|---|---|
| `payroll_run_results` | `employee_id, school_id` | `employees` | RESTRICT |
| `payroll_run_results` | `employment_record_id, school_id` | `employment_records` | RESTRICT |
| `payroll_adjustments` | `employment_record_id, school_id` | `employment_records` | RESTRICT |
| `employee_compensation_assignments` | `employment_record_id, school_id` | `employment_records` | RESTRICT |
| `payroll_lwf_annual_charges` | `employment_record_id, school_id` | `employment_records` | RESTRICT |
| `employee_pf_status` | `employment_record_id, school_id` | `employment_records` | CASCADE |
| `employee_esi_coverage` | `employment_record_id, school_id` | `employment_records` | CASCADE |
| `employee_tax_profile` | `employment_record_id, school_id` | `employment_records` | CASCADE |
| `employee_statutory_identifiers` | `employment_record_id, school_id` | `employment_records` | CASCADE |

### A.3 Payroll-owned SQL functions reading HR tables (8)

Read from `pg_proc.prosrc`, so the `pg_get_functiondef` patches the C10 text scan could not follow are included. **Proposed for all eight: narrow exception.** A PHP reader cannot run in a trigger.

| Function | Reads | Locks an HR row |
|---|---|---|
| `compensation_assignments_reject_overlap()` | `employment_records` | Yes |
| `payroll_run_first_paid_compensation(school, run, calc)` | `employment_records`, `employee_account_links` | No |
| `payroll_run_first_paid_beneficiaries(school, run, calc)` | same | No |
| `payroll_run_independence_exception(school, run)` | same | No |
| `payroll_reversal_reconciliation_subjects(school, reconciliation)` | same | No |
| `payroll_reversal_affected_runs(school, reversal, only_run)` | same | No |
| `retention_assert_payroll_employee_floor(school, employee, cutoff)` | `employment_records`, `employees` | Yes |
| `retention_expire_payroll_employee_evidence(school, employee, cutoff, dry_run)` | `employment_records` | No |

31 functions in all read `employment_records`, `employees`, `employee_assignments` or `employee_account_links`: these 8, 18 of HR's own, `leave_requests_guard`, `leave_decisions_guard`, `staff_attendance_records_guard`, `retention_lock_hrx_employee`, and the platform's `retention_lock_rows`.

### A.4 Adjacent facts an exception must name

| Fact | Where | Proposed |
|---|---|---|
| Raw reads of `employee_account_links` | `PayrollSelfBeneficiaries.php:28,31` | Narrow exception. Rule 145 sanctions the reads |
| A Payroll-named lock trigger on an HR table | `trg_employees_user_link_payroll_lock` | Narrow exception. Rule 144 records the order |
| HR takes the compensation lock | `EmployeeService.php:334,379`, through `App\Support\Concurrency\SchoolCompensationLock` | No change. HR imports no Payroll class |
| A Payroll retention list naming HR tables | `PayrollEmployeeRetentionService.php:71` | No change. An HR schema change can need a Payroll edit |
| One dead import | `StatutoryPayrollCalculationService.php:5` | Remove with the reader moves |

### A.5 Outside Payroll

**24** files outside HR and Payroll carry `use App\Domain\HR\Infrastructure\...`, at this baseline and at `82e70a8a`. The C10 inventory's "25" does not reproduce. Two more files mention the namespace in a docblock only. 15 of the 47 `::query()` sites are outside Payroll, and 2 of those are in `TaskAssignmentService`, an HR sub-domain.

## B. Model inventory (GOV-6)

87 models in the HR, Leave, Staff Attendance, WorkforceTasks and Payroll namespaces. "Runtime" is the local catalogue's privilege for `school_os_app`: I insert, U update, D delete. **No verified mutation bypass exists:** every caller-supplied array reaching a write sits behind a strip or an intersect list.

| Disposition | Models | Brief's class |
|---|---:|---|
| Hardened by C11 | 9 | Done |
| Closed by construction | 3 | Done |
| Service-bound | 13 | Service-bound protected attributes. **Harden first** |
| Stateful, fixed-array writers | 8 | Harmless controlled construction, with state. **Harden second** |
| Statutory | 16 | Decision-dependent. Legally gated |
| Append-only by privilege | 27 | Intentionally append-only: the runtime role holds no UPDATE |
| Configuration and operational | 11 | No caller-supplied array reaches them. This says nothing else about them: `PayrollRunResult` and `PayrollRunResultLine` are pay rows, with runtime UPDATE and DELETE |
| **Total** | **87** | |

Remaining after C11: 75. Their fillable `school_id` and actor columns are set by the writer. The list below names the sensitive fillable columns per model.

| Disposition | Domain | Model | Runtime | Sensitive fillable columns |
|---|---|---|---|---|
| Hardened | HR | `EmploymentRecord` | IU· | — |
| Hardened | HR | `EmploymentSeparationCase` | IU· | — |
| Hardened | Leave | `LeaveAccrualRun` | IU· | — |
| Hardened | Leave | `LeavePolicy` | IU· | — |
| Hardened | Leave | `LeaveRequest` | IU· | — |
| Hardened | Payroll | `EmployeeCompensationAssignment` | IU· | `effective_to` |
| Hardened | Payroll | `PayrollPeriod` | IUD | `ends_on` |
| Hardened | Payroll | `PayrollRun` | IUD | — |
| Hardened | WorkforceTasks | `WorkforceTask` | IU· | — |
| Closed | HR | `EmployeeAccountLink` | ··· | — |
| Closed | HR | `EmployeeAccountLinkCorrection` | IU· | — |
| Closed | HR | `EmployeeReferenceCode` | IU· | — |
| Service-bound | HR | `Department` | IUD | `school_id`, `status` |
| Service-bound | HR | `Employee` | IU· | `user_id` |
| Service-bound | HR | `EmployeeAddress` | IU· | `school_id`, `employee_id` |
| Service-bound | HR | `EmployeeCategory` | IUD | `school_id`, `status` |
| Service-bound | HR | `EmployeeCertification` | IU· | `school_id`, `employee_id`, `verification_status`, `verified_at` |
| Service-bound | HR | `EmployeeDocument` | IU· | `school_id`, `employee_id`, `uploaded_by_user_id`, `uploaded_at`, `status` |
| Service-bound | HR | `EmployeeEmergencyContact` | IU· | `school_id`, `employee_id`, `is_primary` |
| Service-bound | HR | `EmployeeExperience` | IU· | `school_id`, `employee_id`, `ends_on` |
| Service-bound | HR | `EmployeeNote` | IU· | `school_id`, `employee_id`, `author_user_id` |
| Service-bound | HR | `EmployeePersonalDetail` | IU· | `school_id`, `employee_id`, `marital_status` |
| Service-bound | HR | `EmployeeQualification` | IU· | `school_id`, `employee_id`, `verification_status`, `verified_at` |
| Service-bound | HR | `Position` | IUD | `school_id`, `status` |
| Service-bound | Leave | `LeaveType` | IU· | `school_id`, `status` |
| Stateful | HR | `EmployeeAssignment` | IU· | `school_id`, `employment_record_id`, `is_primary`, `ends_on` |
| Stateful | HR | `ReportingRelationship` | IU· | `ends_on`, `started_by_user_id`, `ended_by_user_id`, `voided_at`, `voided_by_user_id` |
| Stateful | HR | `SeparationClearanceItem` | IU· | — |
| Stateful | HR | `SeparationClearanceRequirement` | IU· | `created_by_user_id` |
| Stateful | Leave | `LeavePolicyAssignment` | IU· | `school_id`, `employment_record_id`, `effective_to`, `created_by_user_id`, `ended_at`, `ended_by_user_id` |
| Stateful | Payroll | `SalaryComponent` | IUD | `school_id`, `status` |
| Stateful | Payroll | `SalaryStructure` | IUD | `school_id`, `status` |
| Stateful | StaffAttendance | `StaffAttendanceRecord` | IU· | `school_id`, `employment_record_id`, `first_half_status`, `second_half_status`, `recorded_by_user_id` |
| Statutory | Statutory | `EmployeeEsiCoverage` | IU· | `school_id`, `employment_record_id` |
| Statutory | Statutory | `EmployeePfStatus` | IU· | `school_id`, `employment_record_id`, `has_higher_pension_status` |
| Statutory | Statutory | `EmployeeStatutoryIdentifier` | IU· | `school_id`, `employment_record_id` |
| Statutory | Statutory | `EmployeeTaxProfile` | IU· | `school_id`, `employment_record_id` |
| Statutory | Statutory | `PayrollEsiRuleVersion` | IUD | `effective_to`, `status` |
| Statutory | Statutory | `PayrollIncomeTaxRuleSlab` | IUD | — |
| Statutory | Statutory | `PayrollIncomeTaxRuleVersion` | IUD | `effective_to`, `status` |
| Statutory | Statutory | `PayrollLwfAnnualCharge` | I·· | `school_id`, `employment_record_id` |
| Statutory | Statutory | `PayrollLwfRuleVersion` | IUD | `effective_to`, `status` |
| Statutory | Statutory | `PayrollPfRuleVersion` | IUD | `effective_to`, `status` |
| Statutory | Statutory | `PayrollProfessionalTaxRuleSlab` | IUD | — |
| Statutory | Statutory | `PayrollProfessionalTaxRuleVersion` | IUD | `effective_to`, `status` |
| Statutory | Statutory | `PayrollSalaryComponentStatutoryClassification` | IUD | `school_id`, `effective_to` |
| Statutory | Statutory | `PayrollStatutoryAccountingConfiguration` | IUD | `school_id` |
| Statutory | Statutory | `PayrollStatutoryCalculationResult` | IUD | `school_id` |
| Statutory | Statutory | `PayrollStatutoryRunPosting` | I·· | `school_id`, `payroll_run_id`, `actor_user_id` |
| Append-only | HR | `EmployeePlacementChange` | I·· | `school_id`, `employment_record_id`, `recorded_by_user_id` |
| Append-only | HR | `EmploymentSeparationEvent` | I·· | `actor_user_id`, `occurred_at` |
| Append-only | HR | `EmploymentTerm` | I·· | `school_id`, `employment_record_id`, `recorded_by_user_id` |
| Append-only | Leave | `LeaveAccrualRunItem` | I·· | `school_id`, `employment_record_id` |
| Append-only | Leave | `LeaveAllocationRun` | I·· | `school_id`, `executed_by_user_id`, `executed_at` |
| Append-only | Leave | `LeaveDecision` | I·· | `school_id`, `decided_by_user_id` |
| Append-only | Leave | `LeaveEligibilityRule` | I·· | `school_id`, `created_by_user_id` |
| Append-only | Leave | `LeaveEligibilityRun` | I·· | `school_id`, `executed_by_user_id`, `executed_at` |
| Append-only | Leave | `LeaveLedgerEntry` | I·· | `school_id`, `employment_record_id`, `actor_user_id` |
| Append-only | Leave | `LeaveRequestDay` | I·· | `school_id` |
| Append-only | Leave | `LeaveYear` | I·· | `school_id`, `ends_on` |
| Append-only | Leave | `LeaveYearClose` | I·· | `school_id`, `executed_by_user_id`, `executed_at` |
| Append-only | Leave | `LeaveYearCloseItem` | I·· | `school_id`, `employment_record_id` |
| Append-only | Leave | `LeaveYearCloseReconciliation` | I·· | `school_id`, `created_by_user_id` |
| Append-only | Leave | `LeaveYearStartChange` | I·· | `school_id`, `created_by_user_id` |
| Append-only | Payroll | `CompensationAssignmentValue` | I·· | `school_id` |
| Append-only | Payroll | `PayrollAdjustment` | I·· | `school_id`, `payroll_run_id`, `employment_record_id`, `actor_user_id` |
| Append-only | Payroll | `PayrollPostingAttestation` | I·· | `school_id`, `payroll_run_id`, `attested_by_user_id` |
| Append-only | Payroll | `PayrollReversalReconciliation` | I·· | `school_id`, `payroll_run_id`, `reversed_at`, `reversed_by_user_id` |
| Append-only | Payroll | `PayrollReversalReconciliationReview` | I·· | `school_id`, `reviewed_by_user_id` |
| Append-only | Payroll | `PayrollRunCalculation` | I·· | `school_id`, `payroll_run_id`, `calculated_by_user_id` |
| Append-only | Payroll | `PayrollRunCalculationSource` | I·· | `school_id`, `payroll_run_id`, `employment_record_id`, `effective_to` |
| Append-only | Payroll | `PayrollRunHrxInput` | I·· | `school_id`, `payroll_run_id`, `employment_record_id`, `captured_by_user_id`, `captured_at` |
| Append-only | Payroll | `PayrollRunPosting` | I·· | `school_id`, `payroll_run_id`, `actor_user_id` |
| Append-only | StaffAttendance | `StaffAttendanceCorrection` | I·· | `school_id`, `employment_record_id`, `before_first_half_status`, `before_second_half_status`, `after_first_half_status`, `after_second_half_status`, `corrected_by_user_id` |
| Append-only | WorkforceTasks | `TaskEvent` | I·· | `actor_user_id`, `occurred_at` |
| Append-only | WorkforceTasks | `TaskTemplateVersion` | I·· | `created_by_user_id` |
| Configuration | HR | `HrEmployeeNumberCounter` | IUD | `school_id` |
| Configuration | Leave | `LeaveSetting` | IU· | `school_id`, `updated_by_user_id` |
| Configuration | Leave | `StaffHoliday` | IUD | `school_id`, `created_by_user_id` |
| Configuration | Leave | `StaffWorkingWeekday` | IUD | `school_id`, `updated_by_user_id` |
| Configuration | Payroll | `PayrollAccountingConfiguration` | IUD | `school_id` |
| Configuration | Payroll | `PayrollRunResult` | IUD | `school_id`, `payroll_run_id`, `employment_record_id`, `employee_id` |
| Configuration | Payroll | `PayrollRunResultLine` | IUD | `school_id` |
| Configuration | Payroll | `SalaryStructureComponent` | IUD | `school_id` |
| Configuration | WorkforceTasks | `TaskCategory` | IU· | `created_by_user_id` |
| Configuration | WorkforceTasks | `TaskGenerationRun` | IU· | `started_by_user_id`, `started_at` |
| Configuration | WorkforceTasks | `TaskTemplate` | IU· | `created_by_user_id` |

## C. Runtime privileges, HR family (R1-01)

From `has_table_privilege('school_os_app', ...)`. A privilege answers what the role can issue, not whether a write is legitimate.

| Privileges | Tables |
|---|---|
| SELECT only (1) | `employee_account_links` |
| Insert only (18) | `employee_placement_changes`, `employment_separation_events`, `employment_terms`, `leave_accrual_run_items`, `leave_allocation_runs`, `leave_decisions`, `leave_eligibility_rules`, `leave_eligibility_runs`, `leave_ledger_entries`, `leave_request_days`, `leave_year_close_items`, `leave_year_close_reconciliations`, `leave_year_closes`, `leave_year_start_changes`, `leave_years`, `staff_attendance_corrections`, `workforce_task_events`, `workforce_task_template_versions` |
| Insert, update (28) | `employee_account_link_corrections`, `employee_addresses`, `employee_assignments`, `employee_certifications`, `employee_documents`, `employee_emergency_contacts`, `employee_experience_records`, `employee_notes`, `employee_personal_details`, `employee_qualifications`, `employee_reference_codes`, `employees`, `employment_records`, `employment_separation_cases`, `leave_accrual_runs`, `leave_policies`, `leave_policy_assignments`, `leave_requests`, `leave_settings`, `leave_types`, `reporting_relationships`, `separation_clearance_items`, `separation_clearance_requirements`, `staff_attendance_records`, `workforce_task_categories`, `workforce_task_generation_runs`, `workforce_task_templates`, `workforce_tasks` |
| Insert, update, delete (6) | `employee_categories`, `hr_departments`, `hr_employee_number_counters`, `positions`, `staff_holidays`, `staff_working_weekdays` |

**Runtime DELETE with no caller (C12-N2).** Of the six tables in the last row, only `staff_holidays` is deleted by application code (`StaffCalendarService.php:88`, S-16). A search of `app/` finds no delete of `hr_departments`, `positions`, `employee_categories`, `hr_employee_number_counters` or `staff_working_weekdays`. The proposed point 4 would fail those five as written. Payroll tables were not assessed.

## D. Execution-time School check (rule 86)

Application classes with a write and no `SchoolOperationalGuard`, read at the baseline. Leave and Staff Attendance have none outstanding.

| Group | Classes | Registered as |
|---|---|---|
| HR profile | `EmployeeAddressService`, `EmployeeEmergencyContactService`, `EmployeeNoteService`, `EmployeeQualificationService`, `EmployeeExperienceService`, `EmployeeCertificationService`, `EmployeePersonalDetailService` | C11-N1 |
| HR documents | `EmployeeDocumentService` | C11-N1 |
| HR reference data | `DepartmentService`, `PositionService`, `EmployeeCategoryService`, `EmployeeReferenceCodeService` | C11-N1 |
| Shared archive path | `Profile/ArchivesProfileRecords`, used by six of the profile services | C11-N1's family, but not named there. **C12-N1** |
| WorkforceTasks | `TaskCatalogueService`, and the `administrative` path (`hr.tasks.manage`) of `TaskAssignmentService` and `TaskWorkflowService` | **C12-N1** |
| Documents | `DocumentService` on the generic Employee upload and archive routes | Outside HR, noted only |

`EmployeeOnboardingService`, `EmployeeImportService` and `EmployeeLifecycleService` write nothing themselves: they delegate to guarded writers.

**WorkforceTasks is partly covered.** Self-service and the manager path reach the check through `ActingEmployeeResolver::hold()`, which calls `holdOperational()` (`:106`), and a suspended School then answers as not found. `TaskAuthority::pathFor()` returns `administrative` at `:58-59` before any such call, and `TaskCatalogueService` has no call at all. Other domains were not assessed.

**Document order.** `DocumentService::store()` writes the bytes before its transaction opens, and its catch deletes the object if the transaction fails. A guard only inside the `$within` closure therefore refuses after a storage write, which is safe but wasteful. No guard exists in either place today. WO-1 adds one to `EmployeeDocumentService::register()`'s first transaction, beside the existing self-refusal, and one in the closure, which holds FOR SHARE to commit.
