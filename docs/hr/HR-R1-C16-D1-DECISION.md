# HR.R1-C16: D-1 owner decision and the §2 position (2026-11-08)

**HR.R1 stays OPEN, and this is not the closure audit.** It records exactly what the HR owner decided, and nothing more. D-2 to D-10 and R1-01 stay pending. No gate opened. Nothing was built. Baseline `5ee750e5`.

**Completion state: DECISION RECORDED — §2 IMPLEMENTATION OR EVIDENCE STILL REQUIRED.**

## 1. The decision (Deliverable A)

| Item | Recorded |
|---|---|
| Authority | HR owner (`HR-R1-C12-DECISION-DOCKET.md` §1: D-1, §1, §4, R1-09 and baselines are the HR owner's) |
| How given | In this session, 2026-11-08, by the repository owner answering four questions each framed as the HR owner's decision. The record names the role, not a person, as the docket asks |
| D-1 | **Option B, partial deferral**, exactly as proposed in `HR-R1-C12-HR4-8-DISPOSITION.md` |
| The four ACTIVE rows | **Kept ACTIVE**, to be built later as WO-5 and WO-6. Not deferred |
| The nine rows outside HR.4–8 | **DEFERRED by owner record**, each with a re-review trigger |
| §2 | **The proposed reading is adopted** as the HR owner's interpretation. The contract text is not edited |
| Conditions | Every legal, privacy and security gate stays shut. No biometric, device, GPS, face-recognition, clock-time, shift or WorkSchedule processing is authorized. A deferral opens nothing and closes nothing |
| Excluded | D-2 to D-10, R1-01, R1-09, §1, §4, the baseline selection, S-6's determination, every Legal, DPO and Security decision |
| Implementation | None. WO-5 and WO-6 become the named route for the ACTIVE rows; each still needs its own authorization to start |
| Staging / production | None. Production stays NO-GO |
| Re-review | Each deferred row's trigger (§3); the ACTIVE rows when WO-5 or WO-6 is authorized |

**The four options, as the docket states them (§3):** "A: blanket deferral. B: partial, deferring gated rows and keeping calendar history and the attendance lock open. C: decide OD-HR-14 and build L2a. D: amend §2 with a "gate-held" state." No decision was recorded between C12 and this checkpoint.

## 2. The 82 HR.4–8 rows (Deliverable B)

The per-row matrix, with feature, status, domain, gate, reason, owner, acceptance and next step, is `HR-R1-C12-HR4-8-DISPOSITION.md`, now marked approved. Re-checked against the map at `5ee750e5` before deciding: exactly the 82 rows whose Phase names HR.4 to HR.8, every status matching, the proposal summing to 82.

| Approved | Rows | Map effect | IDs |
|---|---:|---|---|
| HELD | 61 | Stay GATE; the gate keeps its own release | D1-04, D2-07, D5-07…D5-20, D6-01, D6-03…D6-05, D6-07…D6-21, D7-07…D7-13, D9-01…D9-14, D20-19, D20-20, D20-27, D20-28, D20-38 |
| DEFERRED | 10 | PART/MISS → DEF, owner record and trigger in the Gate column | D5-02, D5-03, D5-05, D5-06, D8-03, D8-04, D14-02, D14-03, D14-04, D14-07 |
| KEEP | 5 | Unchanged | D7-14, D7-16, D8-01, D14-05, D14-12 |
| KEEP (DEF) | 2 | Unchanged, already DEF | D8-06 (ADR 0065 §7), D8-14 (HR-004) |
| ACTIVE | 4 | Unchanged, still open | D8-10, D8-13, D14-08, D14-09 |
| **Total** | **82** | | |

**The 12 ungated HR.4 rows:** 8 DEFERRED (D5-02, D5-03, D5-05, D5-06, D14-02, D14-03, D14-04, D14-07), 2 ACTIVE (D14-08, D14-09), 2 KEEP (D14-05, D14-12). HR.7 holds the other two ACTIVE rows and the two regularization deferrals; HR.6 holds D7-16 (KEEP).

**The four ACTIVE rows.** None is implemented, and none has an approved alternative, a gate or a deferral. Each needs a future checkpoint.

| Row | What is missing | Route | Accepted when |
|---|---|---|---|
| D8-10 | A Staff Attendance period lock; Payroll only detects a later change | WO-6, HR.7a, after S-6 (the docket's dependency) | A lock with controlled reopening, Payroll tests, audit |
| D8-13 | S-6: no window, MFA or second person on historical change | WO-6, after an S-6 determination by the HR owner with Security | The recorded determination, then its tests |
| D14-08 | A holiday change has no effective date | WO-5, HR.4a, after D-6 | A change carries a recorded effective date, and past dates keep their answer |
| D14-09 | S-16: `staff_holidays` is hard-deleted at runtime | WO-5, HR.4a, after D-6 | Runtime DELETE revoked, a versioned change, a test |

**Preserved, not decided here.** D8-10, S-6 and S-16 stay visible as ACTIVE rows and open findings: no deferral touches them. HR-011 (regularization) and HR-004 (work from home) stay `OWNER_DECISION_REQUIRED` in `docs/product/reference-parity/functions.csv`. Deferring D8-03 and D8-04 defers the HR-011 question; it does not answer it. HRX-L2 is not split, and OD-HR-14 stays direction only.

**The roadmap amendment** that Option B carries is recorded in `HR-EXPANSION-ROADMAP.md`. HR.4 splits into HR.4a (calendar history, WO-5) and HR.4b (shifts, gated). HR.7 splits into HR.7a (the lock, WO-6), which no longer waits for HR.6, and HR.7b (regularization and manager correction). That removes the RM:315 dependency that stranded D8-10.

## 3. The nine rows outside HR.4–8 (Deliverable C)

These are not part of the 82. They were C12-N5, now closed by this decision.

| Row | Feature | Was | Phase | Re-review trigger |
|---|---|---|---|---|
| D4-07 | Dotted-line manager | MISS | HR.3 | A dotted-line reporting need, as an HR.3A amendment |
| D1-07 | Employment lifecycle (probation) | PART | HR.12 | An owner answer to HR-090, which stays `OWNER_DECISION_REQUIRED` |
| D18-03 | Probation | PART | HR.12 | As D1-07 |
| D18-04 | Confirmation | MISS | HR.12 | As D1-07 |
| D18-17 | Offboarding: relieving and clearance documents | PART | HR.15 | A relieving-letter contract (a Documents owner type and a wording review); the clearance half stays D18-15 |
| D19-07 | Academic task (class-section / subject) | PART | later | A task-relationship contract with TeachingAssignments and Academic Structure |
| D19-14 | Target / deadline | PART | later | As D19-07 |
| D19-15 | Class / section relationship | PART | later | As D19-07 |
| D19-16 | Subject relationship | PART | later | As D19-07 |

The triggers are this record's wording, taken from each row's own gap and phase: the owner approved a deferral with a trigger for each, not these words individually.

## 4. §2 (Deliverable D)

**The exact text:**

> - HR.1–HR.16 are each published, or each explicitly DEFERRED with an owner decision recorded in `HR-REFERENCE-CAPABILITY-MAP.md`.
> - The HR reference pack fingerprint is unchanged (`HR-REFERENCE-CAPABILITY-MAP.md` §1), or a re-capture has been mapped.
> - A green full regression exists on the HR.R1 baseline SHA.

**The reading adopted:** a slice is published when what it delivered is published and each unresolved row of its phase carries a gate or an owner's DEFERRED record in the map.

| Precondition | Position |
|---|---|
| First | **Not met.** Every unresolved row now has a gate or an owner record except the four ACTIVE rows, so HR.4 and HR.7 remain open. It is met when WO-5 and WO-6 ship, or the owner defers those rows |
| Second | **Met as of 2026-11-08:** fingerprint recomputed exactly (§5) |
| Third | **Not met:** no baseline SHA is selected, so no full regression on one exists |

Option B alone could not satisfy the first precondition, and with the four ACTIVE rows kept it still does not. That is what the owner chose: the gap is recorded, not hidden.

## 5. Reference pack and baselines (Deliverable E)

**Fingerprint, verified.** The owner-held pack was found at the location the map names (`…/Lycenza/Screenshots Downloaded/HR`, on the owner's machine). It holds 602 PNG files, no sub-folders, and its fingerprint recomputes to exactly `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`. **The convention, now pinned:**

- file names in sorted order (byte, case-folded and default orders agree for this pack);
- for each file, its UTF-8 name immediately followed by its lowercase hex SHA-256, with no separator;
- SHA-256 over the concatenation.

Of 54 combinations tried (three orders, nine separators, two hex cases), the only matches use no separator and lowercase hex; the three orders give the same sequence for this pack. `HR.zip` holds the same 602 names; its SHA-256 is `9c44d851d8a3aacfd13ab9b1ee5b985d2897a1dd2b8c716bd2fa604753c5f73b`. The pack was read, never copied or published.

| Baseline | Status | Evidence |
|---|---|---|
| Source | **VERIFIED OBSERVATION**, not selected | Last full suite `8132f9c7` (run 42423542688, 11,488 cases). `main` is this record's documentation commit on top |
| Database and migrations | **VERIFIED OBSERVATION**, not selected | 385 migrations, 0 pending, forced RLS 252, verifier `failed=0 operator_evidence_required=1` (C15) |
| Reference pack | **VERIFIED OBSERVATION**, not selected | Fingerprint above |
| Legal and security | **VERIFIED OBSERVATION**, not selected | Gate register unchanged; E16 valid through 2026-11-28 |
| Local environment | **VERIFIED OBSERVATION**, not selected | DDEV, PostgreSQL 16.14, synthetic data |

**No baseline is SELECTED.** The HR owner selects them. Then the required full regression runs on the selected code-lane SHA, and R1-04, R1-05, R1-07 and R1-08 are re-evaluated there, as the docket's §12 table states. C15's green suite is evidence for C15, not that regression.

## 6. What follows (Deliverable F)

| Kind | Work | Needs |
|---|---|---|
| Documentation, done here | The 19 DEF rows, the HELD record, the roadmap split, the §2 reading | Nothing more |
| Implementation | WO-6, the attendance period lock (D8-10, D8-13) | The S-6 determination (the docket lists WO-6 as needing D-1 and S-6), then authorization to start; a new capability key needs a catalogue release (rule 93) |
| Implementation | WO-5, calendar history (D14-08, D14-09) | D-6 first, then authorization |
| Legal / security gated | Everything HELD | Each gate's own owner |
| Architecture | D-2, R1-01, closure levels | Architecture |
| Baseline | Selection, full regression, R1-04/05/07/08 re-run | HR owner selection |

**Recommended next:** the S-6 determination, then WO-6, the Staff Attendance period lock. D8-10 is the highest-risk ACTIVE row, and after the roadmap split it needs no timing data and no L2a. But the docket makes WO-6 depend on S-6 as well as D-1, and S-6 decides what reopening a locked month requires (window, fresh MFA, a second person). So the checkpoint starts with that HR owner and Security determination, and builds the lock only once it is recorded. It is not started.
