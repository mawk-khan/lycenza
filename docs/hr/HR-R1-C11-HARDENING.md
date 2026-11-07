# HR.R1-C11: independent hardening and decision-gated progress (2026-11-07)

**This is not the closure audit, and nothing here is an approval.** D-1 to D-10 stay
pending. Baseline `33ac0b6b`. `HR-R1-CLOSURE-CONTRACT.md` stays the authority,
`HR-R1-0-AUDIT-REGISTER.md` holds the findings, and `HR-R1-C10-CLOSURE-READINESS.md`
holds the decision packages, which this record does not repeat.

## 1. Scope matrix

| Item | Severity | Behaviour at baseline | Contract | Owner | Correction | Database | Dependency | Class |
|---|---|---|---|---|---|---|---|---|
| HRC-10 | P3 | `EmploymentRecord::$fillable` held `school_id`, `status` and the terms columns. No request reached them: every service strips or intersects its input | Rule 130, rule 97 | HR | `$fillable` is `probation_ends_on` only; the writers state their columns | No change. A catalogue test pins the projection's single writer | None | AUTHORIZED-INDEPENDENT |
| HRC-11 | P3 | HR writers checked the School only at the request. A suspension after that, or a direct call, reached the write | Rule 86 | HR | `SchoolOperationalGuard` first in the transaction | No change | None | AUTHORIZED-INDEPENDENT |
| GOV-6 | P3 | Lifecycle models accepted state, actor and parent columns by mass assignment | Rule 97 | HR, Leave, Payroll, Tasks | Nine lifecycle models hardened, `TestCase` refuses a dropped key on them | No change | None | AUTHORIZED-INDEPENDENT, in part |
| C10-N1 | P3 | `is_paid` could change on an untracked type with approved leave | Rule 133 | Leave | `leave_types_freeze` counts a request as use | One forward migration, verifier row | None | AUTHORIZED-INDEPENDENT |
| R1-01 | Criterion | No agreed definition; no HR writer pin | Contract R1-01 | Architecture | Pins only what existing contracts require | No change | Definition | EVIDENCE-ONLY, DECISION-GATED for closure |
| D-1, D-2, D-3, D-4, D-6 | Decisions | Pending | §5 of the C10 readiness record | HR owner, Architecture, Security | None | None | Owner | DECISION-GATED |
| XRP-3, XRP-4, X-1 | P3 | Direct reads in other domains | Rule 4, R1-02 | Teaching, Timetable, Transport, Visitor | None | None | Their checkpoints | OTHER-DOMAIN |
| HRC-3, R1-06 | Closed, evidence | Rerun unchanged | Rule 95, rule 28 | HR | None | None | None | EVIDENCE-ONLY |

Two readings of the brief, corrected against the audit text:
- HRC-11's contract is rule 86's execution-time School check. `ActingEmployeeResolver`
  was the only HR caller of that guard at the baseline. It is not the contract.
- `is_paid` is `leave_types.is_paid`, a classification of leave, not a payment state.
  Payroll's HRX evidence reads it through `LeaveCoverageReader`. It moves no money
  while HRX-L4 is open.

## 2. What changed

**HRC-10.** `EmploymentRecord` accepts `probation_ends_on` only. `PayrollRun` and
`LeaveRequest` accept nothing. Lifecycle writers use `forceCreate`/`forceFill`, so a
state column is always written on purpose.

The finding's second half, that the projection guard depends on trigger depth, is
not a rewrite of that guard. `only_the_terms_trigger_writes_the_projection` fails if
any other function's source updates `employment_records`, which is what a bypass needs. Dynamic SQL would escape it. Two functions reach this table that way: `retention_lock_rows` only locks and `retention_first_reference` only reads.

**HRC-11.** At the start of the transaction, before the writer's own rows, in
`EmploymentService`, `EmployeeService`, `EmployeeAssignmentService`,
`PlacementChangeService`, `EmploymentTermsService`, `ReportingRelationshipService`,
`SeparationCaseService::inSchool()`, `SeparationClearanceRequirementService` and
`EmployeeAccountLinkCorrectionService::request()`. A correction's decision and a
death's separation already re-read the School through `requireCurrent`.

Two callers hold something first. Onboarding has taken the Campus FOR SHARE. Separation
completion takes Identity's access lock, then the School, then its rows, because rule 141
puts that lock first. Import reports the refusal as a failed row with the code
`unavailable`, saying nothing about the School, as its 404 does, and writes nothing.

The terms, reporting and identity writers go beyond the audit's list because they
share the failure. Profile and reference-data writers do not have the check: **C11-N1,
a recorded residual**, and rule 86 says so.

The FOR SHARE on `schools` cannot upgrade: the employee-number counter has its own
row, and nothing writes `schools` inside an HR transaction. No deadlock is proven absent
beyond that reading and the two-process tests.

**GOV-6, PARTIAL.** The nine models in `Tests\Support\LifecycleModels`. Reference-data
and append-only models keep `school_id` and actor fields fillable: they have no
lifecycle, every writer sets those fields itself, and a change there is a separate
review, so the finding stays open for them.

**C10-N1.** A leave request freezes `is_paid`, `tracks_balance` and `allows_half_day`.
A name stays editable. `platform:verify-database` fails if the function loses the
check. Existing rows are unaffected: this adds a refusal to a future update and
rewrites nothing. Any request counts, including a rejected or cancelled one, which is
wider than the approved-leave defect (C11-N4, for the owner).

### What the tests prove

| Test | Kind |
|---|---|
| `no_hardened_model_accepts_a_protected_column`, `a_totally_guarded_model_outside_the_nine_still_refuses` | Proves HRC-10 and GOV-6: fails if `$fillable` or the handler regresses |
| `HrExecutionTimeSchoolCheckTest` | Proves HRC-11: with the guards removed, 26 of 27 cases fail, and the 27th is the positive control. Two of the 26 then refuse for another reason, so they prove the School comes first, not a stopped write |
| `HrExecutionTimeSchoolRaceTest`, first two | Proves the check holds across a real suspension, both commit orders |
| `LeaveTypeInUseFreezeTest`, `a_change_of_a_leave_types_rules_waiting_on_a_submission_is_refused` | Proves C10-N1: reproduced on the baseline |
| `completion_waits_on_identitys_lock_before_it_touches_the_school` | Proves rule 141's order holds with the School check |
| Field-injection tests in `HrLifecycleModelIntegrityTest`, the edit-against-end races | **Regression only.** The service strip lists already held, so these passed on the baseline too |

## 3. R1-01: writer categories (a proposal, not a definition)

The ten-point definition in `HR-R1-C10-ARCHITECTURE-INVENTORY.md` §1.8 stays
**PROPOSED**. Whether a category below satisfies the invariant is Architecture's
answer. Nothing here changes the wording of R1-01.

| Category | Proposed answer | Pinned today by |
|---|---|---|
| Canonical business writer | Satisfies | `a_table_with_several_writers...` |
| Several services, one operation each | Satisfies if named | `NAMED_WRITERS` |
| Database-owned trigger | Satisfies for its projection or history | Catalogue test, rule 145 |
| Migration role | Not a writer | Rule 54, runtime role lacks the privilege |
| Historical import | Satisfies only through the canonical services | `EmployeeImportService`, rule 122 for SIS |
| Test fixture | Excluded, local or testing only | Rule 93 |
| Local demo seeder | Excluded while guarded to local | `DemoDataBuilder` |
| Projection writer | Belongs to its source table's writer | `only_the_terms_trigger_writes_the_projection` |
| Unauthorized direct mutation | Never | Privileges, forced RLS, guards |
| Cross-domain writer | Never | `no_foreign_domain_writes_an_owned_table` |

**Result on the C11 tree:** no controller and no foreign domain writes an owned table,
and every multi-writer table has exactly its named classes:
- `employee_assignments`: `EmployeeAssignmentService`, `EmploymentService` (closing at
  an end), retention;
- `employee_placement_changes`: `PlacementChangeService`, `EmployeeAssignmentService`;
- `workforce_tasks`: `TaskAssignmentService`, `TaskGenerationService`;
- `leave_policy_assignments`: `LeavePolicyAssignmentService`, `LeaveEligibilityService`.

**What the pins do not prove.** They scan source. A write through a variable holding a
model is invisible to them: `ReportingRelationshipService` writes through a private
`insert()`, and two classes writing a locked row are listed by hand. The privilege
assertions are the runtime boundary. No blanket database writer lock was added.

**Owner questions for Architecture:**
1. Is the ten-point definition adopted, per table?
2. Is `EmployeeAssignmentService` the writer of a primary correction, or should that
   move into `PlacementChangeService`? Both are named today.
3. Does a closing write from `EmploymentService::end()` count as a second writer of
   `employee_assignments`, or as part of the end?
4. Are the `WRITE_A_LOCKED_ROW` entries acceptable as a hand-kept list?

## 4. HRC-3 by boundary

| Control | Where it holds |
|---|---|
| Capability and School | Trusted service, and RLS |
| Fresh MFA on a Highly Sensitive write | **HTTP** (`HighlySensitiveDocumentStepUp` on the register and generic routes), as rule 95 permits |
| Own record, file types, the register owning its archive | HTTP through `EmployeeDocumentPolicy`, and the HR service |
| Same-School references, owner shape | Database |
| Assurance on a direct `DocumentService` call | **Caller discipline.** It takes no proof |

The last row is not an open entry point: the only callers of the generic `create()` and
`archive()` are `DocumentController`'s routes, with no job or command.
`only_the_step_up_routes_reach_the_generic_document_writer` pins that, as a source fact,
so a new caller has to answer the question. No HIGH or MEDIUM follows from it.

## 5. Decision: should the generic route take Employee uploads? (pending)

1. **Question.** Should `POST /employees/{id}/documents` keep accepting Employee files,
   and who decides their classification?
2. **Behaviour.** It takes the same step-up and file types as the register. It has no
   category, and the tier is the caller's word, so an identity scan can be declared
   lower than Highly Sensitive. That is lawful behaviour, not a bypass, and the tier is
   never inferred from a filename.
3. **Choices.** A: keep it. B: keep it, default the tier to Highly Sensitive and let
   only a named capability lower it. C: refuse Employee uploads there, so the register
   is the one path. D: require a category and take the tier from it.
4. **Recommended.** C, because the register already does everything the generic route
   does, and more. It changes an API, so it is a recommendation only.
5. **Approvers.** Security owner and HR owner, together with D-4.
6. **Effect until decided.** None. The route keeps working as it does.

## 6. Decision register

No approval exists for any of these. No date is recorded because none exists.

| Id | Decision | Recommended | Approved | Authority | Effect in C11 | Depends on |
|---|---|---|---|---|---|---|
| D-1 | HR.4–8 scope: 82 rows, 61 gated, 12 ungated HR.4, D8-10, S-6, S-16 | B, partial | **No** | HR owner | None; classifications unchanged | OD-HR-14 |
| D-2 | Payroll coupling: 62 sites, 9 keys, 8 functions, 47 direct reads | B, narrow exception | **No** | Architecture | None; no Payroll/HR coupling changed | R1-02 |
| D-3 | Accrual monitoring | Manual, optional detection | **No** | HR owner | None; no scheduler, no monitor | LVA-1 |
| D-4 | Fresh MFA on Leave allocation | Allocation only | **No** | Security owner | None; S-7 stays partial | S-7 |
| D-6 | Calendar history | Residual, then a slice | **No** | HR owner | None; limitation stands | S-16, R1-03 |
| D-7, D-9, D-10 | As recorded in the readiness record | As recorded | **No** | As recorded | None | As recorded |

Also pending: HRC-4, HRC-8, TRR-6, TRR-8, S-6, R1-09, the closure baseline, the
reference-pack fingerprint (`40ed6a83…1ac3` is a partial value, and the owner-held pack,
the complete fingerprint, the byte convention, the inventory mapping and the approved
version are all missing), same-person identity, and the Platform per-commit publication
requirement.

## 7. Carried forward, unchanged

New in C11, LOW, in the register: C11-N1 (the profile and reference-data writers),
C11-N2 (an idempotent 404 stored in the gap) and C11-N3 (a flaky substring assertion in
`PayrollUiTest`, which failed once on a random email containing `pf`).

LOW items from C9 and C10: the future primary-placement gap, closed-placement raw
mutation, raw clearing of `ends_on`, crossed reporting-line writers (a safe abort, then a
500), correction against retention, the own-pay pre-lock read, the unexplained single
test exception, C10-N2 to C10-N10. HRC-10 and HRC-11 touch none of these directly.

R1-12: S-3 re-closed; S-4 open and latent; S-6 no determination; S-7 partial; S-16 open;
X-1 reads remain; M-1 a production prerequisite. E21-D9 stays unratified, and
`E21-RETENTION-DETERMINATION.md` was not touched. Reversal performance has no approved
target.
