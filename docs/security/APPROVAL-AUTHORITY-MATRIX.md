# Approval authority matrix (ORG.8, audited 2026-10-21, closed 2026-10-22)

Status: **ORG.8 built for v1; closure audited by ORG.8F (ADR 0076 §64).**
First written as the ORG.8 contract audit at `98932b07` (§56). Since then
ORG.8-P1 and ORG.8A-8E built the rows marked with their phase, against
repository truth at `e43b1a94`. ORG.8F changed no code, capability, role,
catalogue, route, table or legal gate. A row still marked "recommended"
needs its own owner-authorized phase. The residual and owner-decision
registers are §3 and §6.

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
| Leave (ORG.8D: shared foundation, ADR 0076 §62) | `hr.leave.self` / `hr.leave.manage` (on behalf) | `hr.leave.approve` (current direct manager, whole-School grant) or `hr.leave.manage` (administrative) | approval writes consumption in the same transaction | requester Employee FORBIDDEN (service + `leave_decisions_no_self_decision`); submitter FORBIDDEN (shared MakerCheckerPolicy + `leave_decisions_participant_guard`); authority re-read fresh | none (NONE, unchanged) | rejection/closing closed codes required; request reason optional | request content DB-immutable; manager re-resolved under lock at decision | COMPLETE for v1 (G-4 closed; OD-8.8 closed as Option A) |
| Payroll runs (ORG.8B: shared foundation) | `payroll.runs.prepare` (create, calculate, overrides, deltas) | `payroll.runs.approve` | `payroll.runs.post` (separate step) | creator, every recorded calculator and every adjustment author FORBIDDEN (shared MakerCheckerPolicy + `payroll_runs_sod_check` + `payroll_runs_participant_approval_guard`); latest calculation must be complete and current; approver may post (OD-8.4) | approve, post, reverse, compensation | override/delta free text required; reverse optional free text | results and HRX inputs frozen at approval | COMPLETE for v1 (G-2 closed, ORG.8B §60; OD-8.4 production policy) |
| Payment corrections (ORG.8C: shared foundation, ADR 0076 §61) | `finance.payment_corrections.request` (accountant, school_admin) | `finance.payment_corrections.approve` (school_admin): approve, reject | approval executes the correction in the same transaction (`PaymentCorrectionService::executeApproved`) | requester FORBIDDEN (service + `payment_correction_requests_sod_check`); Guardian of any affected Student FORBIDDEN (service + `payment_correction_requests_beneficiary_guardian_guard`) | approve only | free text 5-500 (DB) | terms DB-immutable; bound to the Payment's correction count (stale refused, service + DB) | COMPLETE (M-1 applies) |
| Erasure cases | compliance request | compliance decision | executing -> completed | n/a (platform/compliance) | per ADR 0059/E21 | closed decision reason | state machine | COMPLETE (out of ORG.8 business scope) |

## 2. Direct privileged operations (no request object)

| Operation | Capability | Holders (templates) | Fresh MFA | Reason | Campus | Recommended v1 |
|---|---|---|---|---|---|---|
| ~~Payment reallocation~~ | — | — | — | — | — | **Moved to §1 by ORG.8C (§61):** requested and approved like reversal and refund (owner decision OD-8.1, Option 1 for all three kinds) |
| ~~Payment reversal / recorded refund~~ | — | — | — | — | — | **Moved to §1 by ORG.8C (§61):** request -> approve (approval executes); self FORBIDDEN; fresh MFA on approval; free-text reason (no closed reason code in v1) |
| Ledger post | `finance.ledger.post` | school_admin, accountant | yes | description | whole-School | NO_APPROVAL; keep |
| Ledger reverse | `finance.ledger.reverse` | school_admin | yes | optional text | whole-School | NO_APPROVAL; a subledger-owned entry is refused since ORG.8-P1 (G-1 closed); open: a reverser != poster rule for manual entries (owner decision, ADR 0071 §26.13) |
| Period close | `finance.periods.manage` | school_admin | yes + typed key | none | whole-School | NO_APPROVAL; keep (irreversible, no reopen) |
| Charge cancel / assessment void / late-fee void | `finance.charges.manage` | school_admin, accountant | no | optional text | whole-School | NO_APPROVAL; **G-8 (MEDIUM, ORG.8F §64)**: no fresh MFA, no closed reason, cached authority and no Guardian-beneficiary refusal. Recommended v1 (OD-8.3): DIRECT_WITH_ASSURANCE, no workflow |
| Fee-run cancel | `finance.fee_assessments.run` | school_admin, accountant | no | none | whole-School | NO_APPROVAL (pre-execution only) |
| Library fine void (ORG.8E: DIRECT_WITH_ASSURANCE, ADR 0076 §63) | `library.fines.void` | school_admin | **yes** (fresh code) | closed `reason_code` + bounded note (DB) | whole-School | NO_APPROVAL (OD-8.5 closed: no librarian request); current authority re-read; at most once; FEE source seam only |
| Student Attendance correction (ORG.8E: DIRECT_WITH_ASSURANCE, §63) | `attendance.manage` (Campus-adopted) / `attendance.teacher` (ownership) | school_admin, principal; teacher (own registers) | no | closed `reason_code` (entered_in_error, late_information, administrative_review; never why a Student was absent) + correction row (DB) | Campus by stored register Campus | NO_APPROVAL; no time window (OD-8.6 closed) |
| Staff Attendance correction | `hr.staff_attendance.manage` | school_admin, principal, hr_officer | no | closed code required | whole-School | NO_APPROVAL; keep (self refused) |
| Student status active <-> inactive (ORG.8E: DIRECT_WITH_ASSURANCE, §63) | `students.manage` | school_admin, principal (whole-School grants only) | **yes** | closed reason per direction + lifecycle event | whole-School (declared) | NO_APPROVAL (OD-8.7 closed); confirmation (web), current authority, row lock, no-op refused |
| Enrollment withdraw / cancel (ORG.8E: DIRECT_WITH_ASSURANCE, §63); complete stays ordinary | `enrollments.manage` | school_admin, principal (whole-School grants only) | **yes** (withdraw/cancel) | closed reason + lifecycle event (DB-required) | whole-School (declared) | NO_APPROVAL; confirmation (web), current authority |
| Campus transfer | `enrollments.manage` over both Campuses | school_admin, principal (whole-School, or a grant covering both Campuses) | no | none | dual-scope | NO_APPROVAL (dual-scope authority, blockers, immutable audit already) |
| Admissions accept / reject | `admissions.manage` (Campus-adopted) | school_admin, principal, admissions_officer | no | optional note, not audited | application Campus | NO_APPROVAL; recommend a closed rejection reason |
| Admissions conversion | `admissions.manage` | as above | no | none | whole-School (declared) | NO_APPROVAL |
| Marks paper lock | `examinations.marks.lock` | school_admin, principal | yes | none | whole-School | NO_APPROVAL; keep (irreversible) |
| Employee separation / rehire / archive | `hr.employees.assignments.manage` (whole-School) | hr_officer | no | status only | whole-School (declared) | NO_APPROVAL; recommend closed separation reason |
| Highly sensitive HR document write | HR document keys | school_admin, hr_officer | yes | — | whole-School | keep |
| School lifecycle | `platform.schools.manage` / `.onboard` | root / Platform Manager (onboard only) | yes + confirmation | closed for suspend/close | platform | keep (platform authority, never tenant business) |
| Module entitlements | `platform.school_entitlements.manage` | root, Platform Manager | yes + confirmation | closed | platform | keep |
| Platform role grant | `platform.role_grants.manage` | root | yes | none | platform | keep |
| Group governance | `platform.school_groups.manage`, `.school_group_grants.manage` | root | **no** | none | platform | G-5 / OD-8.9: production policy prerequisite (ORG.8F §64) |

## 3. Gaps found (recorded, not fixed)

| Id | Severity | Finding | Recommended handling |
|---|---|---|---|
| G-1 | MEDIUM (integrity, pre-existing) — **CLOSED by ORG.8-P1 (ADR 0076 §57)** | `LedgerAdministrationService::reverse()` reverses ANY journal entry of the School. Only Payment settlement entries are guarded (`journal_entries_payment_reversal_guard`). A Charge recognition, fee adjustment, payment correction, payroll posting or statutory posting entry can be reversed directly, leaving the owning record live (reproduced read-only: the Charge stays uncancelled and a later cancel is refused as "already cancelled"). Needs `finance.ledger.reverse` (school_admin only) and fresh MFA; audited; repairable by reversing the reversal. | Prerequisite slice ORG.8-P1 (owner authorization): one generic subledger-ownership guard on journal reversal, mirroring the Payment guard. Before any ledger or Finance approval work. |
| G-2 | MEDIUM (SoD) — **CLOSED by ORG.8B (ADR 0076 §60)** | Payroll SoD compares only the run creator (`prepared_by_user_id`) with the approver. A user holding prepare + approve can record manual overrides or correction deltas on, or recalculate, another preparer's run and then approve it. Approver may also approve own pay (ADR 0071 §26.13). | ORG.8B: per-item prior-participation SoD (creator, calculator, override/delta authors never approve). |
| G-3 | LOW — **accepted v1 residual (ORG.8F §64.6)** | Fee concession revoke and adjustment cancellation have no SoD check, no current-authority re-read and no closed reason (both need fresh MFA, are school_admin-only and move value towards the School); reject and withdraw need no fresh MFA. | Future Finance hardening: current-authority re-read and a closed reason; SoD stays an owner decision. |
| G-4 | LOW — **CLOSED by ORG.8D (ADR 0076 §62)** | `hr.leave.manage` holders allocate, adjust, assign policy, submit on behalf and administratively cancel for their OWN employment without a self-refusal (only decisions are self-guarded). | ORG.8D: extend the HR self-administration rule (rule 95) to own leave balance writes. |
| G-5 | LOW (platform) — **production policy prerequisite (OD-8.9, ORG.8F §64.11)** | Group governance writes need no fresh MFA, confirmation or reason; the self-grant refusal is a plain validation error and is not audited. Root-only and audited on the platform ledger; a Group grant confers School elevation. | Platform hygiene slice before production: fresh MFA + confirmation on grant/revoke/archive/membership, closed reason, audited self-grant refusal. |
| G-6 | **MEDIUM (integrity) — re-classified by ORG.8F (§64.12)**: application paths closed by ORG.8E (row lock, closed reasons, fresh MFA, lifecycle events); the database still accepts, as the runtime role, a `students.status` change with no lifecycle event, any `students.status` / `student_enrollments.status` value, a directly INSERTED withdrawn/cancelled placement with no event, and a terminal placement with no `ends_on` | Reproduced as `school_os_app` (ORG.8F). These are structural invariants PostgreSQL can enforce without knowing the human actor (the existing `student_enrollments_lifecycle_evidence` trigger proves the pattern); only the actor's identity is a genuine trust boundary. | **Technical follow-up before ORG.8 closes**: one narrow slice (ORG.8G). |
| G-6b | LOW (data quality, ORG.8F §64.13) | The withdraw/cancel/complete services accept any `ends_on` on or after the placement start: after the academic year end or far in the future. Effect: the retention exit date moves later (over-retention, never earlier) and date-based fee/Campus attribution treats the placement as running until that date. | Bound `ends_on` to the placement's academic year (optionally in the ORG.8G slice). |
| G-7 | MEDIUM (owner-raised) — **CLOSED by ORG.8A-R1 for account-linked Guardians (ADR 0076 §59); residual M-1 (a staff parent who never links their account) recorded for an owner policy decision; since ORG.8C (§61) M-1 also bounds Payment correction decisions** | Maker/checker excludes the requester only; a staff member who is also the Student's linked Guardian can approve a colleague's concession for that Student. | Owner decision: an optional beneficiary-relationship participation class on the shared foundation. M-1 is a **production policy prerequisite** (ORG.8F §64.7). |
| G-8 | **MEDIUM (related party, ORG.8F §64.9)** | Charge cancel, fee-assessment void and late-fee void (`finance.charges.manage`: school_admin, accountant) need no fresh MFA and no closed reason, read authority from the cache and have no Guardian-beneficiary refusal: an account-linked staff Guardian can cancel or void their own child's unpaid Charge (audited, single actor). | Owner decision OD-8.3; recommended DIRECT_WITH_ASSURANCE (closed reason, fresh MFA, current authority, Guardian-beneficiary refusal); then the ORG.8G slice. |
| G-9 | LOW (ORG.8F §64.14) | The `setManager` / manager-path Leave decision lock-order deadlock (ADR 0076 §62.7): PostgreSQL aborts one side (40P01), the whole transaction rolls back, the user sees a 500 and the idempotency key is released. No state corruption. | HR post-v1 hardening. |
| G-10 | LOW (ORG.8F §64.15) | Communications approval has no database maker/checker backstop (service-only `SelfApprovalNotAllowedException`) although both actor columns exist; Admissions decisions have no status CHECK, no decider column and a free-text note only. | Future hardening; no money effect. |

## 4. Proposed new capability families (names only; none created)

Only where a genuine maker/checker split is recommended. Each is classed
`authority` (ADR 0076 §16) and is never held by an operational role.

| Proposed key | Purpose | Would be held by | Campus |
|---|---|---|---|
| `finance.payment_corrections.request` | **BUILT (ORG.8C):** request any of the three kinds | school_admin, accountant | whole-School (never adopted; not listed in `CAMPUS_INCOMPATIBLE`, see ADR 0076 §61.9) |
| `finance.payment_corrections.approve` | **BUILT (ORG.8C):** decide another person's request | school_admin | whole-School |
| `finance.payment_corrections.view` | **BUILT (ORG.8C):** the queue and the Payment's requests | school_admin, accountant | whole-School |
| ~~`library.fine_voids.request`~~ | **NOT CREATED: OD-8.5 closed (ORG.8E), the void stays direct with assurance** | — | — |
| ~~`students.status.request` / `.approve`~~ | **NOT CREATED: OD-8.7 closed (ORG.8E), lifecycle actions stay direct with assurance** | — | — |

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

## 6. ORG.8 owner-decision register (reconciled by ORG.8F, 2026-10-22)

States: CLOSED, DEFERRED, PRODUCTION_POLICY_PREREQUISITE,
TECHNICAL_FOLLOW_UP_REQUIRED.

| Decision | Outcome |
|---|---|
| OD-8.1 payment reversal/refund maker/checker | CLOSED: Option 1, request -> another person approves, for reallocation, reversal and refund (ORG.8C, ADR 0076 §61) |
| OD-8.2 manual ledger reverser != poster | DEFERRED: future Finance hardening; needs a structural `posted_by_user_id` on `journal_entries` first (today the poster is in the audit event only). Fresh MFA on post and reverse and the ORG.8-P1 subledger guard bound v1 |
| OD-8.3 Charge cancel / void assurance | TECHNICAL_FOLLOW_UP_REQUIRED (G-8): owner decision needed; recommended DIRECT_WITH_ASSURANCE, no request/approve workflow |
| OD-8.4 payroll approve != post; approval of a run containing one's own pay | PRODUCTION_POLICY_PREREQUISITE: Payroll production is already gated (HRX-L4, E45); recommended approver != poster and refusing approval of a run with the approver's own result, plus a closed reverse reason |
| OD-8.5 librarian fine-void request | CLOSED: none in v1; void stays direct with assurance (ORG.8E) |
| OD-8.6 attendance correction window | CLOSED: no time-based approval window in v1 (ORG.8E) |
| OD-8.7 Student status requests (D-13) | CLOSED: no request/approve workflow in v1 (ORG.8E) |
| OD-8.8 Campus principal / cross-Campus Leave authority | CLOSED: Option A, Leave decisions need whole-School authority (ORG.8D, ADR 0076 §62.10) |
| OD-8.9 Group governance fresh assurance (G-5) | PRODUCTION_POLICY_PREREQUISITE: platform hygiene before production |
| M-1 staff parent who never links their account | PRODUCTION_POLICY_PREREQUISITE: a governed related-party source of truth (ADR 0076 §64.7) |
