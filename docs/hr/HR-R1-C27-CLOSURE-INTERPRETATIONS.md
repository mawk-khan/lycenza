# HR.R1-C27: R1-09, §1 and §4 decided; C26 enforcement qualified (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C27 records the HR owner's readings of R1-09, §1 and §4, the Architecture decision on WO-13 and C26-N1, and the answers to four evidence questions about C26. **Documentation only:** no PHP, migration, grant, function or test changed. Baselines are **not** selected; the closure contract's text is **not** amended (the readings are recorded beside it).

| | |
|---|---|
| Baseline | `ad4bb19c` = `main` = `origin/main`; `3efcfcdb` (C26 code) an ancestor; clean tree, one worktree, the historical stash and `integration/e16-r2` preserved; `main-protection` active, 0 bypass actors; main `CI` 43201374145 green |
| Last full suite | `3efcfcdb`, CI gate run 43194294858 (attempt 1 failed on C26-N2, attempt 2 green): 11,685 cases, 466,978 assertions, 0 failures, 1 skip (ESI-12). Counter 0/5 |
| DDEV `db` | 391 migrations, 255 forced-RLS tables, `platform:verify-database` failed=0 (after C26); DDEV v1.25.4, PHP 8.3.33, Laravel 13.30.0, PostgreSQL 16.14. Left running, unchanged by C27 |
| E16 | `OWNER-0O-E16-2026-11-01`, 119 records, last valid day 2026-11-28 |
| Capability map | 361 rows, recounted: IMP 163, ALT 22, PART 8, MISS 0, XDOM 7, DEF 68, GATE 87, NR 6 (unchanged) |

## 1. The literal texts (`HR-R1-CLOSURE-CONTRACT.md`)

- **§1, exact.** "HR.R1 runs only after the wider ERP programme has connected HR with each of these: Organization; SIS; Finance / Payroll; Inventory; Transport; Timetable / Academic; IAM / security; retention / audit."
- **§4, exact.** "An HR.R1 record in `docs/hr/` with a verdict of PASS, PASS WITH CORRECTION or BLOCKED. Rule for the verdict: any BLOCKER or MAJOR finding means not closed; corrections are their own published units, then HR.R1 is re-run."
- **R1-09, exact.** "**Offboarding dependencies.** Inventory custody, Library loans, Finance clearance, Timetable substitution and Transport each answer the clearance question", method "clearance integration tests".

**Authority** (`HR-R1-C12-DECISION-DOCKET.md` §1): the HR owner decides §1, §4, R1-09 and the baselines; Architecture decides closure-level definitions and verifier prerequisites; Legal and DPO gates are nobody's here. The owner answered in the C27 session on 2026-11-12, acting as HR owner (A, B, C) and as Architecture (D).

## 2. Decisions

| Id | Role | Selected | Kind |
|---|---|---|---|
| **C27-A, R1-09** | HR owner | **The C12 reading.** Each listed owner gives one recorded answer to the clearance question. NOT_INTEGRATED is a recorded answer: shown on the case, **never** CLEAR and **never blocking** (`SeparationObligation.php:12-14`, `SeparationObligations::blocking()`; only OPEN and UNAVAILABLE block completion); Schools record an administrative clearance item (R-HR12-2). Library loans, Inventory custody and Finance clearance stay NOT_INTEGRATED by the 2026-11-04 deferral and are carried as owner-deferred residuals. "Timetable substitution" is read as Timetable's answer about its entries | Interpretation for Library, Inventory, Finance and Transport (no relaxation: nothing is reported clear that is not). **Amendment** for "substitution": no substitution concept exists (D20-18, D20-31 are XDOM), so the criterion is narrowed to entries |
| **C27-B, §1** | HR owner | **The C12 reading.** §1 is met when each of the eight connections has one recorded status: INTEGRATED (with a test), DEFERRED BY OWNER, or NOT BUILT (with a named owner) | Interpretation. "Connected" was undefined; nothing is reported connected that is not |
| **C27-C, §4** | HR owner | **The C12 reading plus a severity mapping.** P1 and HIGH are BLOCKER; P2 and MEDIUM are MAJOR; P3 and LOW are recorded findings. The rule applies to development and architecture findings. Legal, staging and production items, and items explicitly DEFERRED by an owner decision (such as R-HR12-2), do not fail the verdict if each is recorded, unchanged and refused, and the verdict then names itself "PASS for development scope; production not addressed". "Permanently closed" (§1 closure states) needs a later assessment that addresses production, or a recorded narrowing | Interpretation (the mapping fills an undefined term). The dev-scope label is a scope statement, not a waiver: every gate stays shut |
| **C27-D** | Architecture | **WO-13 (C22-N1) and C26-N1 are both mandatory before the final HR.R1 assessment**, as one verifier checkpoint | Prerequisite |

**Correction during intake.** C27-A was first asked with a wrong fact (that NOT_INTEGRATED blocks completion), and C27-C with an incomplete list of open MAJOR-class items (two of seven). Both were put to the owner again with the code-verified facts and the full list; the owner confirmed C27-A and, for C27-C, chose that an owner-deferred item counts as recorded. The rows above are the confirmed decisions.

**Open items under C27-C's mapping** (P1/HIGH none open):

| Item | Class | Under C27-C |
|---|---|---|
| C14-N2 (P2) | A rendered 5xx is stored and replayed for 48 h (Platform) | **MAJOR, blocks** until fixed or re-rated with evidence |
| C14-N5 (MEDIUM) | A credential verification names no version | **MAJOR, blocks** |
| LVA-1 / TRR-2 (P2) | Docs claimed accrual heartbeats that do not exist; claims corrected in C10, the choice is D-3 | **MAJOR, blocks** until D-3 is decided and any consequence published |
| Person-level Identity (MEDIUM, docket §11) | Two accounts are not proof of two people | **MAJOR, blocks** until an Identity/Security decision |
| R-HR12-2 (MEDIUM) | Library, Inventory, Finance clearance not built | Owner-DEFERRED (2026-11-04): **recorded, does not fail** a development-scope verdict |
| GOV-8 (P2) | E16 schedule | Release item: recorded |
| GOV-9 (P2) | No staging infrastructure, restore, encryption evidence | Staging/production: recorded |
| PAY-12 (P2) | Payroll review performance untimed (staging item, D-7) | Staging: recorded |
| GOV-1 (P2) | Umbrella: "HR.R1 is not closable" | Closes when the blocking items above do |

**What these decisions are not.** No Legal, DPO, Security, Payroll, Finance, Inventory, Library, Timetable or Transport approval is given or implied. No capability status changes. Nothing is implemented. The baselines stay unselected.

## 3. Literal closure matrix after the decisions

| Requirement | Evidence today | Missing | Owner | Status |
|---|---|---|---|---|
| §1 Organization | Campus-scoped HR access (`HrEmployeeAccess`, `CurrentEmployeeAssignments`), departments, School lifecycle guard; Campus tests | — | Organization, HR | **INTEGRATED** |
| §1 SIS | No HR ↔ SIS contract is built: staff-child report (D20-29, HR-050), staff student fee details (D20-37, HR-055) are XDOM | The contract, never PII matching | SIS + HR; Fees | **NOT BUILT, named owner** |
| §1 Finance / Payroll | Payroll reads HR through the D-2 register (rule 150, `PayrollHrBoundaryRegisterTest`); Finance staff dues and the joining deposit (D1-34) deferred 2026-11-04 | Finance staff obligations | Payroll (integrated); Finance (deferred) | **INTEGRATED** (Payroll) + **DEFERRED BY OWNER** (Finance) |
| §1 Inventory | Custody deferred 2026-11-04 (D20-30); NOT_INTEGRATED in separation | Custody contract | Inventory | **DEFERRED BY OWNER** |
| §1 Transport | Driver eligibility `EmploymentCoverage::hold()`, `DutyCandidateReader`, `TransportSeparationObligations` (`DutyCandidateSelectorTest`, `SeparationObligationTruthTest`) | X-1 reads, XRP-4 date scope (P3) | Transport | **INTEGRATED** (open P3s) |
| §1 Timetable / Academic | Teacher eligibility, teaching ownership ended with employment (`EmploymentEndTeachingOwnershipTest`), `TimetableSeparationObligations`; substitution XDOM | XRP-3, XRP-4, X-1 (P3/LOW) | Timetable, TeachingAssignments | **INTEGRATED** (open P3s) |
| §1 IAM / security | Account links and history (rule 145), `CurrentStaffMembers`, `StaffAccessState`, role grant authority | C14-N5, person-level Identity (MEDIUM) | Identity, Security | **INTEGRATED** (open MEDIUMs) |
| §1 Retention / audit | Retention units for every HR table (R1-11), audit ledger | E21 legal ratification | Platform; Legal | **INTEGRATED for development** (legal gate recorded) |
| §1 overall | Every connection has one recorded status | — | HR owner | **MET under C27-B** |
| R1-09 | Transport, Timetable (entries), Leave, Tasks answer; Library, Inventory, Finance NOT_INTEGRATED, never CLEAR (`SeparationObligationTruthTest`) | The three integrations (deferred) | Each owner | **PASS for development under C27-A**, three owner-deferred residuals |
| §4 verdict rule | Defined by C27-C | Blocking MAJORs: C14-N2, C14-N5, LVA-1/TRR-2 (D-3), person-level Identity | HR owner | **Rule defined; HR.R1 would be "not closed" today** |
| §2 preconditions | HR.1-16 published or deferred (D-1, C16); fingerprint verified (C16); full regression | **Baseline SHA selection and a green suite on it** | HR owner | Not met |
| R1-01 | C26: development-verified, HR family | — | Architecture | PASS for development (HR family) |
| R1-02 | Payroll done (D-2/WO-3); 23 files in other domains (§6) | X-1 and the other readers' migration or recorded exceptions | Architecture, each domain | **PARTIAL** |
| R1-03 | WO-5: calendar and policy answer as-of; before a School's history start is NOT_RECORDED | — | HR owner | PASS for development (explicit unknown history, not reconstructed) |
| R1-04, R1-05, R1-07, R1-08 | Recorded evidence | Re-run on the selected baseline (docket §12 commands) | — | Re-run due |
| R1-06, R1-10, R1-11, R1-12 | As recorded in the docket §17 | R1-11: E21 legal; R1-12: S-4, S-6 decided (C17/C18), S-7 partial, X-1 | Legal, Security | As recorded |

## 4. C26 enforcement qualified (the four evidence questions)

**Q1. Does WO-11's rollback conflict with the fail-closed policy? No. SAFE UNDER THE APPROVED ROLLBACK CONTRACT.**
- `down()` restores the DELETE it revoked (rule 10's working inverse). The same shape is already published: C22's S-16 migration `down()` re-grants DELETE on `staff_holidays`.
- A rollback cannot run outside local and testing: `AppServiceProvider::boot()` calls `DB::prohibitDestructiveCommands()` unless both the app environment and the real `APP_ENV` are local or testing (rule 143), and `RollbackSafeMigrator` further refuses a role that cannot see every row. Nothing runs rollback automatically, and no deployment procedure calls it (a correction outside local is a forward migration or an approved restore, rule 143).
- A rolled-back schema is not silent: `runtime_destructive_privileges_restricted` and `hr_family_runtime_privileges` both FAIL on it.
- The forward state was re-read: `school_os_app` holds no DELETE on the four tables.

**Q2. Do the 63 (C25) and 56 (C26) table sets reconcile? Yes, by name.** C25 §3's 63 = the 56 family tables + Payroll's `employee_compensation_assignments`, `employee_esi_coverage`, `employee_pf_status`, `employee_statutory_identifiers`, `employee_tax_profile` (SIU each, Payroll-owned, written by Payroll's services and outside the HR family) + Identity's `staff_account_invitation_roles` (SI) and `staff_account_invitations` (SIUD: its pruning command deletes, Identity's own contract). C25's classes map one to one: S 2; INSERT-only 20 = 19 family + `staff_account_invitation_roles`; INSERT/UPDATE 36 = 31 family + 5 Payroll; with DELETE 5 = the four WO-11 tables + `staff_account_invitations`. After C26: S 2, SI 20, SIU 40, SIUD 1. These seven are the `NOT_FAMILY_TABLES` of the sweep.

**Q3. Was the final state independently reviewed? Now yes.** The C26 final-state reviewer read the candidate before its five LOW fixes. C27 commissioned a separate read-only delta review of exactly those fixes at `3efcfcdb` (verifier check, register regexes and escaping, the `assignment` removal against all 54 Payroll relations, the table-name sweep against the 304 migration-created tables, the drift strings, the documents). **No HIGH, no MEDIUM.** Findings:

| Id | Severity | Finding | Disposition |
|---|---|---|---|
| C27-N1 | LOW | A **column-level** REFERENCES grant passes `hr_family_runtime_privileges` (REFERENCES is read with `has_table_privilege`; it belongs in the `has_any_column_privilege` loop). Small effect: the runtime role cannot create tables | **OPEN.** Folded into the mandatory WO-13 verifier checkpoint (C27-D) |
| C27-N2 | LOW | Documents overstated the sweep: a model-less family table whose name does not match `FAMILY_TABLE_NAMES` is not seen, and a matching model-less table can only be given a model or listed as another domain's | **CLOSED in C27**: rule 151, ADR 0028 and the C26 record now state exactly this |
| C27-N3 | LOW/INFO | The register's `lazy` lookbehind also skips `$this->employmentRecord->...` inside a Payroll model; none exists | Recorded here; the docblock gains it with WO-13 |
| INFO | — | An older orphaned HR.R1-C1 docblock still sits on `employeeAccountLinkCheck` (pre-C26) | With WO-13 |

**Q4. Are WO-13 and C26-N1 mandatory? Yes, by C27-D** (Architecture), together with C27-N1.

**R1-01 itself, re-checked.** The ten points and R2-R7 stand as C25 adopted them, with C26's rule 76 category under point 2 (C26-A3: a controller's short inline write of a simple entity **of its own domain**; it never lets a foreign-domain controller, Payroll, or uncontrolled SQL write an HR-family table, and the controller and foreign-domain guards still refuse it). The five named multi-writer tables and `employee_esi_coverage` are pinned per table. The scheduled-job inventory (31 commands, C26 §2) covers today's schedule; a future job is caught only if it writes through a statically visible shape. Verification is **development-scope and HR-family only**.

## 5. Effective runtime privileges

Direct ACL as read above; no grant to PUBLIC on the HR family (the allowlist would fail); `school_os_app` is NOINHERIT and a member of **no** role and has no member (`pg_auth_members`: 0 rows); it owns no table (`runtime_role_not_owner_member`). The DEFINER functions it may EXECUTE are the pinned trigger functions of rule 145 and 147; retention functions are EXECUTE for `school_os_retention` only. **C26-N1 stays OPEN** until the verifier checks memberships; its requirement for WO-13: the runtime role is a member of no role and has no member, NOINHERIT, not owner; a planted `GRANT some_role TO school_os_app` must FAIL the verifier.

**WO-13 requirement (C22-N1, restated for the checkpoint):** for every guard trigger of the HR family, resolve `tgfoid` and pin the function name, SECURITY INVOKER (ordinary guards) or DEFINER (only the named rule 145/147 functions and retention), owner, `proconfig` `search_path`, and EXECUTE grants; negative proofs: a re-pointed trigger, an `ALTER FUNCTION … SECURITY DEFINER` on a guard, a changed owner, a removed `search_path`, an added EXECUTE grant each FAIL. Plus C26-N1, C27-N1 and C27-N3 above.

## 6. R1-02 remainder (23 files in other domains)

Recounted at `ad4bb19c` with C12's method (`use App\Domain\HR\Infrastructure` outside HR and Payroll): 24 = these 23 + WorkforceTasks' `TaskAssignmentService` (HR family). All are reads; none writes an HR-family table (C26 guards).

| Domain | Files | Reference | Owner | State |
|---|---|---|---|---|
| Timetable | `TimetableScheduleService`, `TimetableEntry`, `Timetable/Http/.../TimetableEntryController`, `App/Timetable/TimetableEntryController` | X-1 teacher search and validation; `teacher_id` FK | Timetable | Open (X-1, XRP-4) |
| Transport | `TransportRouteAssignmentService`, `TransportRouteAssignment`, `TransportRouteAssignmentController`, `App/TransportOperationsController` | X-1 driver; `driver_employee_id` FK | Transport | Open (X-1, XRP-4) |
| Visitor | `VisitorVisitService`, `VisitorVisit`, `VisitorVisitController` (Http and App) | X-1 host | Visitor | Open (X-1) |
| TeachingAssignments | `TeachingAssignmentService`, `ElectiveTeachingAssignmentService`, both models, both App controllers | XRP-3 `limit(1000)` lists | TeachingAssignments + HR reader | Open (XRP-3) |
| Documents | `DocumentService`, `DocumentListingService`, `Document` | the Employee owner type, through HR's `EmployeeDocumentPolicy` port (HR.R1-C10) | Documents | Approved shape, reads only |
| Attendance | `AttendanceSession` | relation to the recording Employee | Attendance | Read; to classify |
| Leave (family code under `Http/Controllers/App/Leave`) | `LeavePageSupport` | HR family | Leave | Family, not cross-domain |

Each needs its domain owner's reader migration or a recorded exception (C12 §15). HR does not change these domains.

## 7. Seven original open findings (all P3, so not MAJOR under C27-C)

| Id | Finding (`HR-R1-0-AUDIT-REGISTER.md`) | Owner | Needed |
|---|---|---|---|
| HRC-4 | Separation RETAINED is one timestamp, not bound to the grant set | HR, Identity | Bind at recording time or record a residual |
| HRC-8 | Separation reason classification on the API | HR | Require it, or show "unclassified" |
| LVA-4 | Leave allocation and runs without step-up | Security (D-4) | Decide D-4, then step up |
| XRP-3 | Teaching assignment candidate lists `limit(1000)` | TeachingAssignments + HR reader | Separate owner checkpoint |
| XRP-4 | Timetable/Transport obligations key on the Employee, no employment column | Timetable, Transport | Document, or a date-scoped answer |
| TRR-6 | Task authority not registered in the approval registry | Architecture | Register or declare exempt |
| TRR-8 | A completed case's open tasks | Tasks, HR | Accept as documented, or show it |

None is closed by C27. Under C27-C they are recorded findings, not MAJOR; each still needs its own decision or unit before the final record can call itself PASS rather than PASS WITH CORRECTION.

## 8. Gates and other findings (unchanged)

- **Legal / DPO:** E21-D9, E45/ESI-12, E47, HRX-L1 to L4, D-10, ADR 0036, ADR 0040, D-11: unchanged and shut. Under C27-C they do not fail a development-scope verdict while recorded and refused; they block production.
- **Blocking MAJOR-class items (§2):** C14-N2, C14-N5, LVA-1/TRR-2 (D-3) and the person-level Identity MEDIUM. **HR.R1 is "not closed" while any is open.** R-HR12-2 (deferred), GOV-8, GOV-9 and PAY-12 are recorded.
- **Accessibility:** C18-A12 (manual screen reader), the WO-5 manual limitation, C20-N1: open, manual review needed.
- **Other:** C22-N1 (WO-13), C22-N2, C22-N3 open; C22-N4 accepted; C24-N1 (Platform, WO-14); WO-15 needs a Payroll/HR decision; C19-N1, C19-N2, C20-N2, C14-N1, C14-N2, C10-N2 open; C26-N1 (mandatory); C26-N2 (CI flake: C26's candidate was **not** first-attempt green); C27-N1, C27-N3.
- **D-series pending:** D-3, D-4, D-7, D-9, D-10.

## 9. Five-part baseline readiness (nothing selected)

| Dimension | Source of truth | Observed now | Blocking before selection | Owner |
|---|---|---|---|---|
| Source | `main` | `ad4bb19c` (docs on `3efcfcdb`, the last full suite) | WO-13 checkpoint (C27-D); decisions on the MAJORs; then a code-lane SHA with a green full suite (§2) | HR owner |
| Database | migrations + verifier | 391 migrations, 255 forced RLS, verifier failed=0 (one operator-evidence row: TLS) | WO-13 changes the verifier; re-observe after it | HR owner |
| Reference pack | `HR-REFERENCE-CAPABILITY-MAP.md` §1, C16 | 602 PNG, fingerprint `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`, verified 2026-11-08 | Selection only | HR owner |
| Legal / security | gate register (blob `d2296a73`), E16 | Every legal gate shut; E16 through 2026-11-28 | A decision set must be dated; E16 needs a successor before 2026-11-29 for any later publication | HR owner (Legal decides its own) |
| Local environment | DDEV | v1.25.4, PHP 8.3.33, Laravel 13.30.0, PostgreSQL 16.14, synthetic data | Selection only | HR owner |

The closure regression runs on the selected SHA from a clean snapshot with `SAFE_TEST_ISOLATED=1`, plus the R1-04/05/07/08 re-runs of the docket §12; C26's suite does not substitute for it.

## 10. Roadmap to the final assessment (smallest set)

| Order | Unit | Category | Owner |
|---|---|---|---|
| 1 | **WO-13 + C26-N1 + C27-N1/N3** (one verifier checkpoint, mandatory) | B security/database hardening | Platform, Security |
| 2 | **C14-N5** (credential verification binds a version) | A/B, MAJOR | HR, Security |
| 3 | **C14-N2** (a rendered 5xx is released, not replayed; atomic completion where needed) | B, MAJOR | Platform |
| 3a | **D-3** decision, which settles LVA-1/TRR-2 (accrual monitoring) | E, MAJOR | HR owner |
| 3b | **Person-level Identity MEDIUM**: a decision (accept and record, out-of-band School assertion, or verified identity; never biometrics or inferred matching) | E, MAJOR | Identity, Security |
| 4 | Decisions or units for the seven P3 findings, and R1-02's X-1/XRP-3 readers or recorded exceptions | C/E | Each domain |
| 5 | Baseline selection (all five) | F | HR owner |
| 6 | Closure regression and the HR.R1 record | F | — |

Not required for a development-scope verdict, but for "permanently closed": staging, legal gates, the Library/Inventory/Finance integrations, manual accessibility (C18-A12, C20-N1). WO-14, WO-15, C22-N2/N3 are tracked LOWs.

## 11. Readiness

| Question | Answer |
|---|---|
| R1-09 | **Decided (C27-A); PASS for development**, three owner-deferred residuals |
| §1 | **Decided (C27-B); MET** under the reading |
| §4 | **Decided (C27-C)**; HR.R1 would be "not closed" today (four blocking MAJOR-class items) |
| R1-01 | Development-verified, HR family |
| R1-02 | **PARTIAL** |
| HR.R1 | **OPEN. Not eligible** for the final assessment: the WO-13 checkpoint, C14-N2, C14-N5, D-3 (LVA-1/TRR-2), the Identity MEDIUM, baseline selection and the closure regression remain |
| Local synthetic UAT | Eligible for implemented, governed functionality |
| Staging | Paused (owner, 2026-10-17); AWS not authenticated |
| Production | **NO-GO** |

**Next.** The WO-13 verifier checkpoint (with C26-N1, C27-N1, C27-N3): mandatory by C27-D, small, and it unblocks the source and database baselines. Then C14-N5. Nothing starts without the owner's go-ahead.
