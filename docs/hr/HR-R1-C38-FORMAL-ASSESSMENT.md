# HR.R1-C38: formal development-scope assessment (2026-11-21)

**The HR.R1 record that `HR-R1-CLOSURE-CONTRACT.md` §4 calls for.** This record is the assessment only: nothing was built, migrated or selected, and no code, test assertion, fixture or credential was touched. The only other documents changed are the owner's C38 decision annotations (capability map Gate column, roadmap, closure contract; §13). Every verdict word comes from §4 of the contract (PASS, PASS WITH CORRECTION, BLOCKED) as qualified by C27-C. **Verdict (HR owner, 2026-11-21, §13): PASS WITH CORRECTION — development scope; production not addressed.** HR.R1 stays **OPEN**: it counts as development-closed only after WO-26 publishes and the bounded re-run records PASS.

| | |
|---|---|
| Identifier | **HR.R1-C38.** No existing identifier is assigned to the final assessment: the contract reserves "an HR.R1 record in `docs/hr/`", and C27 §10 row 6 names "Closure regression and the HR.R1 record". C38 continues the checkpoint series (C37 is the closure regression); it opens no other namespace |
| Contract | `HR-R1-CLOSURE-CONTRACT.md` §1–§4 (literal), read with the C12 docket (`HR-R1-C12-DECISION-DOCKET.md`), C16 (§2 reading), C27 (`HR-R1-C27-CLOSURE-INTERPRETATIONS.md`: C27-A, -B, -C, -D) |
| Authority | The HR owner decides §1, §4, R1-09 and the baselines; Architecture decides closure-level definitions (docket §1). Legal, DPO and Security decide their own gates; nothing here decides one |
| Baselines | C36 (`2c924e37`, owner-selected 2026-11-20): source `5dcc35ef`; database DDEV `db` 402/402, schema SHA-256 `2d581b70…877b`; reference pack C16 `40ed6a83…1ac3`; Legal/Security blobs at `eb3d6dde` + C36 §1; environment snapshot `lycenza-c36-environment-baseline` |
| Regression | C37 (`4e505c49`): CI 44781452652 + local closure regression, 11,998 tests, 0 failures, 1 skip (ESI-12); R1-04/05/07/08 PASS |
| Publication authority | E16 OWNER-0O-E16-2026-11-01: 119 exceptions expiring 2026-11-29, valid through 2026-11-28 |

## 1. Preflight (2026-11-21)

- `origin/main` = `HEAD` = `4e505c49`, clean tree, one worktree, one stash (preserved), `integration/e16-r2` preserved.
- `5dcc35ef` is an ancestor of `main`. `git diff 5dcc35ef 4e505c49` touches only six documents under `docs/hr/` (WO-25 evidence, DDEV migration, UAT0, C36, C37, the closure contract). **No runtime publication after the selected source.**
- `main-protection` ruleset 24909660: active, 0 bypass actors, rules deletion, non-fast-forward, linear history, required status checks.
- CI: 44781452652 (`integration/hr-r1-c35-wo25`, `5dcc35ef`, attempt 1, success); 44884829473 (`integration/hr-r1-c37`, `4e505c49`, attempt 1, success); 44885020983 (`main`, `4e505c49`, attempt 1, success).
- This checkpoint is documentation only.

## 2. Five-part baseline reconciliation (re-verified read-only today)

| Dimension | Selected (C36) | Verified today | Status |
|---|---|---|---|
| Source | `5dcc35ef9539eb94743af7fc6e402c40008f8a72` | Ancestor of main; no runtime file changed since; every WO publication of HR.1A–WO-25 (`ab4459af` … `5dcc35ef`, §6) is an ancestor | **Intact** |
| Database | DDEV `db`, 402/402, schema `2d581b70…877b`, verifier failed=0, 256 forced RLS, grants md5 `71668564…`/934 | Ledger 402, last `2027_02_27_090000…`; the C36 recipe reproduces `2d581b7042d1f75ca63bda0a4a3eda94c38b8ebc99d63c74bcfdda2ef143877b` (md5 `6a994dc2…`); `platform:verify-database` failed=0 (one operator-evidence row, TLS); 256 forced; grants `71668564a2a3f67e81f35eb1997ff2d8`/934; October 2026 month lock md5 `851693a2c1a63b4b81b88d75d0a63b63` | **Intact** |
| Reference pack | C16 `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`, 602 PNG | Not recomputed today (the pack is owner-held; C36's independent reviewer recomputed it on 2026-11-20). The pack is untouched; the capability map that scopes it gained six Gate-column owner annotations in this checkpoint, with no status change (§9, §13) | **Intact as selected** |
| Legal/Security | Blobs at `eb3d6dde` + C36 §1 | `HR-LEGAL-SECURITY-GATE-REGISTER.md` unchanged since `eb3d6dde`; C36 §1's five dispositions present | **Intact** |
| Local environment | Snapshot `lycenza-c36-environment-baseline` | Snapshot SHA-256 `bc4e0855298d3ab8f5b9452baa28c7565a7ab2c5deedbea48308ac3eb7e3a50d`; frontend manifest `7b9e6f1b…0fce`; `UAT-HR-SYN` active, 15 memberships, 13 Employees, 8 cases, 16 tasks, 22 task events; `secrets.json` mode 600 and git-ignored, not read | **Intact** |

No drift; no baseline-change route is needed. The DDEV checks above were reads (catalogue queries, `pg_dump --schema-only`, the verifier); nothing was written.

## 3. Closure regression acceptance (C37)

| Item | Outcome |
|---|---|
| Accepted CI leg 44781452652 | **Accepted** by the owner at C36 as D-RF2-3's clean complete run (first attempt, every required job) |
| Local full suite on `5dcc35ef` | **PASS**: 11,998 tests, 0 failures, 1 skip, four plan-verified shards, isolated project |
| Coverage union | **Identical** to CI (selection sha256 `0d8fd41c…92e1`) |
| Assertions 465,578 vs CI 468,263 | **Explained**: variable polling counts in concurrency tests; same test list. This explanation does not cover a future unexplained change |
| ESI-12 | The one skip; a Legal deferral (E45), **not a pass**. It does not fail a development-scope verdict (C27-C) and blocks Payroll production |
| R1-04 (34 tests) | **PASS** under the docket criterion. It proves route authorization contracts and deny cases, **not** fresh MFA |
| R1-05 (19) | **PASS**: 18 entries pinned, no person-count |
| R1-07 (45) | **PASS**: each owner's lifecycle contract; it says nothing about the three deferred integrations |
| R1-08 (8) | **PASS**: HR inputs reproduce, differences surface, no loss-of-pay money; not Payroll release |
| Snapshot preparation (C37-N1) | Not a failed regression: no test ran before `cp -al` failed on 2,158 root-owned vendor files. **Actionable tooling item (C38-N4)**: the `/full-regression` skill should use a real copy |
| DDEV sign-in at 07:53 UTC | Recorded, not attributed; the isolated project has no route to DDEV |
| Independent C37 review | Done; R-1 to R-7 corrected |

## 4. §2 preconditions

| Precondition (literal) | Position |
|---|---|
| "HR.1–HR.16 are each published, or each explicitly DEFERRED with an owner decision recorded in `HR-REFERENCE-CAPABILITY-MAP.md`" | **MET after C38-D1** (owner, 2026-11-21: the three gaps below DEFERRED by owner, recorded in the map's Gate column and the roadmap). Before that decision it was not met. Under the C16 reading (owner, 2026-11-08: "a slice is published when what it delivered is published and each unresolved row of its phase carries a gate or an owner's DEFERRED record in the map"), the four ACTIVE rows that C16 named are resolved: WO-5 (`337631b7`) and WO-6 (`26ace7b3`) shipped them (D14-08 ALT, D14-09 IMP, D8-10 ALT, D8-13 IMP), and no row is MISS. Every PART (8) and GATE (87) row carries a gate. Three gaps remained, each now deferred by C38-D1: **(a) D18-21** (retirement date, HR.12) is DEF with no gate, and its cited owner decision HR-084 is still `OWNER_DECISION_REQUIRED` (`functions.csv:775`; roadmap:678 "still open"): the DEF was an HR.0A engineering classification, which C16 did not cover. D8-14 is covered (C16's KEEP (DEF)). **(b) HR.2's "Staff 360 is live"** is not built (roadmap:151) and nowhere deferred; it is a scope item, not a map row, so the C16 reading does not see it, but the slice did not deliver it. **(c) HR.15's XDOM rows** (§6): C12 §3 did not count XDOM rows as unresolved, so §2 itself does not turn on them, but HR.15's own done-when does |
| Pack fingerprint unchanged | **MET** (C16, C36) |
| Green full regression on the baseline SHA | **MET** (C37) |

## 5. §1 timing and the twelve verifications (§3)

**§1** is met under C27-B: Organization INTEGRATED; SIS NOT BUILT with named owners (HR-050, HR-055); Finance/Payroll INTEGRATED (Payroll) + DEFERRED BY OWNER (Finance); Inventory DEFERRED BY OWNER; Transport, Timetable/Academic, IAM/security INTEGRATED; retention/audit INTEGRATED for development (E21 legal gate recorded). Nothing changed since C27's matrix except that the IAM MEDIUMs it named (C14-N5, person-level Identity) are closed for development (C29, C34).

| # | Result | Evidence and reason |
|---|---|---|
| R1-01 | **PASS** (development, HR family) | C26 enforcement, `HrWriterBoundaryTest`, `hr_family_runtime_privileges`, `hr_family_trigger_functions` (verifier failed=0 today) |
| R1-02 | **PASS with a recorded Architecture exception (C38-D3)**; was FINDING C38-N2 | Payroll: owner-approved exception and readers (D-2, WO-3, rule 150). Remaining: 24 files outside `app/Domain/HR`, `app/Domain/Payroll` and HR's and Payroll's own web controllers import `HR\Infrastructure` (C27 §6's count, reproduced today). Of these, 2 are HR-family code (`TaskAssignmentService`, `LeavePageSupport`: C27 "family, not cross-domain"), 3 are Documents' approved Employee owner-type shape (HR.R1-C10 port), and 6 are TeachingAssignments', whose XRP-3 deferral (D-C35-7) covers the `limit(1000)` lists, not the imports. **13 had neither a reader migration nor a recorded exception**, which C12 §15 requires: Timetable 4, Transport 4, Visitor 4, Attendance 1; TeachingAssignments' 6 imports likewise, beyond XRP-3's list deferral. C38-D3 records an owner-approved Architecture exception for these 19. R1-02's literal text allows an exception only for the Payroll conjunct; reading the first two conjuncts ("only through an Application reader", "X-1 is closed") as met by an owner-approved exception is the owner's Architecture reading, adopted with C38-D3 (whose option stated that R1-02 and R1-12 become PASS with a recorded residual), as C27 adopted its readings. All are reads, none writes (C26 guards); separated staff are refused at write time and pickers use `DutyCandidateReader` (HR.15A). C38-N2: LOW |
| R1-03 | **PASS** (development) | Assignment, terms, category, manager (HR.1A, HR.3A), calendar and policy (WO-5, rule 149, NOT_RECORDED before a School's history start), entitlement on the ledger as posted. Shift: no shift exists (HR.4b GATE) |
| R1-04 | **PASS** | C37; no HR export exists, so export authority is vacuous; MFA is separate (rule 95 tests). *Clarification (HR.R1-C39, 2026-11-21): true for HR, Leave, Staff Attendance and Tasks only; the Payroll statutory exports (7 routes) exist, carry capability middleware or rule 98 coverage and deny tests, and were re-run in C39 (`HR-R1-C39-BOUNDED-RERUN.md` §3.3)* |
| R1-05 | **PASS** | C37 |
| R1-06 | **FINDING (C38-N1), LOW (C38-D2); correction WO-26** | See below |
| R1-07 | **PASS** | C37; shifts do not exist |
| R1-08 | **PASS** | C37 |
| R1-09 | **PASS for development under C27-A** | Transport, Timetable (entries), Leave, Tasks, Identity answer; Library, Inventory, Finance NOT_INTEGRATED (§7) |
| R1-10 | **PASS** | Gate register unchanged since `eb3d6dde`; HRX-L1–L4, ADR 0036, ADR 0040, D-11 refused; guard tests green in C37 |
| R1-11 | **PASS** (development) | `TenantClosureReadinessTest::the_catalog_classifies_exactly_the_live_tenant_tables` and `an_unclassified_tenant_table_fails_closed`; HR and Payroll purge and hold tests green in C37. E21 ratification is Legal's |
| R1-12 | **PASS with recorded residuals (after C38-D3)**; was FINDING | S-1–S-3, S-5–S-16, X-2, X-3 FIXED or CLOSED for development; S-4 latent, GATE before any statutory grant (owner decision D-RF2-21 via GOV-2). X-1's behaviour is fixed (HR.12 write paths, HR.15A pickers), but its rule 4 remainder is neither fixed nor a residual with an owner decision: "DECISION (HR.1A): not changed" was an engineering decision about behaviour, and D-2 decided Payroll only. C38-D3 records it as an owner-approved residual |

### R1-06 in detail (C38-N1)

**The contract:** "Raw-SQL / RLS tests for every HR table, report and export", by the `RawIsolationTest` pattern (rule 28: School A's rows invisible and unwritable from School B, and no context fails closed, at the raw layer as the runtime role). C12 recorded it as "Done for ten tables; the others not re-audited" (docket :537). No later checkpoint re-audited it, and RF1/RF2 did not carry it as a finding (C38-N3).

An audit of the test suite at `5dcc35ef` against the 57 HR-family tables (`DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES`) found:

| Evidence | Tables |
|---|---|
| Full raw cross-School test (positive control, B reads 0, no context 0, foreign write refused) | **33** (HrRawIsolationTest's 14, HrSeparationAndTaskRawIsolationTest's 10, CredentialVerificationRawRoleTest, LeaveRlsIsolationTest's types, eligibility, requests, staff attendance records and corrections) |
| Raw test missing at least one leg (no-context, a cross-School write, or a write leg run on `pgsql_admin`) | **14** (counters, reporting lines, terms, reference codes, both account-link tables, four Leave tables, year start changes, the two month-lock tables, working-week versions) |
| No behavioural test: named in a loop whose fixture creates no row (`LeaveRlsIsolationTest::raw_sql_sees_only…`, `StaffCalendarRawRoleTest`), so the zero assertion cannot fail; or in no isolation test at all (`employee_placement_changes`) | **10**: `leave_settings`, `leave_allocation_runs`, `leave_year_closes`, `leave_year_close_items`, `leave_year_close_reconciliations`, `leave_accrual_runs`, `leave_accrual_run_items`, `staff_holidays`, `staff_working_weekdays`, `employee_placement_changes` (named in non-isolation tests and a comment, `ElevationRlsIsolationTest:118`, never in an isolation test) |
| Reports | 4 of the 10 rendered reports have a meaningful cross-School HTTP test (`HrReportsTest`); 6 do not (the other School has no foreign leave, absence, correction or task rows); none is tested with RLS as the only barrier. The 8 governed pages rely on their own modules' tests |
| Exports | None exist (`HrReportRegistry`, `HrReportController`: browser-only, rule 140); vacuously met, not pinned by a test. *Clarification (HR.R1-C39, 2026-11-21): this inventory was incomplete. Payroll's statutory exports exist; WO-26 proved their School isolation and C39 re-ran it (`HR-R1-C39-BOUNDED-RERUN.md` §3.3). The original statement is kept as reported* |

**Is anything actually exposed? No evidence of it.** Read today from the selected database: **all 57 tables** have RLS enabled and forced and exactly one policy, permissive, for all commands, to PUBLIC, with USING and WITH CHECK both exactly `school_id = NULLIF(current_setting('app.current_school_id', true), '')::uuid`, the `TenantRls::enable()` expression; `school_os_app` is neither superuser nor BYPASSRLS, owns no table and is a member of no role (verifier). Given that catalogue state, PostgreSQL itself isolates these rows. **The gap is regression evidence**: the verifier pins forced RLS but not the policy text, so a future change to one of these 24 tables' policies would not be caught by a behavioural test.

**Result: FINDING.** Proposed severity **LOW** (P3): a missing mandatory test layer with the protection itself demonstrated in the catalogue. The RF2 precedent for raising a severity (ADR 0076 §64, D-RF2-1) concerned a missing structural backstop with a reproduced bypass, which this is not. **The alternative is real:** rule 28 calls these tests mandatory, and if the owner rates C38-N1 MEDIUM it is MAJOR under C27-C and the verdict is BLOCKED until WO-26 publishes. **Correction:** one test-only unit (proposed WO-26): a data-provider raw-isolation test over all 57 family tables, rows created through the real services, positive control first, policy expression asserted (the HrSeparationAndTask pattern; `staff_working_weekdays` takes no runtime write, so an admin-seeded row or a recorded exemption); cross-School tests with real foreign rows for the 6 reports; optionally a pin that no HR export route exists. Publishing WO-26 on or after 2026-11-29 needs an E16 successor first.

## 6. HR.1–HR.16

Requirement text is the roadmap's (`HR-EXPANSION-ROADMAP.md`), quoted: heading, "Scope" and "Done when". "Status" applies the C16 reading. Phase rows are the map rows whose Phase names the slice; every unresolved row's treatment is in the map's Gate column. Owners: HR unless stated. Code at `5dcc35ef`; tests ran in C37 (HR 138, Leave 34, Staff Attendance 13, WorkforceTasks 6, Payroll 70 test files).

| Id | Requirement (verbatim) | Delivered and evidence | Unresolved and its treatment | Status | Impact: closure / UAT / staging / production | Re-review trigger |
|---|---|---|---|---|---|---|
| HR.1 | "Workforce foundation". Scope (list items joined): "D1-02/07/11/12; D2-01…06, D2-10…13; D18-05…08; S-8, S-9, S-10 (employment), S-11, S-12 (`employment_type`), S-15; ORG10-D01." Done when: "the D2 rows are IMP; X-3 and S-8…S-15 (as listed) are closed; full regression green" | HR.1A `ab4459af` (rule 130): `EmploymentTermsService`, `PlacementChangeService`, overlap triggers, `WorkforceAsOfReader`; X-3, S-8–S-11, S-15 FIXED; HR.R1-C2/C9 lifecycle corrections. Tests: `WorkforceDatabaseInvariantsTest`, `WorkforceLifecycleIntegrityTest`, `EmploymentRecordTest` | D1-07 probation DEF (C16, HR-090) | **Published** | None / walkthrough pending / — / Legal gates | HR-090 answer |
| HR.2 | "Staff profile & records". Scope (abridged): "D1-03 …; D1-17/19/26/27/29; S-3, S-16 (profile), S-12 (document category); a stale-form token on profile edits; Staff 360 (each owner's summary under its own capability, rule 118 pattern)". Done when: "S-3, S-12 (documents) and S-16 (profile) are closed; Staff 360 is live" | HR.2A `1e4d6ad3` (rule 132), HR.R1-C10/C14/C29 (documents API, credential decisions); S-3, S-12, S-16 closed. Tests: `EmployeeDocumentTest`, `HighlySensitiveHrDocumentWriteTest`, `CredentialVerificationRawRoleTest`, profile tests | **Staff 360 summaries (Leave, StaffAttendance, Payroll) not built** (roadmap:151); undeferred until C38-D1 deferred them (recorded in the roadmap only, as Staff 360 has no map row); gated families (D1-18 HR-L6, D1-20 HR-L5, D1-25/35 ADR 0036) | **Published; Staff 360 DEFERRED by owner (C38-D1)** | None / walkthrough pending / — / HR-L5, HR-L6, ADR 0036 | A Staff 360 contract (rule 118 pattern) |
| HR.3 | "Reporting hierarchy". Scope: "D4-04/05/07/11/12; D2-05; D20-10; S-10 (reporting); S-14 (G-9 deadlock); delegation only if amended." Done when: "D4 has no MISS / PART except DEF; full regression green" | HR.3A `2c0a873e` (rule 131), C9 lock order. Tests: `ReportingDatabaseInvariantsTest`, reporting concurrency tests | D4-07 dotted line DEF (C16); D4-08 DEF (ADR 0065 §6) | **Published** | None / pending / — / — | A dotted-line need; a §6 amendment |
| HR.4 | "Work calendar & shifts". Scope: "D5 (all); D14-02…09/12; D2-07". Done when: "the D5 rows are IMP; Leave and attendance read the WorkSchedule" | HR.4a = WO-5 `337631b7` (rule 149, D-6): calendar history, D14-08/09, S-16. Tests: `StaffCalendarRawRoleTest`, calendar history tests | HR.4b shifts: 15 GATE (HRX-L2), 8 DEF (D-1 Option B, C16 2026-11-08) | **Published (4a); 4b held by gate** | None / C18-A12 screen reader on WO-5 pages / — / HRX-L2 | OD-HR-14 / L2a |
| HR.5 | "Attendance events". Scope: "D6-07…21". Done when: "events are immutable, idempotent and tenant-isolated" | Nothing built, by decision | 15 GATE, HELD (C16) | **Gated, recorded** | None / none / — / HRX-L2 | OD-HR-14 |
| HR.6 | "Attendance evaluation". Scope: "D7-07…13/16". Done when: "the D7 rows are IMP or GATE (HRX-L4 only)" | Nothing built, by decision | 7 GATE (HELD), D7-16 KEEP (ALT) | **Gated, recorded** | None / none / — / HRX-L2, L4 | OD-HR-14 |
| HR.7 | "Attendance operations". Scope: "D8-03/04/10/13/14/15; S-6". Done when: "D8-10 is IMP; S-6 is closed" | HR.7a = WO-6 `26ace7b3` (rule 147, S-6 decided C17): month lock, assured locked corrections; C20 acceptance. S-6 closed. Literal "D8-10 is IMP" is not met: D8-10 is ALT, because S-6 decided "no reopen" (C17) | D8-03/04 DEF (C16, HR-011), D8-06 DEF (§7), D8-14 KEEP (DEF) (C16, HR-004) | **Published (7a, as an accepted alternative); 7b deferred** | None / C18-A12 / — / — | HR-011, HR-004, §7 amendment |
| HR.8 | "Biometric / FR / GPS". Scope: "D6-01/03/04/05; D9 (all); D20-19/27/28/38". Done when: "gated rows are opened only by the recorded decision" | Nothing built; gates refused (R1-10) | 24 GATE (HRX-L2b) | **Gated, recorded; its own done-when holds** | None / none / — / HRX-L2b | A Legal/Security determination |
| HR.9 | "Leave policy". Scope: "D10-03/09/10/11/13/14/15/16/20; D12-04/05; D14-11". Done when: "D10 has no MISS except DEF / GATE" | `f82a75d0` (rule 133, ADR 0065 §28); C11 leave-type freeze. Tests: `LeaveRlsIsolationTest` (eligibility), eligibility tests | D10-13/14/15, D14-11 DEF and D10-16 PART (OD-HR-15); D10-07/08 DEF (HR-L8) | **Published** | None / pending / — / HR-L8 | OD-HR-15, HR-L8 |
| HR.10 | "Leave ledger & accrual". Scope: "D10-05/19; D11-01/03/06/16; D12-01/03/08; S-7". Done when: "D11 and D12 have no MISS / PART" | `6886247e` (rule 134), WO-19 `3786968e` (S-7 closed, D-4), WO-9 `63015a50` (D-3 accrual status) | D11-06 PART (OD-HR-16); D10-19, D12-03 PART and D12-08 DEF (OD-HR-17). Literal done-when not met; met under the C16 reading | **Published** | None / failed-accrual fixture "not configured" / — / — | OD-HR-16, OD-HR-17 |
| HR.11 | "Leave, comp-off & permission workflows". Scope: "D15, D16, D17; D13-15…17/20; D11-09/10". Done when: "D15–D17 are IMP or GATE" | `60da2011` (rule 135, OD-HR-5 `line_manager`) | Comp-off DEF (OD-HR-18), permissions DEF (OD-HR-19), windows DEF (OD-HR-20), all owner 2026-11-04; D16-03/04/11 GATE (L2a); D13-15…17 DEF (§6 kept) | **Published in part; remainder owner-deferred** | None / manager-inbox walkthrough pending / — / — | Each OD |
| HR.12 | "Workforce lifecycle". Scope: "D18-03/04/10…13/15…17/20/21; D1-07, D1-33". Done when: "D18 has no MISS / PART except DEF" | `d5ea8a93` (rule 137, HR-061), WO-20 `338785ba`, WO-21 `f03aa6aa`, WO-24 `e7d7ee02`. Tests: `SeparationPersonLevelAccessTest`, `SeparationObligationTruthTest`, separation suite (120 tests) | D18-03/04, D1-07 DEF (C16); D18-21 DEFERRED by owner (C38-D1; HR-084 still open); D1-33 DEF (HR-L9); D18-15 PART → §7 | **Published** | None / off-boarding needs the UAT admin's MFA / — / — | HR-090, HR-084, the three integrations |
| HR.13 | "Staff tasks". Scope: "D19 (all), D20-26". Done when: "the D19 rows are IMP" | `7e05f8d6` (rule 139), WO-22 `5b78154c`, WO-23 `d11a4537`. Tests: WorkforceTasks suite (59 tests) | D19-07/14/15/16 DEF (C16); D19-19 DEF (HR.13 decisions: no attachments); D20-26 PART (ADR 0040) | **Published** | None / pending / — / ADR 0040 | A task-relationship contract |
| HR.14 | "Reports / MIS". Scope: "D20-02/06/11/23/33; D8-15; D9-15; D11-16". Done when: "every non-gated D20 row is IMP, XDOM or DEF" | `13cc4a15` (rule 140); R1-05 PASS | D9-17 GATE (D-11); D20-11 PART (ADR 0040) | **Published** (R1-06 report evidence: C38-N1) | N1 / pending / — / D-11, ADR 0040 | — |
| HR.15 | "Cross-ERP integrations". Scope: "Payroll integrity slice: S-1, S-2, S-4, S-5, S-13 …; X-1 (Timetable, Transport, Visitor via HR readers); the Payroll `EmploymentRecord` coupling decision; D20-18/29/30/31/34/37". Done when: "every XDOM row has an owner contract or a recorded deferral" | `8cfacc40` (rule 138), OD-HR-1/2 `3254e1a0` and C1–C8 (rules 142–146), D-2/WO-3 `ede8af12` (rule 150). XDOM rows against the done-when: D20-34 Payroll (OD-HR-1/2: owner contract) and D20-30 Inventory (deferred 2026-11-04) **hold**; D20-29 and D20-37 rest on HR-050 and HR-055, still `OWNER_DECISION_REQUIRED` (roadmap:590 lists them as HR.15 prerequisites), held only by E47; D20-18 and D20-31 (Timetable substitution) carried only HR-054's `BUILD_POST_V1` backlog label, and D1-34 (staff joining deposit) no deferral (the 2026-11-04 deferral names "staff dues", gate register:432), until C38-D1 recorded owner deferrals for these five | HR.15B/C/D deferred (owner 2026-11-04); D7-15 PART (HRX-L4); D1-25/35 GATE; S-4 GATE. **The X-1 reader rewiring in its scope is not done beyond the pickers** (C38-N2) | **Published in part; the five XDOM rows DEFERRED by owner (C38-D1), so its done-when now holds; X-1 rewiring recorded as an exception (C38-D3)** | None / — / — / HRX-L4, E47, ADR 0036 | Each row's trigger in the map; C38-D3's triggers |
| HR.16 | "Security / performance / closure". Scope (list items joined): "independent security review; query budgets on every HR page; load test with 1,000 staff (local); documentation drift (`HR.md`, HRX closure count of `staff_self_service` keys); the capability map refreshed." Done when: "HR implementation-complete. HR is not closed until HR.R1" | `81ebd080` (rule 141), `HR-16-READINESS.md`, C28 verifier (`bf627ae4`) | — | **Published** | None / — / staging performance (PAY-12, D-7) / — | — |

**Three items lacked an owner treatment until C38-D1 deferred them (2026-11-21):** D18-21 (HR.12), Staff 360 (HR.2) and five HR.15 XDOM rows (D20-18, D20-29, D20-31, D20-37, D1-34). Every HR.1–HR.16 item now has a treatment: published HR.1, HR.3, HR.9, HR.10, HR.13, HR.14, HR.16, and the published parts of HR.2, HR.4 (4a), HR.7 (7a, as ALT), HR.11, HR.12 and HR.15; gated or deferred by recorded decision HR.4b, HR.5, HR.6, HR.7b, HR.8, HR.11's comp-off, permissions and windows, HR.15B–D. Literal done-whens not met but satisfied under the C16 reading: HR.4, HR.7 (D8-10 ALT), HR.10, HR.13. The only deferrals added are the owner's C38-D1 records. The findings C38-N1 and C38-N2 touch HR.14's and HR.15's evidence, not their map rows.

## 7. Cross-domain clearance (R1-09, R-HR12-2)

| Domain | HR-owned contract | External contract | Integration | Visible state | Deferral | Closure effect |
|---|---|---|---|---|---|---|
| Inventory | Rule 137: ask each owner through `SeparationObligationSource`; never write it | Custody contract (D20-30) | **Not integrated**: `SeparationObligations::NOT_INTEGRATED` lists it | NOT_INTEGRATED on every case: never CLEAR, never blocking (`blocking()` counts OPEN and UNAVAILABLE only, `SeparationObligations.php:89-92`) | HR.15B, owner 2026-11-04 (R-HR12-2; HR.15 decisions) | Permitted for development by C27-A and C27-C; recorded residual |
| Library | Same | Staff borrowing (none) | **Not integrated** | NOT_INTEGRATED | HR.15D, owner 2026-11-04 | Same |
| Finance | Same; R1-08 covers Payroll HR inputs only | Staff dues and joining deposit (D1-34) | **Not integrated** | NOT_INTEGRATED | HR.15C, owner 2026-11-04 | Same |

Schools record an administrative clearance item instead (R-HR12-2). **None of the three is integrated, and none is shown CLEAR** (`SeparationObligationTruthTest`). They are required for "permanently closed" (C27 §10), not for a development-scope verdict.

## 8. Findings

**Development MAJOR inventory.** Every MAJOR-class item named since C27 is closed for development by a published unit, each in the selected source:

| Item | Unit |
|---|---|
| C14-N2 | C30 `8eda4cc7` |
| C14-N5 | C29 `3ad20770` |
| LVA-1/TRR-2 (D-3) | C31 `63015a50` |
| Person-level Identity | C34 `6b881295` |
| C34-N1 | WO-17 `023c57f8` |
| WO17-N1 | `f58b2eeb` |
| WO22-N1, RF1-N1, WO23-N1 | WO-23 `d11a4537` (evidence `5b1cf382`) |
| WO20-N1, RF1-N2 (+ WO20-N7, WO18-N1) | WO-24 `e7d7ee02` |

**No development HIGH or MEDIUM is open** (C38-N1 rated LOW by the owner, C38-D2; C38-N2 recorded as an exception, C38-D3). R-HR12-2 (MEDIUM) is owner-deferred; GOV-8, GOV-9, PAY-12 (P2) are release and staging items recorded under C27-C.

**RF2:** 81 rows, 81 distinct ids, file unchanged since its own publication `33166cd3`, so its 8 CORRECTION rows still read as decided-not-built there; their execution is recorded in the WO-23, WO-24 and WO-25 records and the closure contract's evidence log (CORRECTION 8, VERIFIED CLOSED 1, ACCEPTED ALTERNATIVE 38, DEFERRED 2, HANDOFF 12, GATE 13, OPERATIONAL 7). Not every row is development-closed: the GATE, HANDOFF and OPERATIONAL rows stay open in their own lanes.

**C36 §1:** WO24-N1, WO24-N2 (rule 102), WO24-N3, WO25-N1, WO25-N2 (both routed to Platform): intact, accepted, with their triggers.

**New in C38:**

| Id | Proposed | Finding | Proposed treatment |
|---|---|---|---|
| C38-N1 | **LOW (owner, C38-D2)** | R1-06 evidence incomplete (§5) | **WO-26, test-only (the correction).** **CLOSED for development by WO-26 (`e33a1e1e`, 2026-11-21, `HR-R1-C35-WO26-RLS-ISOLATION.md`)**: all 57 tables, the 10 rendered reports and the export routes proven; the bounded re-run is still due |
| C38-N2 | LOW | R1-02 remainder: read-only imports of HR Infrastructure without a migration or recorded exception (§5) | **Recorded Architecture exception (owner, C38-D3)**; machine-pinned by WO-26 (`HrCrossDomainImportRegisterTest`) |
| C38-N3 | INFO | RF1 and RF2 did not carry C12's "R1-06 not re-audited" note forward, so it reached the final assessment undecided | Recorded here |
| C38-N4 | LOW (tooling; C37-N1 was INFO, raised here because it will recur on every snapshot run) | `/full-regression`'s hard-link snapshot fails on root-owned vendor files (C37-N1) | Platform tooling; not a product defect |

## 9. Registers

- **Capability map:** 361 rows, 361 distinct ids: IMP 163, GATE 87, DEF 68, ALT 22, PART 8, XDOM 7, NR 6, MISS 0. Statuses and totals unchanged since C36's blob; nothing reclassified. Six Gate-column annotations record C38-D1, with a C38 entry in the map's §5 change log.
- **Audit register:** 61 distinct identifiers (GOV-1–11, HRC-1–12, LVA-1–9, PAY-1–12, TRR-1–10, XRP-1–7); 78 table rows because 17 ids reappear in a later status table; none missing, none renumbered. Their dispositions are RF2's and the C-records'; GOV-1 (umbrella) closes with the verdict.
- **Traceability:** HR.R1-0 audit → C1–C15 corrections → decisions D-1 (C16), S-6 (C17), D-6 (C21), D-2 (C23), R1-01 (C25), C27 readings → WO-3, 5, 6, 9, 11–13, 16–25 → RF1 (`706ec9c3`), RF2 (`33166cd3`) → WO-23, WO-24, WO-25 → DDEV 402 (`58ae0236`) → UAT0 (`eb3d6dde`) → C36 (`2c924e37`) → C37 (`4e505c49`) → C38. Every implementation unit is an ancestor of `5dcc35ef`.

## 10. Identity, gates and handoffs (unchanged; nothing decided here)

- **RG-ID-1:** OPEN. Development known-same-person enforcement (rule 152) does not satisfy it; the synthetic School is never an assertion.
- **C33-H1–H5** (Fees, Payments, Communications, Examinations, Erasure): unchanged handoffs.
- **R1-02:** PARTIAL until this assessment; now PASS with the owner-approved Architecture exception C38-D3 (§13). The exception is not a reader migration.
- **ESI-12 / E45:** deferred skip; blocks Payroll production.
- **Legal/DPO:** E21 (incl. D9), E45, E47, HRX-L1–L4, S-4, ADR 0036, ADR 0040, D-11, D-10: shut. A development verdict is not a Legal or DPO determination. Real data, real Payroll, staging and production remain refused.
- **M-1:** production policy prerequisite (D-RF2-21).

## 11. UAT and release (separate from this verdict)

- **Browser UAT:** not executed, not signed off. `UAT-HR-SYN` is prepared (UAT0); page loads are not UAT.
- **Accessibility:** axe and keyboard not run; manual screen reader (C18-A12, WO-5 pages) needs a named human reviewer; C20-N1 handed off.
- **School administrator MFA:** 0 factors; enrolment is the owner's UAT prerequisite. Nothing was enrolled or bypassed.
- **Fixture limits:** as UAT0 records (legacy kept-access fixture, failed accrual run, reporting lines, pending leave, Payroll runs: "not configured" or to be created by UAT).
- **D-RF2-23** makes UAT a sign-off prerequisite, not a C36 or development-verdict prerequisite; the contract's §4 verdict concerns the §3 verifications and findings. UAT therefore does not enter the development verdict, and the verdict says nothing about UAT.
- **Staging:** PAUSED. **Production:** NO-GO. No deployment instruction is given.

## 12. Independent review

A fresh reviewer read the draft and the sources, read only (no DDEV, no pack). It found the proposed verdict well-founded and the following, all resolved in this record:

| # | Rating | Finding | Resolution |
|---|---|---|---|
| M-1 | MEDIUM | "Every DEF row carries an owner deferral" was false for D18-21 (HR-084 open) | §4, §6: gap (a), put to the owner (C38-D1) |
| M-2 | MEDIUM | HR.15's done-when and "7 XDOM rows each name an owner contract" were unsupported (HR-050/055 open; HR-054 a backlog label; D1-34 not deferred) | §4, §6: gap (c), C38-D1 |
| M-3 | MEDIUM | "No item lacks a treatment" was false: HR.2's Staff 360 neither built nor deferred; other unmet literal done-whens unflagged; quotes not verbatim | §4, §6: gap (b), C38-D1; HR.1 and HR.3 quoted exactly, HR.2 marked abridged; HR.4, 7, 10, 13 flagged |
| M-4 | MEDIUM | §13 attributed a bounded re-run and "not development-closed" to §4, which says "HR.R1 is re-run" and makes only BLOCKER/MAJOR "not closed" | §13: both put to the owner as proposals (C38-D4) |
| L-5 | LOW | R1-12 PASS unsupported for X-1's remainder | R1-12 made a FINDING with C38-N2; after C38-D3, PASS with a recorded residual |
| L-6 | LOW | The 24 files include HR-family and Documents' approved shape | R1-02 split: 13 unrecorded (+ TeachingAssignments' 6 imports) |
| L-7 | LOW | 17 (not 10) reappearing ids; RF2 last changed at `33166cd3`; WO-23 evidence `5b1cf382`; a comment mention; C38-N4's re-rating unstated | Corrected |
| L-8 | LOW | The correction path omitted the E16 deadline | §5, §13 |
| INFO | — | C38-N1 at LOW is defensible, not certain; the MEDIUM alternative should be stated | §5 states it; C38-D2 |

Spot checks it confirmed: the Leave loop names `leave_settings` and `leave_accrual_runs` with no fixture rows; the calendar test counts holidays without creating one; no verifier code reads `pg_policies`. It confirmed the CI runs, the ruleset, the six-document diff, the counts and the cross-domain states. Unverifiable to it: the DDEV catalogue reads and the pack (read by this record, §2, §5).

**Final-state review** (a second fresh reviewer, read only, after the owner's decisions): no new MEDIUM or higher. It confirmed every earlier finding resolved, the exception list (24 importers reproduced; the 19 excepted import `Employee` only), the six map edits (Gate column only; all 361 rows parse, totals identical), the triggers against §13, and consistent verdict wording, decision ids and re-run reading across the four documents. Its LOWs were stale pre-decision sentences, an abridged HR.16 quote, an unlabelled Architecture reading of R1-02's first two conjuncts, Staff 360's roadmap-only recording, the map blob and a missing map change-log entry, and the report count wording in the contract. All are corrected; the R1-02 reading is labelled as adopted with C38-D3, and the baseline note is marked as this record's reading. This is a review, not the owner's approval.

## 13. Owner decisions and verdict

Decided by the HR owner, also acting as Architecture as at C27-D, in the C38 decision dialog on 2026-11-21. The repository owner answered four questions, each framed as that role's decision; the record names the role, not a person.

| Id | Question | Decision |
|---|---|---|
| **C38-D1** | §2's first precondition, gaps (a)–(c) | **DEFERRED by owner, every item, each with a trigger.** D18-21: when HR-084 is answered. Staff 360: a Staff 360 contract under rule 118's pattern. D20-18, D20-31: a Timetable substitution contract (HR-054). D20-29: HR-050 decided and an HR ↔ SIS contract (E47 still applies). D20-37: HR-055 decided and a Fees contract (E47). D1-34: a Finance staff-obligation contract (HR.15C). Recorded in the map's Gate column (status unchanged) for the six rows, and in the roadmap; Staff 360 has no map row, so its deferral is recorded in the roadmap (HR.2) and here only. Nothing is built |
| **C38-D2** | C38-N1's severity | **LOW**; corrected by **WO-26**, test-only (§5's scope) |
| **C38-D3** | C38-N2 | **A recorded Architecture exception** (the D-2 pattern), below |
| **C38-D4** | Verdict and §4's "HR.R1 is re-run" | **PASS WITH CORRECTION — development scope; production not addressed**, with the **bounded re-run** reading |

**C38-D3, the exception.** These read-only `HR\Infrastructure` imports stay as they are at `5dcc35ef`:
- Timetable (4): `Timetable/Application/TimetableScheduleService`, `Timetable/Http/Controllers/TimetableEntryController`, `Timetable/Infrastructure/TimetableEntry`, `Http/Controllers/App/Timetable/TimetableEntryController`;
- Transport (4): `Transport/Application/TransportRouteAssignmentService`, `Transport/Http/Controllers/TransportRouteAssignmentController`, `Transport/Infrastructure/TransportRouteAssignment`, `Http/Controllers/App/TransportOperationsController`;
- Visitor (4): `Visitor/Application/VisitorVisitService`, `Visitor/Http/Controllers/VisitorVisitController`, `Visitor/Infrastructure/VisitorVisit`, `Http/Controllers/App/VisitorVisitController`;
- Attendance (1): `Attendance/Infrastructure/AttendanceSession`;
- TeachingAssignments (6): both services, both models, both App controllers.

All import `Employee` only. Not in the exception because they need none: Documents' 3 (the HR.R1-C10 port) and the HR-family `TaskAssignmentService` and `LeavePageSupport` (`EmploymentRecord`). **Conditions:** reads only, never a write (C26's guards); write-time eligibility stays `EmploymentCoverage::hold()` and pickers stay `DutyCandidateReader` (rule 138). **Re-review** on any new importer, any write through these imports, or the owning domain's next checkpoint. XRP-3 and C10-N3 keep their own deferrals. **Enforcement:** recorded, not yet machine-pinned; a register test (the `PayrollHrBoundaryRegisterTest` pattern) is test-only and may join WO-26 if the owner includes it when authorizing that unit.

**C38-D4, what the verdict means.**
- **Not BLOCKED:** no BLOCKER or MAJOR is open under C27-C (C38-N1 rated LOW; C38-N2 recorded).
- **Not PASS:** R1-06 is a FINDING whose correction is a unit (WO-26), not a decision (C27 §7).
- **Development closure:** HR.R1 counts as development-closed only once WO-26 publishes and HR.R1 is re-run. **The re-run is bounded:** R1-06, plus any verification the correction touches, on the corrected source with its green full suite. §2, the other verifications and the baselines are not re-assessed unless the corrected source changes them.
- **Not:** UAT, staging or production acceptance; any Legal or DPO determination; "permanently closed" (§1), which also needs production, the Legal gates, the three integrations and the manual accessibility review (C27 §10).
- WO-26 and the re-run each need their own authorization. Either published on or after 2026-11-29 needs an E16 successor first.

**Baseline note (this record's reading, not one of C38-D1–D4).** The C38-D1 annotations change the capability map file (Gate column only; 361 rows, every status and total unchanged). The C36 reference-pack baseline is the pack fingerprint `40ed6a83…`, which is untouched; the map blob C36 recorded as its mapped scope (`c4f33615`) is now `a9d4c0fc`, carrying six owner annotations and a change-log entry. Because no status or scope changes, this record reads it as not a new selection; the owner may say otherwise. The Legal/Security baseline blobs are not edited.

**HR.R1 status:** OPEN. Development scope: PASS WITH CORRECTION, pending WO-26 and the bounded re-run.
