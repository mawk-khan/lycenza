# HR.R1-C35: the seven original P3 findings and the C34 residuals. Evidence and owner decisions (2026-11-13)

**Read-only audit and documentation. No code, schema or data changed.** HR.R1 stays OPEN. Every decision below was given by the owner on 2026-11-13 in the C35 decision dialog; nothing is recorded that was not given.

| | |
|---|---|
| Baseline | `463777ce` = `main` = `origin/main`; clean tree; the historical stash and `integration/e16-r2` preserved |
| Last runtime | C34's code change is commit `6fddaf74`; it was published as `6b881295` (that commit plus a documentation whitespace fix), whose exact-SHA full suite is the last regression: 11,816 tests, 467,445 assertions, 0 failures, 1 ESI-12 skip; counter 0/5 |
| Authorized | The owner's dispatch of the C35 brief, 2026-11-13: decision-first, read-only, documentation. The decisions authorize the work orders in §7 to be specified, not started |
| Method | Each finding read verbatim from `HR-R1-0-AUDIT-REGISTER.md` (summary row and "Audit's text" row), traced through C10 to C34, and checked against code at `463777ce`. One throwaway test (C34-N1, §4), never committed. An independent read-only review of this package (§6) |
| Not changed | Code, schema, the development database, capabilities, the capability map's statuses, the 61 audit identifiers, every gate |

## 1. The verdict contract (C27-C, literal)

`HR-R1-C27-CLOSURE-INTERPRETATIONS.md` §2, C27-C: "P1 and HIGH are BLOCKER; P2 and MEDIUM are MAJOR; P3 and LOW are recorded findings. [...] Legal, staging and production items, and items explicitly DEFERRED by an owner decision [...] do not fail the verdict if each is recorded, unchanged and refused, and the verdict then names itself 'PASS for development scope; production not addressed'." §4: "any BLOCKER or MAJOR finding means not closed". C27 §7: the seven P3s "each still needs its own decision or unit before the final record can call itself PASS rather than PASS WITH CORRECTION."

So an open MAJOR means NOT CLOSED. With none, a P3 without a decision or unit allows at best PASS WITH CORRECTION. The owner decided (§9, D-C35-10) that every other open P3 and LOW is dispositioned individually as well, so a final unqualified PASS is possible.

**Correction found in C35.** C27 §7 summarizes HRC-8 as "Separation reason classification on the API". The audit's text concerns the **staff type** (§2). The register row is authoritative.

## 2. The seven findings: verbatim, traced, current

Carried open, unchanged, by C23 §12, C24 §12, C25, C26 §8 and C34 §7. No later checkpoint closed or changed any of them.

| Id | Audit text (register, verbatim excerpt) | Code at `463777ce` | State |
|---|---|---|---|
| **HRC-4** | "RETAINED is a one-off boolean, not bound to the grant set. A role granted after access was recorded as kept stays non-blocking at completion." Recommendation: "Bind it to the grant count or set at recording time, or record it as a residual." | Unchanged. `SeparationObligations::schoolAccess()` (`SeparationObligations.php:119-138`) returns RETAINED whenever `access_retained_at` is set and any grant exists; the grants are read live and never compared with what was kept | Unimplemented |
| **HRC-8** | "Unclassified staff type and clearance. `staff_type` NULL is still creatable through the API, import and rehire, not only on legacy rows. Clearance requirements scoped to a staff type silently skip those employments." Recommendation: "Show 'unclassified' on the case, or require staff type on the API." | Unchanged. `EmploymentController.php:43`, `EmployeeLifecycleController.php:78`: `sometimes, nullable`. `SeparationCaseService::snapshotClearance()` (`:447`) takes requirements whose `staff_type` is NULL or equal, so a staff-type-scoped requirement skips an unclassified employment. Nothing in the separation code or pages says "unclassified" | Unimplemented |
| **LVA-4** | "Gate register S-7 still reads 'open / HR.10'. Adjustments and openings now need fresh MFA. Allocations (single and run) still have no fresh MFA. Every ledger and accrual write uses the cached `authorizeCapabilityFor` [...], never `requireCurrent`." Recommendation: "Mark S-7 PARTIAL in the register. Owner decision on fresh MFA for allocation." | Register half done (S-7: "Adjustments and openings FIXED (HR.10). Allocation, runs and the cached check open: D-4"). Code unchanged: `LeaveLedgerController::allocate()` (`:62`) and `executeRun()` (`:96`) take no fresh MFA. The writing entry points use the cached check: `LeaveLedgerService:63` (allocate), `:114`, `:176`, `:218`, `LeaveAccrualService:113`, `:131`, `:176` (`:103` and `:77` are reads). D-4 was undecided until §9 | Partially implemented |
| **XRP-3** | "the Teaching Assignment pickers still list by identity flag, capped at 1000 [...] separated and draft Employees are offered, and staff beyond 1000 are unreachable. The save still refuses them [...] Not a security defect." | Unchanged since before the audit (the `orderBy('full_name')` at `TeachingAssignmentController.php:163-164` and `ElectiveTeachingAssignmentController.php:71-72` dates from TCH-E `e0bc45d1`; the audit did not quote it). Still `record_status = 'active'` and `limit(1000)`; the save-time `hold()` still refuses an uncovered employment. A similar cap: C10-N3 (`LeavePageSupport.php:49`, employment records by status, no order) | Unimplemented (other domain) |
| **XRP-4** | "Timetable and Transport obligations are keyed by Employee, not employment [...] If someone is rehired before their old case is completed, duties under the new employment block, or mislabel, the old case. [...] This is an edge case." | Unchanged. `TimetableSeparationObligations.php:27` (`teacher_id`), `TransportSeparationObligations.php:26` (`driver_employee_id`); neither table has an employment column | Unimplemented (schema limit, other domains) |
| **TRR-6** | "task review (submitted → completed/returned) is a request/decide step outside `ApprovalWorkflowRegistry`. It reads authority through cached `CapabilityResolver::can()` [...] The registry's regex does not detect `completed_by_user_id`." Recommendation: "register it or declare it exempt with a reason." | Unchanged. `TaskAuthority::holds()` (`TaskAuthority.php:36-39`) uses `can()`; no Tasks entry in `WORKFLOWS` or `NOT_WORKFLOWS`; `DECISION_COLUMNS` (`ApprovalWorkflowRegistryTest.php:24`) has no `completed_by`. "Never one's own task" is enforced in the service and by `trg_workforce_tasks_guard` (rule 139) | Unimplemented |
| **TRR-8** | "open tasks of a separated employment cannot be submitted and are never auto-closed. After completion they stay overdue in the reports indefinitely." Recommendation: "accept as documented (a person must reassign or cancel), or show it on the completed case." | Unchanged and documented. `TaskSeparationObligations` shows them as ATTENTION on the case: "nothing is moved automatically" (rule 139, HR.13) | Documented behaviour |

No finding has new evidence that raises its severity.

## 3. Dispositions (owner decisions, §9)

| Id | Disposition | Selected | Work |
|---|---|---|---|
| HRC-4 | **IMPLEMENTATION REQUIRED (approved)** | Bind RETAINED to the grant SET (the role-assignment ids) at recording; a grant outside that set returns the obligation to ATTENTION/OPEN. A revoke-then-grant keeps the count but not the set, so the set is what is bound | WO-20 |
| HRC-8 | **IMPLEMENTATION REQUIRED (approved)** | The case says the employment is unclassified and names the staff-type requirements that were not applied. No API change. (The review noted a stricter fail-closed alternative, applying every staff-type requirement to an unclassified employment; it was not chosen) | WO-21 |
| LVA-4 | **IMPLEMENTATION REQUIRED (approved; D-4 decided)** | Fresh MFA on allocation and allocation runs, and a fresh authority read (`requireCurrent`) on every Leave ledger and accrual write entry point (allocate, allocation run, adjust, opening, accrual run start and continue). Stricter than the docket's recommendation ("allocation only, with `requireCurrent`"); consumption and reversal written inside a leave decision keep that decision's authority | WO-19 |
| XRP-3 | **APPROVED DEFERRED** | To a TeachingAssignments checkpoint: a future-dated employment reader and a search picker. Trigger: a School above 1,000 staff, or staging. Does not fail the development verdict | None in HR.R1 |
| XRP-4 | **APPROVED ALTERNATIVE (documented known limit)** | The rehire-before-completion case is a known limit: duties under a new employment can block or mislabel the old case, never clear it. Trigger: rehire volume, or an employment column on either table | None |
| TRR-6 | **IMPLEMENTATION REQUIRED (approved)** | Register task review as a domain-owned workflow (`makerChecker: domain`, backstop `trg_workforce_tasks_guard`), with a fresh authority read under the task lock and the known-same-person refusal rule 152 requires of an HR-family workflow | WO-22 |
| TRR-8 | **APPROVED ALTERNATIVE (accepted as documented)** | Shown on the case, never moved automatically; a person reassigns or cancels. Trigger: report users misreading overdue tasks of a separated employment | None |

## 4. C34 residuals

**C34-N1, verbatim** (`HR-R1-C34-WO16-KNOWN-SAME-PERSON.md` §6): "Beneficiary through a second account (C34-N1, LOW). C33 scoped the rule to participants. A Payroll decider known to be the same person as an account linked to an Employee the run pays first is caught only if the decider's own account is linked to that Employee (rule 145's self-beneficiary rule, unchanged). The remaining gap needs two Employee records for one person, one of them shared by both accounts. Not extended without an owner decision."

**Reproduced at `463777ce`** (throwaway test in the C34 Payroll fixture `BuildsPayrollIndependenceScenarios`, deleted):
1. Create accounts A and D, each holding approve, run view and sensitive view.
2. Create a duplicate Employee E′. Link A to E′, unlink, then link A to the paid Employee E.
3. Link D to E′, so A and D are known to be one person (`knownSamePerson` true).
4. X, an independent author, raises E from 2015-08-01. Calculate August.
5. Result: **A is refused** (`SelfBeneficiaryConflictException`); **D approves.**

**Against which contract.**
- OD-HR-24 (gate register) states its problem as "a person whose own pay is implicated could approve or attest". Its definition (row 11, amended by OD-HR-25 to the link history) counts only the decider's own links.
- The literal definition is met; the purpose is defeated by an account the system already knows is the same person.
- C33 already aliases the subject side for Leave and separation ("any account ever linked (uncorrected) to the requester's Employee"), so Payroll's beneficiary rule is the inconsistent one. `HrSelfAdministrationGuard::isKnownToBeEmployee()` already implements the relation.
- Maker/checker is not defeated: the raise's author is someone else, and an alias of the author is refused by WO-16.
- The precondition (two Employee records for one person, linked by a third party: rule 95 refuses self-linking) is why it is not HIGH.

**Owner decision (D-C35-1): MEDIUM, so MAJOR under C27-C; fix with WO-17.** HR.R1 is NOT CLOSED until WO-17 is published.

**C34-N2 (INFO, unchanged).** Two-session tests cover Payroll approval and Leave. Attestation and review take the compensation lock the approval race proves; a separation decision holds the subject's Employee row FOR SHARE; a voiding correction takes the compensation lock in its guard and only removes evidence. No race is claimed. Proposed cases, to be built with WO-17/WO-18: attestation and review against a link, a separation decision against a relink, an approval against a voiding correction.

**C34-N3 (INFO), exact scope.**
- Separation record, cancel, finalize, access-retained, clearance clearing and complete use `lockEmployment()` → `refuseOwnEmployee()` (current link) and the database's `v_subject` (current link).
- Leave administrative withdraw and cancel use `refuseOwnEmployment()` → `ownsEmployment()` (current link).
- Rule 145 keeps `ownsEmployment()` "the current-link answer for Leave, Tasks and HR" (OD-HR-25), so extending it changes a decided contract.
- An ended-link or known-same account of the separating person can cancel their own approved separation, undoing the decision WO-16 protects.

**Owner decision (D-C35-9):** extend to all listed. This amends OD-HR-25 for HR administration through WO-18. It stays INFO until built: not MAJOR, and the decision does not re-rate it.

**New from the C35 final-delta review of C34's post-review fixes (LOW):**
- **C34-N4:** `known_same_person_guards` pins the three Payroll guard functions' bodies and bindings, but not their triggers' timing and events (`pg_get_triggerdef`). The four HR-family triggers are pinned by `HR_FAMILY_TRIGGERS`. A Payroll guard recreated AFTER, or on other events, with the same function would pass. Fixed with WO-17.
- **C34-N5:** `SeparationKnownSamePersonTest`'s corrected-link case shows no refusal before the correction and exercises `reject` only. Strengthened with WO-18.
- **INFO:** the approval packet's bound of 50 measures the no-alias path only (the alias path is constant but unmeasured), and the posting page reads the aliases up to three times (correct, wasteful). Recorded.

**Actor trust boundary.** Each protected service records the authenticated request's user (`$request->user()`); the database guards judge the recorded decider column. One runtime role serves every domain, so the database cannot authenticate the human, and a raw writer naming another decider is judged as that decider (rule 102). Nothing here claims more.

**Review coverage.** C34's independent review preceded its LOW fixes. C35's independent review read those fixes as a final delta and found C34-N4 and C34-N5 (above). No other defect was found.

## 5. MAJOR inventory and other open findings

| Item | Class | Status |
|---|---|---|
| P1/HIGH | — | None open |
| C14-N2, C14-N5, LVA-1/TRR-2 (D-3), person-level Identity | P2/MEDIUM | Closed for development (C30, C29, C32, C34) |
| **C34-N1** | **MEDIUM (owner, D-C35-1)** | **Open MAJOR**, WO-17. **Built by WO-17** (`HR-R1-C35-WO17-KNOWN-SAME-BENEFICIARY.md`): closed for development on its publication |

**Other open findings.** Each is carried individually and dispositioned before baseline selection (D-C35-10):

| Finding | Class |
|---|---|
| C10-N2, C10-N3, C10-N7, C10-N8, C10-N9, C14-N1 | P3 |
| C10-N10, C19-N1, C19-N2 | LOW |
| C26-N2 | observation |
| C34-N2, N3 | INFO |
| C34-N4, N5 | LOW |
| C32-N1 to N4 | INFO |
| C30-N1/N2, C29-N1/N2, C28-N1/N3/N4, C24-N1, C20-N1/N2 | as recorded |
| C18-A12 | acceptance item |

C28-N1, C28-N3 and C28-N4 are LOW Platform items.

## 6. Independent review of this package

A separate reviewer read this package, the register, the decision records and the code. **No HIGH.**
- **Three MEDIUM, all acted on before the decisions:**
  - TRR-6's first recommendation (declare exempt) misclassified a second-person decision. The option set was corrected and the owner chose registration.
  - LVA-4's options did not match the docket's. The docket's recommendation ("allocation only") is now recorded alongside the owner's stricter choice, and the writing entry points are named precisely.
  - The open-findings inventory was incomplete. It is now complete in §5, and the owner decided D-C35-10.
- **LOWs, corrected here:**
  - XRP-3's "partly changed" (the ordering predates the audit);
  - the C23, C24 and C26 section citations;
  - C10-N3's line and shape;
  - HRC-4 binds the set, not the count;
  - C34-N3's cancel case strengthened;
  - HRC-8's fail-closed alternative recorded;
  - C34-N1's stronger evidence and the reproduction steps.
- **Final delta of C34:** C34-N4, C34-N5, two INFO.
- The reviewer confirmed: the verbatim quotes, the HRC-8 correction, that no later checkpoint closed any of the seven, C27-C's literal application, the D-series and gate statuses, and that no recommended option weakens history, financial integrity or School isolation.
- A reviewer grants no approval: the decisions in §9 are the owner's.

## 7. Work orders (specified, none started)

Each needs its own authorization to start, and each follows the standard path:
- service and database tests, allow and deny;
- architecture and register updates;
- the rollback proof for a migration;
- an independent review;
- the exact-SHA full suite;
- protected-main publication.

| WO | Closes | Scope |
|---|---|---|
| **WO-17** | C34-N1 (MAJOR), C34-N4 | Payroll approval, attestation and review refuse a decider for whom `isKnownToBeEmployee()` holds against any Employee whose change the run (or obligation) pays first. Service (`PayrollSelfBeneficiaries`) and database (`payroll_runs_self_beneficiary_guard`, `payroll_posting_attestations_self_beneficiary_guard`, the review guard's subject check) through `employee_account_link_same_person_accounts()`. The same closed reason, naming nothing. Pin the three Payroll guard triggers' definitions. Reproduction first, as C34 did. Two-session tests: attestation and review against a link |
| **WO-18** | C34-N3, C34-N5 | Amend OD-HR-25 for HR administration: "never one's own" also refuses an uncorrected (ended) link and a known-same account, for every separation write and administrative leave withdraw/cancel. Service and the separation guard's subject check. Strengthen the corrected-link test (refused before, allowed after, approve and reject). A separation-against-relink race |
| **WO-19** | LVA-4 (D-4), S-7 | Fresh MFA on allocation and allocation runs (web, API, OpenAPI); `requireCurrent` on every Leave ledger and accrual write entry point. Needs an enrolled factor. S-7 closes on publication |
| **WO-20** | HRC-4 | Record the grant set when access is recorded as kept; RETAINED only while the live grants are within it |
| **WO-21** | HRC-8 | Show "unclassified" and the skipped staff-type requirements on the case (page and API) |
| **WO-22** | TRR-6 | Register task review (domain workflow, `trg_workforce_tasks_guard`); fresh authority read under the task lock; known-same-person refusal (rule 152), service and database; the registry test detects `completed_by_user_id` |

## 8. Readiness, gates and baselines (recomputed)

**Recomputed at `463777ce`:**
- 394 migrations, all applied on the test database. The development database has 393: it lacks C34's `2027_02_19_090000`, not applied here (needs authorization).
- 256 tables with forced RLS (test database).
- Capability map: 361 rows (IMP 163, GATE 87, DEF 68, ALT 22, PART 8, XDOM 7, NR 6). No status changed.
- Audit register: 61 identifiers.

| Area | Status |
|---|---|
| R1-01 | Development-verified for the HR family (C26, C28); the shared runtime role limit stands |
| R1-02 | PARTIAL: Payroll D-2 readers built (C24); 24 other-domain files still import `HR\Infrastructure` (X-1, XRP-3) |
| R1-09 | C27-A: NOT_INTEGRATED visible, never CLEAR, does not block completion; Library, Inventory and Finance deferred |
| §1 | C27-B readings adopted |
| D-1 | Decided (C16); its four ACTIVE rows built by WO-5 and WO-6 |
| D-2, D-3, D-6 | Decided and built |
| D-4 | **Decided (D-C35-3)**; WO-19 not started |
| D-7, D-9, D-10 | Pending in the C12 docket: D-7 performance targets (Product); D-9 "employments examined" against ADR 0040 (Product); D-10 extend E47 (Legal, not sent) |
| RG-ID-1 | Open release gate |
| C33-H1 to H5 | Handoffs, unchanged |
| Accessibility | C18-A12 (screen reader, Staff Attendance), C20-N1, C32-N1 (no browser or axe run of the accrual workspace; the demo School has no Leave configuration, and a synthetic School is the route). Operational evidence, not development findings under C27-C; needed before UAT sign-off of those screens |
| Legal/DPO | E21-D9, E45/ESI-12, E47, HRX-L1 to L4, S-4, X-1, D-10, ADR 0036, ADR 0040, D-11: unchanged and shut |

**Five closure baselines: all unselected.**

| Dimension | Candidate | Before selection |
|---|---|---|
| Source | `main` after the last development unit | WO-17 published (no open MAJOR); WO-18 to WO-22 published; the other open findings dispositioned (D-C35-10) |
| Database | 394+ migrations; 256 forced RLS; `platform:verify-database` failed=0 on the test database | Same SHA; the development database brought to the same migration (needs authorization) |
| Reference pack | C16 fingerprint `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3` | Unchanged; not reconstructed |
| Legal/Security | The gate register at the selected SHA | Every gate recorded, unchanged and refused |
| Local environment | DDEV (PHP 8.3, PostgreSQL 16), isolated test database | Accessibility evidence (C32-N1, C18-A12) if the owner makes it a precondition |

**Closure regression.** C34's suite is WO-16 evidence, not the closure regression. The closure regression runs on the selected source SHA after selection.

## 9. Owner decisions (2026-11-13, the owner in the C35 decision dialog)

Engineering and product direction only; not legal, privacy or DPO approval.

| Id | Finding | Selected | Role | Development effect | Staging / production | Outstanding | Re-review |
|---|---|---|---|---|---|---|---|
| D-C35-1 | C34-N1 | MEDIUM (MAJOR); fix with WO-17 | Payroll, Identity, Security owner | NOT CLOSED until WO-17 publishes | Real Payroll waits as before | WO-17 | Any change to OD-HR-24/25 |
| D-C35-2 | HRC-4 | Bind to the grant set at recording | HR, Identity owner | P3 closes on WO-20 | None | WO-20 | Off-boarding or certification changes |
| D-C35-3 | LVA-4 (D-4) | Fresh MFA on allocation and runs; fresh authority read on every ledger and accrual write entry point | Security owner | P3 closes on WO-19; S-7 closes | Enrolled factor needed | WO-19 | Leave gaining a money effect (HRX-L4) |
| D-C35-4 | HRC-8 | Show "unclassified" on the case | HR owner | P3 closes on WO-21 | None | WO-21 | A staff-type requirement made mandatory |
| D-C35-5 | TRR-6 | Register task review; fresh read; known-same-person (rule 152) | Architecture owner | P3 closes on WO-22 | None | WO-22 | Task review gaining a consequence (OD-HR-7) |
| D-C35-6 | TRR-8 | Accept as documented | HR, Tasks owner | P3 closed (accepted) | None | None | Reports misread |
| D-C35-7 | XRP-3 | Defer to a TeachingAssignments checkpoint | TeachingAssignments owner | Recorded deferral, not failing | Before staging or a School above 1,000 staff | That checkpoint | Trigger as stated |
| D-C35-8 | XRP-4 | Document as a known limit | Timetable, Transport owners | P3 closed (accepted alternative) | None | None | Rehire volume; an employment column |
| D-C35-9 | C34-N3 | Extend "never one's own" person-level to every separation write and administrative leave withdraw/cancel (amends OD-HR-25 for HR) | HR, Identity, Security owner | Finding stays INFO until WO-18 | None | WO-18 | Any change to OD-HR-25 |
| D-WO18-1 | WO17-N1 | MEDIUM (MAJOR); Option A, the post-decision independence functions treat a known-same account of the paid Employee like the Employee's own | Payroll, Identity, Security owner | NOT CLOSED until that unit publishes | Real Payroll waits as before | A separate unit after WO-18 | Any change to OD-HR-24/25 |
| D-WO18-2 | Development database | Forward-migrate it through `pgsql_admin` after WO-18 publishes, then verify | Owner | None | None | Done in WO-18 §8 | — |
| D-C35-10 | Other open P3/LOW findings | Disposition each individually before baseline selection | HR owner | Final PASS remains possible | None | Their dispositions | — |

## 10. Verdict and next checkpoint

**BLOCKED — NEW MATERIAL SECURITY FINDING.** C34-N1 is demonstrated and owner-rated MEDIUM, so it is MAJOR under C27-C.
- All seven P3 findings have authorized individual dispositions:
  - four approved for implementation (HRC-4, HRC-8, LVA-4, TRR-6);
  - one deferred (XRP-3);
  - two accepted alternatives (XRP-4, TRR-8).
- HR.R1 is NOT CLOSED and not ready for baseline selection.

**Next: WO-17 (C34-N1, with C34-N4)**: the one open MAJOR, the smallest unit and the highest risk. Then WO-18 to WO-22 and the D-C35-10 dispositions, then HR.R1-C36 (baseline selection). None is started; each needs authorization.
