# HR.R1-C33: person-level identity. Known-same-person refusals decided; the unknown residual recorded (2026-11-12)

**Decision only. Nothing is built here.** HR.R1 stays OPEN. The Identity MEDIUM's development part stays a MAJOR blocker under C27-C until WO-16 is built and published.

**Status (HR.R1-C34, 2026-11-12): WO-16 built** (`HR-R1-C34-WO16-KNOWN-SAME-PERSON.md`). The attestor check sits in `trg_payroll_posting_attestations_guard`, where the attestor is recorded, rather than `trg_payroll_runs_posting_independence` (§6 table); nothing weaker results. RG-ID-1 and the handoffs are unchanged.

| | |
|---|---|
| Baseline | `69ef41c0` = `main` = `origin/main`; `63015a50` (C32 code) an ancestor; clean tree; the historical stash and `integration/e16-r2` preserved |
| Last full suite | `63015a50`: 11,792 cases, 467,236 assertions, 0 failures, 1 skip (ESI-12); counter 0/5. This checkpoint changes documentation only and runs no new runtime regression |
| Authority | The owner, as Identity and Security owner, 2026-11-12 (`HR-R1-C12-DECISION-DOCKET.md` §11 names Identity and Security) |
| Not changed | Every approval rule, Payroll rule, link-history rule (rule 145), capability and the schema. No identity data is collected, matched or merged |

## 1. The finding, as recorded

**Origin, HR.R1-C7 (2026-11-06).** "One person with a second, never-linked HR account can correct their own link." C8 closed that exact path: correction now takes two linked staff, plus known-same-person refusals.

**What remains** (`HR-LEGAL-SECURITY-GATE-REGISTER.md`, OD-HR-25 known limits): "a person holding a second account that is linked to a second Employee record they control, or two people colluding".

**`HR-R1-C10-CLOSURE-READINESS.md`:** "Same-person identity: OPEN, MEDIUM. Two accounts do not prove two people, and two HR holders can collude. Identity and security owners. A decision before real Payroll."

**Docket (`HR-R1-C12-DECISION-DOCKET.md` §11):**
- **Question:** "What assurance is required before real Payroll that two accounts are two natural persons? ... Options for Identity and Security: accept and record it, an out-of-band assertion by the School, or a verified identity process."
- **Fixed constraint:** "No biometric, no inferred matching, and no new personal identifier may come from this docket."

**Classification:** MAJOR under C27-C.

## 2. What the system knows about people

| Concept | Where | What it proves |
|---|---|---|
| Account | `users` | An authenticated login. Never a person |
| Employee | `employees` (HR) | An HR record. One person may, wrongly or legitimately, have two |
| Employment | `employment_records` | A period of employment of one Employee |
| Account-to-Employee link history | `employee_account_links` (rule 145), written only by triggers; a correction voids a row through a two-person workflow | That an account was at some time tied to an Employee |
| **Known same person** | `HrSelfAdministrationGuard::knownSamePerson()` and the SQL `employee_account_link_correction_same_person()`: two accounts with **uncorrected** links to **one** Employee | That two accounts are one person, as far as the system can know |
| Distinct persons | **Nothing.** Different ids, emails, Employees, MFA factors or names prove account separation only | — |
| Natural person | **No record exists, and none is added** | — |

**Not people:**
- Platform service identities are not School actors; none can decide a School workflow.
- There is no impersonation or shared-account feature: the code's only mentions of impersonation are comments saying it does not exist. A shared login would be one account, and refused as itself.
- Platform elevation (rule 83) grants tenant context, never School authority, so an elevated actor decides no School workflow.

## 3. Reproduction (on `69ef41c0`, throwaway test, not committed)

The setup: one person's Employee record was linked first to account A, then relinked to account B. `knownSamePerson(A, B)` is **true**: both links are uncorrected, so the system knows A and B are one person.

The result: A prepared and calculated a payroll run, and **B approved it. The approval succeeded.**

**Cause:**
- `MakerCheckerPolicy::disqualification()` compares account ids (`ApprovalParticipants::participationOf($decider->id)`).
- The database backstop `payroll_run_has_participant()` does the same.
- Known-same-person evidence is applied in only two of the ten registered workflows.

## 4. Workflow inventory (`ApprovalWorkflowRegistry`, 10 request/decide workflows)

| Workflow | Domain | Today | Decided |
|---|---|---|---|
| `employee_account_link_corrections` | HR | Account + known same person (service and DB) | Unchanged |
| `staff_attendance_locked_corrections` | Staff Attendance | Account + known same person (service and DB) | Unchanged |
| `payroll_runs` approval, posting attestation (`payroll_posting_attestations`), reconciliation review | Payroll | Account only (plus the self-beneficiary rule through account links) | **Known same person refuses (WO-16)** |
| `leave_requests` decision | Leave | Account (requester and submitter), plus the decider's current link to the requester's employment | **Known same person refuses (WO-16)** |
| `employment_separation_cases` decision | HR | Account (recorder), plus the subject's own record | **Known same person refuses (WO-16)** |
| `fee_concessions` | Fees | Account | Handoff to Fees/Finance (C33-H1) |
| `payment_corrections` | Payments | Account | Handoff to Finance (C33-H2) |
| `communication_approvals` | Communications | Account | Handoff to Communications (C33-H3) |
| `student_mark_corrections` | Examinations | Account | Handoff to Academics (C33-H4) |
| `erasure_cases` | Platform/Identity | Account | Handoff to Platform/Identity (C33-H5) |

**Out of scope:**
- **Credential verification** (C29) is not a request/decide workflow. C29-N1 records that two verifiers may decide the same revision; no maker/checker rule is added.
- **Ordinary HR edits** are single-actor operations under rule 95's self-administration guard. They are not two-person controls.

## 5. The decisions (Identity and Security owner, 2026-11-12)

| | Decided |
|---|---|
| **Known same person** | In the **HR family and Payroll**, a decision is refused when the decider is a known same person as any of the item's material participants (the participants each workflow already names). The evidence is rule 145's existing link history, uncorrected links only; nothing new is collected. In scope: Payroll approval, posting attestation and reconciliation review; Leave decisions; separation-case decisions. The two workflows that already do this are unchanged. Fees, Payments, Communications, Examinations and Erasure are **handoffs** (C33-H1 to H5), not changed |
| **Unknown** (docket §11, option 2 sequenced) | When the system has no evidence either way, independence is **never treated as proven**, and the residual is **recorded now**: a second account tied to a second Employee record the person controls, and collusion. **Before a School runs real Payroll**, a School administrator records an out-of-band assertion that each staff member holds one account. This is a release gate (RG-ID-1), designed at that stage, with no identifier, document or biometric. Under C27-C it is a recorded release item, not a development blocker |
| **Timing** | Evaluated **at the decision, inside the decision's own transaction**, from the link history as it then stands (as C8 and C18 do). Completed, approved or posted decisions are **never re-evaluated or rewritten**; a later link correction affects only later decisions. The refusal names no other account |
| **Rejected** | Docket option 1 alone (accept, never assert). Option 3, a verified identity process: it needs its own necessity, privacy, legal and security assessment, and nothing here starts it. Extending the refusal to all ten workflows now: five other domains' owners would have to accept it. Re-checking pending items when a link changes |

**No other authority is claimed.** Payroll, Leave and HR are the owner's own domains for HR.R1. The five handoffs need their owners' decisions. No Legal or DPO determination is made, and no new personal-data processing is introduced: the evidence already exists, and its use widens only to refusals.

## 6. WO-16 work order (bounded, not started)

**One rule, reused.**
- `HrSelfAdministrationGuard::knownSamePerson()` is the application answer. It is a reader Payroll may already use (rule 150 register).
- Each database backstop gains the same test through the existing SQL helper's semantics (uncorrected links to one Employee, same School). Rule 102's backstop stays at least as strict as the service.
- Never a name, email, birth date, phone, device or IP comparison.

| Workflow | Service check | Database backstop |
|---|---|---|
| Payroll approval | The approver is a known same person as no participant (creator, calculators, adjustment authors, first-paid compensation authors) | `payroll_run_has_participant()` (or a companion) also matches known-same-person accounts; `payroll_runs_participant_approval_guard` refuses |
| Posting attestation | The attestor is a known same person as no participant or the approver | `trg_payroll_runs_posting_independence` |
| Reconciliation review | The reviewer is a known same person as no maker, approver or reverser | `trg_payroll_reversal_reconciliation_reviews_guard` |
| Leave decision | The decider is a known same person as neither the submitter nor any account ever linked (uncorrected) to the requester's Employee | `leave_decisions_participant_guard` |
| Separation decision | The decider is a known same person as neither the recorder nor any account linked to the subject Employee | `trg_employment_separation_cases_guard` |

**Refusal.** The workflow's existing self-approval refusal, with a reason code such as `APPROVAL_SAME_PERSON`, naming no account. It is audited as that workflow's refusals already are.

**Registry.** `ApprovalWorkflowRegistry` gains a per-workflow `knownSamePerson` declaration. An architecture test requires it for every HR-family and Payroll workflow, and lists the handoffs.

**Tests:**

| Case | Expected |
|---|---|
| The same account | Refused, as today |
| Two accounts linked over time to one Employee (ended, not voided) | **Refused** |
| The same pair after an approved link correction voided one link | Allowed (no longer known same) |
| Two accounts with no shared Employee | Allowed (unknown is never proof, and is not refused: the decision) |
| Each workflow above, service and raw runtime-role backstop | Refused in both |
| Link change racing a decision: the decision holds the order Payroll already uses (rule 144: a link writer takes the compensation lock exclusively before the Employee row) | Decided on one consistent history, deterministic barrier |
| A completed approval, then a new link | Stays approved and unchanged |
| Wrong School, missing capability, stale MFA | Unchanged refusals |

**Standard regression:**
- Payroll (maker/checker, posting, reversal, reconciliation), Leave, Separation, Staff Attendance and account-link non-regression;
- `platform:verify-database` (a backstop change updates the pins);
- the rollback proof for any migration;
- independent review, full exact-SHA CI and protected-main publication.

**Done means:** the Identity MEDIUM's development part closes when WO-16 is published with these tests. RG-ID-1 and the handoffs stay recorded.

## 7. Effects

| Item | Effect |
|---|---|
| Identity MEDIUM | **Decided; still MAJOR under C27-C until WO-16 is published.** The unknown residual is recorded with release gate RG-ID-1 (before real Payroll) |
| HR.R1 §4 | Not eligible. The development blocker is WO-16. Then the seven P3 dispositions, the baselines and the closure regression |
| C33-H1 to H5 | Recorded handoffs to Fees, Finance, Communications, Academics and Platform/Identity |
| C32-N1 to N4, C30-N1/N2, C29-N1/N2, C28-N1/N3/N4 | Unchanged |
| WO-9 (C32) | Unchanged, development-verified |
| R1-02, D-2, R1-01, C27-A to C27-D | Unchanged |
| Legal, DPO, Security gates (E21-D9, E45/ESI-12, E47, HRX-L1–L4, S-4, S-7, X-1, D-11) | Unchanged. RG-ID-1 is added as a release gate |
| Capability map (361 rows), 61 audit identifiers | Unchanged |
| Five closure baselines | Unselected |
| Local UAT | Eligible. Staging paused; production **NO-GO** |

**Re-review trigger.** Any proposal to collect identity evidence, to infer matches, to refuse on unknown, or to extend the refusal beyond the decided scope.
