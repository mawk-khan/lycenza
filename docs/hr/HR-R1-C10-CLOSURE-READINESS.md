# HR.R1 closure readiness (HR.R1-C10, 2026-11-07)

**This is not the closure audit, and nothing here is an approval.** Recommendations are
proposals. Every decision is **pending** until its named owner records it. Baselines:
`HR-R1-CLOSURE-CONTRACT.md` is the authority, `HR-R1-0-AUDIT-REGISTER.md` holds the
findings, and `HR-R1-C10-ARCHITECTURE-INVENTORY.md` and `HR-R1-C10-EVIDENCE-INVENTORY.md`
hold the evidence with file and line.

## 1. Four readiness levels

| Level | Meaning | State |
|---|---|---|
| Development complete | Authorized code and tests exist | Yes for what was authorized, with the open defects in §4 |
| Architecture closure eligible | Ownership, controls, decisions and evidence suffice for the defined HR.R1 scope | **No** |
| Staging eligible | Application and environment each meet their prerequisites | **No:** no environment, no access evidence, paused by the owner on 2026-10-17 |
| Production ready | Every technical, legal, security, deployment and operational gate | **No: NO-GO** |

## 2. Closure matrix

| Criterion | State | Remaining |
|---|---|---|
| §1 ERP connections | Not satisfiable literally | Owner reading, §6 below |
| §2 slices | **Open** | D-1 |
| §2 fingerprint | **Owner-held** | The pack is not on the development machine's workspace or in Git. Recorded `40ed6a83…1ac3`, 602 PNG, but the byte convention is not fully recorded. Never recomputed here |
| §2 baseline | **Proposed**, §7 | Owner selection |
| R1-01 one writer | **Open** | No duplicate or foreign writer found. The criterion needs a definition (inventory A §1.8) and pins |
| R1-02 integration | **Open** | 62 Payroll use sites, 9 foreign keys, 8 SQL functions, no approved exception (D-2). Non-Payroll reads remain (X-1, XRP-3) |
| R1-03 temporal | **Open** | Calendar has no as-of answer, policy has no public reader (D-6) |
| R1-04 authorization | Recorded | Re-run on the baseline. It would not have caught HRC-3: it proves a contract, never assurance |
| R1-05 reports | Recorded | Re-run on the baseline; D-9 |
| R1-06 isolation | **Done for the ten tables** | The other tables are named in raw tests by name; per-table semantics were not re-audited |
| R1-07 lifecycle | Recorded | Shifts absent by design, dependent on D-1; XRP-4, TRR-8 |
| R1-08 reconciliation | Recorded, limited | The snapshot is sound. "Reproduce from sources" fails for a past run once the calendar changed (D-6) |
| R1-09 offboarding | **Open** | Library, Inventory and Finance stay NOT_INTEGRATED, and substitution does not exist. Owner reading, §6 |
| R1-10 gates | Intact | Refused by absence or guard tests, never opened |
| R1-11 retention | Mapped | E21 stays unratified |
| R1-12 security | **Open** | S-4, S-6 owner record, S-7, S-16, X-1 |

## 3. Security register

| Finding | State | Owner | Required |
|---|---|---|---|
| S-3 | **Reopened and re-closed by C10:** it was marked FIXED, but the generic Documents routes had no refusal | HR | None |
| S-4 | Open, latent: no MFA or no-store on statutory reveal and exports. No production role holds the key | Payroll | `mfa` and `private-no-store` before any statutory grant |
| S-6 | **No owner determination found.** The "recorded decision" traces to `APPROVAL-AUTHORITY-MATRIX.md:48`, an audit classification about approval only, carried without its citation. OD-8.6 concerns Student Attendance. Nothing here treats it as approved | HR owner | A real record, or HR.7 |
| S-7 | Partial: adjustments and openings FIXED; allocation, runs and the cached check open | Security | D-4 |
| S-16 | Open for `staff_holidays` | HR owner | D-6 |
| X-1 | Write paths and pickers FIXED. Rule 4 drift remains in Timetable, Transport and Visitor controllers | Architecture | With D-2 |
| M-1 | Production prerequisite: an unlinked staff parent is not detected | Owner policy | Before production |
| Same-person identity | **OPEN, MEDIUM.** Two accounts do not prove two people, and two HR holders can collude | Identity and security owners | A decision before real Payroll |

## 4. Blockers

"Continue" says whether other work can proceed around it.

| Item | Requirement | Owner | Gap | Sev | Prerequisite | Next action | Continue | Affects |
|---|---|---|---|---|---|---|---|---|
| D-1 | §2 record | HR owner | No DEFERRED record | Admin | None | Decide, §5 | Yes | Architecture |
| Fingerprint | §2 | Owner | Owner-held pack | Admin | Owner's machine | Recompute, record convention | Yes | Architecture |
| D-2 | R1-02 | Architecture | No exception | P3 | None | Decide, §5 | Yes | Architecture |
| R1-01 | One writer | Architecture | No definition or pins | P3 | Definition | Adopt, then pin | Yes | Architecture |
| D-3 | LVA-1 | HR owner | Choice | P2 | None | Decide, §5 | Yes | Architecture, operations |
| D-4 | S-7 | Security | Choice | P3 | None | Decide, §5 | Yes | Architecture, production |
| D-6 | R1-03, S-16 | HR owner | Choice | P2 | None | Decide, §5 | Yes | Architecture, production |
| Generic Employee upload | Whether that route should take Employee uploads at all: it has no category, and its tier is the caller's word | Security, HR owner | Choice | P3 | None | Decide, with D-4 | Yes | Architecture |
| HRC-10, HRC-11, GOV-6, C10-N1 | Rules 86, 97; `is_paid` freeze | HR, Leave | Code | P3 | None | Next checkpoint | Yes | Architecture |
| C10-N7, N8 | ECR delimiter, PAN format | Payroll | Code | P3 | Before any statutory grant | With S-4 | Yes | Production |
| C10-N9 | No failed-code throttle | Security | Choice | P3 | None | Decide | Yes | Production |
| C10-N2, N3, N6 | Composed move, `LeavePageSupport`, E21:490 | HR, Leave, E21 owner | Records | P3 | None | Next checkpoint; E21 by its owner | Yes | Architecture |
| HRC-4, HRC-8, TRR-8, TRR-6 | Registers | HR, Tasks | Owner choices | P3 | Answers | Decide | Yes | Architecture |
| XRP-3, XRP-4 | R1-02, R1-07 | Teaching, Timetable, Transport | Other owners | P3 | Their checkpoint | Authorize | Yes | Architecture |
| S-4, S-6 record, S-16, X-1 | R1-12 | As §3 | Records and code | P3 | Decisions | As §3 | Yes | Architecture |
| R1-09, §1, §4 | Verdict reading | Owner | Undefined | Admin | None | Decide, §6 | Yes | Architecture |
| R-HR10-1 | Closed-year credit | Leave owner | No ledger path | P3 | Owner decision whether one is wanted | Keep flagged; never reopen a closed year | Yes | Production |
| Same-person MEDIUM | Payroll integrity | Identity, security | Decision | P2 | Before real Payroll | Decide | Yes | Production |
| Reversal performance, D-7 | Operations | Product | Staging numbers | P3 | Staging | Measure | Yes | Staging, production |
| E21-D9, E45, E47, HRX-L1–4, ADR 0036, ADR 0040, D-11 | Legal | Legal, DPO | Determinations | Gate | Sending them is the owner's act | Owner | Yes | Production |
| Staging | Environment | Owner | No access, drill or TLS evidence | Gate | Authorization | Owner | Yes | Staging |

## 5. Decision packages

All **pending**. Evidence and file references for each are in the evidence inventory §4.

### D-1 HR.4–8 deferral (HR owner)
1. **Question.** Record HR.4–8 as DEFERRED, as §2 requires, in `HR-REFERENCE-CAPABILITY-MAP.md`.
2. **Governing behaviour.** Not built, design-only pending OD-HR-14. No such record exists.
3. **Choices.** A blanket deferral. B partial: defer gated rows, keep calendar history and the attendance lock open. C decide OD-HR-14 and build L2a. D amend §2 with a "gate-held" state.
4. **Recommended.** B.
5. **Why.** 82 rows point at these phases, 61 gated. A blanket deferral silently drops 12 ungated HR.4 rows, D8-10 and the destinations of S-6 and S-16.
6. **Modules.** Leave, Staff Attendance, Payroll, proposed WorkSchedule.
7. **Implementation.** A map section, plus a roadmap amendment.
8. **Staging, production.** None in code; production stays NO-GO.
9. **Authority.** Owner for L2a; legal and security for L2b.
10. **Approver.** HR owner.
11. **Evidence.** A record naming rows, reason, re-review trigger and re-homed S-6 and S-16.
12. **Status.** Pending. Nothing is written as DEFERRED.

### D-2 Payroll coupling (Architecture)
1. **Question.** Resolve the coupling, or record an owner-approved exception.
2. **Governing behaviour.** 62 use sites (direct read 25, eligibility 14, calculation 7, relationship 12, infrastructure 4, write 0, historical 0), 9 foreign keys, 8 SQL functions. ADR 0034:43 says otherwise.
3. **Choices.** A exception for all. B narrow exception plus readers for the 13 existence and 9 label sites. C a new HR reader for all PHP.
4. **Recommended.** B.
5. **Why.** Foreign keys, SQL guards and the compensation lock cannot use a PHP reader under any option, so an exception is needed regardless. Existence and labels already have one.
6. **Modules.** Payroll, Statutory, HR.
7. **Implementation.** An ADR 0034 amendment, a guard pinning the excepted list, the reader moves.
8. **Staging, production.** None functional.
9. **Authority.** Engineering, rules 4 and 15.
10. **Approver.** Architecture.
11. **Evidence.** A scan on the baseline and the pin. An exception answers only one of R1-02's three conjuncts: 25 non-Payroll files also import HR Infrastructure.
12. **Status.** Pending.

### D-3 Accrual scheduling (HR owner)
1. **Question.** Keep accrual manual, or schedule it.
2. **Governing behaviour.** Not scheduled. `HR-16-READINESS.md` and `HR-OPERATIONS.md` claimed heartbeats; C10 corrected those false claims.
3. **Choices.** Manual; scheduled with a system actor; manual plus "month not run" detection.
4. **Recommended.** Manual now, with the detection as an option.
5. **Why.** Every service takes a human `User`; a system actor is a new authority concept.
6. **Modules.** Leave, Observability.
7. **Implementation.** None for manual; a command, heartbeat, alert, ADR amendment and actor design otherwise.
8. **Staging, production.** A missed manual month is silent. It needs an operator procedure.
9. **Authority.** Product and engineering.
10. **Approver.** HR owner.
11. **Evidence.** A record, or a heartbeat test.
12. **Status.** Pending.

### D-4 Fresh MFA on Leave writes (Security)
1. **Question.** Which Leave writes need fresh MFA. The HR-document half is closed: it was a defect against an existing contract, not a decision.
2. **Governing behaviour.** Adjustment and opening take it. Allocation, runs, assignment, year close and request decisions do not, and read the cached capability except decisions.
3. **Choices.** Allocation and runs; allocation only; record no step-up as deliberate with `requireCurrent`; keep S-7 open.
4. **Recommended.** Allocation only, with `requireCurrent`: a run is bounded by policy units, while a direct allocation names any amount.
5. **Why.** Proportion. This is a judgement for Security.
6. **Modules.** Leave, OpenAPI.
7. **Implementation.** `requireForAction` on allocate, on web and API, with tests.
8. **Staging, production.** A School needs an enrolled factor before allocation.
9. **Authority.** Security; ADR 0071 §26.7, rule 95.
10. **Approver.** Security owner.
11. **Evidence.** Allow and deny tests on both surfaces.
12. **Status.** Pending.

### D-6 Calendar as-of (HR owner)
1. **Question.** Accept a residual, or build calendar history.
2. **Governing behaviour.** The weekly pattern is overwritten and holidays are hard-deleted. Approved leave is a sound snapshot. Past-date registers, month views, and Payroll's fresh read use today's calendar and cannot be reconstructed. Audit keeps partial traces for 7 years, against 8 years of leave evidence.
3. **Choices.** Residual; recorded-time history; effective-date history; both. Separately, what past-date pages show, and whether a change may be retroactive.
4. **Recommended.** A residual now, plus a calendar-history slice split out of HR.4 before real data, and the `is_paid` freeze (C10-N1) on its own.
5. **Why.** History starts accruing on the day a School goes live, and what is lost before cutover cannot be recovered. Calendar history needs no timing data, so OD-HR-14 should not hold it.
6. **Modules.** Leave, Staff Attendance, Payroll HRX, HR reports.
7. **Implementation.** Marked holidays, an append-only week version, one as-of reader (evidence inventory §1.5). No WorkSchedule or holiday engine.
8. **Staging, production.** Harmless on synthetic data. In production the first pattern edit silently changes past month views. No money effect while HRX-L4 is open.
9. **Authority.** Product; HRX-L4 is the trigger.
10. **Approver.** HR owner.
11. **Evidence.** The proposed tests, or a residual row with a re-review trigger.
12. **Status.** Pending.

### D-7, D-9, D-10, all pending
- **D-7 (Product).** Performance targets. Proposed: set a School size first, then measure on staging. The reversal target is already deferred to staging by the owner.
- **D-9 (Product, ADR 0040 owner).** "Employments examined". Proposed: accept as operational with a recorded reason. Dropping the column alone does not remove the number, because the other three reconcile to it.
- **D-10 (Legal).** Extend E47. Names alone are not enough: `line_manager` and `team_task_manager` touch data sets the question does not list. Sending it is the owner's act (rule 16).

## 6. Proposed readings of §1, §4 and R1-09 (not authorized)

- **§4.** BLOCKER or MAJOR applies to the development and architecture rows. Open legal, staging and production items do not fail the verdict if each is recorded, unchanged and refused, and the verdict names itself "PASS for development scope; production not addressed". "Permanently closed" would then need a later second assessment, or narrowing.
- **R1-09.** Each owner gives one of three recorded answers: INTEGRATED with a test; DEFERRED BY OWNER and shown NOT_INTEGRATED, never clear; or NOT BUILT, with a named owner. A NOT_INTEGRATED owner never counts as "answered clear".
- **§1.** "Connected" is undefined. Inventory, Library staff borrowing and Finance staff dues are deferred by owner decision, so the sentence cannot be met literally. It needs the same three-state reading.

**This does not redefine the contract.** Only the owner can.

## 7. Proposed closure baseline (not selected)

| Baseline | Pins | Note |
|---|---|---|
| Source | One exact `main` SHA with a green exact-SHA `CI gate` | After the next checkpoint |
| Migrations | Count, last name, verifier result, schema dump hash | Rules 10, 143 |
| Reference pack | `40ed6a83…1ac3`, owner-run recomputation, date, byte convention, script hash | Owner's action, outside Git |
| Legal and security | Register SHA plus a dated list of id, status, expiry | Never an implied approval. E16 last valid day 2026-11-28 |
| Local environment | CI run, shard count, cases, skip list, PHP and PostgreSQL versions | Synthetic data, one machine |

## 8. Remediation sequence

1. **Urgent security corrections.** None remain open: HRC-3 is closed.
2. **Independent implementation.** HRC-10, HRC-11, GOV-6, C10-N1, and the R1-01 pins once defined. No owner decision needed, except the definition.
3. **Owner decisions.** D-1, D-2, D-3, D-4, D-6, then R1-09/§4, HRC-4, HRC-8, TRR-6, TRR-8, S-6, D-7, D-9.
4. **Cross-domain owners.** XRP-3 (TeachingAssignments), XRP-4 (Timetable, Transport), X-1 reads.
5. **Implementing the decisions.** D-4, D-6, D-2's readers and pins.
6. **Documentation.** Whatever the decisions change, plus E21:490 with its owner.
7. **Legal and privacy.** E21-D9, E45, E47, HRX-L1–4, the same-person decision, by their owners.
8. **Staging.** Access, environment, restore drill, TLS, legacy inventory, MFA personas, screen reader, reversal and month-view measurement.
9. **Final HR.R1 assessment,** on the selected baseline.
