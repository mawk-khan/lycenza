# HR.R1-C13: independent HR hardening (2026-11-07)

**This is not the closure audit, and nothing here is an approval.** D-1 to D-10 and R1-01 stay pending. Baseline `ceff7576`. `HR-R1-CLOSURE-CONTRACT.md` stays the authority, `HR-R1-0-AUDIT-REGISTER.md` holds the findings, and `HR-R1-C12-DECISION-DOCKET.md` holds the decisions and work orders. WO-1 and WO-2 were authorized by the owner's HR.R1-C13 instruction.

## 1. Scope

| Item | Class | Outcome |
|---|---|---|
| C11-N1, HR profile and reference-data writers | AUTHORIZED-INDEPENDENT | Closed for development |
| C12-N1, the archive path and the task writers | AUTHORIZED-INDEPENDENT | Closed for development |
| Rule 86 elsewhere in the HR family | EVIDENCE-ONLY, then AUTHORIZED-INDEPENDENT | One more gap found and closed: the generation run row. The generic Documents route is closed through HR's port |
| GOV-6, service-bound and stateful HR and Leave models | AUTHORIZED-INDEPENDENT | 19 models hardened |
| GOV-6, Payroll's six models | OTHER-DOMAIN | Untouched. WO-2's owners are HR and Leave |
| GOV-6, statutory models and the staff calendar | OWNER-DECISION-REQUIRED | Untouched: ADR 0036, D-6 |
| R1-01, D-1, D-2, D-3, D-4, D-6 | OWNER-DECISION-REQUIRED | Untouched |

No migration, table, capability, route, Vue file or capability-map status changed.

## 2. WO-1: the writers

Rule 86 says every School business effect re-checks the School at execution, through `SchoolOperationalGuard`, inside its claim transaction. `holdOperational()` reads the School row FOR SHARE until commit, so a writer and a lifecycle transition serialize. The refusal renders as a plain 404, the answer School routes already give.

**Reproduced on `ceff7576`:** all 52 writer cases then in the test reached the write on a suspended School, and a suspended School took a document. The 52 positive controls passed. The generic route and generation cases were added after the independent review.

| Service | Method | Business effect | Guard before C13 | Transaction | Correction | Test |
|---|---|---|---|---|---|---|
| `EmployeeAddressService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeAddressService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeEmergencyContactService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeEmergencyContactService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeEmergencyContactService` | `setPrimary` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeNoteService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeNoteService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeQualificationService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeQualificationService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeQualificationService` | `verify` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeQualificationService` | `reject` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeExperienceService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeExperienceService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeCertificationService` | `add` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeCertificationService` | `update` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeCertificationService` | `verify` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeCertificationService` | `reject` | Profile write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeePersonalDetailService` | `setDetails` | Personal data write | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `ArchivesProfileRecords (trait)` | `remove, for six services` | Archive with a closed reason | None | Own | Guard first, `$employee->school_id` | `HrProfileSchoolCheckTest` |
| `EmployeeReferenceCodeService` | `record` | Reference code | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeReferenceCodeService` | `retire` | Reference code | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `DepartmentService` | `create` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `DepartmentService` | `update` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `DepartmentService` | `archive` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `DepartmentService` | `reactivate` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `DepartmentService` | `reparent` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `PositionService` | `create` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `PositionService` | `update` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `PositionService` | `archive` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `PositionService` | `reactivate` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeCategoryService` | `create` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeCategoryService` | `update` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeCategoryService` | `archive` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeCategoryService` | `reactivate` | Reference data | None | Own | Guard first, `$school->id` | `HrProfileSchoolCheckTest` |
| `EmployeeDocumentService` | `register` | HR document | None | Own | Pre-flight transaction, and again in the closure | `HrProfileSchoolCheckTest` |
| `EmployeeDocumentService` | `update` | HR document | None | Own | Guard first | `HrProfileSchoolCheckTest` |
| `EmployeeDocumentService` | `archive` | HR document | None | Own | Now one outer transaction, guard first, before the Document row | `HrProfileSchoolCheckTest` |
| `EmployeeDocumentService` | `archiveRegistered` | HR document | None | Own | Through `archive` | `HrProfileSchoolCheckTest` |
| `HrEmployeeDocumentPolicy` | `beforeUpload, beforeArchive` | Generic route for an Employee file | None | Documents' | Plain read, so a refused School stores no bytes | `HrProfileSchoolCheckTest` |
| `HrEmployeeDocumentPolicy` | `refuseOwnRecord` | Generic route for an Employee file | None | Documents' | Guard first, inside the Documents transaction | `HrProfileSchoolCheckTest` |
| `TaskCatalogueService` | `createCategory` | Task configuration | None | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskCatalogueService` | `retireCategory` | Task configuration | None | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskCatalogueService` | `createTemplate` | Task configuration | None | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskCatalogueService` | `addVersion` | Task configuration | None | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskCatalogueService` | `retireTemplate` | Task configuration | None | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskAssignmentService` | `assign` | Task assignment | Manager path only | Own | Guard first, every path | `HrProfileSchoolCheckTest` |
| `TaskAssignmentService` | `reassign` | Task assignment | Manager path only | Own | Guard first, every path | `HrProfileSchoolCheckTest` |
| `TaskWorkflowService` | `review` | Task transition | Manager path only | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskWorkflowService` | `cancel` | Task transition | Manager path only | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskWorkflowService` | `submit` | Task transition | Through the acting Employee | `inSchool()` | Guard in `inSchool()` | `HrProfileSchoolCheckTest`, race |
| `TaskGenerationService` | `run`, `generateNow` | Run row | Batches only: the row was written first | New, around the row | Guard first | `a_suspended_school_starts_no_generation_run_and_reopens_none` |

**Excluded, with the reason:**

| Path | Why |
|---|---|
| Reads, including `EmployeeDocumentService::content` | No effect |
| `EmployeeRecordRetentionService`, Leave, Staff Attendance and Payroll retention | Platform retention must keep running for a suspended School (ADR 0047 §8). A purge calls no guarded service |
| `EmployeeOnboardingService`, `EmployeeImportService`, `EmployeeLifecycleService` | They delegate to guarded writers |
| `EmployeeNumberAllocator`, `createRecord`, `setPrimaryRecord`, `closeForEmploymentEnd`, `startNoticePeriod`, `endNoticePeriod` | Internal, called only inside a guarded transaction |
| Leave `recordAccrual`, `recordConsumption`, `recordReversal`, `recordCloseMovement`, `reconcile` | Internal, called only inside a guarded transaction |
| `EmployeeAccountLinkCorrectionService` approve, refuse | `requireCurrent()` holds the School |
| `LeaveAccrualService::markFailed` | Bookkeeping after a refusal, not a business effect |

Other ERP domains were not assessed for rule 86.

**C12 said "Leave and Staff Attendance have none outstanding" from a file-level search.** A method-level scan here confirms it, for the reasons above.

### Placement

Authorization stays first, outside the transaction. The guard is then the first statement of the transaction, before any row lock, ahead of `EmployeePlacementLock` as C11 placed it. `HrSchoolGuardCoverageTest` pins this for each profile and reference writer.

Three places needed more than a line:

- **Upload.** `DocumentService::store()` writes bytes before its transaction. `register()` checks in its pre-flight, so nothing is stored, and again in the closure, which holds the lock to commit.
- **Archive.** `archive()` now wraps the Document path in one outer transaction, so the School comes before the Document row. `archiveForEmployee` joins it as a savepoint.
- **The generic Documents route.** `HrEmployeeDocumentPolicy`, HR's own implementation of the port, checks in `beforeUpload` and `beforeArchive`, and holds the School in `refuseOwnRecord`, the first statement of both Documents transactions for an Employee owner. No Documents file changed. Non-Employee owners there were not assessed.

**Locks.** School FOR SHARE conflicts only with `SchoolLifecycleService`, which takes no HR, access or compensation lock, so no new cycle exists on reading and two-process proof. Rule 141's order is untouched: none of these writers takes Identity's lock. No deadlock was found in the races.

**Refusals.** Import keeps its neutral `unavailable` row. An unauthorized, wrong-School or Campus-only actor is refused for that reason first, and learns nothing of the School.

## 3. WO-2: the models

Counts: 87 models, re-verified. **No request reached a protected column before C13, and none does now.** A strip or intersect list stood in front of each one. So this is structural hardening, not a closed exploit: protection no longer rests on each hand-written list staying complete.

The method, by writer shape:

| Shape | Writer now | Why |
|---|---|---|
| Caller array plus owner columns | `new Model($attributes)`, then `forceFill` of the owner columns | `forceCreate([...$attributes])` would have widened the surface: the caller's fields must keep passing through `fill()` |
| Fixed array | `forceCreate` | Every key is written by the service |
| A state change | `forceFill([...])->save()` | The transition is stated |
| `employees.user_id` | Property assignment | `ActingEmployeeArchitectureGuardTest` forbids `forceFill` of the link |

**Production has no strict mode.** A dropped key disappears there with no error, while `TestCase` turns it into an exception for the listed models. That is why all 28 are on the strict list: it caught a real regression before publication. `EmployeeQualificationService` and `EmployeeCertificationService` added the verification reset to their array, which would have left an edited credential verified. Both now state the reset, and the audit's `fields` are unchanged.

`HrModelMassAssignmentCatalogueTest` discovers the models from the namespaces, so a new one fails until it has a disposition, and its three lists must partition the namespace. It cannot know what a column means: the name pattern needs a person, and the 19 are pinned to exact lists for that reason.

### Model matrix

| Action | Models |
|---|---:|
| HARDENED (19 in C13, 9 in C11) | 28 |
| ALREADY SAFE (27 append-only by privilege, 3 by construction) | 30 |
| DECISION-GATED (16 ADR 0036, 2 D-6) | 18 |
| OTHER-DOMAIN (Payroll) | 6 |
| INTENTIONALLY FILLABLE | 5 |
| OPEN, evidence needed | 0 |
| **Total** | **87** |

| Model | Protected attributes | Writer | Input trust | Database protection | Risk | Action |
|---|---|---|---|---|---|---|
| `Department` | school_id, campus_id, parent_department_id, status | DepartmentService | Fixed array; update stripped | No-cycle trigger, composite keys, RLS. Runtime DELETE held (C12-N2) | Structural | HARDENED |
| `EmployeeAddress` | school_id, employee_id | EmployeeAddressService | Caller array through fill(); owner columns stated | Archive guard freezes Employee, RLS, no DELETE | Structural | HARDENED |
| `EmployeeAssignment` | every column | EmployeeAssignmentService, EmploymentService, PlacementChangeService | Fixed arrays | Never-effective, identity and overlap triggers, RLS, no DELETE | Structural | HARDENED |
| `EmployeeCategory` | school_id, status | EmployeeCategoryService | Fixed array; update stripped | RLS. Runtime DELETE held (C12-N2) | Structural | HARDENED |
| `EmployeeCertification` | school_id, employee_id, verification_status, verified_at | EmployeeCertificationService | As above | Archive guard, RLS, no DELETE | Structural | HARDENED |
| `EmployeeDocument` | every column | EmployeeDocumentService | Fixed array; update is a four-key intersect | `trg_employee_documents_guard`, RLS, no DELETE | Structural | HARDENED |
| `EmployeeEmergencyContact` | school_id, employee_id, is_primary | EmployeeEmergencyContactService | As above | Archive guard, RLS, no DELETE | Structural | HARDENED |
| `EmployeeExperience` | school_id, employee_id | EmployeeExperienceService | As above | Archive guard, RLS, no DELETE | Structural | HARDENED |
| `EmployeeNote` | school_id, employee_id, author_user_id | EmployeeNoteService | Caller array through fill(); owner columns stated | Archive guard, RLS, no DELETE | Structural | HARDENED |
| `EmployeePersonalDetail` | school_id, employee_id | EmployeePersonalDetailService | Allowlist to the model's fields, then unguarded updateOrCreate | Composite key, RLS, no DELETE | Structural | HARDENED |
| `EmployeeQualification` | school_id, employee_id, verification_status, verified_at | EmployeeQualificationService | As above; verify, reject and the reset are stated | Archive guard, RLS, no DELETE | Structural | HARDENED |
| `Employee` | user_id, employee_number | EmployeeService | Caller array, stripped; `user_id` refused; link by property assignment | Identity guard, link-history triggers, RLS, no DELETE | Structural | HARDENED |
| `LeavePolicyAssignment` | every column | LeavePolicyAssignmentService, LeaveEligibilityService | Fixed arrays | Guard and accrual-bounds triggers, RLS, no DELETE | Structural | HARDENED |
| `LeaveType` | school_id, status | LeaveTypeService | Fixed array; update is an intersect | `trg_leave_types_freeze`, RLS, no DELETE | Structural | HARDENED |
| `Position` | school_id, status | PositionService | Fixed array; update stripped | RLS. Runtime DELETE held (C12-N2) | Structural | HARDENED |
| `ReportingRelationship` | every column | ReportingRelationshipService | Fixed arrays | `trg_reporting_relationships_guard`, RLS, no DELETE | Structural | HARDENED |
| `SeparationClearanceItem` | every column | SeparationCaseService | Fixed array, a snapshot | `trg_separation_clearance_items_guard`, RLS, no DELETE | Structural | HARDENED |
| `SeparationClearanceRequirement` | every column | SeparationClearanceRequirementService | Fixed array | RLS, no DELETE | Structural | HARDENED |
| `StaffAttendanceRecord` | every column | StaffAttendanceService | Fixed array | `trg_staff_attendance_records_guard`, RLS, no DELETE | Structural | HARDENED |
| `EmployeeCompensationAssignment` | State, actor, parent | CompensationService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `EmploymentRecord` | State, actor, parent | EmploymentService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `EmploymentSeparationCase` | State, actor, parent | SeparationCaseService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `LeaveAccrualRun` | State, actor, parent | LeaveAccrualService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `LeavePolicy` | State, actor, parent | LeavePolicyService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `LeaveRequest` | State, actor, parent | LeaveRequestService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `PayrollPeriod` | State, actor, parent | PayrollPeriodService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `PayrollRun` | State, actor, parent | PayrollRunService | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `WorkforceTask` | State, actor, parent | Task services | Fixed arrays | Own triggers, RLS | Structural | HARDENED (C11) |
| `CompensationAssignmentValue` | school_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `EmployeeAccountLinkCorrection` | None fillable | Trigger or its own service | No mass assignment | Triggers, RLS | None | ALREADY SAFE |
| `EmployeeAccountLink` | None fillable | Trigger or its own service | No mass assignment | Triggers, RLS | None | ALREADY SAFE |
| `EmployeePlacementChange` | school_id, employment_record_id, recorded_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `EmployeeReferenceCode` | None fillable | Trigger or its own service | No mass assignment | Triggers, RLS | None | ALREADY SAFE |
| `EmploymentSeparationEvent` | actor_user_id, occurred_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `EmploymentTerm` | school_id, employment_record_id, recorded_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveAccrualRunItem` | school_id, employment_record_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveAllocationRun` | school_id, executed_by_user_id, executed_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveDecision` | school_id, decided_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveEligibilityRule` | school_id, created_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveEligibilityRun` | school_id, executed_by_user_id, executed_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveLedgerEntry` | school_id, employment_record_id, actor_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveRequestDay` | school_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveYearCloseItem` | school_id, employment_record_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveYearCloseReconciliation` | school_id, created_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveYearClose` | school_id, executed_by_user_id, executed_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveYearStartChange` | school_id, created_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `LeaveYear` | school_id, ends_on | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollAdjustment` | school_id, payroll_run_id, employment_record_id, actor_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollPostingAttestation` | school_id, payroll_run_id, attested_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollReversalReconciliationReview` | school_id, reviewed_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollReversalReconciliation` | school_id, payroll_run_id, reversed_at, reversed_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollRunCalculationSource` | school_id, payroll_run_id, employment_record_id, effective_to | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollRunCalculation` | school_id, payroll_run_id, calculated_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollRunHrxInput` | school_id, payroll_run_id, employment_record_id, captured_by_user_id, captured_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `PayrollRunPosting` | school_id, payroll_run_id, actor_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `StaffAttendanceCorrection` | school_id, employment_record_id, corrected_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `TaskEvent` | actor_user_id, occurred_at | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `TaskTemplateVersion` | created_by_user_id | Its domain service | Fixed array, built once | Runtime role has no UPDATE, RLS | Low | ALREADY SAFE |
| `HrEmployeeNumberCounter` | school_id | Its domain service | Fixed array | RLS | Low | INTENTIONALLY FILLABLE |
| `LeaveSetting` | school_id, updated_by_user_id | Its domain service | Fixed array | RLS | Low | INTENTIONALLY FILLABLE |
| `TaskCategory` | created_by_user_id | Its domain service | Fixed array | RLS | Low | INTENTIONALLY FILLABLE |
| `TaskGenerationRun` | started_by_user_id, started_at | Its domain service | Fixed array | RLS | Low | INTENTIONALLY FILLABLE |
| `TaskTemplate` | created_by_user_id | Its domain service | Fixed array | RLS | Low | INTENTIONALLY FILLABLE |
| `EmployeeEsiCoverage` | school_id, employment_record_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `EmployeePfStatus` | school_id, employment_record_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `EmployeeStatutoryIdentifier` | school_id, employment_record_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `EmployeeTaxProfile` | school_id, employment_record_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollEsiRuleVersion` | effective_to, status | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollIncomeTaxRuleSlab` | None listed | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollIncomeTaxRuleVersion` | effective_to, status | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollLwfAnnualCharge` | school_id, employment_record_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollLwfRuleVersion` | effective_to, status | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollPfRuleVersion` | effective_to, status | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollProfessionalTaxRuleSlab` | None listed | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollProfessionalTaxRuleVersion` | effective_to, status | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollSalaryComponentStatutoryClassification` | school_id, effective_to | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollStatutoryAccountingConfiguration` | school_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollStatutoryCalculationResult` | school_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `PayrollStatutoryRunPosting` | school_id, payroll_run_id, actor_user_id | Statutory services | Unreachable without a statutory grant | RLS | Latent | DECISION-GATED (ADR 0036) |
| `StaffHoliday` | school_id, created_by_user_id | StaffCalendarService | Fixed array | RLS. Runtime DELETE held | Low | DECISION-GATED (D-6) |
| `StaffWorkingWeekday` | school_id, updated_by_user_id | StaffCalendarService | Fixed array | RLS. Runtime DELETE held | Low | DECISION-GATED (D-6) |
| `PayrollAccountingConfiguration` | school_id | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |
| `PayrollRunResultLine` | school_id | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |
| `PayrollRunResult` | school_id, payroll_run_id, employment_record_id, employee_id | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |
| `SalaryComponent` | school_id, status | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |
| `SalaryStructureComponent` | school_id | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |
| `SalaryStructure` | school_id, status | Payroll services | Fixed array | RLS. Runtime DELETE held | Not assessed | OTHER-DOMAIN (Payroll) |

**Also tightened:** `WorkforceTask`, hardened by C11, kept four attribution columns fillable with no caller. The wider pattern found `campus_id`.

**Database backstops, measured as the runtime role.** `hr_profile_record_archive_guard` refuses any change of a profile row's Employee or School, even to a colleague in the same School. Another School's update touches nothing, no context reads nothing, and DELETE is denied (`HrHardenedColumnRawRoleTest`).

**Payroll's `SalaryComponent` and `SalaryStructure`** were in C12's stateful group. WO-2 names HR and Leave, so they stay OTHER-DOMAIN.

## 4. What the tests prove

| Test | Kind |
|---|---|
| `HrProfileSchoolCheckTest` | Proves WO-1 through direct service calls: the refusal, no row changed, nothing audited, and a positive control for each writer |
| `HrProfileSchoolRaceTest` | Two real sessions, the contender observed blocked, both commit orders, on notes, archive, catalogue and an administrative cancel. A `FOR UPDATE NOWAIT` probe proves a waiting writer holds none of its own rows |
| `HrSchoolGuardCoverageTest` | Structural: every writing class answers rule 86, and the guard is first. It sees files, not paths |
| `HrModelMassAssignmentCatalogueTest` | Structural, discovered from the namespaces |
| `HrServiceBoundModelTest` | **Regression only** for forged keys: the strip lists held before C13 too. It runs in true production mode and reads the row. It does prove the reset, the personal-details writer and the link |
| `HrHardenedColumnRawRoleTest` | The database's answer as `school_os_app`, 32 cases |

**13 mutations each failed a test,** one of them only after the first run exposed a gap: a model could leave the strict list unnoticed, which is why the lists must now partition the namespace. Moving a guard after the task row lock fails the race probe.

## 5. Review

Two reviews read the candidate, and neither ran anything. No HIGH.

| Finding | Severity | Disposition |
|---|---|---|
| The generic Documents route wrote an Employee file on a suspended School | MEDIUM | Fixed through HR's port, with no Documents change |
| `TaskGenerationService` wrote its run row before the guard | LOW | Fixed |
| Pins saw only `school_id` and a name pattern | LOW | Fixed: exact lists, and a true production-mode test |
| Personal details ran unguarded over an allowlist, hiding a dropped key from strict mode | LOW | Fixed. The Employee lock already serializes the first insert, so it is a plain `fill()` |
| `AcademicDepartment` caught by a replace, doubled docblocks | LOW | Fixed |
| Rules 86 and 97, a hard-coded timezone, invalid fixture values | LOW | Fixed |

## 6. Findings reconciled

| Id | Before | Now | Remaining |
|---|---|---|---|
| C11-N1 | OPEN | **CLOSED for development** | None for the writers it named |
| C12-N1 | OPEN | **CLOSED for development** | Within the HR family |
| GOV-6 | PARTIAL | **PARTIAL** | 18 decision-gated, 6 Payroll. Each has a disposition, and full closure needs those owners |
| C11-N2, C11-N3 | OPEN | Unchanged | Not in WO-1 or WO-2. C11-N3 stays a test flake |
| C11-N4 | Owner note | Unchanged | HR owner |
| C12-N2 | OPEN | Unchanged | R1-01 point 4, WO-11 |
| C12-N3, C12-N4, C12-N5 | OPEN | Unchanged | WO-10, D-2, HR owner |

## 7. Preserved

R1-01 stays PROPOSED and `HrWriterBoundaryTest` keeps its 13 cases, one literal updated. D-1, its 82 rows, the nine rows outside HR.4–8 and the four ACTIVE rows are unchanged. D-2's inventory is unchanged, with no change to `span()`, `labels()` or any 404 body. D-3, D-4, D-6, S-7 and S-16 are unchanged. Payroll, TeachingAssignments, Timetable, Transport, Visitor, Finance, Inventory and Library are unchanged. HRC-3, HRC-10, HRC-11 and C10-N1 hold. No legal or Security determination moved.

## 8. Limits

- **Browser:** none run. No screen changed, and the refusal surfaces only in the race after the request-time check. A screen-reader pass stays outstanding.
- **`ddev demo-reset`:** not run, so the local School database was left as it was. The demo builders ran through `tests/Feature/Demo`.
- **Source pins** see files, not paths. A write through a dynamically named variable would escape them.
- **The first local family run was disturbed.** Unrelated tests deadlocked and left rows behind. They passed when rerun alone, which points to a second process on the test database, most likely a run I had started and lost track of. That cause is not proven.

## 9. Published

`060743c4`: CI gate run 42398199405, shards 2,852, 2,853, 2,853 and 2,852, 11,410 cases, 0 failures, 1 skip (ESI-12), 462,457 assertions, coverage union green. `main` CI run 42400347210 green. The first candidate passed. HR.R1 stays OPEN, staging stays blocked, and production stays NO-GO.
