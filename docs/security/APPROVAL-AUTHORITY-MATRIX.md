# Approval authority matrix (ORG.8 contract audit, 2026-10-21)

Status: **contract, not built.** This records repository truth at
`98932b07` and the recommended v1 approval contract (ADR 0076 §56). Nothing
here is implemented by ORG.8. No capability, role, catalogue, route, table
or legal gate was changed. A row marked "recommended" needs its own
owner-authorized phase (§56.30).

Legend for the separation-of-duties column:

- `FORBIDDEN`: the requester (or any prior participant) never decides.
- `CONDITIONAL`: allowed only under a named condition.
- `ALLOWED`: one actor may request and execute.
- `NO_APPROVAL`: a direct privileged operation; there is no request object.

Legend for step-up: `NONE`, `ON_APPROVAL`, `ON_EXECUTION`, `ON_BOTH`.
Fresh MFA always means `FreshMfaRequirement` (a new TOTP or unused
recovery code per action); there is no other mechanism.

## 1. Existing request/approve workflows (repository truth)

| Workflow | Request | Decide | Execute | SoD today | Fresh MFA today | Reason today | Drift binding | Status |
|---|---|---|---|---|---|---|---|---|
| Fee concessions (ORG.8A pilot on the shared foundation) | `finance.fee_concessions.request` | `.approve` (approve, reject) | approval posts the adjustment in the same transaction | approve/reject FORBIDDEN (service + `fee_concessions_sod_check`); revoke and cancel-adjustment unchecked | approve, revoke, cancel-adjustment; not reject/withdraw | category closed; no decision reason (owner decision M) | request fields DB-immutable; targeted amount re-validated under the Charge lock | COMPLETE (gaps G-3) |
| Communications approval | announcement author | `communications.approve` | publish re-checks `currentlyApprovedAndValid` | FORBIDDEN (`SelfApprovalNotAllowedException`) | none on approve; emergency publish only | rejection reason required (free text) | canonical fingerprint; approval-sensitive edit -> `invalidated` | COMPLETE (fingerprint reference) |
| Marks corrections | `examinations.marks.correction.request` | `.approve` | approval applies the change in the same transaction | FORBIDDEN (service + `student_mark_corrections_maker_checker_check`) | approve, reject, lock | closed `reason_code` required | one pending per mark; decided rows immutable | COMPLETE; production refused in code (E36/E37) |
| Leave | `hr.leave.self` / `hr.leave.manage` (on behalf) | `hr.leave.approve` (current direct manager) or `hr.leave.manage` (administrative) | approval writes consumption in the same transaction | FORBIDDEN on decide (service + `leave_decisions_no_self_decision`) | none | rejection/closing closed codes required; request reason optional | request content DB-immutable; manager re-resolved under lock at decision | COMPLETE for v1 (gaps G-4) |
| Payroll runs | `payroll.runs.prepare` (create, calculate, overrides, deltas) | `payroll.runs.approve` | `payroll.runs.post` (separate step) | creator != approver (service + `payroll_runs_sod_check`); contributors unchecked; approver may post | approve, post, reverse, compensation | override/delta free text required; reverse optional | results and HRX inputs frozen at approval | PARTIAL (gap G-2) |
| Erasure cases | compliance request | compliance decision | executing -> completed | n/a (platform/compliance) | per ADR 0059/E21 | closed decision reason | state machine | COMPLETE (out of ORG.8 business scope) |

## 2. Direct privileged operations (no request object)

| Operation | Capability | Holders (templates) | Fresh MFA | Reason | Campus | Recommended v1 |
|---|---|---|---|---|---|---|
| Payment reallocation | `finance.payments.record` + `finance.charges.manage` | school_admin, accountant | yes | free text 5-500 (DB) | whole-School | NO_APPROVAL; keep |
| Payment reversal / recorded refund | `finance.ledger.reverse` + `finance.payments.view` | school_admin | yes | free text 5-500 (DB) | whole-School | APPROVAL: request -> approve (approval executes); FORBIDDEN self; ON_APPROVAL; closed reason + text; amends ADR 0031 |
| Ledger post | `finance.ledger.post` | school_admin, accountant | yes | description | whole-School | NO_APPROVAL; keep |
| Ledger reverse | `finance.ledger.reverse` | school_admin | yes | optional text | whole-School | NO_APPROVAL; a subledger-owned entry is refused since ORG.8-P1 (G-1 closed); open: a reverser != poster rule for manual entries (owner decision, ADR 0071 §26.13) |
| Period close | `finance.periods.manage` | school_admin | yes + typed key | none | whole-School | NO_APPROVAL; keep (irreversible, no reopen) |
| Charge cancel / assessment void / late-fee void | `finance.charges.manage` | school_admin, accountant | no | optional text | whole-School | NO_APPROVAL; recommend REASON_REQUIRED (closed) and ON_EXECUTION step-up |
| Fee-run cancel | `finance.fee_assessments.run` | school_admin, accountant | no | none | whole-School | NO_APPROVAL (pre-execution only) |
| Library fine void | `library.fines.void` | school_admin | no | free text required (DB) | whole-School | NO_APPROVAL for the holder; optional librarian request (`library.fine_voids.request`) only if the owner wants it; REASON_REQUIRED closed |
| Student Attendance correction | `attendance.manage` (Campus-adopted) / `attendance.teacher` (ownership) | school_admin, principal; teacher (own registers) | no | none | Campus by stored register Campus | NO_APPROVAL; REASON_REQUIRED (closed); any late-correction approval window is a School-configurable business policy (owner decision) |
| Staff Attendance correction | `hr.staff_attendance.manage` | school_admin, principal, hr_officer | no | closed code required | whole-School | NO_APPROVAL; keep (self refused) |
| Student status active <-> inactive | `students.manage` | school_admin, principal | no | none | whole-School (declared) | NO_APPROVAL in v1 + REASON_REQUIRED + confirmation (LC-8 STU-14); request/approve only on owner decision D-13 |
| Enrollment complete / withdraw / cancel | `enrollments.manage` | school_admin, principal | no | none | whole-School (declared) | as Student status |
| Campus transfer | `enrollments.manage` over both Campuses | school_admin, principal (whole-School, or a grant covering both Campuses) | no | none | dual-scope | NO_APPROVAL (dual-scope authority, blockers, immutable audit already) |
| Admissions accept / reject | `admissions.manage` (Campus-adopted) | school_admin, principal, admissions_officer | no | optional note, not audited | application Campus | NO_APPROVAL; recommend a closed rejection reason |
| Admissions conversion | `admissions.manage` | as above | no | none | whole-School (declared) | NO_APPROVAL |
| Marks paper lock | `examinations.marks.lock` | school_admin, principal | yes | none | whole-School | NO_APPROVAL; keep (irreversible) |
| Employee separation / rehire / archive | `hr.employees.assignments.manage` (whole-School) | hr_officer | no | status only | whole-School (declared) | NO_APPROVAL; recommend closed separation reason |
| Highly sensitive HR document write | HR document keys | school_admin, hr_officer | yes | — | whole-School | keep |
| School lifecycle | `platform.schools.manage` / `.onboard` | root / Platform Manager (onboard only) | yes + confirmation | closed for suspend/close | platform | keep (platform authority, never tenant business) |
| Module entitlements | `platform.school_entitlements.manage` | root, Platform Manager | yes + confirmation | closed | platform | keep |
| Platform role grant | `platform.role_grants.manage` | root | yes | none | platform | keep |
| Group governance | `platform.school_groups.manage`, `.school_group_grants.manage` | root | **no** | none | platform | observation G-5 (owner decision) |

## 3. Gaps found (recorded, not fixed)

| Id | Severity | Finding | Recommended handling |
|---|---|---|---|
| G-1 | MEDIUM (integrity, pre-existing) — **CLOSED by ORG.8-P1 (ADR 0076 §57)** | `LedgerAdministrationService::reverse()` reverses ANY journal entry of the School. Only Payment settlement entries are guarded (`journal_entries_payment_reversal_guard`). A Charge recognition, fee adjustment, payment correction, payroll posting or statutory posting entry can be reversed directly, leaving the owning record live (reproduced read-only: the Charge stays uncancelled and a later cancel is refused as "already cancelled"). Needs `finance.ledger.reverse` (school_admin only) and fresh MFA; audited; repairable by reversing the reversal. | Prerequisite slice ORG.8-P1 (owner authorization): one generic subledger-ownership guard on journal reversal, mirroring the Payment guard. Before any ledger or Finance approval work. |
| G-2 | MEDIUM (SoD) | Payroll SoD compares only the run creator (`prepared_by_user_id`) with the approver. A user holding prepare + approve can record manual overrides or correction deltas on, or recalculate, another preparer's run and then approve it. Approver may also approve own pay (ADR 0071 §26.13). | ORG.8B: per-item prior-participation SoD (creator, calculator, override/delta authors never approve). |
| G-3 | LOW (open; outside the ORG.8A pilot) | Fee concession revoke and adjustment cancellation have no SoD check; reject and withdraw need no fresh MFA. | Pilot retrofit (ORG.8A): closed reason on revoke/cancel; SoD CONDITIONAL (owner decision); reject stays NONE step-up. |
| G-4 | LOW | `hr.leave.manage` holders allocate, adjust, assign policy, submit on behalf and administratively cancel for their OWN employment without a self-refusal (only decisions are self-guarded). | ORG.8D: extend the HR self-administration rule (rule 95) to own leave balance writes. |
| G-5 | LOW (platform) | Group governance writes need no fresh MFA, confirmation or reason; the self-grant refusal is a plain validation error and is not audited. | Owner decision; platform hygiene slice, not tenant approval. |
| G-6 | LOW | Student status toggle: no row lock, no reason, no `students.status` CHECK; enrollment end dates free (backdatable) with no reason. | ORG.8E with D-13. |
| G-7 | LOW (pre-existing, ORG.8A review) | Maker/checker excludes the requester only; a staff member who is also the Student's linked Guardian can approve a colleague's concession for that Student. | Owner decision: an optional beneficiary-relationship participation class on the shared foundation. |

## 4. Proposed new capability families (names only; none created)

Only where a genuine maker/checker split is recommended. Each is classed
`authority` (ADR 0076 §16) and is never held by an operational role.

| Proposed key | Purpose | Would be held by | Campus |
|---|---|---|---|
| `finance.payment_corrections.request` | propose a reversal or refund | school_admin, accountant | whole-School (CAMPUS_INCOMPATIBLE family) |
| `finance.payment_corrections.approve` | decide another person's reversal/refund request | school_admin (and any future finance-controller persona, owner decision) | whole-School |
| `library.fine_voids.request` (optional) | librarian proposes a void; `library.fines.void` stays the decision authority | librarian | Library fines whole-School; a Campus request would follow the stored loan Campus only if later adopted |
| `students.status.request` / `.approve` (optional, D-13) | Campus staff request inactivation/leaver; whole-School approves | request: principal (Campus-adoptable); approve: school_admin | request may be Campus-scoped; approve whole-School |

No `.override` key is needed in v1: no current policy is designed to be
overrideable. Payroll `manual_override` is an adjustment kind, not a policy
override, and keeps its name. No threshold, delegation or multi-level key is
proposed.

## 5. Legal / production gates against approval candidates

| Candidate | Gate | Affects |
|---|---|---|
| Payment corrections, receipts | E30, E31 | production execution (no code refusal) |
| Concessions, waivers, RTE | E32 | production execution |
| Library fine void / approval | E34 | production execution |
| Marks corrections, lock | E36, E37 (refused in code), RES-L3..L9 | all environments except local/testing |
| Leave | HRX-L1, HRX-L3 | production activation of statutory/health rules |
| Staff Attendance | HRX-L2 | biometric/device attendance only |
| Payroll approval | HRX-L4, E45 PAY-L1 | loss-of-pay money effect; ESI disability branch |
| Teacher Attendance corrections | E33 / TCH-L1 | production teacher grants after deployment re-verification |
| Portal-visible decisions | E46 POR-L1 | refused in code outside local/testing |
| Everything | E21, E02/E15, E16, O1 | production go-live |

An approval, override or administrative decision never opens a legal gate,
in any environment.
