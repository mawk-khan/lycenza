# HR.R1-C35-RF2: residual-finding decisions, the WO-23 and WO-24 contracts, and closure readiness (2026-11-20)

**A decision record. It changes no runtime code, migration, test, development database or demo data.** Every decision in §2 was given by the owner on 2026-11-20 in the RF2 decision dialog, acting as the accountable owner named in each row. Nothing is recorded as decided that was not given there. A reviewer's finding (§13) is a recommendation, never an approval. HR.R1 stays **OPEN**; the five closure baselines stay **unselected**.

| | |
|---|---|
| Authority | The owner's dispatch of the RF2 brief on 2026-11-20: decision-first, documentation only. The decisions approve the work orders in §4 to §6 as contracts; **none is started** and each needs its own start authorization |
| Source record | `HR-R1-C35-RF1-RESIDUAL-FINDINGS.md` (RF1, `706ec9c3`). Its 81 open rows are the scope (D-C35-10, `HR-R1-C35-P3-DISPOSITIONS.md` §9) |
| Severity rule | C27-C (`HR-R1-C27-CLOSURE-INTERPRETATIONS.md:27`): "P1 and HIGH are BLOCKER; P2 and MEDIUM are MAJOR; P3 and LOW are recorded findings." Legal, staging, production and owner-DEFERRED items "do not fail the verdict if each is recorded, unchanged and refused" |
| Not changed | Code, schema, the DDEV database (its ledger was read, not written), capabilities, the capability map's statuses, the 61 audit identifiers, every Legal, DPO and Security gate |

## 1. Baseline and source integrity (brief Part A)

| Check | Result |
|---|---|
| `origin/main` after `git fetch` | `706ec9c3` (RF1), equal to local `main`. No publication since 2026-11-14 from any workstream |
| Runtime ancestor | `5b78154c` (WO-22) is an ancestor of `706ec9c3`; `00f194af` and `706ec9c3` change documentation only (`git diff --stat 00f194af 706ec9c3`: 3 files, docs) |
| Working tree, worktrees | Clean; one worktree (`main`) |
| Preserved | `integration/e16-r2` (remote) and the historical stash (`stash@{0}`, 2026-08-27) untouched |
| Protected `main` | Ruleset 24909660 `main-protection`: `active`, rules `deletion`, `non_fast_forward`, `required_linear_history`, `required_status_checks`; `bypass_actors` empty (GitHub API, 2026-11-20) |
| E16 | `infrastructure/release/vulnerability-exceptions.json`: one approval (`OWNER-0O-E16-2026-11-01`), 119 exceptions, all `expires` 2026-11-29: valid through 2026-11-28. **A successor is needed before any publication on or after 2026-11-29** (GOV-8) |
| CI on `706ec9c3` | Candidate run 43595475528 (`integration/hr-r1-c35-rf1`, attempt 1, success), `main` run 43595716763 (attempt 1, success); scheduled Protection drift and SBOM re-scan green daily through 2026-11-19 |
| Last canonical inventory | WO-22 candidate run 43581045054 on `5b78154c`: 11,943 tests, 466,988 assertions, 0 failures, 1 skip (ESI-12); attempt 2 after WO22-N5. Regression counter 0/5 (RF1 and RF2 are documentation only) |
| DDEV ledger (read only) | `db`: 398 rows, last batch 9, last `2027_02_23_090000_bind_separation_retained_access_to_grant_set`; repository 400 files. Pending exactly `2027_02_24_090000_record_separation_clearance_staff_type_basis` and `2027_02_25_090000_refuse_known_same_person_task_review`. Data: 10 Schools, 0 separation cases, 0 tasks, 0 task events, 1 account link, 2 payroll runs, 0 leave ledger rows; one Staff Attendance month lock (2026-10-01, locked 2026-11-08). `school_os_app` and `school_os_retention` neither superuser nor BYPASSRLS. DDEV had been stopped; the read-only `ddev exec` started it, and nothing else was done to it |
| Gate register | `HR-LEGAL-SECURITY-GATE-REGISTER.md`, last changed `3786968e`. RG-ID-1 decided as a release gate (:43), unchanged |

**Inventory recomputed mechanically** (`grep -ohE` over `docs/hr`, `docs/architecture/adr`, `docs/modules`, `CLAUDE.md`, at both `00f194af` and `706ec9c3`):
- 104 identifiers: 75 `C\d+-[NAE]\d+` and 29 `WO\d+-[NE]\d+`.
- 61 original audit identifiers in `HR-R1-0-AUDIT-REGISTER.md` "Every finding" (:178-238).
- RF1's four tables, parsed row by row: §4.1 28 open, §4.2 32, §4.3 12, §4.4 9, so **81**. These match RF1 §4.6. No finding is counted twice: the R-HR13-1..3 row is one decided row, outside the 81.
- Capability map (`HR-REFERENCE-CAPABILITY-MAP.md` §4) has **361** rows: IMP 163, GATE 87, DEF 68, ALT 22, PART 8, XDOM 7, NR 6, MISS 0. A naive count also picks up the 8 rows of the status-code legend, which are excluded.

## 2. Owner decisions (2026-11-20, the owner in the RF2 decision dialog)

| Id | Finding(s) | Decision | Accountable owner | Dev effect | Staging / production | Work | Re-review trigger |
|---|---|---|---|---|---|---|---|
| **D-RF2-1** | WO22-N1, RF1-N1 | **MEDIUM, so MAJOR** under C27-C, consistent with ADR 0076 §64.12; correct with WO-23. RF1-N1 is the `submitted` half of the same invariant | Architecture, HR | **NOT CLOSED until WO-23 publishes** | None beyond development | WO-23 (§4) | Any new task status or event kind |
| D-RF2-1a | WO-23 design | **Version-bound evidence with a deferred check**, for `submitted` and `returned` (§4.3). Not every transition; not actor columns | Architecture | — | — | WO-23 | A transition gaining a consequence (OD-HR-7) |
| **D-RF2-2** | WO20-N1, RF1-N2 (with WO20-N7, WO18-N1) | **MEDIUM, so MAJOR**: a supported path defeats R-HR12-3 (reproduced, §5.2). Correct with **WO-24, after WO-23**, its own unit. R-HR12-3 is not amended | HR, Identity | **NOT CLOSED until WO-24 publishes** | None beyond development | WO-24 (§5) | Any change to R-HR12-3, rule 92 off-boarding or rule 145 corrections |
| **D-RF2-3** | Final closure regression | **Clean complete run** (§8) | HR owner (closure), Platform (CI) | Applies to the HR.R1 baseline SHA only | — | C36 | Any change to ADR 0072 §31 sharding |
| D-RF2-4 | **D-7** (LVA-7, PAY-12, R-HR16-1, C20-N2, C32-N2) | **Defer performance targets to staging**: a target is set from a declared School size there and measured there | Product | Recorded staging GATE; not failing (C27-C) | Targets and measurements before staging sign-off | None now | Staging start, or a School declared above 1,000 staff |
| D-RF2-5 | **D-9** (TRR-5) | **Accept "Employments examined" as operational reconciliation** under ADR 0040's operational exemption. It counts examinations across passes, never headcount; no build | Product, ADR 0040 owner | P3 closed (accepted) | None | None | Anyone reading it as headcount, or it reaching an export or report beyond the run page |
| D-RF2-6 | **D-10** (GOV-7) | **Extend the E47/SR-L1 draft later** (documentation only) with `line_manager`, `team_task_manager`, `payroll_approval_reviewer` and their leave and task data. **Never sent** by this decision | Legal | Recorded Legal gate, not failing | E47 stays shut | A later docs unit | E47 taken up for sending |
| D-RF2-7 | WO22-N5, C26-N2 | **Authorize WO-25** (test-only root cause, §6). It is **required before the final closure regression**, not before WO-23 | Platform | LOW, recorded | — | WO-25 | Any recurrence: reported as WO22-N5, never silently rerun |
| D-RF2-8 | C11-N4 | **Preserve the broad freeze** (C12 docket recommendation): any leave request, including rejected and cancelled, puts a leave type in use | HR | Accepted alternative | None | None | A request to change a type's classification after use |
| D-RF2-9 | C10-N2, HRC-7 | **Accept both as correction paths.** Each step authorizes `hr.employees.assignments.manage` and audits (`EmployeeAssignmentService.php:93/163`, `:183/210`, `:256/376`) under the overlap and primary triggers | HR | Accepted alternative | None | None | Reports depending on placement-change kinds |
| D-RF2-10 | C14-N1 | **Route to a Payroll unit (WO-10) before staging**: the capability check moves before the lookup | Security, Architecture, Payroll | Handoff, not failing | Before staging | WO-10 (Payroll) | — |
| D-RF2-11 | R-HR10-1 | **Accept, flagged**: a closed year is final; the credit stays visible as drift and is handled manually | Leave | PARTIAL accepted | None while Leave has no money effect | None | HRX-L4 (a Leave money effect), or a closed-year correction request |
| D-RF2-12 | C24 R-2 (WO-15) | **Defer to a Payroll/HR integrity unit before any real Payroll run or staging**, whichever is first | Payroll, HR | Recorded deferral, not failing | Required before real Payroll or staging | WO-15 | An application writer that changes an employment's Employee |
| D-RF2-13 | WO17-N2 | **Accept for development; pin before staging**: the full trigger set of the three Payroll guard tables, and staging drift verification must detect privileged DDL | Platform, Payroll | Accepted (dev) | Before staging | Platform/Payroll verifier unit | A new Payroll guard table |
| D-RF2-14 | WO21-N1 | **Accept, fails closed** | HR | Accepted | None | None | Observed outside tests |
| D-RF2-15 | GOV-6 | **Accept PARTIAL, routed**: the 18 decision-gated models follow their gate decisions; the 6 Payroll models go to the Payroll hardening unit before staging; `HrModelMassAssignmentCatalogueTest` keeps every model dispositioned | HR, Payroll | Accepted PARTIAL | Payroll models before staging | Payroll hardening | A new model in the family |
| D-RF2-16 | Group A1 (14 rows, §3.1) | **Accept each as listed**, with its own reason and trigger | HR, Identity, Architecture | Closed (accepted) | None | None | Per row |
| D-RF2-17 | Group A2 (14 rows, §3.2 to §3.4) | **Accept each as listed** | Per row | Closed (accepted) | None | None | Per row |
| D-RF2-18 | WO19-N1 | **Accept within D-4's scope**: fresh ownership (the locked ActingEmployee) plus the cached self-service right satisfies the approved writer scope; D-4 is not extended to self-service | HR, Security | Accepted | None | None | HRX-L4 |
| D-RF2-19 | WO20-N6, WO19-N5 | WO20-N6 **VERIFIED CLOSED** (not reachable, §7.2). WO19-N5 accepted for development; the Access Center wording is **corrected before UAT sign-off** in the next Identity UI or docs unit | HR, Identity | N6 closed; N5 accepted | N5 before UAT sign-off | Identity UI/docs | — |
| D-RF2-20 | Group B (11 rows) | **Routing acknowledged**: each stays open with its receiving owner until that owner decides. No global idempotency refactor is authorized | Receiving owners | Handoff, not failing | As stated per row | Their owners | Per row |
| D-RF2-21 | Group C (8 rows) | **Acknowledged as gates or deferrals**: recorded, unchanged, refused | Security, Legal, Platform, owner policy | Not failing (C27-C) | Each gate stays shut | None | Per row |
| D-RF2-22 | DDEV migrations | **Approved, to run after WO-24 publishes**: one operator step brings DDEV to the full ledger, naming every migration then pending, and re-verifies (§9). RF2 applies nothing | HR owner (operator) | — | — | Operator step | DDEV state changing before then |
| D-RF2-23 | Group D (7 rows), synthetic UAT | Operational evidence is a **UAT sign-off prerequisite, not a baseline prerequisite**. A separate synthetic School is **approved in principle**, executed only after WO-24 and the DDEV migration (§10) | HR owner, accessibility reviewer | Not failing | UAT sign-off | UAT unit | — |

**Counts.** 81 open rows: **81 approved dispositions, 0 proposals pending.** Every one of the 24 decision rows above (D-RF2-1 to D-RF2-23, plus D-RF2-1a) was given individually; the grouped ones (D-RF2-16, 17, 20, 21, 23) were confirmed as lists naming each identifier, with the reason per row, and the owner could exclude any item.

## 3. The 81-row matrix

Columns:
- *Source* is the file and line holding the finding's verbatim text. Material findings are also quoted in §4 to §7.
- *Sev* is original → current.
- *Rule* is the governing rule or ADR.
- *Status* uses: CORRECTION (approved; **MAJOR** marks a material blocker), VERIFIED CLOSED, ACCEPTED ALTERNATIVE (an accepted limitation or documented behaviour), DEFERRED, OPERATIONAL, HANDOFF (another owner), GATE (Legal, Security or release), PENDING (none remain).
- *Dev/Stg/Prod* is the development, staging and production consequence: "—" is none, "rec" is recorded not failing.
- *Next* is the work order or next action.
- *Re-review* is the trigger.

Reproduction status: WO22-N1, RF1-N1 (RF1), WO20-N1 and RF1-N2 (RF2 §5.2) are reproduced. WO20-N6 is shown unreachable by code. The others rest on their source record's evidence and the code reading cited there.

### 3.1 C35 work-order findings and RF1-N1/N2 (28)

| Id | Source | Sev | Rule | Current evidence | Status | Decision | Owner | Dev/Stg/Prod | Next | Re-review |
|---|---|---|---|---|---|---|---|---|---|---|
| WO17-N2 | `HR-R1-C35-WO17-KNOWN-SAME-BENEFICIARY.md:108` | LOW → LOW | 151 | No runtime TRIGGER privilege (RF1 §3.10) | ACCEPTED (dev) + HANDOFF | D-RF2-13 | Platform, Payroll | — / pin before staging / same | Verifier unit | New Payroll guard table |
| WO18-N1 | `HR-R1-C35-WO18-OWN-CASE.md:93` | INFO → INFO | 152 | `EmployeeLifecycleService::separate()` compares the current link | CORRECTION | D-RF2-2 | HR, Identity | closed by WO-24 / — / — | WO-24 | — |
| WO18-N2 | `…WO18-OWN-CASE.md:94` | INFO | 152 | A submission is not a decision; the decision is person-level | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | Submission gaining a consequence |
| WO19-N1 | `HR-R1-C35-WO19-LEAVE-WRITE-ASSURANCE.md:115` | LOW | 134; D-C35-3 | Cached `hr.leave.self` plus locked ActingEmployee | ACCEPTED ALTERNATIVE | D-RF2-18 | HR, Security | — | — | HRX-L4 |
| WO19-N2 | `…WO19…:116` | LOW | 134 | Run marked `failed`: truthful, resumable, audited | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | Run-state change |
| WO19-N3 | `…WO19…:117` | LOW | 37 | Only the SHA-256 of the JSON body is stored; the raw code never is (RF1 §3.7) | HANDOFF | D-RF2-20 | Platform | rec / before staging / same | Platform: exclude `mfa_code` centrally | — |
| WO19-N4 | `…WO19…:118` | LOW | 94 | Grants held FOR SHARE; same order as `StaffAccessService`, no deadlock | ACCEPTED ALTERNATIVE | D-RF2-16 | HR, Identity | — | — | Lock-order change |
| WO19-N5 | `…WO19…:119` | INFO | 95 | Access Center label overstates MFA for `hr.leave.manage` | ACCEPTED (dev); wording before UAT sign-off | D-RF2-19 | Identity UI | — / UAT sign-off / — | Identity UI/docs | — |
| WO19-N6 | `…WO19…:120` | INFO | 134 | Entry races proven; batch, run, adjust and close share the read | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | A new entry point |
| **WO20-N1** | `HR-R1-C35-WO20-RETAINED-ACCESS-ROLE-SET.md:108` | LOW → **MEDIUM** | 137, 138, R-HR12-3 | Reproduced (§5.2) | **CORRECTION, MAJOR** | D-RF2-2 | HR, Identity | **NOT CLOSED** until WO-24 / — / — | WO-24 | — |
| WO20-N2 | `…WO20…:109` | INFO | 5 | School filter behind forced RLS | ACCEPTED ALTERNATIVE | D-RF2-16 | Identity | — | — | RLS change |
| WO20-N3 | `…WO20…:110` | INFO | 137 | A purged bound grant is omitted from the names | ACCEPTED ALTERNATIVE | D-RF2-16 | Identity | — | — | Retention change |
| WO20-N4 | `…WO20…:111` | INFO | — | `activeSchoolGrantCount()` has no caller | ACCEPTED ALTERNATIVE | D-RF2-16 | Identity | — | — | — |
| WO20-N5 | `…WO20…:112` | LOW | 137 | Display only; nothing is blocked or written | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | Rehire volume |
| WO20-N6 | `…WO20…:113` | LOW | 137 | Not reachable (§7.2) | VERIFIED CLOSED | D-RF2-19 | HR, Identity | — | — | Lock change on either path |
| WO20-N7 | `…WO20…:114` | INFO | 152 | Older uncorrected account without a kept grant is not read | CORRECTION | D-RF2-2 | HR, Identity | closed by WO-24 | WO-24 | — |
| WO21-N1 | `HR-R1-C35-WO21-UNCLASSIFIED-STAFF-TYPE.md:111` | LOW | 137 | Fails closed: guard refuses, rolls back | ACCEPTED ALTERNATIVE | D-RF2-14 | HR | — | — | Observed outside tests |
| WO21-N2 | `…WO21…:112` | INFO | 137 | Configuration labels only | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | — |
| WO21-N3 | `…WO21…:113` | INFO | 151 | No runtime DELETE on requirements (DDEV, verifier pins) | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | Any deletion or retention design for requirements |
| WO21-N4 | `…WO21…:114` | INFO | 5 | School filter behind forced RLS | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | RLS change |
| WO21-N5 | `…WO21…:115` | INFO | 82 | No browser, axe or screen reader | OPERATIONAL | D-RF2-23 | HR | — / UAT sign-off / — | §10 | — |
| **WO22-N1** | `HR-R1-C35-WO22-TASK-REVIEW-GOVERNANCE.md:104` | LOW → **MEDIUM** | 102 (§64) | Reproduced (RF1 §3.1) | **CORRECTION, MAJOR** | D-RF2-1 | Architecture, HR | **NOT CLOSED** until WO-23 | WO-23 | — |
| WO22-N2 | `…WO22…:105` | INFO | 102 | Registry label precision | ACCEPTED ALTERNATIVE | D-RF2-16 | Architecture | — | — | Registry contract change |
| WO22-N3 | `…WO22…:106` | INFO | 152 | Cancel and reassign are not decisions | ACCEPTED ALTERNATIVE | D-RF2-16 | HR, Architecture | — | — | Cancel gaining a consequence |
| WO22-N4 | `…WO22…:107` | INFO | 98 | 404 versus 403; both refuse | ACCEPTED ALTERNATIVE | D-RF2-16 | HR | — | — | — |
| WO22-N5 | `…WO22…:108` | LOW | 82 | 1 failure in 70 CI runs, 10/10 local (RF1 §3.2) | CORRECTION (test-only) | D-RF2-7 | Platform | rec / before closure regression | WO-25 | Recurrence |
| **RF1-N1** | `HR-R1-C35-RF1-RESIDUAL-FINDINGS.md:144` | INFO → **MEDIUM** (with WO22-N1) | 102 | Reproduced (RF1 §3.1) | **CORRECTION, MAJOR** | D-RF2-1 | Architecture, HR | **NOT CLOSED** until WO-23 | WO-23 | — |
| **RF1-N2** | `…RF1…:247` | LOW → **MEDIUM** (with WO20-N1) | 152, R-HR12-3 | Reproduced (§5.2) | **CORRECTION, MAJOR** | D-RF2-2 | HR, Identity | **NOT CLOSED** until WO-24 | WO-24 | — |

### 3.2 C-series findings (32)

| Id | Source | Sev | Rule | Current evidence | Status | Decision | Owner | Dev/Stg/Prod | Next | Re-review |
|---|---|---|---|---|---|---|---|---|---|---|
| C10-N2 | `HR-R1-0-AUDIT-REGISTER.md:86` | P3 | 130 | Each step authorized and audited (D-RF2-9) | ACCEPTED ALTERNATIVE | D-RF2-9 | HR | — | — | Reports on change kinds |
| C10-N3 | REG:87 | P3 | 138 | `limit(1000)`, no order | DEFERRED (XRP-3 trigger) | D-RF2-21 | Leave, HR | rec / staging or > 1,000 staff | TeachingAssignments/Leave checkpoint | As XRP-3 |
| C10-N4 | REG:88 | P3 | 98, 132 | A contract, not assurance; step-up test per route | ACCEPTED ALTERNATIVE | D-RF2-17 | Architecture | — | — | A new protected write route |
| C10-N6 | REG:94 | docs | — | E21 record states links have no history | HANDOFF | D-RF2-20 | E21 record owner | — | Correct that record | — |
| C10-N7 | REG:90 | P3 | S-4 | Unreachable until a role holds the statutory key | GATE | D-RF2-21 | Payroll, Security | rec / — / before any statutory grant | — | Any statutory grant |
| C10-N8 | REG:91 | P3 | S-4 | As C10-N7 | GATE | D-RF2-21 | Payroll, Security | as C10-N7 | — | As C10-N7 |
| C10-N9 | REG:92 | P3 | 95 | No route-specific throttle | HANDOFF | D-RF2-20 | Security, Platform | rec / before staging | Platform | — |
| C10-N10 | REG:93 | LOW | — | Development data only | ACCEPTED ALTERNATIVE | D-RF2-17 | Documents, HR | — | — | Any non-development data |
| C11-N4 | `HR-R1-C11-HARDENING.md:67`; docket :343 | note | 133 | Freeze without status filter | ACCEPTED ALTERNATIVE | D-RF2-8 | HR | — | — | Classification change request |
| C14-N1 | `HR-R1-C14-RESIDUAL-HARDENING.md:84` | P3 | 98 | `app/Http/Controllers/App/Payroll/PayrollRunController.php:92`, `:100` | HANDOFF | D-RF2-10 | Security, Architecture, Payroll | rec / before staging | WO-10 | — |
| C14-N3 | `…C14…:189` | LOW | 78 | Operator practice | ACCEPTED ALTERNATIVE | D-RF2-17 | Platform tooling | — | — | Tooling change |
| C14-N4 | `…C14…:190` | LOW | 32 | Row result in a remembered 201 | ACCEPTED ALTERNATIVE | D-RF2-17 | Platform, HR | — | — | Import contract change |
| C18-A12 | `HR-R1-C19-RETENTION-BATCH-BOUND.md:133` | acc. | 82 | No manual screen-reader pass | OPERATIONAL | D-RF2-23 | HR, accessibility reviewer | — / UAT sign-off | §10 | — |
| C19-N1 | `…C19…:113` | LOW | 148 | Loop stops on a locked row | HANDOFF | D-RF2-20 | Communications, Retention | rec | Communications | — |
| C19-N2 | `…C19…:114` | LOW | 87 | Network versus resolution failure hidden | HANDOFF | D-RF2-20 | Platform CI | rec | Platform | — |
| C20-N1 | `HR-R1-C20-WO6-ACCEPTANCE.md:81` | LOW (axe serious) | 136 | Scroll region not focusable | HANDOFF | D-RF2-20 | Compliance UI | rec / before UAT sign-off | Compliance | — |
| C20-N2 | `…C20…:123` | obs. | D-7 | 1.4 s and 9.3 s at 1,000 | GATE (staging) | D-RF2-4 | HR, Payroll | rec / target at staging | Staging | D-7 |
| C22-N2 | `HR-R1-C22-WO5-CALENDAR-HISTORY.md:113` | LOW | 149 | Body hash strips `--` comments | HANDOFF | D-RF2-20 | Platform | rec / before staging | Platform | — |
| C22-N3 | `…C22…:114` | LOW | 149 | No answer changes | ACCEPTED ALTERNATIVE | D-RF2-17 | HR | — | — | — |
| C24-N1 | `HR-R1-C24-WO3-READER-MIGRATION.md:124` | LOW | 150 | Malformed id gives 500 | HANDOFF | D-RF2-20 | Platform | rec / before staging | WO-14 | — |
| C26-N2 | `HR-R1-C26-R1-01-ENFORCEMENT.md:193` | obs. | 82 | Exact-age assertion across a second boundary | CORRECTION (test-only) | D-RF2-7 | Platform | rec / before closure regression | WO-25 | Recurrence |
| C28-N1 | `HR-R1-C28-WO13-VERIFIER.md:73` | LOW | 151 | 115 non-HR INVOKER functions without `search_path` | HANDOFF | D-RF2-20 | Platform, domains | rec / before staging | Platform | — |
| C28-N3 | `…C28…:76` | LOW | 151 | A weakened body keeping `SET search_path` passes | HANDOFF | D-RF2-20 | Platform, HR | rec / before staging | Platform | — |
| C28-N4 | `…C28…:78` | LOW | 151 | False failure on managed PostgreSQL 16 | HANDOFF | D-RF2-20 | Platform | rec / before staging | Platform | — |
| C29-N1 | `HR-R1-C29-CREDENTIAL-VERIFICATION.md:180` | INFO | 132 | No maker/checker in scope | ACCEPTED ALTERNATIVE | D-RF2-17 | HR | — | — | Verification gaining maker/checker |
| C29-N2 | `…C29…:181` | INFO | 132 | Unbounded history per credential | ACCEPTED ALTERNATIVE | D-RF2-17 | HR | — | — | Retention design |
| C30-N1 | `HR-R1-C30-IDEMPOTENCY-SERVER-ERRORS.md:140` | LOW | 32 | Conservative direction | ACCEPTED ALTERNATIVE | D-RF2-17 | Platform | — | — | — |
| C30-N2 | `…C30…:141` | INFO | 33 | Documented contract | ACCEPTED ALTERNATIVE | D-RF2-17 | Platform | — | — | — |
| C32-N1 | `HR-R1-C32-WO9-ACCRUAL-STATUS.md:111` | INFO | 82 | WO-9 browser and axe not run | OPERATIONAL | D-RF2-23 | HR | — / UAT sign-off | §10 | — |
| C32-N2 | `…C32…:112` | INFO | D-7 | Measured at 300, not 1,000 | GATE (staging) | D-RF2-4 | HR | rec / at staging | Staging | D-7 |
| C32-N3 | `…C32…:113` | INFO | 134 | Untested unreadable-evidence paths | ACCEPTED ALTERNATIVE | D-RF2-17 | HR | — | — | WO-9 change |
| C32-N4 | `…C32…:114` | INFO | 134 | Cost grows with never-closed years | ACCEPTED ALTERNATIVE | D-RF2-17 | HR | — | — | Operators not closing years |

### 3.3 Original HR.R1-0 identifiers (12)

| Id | Source | Sev | Rule | Current evidence | Status | Decision | Owner | Dev/Stg/Prod | Next | Re-review |
|---|---|---|---|---|---|---|---|---|---|---|
| HRC-7 | REG:184 | P3 | 130 | `setPrimary` is a recorded correction | ACCEPTED ALTERNATIVE | D-RF2-9 | HR | — | — | As C10-N2 |
| LVA-7 | REG:196 | P3 | D-7 | `StaffAttendanceReadService.php:164-196` still composes in PHP | GATE (staging) | D-RF2-4 | HR, Leave | rec / target at staging | Staging | D-7 |
| PAY-12 | REG:217 | P2 (staging) | D-7 | Query-count bound now (`PayrollApprovalReviewLargeRunTest.php:92`); no timing | GATE (staging) | D-RF2-4 | Payroll | rec (C27: staging) / target at staging | Staging | D-7 |
| TRR-5 | REG:222 | P3 | ADR 0040, 140 | `TaskGenerationRunReport.php:54`, accumulation `TaskGenerationService.php:106` | ACCEPTED ALTERNATIVE | D-RF2-5 | HR | — | — | D-RF2-5 trigger |
| TRR-10 | REG:227 | P3 | 82 | Evidence uncommitted (scratchpad) | OPERATIONAL | D-RF2-23 | HR | — / UAT sign-off | §10 | — |
| GOV-2 | REG:229 | P3 | S-4 | S-4 open | GATE | D-RF2-21 | Security | rec / — / before any statutory grant | — | Statutory grant |
| GOV-6 | REG:233 | P3 | 97 | 28 hardened, 18 decision-gated, 6 Payroll; no verified bypass | ACCEPTED (PARTIAL), routed | D-RF2-15 | HR, Payroll | rec / Payroll models before staging | Payroll hardening | New family model |
| GOV-7 | REG:234 | P3 | E47 | Roles exist (`StaffRolePresentation.php:40-44`); E47 not sent | GATE (Legal) | D-RF2-6 | Legal | rec / E47 shut | Docs unit later | E47 sending |
| GOV-8 | REG:235 | P2 | 87 | E16 valid through 2026-11-28 | GATE (release) | D-RF2-21 | Security | Successor before 2026-11-29 | E16 owner | 2026-11-29 |
| GOV-9 | REG:236 | P2 | ADR 0074 | No staging infrastructure | GATE (staging) | D-RF2-21 | Platform | rec / staging | PPR | — |
| GOV-10 | REG:237 | P3 | 11 | Old decisions carry NULL evidence | ACCEPTED ALTERNATIVE (formal) | D-RF2-17 | HR | — | — | — |
| GOV-11 | REG:238 | P3 | 82 | No browser evidence for MFA-gated flows | OPERATIONAL | D-RF2-23 | HR | — / UAT sign-off | §10 | — |

GOV-1 (umbrella) closes when the others do (C27:44); it is not one of the 81.

### 3.4 Gate-register and review residuals (9)

| Id | Source | Sev | Rule | Current evidence | Status | Decision | Owner | Dev/Stg/Prod | Next | Re-review |
|---|---|---|---|---|---|---|---|---|---|---|
| R-HR10-1 | `HR-LEGAL-SECURITY-GATE-REGISTER.md:433` | LOW, PARTIAL | 134 | Closed-year credit stays flagged | ACCEPTED ALTERNATIVE | D-RF2-11 | Leave | — | — | HRX-L4 |
| R-HR14-4 | `…GATE…:452` | LOW | 140 | Runbook §6 (ANALYZE) | ACCEPTED ALTERNATIVE | D-RF2-17 | Operations | — | — | — |
| R-HR16-1 | `…GATE…:459` | LOW | D-7 | ~4.3 s at 5,000 | GATE (staging) | D-RF2-4 | Staff Attendance | rec / target at staging | Staging | D-7 |
| R-HR16-2 | `…GATE…:460` | LOW | 82 | Decisions not exercised in a browser | OPERATIONAL | D-RF2-23 | HR UI | — / UAT sign-off | §10 | — |
| R-HR16-3 | `…GATE…:461` | LOW | 82, 136 | No screen reader; date-segment focus ring | OPERATIONAL | D-RF2-23 | HR UI | — / UAT sign-off | §10 | — |
| R-HR16-4 | `…GATE…:462` | INFO | 132 | Legacy path-only documents: count unknown | GATE (pre-staging inventory) | D-RF2-21 | HR, Documents | rec / inventory before staging | Staging | — |
| M-1 | `…GATE…:46` | prerequisite | ORG.8F §64.7 | Unlinked staff parent not seen | GATE (production policy) | D-RF2-21 | Owner policy | — / — / before production | — | — |
| C24 R-2 | `HR-R1-C24-WO3-READER-MIGRATION.md:133` | LOW/INFO | 150 | Nothing ties the two `employee_id`s | DEFERRED | D-RF2-12 | Payroll, HR | rec / before real Payroll or staging | WO-15 | As D-RF2-12 |
| C24 R-5 | `…C24…:136` | LOW | 150 | Stated in the docblock | ACCEPTED ALTERNATIVE | D-RF2-17 | Architecture | — | — | Guard rewrite |

**Status totals (81):**

| Status | Count |
|---|---|
| CORRECTION | 8. The MAJORs are WO22-N1, RF1-N1, WO20-N1 and RF1-N2. The others are WO18-N1, WO20-N7 and the test-only WO22-N5 and C26-N2 |
| VERIFIED CLOSED | 1 (WO20-N6) |
| ACCEPTED ALTERNATIVE | 38: Groups A1 and A2 (28), plus WO17-N2, WO19-N1, WO19-N5, WO21-N1, C10-N2, C11-N4, HRC-7, TRR-5, GOV-6 and R-HR10-1 |
| DEFERRED | 2 (C10-N3, C24 R-2) |
| HANDOFF | 12: Group B (11) and C14-N1 |
| GATE | 13: C10-N7, C10-N8, GOV-2, GOV-8, GOV-9, R-HR16-4, M-1 (Group C, with C10-N3 deferred), and the D-7 staging items C20-N2, C32-N2, LVA-7, PAY-12 and R-HR16-1, and GOV-7 (Legal) |
| OPERATIONAL | 7 (Group D) |
| PENDING | 0 |
| **Total** | **81** |

Three ACCEPTED rows carry a later step: WO17-N2 (pinning before staging), WO19-N5 (wording before UAT sign-off) and GOV-6 (the Payroll models before staging). Each is also listed with that step in §2.

## 4. WO-23 contract: task state needs its event (D-RF2-1, D-RF2-1a)

### 4.1 The findings, verbatim

- **WO22-N1** (`…WO22…:104`): "The runtime role can set a task `returned` without its `returned` event; the event is the only record of who returned it, so a raw return escapes the identity guard. The service always writes both in one transaction. Inside rule 102's boundary (who acted); a deferred trigger or a `returned_by_user_id` column would close it."
- **RF1-N1** (RF1 :144): "the same holds for `submitted`: a raw transition to `submitted` with no `submitted` event is accepted. WO-22's submitter exclusion (`isKnownToBeSubmitter`) reads the `submitted` events, so such a task has no recorded submitter. The assignee check still applies."
- **Precedent** (ADR 0076 §64.12): "PostgreSQL can require the evidence row and the status domains without knowing who acted … **Classification: MEDIUM integrity gap**." Rule 102 (ORG.8F): "If PostgreSQL can enforce a structural rule without knowing which human acted (a status domain, an evidence row's existence, a terminal state's end date), enforce it in the database."

### 4.2 RF1-N1 against WO22-N1 (brief §9)

**One invariant:** a task status that its timeline must evidence exists only with its event, written in the same transaction. The two findings are the two statuses whose actor lives only on the event: neither `submitted` nor `returned` has an actor column on `workforce_tasks`.

The consequences differ:
- A raw `returned` with no event loses the reviewer and skips the WO-22 known-same-person check on the `returned` event.
- A raw `submitted` with no event loses the submitter. `isKnownToBeSubmitter()` and the database clause added by `2027_02_25` then find no submitter, so the submitter exclusion on completion cannot fire. The assignee check still applies.

One correction closes both. Each has its own acceptance criterion (§4.6, tests 1 and 2).

### 4.3 Current schema and order (inspected at `706ec9c3`)

**`workforce_tasks`** (`2027_01_31_090000_create_workforce_tasks.php`, :130-193):
- Status domain `assigned, submitted, returned, completed, cancelled`; `return_count <= 1`; `version`.
- `completed` and `cancelled` carry their actor on the row; the outcome CHECK at :189-191 requires it. `submitted` and `returned` have no actor column.

**`workforce_task_events`** (:261-288):
- `kind` is one of `generated, assigned, submitted, returned, completed, cancelled, reassigned`; `actor_user_id`; `occurred_at` (precision 0, set from PHP).
- Composite FK `(task_id, school_id)` to the task (cascade).
- **No unique index**, and **no version column**.
- Append-only: `TenantRls::makeAppendOnly` revokes UPDATE and DELETE.

**Guards** (both plain BEFORE row triggers, neither deferrable):
- `hr_workforce_tasks_guard` enforces transitions, versions, `return_count`, self-review and the WO-22 completion clauses.
- `hr_workforce_task_events_guard` reads the task only for its assignee, and **never checks the task's status**.

**Writers.** Every writer of a status change updates the task **first** and inserts the event **after**, inside one `DB::transaction` under `lockForUpdate`. Generation inserts new tasks, then their `generated` events, with no row lock:
- `TaskWorkflowService.php:59-62` (submit), `:91-94` (review), `:121-122` (cancel), `:131` (lock), `:211-217` (transaction);
- `TaskAssignmentService.php:85-86`, `:126`, `:137`;
- `TaskGenerationService.php:180-190`.

**Why not ORG.8G's pattern.** ORG.8G's immediate BEFORE UPDATE check (`students_require_lifecycle_evidence`) binds by `occurred_at = now()` on a microsecond column, with the event written first. That pattern would refuse every legitimate task transition, because the event is written after the task. It also cannot bind on a precision-0 column set from PHP. **So an immediate trigger requiring the event is wrong here.**

### 4.4 The approved design (version-bound, deferred)

The precedents:
- Staff Attendance corrections bind `from_version`/`to_version`, with a unique chain index and a deferred reverse check (`2026_11_20_090000_create_staff_attendance.php:82`, `:114-123`, `:148-159`).
- Student Marks use `student_marks_locked_change_approved`, AFTER UPDATE, DEFERRABLE INITIALLY DEFERRED, binding `base_version` (`2026_12_07_090000_…:201-220`).
- The Payroll reconciliation check is deferred and refuses an invisible row: "an empty answer proves nothing", PAY-15 (`2027_02_07_090000_…:287-306`).

The design:

1. **Schema.** Add `workforce_task_events.task_version integer NULL` with `CHECK (task_version IS NULL OR (task_version >= 1 AND kind IN ('submitted','returned')))`, so no other kind can carry a version. Add a partial unique index `(school_id, task_id, task_version) WHERE task_version IS NOT NULL`. That index covers only the two checked kinds, so an unchecked kind can never occupy a future version and block a legitimate event (review finding 8). Existing events keep NULL: **nothing is back-filled and no historical event is rewritten.**
2. **Event guard.** Patch `hr_workforce_task_events_guard` (BEFORE INSERT) through an exactly-once `pg_get_functiondef` anchor, as `2027_02_25` does; never restate it (rule 146). For `kind IN ('submitted','returned')`:
   - `task_version` must be NOT NULL;
   - the task must be visible, or the insert is refused;
   - its current `status = NEW.kind` and `version = NEW.task_version`.

   Because the service updates first, the event sees the new status and version in the same transaction. An event for a past or future transition, or for a task not in that state, is refused. The WO-22 known-same-person clause is kept unchanged and **evaluated first**, so its refusal code (`hr_workforce_task_review_same_person`) still wins for a same-person actor (review finding 2).
3. **Deferred existence check.** Add `trg_workforce_tasks_transition_evidence`, a `CONSTRAINT TRIGGER AFTER UPDATE OF status ON workforce_tasks DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('submitted','returned'))`, executing a new INVOKER function `hr_workforce_task_transition_evidence()` with `search_path` pinned and `pg_temp` last (rule 151). At commit it:
   - refuses when the task row is not visible under RLS (an empty answer proves nothing);
   - refuses unless an event exists with `school_id = NEW.school_id`, `task_id = NEW.id`, `kind = NEW.status` and `task_version = NEW.version`.

   NEW is the row as that UPDATE left it, so two transitions in one transaction (`submitted` v2, then `returned` v3) are each checked against their own version.
4. **Writers.** `TaskWorkflowService` sets `task_version` to the task's saved version on the `submitted` and `returned` events only; other kinds keep NULL, enforced by the CHECK (D-RF2-1a: not every transition). `TaskEvent` is a lifecycle model (rule 97, `tests/Support/LifecycleModels.php`), and `task_version` is not in its `$fillable` (`TaskEvent.php:30`). So it is written with `forceCreate` on a fixed array, or the model's disposition is updated. A dropped key would fail closed at commit. No other writer changes, and no statement order changes.
4a. **Context invariant** (review finding 9). The commit-time check needs the School's RLS setting still active at COMMIT. `TenantContext` sets it per session and restores it after its callback (`TenantContext.php:62`, `:195-212`). Every canonical caller (`HrTaskController` through `inSchool`) commits inside that callback. A future caller wrapping the service in an outer transaction that commits after the context is restored would fail closed, never open. WO-23 states this in the service docblock and tests it.
5. **Out of scope.**
   - `completed`/`cancelled`: the actor is on the row and required by CHECK.
   - `generated`/`assigned`: creation events.
   - The task-row identity of the actor: the actor column remains the caller's claim, rule 102's application boundary.
6. **Runtime role.** Its privileges do not change: `workforce_tasks` SIU, `workforce_task_events` SI, no DELETE. The runtime role cannot disable a constraint trigger, and `SET CONSTRAINTS … IMMEDIATE` only makes the check fire earlier, which refuses the raw path all the same.
7. **Retention.** Cascade deletes do not update `status`. The FK `SET NULL (reassigned_from_task_id)` update does not include `status` and fails the WHEN clause, so HR evidence purges are unaffected.
8. **Verifier.**
   - `DatabaseRoleVerifier::HR_FAMILY_TRIGGERS` gains the constraint trigger (111 → 112 triggers; the map is keyed by 45 tables). Rule 151's table counts are reconciled against the code in the same change: its "56 tables" privilege sentence is already stale, since `HR_FAMILY_RUNTIME_PRIVILEGES` holds 57 (review finding 11).
   - `KNOWN_SAME_PERSON_BODIES` gets the event guard's new body hash.
   - `hr_task_invariants` also asserts the trigger is enabled, `tgdeferrable` and `tginitdeferred`, the unique index and the CHECK.
   - The new function is INVOKER with the pinned `search_path`.
   - Rule 151's counts and rule 139's text are updated in the same change.
9. **Migration.** `down()` reverses each change in reverse order, through the reverse anchor. The guard is listed in `docs/hr/PAY-15-ROLLBACK-GUARD-INVENTORY.md` (rule 143); rollback is proven on the isolated test database only (`/rollback-proof`).

### 4.5 Ordering proof required

WO-23 must show, on committed data with two real sessions where it matters:
- the canonical submit and review paths commit with their events;
- a raw UPDATE with no event fails **at COMMIT**, not at the statement, and leaves the task unchanged;
- an event inserted before its UPDATE is refused, because the status does not yet match. This proves the order is binding, not incidental.

### 4.6 Tests required (each asserts the invariant's own refusal code, never an unrelated permission error)

1. Raw `submitted → returned` with no event, as `school_os_app` under the School's context: refused at commit, task still `submitted` (WO22-N1).
2. Raw `assigned → submitted` with no event: refused at commit (RF1-N1).
3. Canonical submit and review (return and complete): commit, with one event each, carrying `task_version`.
4. An event naming another task, or the right task at a different version: refused.
5. An event with another School: refused (composite FK and RLS), never a cross-tenant read. Raw RLS-layer isolation per rule 28.
6. An event whose actor is the assignee, or known to be the same person (WO-22): still refused, with the WO-22 code unchanged.
7. A duplicate event for the same version: refused by the unique index.
8. A forged event for a status the task does not have, or inserted afterwards for a past transition: refused.
9. Two concurrent reviews of one task (two processes): one commits; the other is refused on version or lock, and no orphan event or partial transition remains.
10. Rollback after a partial transition (the event insert fails): the task UPDATE rolls back too.
11. Historical events (`task_version` NULL) are unchanged after the migration, and a legacy `returned` task can be resubmitted canonically.
12. The permitted runtime-role mutation (the canonical path as `school_os_app`) still succeeds; DELETE stays refused.
13. Verifier removal probes:
    - drop the constraint trigger;
    - recreate it NOT DEFERRABLE or without the WHEN clause;
    - drop the unique index;
    - weaken either function body;
    - unpin `search_path`.

    Each makes `platform:verify-database` fail.
14. `WorkforceTaskReviewGovernanceTest.php:341` (a bare `returned` event from a same-person account) must keep its WO-22 refusal code, proving the clause order in §4.4 item 2. Rework the positive control at :342, which inserts a bare `returned` event on a `submitted` task and expects `ok`. It becomes a deliberate refusal case, with a canonical return as the new positive control. Its old acceptance is recorded as the contract change, never deleted silently. The raw completion control at :343 is unaffected, because `completed` is out of scope.

Plus: the WorkforceTasks, Postgres, HR, Authorization and Operations suites; `/rollback-proof`; `/publish-gate`; **an independent review**; the **exact-SHA full canonical regression** (`CI gate`); and publication through protected `main` (rule 96).

### 4.7 Acceptance

WO22-N1 and RF1-N1 close for development when WO-23 is published, with tests 1 and 2 green and the review finding no MEDIUM or above. Until then both are **open MAJOR**.

## 5. WO-24 contract: unlinking never clears School access (D-RF2-2)

### 5.1 Findings, verbatim

- **WO20-N1** (`…WO20…:108`): "An unrecorded case, or a legacy record, still reads only the Employee's CURRENT link: unlinking the Employee turns OPEN into CLEAR, so completion can certify while the old account keeps its roles. Older than WO-20 (HR.15) and never a false RETAINED; closing it person-level (uncorrected links, rule 152) for every state is an HR and Identity owner decision".
- **WO20-N7** (:114): "An older, ended, uncorrected account of the person whose membership holds none of the kept grants is not checked (as for non-kept cases); closing it is WO20-N1's person-level decision".
- **WO18-N1** (`…WO18…:93`): "the deceased direct end (`EmployeeLifecycleService::separate()`) still compares the current link only."
- **RF1-N2** (RF1 :247-251): "the unlink self-check is current-link only. `unlinkUser()` refuses only `refuseSelfLink($actor, $locked->user_id)` … An older, uncorrected account of the same person (rule 152's "known same person") can unlink them".
- **R-HR12-3** (gate register :434): "a separated person keeps School staff access until someone off-boards them; completion of the case is blocked meanwhile | by owner decision (flag, never automatic)".

### 5.2 Exact sequence (code at `706ec9c3`; reproduced 2026-11-20)

1. Employee E is linked to account A (`employees.user_id`), and A holds a School role.
2. The case is recorded, approved by another person (fresh MFA) and finalized (fresh MFA).
3. `SeparationObligations::schoolAccess()` (`SeparationObligations.php:139-169`) reads A's live grants (:141) and answers OPEN (:152, :167); completion refuses with `SEPARATION_OBLIGATIONS_OPEN` (`SeparationCaseService.php:392-394`).
4. An actor holding `hr.employees.manage` unlinks E (`EmployeeService::unlinkUser()` :323-359): **no fresh MFA, and no check of an open case**. The only self-check, `refuseSelfLink($actor, $locked->user_id)` (:340, `HrSelfAdministrationGuard.php:37-42`), compares the current account only.
5. With no current link, `$live` is empty: CLEAR (:141, :148). This is so for a case without a kept record and for a pre-WO-20 kept record. Only a bound kept record reads `uncorrectedLinkedAccounts()` (:142-144).
6. A holder of `hr.separations.manage` completes (no fresh MFA). That may be **the same actor**: nothing compares the unlinker with the completer. The completer may not be A, or an account known to be A's person (`isKnownToBeEmployee`, `2027_02_21`).
7. A keeps the role and an active membership. Completion never revokes anything (:116-118).

**Throwaway reproduction** on the isolated test database (`SAFE_TEST_ISOLATED=1 bin/safe-test`), deleted afterwards and never committed. The fixture is that of `SeparationRetainedAccessBindingTest`, with one actor holding `hr.employees.manage` and `hr.separations.manage`:
- `before unlink: access=open completion=SEPARATION_OBLIGATIONS_OPEN`;
- `after unlink: access=clear completion=completed activeGrants=1 membership=active`;
- RF1-N2: an older account (link history `["subject",ended],["older",ended],["subject",current]`, none voided) holding `hr.employees.manage`: `unlink ACCEPTED; access now=clear`. The older link was written by a fixture update; through the application it needs a third party's link (rule 95).

**Classification (D-RF2-2): MEDIUM, so MAJOR.** It is not a continuing *unauthorized* access condition: the roles were granted legitimately and stay visible under Settings → Staff accounts. It **is** a supported premature-completion path. One person, without fresh MFA, certifies a separation while the access the owner's decision (R-HR12-3) keeps blocking still exists. A weaker path (unlink) defeats a control whose resolution path (off-boarding, `StaffAccessService::suspend()`) requires `school.members.*` and fresh MFA. HRC-4 (same harm class) was recorded P3. The register gives no reason for that rating. RF2's own distinction, not a recorded one: HRC-4 needed a later grant, while this needs only an ordinary unlink. RF1-N2's reproduction is partial: its older link was written by a fixture update, where the application would need a third party's link (rule 95).

### 5.3 Approved correction

1. **Person-level access answer for every state.** `schoolAccess()` reads the active School grants held by the accounts in `uncorrectedLinkedAccounts()` (current and ended links, `voided_at IS NULL`) for every case state, not only bound kept records:
   - not kept, with a grant → OPEN, or ATTENTION before finalization;
   - pre-WO-20 kept record → OPEN/ATTENTION (D-WO20-2 unchanged);
   - bound kept record → RETAINED only while every active grant of **every** uncorrected linked account is in the bound set, not only the holders of the bound grants.

   Closes WO20-N1 and WO20-N7.
1a. **Kept access is recorded person-level too** (review finding 1). Today `recordAccessRetained()` binds the **current** account's grants only, and the `2027_02_23` guard requires the set to equal the grants of `hr_separation_subject_user()` (the current link). Under item 1 that would leave two failures:
   - kept access could never be recorded after an unlink;
   - an older account's grants would sit outside the set and read OPEN for good.

   So WO-24 also binds, at recording, the grant set of every uncorrected linked account, read under Identity's School access lock, with the database guard's set requirement widened to match. What stays the same:
   - D-WO20-1: never recorded again; off-boarding resolves it.
   - D-WO20-2: a pre-WO-20 record binds nothing.
   - A bound record made before WO-24 keeps its current-account set and reads OPEN while an older account holds a grant, until that grant is off-boarded. That is D-WO20-1's resolution.

   This follows from D-RF2-2's person-level reading and does not change D-C35-2's choice to bind the set. The owner may revisit it when authorizing WO-24's start.
2. **A correction still releases.** A link corrected as another person's (rule 145, two-person, fresh MFA) leaves `uncorrectedLinkedAccounts()`, so a genuinely wrong account never traps the case. `an_account_corrected_as_someone_else_is_never_held_to_the_kept_set` stays green, with a new test for the non-kept states. An unlink is never a correction.
3. **Person-level unlink self-check** (RF1-N2). `unlinkUser()` refuses an actor for whom `HrSelfAdministrationGuard::isKnownToBeEmployee($actor, $school, $employee)` is true (`:129`), inside its transaction after the existing locks. This is the rule 152 relation, never a name or email match.
4. **Person-level deceased direct end** (WO18-N1). The current-link refusal is `EmploymentService::end()`'s `refuseOwnEmployee()` (`EmploymentService.php:313-315`), which `EmployeeLifecycleService::separate()` (:72-102) calls. WO-24 makes the direct-death path person-level through the rule 152 relation (`isKnownToBeEmployee`), with its own refusal code. As rule 152 requires, there is a database half wherever PostgreSQL can enforce it on the recorded actor; otherwise WO-24 records why it cannot. Other callers of `end()` (the separation finalization) already refuse person-level through `lockEmployment()`, and are kept.
5. **Database backstop (rule 102).** PostgreSQL can enforce "no `finalized → completed` while an account with an uncorrected link to the Employee holds an active School grant outside a bound kept set" without knowing who acted. `2027_02_23` already reads live grants from a separation guard, so this is a precedent, not a new kind of coupling. WO-24 adds that completion guard, or records in its own review why it cannot without actor identity.
6. **Not changed.**
   - No automatic revocation: off-boarding stays rule 92's.
   - No fresh MFA added to unlink (not decided).
   - R-HR12-3's decision is unchanged; its register cell is only annotated with this record.
   - WO20-N5 (display after a rehire) may appear more often; it stays display only and accepted.

### 5.4 Tests required

- The §5.2 sequence becomes a refusal: after the unlink, OPEN, and completion `SEPARATION_OBLIGATIONS_OPEN`.
- The older-account unlink is refused.
- A pre-WO-20 kept record is refused the same way.
- An ended, uncorrected account holding no kept grant is read (WO20-N7).
- A corrected link releases, in every state.
- A deceased direct end by a known-same account is refused.
- Kept access recorded after an unlink binds the older account's grants (§5.3 item 1a). A bound record made before WO-24 reads OPEN while an older account holds a grant.
- The database refuses a recorded set that omits an uncorrected account's grant.
- A raw `completed` with a linked holder's grant is refused by the database, as the runtime role.
- Concurrency (two processes): an unlink against a completion serializes (Employee FOR SHARE against FOR UPDATE), and a grant against a completion holds Identity's lock first.
- Verifier pin of the new guard.
- Allow and deny tests for every touched route; Separation, Identity, HR, Postgres and Authorization suites; `/rollback-proof` if a migration ships; an independent review; the exact-SHA full canonical regression; protected `main`.

## 6. WO-25 contract: test stability (D-RF2-7, test-only, Platform)

- **WO22-N5** (`…WO22…:108`): "`IdempotencyServerErrorRealRouteTest::two_real_processes_racing_a_released_failure_never_write_twice` (C30, unrelated to WO-22) failed once on the first candidate run: two real HTTP processes did not answer within its 25 s barrier under CI load."
- **The test** (`tests/Feature/Idempotency/IdempotencyServerErrorRealRouteTest.php:188-260`):
  - `raceUnderBarrier()` holds advisory lock 3030 and starts two `curl` processes against `php -S` (`PHP_CLI_SERVER_WORKERS=4`);
  - it fails when **both** are still running after 25 s ("Under the 30 s in-flight timeout");
  - it always releases the lock in `finally`.
- **Unit steps:**
  1. Instrument first: which request reached the injected trigger, each request's server accept time, and `pg_stat_activity` wait events at the deadline.
  2. Reproduce under repeatable load (CPU-limited container, warm and cold server).
  3. Fix the coordination (for example, warm the server and wait for readiness before the race, or start the second request only once the first is held).
- **Constraints:**
  - never only raise the 25 s deadline;
  - never weaken "one request returns 409 while the other is held";
  - no change to `EnsureIdempotent`, `IdempotencyGuard` or `CommittedEffectDetector`, proven by an empty diff under `app/`;
  - the record distinguishes harness failure from product failure.
- **C26-N2** (`BacklogSignalsTest`, an exact-age assertion across a second boundary) joins the unit under the same rules.
- **Timing:** required before the final closure regression (§8), not before WO-23 or WO-24.

## 7. Other priority dispositions

### 7.1 WO19-N1 (D-RF2-18) and WO19-N3 (D-RF2-20)

**WO19-N1, verbatim** (:115): "Self-service cancellation writes its reversal (and any reconciliation) behind the cached `hr.leave.self` plus a freshly locked ActingEmployee." D-C35-3 (`HR-R1-C35-P3-DISPOSITIONS.md:43`) named the entry points as "allocate, allocation run, adjust, opening, accrual run start and continue", and added that "consumption and reversal written inside a leave decision keep that decision's authority". The owner accepted it within that scope and did not extend D-4.

**WO19-N3, verbatim** (:117): "The idempotency fingerprint hashes the whole JSON body, `mfa_code` included (unsalted SHA-256)."
- **What is stored:** the **raw MFA code is never stored or logged**. `api_idempotency_keys.request_fingerprint` holds a SHA-256 of the logical JSON body, and the `idempotency.outcome` log carries no payload (rule 37).
- **The exposure:** with the other fields known, a six-digit code is searchable from that hash. Only a code that failed validation before verification could still be live, and using it needs the account's session or token.
- **Replay identity:** a retry with a new code changes the fingerprint and conflicts, which is the documented key contract.
- **Routing:** to Platform before staging; no global refactor is authorized.

### 7.2 WO20-N6 (D-RF2-19)

**Verbatim** (:113): "Completion does not take the lock that link changes take (the compensation lock and the Employee row), so a relink committing during completion can be missed…".

The code says otherwise:
- `complete()` → `lockCase()` → `lockEmployment()` reads the **Employee row FOR SHARE** (`HrSelfAdministrationGuard.php:47`, through `EmployeePlacementLock`) before it reads the link, and holds it to commit.
- `linkUser()` and `unlinkUser()` take that row **FOR UPDATE** (`EmployeeService.php:333`, and the link path).
- So the two strictly serialize: a link change committed first is seen, and one committed later shows OPEN on the completed case.

No alternative path writes `employees.user_id` (rule 145: one writer, history by trigger). **VERIFIED CLOSED.** WO-24 keeps this order.

### 7.3 WO21-N1 and WO21-N3 (D-RF2-14, D-RF2-16)

- **WO21-N1:** the items guard refuses the insert and the transaction rolls back, so nothing commits. It fails closed. The 500 is an operator-experience defect, and its development effect is none. Accepted; re-reviewed if it is ever observed outside tests.
- **WO21-N3:** `school_os_app` has no DELETE on `separation_clearance_requirements` (DDEV, read only; `HR_FAMILY_RUNTIME_PRIVILEGES`). Requirements are retired, never deleted, so the requirement history keeps its lifecycle. Accepted, with the trigger: any retention or deletion design for requirements.

### 7.4 WO17-N2 (D-RF2-13)

The runtime role has no TRIGGER privilege on the three Payroll guard tables, and they are owned by the migration role (RF1 §3.10, `01-roles.sql:42-43`), so an extra trigger needs privileged DDL. It is not a runtime exploit without that prerequisite. Before staging:
- a Platform/Payroll unit pins those tables' full trigger set;
- staging drift verification must detect privileged DDL.

## 8. D-RF2-3: the final closure regression

For the **selected HR.R1 baseline SHA**:
1. The complete canonical inventory runs on that one exact SHA, with the selected environment baseline, through the remote `CI gate` (ADR 0072 §28.8, §31).
2. All four test shards, the coverage-union proof (`platform-coverage`), and every required static, security and supply-chain check pass.
3. **A failed shard requires a complete clean execution of all jobs on that SHA** for closure acceptance. A failed-jobs-only rerun does not count. No existing higher authority in ADR 0072, `RELIABILITY.md` or the closure contract allows another proof (RF1 §5).
4. A failed external build or infrastructure job (for example, an image download) is reported apart from a test failure. It still requires the complete clean execution.
5. Reruns never erase history: the failed run and the passing run are both recorded, with each failure dispositioned individually (WO22-N5-style recurrences are named, never silently rerun).
6. No earlier publication is re-judged: WO-20 to WO-22's CI history (RF1 §5) stands as published under rule 96.
7. The R1-04/05/07/08 re-runs of the docket §12 and the `SAFE_TEST_ISOLATED=1` clean snapshot (C27:147) still apply.

**Assertion drift.** No monotonic assertion total is required. The canonical test inventory and the coverage union are what must hold. Explained drift (polling-loop concurrency tests, `StaffNavigationCatalogueTest` iterating `roles`; RF1 §5) is recorded. An unexplained drop in the *test* count blocks until explained.

## 9. DDEV migration request (D-RF2-22: approved, to run after WO-24 publishes)

RF2 applied nothing. The operator step, when its time comes:

1. **Target and ledger.** Confirm `current_database()` = `db` in the `lycenza` DDEV project. Record `count(*)` and `max(batch)` from `migrations` (398 and 9 on 2026-11-20).
2. **Source and pending set.** Record the source SHA (published `main`). `comm` of the ledger and `database/migrations` must list exactly the pending files, which are named in the operator record:
   - `2027_02_24_090000_record_separation_clearance_staff_type_basis`: two nullable columns, a shape CHECK, two guard functions and triggers; no data transformation.
   - `2027_02_25_090000_refuse_known_same_person_task_review`: patches two task guards; no table or data change.
   - WO-23's migration, and WO-24's if it ships one.
   - 24 depends on HR.12's separation tables and `2027_02_23`; 25 on `2027_01_31`, `2027_02_17` and `2027_02_19`. All are applied on DDEV.
3. **Before-evidence.**
   - Counts of Schools, employees, account links, separation cases, clearance items, tasks, task events, payroll runs, leave ledger rows and School audit events.
   - The `membership_role_assignments` rows bound by any kept record (WO-20 role evidence).
   - md5 of the October 2026 Staff Attendance month-lock row.
   - The forced-RLS table count.
   - `platform:verify-database`.
4. **Migrate.** `php artisan migrate --database=pgsql_admin` only (rule 54). Never `fresh`, `rollback`, `db:seed` or `demo-reset`.
5. **After-evidence.**
   - The full ledger.
   - Identical counts, an identical month-lock md5, identical WO-20 evidence.
   - Task events unchanged (`task_version` NULL on all).
   - Payroll guards present.
   - The forced-RLS count plus any new table.
   - `platform:verify-database` failed=0 (the connection-encryption operator row is expected outside production).
6. **Contingency.** A forward correction only. Rollback is proven on the isolated test database in each unit, and the development database is never rolled back.
7. **Record.** The result goes in the HR.R1 evidence.

No database closure baseline is selected before this evidence exists.

## 10. Operational acceptance and synthetic UAT (D-RF2-23)

### 10.1 Acceptance matrix (nothing has been executed; nothing is marked passed)

| Workflow | Evidence today | Browser walkthrough | Keyboard | axe | Screen reader (manual) | Prerequisite data |
|---|---|---|---|---|---|---|
| WO-6 month lock | C18/C20 suites; C20 walkthrough; axe 0 | Done (C20) | Done (C20) | Done (C20) | **C18-A12 open** | Locked month exists |
| WO-5 staff calendar | C22 suites | Required | Required | Required | Required | Synthetic School, history start |
| WO-9 accrual status | C32 suites | Required (C32-N1) | Required | Required | Required | Leave year, monthly policy, runs (complete, failed, missed) |
| WO-19 Leave writes | 26 tests | Required: fresh-MFA prompts, refusals, failed run | Required | Required | Required | Leave configuration, MFA identity, a revocable operator |
| WO-20 kept access | 19 tests | Required: OPEN with role names, RETAINED, legacy | Required | Required | Required | Cases, roles, a kept record |
| WO-21 unclassified | 17 tests | Required: "Unclassified", named skipped requirements (WO21-N5) | Required | Required | Required | Classified and unclassified employments, staff-type requirements |
| WO-22 task review | 20 tests | Required: `TASK_SAME_PERSON_REVIEW`, return, complete | Required | Required | Required | Two staff, linked accounts, a known-same account, reviewed template |
| WO-23, WO-24 | (to be built) | Required after build | Required | Required | Required | As WO-22 and WO-20 |
| MFA-gated HR/Payroll (GOV-11, TRR-10, R-HR16-2/3) | Tests | Required | Required | Required | Required | Enrolled MFA persona |

Each step records:
- the operation;
- the expected page and refusal text;
- the persisted state (status, event row, audit event);
- a screenshot, the axe JSON and the keyboard path.

Evidence is committed under the UAT unit's record, never left in the scratchpad (TRR-10).

### 10.2 Synthetic fixture plan (approved in principle; run only after WO-24 and the DDEV migration)

**Identity, ownership and data.**
- **School:** one new School, created through `SchoolLifecycleService` (rule 86), with a recognisably synthetic name and code.
- **Owner:** the HR owner, through the UAT unit's own operator.
- **Lycenza Demo School** and every existing School are untouched, and there is no `demo-reset`.
- **No real personal data:** generated names and `example.test` addresses through Mailpit only, and no statutory identifiers.

**Scope.**
- Employees and employments: classified and unclassified, active and separated.
- School roles: `line_manager`, `team_task_manager`, `payroll_approval_reviewer`, and a separation approver.
- Separation cases in recorded, approved, finalized and completed states.
- A WO-20 kept record and a legacy one.
- Task templates (self and reviewed), tasks and reviews.
- A Leave year, a policy with monthly accrual, and allocations and runs.
- Two MFA test identities (enrolled factors), plus two accounts each holding an uncorrected link to one Employee (a known-same-person pair, rule 152) for the refusals.
- Every write goes through the canonical services, so audit evidence is produced naturally. There is no raw insert, except the documented `LocalCatalogueFixtures` seam where a role fixture needs it.

**Isolation.** The School's own RLS context. Nothing is shared with other Schools.

**Cleanup and retention.** Suspend, then close, the School through the lifecycle (rule 86). Audit history is never deleted. Screenshots, axe JSON and logs are kept with the UAT record, and contain no secret, token or MFA code.

### 10.3 Accessibility, separated

| Kind | Owner | Environment | Prerequisite |
|---|---|---|---|
| Keyboard verification | HR UI | DDEV, synthetic School | Fixture plan executed |
| Automated axe | HR UI | DDEV, ephemeral browser outside the repo (HR.16 method) | School selected after login, so the right page is measured |
| Manual screen reader (C18-A12, R-HR16-3) | **A named human accessibility reviewer** | DDEV | Fixtures, a reviewer appointed by the HR owner |
| Browser functional UAT | HR owner | DDEV | Fixtures |
| API/service evidence | Existing suites | CI | Already present |

A green Vue build or a passing `vue-tsc` is never accessibility evidence.

## 11. External handoffs and gates carried unchanged

- **R1-02: PARTIAL.** Payroll's part is done (C24); X-1's three controllers and the 24 non-Payroll files remain. C10-N3 is one of them.
- **C33-H1..H5** (Fees, Payments, Communications, Examinations, Erasure): open with their owners.
- **RG-ID-1:** open release gate before real Payroll.
- **XRP-3:** deferred to a TeachingAssignments checkpoint (staging, or a School above 1,000 staff), under D-C35-7.
- **Legal/DPO:** E21 (incl. D9), E45/ESI-12, E47 (D-RF2-6: draft extension later, never sent), HRX-L1..L4, S-4, X-1, ADR 0036, ADR 0040, D-11.
- **Platform:** C28-N1/N3/N4, C22-N2, C24-N1, C30, WO19-N3, C10-N9, C19-N2.

A routed item stays open with its receiving owner until that owner disposes of it. A handoff authorizes no production processing.

## 12. Closure readiness

### 12.1 Development MAJOR inventory (C27-C)

| Finding | Severity | Owner | Closes with |
|---|---|---|---|
| WO22-N1 | MEDIUM (D-RF2-1) | Architecture, HR | WO-23 |
| RF1-N1 | MEDIUM, same invariant (D-RF2-1) | Architecture, HR | WO-23 |
| WO20-N1 | MEDIUM (D-RF2-2) | HR, Identity | WO-24 |
| RF1-N2 | MEDIUM, with WO20-N1 (D-RF2-2) | HR, Identity | WO-24 |

There is no HIGH. **HR.R1 is NOT CLOSED** until WO-23 and WO-24 publish.

### 12.2 The five baselines

| Dimension | State | Before selection |
|---|---|---|
| Source | **Not ready** | WO-23 and WO-24 published; WO-25 published before the closure run; then a code-lane SHA with a clean complete run (§8) |
| Database / migrations | **Not ready** | §9 executed after WO-24, with verifier evidence |
| Reference pack | Ready for selection | C16 fingerprint unchanged (`40ed6a83…`); not re-captured |
| Legal / Security evidence | Ready as a snapshot | Selecting it opens nothing. E16 needs a successor for any publication from 2026-11-29 |
| Local environment | **Not ready** | DDEV migrated (§9). Operational evidence is a UAT sign-off prerequisite (D-RF2-23), not a selection prerequisite |

## 13. Independent review

An independent reviewer read the record and its four cross-reference edits at the working tree on `706ec9c3`, read only, against the sources and the code. It ran nothing beyond reads and `git`. It is a review, not an owner, Security or Legal approval, and it changed no decision.

| # | Rating | Finding | Treatment |
|---|---|---|---|
| 1 | MEDIUM | WO-24's person-level read clashed with kept access binding the current account only: after an unlink nothing could be recorded, and an older account's grants would read OPEN for good. "Bound record → unchanged" would not close WO20-N7 | **Corrected:** §5.3 items 1 and 1a (person-level recording, the database set widened, the effect on bound records made before WO-24), with tests in §5.4. Flagged for the owner at WO-24's start |
| 2 | LOW | Test :341 expects the WO-22 refusal code; the new clause could fire first | **Corrected:** WO-22 clause evaluated first (§4.4 item 2), and test 14 |
| 3 | LOW | The audit register edit inserted 2 lines, shifting every `REG:n` citation (RF1's too) | **Corrected:** the note now extends the existing line 6; no register line moves |
| 4 | LOW | C29-N1, C19-N2 and C20-N2 cited a mention, not the finding row; C14-N1's controller path was ambiguous | **Corrected:** :180, :114, :123; full path |
| 5 | LOW | "No change to R-HR12-3's text", while its register cell is annotated | **Corrected:** "decision unchanged; cell annotated" |
| 6 | LOW | "One account linked twice" cannot form a known-same pair | **Corrected:** two accounts, each with an uncorrected link to one Employee (§10.2) |
| 7 | LOW | The deceased-end fix named the wrong function and no database half | **Corrected:** `EmploymentService::end()`'s `refuseOwnEmployee()`; database half or a recorded reason (§5.3 item 4) |
| 8 | LOW | A version on an unchecked kind could occupy a future version (raw denial of service) | **Corrected:** CHECK limits `task_version` to `submitted`/`returned` (§4.4 item 1) |
| 9 | INFO | The commit-time visibility check needs the RLS setting at COMMIT | **Corrected:** stated as an invariant (§4.4 item 4a) |
| 10 | INFO | `TaskEvent::$fillable` lacks `task_version` | **Corrected:** `forceCreate` on a fixed array or the model disposition updated (§4.4 item 4) |
| 11 | INFO | "57 tables, 112 triggers" mixes two maps; rule 151's "56 tables" is stale | **Corrected:** 111 → 112 triggers (45 tables keyed); the rule's counts are reconciled in WO-23 |
| 12 | INFO | HRC-4's P3 reason is RF2's inference; RF1-N2's reproduction used a fixture-written link | **Corrected:** both stated as such (§5.2) |
| 13 | INFO | 24 decision rows, not 23; 8 legend rows; the closure-contract wording omitted the DDEV migration before UAT; the "every writer" wording included generation | **Corrected** |

**Confirmed by the reviewer:**
- **Inventory:**
  - all 81 rows exactly once;
  - the status totals per row (8/1/38/2/12/13/7);
  - every group list against its decision id;
  - 104 identifiers, E16 (119 exceptions, expiring 2026-11-29), 400 migrations and the ancestry.
- **Citations:** about 60 source lines.
- **Precedents:** the §64.12 quotation and rule 102; the three deferred precedents and ORG.8G's binding, described correctly.
- **WO-23 design (§4.4):**
  - (a) it works with every writer's statement order, self-mode submit (kind `completed`) and reassign included;
  - (b) no legitimate writer reaches `submitted`/`returned` without its event;
  - (c) no INSERT or FK action reaches those states unchecked;
  - (d) the runtime role cannot avoid a deferred constraint trigger.
- **WO-24 (§5):** the sequence and lines; corrected links still release; `2027_02_23` reads live grants, so the database backstop has a precedent; no other clearing path. Disabling a User does not clear.
- **WO20-N6:** the lock facts, and `EmployeeService` as the sole writer of `employees.user_id`.
- **Closure:** consistent with closure-contract §2 and C27.
- **Hygiene:** no secret, personal data, forged approval or claimed execution. The owner approvals are, by nature, not verifiable from the repository.

## 14. Verdict and next

**RF2 COMPLETE — MULTIPLE CORRECTIONS REQUIRED.**
- Every one of the 81 open rows has an individually approved disposition (0 pending). D-7, D-9 and D-10 are decided.
- Two separately scoped MAJOR corrections, **WO-23** (task evidence) and **WO-24** (unlink never clears access), and the test-only **WO-25** must precede baseline selection.
- WO-23's contract (§4) is complete and ready for a start authorization.
- No baseline is selected, and HR.R1 stays **OPEN**.
- The regression counter is unchanged at 0/5 (documentation only; no runtime regression is claimed).

**Next:** **WO-23**, on the owner's start authorization. Then WO-24, then WO-25, then the DDEV operator step (§9), the synthetic UAT (§10), and HR.R1-C36 (baseline selection). None is started.
