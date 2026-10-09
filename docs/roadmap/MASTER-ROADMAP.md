# School OS — Master Roadmap

This roadmap sequences future phases by dependency, not by business
priority alone — a module cannot be built correctly before what it
depends on exists (`docs/architecture/DOMAIN-MAP.md`). Each phase below
records its own status; a phase with no recorded status or
implementation notes has not been started. Nothing here is a
commitment to timing, only to order and scope.

## Phase Zero closeout status (authoritative; ADR 0061, 2026-09-29)

Phase Zero is complete only when every active phase below is complete or
closed under its accepted scope decisions (including accepted deferrals)
**and** Phase 0O/O1 is satisfied (ADR 0058). A closed phase with deferred
scope is **not** a claim that the deferred capability exists.

| Phase | Phase Zero status |
|---|---|
| 0A | COMPLETE |
| 0B | COMPLETE |
| 0C | COMPLETE |
| 0D | COMPLETE |
| 0E | COMPLETE |
| 0F | COMPLETE / PUBLISHED |
| 0G | COMPLETE |
| 0H | **CLOSED FOR PHASE ZERO** — active foundation scope delivered; remaining Academic / Examination depth (Lesson Planning, StudentMark, results, report cards, transcripts, 0H.4D-P3) deferred post-v1 (ADR 0061); P3 and internal StudentMark later reopened by ADR 0068 as RES (row 5) |
| 0I | COMPLETE — Submission cancelled / out of scope |
| 0J | ENGINEERING COMPLETE — the recorded ESI disability-threshold legal deferral stands (ADR 0036) |
| 0K | CLOSED — Health / Safety deferred behind their recorded gates |
| 0L | COMPLETE — optional / gated future scope preserved |
| 0M | **CLOSED FOR PHASE ZERO** — real model providers, real agents and AI write tools deferred post-v1 (ADR 0061); fail-closed `NullProvider` state preserved |
| 0N | COMPLETE |
| 0O | **ACTIVE — CLOSEOUT BLOCKED**: O1 evidence outstanding (ADR 0058 §6) |

**Phase Zero: NOT COMPLETE.** Phase 0O/O1 remains.
- **Only 0O is active.** No other Phase Zero product implementation phase
  is required for closure beyond the accepted Phase 0O work and evidence
  programme.
- **0O closeout never reopens 0H or 0M.**

**Phase 0O production hardening is PARKED (owner decision, 2026-09-29).**
Product development resumes. This is scheduling only; nothing below is
waived or complete.
- **Parked rows.** ADR 0058 rows E02, E03, E05, E07–E23 and E29 stay open
  as final-hardening obligations. O16 qualification is not repeated during
  product development.
- **E16.** The decision `OWNER-0O-E16-2026-09-29` is historical evidence. It
  may lapse (2026-10-29); final qualification needs a fresh scan and
  decision on its own date.
- **E03 is GOVERNANCE_REQUIRED — DEFERRED TO FINAL PROJECT / PRODUCTION
  HARDENING.** The temporary `main` ruleset created on 2026-09-29 was
  removed the same day for active development. The E03 evidence merged by
  PR #1 was reverted (`5d8dce3`). Final hardening must protect `main`, block
  force-push and deletion, restore the pull-request path, record E03
  evidence and run E02 from protected `main`.
- **Legal and security gates still bind** product work:
  - the Phase 0M reopening gate;
  - E17 before any production SendGrid use;
  - E21 before any retention period. E21.1 (2026-10-01, docs only) issued
    the decision request `docs/security/E21-RETENTION-DECISION-REQUEST.md`
    (decisions E21-D0–D13). E21 is still OPEN. *(2026-10-08: the three
    latent email-prune defects L1–L3 were fixed in E21.2A, 2026-10-01; see
    the E21 decision request "L1–L3 are fixed". E21 needs legal
    ratification and production configuration, not engineering.)*;
  - no real School data to external providers;
  - no deployment, purchases or production secrets without explicit owner
    authorization.

## Post-foundation product programmes

Named programmes, not phase numbers: historical Phase 1, 5, 8A, 9 and 10
numbers are already taken (Phase 1 = 0F, Phase 5 = Communications, 8A/9 =
0J, 10 = 0K). They are ordered by dependency. Each programme starts with a
contract checkpoint.

| Order | Programme | Status |
|---|---|---|
| 1 | **FEE — Fee Management** (fee heads, structures, bulk assessment, concessions, receipts, staff statements, late fees) | **DEVELOPMENT CLOSED** (FEE.0–FEE.5, 2026-09-30; ADR 0062). **Not production-ready:** legal E21, E30, E31, E32 and governance/release E03, E02/E15, E16 remain open |
| 2 | **TCH — Teacher Identity & Ownership-Based Authorization** | **DEVELOPMENT CLOSED** (TCH.0–TCH.6, 2026-10-01; ADR 0063 §38). Built: ActingEmployee identity (TCH.1), TeachingAssignment ownership (TCH.2), the production `teacher` role (four owned-scope capabilities, never a role-name check) and owned teacher access to Curriculum Delivery (TCH.3), Attendance (TCH.4), Learning Content (TCH.5C) and Assignments (TCH.5D) on the LMS owner/audience persistence (TCH.5B). Every owned access needs capability AND verified ActingEmployee AND TeachingAssignment; the role alone grants nothing, and Timetable is never authority. **Production readiness (ADR 0063 §39, 2026-10-01): PRODUCTION READY EXCEPT DOCUMENTED EXTERNAL GATES** — teacher Attendance is **BLOCKED by open legal/compliance determination TCH-L1** (now ADR 0058 register **E33**), enforced by process only. The one `teacher` role also carries `attendance.teacher`. **Owner decision (ADR 0063 §40): no production `teacher` role grants while E33 / TCH-L1 is OPEN**, and no role split or Attendance gate. The role is implemented and production-capable; the blocker is external, not a technical deficiency. **E33 / TCH-L1 DETERMINED — APPROVED WITH CONDITIONS** (Lead Privacy Counsel & DPO, 2026-10-07; ADR 0063 §42): teacher Attendance development permitted within scope; production only after the nine controls are verified. **TCH ATTENDANCE PRODUCTION CONTROLS — BUILT** (2026-10-07, ADR 0063 §43): MFA on every My Attendance route, every owned read audited, the bearer-token teacher API development-only; all nine controls PASS in the repository. Production `teacher` grants still wait on production-candidate re-verification, E21 and the platform checklist. No effect on StudentMark. TCH history retention waits on the platform-wide legal item **E21** (ADR 0058). TCH.6 fixed one closure defect (non-identical not-found bodies on owned surfaces). LMS Submission remains cancelled and outside TCH |
| 3 | HRX — Leave & staff attendance | **HRX.0 CONTRACT — PUBLISHED / CLOSED** (ADR 0065, 2026-10-03, docs only). **HRX.1 LEAVE FOUNDATION — PUBLISHED / CLOSED** (2026-10-03; ADR 0065 §22 final owner decisions). **HRX.2 LEAVE REQUESTS & APPROVAL — PUBLISHED / CLOSED** (2026-10-03; ADR 0065 §23). **HRX.3 STAFF ATTENDANCE — PUBLISHED / CLOSED** (2026-10-03; ADR 0065 §24: exact half-day evidence, CAS corrections with append-only history, Leave ↔ Attendance through application contracts). **HRX.4 STAFF SELF-SERVICE — PUBLISHED / CLOSED** (2026-10-04; ADR 0065 §25: ActingEmployee-only identity, own leave view/submit/withdraw/cancel, read-only own attendance, posted own payslips, separate `staff_self_service` bundle). **HRX.5 PAYROLL LOSS-OF-PAY INTEGRATION — MECHANISM PUBLISHED / CLOSED** (2026-10-04; ADR 0065 §26: HRX→Payroll evidence contract implemented, versioned, fingerprinted and snapshotted; posted payroll immutable; differences detected; **automatic wage deduction disabled / not legally activated; EPFO NCP conversion pending current-rule validation; HRX-L4 legal activation pending**). **HRX.6 RETENTION, READINESS & CLOSURE — PUBLISHED / CLOSED** (2026-10-04; ADR 0065 §27: Leave and Staff Attendance evidence join the existing E21-D9 employee retention run through two narrow, separation-floored database functions; no runtime DELETE, no cascade; Payroll's HRX snapshot follows Payroll retention). **HRX — LEAVE & STAFF ATTENDANCE IMPLEMENTATION PUBLISHED / CLOSED. HRX.5 MECHANISM CLOSED / LEGAL ACTIVATION GATED.** HRX-L1–L4 remain OPEN; E21 qualified ratification and production retention configuration pending (`docs/modules/HRX-READINESS-AND-CLOSURE.md`). Health-data features and biometric attendance stay out of v1 behind legal gates; Payroll loss-of-pay is HRX.5. No TCH/E33 change |
| 4 | OPF — Operational fee integrations (Transport, Hostel, Library fines, Admissions fee) | **PUBLISHED / CLOSED — DEVELOPMENT** (OPF.0–OPF.5, 2026-10-06; ADR 0067 §31). **Not production-ready:** legal E21, E30, E31, E32 and E34 (Library fines) remain open. **OPF.0 CONTRACT — PUBLISHED** (ADR 0067, 2026-10-05, docs only; owner decisions D1–D9 adopted). **OPF.1 TRANSPORT FEE SELECTION — PUBLISHED** (2026-10-05, ADR 0067 §27). **OPF.2 HOSTEL FEE SELECTION — PUBLISHED** (2026-10-05, ADR 0067 §28). **OPF.3 ADMISSION FEE AT CONVERSION — PUBLISHED** (2026-10-06, ADR 0067 §29). **OPF.4 LIBRARY OVERDUE FINES — PUBLISHED** (2026-10-06, ADR 0067 §30; E34 production gate unresolved). **OPF.5 CLOSURE AUDIT — PUBLISHED / CLOSED** (2026-10-06, ADR 0067 §31; test, rollback and documentation corrections only). Prerequisite FEE.1–FEE.2 satisfied. Production gated by E21, E30, E31, E32 and the new E34 (Library fines); development permitted |
| 5 | RES — Assessment & results (P3 → StudentMark → results → report cards → transcripts) | **RES.0 REOPENING AUDIT — COMPLETE** (2026-10-06). **RES.0B REOPENING CONTRACT — PUBLISHED** (ADR 0068, 2026-10-06, docs only; owner decisions R1–R20): reopens only P3 and internal StudentMark. **RES.1 P3 AS-OF-DATE OFFERING ELIGIBILITY — PUBLISHED** (2026-10-06, ADR 0068 §18; Students read seam, consumed by RES.2–RES.4). **RES-L0 — RESOLVED: CURRENT WITH CHANGES** (Lead Privacy Counsel & DPO, 2026-10-07; ADR 0068 §19). **RES.2 ADMIN/INTERNAL STUDENTMARK ENTRY — PUBLISHED (DEVELOPMENT ONLY / NOT PRODUCTION)** (2026-10-07, ADR 0068 §20; production gated by RES-L1; retention RES-L8; teachers RES-L2). **RES.3 PER-PAPER LOCK & APPEND-ONLY CORRECTIONS — COMPLETE (DEVELOPMENT ONLY / NOT PRODUCTION)** (2026-10-07, ADR 0068 §21; production gated by RES-L1). **RES.4 READINESS — REQUESTS DRAFTED, NOT SENT** (2026-10-07, ADR 0068 §22, docs only): RES-L2, RES-L0 teacher re-review and TCH-L1 requests in `docs/security/`; electives technically blocked (no elective ownership fact). **E33 / TCH-L1 DETERMINED — APPROVED WITH CONDITIONS** (2026-10-07; teacher Attendance only, no StudentMark effect). Elective teacher ownership technically READY since TCH-E (2026-10-07, ADR 0063 §45). **RES.4 — IMPLEMENTED FOR DEVELOPMENT / PRODUCTION BLOCKED PENDING RES-L2 + TEACHER RES-L0 + RES-L1** (2026-10-07, ADR 0068 §25; owner-authorised engineering development, not a legal determination: E37 and the E35 teacher re-review unresolved; production refused in code). **RES.4A teacher paper discovery — IMPLEMENTED FOR DEVELOPMENT** (2026-10-07, ADR 0068 §26). **RES.5 CLOSURE AUDIT — RES CURRENT REOPENED SCOPE (P3 + internal StudentMark) CLOSED** (2026-10-07, ADR 0068 §27; development only; all StudentMark refused in production code pending RES-L1). The Assessment & Results family is NOT complete: production StudentMark (RES-L1), teacher clearance (E37 + E35 re-review), results (RES-L4), report cards (RES-L5), transcripts (RES-L6), Student/Guardian access (RES-L7), retention (RES-L8) and statutory rules (RES-L9) are future gated programmes, never auto-started. Results, report cards, transcripts and Student/Guardian access are not sequenced (RES-L4 – RES-L7) |
| 6 | POR — Guardian/Student portal | **POR.0 CONTRACT — PUBLISHED** (ADR 0070, 2026-10-08, documentation only; owner decisions fixed: a closed `guardian`-scope system role delivering `portal.*` capabilities, ActingGuardian (Identity), GuardianStudentScope (Guardians; fail-closed `is_legal_guardian` pending POR-L1), one School per session, web/session only, MFA for Attendance and Fees, mandatory Guardian off-boarding). **POR-L1 (E46) DRAFT REQUEST — NOT SENT, NOT ANSWERED**: blocks production of every Guardian surface and design/development of Student accounts. **POR.1 GUARDIAN FOUNDATION + READ-ONLY COMMUNICATIONS INBOX — IMPLEMENTED FOR DEVELOPMENT** (2026-10-08, ADR 0070 §24): fourth `guardian` role scope (database-enforced), `portal.communications.view`, ActingGuardian, Guardian off-boarding, staff/Guardian lifecycle split, `/app/school-setup` gated, `PortalAvailability` (refused in code outside local/testing). **POR.2 GUARDIANSTUDENTSCOPE + LINKED-STUDENT ATTENDANCE — IMPLEMENTED FOR DEVELOPMENT** (2026-10-09, ADR 0070 §25): `portal.attendance.view`, live per-Student scope (legal guardian + active Student, fail-closed pending POR-L1), active academic year only (≤ 62 days), MFA, the same 404 for every other Student, `attendance.guardian.viewed`. **POR.3 GUARDIAN FEE STATEMENT & PAYMENTS APPLIED TO A STUDENT — IMPLEMENTED FOR DEVELOPMENT** (2026-10-09, ADR 0070 §26): `portal.fees.view`, Payments-owned Guardian read seam (staff services unchanged), active year + other years' unpaid charges, authoritative balances, only the amount of each payment applied to that Student (never a shared payment's total or a sibling), MFA. **POR.4 GUARDIAN CONVERSATIONS & REPLIES — IMPLEMENTED FOR DEVELOPMENT** (2026-10-09, ADR 0070 §27): `portal.communications.reply` (with `.view`), existing staff-started conversations the Guardian joined as the Guardian persona (no other Guardian; Student participants in live scope), idempotent text replies through the unchanged Communications writer (server-issued key, unique per School + sender), in-app only, MFA, per-User throttle, lock-ordered against off-boarding/unlink/removal/closure; Guardian-initiated conversations deferred (no contract). POR.5 (closure audit) awaits owner authorisation. Marks/results stay blocked by E39–E42 |

**TCH checkpoints (ADR 0063 §25)** -- all built; **TCH DEVELOPMENT CLOSED
by TCH.6 (2026-10-01, ADR 0063 §38)**. Curriculum Delivery, Attendance,
Learning Content and Assignments are the teacher-owned surfaces. Production
enablement of teacher Attendance stays blocked by open TCH-L1, and E21
(retention) stays open. *(2026-10-07: TCH-L1 determined APPROVED WITH
CONDITIONS; production now waits on the control evidence — see the TCH-L1
determination entry below.)*:
- **TCH.0 — Teacher Identity & Ownership-Based Authorization Contract**
  (ADR 0063, docs only). Closed.
- **TCH.1 — Verified ActingEmployee identity boundary** (HR). Closed
  (ADR 0063 §29): `ActingEmployeeResolver` (User → active membership →
  linked active Employee → exactly one eligible current employment;
  `resolve()` fresh, `hold()` locked inside a consumer's transaction);
  explicit, audited link/unlink (`employee.user_linked`/
  `employee.user_unlinked`) requiring an enabled User with an active
  membership; `user_id` removed from the generic Employee update; the
  Employee and Employment status catalogues database-constrained. Grants no
  access by itself.
- **TCH.2 — Authoritative TeachingAssignment foundation.** Closed
  (ADR 0063 §30, `docs/modules/TEACHING-ASSIGNMENTS.md`):
  - a dated Employee × Section × required SubjectOffering ownership fact
    with composite same-context foreign keys, forced RLS, no runtime DELETE
    and a history trigger;
  - overlap refused per Employee/Section/Offering under an advisory lock
    (no "one open row" index); co-teaching allowed;
  - create/end only, under `teaching.assignments.view`/`.manage`
    (school_admin, principal);
  - API, an admin page and audit.

  Dormant at TCH.2; TCH.3 adopts it for Curriculum Delivery.
- **TCH.3 — Production Teacher role + Curriculum Delivery adoption.**
  Closed (ADR 0063 §31):
  - the system `teacher` role carried only `curriculum.delivery.teacher`
    (TCH.4 adds `attendance.teacher`);
  - an owned `/my/` API and "My Curriculum Delivery" page require that
    capability AND a verified ActingEmployee AND a TeachingAssignment for
    the exact class on the delivery's dates;
  - writes use the same `CurriculumDeliveryService`, holding identity and
    ownership in its transaction;
  - Tier 1 is unchanged;
  - there is no `teacher_id` on deliveries.
- **TCH.4 — Attendance teacher adoption.** Development closed (ADR 0063 §32):
  - the `teacher` role carries exactly `curriculum.delivery.teacher` and
    `attendance.teacher`;
  - an owned `/my/` API and "My Attendance" page require that capability
    AND a verified ActingEmployee AND a TeachingAssignment for the exact
    class on the register's `attendance_date`;
  - writes use the same Attendance submission/correction services, holding
    identity and ownership in their transaction;
  - timetable and session `teacher_id` stay provenance only;
  - Tier 1 (`attendance.view`/`.manage`) is unchanged.

  **Teacher Attendance functionality is implemented but production
  enablement remains blocked by TCH-L1 until the required legal/compliance
  determination is recorded.** TCH-L1 is OPEN: not a development blocker, a
  production blocker.
- **TCH.5 — LMS teaching adoption.** Built as TCH.5A–TCH.5D (all closed).
  - **TCH.5A — LMS teacher ownership contract.** Published / closed (ADR
    0063 §33 audit, §34 owner resolution; docs only). D-14 is resolved:
    - teacher-authored Learning Content and Assignments carry an immutable
      owner Employee and an immutable one-or-more Section audience (bridge);
    - writes are owner-only, with current TeachingAssignment coverage of
      every audience Section on the School-local date;
    - published rows are readable by current teachers of an audience
      Section;
    - legacy/admin rows keep a NULL owner and Offering-wide meaning, with
      no backfill;
    - offering-only teacher authority is rejected;
    - two separate capabilities (`lms.content.teacher`,
      `lms.assignments.teacher`) were planned (since added by TCH.5C and
      TCH.5D).
  - **TCH.5B — LMS ownership & audience persistence foundation.**
    Closed (ADR 0063 §35):
    - an immutable `owner_employee_id` on `learning_content`/`assignments`;
    - immutable Section audience bridges, pinned to the Offering context by
      composite FKs;
    - owned ⇔ ≥ 1 audience, enforced at commit;
    - forced RLS; `down()` refuses while owned data exists;
    - the Documents → LMS parent-authorization seam;
    - Learning Content and Assignment re-tiered to Sensitive.

    Dormant: no teacher capability, route or page; no backfill.
  - **TCH.5C — Learning Content teacher adoption** (`lms.content.teacher`).
    Closed (ADR 0063 §36):
    - the `teacher` role carries `curriculum.delivery.teacher`,
      `attendance.teacher` and `lms.content.teacher`;
    - teachers create Section-targeted rows owned by their ActingEmployee
      for Sections they teach today;
    - writes are owner-only while every audience Section is taught, held
      in the service's transaction in sorted Section order;
    - teachers read their own rows, published rows for a taught Section, and
      published Offering-wide rows of a taught Offering;
    - attachments follow the parent through `LmsParentResourceAuthorization`;
    - Tier 1 is unchanged.

    Learning Content teacher adoption is implemented.
  - **TCH.5D — Assignment teacher adoption** (`lms.assignments.teacher`).
    Closed (ADR 0063 §37). The TCH.5C rule for staff-authored
    Assignments:
    - the `teacher` role carries four owned-scope capabilities;
    - `published` is the only shared status, and `draft`/`closed` are
      owner-only;
    - `due_on` is never an authorization date;
    - attachments follow the parent Assignment;
    - Tier 1 is unchanged.

    Assignment teacher adoption is implemented.
  - LMS Submission stays cancelled and outside every checkpoint.
- **TCH.6 — TCH closure audit.** Closed (ADR 0063 §38, 2026-10-01):
  - repository-wide audit of the TCH.0–TCH.5D chain against ADR 0063;
  - one closure defect fixed: an owned resource the teacher may not see now
    answers with exactly the unknown-id 404 body (Attendance correction,
    submission and roster preview; Curriculum Delivery writes; LMS
    attachments through Documents), plus a 404 instead of a database error
    for a malformed id on the web Curriculum Delivery page;
  - stale "no Teacher role / admin-only" statements corrected;
  - full isolated regression on the closure code.

  **TCH — DEVELOPMENT CLOSED.** Production clearance is separate: teacher
  Attendance stays blocked by TCH-L1, and E21 stays open.
- **TCH post-closure production-readiness audit** (ADR 0063 §39,
  2026-10-01, docs only): PRODUCTION READY EXCEPT DOCUMENTED EXTERNAL GATES.
  - TCH-L1 is carried into the ADR 0058 register as **E33**.
  - The reviewer's decision record and technical control facts are
    recorded in §39.4.
  - E21 is reduced to five TCH retention questions (§39.5).
  - No code change and no new blocker.
- **TCH production Teacher-role gate** (ADR 0063 §40, 2026-10-01, docs
  only): owner decision — no production `teacher` grants while E33 /
  TCH-L1 is OPEN, and no role split or feature gate. After APPROVED the
  existing role may be granted, subject to E21 and the platform checklist.
- **TCH-L1 / E33 determination** (ADR 0063 §42, 2026-10-07, docs only):
  **APPROVED WITH CONDITIONS** (Lead Privacy Counsel & DPO;
  `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`).
  - Teacher **Attendance** only: assignment-scoped, deny by default, School-
    scoped; no School-wide browsing, export, analytics or override.
  - Development permitted within scope.
  - Production only after nine controls are implemented and verified. Not
    yet evidenced: **MFA** on the teacher Attendance surfaces (web and
    `/api/v1`) and **audit of teacher reads**. Until then no production
    `teacher` grants (ADR 0063 §42.5); E21 still applies.
- **TCH Attendance production controls** (ADR 0063 §43, 2026-10-07):
  - `capability:attendance.teacher` + `mfa-page` on every `/app/my-attendance`
    page and post (the `MfaRequired` page otherwise); MFA never replaces
    ownership.
  - The bearer-token teacher API is development only (a token cannot prove
    MFA, ADR 0049; double-guarded `teacher-attendance-api`).
  - Every owned read audited (`attendance.teacher.*`, ids and counts only).
  - All nine E33 controls PASS in the repository. Production `teacher`
    grants: production-candidate re-verification, E21, platform checklist.
  - Regression cadence: round 1/5 after the RES.3 checkpoint.
- **TCH Attendance production-candidate verification** (ADR 0063 §44,
  2026-10-07): **E33 TECHNICALLY READY FOR PRODUCTION-CANDIDATE SIGN-OFF**.
  - All nine controls PASS from the call chain.
  - Three narrow corrections:
    - MFA assurance bound to the current factor (a reset forces a new
      sign-in; ADR 0037 amendment);
    - production refuses the dev teacher-API flag;
    - raw-SQL Attendance RLS proof.
  - Not go-live: deployment re-verification, E21 and the other O1 rows
    remain; production `teacher` grants stay process-gated.
  - Regression cadence: round 2/5.
- **TCH-E — Dated elective teaching ownership** (ADR 0063 §45, 2026-10-07;
  owner-authorised technical prerequisite):
  - `elective_teaching_assignments` (Employee × elective Offering,
    Offering-wide, dated, history-frozen, forced RLS, E21-D6 retention);
  - `TeachingOwnership::electivePeriods()` / `holdElective()` /
    `holdOffering()`;
  - admin page `/app/elective-teaching-assignments` under
    `teaching.assignments.*`.
  - Grants no access and no StudentMark processing; elective teacher
    ownership is now technically READY for a future RES.4.
  - Regression cadence: round 1/5 after the `10b2e04` checkpoint.
  - No fixed expiry; re-review triggers recorded.
  - **No effect on teacher StudentMark** (RES-L2, RES-L0 teacher re-review
    unresolved; RES.4 NOT AUTHORISED).

TCH reopens only the ADR 0061 §2.3 item "teacher identity and
ownership-based authorization". Lesson Planning, StudentMark/RES, POR, HRX,
generic staff-role expansion, tenant-custom roles and LMS Submission stay
outside it.

**HRX checkpoints (ADR 0065 §20)** -- all built; **HRX — LEAVE & STAFF ATTENDANCE IMPLEMENTATION PUBLISHED / CLOSED** (HRX.6, 2026-10-04); **HRX.5 MECHANISM CLOSED / LEGAL ACTIVATION GATED**:
- **HRX.0 — Leave & Staff Attendance Contract** (ADR 0065, docs only).
  Closed (2026-10-03). Decided: `App\Domain\Leave` and
  `App\Domain\StaffAttendance` under HR ownership, one-way dependencies
  (Payroll reads only an explicit HRX contract); School-configured leave
  types and policies assigned per EmploymentRecord; an append-only,
  integer half-day leave ledger (no stored mutable balance, no overdraft);
  single-level manager approval from the reporting line (capability AND
  fresh ownership, no self-approval) plus an administrative path; daily
  staff attendance with compare-and-swap corrections and an append-only
  history; no medical detail, no free-text reason and no biometrics in v1;
  D9 retention for Employee evidence, tenant lifetime for configuration.
  Owner decisions with recommended defaults: leave-year start month,
  mid-year proration, and a new `staff` self-service role (the `teacher`
  role is not changed while E33 is open).
- **HRX.1 — Leave Foundation.** Published / closed (2026-10-03).
  - **Final owner decisions (ADR 0065 §22):**
    - the leave year is School-configured, start month 1..12, default
      April. This is a product default, not statutory, and has no Finance
      dependency. It is prospectively configurable: materialized leave
      years are immutable, and a start-month change takes effect on a
      future first-of-month boundary after every opened year, through an
      explicit transition year (§22.1a correction);
    - no automatic proration: a mid-year joiner gets an explicit
      allocation of exact units;
    - HRX.4 creates a separate `staff_self_service` role;
    - day portions are `full` = 2, `first_half` = 1, `second_half` = 1
      integer units.
  - **Built (`App\Domain\Leave`):**
    - ten forced-RLS tables;
    - leave types, versioned policies and effective-dated assignments on
      the EmploymentRecord;
    - the staff working calendar;
    - explicit allocations and a once-per-year annual run;
    - closed-reason adjustments;
    - an append-only ledger with a derived balance, where the database
      refuses a negative balance;
    - carry-forward/expiry schema and pure calculation only (the
      year-close run is HRX.2);
    - capabilities `hr.leave.configure/.view/.manage`;
    - 25 API operations.
  - **HRX.2 forward invariant (ADR 0065 §22.6):** approved leave keeps its
    exact chargeable dates, portions and units. Later calendar, policy or
    leave-year changes never rewrite it.
  - **Not built in HRX.1:** the admin UI, requests and approvals (all
    delivered in HRX.2), and Staff Attendance. HRX-L1–L4 stay open; see
    `docs/security/HRX-L3-STATUTORY-LEAVE-APPLICABILITY-MATRIX.md`.
- **HRX.2 — Leave Requests & Approval.** Published / closed (2026-10-03,
  ADR 0065 §23).
  - **Requests:**
    - submitted, then approved / rejected / withdrawn, then (approved)
      cancelled; no draft, edit or partial cancellation;
    - dates plus integer half-day portions;
    - live requests never overlap (database);
    - closed, neutral reason codes only.
  - **Approval:**
    - an immutable chargeable-day snapshot (calendar, year and policy
      version per date);
    - one consumption per leave year; cross-year requests are split by
      date;
    - cancellation is an append-only reversal.
  - **Who decides:**
    - the manager path is `hr.leave.approve` plus fresh reporting
      ownership from HR's `ReportingLine` (private 404 otherwise);
    - the administrative path is `hr.leave.manage`;
    - no self-decision on either path (database CHECK).
  - **Year close:**
    - preview, then execute once;
    - carry-forward up to the cap, the rest lapses;
    - refused while submitted requests remain, out of order, or before the
      next year is open;
    - the closed year is sealed;
    - a cancellation after the close is reconciled under the original
      close's policy terms, never by rerunning the close.
  - **Interfaces and evidence:**
    - outbox events `leave.request.approved.v1` and
      `leave.request.cancelled.v1` (not webhooks);
    - the Leave administration UI and the manager approvals page;
    - 40 Leave API operations.
  - **Not executed:** the in-year lapse of carried units (recorded only).
    Self-service and Payroll loss-of-pay are not built (Staff Attendance
    followed in HRX.3). HRX-L1–L4 stay open.
- **HRX.3 — Staff Attendance.** Published / closed (2026-10-03, ADR 0065
  §24, decisions recorded before coding).
  - **Model:** one record per EmploymentRecord × date, each half
    `present`, `absent` or null (no evidence). Leave, holidays and weekly
    offs are never stored; the read model derives them per half, with a
    daily summary, and never hides the recorded evidence underneath.
  - **Writes** (`hr.staff_attendance.manage`, administrative only): single
    record and the all-or-nothing bulk daily register. No future date, only
    working halves, only an employment in force, never a half on approved
    leave. An existing record changes only by correction.
  - **Corrections:** compare-and-swap on the version plus an append-only
    correction row (both halves before/after, closed neutral reason). The
    database refuses any other UPDATE and every DELETE, including raw SQL
    with the runtime role.
  - **Leave relationship:**
    - recorded presence blocks approving leave over the same half, through
      Leave's own dependency-inverted port;
    - recorded absence never blocks and stays underneath the leave;
    - approved leave blocks attendance on the same half only;
    - one cross-domain day lock serializes the two;
    - neither domain writes the other's tables.
  - **Interfaces and evidence:**
    - `hr.staff_attendance.view` / `.manage` on `school_admin` and
      `principal`;
    - 6 API operations and the `/app/staff-attendance` register and history
      pages;
    - audit, and the outbox event `staff_attendance.corrected.v1` (not a
      webhook);
    - forced RLS on two tables; D9 retention (`staff_attendance_evidence`,
      purge implemented in HRX.6).
  - **Not built:** Payroll loss-of-pay, clock times, devices and
    biometrics (own attendance followed in HRX.4). HRX-L1–L4 stay open.
- **HRX.4 — Staff Self-Service.** Published / closed (2026-10-04, ADR 0065
  §25, decisions recorded before coding).
  - **Identity:** every self-service action starts from the signed-in User
    and the trusted School, resolved only through `ActingEmployeeResolver`.
    Exactly one current EmploymentRecord, never a client-supplied
    Employee, EmploymentRecord or School. A capability gives permission;
    ActingEmployee gives ownership; both are required.
  - **Capabilities:** `hr.leave.self`, `hr.staff_attendance.self`,
    `payroll.payslips.self`, bundled in the new `staff_self_service` School
    role.
    - The role is granted through Settings → Staff accounts and is never
      checked by key.
    - `school_admin` holds the three only so it can grant the role.
    - `teacher` (E33) and `principal` are unchanged; `hr.leave.approve`
      stays separate.
  - **Own leave:** balances (the same ledger-derived calculation), types,
    own requests; submit for the acting EmploymentRecord through the same
    HRX.2 `submit()`; withdraw a submitted request; cancel an approved one
    before it starts. Recorded with the new decision path `self`
    (database: withdraw/cancel only, decider = requester). No approve or
    reject on this path.
  - **Own attendance:** read only; the HRX.3 per-half composition for the
    acting EmploymentRecord, without record ids or correction history.
  - **Own payslips:** posted runs only, through `PayslipReadService`'s new
    ownership path, sharing the existing assembly; audited
    `payroll.payslip.self_viewed`. Nothing is copied or recalculated.
  - **Privacy and lifecycle:**
    - one identical private 404 for unknown, unowned, other-School or no
      ActingEmployee;
    - no post-employment portal: access ends with the current employment;
    - School switching re-resolves everything.
  - **Interfaces:** 9 `/my/...` API operations and the `/app/my-leave`,
    `/app/my-staff-attendance` and `/app/my-payslips` pages, with one
    Dashboard link per own capability. No new table; one CHECK amendment
    on `leave_decisions`.
  - **Not built:** Payroll loss-of-pay / NCP (HRX.5), statutory automation,
    biometrics, devices and clock-in/out (the HRX retention purge followed
    in HRX.6).
    HRX-L1–L4 stay open.
- **HRX.5 — Payroll Loss-of-Pay Integration.** **Mechanism published /
  closed** (2026-10-04, ADR 0065 §26, decisions recorded before coding).
  **HRX-L4: legal activation pending.**
  - **HRX → Payroll evidence contract: implemented.**
    `PayrollAbsenceEvidenceReader` (`hrx_payroll_input.v1`): one effective
    class per half, so leave over a recorded absence counts once. It
    counts integer half-day units of approved paid/unpaid leave, recorded
    absence/presence and unresolved working time, clipped to the
    employment's dates, with completeness and a SHA-256 fingerprint.
  - **Snapshot:** `payroll_run_hrx_inputs` holds one row per regular-run
    result, replaced on recalculation, frozen from approval and deleted
    only with its result.
  - **Posted payroll: immutable.** Later leave or attendance changes
    surface as fingerprint differences (read API and an idempotent, audited
    check) and are corrected only through the existing correction run.
  - **Automatic wage deduction: disabled, not legally activated.** No
    evidence changes any amount (guarded).
  - **EPFO NCP conversion: pending current-rule validation.** ECR NCP
    keeps its legacy default `0`, and no half-day rounding exists.
  - **Concurrency:** a new `hrx.staff_employment` lock gives a payroll
    capture one consistent view of each employment's evidence.
  - **Record:** the legal baseline and the open questions are in
    `docs/security/HRX-L4-PAYROLL-LOSS-OF-PAY-DETERMINATION.md`.
- **HRX.6 — Retention, Readiness & Closure Audit.** **Published / closed**
  (2026-10-04, ADR 0065 §27). **HRX — LEAVE & STAFF ATTENDANCE
  IMPLEMENTATION PUBLISHED / CLOSED. HRX.5 MECHANISM CLOSED / LEGAL
  ACTIVATION GATED.**
  - **E21-D9 for HRX:** one Employee's Leave evidence (assignments, ledger,
    requests with days and decisions, close items and reconciliations) and
    Staff Attendance evidence (records with their corrections) expire 8
    years after final separation, as participants of
    `platform:employee-retention-prune` (and the reviewed erasure case),
    before HR's own purge. No second engine, no HRX setting.
  - **Privileged path only:** two SECURITY DEFINER functions re-prove the
    tenant, the D9 Employee floor, the HRX locks and that every row is
    older than the cutoff (a late write keeps its unit). The runtime role
    gains no DELETE; no cascade is added; causally complete, idempotent,
    tenant-safe; counts-only metrics and dry run.
  - **Hardened (ADR 0065 §27.10):**
    - the runtime role cannot EXECUTE the purge functions; the run calls
      them on the migration/owner connection;
    - the functions refuse any session user without the owner's
      privileges;
    - (E21-RH.3, ADR 0066 §11: superseded by authoritative `retention_holds`;
      HRX refuses an active School or platform hold in the database;
      explicit audited release only)
    - they refuse a School held in `retention_school_holds`, the database
      mirror of `RETENTION_HOLD_SCHOOL_IDS`.
  - **Kept:** School configuration (tenant lifetime), audit, outbox, and
    `payroll_run_hrx_inputs` (Payroll evidence, E21.3F). A manager's
    decision stays with the requester's request.
  - **Employee deletion:** unexpired or expired-but-unpurged HRX evidence
    blocks it; after the HRX purge HRX no longer blocks; other domains
    still block independently.
  - **Test isolation:** the HRX race tests are hermetic (shared
    `PurgesCommittedHrxFixtures`, durable-count assertion).
  - **Catalog:** `leave_evidence` and `staff_attendance_evidence` adopted;
    no `mechanism_pending` category remains.
  - **Open:** HRX-L1–L4 (no jurisdiction cleared), E21 qualified
    ratification, production retention configuration. Deferred: in-year
    carried-leave expiry (recorded, not executed);
    `LEAVE_CANCELLATION_CLOSE_CHAIN`. Closure record:
    `docs/modules/HRX-READINESS-AND-CLOSURE.md`.

**FEE checkpoints (ADR 0062 §25)** -- all built, development-closed
2026-09-30:
- **FEE.0 — contract** (docs only). Complete.
- **FEE.1 — fee heads & fee structures**, including minimal ledger-account
  administration (decision K). Complete.
- **FEE.2 — assessment runs.** Complete.
- **FEE.3 — concessions / scholarships / waivers.** Complete.
- **FEE.4 — receipts & staff fee statements.** Complete.
- **FEE.5 — late fees.** Development complete under the legal-gate
  operating rule (DEVELOPMENT AUTHORISED — PROD LEGAL SIGN-OFF REQUIRED).
  The fee-regulation legal answer (E31, E32) is still required before
  production.

*Historical (FEE.0):* FEE.1 started only after the owner records ADR 0062 decisions A, B, C, E, K
and L.

**FEE.1 — COMPLETE (2026-09-29).** Owner decisions A, B, C, E, K1 and L
confirmed. Built:
- ledger-account administration;
- fee heads;
- fee structures (draft/active/retired, successor amendment, one active
  per scope);
- instalment schedules with generators;
- optional-fee selections.

Configuration only: no charge generation. The as-built record is in
`docs/modules/FINANCE.md` "FEE.1 as-built" and the ADR 0062 implementation
note. **Next: FEE.2 — Assessment Runs.**

**ADR 0062 decision D confirmed (owner, 2026-09-29): D1.**
- No proration.
- Instalments whose billing period ended before enrollment `starts_on` are
  skipped.
- The instalment covering `starts_on`, and every later one, is assessed in
  full.
- Staff exclusions are explicit preview-item state.

FEE.2 is unblocked. F, F2, G, H, I, I2 and M, and the §27 legal questions,
remain open.

**FEE.2 — COMPLETE (2026-09-29).** Built:
- staff-triggered assessment runs (preview, explicit exclusion, queued
  resumable execution);
- the Students-owned enrollment read boundary;
- idempotency through `fee_assessments_one_live_per_period`;
- the assessment void path.

No proration (D1). The as-built record is in `docs/modules/FINANCE.md`
"FEE.2 as-built" and the ADR 0062 implementation note.

**ADR 0062 decisions F, F2, G and M confirmed (owner, 2026-09-29):**
- **F:** maker/checker for every concession, requester ≠ approver
  (database-enforced).
- **F2:** one School-level `expense` concession account in `fee_settings`.
- **G:** adjustments limited to the current outstanding; refused, never
  reduced; never a credit or refund.
- **M:** closed categories `concession`/`scholarship`/`waiver`, with no
  free-text note.

**FEE.3 — COMPLETE (2026-09-30).** Built:
- concession requests with maker/checker approval (database-enforced
  separation of duties);
- posted adjustments to the School's concession expense account;
- the G1 current-outstanding cap through the Payments-owned capacity guard;
- standing concessions applied inside assessment;
- adjustment cancellation and the charge-cancel guard.

The as-built record is in `docs/modules/FINANCE.md` "FEE.3 as-built" and
the ADR 0062 implementation note.

**ADR 0062 decisions I and I2 confirmed (owner, 2026-09-30):**
- **I:** one receipt series per School × financial year; the FY start
  month is configurable (default April); format `<PREFIX>/<FY>/<000001>`;
  gap-free transactional counter; numbers immutable.
- **I2:** existing Payments get receipts only through the explicit,
  audited, idempotent `finance:receipts-backfill {school}` command, in
  `(settled_at, id)` order.

**FEE.4 — COMPLETE (2026-09-30).** Built:
- gap-free receipts per School × financial year, issued with every
  settlement;
- the explicit, idempotent `finance:receipts-backfill {school}`;
- a printable payment acknowledgement;
- the staff Student fee statement, computed on read.

The receipt form is provisional: **J — LEGAL REVIEW REQUIRED, DEVELOPMENT
AUTHORISED, PROD LEGAL SIGN-OFF REQUIRED** (ADR 0058 register E30). It is
never labelled a tax invoice and has no tax fields. The as-built record is
in `docs/modules/FINANCE.md` "FEE.4 as-built" and the ADR 0062
implementation note.

**ADR 0062 decision H confirmed (owner, 2026-09-30; product decision
only):**
- fixed or percentage-of-current-outstanding late fees;
- `grace_days`, eligible only when `evaluation_date > due_date +
  grace_days`;
- an optional cap (the lesser of calculated and cap);
- one live late fee per source charge per rule;
- no tiers, recurrence or compounding.

The FEE.4 numbering behaviour (start-month lock, a prefix fixed per series)
is confirmed as the final v1 contract.

**FEE.5 — COMPLETE (2026-09-30, development).** Built:
- late-fee rules (fixed or percentage of current outstanding, grace days,
  optional cap);
- staff-triggered late-fee runs (preview, execute, resume, cancel) with
  one live late fee per source charge per rule;
- void through a Finance reversal.

No recurrence, tiers or compounding. **Production stays blocked** on the
fee-regulation legal sign-off (ADR 0058 E31; RTE E32). The as-built record
is in `docs/modules/FINANCE.md` "FEE.5 as-built".

**The FEE programme (FEE.0–FEE.5) is complete for development.** Its
remaining production gates are legal: E30 (receipt/GST form), E31, E32
and E21 (retention).

**FEE.0–FEE.5 — DEVELOPMENT CLOSED (2026-09-30).** A read-only closure
audit of the functional baseline `819e150` found only minor items. The
closure-remediation unit fixed them:
- Finance no longer depends on Fees for its HTTP error glue (now in
  `App\Support\Http` and Finance's own `Http`, guarded);
- a first receipt and a receipt-numbering change now serialize on the
  School's Fees settings lock (issuer SHARED, change EXCLUSIVE), proven by
  four real two-process races;
- the stale documentation was corrected;
- all 17 FEE migrations were rolled back and re-applied on DDEV, reaching
  the identical schema at every checkpoint boundary.

The record is in ADR 0062 "Development closure". The formal
development-closure baseline is the remediation commit, not `819e150`
(which stays the functional implementation baseline). This is **not**
production readiness. Still open:
- legal: E21, E30, E31, E32;
- governance and release: E03 branch protection (deferred to final
  production hardening), then E02/E15 requalification of an image that
  contains FEE, and a fresh E16 decision;
- per-School onboarding: the concession account, the receipt prefix and
  the start month (FINANCE.md "FEE onboarding").

**OPF checkpoints (ADR 0067 §26)** -- OPF.0–OPF.5 published; the
programme is development closed (ADR 0067 §31), not production-ready:
- **OPF.0 — Operational Fee Integrations contract** (ADR 0067, docs only).
  Published (2026-10-05).
  - **Dependency rule.** Operational modules call trusted FEE seams; FEE
    never reads Transport, Hostel, Library or Admissions to infer intent.
    There is no parallel ledger or charge.
  - **Charges** stay Student-only.
  - **Amounts** stay in FEE instalments (Transport, Hostel, Admission fee)
    or a Library-owned versioned fine policy.
  - **Primitives:** optional selections for Transport, Hostel and the
    Admission fee at conversion; an event charge through `assess()` for
    Library fines.
  - **Lifecycle:** no proration. Ending an operational relationship never
    cancels a charge. Academic-year carry-forward is explicit and audited.
  - **Authorization:** the operational capability creates selection intent
    through a trusted seam; only `finance.fee_assessments.run` makes money.
  - **Retention:** every new link table is registered (anchors, catalog,
    classification), and the Finance expiry function is amended for any
    `charges` link. E21-RH is extended, never reopened.
  - **Deferred:** Hostel deposits, refunds and credits, pre-conversion
    application fees, Library lost or damaged charging, proration.
  - **New legal row:** E34 (Library fine regulation).
- **OPF.1 — Transport fee selection.** Published (2026-10-05, ADR 0067
  §27).
  - **Seam:** FEE's trusted `FeeSourceSelectionService`.
  - **Transport configuration:** route → fee-head mapping, with no amounts.
  - **Provenance:** insert-only, unique per assignment × year,
    database-checked.
  - **Lifecycle:** assignment start records intent for the active year; end
    withdraws intent and never cancels a charge; carry-forward is explicit,
    idempotent and race-safe.
  - **Separation of duties:** Transport capabilities only. Only Finance
    assessment runs charge.
  - **Retention:** registered (Finance ledger evidence, anchor, guard,
    catalog, classification).
  - **Proof:** real-process races, plus an architecture guard that FEE never
    reads Transport.
- **OPF.2 — Hostel fee selection** (no deposits). Published (2026-10-05,
  ADR 0067 §28).
  - **Seam:** the same `FeeSourceSelectionService` (`hostel` source); the
    seam's callers are an explicit allow-list (Transport, Hostel).
  - **Hostel configuration:** Hostel default + per-room override → fee
    head, with no amounts.
  - **Provenance:** insert-only, unique per residency × year,
    database-checked, records the matched scope.
  - **Lifecycle:** residency start records intent for the active year; end
    withdraws intent and never cancels a charge; a move is end + assign (no
    proration); carry-forward is explicit, idempotent and race-safe.
  - **Separation of duties:** Hostel capabilities only. Only Finance
    assessment runs charge.
  - **Retention:** registered (Finance ledger evidence, anchor, guard,
    catalog, classification).
  - **Proof:** real-process races (residency link vs carry-forward, two
    carry-forwards, two seam selections), architecture allow-list guard.
  - **Regression note (OPF.2R, 2026-10-06):** OPF.2 itself is unchanged. Its
    canonical regression exposed a pre-existing timing-sensitive Admissions
    retention assertion: since E21-RH.7 committed that class's fixtures, it
    read the expected time in a later transaction. The assertion now reads
    the transition transaction's own time, exactly. The final canonical
    regression passed.
- **OPF.3 — Admission fee at Student conversion.** Published (2026-10-06,
  ADR 0067 §29).
  - **Seam:** the same `FeeSourceSelectionService` (`admissions` source);
    the seam's callers are exactly Transport, Hostel and Admissions.
  - **Configuration:** one Admission fee head per School, no amounts.
  - **Lifecycle:** a successful conversion records the converted Student's
    one-time intent in the conversion transaction (application's year); no
    withdrawal, no carry-forward; never an applicant (D1).
  - **Provenance:** insert-only, one per converted application,
    database-checked.
  - **Retention:** registered; a converted application with provenance is
    `dependency_blocked` until Finance evidence can go.
  - **Proof:** real-process races (two conversions, two fee-links, two seam
    selections), failed-conversion rollback, architecture allow-list.
- **OPF.4 — Library overdue fines.** Published (2026-10-06, ADR 0067 §30).
  - **Seam:** a second, narrow FEE event-charge seam
    (`FeeSourceChargeService`); Library is its only caller and never calls
    `ChargeService` itself.
  - **Policy:** Library-owned immutable versions (rate, grace, cap, fee
    head).
  - **Lifecycle:** one fine per loan at check-in from the final overdue
    duration; no daily accumulation, no assess endpoint; waiver = FEE
    concession; void = unpaid-only cancellation, never a refund.
  - **Retention:** Finance evidence; `retention_expire_finance_unit`
    forward-amended to refuse fined charges (Canteen precedent); the fine
    keeps its loan.
  - **Legal:** E34 still gates production.
  - **Proof:** real-process races (two check-ins, two voids), formula and
    database derivation, architecture guard for both seams.
- **OPF.5 — Closure audit.** Published / closed (2026-10-06, ADR 0067
  §31).
  - **Result:** OPF matches ADR 0067 and D1–D9. There is no product,
    schema or authorization defect, and no deferred scope partially built.
  - **Corrections (no new behaviour):**
    - rule 28 raw-SQL RLS isolation for all nine OPF tables;
    - missing rule 13 deny and cross-School tests;
    - Transport and Hostel `dependency_blocked` tests;
    - a Library charge-shape trigger test;
    - `TenantRls::disable()` in the four OPF `down()` methods (rollback
      re-proven);
    - the Admissions → Fees layer exception recorded (ADR 0067 §31.2,
      DOMAIN-MAP);
    - stale module-doc sentences.
  - **Production:** gated by E21, E30, E31, E32 and E34 (unchanged).

**RES checkpoints (ADR 0068 §11, §19, §27)** -- **current reopened scope
CLOSED by RES.5 (2026-10-07)**:

| Stage | Status |
|---|---|
| RES.0 | COMPLETE |
| RES.0B | COMPLETE |
| RES.1 | COMPLETE |
| RES.2 | COMPLETE — development implemented; production blocked (RES-L1, refused in code) |
| RES.3 | COMPLETE — development implemented; production blocked (RES-L1, refused in code) |
| RES.4 | COMPLETE — development implemented; teacher production blocked (E37, E35 re-review, RES-L1; refused in code) |
| RES.4A | COMPLETE — development implemented; production blocked as RES.4 (refused in code) |
| RES.5 | COMPLETE — CURRENT REOPENED SCOPE CLOSED |

Future gated programmes, not started:
- production StudentMark enablement (RES-L1);
- teacher legal / privacy clearance (E35 re-review, E37);
- result calculation and publication (RES-L4);
- report cards (RES-L5);
- transcripts (RES-L6);
- Student / Guardian exposure (RES-L7);
- retention finalisation (RES-L8);
- statutory academic rules (RES-L9).

Detail per stage:
- **RES.0 — Reopening audit.** Complete (2026-10-06, read-only).
  - No hidden or partial marks/results implementation exists.
  - Prerequisites built: Examination, ExaminationPaper (`max_marks`),
    GradeScale/GradeBand, Staff MFA, the processing-authorization
    registry, Student subject enrollment, Teacher ownership.
  - P3 was named but never defined; the StudentMark determination must be
    re-confirmed (ADR 0061 §2.4).
- **RES.0B — Reopening contract.** Published (2026-10-06, ADR 0068, docs
  only).
  - Reopens only P3 and internal StudentMark; owner decisions R1–R20.
  - Marks are Highly Sensitive; administrative entry only; `mfa` on every
    marks route and fresh MFA for the lock; per-paper `open` → `locked`;
    append-only, maker/checker corrections; no results, grades, pass/fail,
    rank, promotion or publication.
  - Legal items RES-L0 – RES-L9 recorded (ADR 0058 E35–E44); the RES-L0
    request is drafted for the owner to send.
- **RES.1 — P3 as-of-date SubjectOffering eligibility.** Published
  (2026-10-06, ADR 0068 §18).
  - `SubjectOfferingEligibilityReadService` (Students): plain and
    `FOR SHARE` lock-capable reads; required = placement in the Offering's
    year, campus and grade on the date (Section returned, not filtered);
    elective = also a dated, anchored subject-enrollment row.
  - Temporal like `membersAsOf()` (cancelled intervals count); the date
    must be inside the Offering's year; fails closed on legacy, ambiguous
    or inconsistent history and another School's rows.
  - §5.5 answered: placement transfers never re-anchor an elective row.
  - No table, route, capability, UI or consumer.
  - Proof: 15 behaviour tests, two real-process lock races (mutation-
    checked), an architecture guard against any RES.2+ artifact.
- **RES-L0 — StudentMark determination revalidation.** **Resolved: CURRENT
  WITH CHANGES** (Lead Privacy Counsel & DPO, 2026-10-07;
  `docs/security/RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`).
  - Current only for School-internal recording by authorised administrative
    staff, under 16 conditions now binding in ADR 0068 §19.
  - Withdrawal never deletes or invalidates a mark; continued access needs an
    independently valid basis.
  - No fixed expiry; re-review on the recorded triggers.
- **RES.2 — Administrative StudentMark entry.** **Published — development
  only, not production** (2026-10-07, ADR 0068 §20).
  - `student_marks` (one per paper × Student; present/absent/exempt;
    `numeric(6,2)` ≤ the paper's maximum; snapshotted P3 placement, source and
    elective row; ADR 0038 authorization proven by the registry's own key) and
    `student_mark_revisions` (value history of every write, trigger-written,
    insert-only).
  - Session JSON grid read and atomic batch write; `examinations.marks.*` +
    `mfa`; school_admin and principal only; optimistic versions; deny-by-default
    reads without a current basis; closed year and inactive paper refuse; a
    marked paper's maximum and date are frozen.
  - Retention `policy_unresolved` (RES-L8); RLS 202; no outbox, export,
    analytics or teacher path.
  - Proof: service, HTTP, raw-SQL RLS and guard suites; real-process races
    (two editors, withdrawal, transfer) with mutation checks.
  - Production: RES-L1. Retention: RES-L8.
- **RES.3 — Per-paper lock and append-only corrections.** **Complete —
  development only, not production** (2026-10-07, ADR 0068 §21).
  - `examination_paper_mark_states` (one per paper, `open` → `locked`, no
    unlock at the database) and `student_mark_corrections` (one row per
    request: base version, previous and proposed status/value, closed reason
    `entry_error` / `totalling_error` / `status_error`, requester and decider
    with the ADR 0038 basis at request and at approval; `pending` →
    `approved` | `rejected`, terminal).
  - Database-enforced maker ≠ checker, one pending request per mark, and no
    locked-mark change without an approved correction in the same transaction.
    Approval writes through StudentMarkService (version +1 once, revision
    appended).
  - Lock and decisions need fresh MFA; capabilities `examinations.marks.lock`,
    `.correction.request`, `.correction.approve` (school_admin, principal
    only); session JSON only.
  - Closed year: entry refused, correction workflow permitted. No basis:
    no request or approval, and the grid withholds the mark and its pending
    correction.
  - Retention `policy_unresolved` (RES-L8); RLS 204; no outbox, export,
    analytics or teacher path.
  - Proof: service, HTTP, raw-SQL and guard suites; real-process races T1–T5
    with mutation checks.
  - Follow-up recorded (not done): consider preventing required/elective flips
    once an Offering has dependent enrollment, examination-paper or mark
    evidence.
  - Production: RES-L1.
- **RES.4 readiness — teacher gates.** **Docs only, 2026-10-07 (ADR 0068
  §22).**
  - Three requests drafted, not sent: RES-L2 (E37), the RES-L0 re-review for
    teacher processing (E35) and TCH-L1 (E33), each with its own outcome.
  - Ownership audit:
    - required-subject ownership is READY (P3 placement Section × Offering ×
      `scheduled_on` → `TeachingOwnership::hold()`);
    - co-teachers and cover are READY only as equal dated assignments;
    - electives are BLOCKED (no elective ownership fact).
  - Proposed key `examinations.marks.teacher`; not created.
  - No register status changed.
- **RES.4 — Teacher-owned marks entry.** **IMPLEMENTED FOR DEVELOPMENT /
  PRODUCTION BLOCKED PENDING RES-L2 + TEACHER RES-L0 + RES-L1** (2026-10-07,
  ADR 0068 §25).
  - Authority: the product owner explicitly authorised engineering
    development. That overrides the internal development hold only and is
    not a legal determination; E37 and the E35 teacher re-review remain
    unresolved, and E36 (RES-L1) still blocks production StudentMark.
  - Capability `examinations.marks.teacher` (`teacher`; `school_admin` for
    grantability only).
  - Session routes `/app/my-examination-papers/{paper}/marks` with `mfa`.
  - Per-Student authority: P3 + ownership on `scheduled_on` (required:
    Section × Offering; elective: TCH-E Offering-wide) + ADR 0038 + an
    ActingEmployee today.
  - `StudentMarkService` is still the one writer; lock order in ADR 0068
    §25.7.
  - Co-teachers and short cover are equal owners: owner-adopted
    development rules pending RES-L2.
  - A non-configurable code block refuses teacher marks outside `local` /
    `testing`.
  - No results, correction or lock authority for teachers.
  - Real-process races X1–X6 with mutation checks.
  - Regression cadence: early full-regression checkpoint at `c9d9762`
    (7874 green; counter 0/5).
  - If a determination conflicts, RES.4 is amended or disabled before
    production.
- **RES.4A — Teacher "My examination papers" discovery.** **IMPLEMENTED FOR
  DEVELOPMENT** (2026-10-07, ADR 0068 §26).
  - `GET /app/my-examination-papers`: papers owned on their `scheduled_on`,
    active, in years that are not closed; locked papers read-only.
  - No Student or mark data; same gates and production block as RES.4.
  - E35, E36 and E37 unchanged.
  - Regression cadence: round 1/5 after the `c9d9762` checkpoint.
- **RES.5 — Closure audit.** **COMPLETE — RES CURRENT REOPENED SCOPE
  CLOSED** (2026-10-07, ADR 0068 §27).
  - Every ADR 0068 clause and all sixteen RES-L0 conditions were reconciled
    with the code.
  - Three corrections were made:
    - §20.1 enforced: an edit never re-derives a mark's context (409
      `STUDENT_MARK_CONTEXT_CHANGED`);
    - **administrative StudentMark refused in code outside local/testing**
      (`StudentMarkAvailability`, RES-L1);
    - the §21.6 paper id is UUID-constrained.
  - Also: the OpenAPI 409 for paper update, three closure guards, a
    definitive lock order and authorization/production-gate matrices, and
    about 25 drift corrections.
  - RES.5 regression cadence: round 2/5 after the `c9d9762` checkpoint (executable
    corrections, confined to the marks paths; focused and broad suites).
  - Follow-ups S1–S8 are recorded and are not blockers.
  - **S1 — COMPLETE (2026-10-07, ADR 0069):** SubjectOffering
    required/elective classification frozen once dependent academic
    evidence exists.
    - Symmetric, database-enforced and race-free; the evidence is seven
      tables, papers included.
    - 409 `SUBJECT_OFFERING_CLASSIFICATION_LOCKED`.
    - Real-process races T1–T5 with mutation checks; rollback proof.
    - RES stays CLOSED.
    - Regression cadence: an early full-regression checkpoint on the final
      tree — the triggers touch every writer of seven tables in seven modules
      (counter reset to 0/5).
  - **S5 — COMPLETE (2026-10-07, ADR 0038 lock-order amendment):**
    - one canonical order, Student → processing-authorization grants →
      guardian relationships;
    - Guardian `unlink` / `setPrimary` / `update` take the Student first
      (both cycles reproduced as real deadlocks, then proven gone, with
      mutation checks);
    - StudentMark writes translate a deadlock / serialization abort into 409
      `STUDENT_MARK_RETRY_REQUIRED`.
    - S8 still open; RES stays CLOSED.
    - Regression cadence: round 1/5 after the `11e0ff3` checkpoint (focused
      and broad suites; no shared primitive changed broadly).
  - **S6 — COMPLETE (2026-10-07):** StudentMark database defence in depth.
    - Every mark write takes its paper `FOR SHARE` first. This closes two
      raw-SQL races, both reproduced on the old code: a mark inserted onto a
      paper being locked, and a mark validated against an Offering the paper
      was being re-pointed from.
    - A marked paper keeps its Examination and Offering.
    - Rollback proof; mutation checks.
    - Residuals S6c / S6d (LOW, raw SQL only; no application path reaches
      them) are deferred (ADR 0068 §27.11).
    - RES stays CLOSED.
    - Regression cadence: round 2/5 (focused and broad suites; the triggers
      touch only StudentMark writes and paper identity updates).
  - **Guardian unlink: referenced-consent conflict — COMPLETE (2026-10-07;
    ADR 0038 note):**
    - a relationship still named by retained processing-authorization
      evidence is refused with 409 `GUARDIAN_RELATIONSHIP_IN_USE`, instead
      of a raw foreign-key 500;
    - the `RESTRICT` key stays the authority and is the narrow backstop;
    - Student-first (S5) preserved;
    - races with consent recording are proven.
    - Regression cadence: round 3/5.
  - **S7 — COMPLETE (2026-10-08; ADR 0063 §47):** ending an employment ends
    teaching ownership.
    - `EmploymentService::end()` ends the Employee's required and elective
      assignments in the same transaction, through an HR-owned participant
      port, so HR still does not depend on Teaching Assignments.
    - Ownership holds through the employment's last day; unstarted rows are
      voided; no deletes; no start rewritten.
    - A rehire owns nothing until a new assignment. Creation can no longer
      outlast the covering employment.
    - Real-process races X1–X5; rollback proof; mutation checks.
    - The RES.4 paper-date rule is unchanged (RES-L2 open); RES stays
      CLOSED; S8 stays open.
    - Regression cadence: round 4/5.
  - **StudentMark retryable-abort metric — COMPLETE (2026-10-08; ADR 0068
    §27.11 S5 follow-up):**
    - `lycenza_student_mark_retryable_aborts_total{operation, reason}`
      counts each 409 `STUDENT_MARK_RETRY_REQUIRED` once, through the shared
      `MetricsRecorder`.
    - Closed labels; no identifiers, values, SQLSTATE or messages.
    - The response, rollback and "no automatic retry" rule are unchanged.
    - A true deadlock is proven to count once, for administrative and
      teacher entry.
    - RES stays CLOSED.
    - Regression cadence: round 5/5 — canonical full regression on the
      exact published tree; counter reset to 0/5.
  - **S8 — COMPLETE (2026-10-08; ADR 0068 §27.11):** the administrative
    grid translates the service-level marks block.
    - With its route block bypassed, the grid answered a 500; it now answers
      the same fixed 403 `STUDENT_MARKS_UNAVAILABLE` as the middleware.
    - Only that exception is translated; the other five administrative
      actions already were. Both layers remain.
    - No refused-read audit or log; the teacher block is unchanged.
    - No authorization, environment, schema or legal change. RES stays
      CLOSED.
    - Regression cadence: round 1/5 after the `e2b31647` checkpoint.
  - **S3 — COMPLETE (2026-10-08; ADR 0068 §27.11):** the Visitors timestamp
    flake.
    - Root cause: an inverted fixture (the check-out `now()` was read before
      the factory's check-in `now()`) plus whole-second storage. Reproduced
      deterministically and at 3 in 3,000 real-clock runs.
    - The fixture now derives the check-out from the check-in.
    - Production `checkOut()` records `max(now, checked_in_at)`, so a
      backward clock step is no longer a 500.
    - The CHECK is unchanged and pinned; RLS tests are unchanged.
    - RES stays CLOSED.
    - Regression cadence: round 2/5.
  - **Library loan clock correction — COMPLETE (2026-10-08; the
    non-RES counterpart of S3):**
    - Same defect class, both reproduced on the old code:
      - `TenantClosureReadinessTest`'s returned-loan fixture read the return
        `now()` before the factory's checkout `now()`;
      - production `LibraryLoanService::checkIn()` was refused with a 500
        after a backward clock step.
    - `LibraryLoanFactory::returned()` derives the return from the checkout.
    - `checkIn()` records `max(now, checked_out_at)`; such a return is never
      overdue or fined.
    - The CHECK is unchanged and now pinned; no schema change.
    - Regression cadence: round 3/5.
  - **RES THREAD — CLOSED / HANDOFF READY (2026-10-08; ADR 0068 §27.13).**
    - The final follow-up audit found no correctness blocker. Only stale
      current-state wording was corrected.
    - **Development closure only.** No legal row changed, and production
      stays refused in code.
    - Remaining work leaves this thread:
      - external legal: E35, E36, E37, E38–E44;
      - S2, the teacher marks UI;
      - S6c / S6d, raw-SQL hardening;
      - two non-RES teaching-ownership residuals (ADR 0063 §47.6).
    - Shared test database: a stray schema `CREATE` grant was revoked. That
      was environment drift; the repository was unchanged.
    - The next phase is chosen by a separate Post-RES Roadmap & Next-Phase
      Readiness Audit; nothing starts automatically.
- **Not sequenced:** results, finalization, publication, report cards,
  transcripts and Student/Guardian access, until RES-L4 – RES-L7 are
  answered and each has its own contract.

**POR checkpoints (ADR 0070 §19)** — contract published; nothing built:
- **POR.0 — Guardian/Student Portal Contract (ADR 0070).** Published
  2026-10-08, documentation only.
  - **Capabilities and authority:**
    - `portal.*` capabilities delivered by a closed `guardian`-scope system
      role (database-separated from staff roles; checked by capability,
      never by role name);
    - `ActingGuardian` (Identity) and `GuardianStudentScope` (Guardians),
      both live and never cached;
    - identical 404 for anything out of scope;
    - one School per session.
  - **Lifecycle:** Guardian off-boarding is a mandatory POR.1 prerequisite,
    and staff and Guardian lifecycles are split.
  - **Gates:** `/app/school-setup` gating; a code-level `PortalAvailability`
    development-only block.
  - **Records:** POR-L1 (E46) request drafted, not sent. ADR 0039's
    Guardian Communications claim corrected. Communications classification
    added.
  - **Separate records:** a TCH-L1 historical-date clarification (not
    sent; E33 unchanged).
  - **Regression cadence:** docs only; counter stays 3/5. Owner rule: the
    canonical full regression runs only at 5/5.
- **POR.1 — Guardian foundation + read-only Communications inbox —
  IMPLEMENTED FOR DEVELOPMENT** (2026-10-08, ADR 0070 §24; ADR 0045 and
  ADR 0059 amended; CLAUDE.md rule 25 updated).
  - **Scope and capability:**
    - a fourth `guardian` scope, database-separated from staff roles;
    - the closed `guardian` role carrying `portal.communications.view`;
    - activation grants it idempotently;
    - unlink and off-boarding revoke it first.
  - **Identity:** `ActingGuardian` (live; ≥ 1 eligible relationship).
  - **Lifecycle:** Guardian off-boarding (fresh MFA; suspends a Guardian-only
    membership), and staff off-boarding of a dual staff + Guardian person
    keeping the Guardian.
  - **Gates:** `/app/school-setup` requires `school.profile.view`;
    `PortalAvailability` keeps the portal development only.
  - **The inbox:** own announcements, own read state, scoped attachments,
    identical 404s.
  - **Legal:** POR-L1 not sent; no production clearance.
  - **Regression:** rule 82's "sooner after a major cross-domain
    integration" applies, so the canonical full regression ran on the
    POR.1 tree.
- **POR.2 — GuardianStudentScope + linked-Student Attendance — IMPLEMENTED
  FOR DEVELOPMENT** (2026-10-09, ADR 0070 §25):
  - **Capability:** `portal.attendance.view` on the closed `guardian` role.
  - **Scope:** `GuardianStudentScope` as the single live predicate (legal
    guardian + active Student + active Guardian, same School), embedded in
    the Attendance query.
  - **Read seam:** Attendance-owned; date, period and status only.
  - **Window:** the active academic year only, never after today, at most
    62 days.
  - **Protection:** `mfa-page`, PortalAvailability, the same 404 for any other
    Student, one audit per read (no content).
  - **Regression:** round 1/5 after the `a288292` checkpoint.
  - **Legal:** POR-L1 not sent.
- **POR.3 — Guardian fee statement + payments applied to a Student —
  IMPLEMENTED FOR DEVELOPMENT** (2026-10-09, ADR 0070 §26):
  - **Capability:** `portal.fees.view`.
  - **Read seam:** `GuardianFeeReadService` (Payments), with the scope
    embedded through Fees' `statementLinesForStudentWithin`.
  - **Balances:** authoritative, from `ChargeStateReader`.
  - **Window:** active-year charges plus other years' unpaid charges, so the
    outstanding total is the all-years figure.
  - **Shared payments:** shown only as the amount applied to this Student;
    never the payment total or a sibling.
  - **Protection:** MFA; the same 404 for anything else; two Guardian audit
    events.
  - **Regression:** round 2/5.
  - **Legal:** POR-L1 not sent.
- **POR.4 — Guardian conversations & replies — IMPLEMENTED FOR DEVELOPMENT**
  (2026-10-09, ADR 0070 §27):
  - **Capability:** `portal.communications.reply`, always with
    `portal.communications.view`.
  - **Seam:** `GuardianConversationService` (Communications); every message
    through the unchanged `CommunicationMessageService::send()`.
  - **Visibility:** threads joined as the Guardian persona; another
    Guardian's presence or an out-of-scope Student participant withholds the
    thread (fail closed, POR-L1 Q12/Q15).
  - **Idempotency:** server-issued key on `communication_messages`
    (migration `2026_12_13_090000`, unique per School + sender); replay only
    for identical text in the same thread.
  - **Races:** link → membership FOR SHARE, thread FOR NO KEY UPDATE,
    participant FOR SHARE; five real-process races.
  - **Protection:** MFA, per-User throttle, in-app only, the same 404.
  - **Initiation:** deferred; no Guardian-initiated conversation contract
    exists.
  - **Regression:** early canonical full regression on the published tree
    (sooner rule: a schema change on `communication_messages`, the shared
    Communications writer and a new lock order); the counter resets to 0/5.
    Counts are in the publication commit.
  - **Legal:** POR-L1 not sent (Q5, Q12, Q15 annotated).
- **Planned, awaiting separate owner authorisation:**
  - **POR.5:** closure audit.

  Student accounts, API/mobile and marks/results are later and separately
  gated.

## Phase 0A — Architectural Foundation (complete)

Repository structure, ADRs, domain map, tenancy/API/event/AI/security
design docs, local dev environment, CI foundation, and tiny primitives
proving the stack (Laravel+Inertia+Vue+TS request chain, versioned API
+ error envelope, AI Gateway + capability-gated tool authorization,
contract → generated-types pipeline). No business module implemented.

## Phase 0B — Identity, Access, and Tenancy (complete)

The one phase every later module structurally depends on
(`docs/architecture/DOMAIN-MAP.md` Layer 0) — this checkpoint made
Phase 0A's tenancy/authorization design **real**, not just documented:

- Real tenant model: School/Campus/Group entities (`schools`,
  `campuses`, `school_groups`, `school_group_members`,
  `school_domains`), a real tenant-resolution middleware chain
  (`ResolveSchoolContext`), real PostgreSQL RLS policies (enabled and
  forced on every tenant-owned table), and real tenant-aware queue/
  cache/storage/log/AI plumbing — see `docs/architecture/TENANCY.md`
  and ADR 0004, ADR 0020–0024.
- Real Identity & Access: `users` (central identity, deliberately
  narrow — see `docs/security/AUTHORIZATION.md`), a capability catalog,
  system-defined roles (`school_admin`, `principal`,
  `platform_super_admin`), and `CapabilityResolver` as the one
  authoritative resolution service — capabilities, not hard-coded role
  checks, enforced via a Gate + route middleware + a controller trait,
  with allow *and* deny authorization tests from the first commit.
- Platform Super Admin bootstrap (`platform_role_assignments`, DB-
  trigger-enforced scope separation from School roles) — the
  foundation only; no "enter a School's context as platform admin"
  elevation workflow yet (deliberately, per the checkpoint's brief: no
  invisible cross-tenant bypass).
- Durable, RLS-protected, database-privilege-enforced append-only audit
  (`platform_audit_events`, `school_audit_events`) — see ADR 0017's
  Phase 0B update.
- A minimal login/dashboard/school-switch/settings Inertia UI and one
  Sanctum-authenticated API endpoint, proving the web and mobile
  authentication chains actually work, not just the data model.
- The Laravel↔AI Gateway boundary strengthened with signed context
  tokens (ADR 0023), proven with a live, unmocked, cross-container
  round trip in addition to the automated test suite.

95 Laravel tests + 10 Python tests, all passing against real
PostgreSQL (ADR 0024) — see the Phase 0B Final Report for the full
verification record, including the PostgreSQL isolation proof and
security review.

## Phase 0C — Reliability & Integration Substrate (complete)

Re-sequenced ahead of the originally-planned "Organizational Structure"
phase (now Phase 0D, below): every later business module needs to
reliably trigger notifications, integrations, automation, analytics,
and AI without losing work, duplicating dangerous side effects,
crossing tenants, or tightly coupling to external infrastructure —
retrofitting that substrate after several modules already exist would
be far more expensive than building it first, the same reasoning that
put Phase 0B ahead of every business module. Landing in checkpoints:

- **Phase 0C (core substrate, complete):** durable domain events +
  transactional outbox (ADR 0025), event-consumer idempotency
  (`EventConsumerReceipt`), reliable queued dispatch (`SKIP LOCKED`
  outbox dispatcher), notification infrastructure (fake/local
  providers only), webhook infrastructure (HMAC signing, SSRF
  protection, retry/dead-letter), service identities distinct from
  User, feature flags, School settings foundation, and the AI Gateway
  durable-audit write-back closing the Phase 0B audit debt. Three
  required end-to-end proofs completed: Proof A (event → outbox →
  dispatcher → consumer → idempotency receipt → audit), Proof B (event
  → webhook → real local HTTP delivery → HMAC verification → no
  duplicate on replay), Proof C (Laravel → signed context → FastAPI →
  authorized operation → durable audit returned to Laravel, live
  cross-container, plus all four required denial scenarios).
- **Phase 0C.2 — API Idempotency Foundation (complete):** a
  reusable, opt-in `idempotent` route middleware
  (`App\Http\Middleware\EnsureIdempotent`) plus
  `App\Support\Idempotency\IdempotencyGuard`, giving any future
  consequential mutation a School/actor/route-scoped
  `Idempotency-Key` contract backed by real PostgreSQL uniqueness —
  see `docs/architecture/RELIABILITY.md` ("API idempotency") for the
  full design, guarantees, and documented limits.
- **Phase 0C.3 — Webhook & External Integration Delivery Foundation
  (complete):** the production-grade webhook subsystem — four distinct
  tenant-owned/RLS-protected tables (endpoint, subscription, delivery,
  attempt), an externally-publishable event registry
  (`App\Support\Webhooks\WebhookEventRegistry`), secure secret
  generation/rotation/one-time display, HMAC-SHA256 signing with
  timestamp replay protection (ADR 0026), re-validated-per-attempt SSRF
  protection with IP pinning (ADR 0027), a lease-based concurrency-safe
  delivery/retry state machine (`App\Jobs\DeliverWebhookJob`,
  `App\Console\Commands\RedispatchDueWebhookDeliveries`), capability-
  gated management API (`integrations.webhooks.view`/`.manage`,
  idempotency-key-protected mutations), and manual redelivery. Real
  local cross-container proof covers 2xx/500-retry-recovery/429-
  Retry-After/404-permanent/timeout/redirect-not-followed/tampered-
  signature/duplicate-event, plus a genuine two-OS-process concurrency
  proof. Full design: `docs/architecture/INTEGRATIONS.md`,
  `docs/security/INTEGRATION-SECURITY.md`.

- **Phase 0C.3A — Test-Database Safety & Transactional-Outbox
  Architecture Closure (complete):** fail-closed `TestDatabaseGuard`
  (`App\Support\Testing\TestDatabaseGuard`) preventing a testing
  command from ever silently resolving to the development database;
  `platform:test-db-reset` / `composer test:reset-db`; ADR 0025
  formally documenting the transactional-outbox pattern already in use
  since Phase 0C's core substrate.
- **Phase 0C.4 — Health, Scheduler, Queue Operations & Observability
  Completion (complete):** Laravel (`/api/health/live`,
  `/api/health/ready`) and FastAPI (`/health/live`, `/health/ready`)
  liveness/readiness; scheduler and queue heartbeat/staleness
  detection reusing the existing `scheduler_heartbeats` table
  (`App\Support\Observability\SchedulerHeartbeatRecorder`); a queue
  health model that never reports an idle queue as stalled
  (`App\Support\Observability\OperationalStatusService::queues()`);
  read-only failed-job visibility
  (`App\Support\Observability\FailedJobInspector`,
  `php artisan platform:failed-jobs`); documented job timeout/
  retry_after invariants; a tenant-aware named rate-limiter foundation
  (`App\Providers\RateLimiterServiceProvider`) — including a real bug
  this checkpoint's own tests caught and fixed in how those limiters
  key School-scoped routes (see `docs/architecture/RELIABILITY.md`);
  structured-logging sanitization, an error-reporter abstraction, a
  metrics abstraction with high-cardinality safety rules, and a W3C
  Trace-Context-compatible tracing abstraction spanning Laravel and
  the AI Gateway (ADR 0015's documented fallback, not a full
  OpenTelemetry SDK); authenticated cross-tenant internal diagnostics
  (`GET /api/internal/operations/status`, `platform:operations-status`
  CLI). Full design: `docs/architecture/OBSERVABILITY.md`.

**Phase 0C closeout (2026-09-23, complete):** the two items that
remained after 0C.4 are done — webhook delivery/attempt retention
pruning (`platform:webhook-deliveries-prune`, scheduled daily; the
retention PERIOD itself stays unset pending the [LEGAL REVIEW REQUIRED]
retention decision) and the consolidated closeout report
(`docs/architecture/PHASE-0C-CLOSEOUT.md`). The closeout also scheduled
`platform:idempotency-prune`, which Phase 0C.2 had deferred to "the rest
of Phase 0C's operational-safety work". Outbox retention remains
deliberately deferred (ADR 0025).

## Phase 0D — Organizational & Academic Structure Foundation (complete)

The first real School ERP domain checkpoint — Schools, Campuses,
Academic Structure (Academic Years/Terms, Grade Levels, Sections,
Subjects, Academic Departments, Rooms, Subject Offerings, and a
platform Education Board catalog) — Layer 1 reference data nearly
every later module reads. (Originally sequenced as "Phase 0C" before
the reliability substrate was moved ahead of it — see above.)

School → Campus → Academic Year → Grade → Section → Subjects is fully
configurable via API and a minimal Inertia UI, with real PostgreSQL
RLS isolation on every School-owned table, composite foreign keys
structurally preventing any cross-School parent reference, a
database-enforced single-active-Academic-Year invariant proven safe
under genuine concurrent activation (two real OS processes racing),
and Section/SubjectOffering historical-safety (AcademicYear-scoped,
never mutated/reused across years) so future Students/SIS, Admissions,
Attendance, Exams, Timetable, and Finance modules can reference this
structure without a redesign. `app/Domain/AcademicStructure/*` is the
first module using the `Domain/Application/Infrastructure/Http`
layout `apps/platform/app/Domain/README.md` reserved for exactly this.
Full design: `docs/modules/ORGANIZATION.md`,
`docs/modules/ACADEMIC-STRUCTURE.md`.

Deliberately deferred (not started): a dedicated UI screen for Academic
Terms, Sections, Academic Departments, Rooms, and Subject Offerings
(the API/backend for all of these is complete and tested; only the
Inertia page is pending — the existing pages follow an identical,
quick-to-replicate pattern).

## Phase 0E — Cross-Cutting Infrastructure (complete)

Documents (ADR 0012) and Communications — built early, deliberately,
because Layer 2–3 modules depend on them and retrofitting a shared
Documents/Communications module after several other modules have
already invented their own file-handling or notification logic is
expensive to unwind. Communications now builds directly on Phase 0C's
notification infrastructure rather than inventing its own.

**Communications is complete** (Phase 5A/5B, `docs/communication-hub/`).

**Documents is complete (Phase 0E.1–0E.7):** foundation schema, write
path, authorized read/content streaming, owner-scoped listing, and
HTTP/API transport are implemented and verified end-to-end against
isolated infrastructure (0E.7 closure — RLS, owner-integrity, real
MinIO clean-room, full regression, zero unresolved P0/P1/P2). The ADR
0028 `employee_documents` reconciliation obligation is discharged by
ADR 0029: the two tables stay permanently separate, no merge. See
`docs/modules/DOCUMENTS.md` for the full domain contract, the accepted
P3 residual, and what remains deferred as non-blocking future work
(Student/Guardian owner activation, signed URLs, retention policy,
malware scanning, checksum/integrity, orphan cleanup, storage quota,
UI) — none of these were closure-critical for the generic Documents
infrastructure this phase scoped.

**Both halves of Phase 0E (Documents and Communications) are complete.**
"Complete" here means this phase's own cross-cutting infrastructure
scope is closed, matching Phase 0D's precedent — it does not mean every
possible future enhancement to either module has been built; those are
tracked as each module's own deferred/future work, not as open Phase 0E
obligations.

## Phase 0F — People

Students/SIS, Guardians, Admissions. First real domain events
(`StudentAdmitted`, `GuardianLinked`, `AdmissionLeadCreated` —
`docs/architecture/EVENTS.md`) get real producers here, flowing through
the Phase 0C outbox/consumer substrate rather than a bespoke mechanism.

**Status: complete and published to `main`** — built as the separately
numbered "Phase 1" initiative (1A–1H: Student/Guardian identity,
enrollment and rollover, subject enrollment, admissions, lifecycle
decision, electives, subject rollover, elective administration UI) and
merged as `cfb2796` ("Merge final Phase 1 student foundation").
`docs/students/PHASE-1-FINAL-COMPLETENESS-AUDIT.md` records zero missing
or partial Phase 1 requirements; its two formatting-only closure
blockers were resolved in `351f440`
(`docs/students/PHASE-1-CLOSURE-QUALITY-CORRECTION.md`). Items that audit
marks as explicitly deferred remain deferred.

## Phase 0G — Finance and Fees (complete)

Finance (core ledger), Fees, Payments — including the first real
payment-gateway integration and inbound-webhook idempotency (ADR 0018,
extended by Phase 0C's webhook infrastructure and Phase 0C.2's
documented payment-readiness distinction between client API idempotency
and payment-provider/webhook idempotency), built against the
financial-correctness rules in `docs/architecture/ARCHITECTURE.md` §10
from day one. This is a security- and correctness-critical phase;
expect the heaviest testing and review bar of any phase so far.

> **Correction (2026-09-28, ADR 0057, Phase 0O.11).** No real payment
> gateway was ever integrated in Phase 0G. It delivered the ledger,
> Charges, and the idempotent payment-provider **ingestion foundation**
> (ADR 0031: trusted `PaymentProviderEventService::recordSettlement()`, no
> route, no adapter). The first real gateway is **deferred** from Phase 0 /
> production v1. Manual/offline payment recording, which 0G deferred, is a
> required v1 Finance correction: Phase 0O.11A. The text below is the
> historical record.

**0G.0 — Finance Architecture & Module Plan (implemented):**
architecture/domain-contract checkpoint, no code. Settled the one
decision `ARCHITECTURE.md` §10 left open — Finance is a true
double-entry ledger with a chart of accounts, not a subledger or a
mutable-balance charge/payment tracker (ADR 0030). Full domain
contract, checkpoint sequence (0G.1-0G.8), and security register:
`docs/modules/FINANCE.md`.

**0G.1 — Ledger Schema Foundation (implemented):** the persistence
kernel only — `ledger_accounts`, `journal_entries`, `journal_lines`
(RLS-protected, same-School+same-currency composite foreign keys,
`NUMERIC(14,2)` money, no float anywhere), the deferred
constraint-trigger enforcing "debits equal credits" per entry, the
structural (partial-unique-index-backed) reversal relationship proven
safe under real two-process concurrency, and the first-party
`App\Support\Money\Money` value object. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.1 as-built").

**0G.2 — Ledger Posting & Reversal Application Services
(implemented):** `App\Domain\Finance\Application\LedgerService` — the
one sanctioned write path for posting and reversing journal entries
(`post()`/`reverse()`), inside one PostgreSQL transaction each,
audited (`App\Support\Audit\AuditRecorder`) and emitted through the
existing transactional outbox (ADR 0025) exactly once per successful
operation. Application-layer balance/currency/account validation sits
in front of, and never replaces, every 0G.1 database defense. Not an
authorization boundary — no capabilities, no HTTP, no UI. Zero new
migrations. Full as-built detail: `docs/modules/FINANCE.md` ("0G.2
as-built").

**0G.3 — Finance Authorization & Administrative Read Model
(implemented):** real `finance.ledger.view`/`.post`/`.reverse`
capabilities (`database/seeders/CapabilityAndRoleSeeder.php`, no data
migration — the existing sole capability/role catalog), granted by
default to `school_admin` only (not `principal`).
`App\Domain\Finance\Application\LedgerAdministrationService` is the
authorized administrative facade wrapping the still-unmodified,
still-unauthorized `LedgerService` trusted core; `App\Domain\Finance
\Application\LedgerReadService` is the sole authorized read path for
Ledger Accounts, journal history, and journal detail, returning only
typed DTOs (never a raw Eloquent model, never `posting_txid`). Every
successful read is audited (Highly Sensitive tier, unchanged from
`docs/modules/FINANCE.md`'s already-committed classification). Zero
new migrations, zero composer changes, no HTTP/API/UI, no Ledger
Account CRUD. Full as-built detail: `docs/modules/FINANCE.md` ("0G.3
as-built").

**0G.4 — Fees / Receivables Foundation (implemented):** a new module,
`App\Domain\Fees` (not `App\Domain\Finance` — DOMAIN-MAP.md's separate
Finance/Fees dependency rows required it, see `docs/modules/FINANCE.md`
"0G.4 as-built", "Module boundary"), adds `charges` (one migration,
`NUMERIC(14,2)`, INR-only, same-School/same-currency composite foreign
keys against `students`/`academic_years`/`ledger_accounts`/
`journal_entries`) — the receivable obligation entity; no `invoices`/
fee-definition entity in this checkpoint. `App\Domain\Fees\Application\ChargeService`
(`assess()`/`cancel()`) posts through Finance's existing `LedgerService`
(one small addition, `reverseById()`, so Fees never reads Finance's
`JournalEntry` model directly) inside one atomic transaction — never a
direct `journal_entries`/`journal_lines` write. `finance.charges.view`/
`.manage` capabilities, granted to `school_admin` only, gate
`ChargeAdministrationService`/`ChargeReadService`. No payments,
payment allocations, refunds, API, or UI. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.4 as-built").

**0G.5 — Payments / Allocation / Idempotent Provider Integration
(implemented):** a new module, `App\Domain\Payments`
(`docs/architecture/adr/0031-payments-settlement-allocation-and-idempotency-architecture.md`,
DOMAIN-MAP.md's own row: depends on Fees and Finance, neither depends
on it — no cycle), adds three tables — `payment_provider_events` (pure
immutable provider-callback ingress identity, `(school_id, provider,
provider_event_id)` unique, the durable idempotency claim),
`payments` (the School's immutable settlement fact, created ONLY at
settlement — no pending/failed rows, a deliberate refinement of this
document's own earlier conceptual sketch toward 0G.4's immediate-
recognition precedent), and `payment_allocations` (immutable payment-
to-charge join, `charges(id, school_id)` composite FK). Settlement is
mandatory-fully-allocated (sum of allocations must equal the settled
amount, both application-checked and database-enforced via a deferred
constraint trigger mirroring `journal_entries_balanced_check`'s
precedent) — true overpayment/unapplied-cash accounting remains
explicitly deferred, alongside Refunds. A Payment's allocation set is
additionally frozen the instant its own transaction commits
(`payments.creation_txid`, mirroring `journal_entries.posting_txid`) —
a later, separate transaction can never insert an additional
allocation row, regardless of remaining Charge capacity — and Charge
over-allocation is prevented by an IMMEDIATE `BEFORE INSERT` trigger
that takes a `SELECT ... FOR UPDATE` lock on the Charge row before
validating capacity, closing a genuine concurrent-transaction race a
deferred-only check could not (both proven under real two-process
concurrency, including a raw path that bypasses the Application
service entirely). `App\Domain\Payments\Application\PaymentProviderEventService::recordSettlement()`
posts through Finance's existing `LedgerService::post()` (one debit
line for the settlement account, one credit line per charge
allocation) and through Fees' new `ChargeService::lockChargeForAllocation()`
(a `SELECT ... FOR UPDATE` lock, never a direct `charges` table read)
— all inside one atomic transaction. A Charge with any recognized
allocation can never be cancelled (`charges_payment_allocation_guard_trigger`,
a Payments-owned trigger physically attached to Fees' `charges` table,
sharing the SAME Charge-row lock protocol as allocation insertion so
the two paths genuinely serialize against each other;
`App\Domain\Fees\Application\ChargeService::cancel()` translates its
rejection to a typed exception). `finance.payments.view` only (no
`.manage` — provider ingestion is a trusted system boundary, never a
human capability). No signature verification, HTTP/provider adapter,
refunds, or UI. Full as-built detail: `docs/modules/FINANCE.md` ("0G.5
as-built").

**0G.6 — Finance / Fees / Payments HTTP & API Transport (implemented):**
thin HTTP controllers exposing the already-authorized 0G.2-0G.5
Application boundary over this repo's canonical `/api/v1` transport —
`LedgerAccountController`/`JournalEntryController`
(`App\Domain\Finance\Http\Controllers`), `ChargeController`
(`App\Domain\Fees\Http\Controllers`), `PaymentController`
(`App\Domain\Payments\Http\Controllers`, READ-ONLY). Every controller
calls only its module's already-authorized facade/read service
(`LedgerAdministrationService`/`LedgerReadService`,
`ChargeAdministrationService`/`ChargeReadService`, `PaymentReadService`)
— never `LedgerService`/`ChargeService`/`PaymentProviderEventService`
or a raw Eloquent model directly, proven both by review and by a
static source-grep guard (`FinanceHttpArchitectureGuardTest`). Eleven
routes total: Ledger Accounts (read), journal entries (read/post/
reverse), Charges (read/assess/cancel), Payments (read only — no
`finance.payments.manage` capability exists, no human Payment mutation
route of any kind). No provider-specific webhook/callback route (no
provider was selected in this checkpoint's scope, per ADR 0031's own
deferral); `PaymentProviderEventService` remains unreachable from any
route. Money is always an exact decimal string over the wire, never a
float. Zero new migrations, zero new capabilities, zero new
dependencies. Full as-built detail: `docs/modules/FINANCE.md` ("0G.6
as-built").

**0G.7 — Finance / Fees / Payments UI (implemented):** the
administrative Inertia UI over 0G.6's boundary — Ledger account
directory, journal history/detail/post/reverse, Charge list/detail/
assess/cancel, read-only Payment list/detail. New session-authenticated
Inertia controllers (`App\Http\Controllers\App\Finance\*`), calling the
SAME already-authorized Application-layer services 0G.6's `/api/v1`
controllers call — never a raw Eloquent model, never `LedgerService`/
`ChargeService`/`PaymentProviderEventService` directly — mirroring the
established Students/Guardians/Communications pattern, NOT the 0G.6
Bearer-token JSON API (which the browser cannot authenticate against
without new Sanctum stateful-SPA infrastructure this checkpoint
deliberately did not introduce; see FINANCE.md "0G.7 as-built" for the
full contract-gap writeup). No new capability, no new migration, no
OpenAPI/generated-type change; the 0G.6 `/api/v1` surface is
unmodified. Money stays an exact decimal string end to end (no
JavaScript `Number`/float, including the client-side journal-balance
preview, which uses exact `BigInt`-cents arithmetic). No Refund UI, no
Payment mutation UI, no provider configuration UI, no reporting
dashboard. Full as-built detail: `docs/modules/FINANCE.md` ("0G.7
as-built").

**0G.8 — Phase 0G Closure / Integration Readiness (feature work
complete; publication pending):** `origin/main` advanced 32 commits
(Admissions, Library, Transport, Visitor, and other unrelated work)
while Finance was under construction, producing seven textual merge
conflicts (`DashboardController.php`, `Dashboard.vue`,
`CapabilityAndRoleSeeder.php`, `routes/api.php`, `routes/web.php`, the
OpenAPI contract, and its generated TypeScript) — all additive-only on
both sides, none a real naming/semantic collision. Resolved on a
dedicated `integration/phase-0g-finance-resolution` branch (never the
Finance feature branch, never `main`) by keeping both sides everywhere
except the generated TypeScript file, which was discarded and
regenerated from the merged OpenAPI source rather than hand-merged.
Validated against the merged candidate: clean-install AND upgrade-path
migration proof (133 migrations = 125 from `origin/main` + Finance's 8,
zero collisions either way), RLS/trigger/SECURITY-DEFINER catalog
spot-check, zero duplicate routes/capabilities, full application
regression (3429 tests / 11305 assertions / 0 failures / 0 errors),
and all frontend/static quality gates green. Full as-built detail:
`docs/modules/FINANCE.md` ("0G.8 as-built"). Phase 0G's feature work
(0G.0–0G.8) is complete and **published to `main`** — `c4652ca`
("Phase 0G.8: resolve Finance integration and close Phase 0G"), which
merges `feature/phase-0g-finance-foundation` (`1413113`).

## Phase 0H — Academic Operations

Attendance, Timetable, Academics, Examinations.

**Timetable Foundation (Phase 0H.1) is complete** — recurring-weekly
class scheduling: `TimetablePeriod` (reusable named time slots, a
`TenantLock`-enforced non-overlap invariant) and `TimetableEntry`
(SubjectOffering × Section × HR Employee-as-teacher × optional Room ×
Period × day-of-week, three database partial-unique conflict indexes,
required-SubjectOffering-only v1 scope), `timetable.periods.*`/
`timetable.schedule.*` capabilities, an `/api/v1` administrative
surface, and a session-authenticated Inertia UI — 110 tests / 298
assertions, 3 real two-process concurrency races proven non-flaky, a
full security review against the module's own checklist, and a
definitive full-regression run. See `docs/modules/TIMETABLE.md` for
the complete as-built record.

**Attendance Foundation (Phase 0H.2) is complete** — Student class
attendance: `AttendanceSession` (the immutable header of one SUBMITTED
class register, carrying an immutable snapshot of the class context it
was instantiated from — AcademicYear/Campus/GradeLevel/Section/
SubjectOffering/teacher/Period plus the Period's wall-clock times — so
later Timetable or Period edits can never rewrite history;
`timetable_entry_id` is provenance only) and `AttendanceRecord` (one
StudentEnrollment's status, bound to its Session by DUAL composite
context foreign keys that make a wrong-Section/year/campus/grade row
structurally impossible), a complete-register submission discipline
over a Students/SIS-owned as-of-date placement roster, `present`/
`absent`/`late`/`excused` with no reason or free-text field anywhere,
expected-status compare-and-swap correction, `attendance.view`/
`attendance.manage` capabilities, an `/api/v1` command surface with a
COMPLETE OpenAPI contract shipped in the same branch, and a
session-authenticated Inertia UI. Phase 0H.2 also added a
Section-before-Enrollment lock order to the existing
`StudentEnrollmentService` so Attendance and Students/SIS serialize on
one shared Section row — a synchronization discipline that changed no
Students/SIS domain outcome. Five real two-process concurrency proofs
(one of which caught and closed a genuine deadlock before it shipped),
raw-PostgreSQL structural-integrity proofs from both FK directions, and
historical-mutation proofs against TimetableEntry edits, Period
retiming and backdated SIS changes. See `docs/modules/ATTENDANCE.md`
for the complete as-built record.

**Syllabus Foundation (Phase 0H.3A) is complete** — the first concrete
Academics fact: `SyllabusUnit`, one ordered unit of instructional
content that a `SubjectOffering` is EXPECTED to cover. A catalogue of
expected content only — it records nothing about what was actually
taught, nothing about an individual lesson, and nothing about any
Student. Exactly one parent (`SubjectOffering`, composite-FK
RESTRICT, no denormalized context); no `Curriculum` entity, no
`academic_term_id`, no `section_id`; required AND elective Offerings
both supported; case-insensitive code uniqueness enforced by an
unconditional PostgreSQL expression index (so an inactive unit keeps
reserving its code, which is why the entity needs no
activate/deactivate command); lifecycle through the ordinary PATCH and
no delete route; `syllabus.view`/`syllabus.manage` capabilities —
deliberately NOT under Academic Structure's `academics.*` root, with
the Academics → Syllabus → `syllabus.*` mapping recorded in
`docs/modules/ACADEMICS.md`; four `/api/v1` operations with a COMPLETE
OpenAPI contract and regenerated shared types in the same branch; and a
session-authenticated Inertia surface at `/app/syllabus`. Classified
**Confidential** — it stores no personal data at all. See
`docs/modules/ACADEMICS.md` for the complete as-built record.

**Curriculum Delivery (Phase 0H.3B) is complete** — the second concrete
Academics fact: `CurriculumDelivery`, the record that one `Section` has
COVERED one `SyllabusUnit` — when that Section began it, and when, if
yet, it finished. Actual instructional coverage by a cohort, the
counterpart to `SyllabusUnit`'s catalogue of expected content; it
records nothing about an individual lesson, nothing about who taught it,
and nothing about any Student. **Section-specific** (per-Section
variation is precisely what the Offering-wide syllabus deferred to
delivery) and **required-SubjectOffering-only in v1** — an elective is a
Student-level enrollment choice, not a Section-wide cohort, which is
Timetable v1's identical restriction and rationale. **Cross-parent
integrity is fully database-authoritative**: two 5-column composite
foreign keys pin the Section and the SubjectOffering to the same
AcademicYear/Campus/GradeLevel, and a third pins the SyllabusUnit to
that exact Offering (consuming one additive, non-destructive
`syllabus_units_offering_context_unique` key added to Phase 0H.3A's
table), so a Section teaching one Subject can never record delivery
against another Subject's unit even by raw SQL. One mutable state row
per Section × SyllabusUnit (`in_progress`/`completed`; **`not_started`
is deliberately the absence of a row**, so consumers LEFT JOIN from
`syllabus_units` rather than counting deliveries), School-local dates
validated as non-future and inside the AcademicYear, expected-status
compare-and-swap transitions under a row lock — proven with two real
separate OS processes — a closed two-edge state machine, and no delete
route. An Application service is required here, unlike SyllabusUnit,
because real invariants exist. `curriculum.delivery.view`/`.manage`
capabilities, a sibling of `syllabus.*` rather than an extension of it;
five `/api/v1` operations with a COMPLETE OpenAPI contract and
regenerated shared types in the same branch; and a session-authenticated
Inertia surface at `/app/syllabus-delivery`. **No teacher identity, no
Timetable dependency, no Attendance dependency, no Student data, no
`academic_term_id`, zero domain events.** Classified **Confidential** —
it stores no personal data at all. See `docs/modules/ACADEMICS.md` §18
for the complete as-built record.

**Academics is NOT complete.** Syllabus Foundation and Curriculum
Delivery are two of its three scoped concerns: **Lesson Planning remains
deferred**, pending a real requirement and the platform's first
ownership-based authorization model, which still does not exist. Phase
0H.3B deliberately stores nothing at lesson granularity, so Lesson
Planning remains fully necessary rather than redundant.

**Examination Foundation (Phase 0H.4A) is complete** — the first
Examinations fact: `Examination`, one named assessment WINDOW that a
School holds within one AcademicYear ("Mid-Term Examination 2026-27,
10–20 September). **A window/container, NOT a paper**: it owns only its
identity and the date range it spans, and owns no Subject,
SubjectOffering, Section, paper, per-paper sitting date/time or max
marks, no Student, enrollment, teacher or invigilator, and no mark,
grade, result, publication state, report card or transcript. Exactly two
parents (School and a composite-FK RESTRICT `AcademicYear`); no Campus
or GradeLevel — per-campus/per-grade variation is a paper concern — and
no `academic_term_id` ("Midterm" is a name, not a term reference;
AcademicTerm still has no lifecycle status and no consuming domain).
**Two deliberate departures from Curriculum Delivery**: future dates are
permitted and expected (an examination is scheduled ahead, exactly as
AcademicYears and AcademicTerms already are), and overlapping windows
are permitted (examinations partition nothing) — so this module
introduces no lock, no exclusion constraint and no concurrency test,
there being no multi-row invariant at all; the AcademicYear need not be
active, so planning next year's examinations inside a draft year is
supported. Case-insensitive code uniqueness within one AcademicYear
enforced by an unconditional PostgreSQL expression index (so an inactive
Examination keeps reserving its code, which is why there is no
activate/deactivate command); `active`/`inactive` through the ordinary
PATCH and no delete route; an Application service because the
AcademicYear range check needs a parent lookup;
`examinations.definitions.view`/`.manage` capabilities, deliberately
depth-2 so a later marks or result-publication family can never be
granted by the same key; four `/api/v1` operations with a COMPLETE
OpenAPI contract and regenerated shared types in the same branch; and a
session-authenticated Inertia surface at `/app/examinations`. Classified
**Confidential** — it stores no personal data at all. See
`docs/modules/EXAMINATIONS.md` and ADR 0032 for the complete as-built
record and the decomposition rationale.

**ExaminationPaper / Scheduling (Phase 0H.4B) is complete** — the second
Examinations fact: `ExaminationPaper`, one SubjectOffering assessed
within one Examination, with its scheduled sitting (date/time range) and
maximum obtainable marks. Offering-wide, never Section-specific. An
additive `examinations_context_unique` (`id, school_id,
academic_year_id`) plus the pre-existing `subject_offerings_context_unique`
let ExaminationPaper declare TWO composite FKs sharing the same stored
`academic_year_id` column, structurally guaranteeing
`Examination.academic_year_id == SubjectOffering.academic_year_id` even
by raw SQL — proven in `Tests\Feature\Postgres\ExaminationPapersRlsIsolationTest`.
Exactly one Paper per `(school_id, examination_id, subject_offering_id)`
(unconditional unique constraint, so an inactive Paper keeps reserving
the pair). Both required AND elective SubjectOfferings supported
identically. Creation requires both parents active; ordinary corrections
never re-check parent activity, but reactivating a withdrawn Paper does.
School-local same-day sittings, positive `max_marks`
(`NUMERIC(6,2)`), overlaps across different Offerings permitted (no
lock, no concurrency test — mirroring Examination's own reasoning).
`examinations.papers.view`/`.manage` capabilities; four more `/api/v1`
operations with a COMPLETE OpenAPI contract and regenerated shared
types; a session-authenticated drill-down UI at
`/app/examinations/{examination}/papers`; zero domain events. Classified
**Confidential**. See `docs/modules/EXAMINATIONS.md` §18 and ADR 0033
for the complete as-built record.

**GradeScale / GradeBand mapping (Phase 0H.4C) is implemented and
published to `main`** (`70e4a43`, 2026-09-03) — the third Examinations fact:
`GradeScale`/`GradeBand`, a named, School-owned percentage-to-grade
mapping wholly independent of the Examination chain. GradeBand stores
ONLY a lower-bound threshold; overlap-freedom is a plain
`UNIQUE(grade_scale_id, min_percentage)` constraint, coverage/gap-
freedom is a single "band exists at 0.00" check — no PostgreSQL range
type, exclusion constraint or `btree_gist`. Lifecycle
`draft/active/inactive`, exactly three legal transitions; GradeBands
mutable only while `draft`, frozen forever once ever `active`. Every
mutating operation reloads the target GradeScale with a parent-row
`lockForUpdate()` — an aggregate-local lock, deliberately NOT
`TenantLock` (a corrected design from the original architecture-gate
recommendation) — proven safe with two real, separate OS processes in
`Tests\Feature\Examinations\GradeScaleConcurrencyTest`.
`examinations.grade_scales.view`/`.manage` capabilities; seven
`/api/v1` operations (no GradeScale delete; GradeBand removal is the
sole delete route) with a COMPLETE OpenAPI contract and regenerated
shared types; a session-authenticated Inertia surface at
`/app/examinations/grade-scales`; zero domain events. Classified
**Confidential**. See `docs/modules/EXAMINATIONS.md` §19 and ADR 0035
for the complete as-built record. *(Corrected 2026-10-06, RES.0B: this
paragraph previously said "not yet published" and named a pending
publication gate; it was published at `70e4a43`.)*

*(Dated note, 2026-10-07: P3 and StudentMark were later built for
development under RES — Highly Sensitive, ADR 0068 — see the RES
checkpoints; results, report cards and transcripts remain unbuilt.)*
**Examinations has STARTED but is NOT complete.** Examination
Foundation, ExaminationPaper/Scheduling and GradeScale/GradeBand
mapping are its first three checkpoints: **marks, result calculation,
result publication, report cards and transcripts are all not
implemented**. The checkpoint that first introduces Student marks
crosses from Confidential into Sensitive personal data and must undergo
a dedicated privacy/security architecture audit — including the
children's-data **[LEGAL REVIEW REQUIRED]** gate in
`docs/security/DATA-CLASSIFICATION.md` — before implementation.

**Phase 0H as a whole is NOT complete** — Timetable, Attendance,
Syllabus Foundation, Curriculum Delivery, Examination Foundation,
ExaminationPaper/Scheduling and GradeScale/GradeBand mapping are done
(GradeScale/GradeBand published to `main`); Academics still
lacks Lesson Planning, and Examinations still lacks marks, results,
report cards and transcripts.

**Phase 0H.4D-P1 — Staff MFA Foundation — is implemented and published
to `main`** (ADR 0037): generic, TOTP-only, User-global multi-factor
authentication infrastructure, built as a mandatory platform
prerequisite identified by the StudentMark engineering-readiness audit
— independent of StudentMark's own legal/compliance status.

**Phase 0H.4D-P2 — Student Processing Authorization Registry — is
implemented and published to `main`** (ADR 0038; registry plus its
locked-state revalidation correction): the second
Students/SIS platform prerequisite — `StudentProcessingAuthorization`,
an append-only, School/Student-scoped record of the processing basis
(Guardian consent, adult Student consent, or statutory School purpose)
authorizing a Student's data processing for a given purpose, gated by
`students.processing_authorizations.view`/`.manage` composed with
`mfa` — the first real production route pairing a capability with MFA.
Phase 0H.4D-P3 (elective historical eligibility, not yet started)
remains fully independent.

Neither P1 nor P2 gates anything yet on its own (no Examinations/marks
route exists to consume either). **StudentMark itself remains NOT
implemented.** StudentMark architecture/backend processing has legal
approval with conditions, but implementation remains blocked by unmet
platform/engineering prerequisites (P2 published, P3 not yet started),
and production enablement remains separately withheld — this
checkpoint does not start StudentMark implementation and does not
itself constitute production approval; see
`docs/security/DATA-CLASSIFICATION.md` and
`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` for the
current classification and conditions.

**"Phase 0H Attendance" means Student class attendance.** Staff/
Employee attendance remains outside this Phase 0H checkpoint and
belongs to the separately-scoped HR/Phase 0J concern, unless future
authoritative roadmap work changes that boundary.

**Owner scope decision (2026-09-29, ADR 0061).** It supersedes, for Phase
Zero closeout only, the historical "Phase 0H as a whole is NOT complete"
above, which stays true for its date.
- **Status:** Phase 0H is **CLOSED FOR PHASE ZERO**. The delivered
  foundation scope stays as built.
- **Deferred post-v1, not required for Phase Zero closure:**
  - Lesson Planning;
  - 0H.4D-P3 elective historical eligibility;
  - StudentMark / marks entry;
  - result calculation and publication;
  - report cards;
  - transcripts;
  - Student/Guardian-facing surfaces.
- **Not implied:** this is not implementation completion and not legal
  clearance. The StudentMark determination is unchanged and not widened.
- **Reopening:** no deferred item resumes automatically. A post-v1
  initiative needs a fresh audit, revalidated legal and security
  decisions, a new checkpoint and explicit owner authorization (ADR 0061
  §2.5).
- **RES reopening (2026-10-06, ADR 0068):** RES.0 audited and ADR 0068
  reopened only 0H.4D-P3 and internal StudentMark. Student marks are
  classified **Highly Sensitive** (superseding the "Sensitive" forward
  notes above). Everything else listed here stays deferred; see "RES
  checkpoints".

## Phase 0I — LMS

**Status: COMPLETE.** Active scope is Learning Content (0I.2) +
Assignment (0I.3), both implemented. Submission was reviewed (0I.4) and
then **cancelled as a product-scope decision on 2026-09-05** — see
below. Phase 0I.4A (Submission legal decision incorporation) is **NOT
REQUIRED — FEATURE CANCELLED**. No further Phase 0I checkpoint is
planned.

**Phase 0I.1 (2026-09-04): architecture contract frozen — complete.**
See ADR 0039 (`docs/architecture/adr/0039-lms-domain-contract.md`) for
the full decision record and `docs/modules/LMS.md` for the living
operational reference.

**Phase 0I.2 (2026-09-04): Learning Content Foundation implemented —
complete.** `App\Domain\LMS` — `LearningContent`/`learning_content`
(`draft→published→archived` lifecycle, `lms.content.view`/`.manage`
capabilities, six `/api/v1` operations plus a session-authenticated
Inertia surface at `/app/learning-content`), and the Documents module's
fourth exclusive-arc owner column (`learning_content_id`, `internal`
tier only) — see `docs/modules/LMS.md` §13 for the full as-built
record.

**Phase 0I.3 (2026-09-04): Assignments implemented — complete.** Same
Confidential/staff-authored posture as Learning Content — six `/api/v1`
operations (`draft→published→closed` lifecycle, `lms.assignments.view`/
`.manage`), a session-authenticated Inertia surface at
`/app/assignments`, and the Documents module's fifth exclusive-arc
owner column (`assignment_id`, `internal` tier only).

**Phase 0I.4 (2026-09-05): Submission legal-gate review conducted —
completed as a governance review; gate never cleared.** A dedicated
legal/data-governance review (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW.md`)
classified Submission Sensitive/`[LEGAL REVIEW REQUIRED]`, performed a
detailed per-category data-inventory/classification exercise, and
formally escalated an 18-question set to qualified legal counsel and
product governance (`docs/security/LMS-SUBMISSION-LEGAL-REVIEW-REQUEST.md`).
**No qualifying response was ever received.**

**Submission capability — CANCELLED / OUT OF SCOPE (2026-09-05).** The
product owner made a final scope decision that LMS student Submission
functionality (coursework submission, Submission records/text/file
uploads, revisions/resubmissions, teacher review of submitted
coursework, Guardian-on-behalf or staff-on-behalf submission,
Submission grading/scoring, the Submission Documents owner arm, and all
Submission events/APIs/UI) is not required for the intended product
(Indian school-system ERP market) and is intentionally cancelled. **This
is a product-scope cancellation, not legal clearance** — the
`[LEGAL REVIEW REQUIRED]` gate is retired because the underlying
feature no longer exists, not because a qualified legal answer was ever
obtained; the historical legal/data-governance concerns Phase 0I.4
raised remain unresolved and must not be assumed settled if this scope
is ever reopened. Both legal-review documents are retained, marked
`CLOSED — FEATURE CANCELLED / OUT OF SCOPE`, as historical governance
records (see `docs/architecture/adr/0039-lms-domain-contract.md`'s
Submission cancellation addendum for the authoritative decision text).

**Phase 0I.4A (Submission legal decision incorporation): NOT REQUIRED
— FEATURE CANCELLED.** There is no legal decision to incorporate; the
feature it would have gated no longer exists.

**Proposed "Phase 0I.5 — Submission implementation": REMOVED /
CANCELLED.** No such checkpoint will be scheduled. Reopening Submission
in the future requires a fresh architecture and governance review from
first principles, not a resumption of this cancelled scope.

No external LMS integration or standard (Canvas, Moodle, Google
Classroom, Microsoft Teams, OneRoster, LTI, QTI, SCORM, xAPI, Common
Cartridge, Caliper) was ever authorized for Phase 0I and none is
authorized now.

## Phase 0J — HR and Payroll

Including the **[LEGAL REVIEW REQUIRED]** statutory-compliance
questions flagged in `docs/security/DATA-CLASSIFICATION.md` (PF/ESI/
TDS and similar) — Compliance module involvement expected here, not
deferred.

**Resequencing note (2026-08-23):** the HR half of this phase's scope
(Employee master record, employment history, assignments, org
structure, directory, documents, lifecycle/rehire — everything short
of Payroll) is being built now, ahead of Phases 0E–0I, under a
separately-numbered initiative ("Phase 8A") on
`feature/phase-8a-hr-employee-records`. This is a deliberate, reviewed
exception — see ADR 0028 and `docs/modules/HR.md` for the full decision
record, scope boundary, and the one accepted cost (Phase 8A.7's
Employee Documents has no Phase 0E Documents module to build on yet, so
it uses a narrow HR-scoped table instead). Payroll itself is untouched
by this note and remains scoped to this Phase 0J entry, in its
originally documented order.

**Closure (2026-08-24):** Phase 8A (8A.0–8A.16) is complete — see
`docs/modules/HR.md`'s "Phase 8A Closure (8A.16, implemented)" section
for the full regression/closure record. Payroll (the remainder of this
Phase 0J entry) remains not started.

**Resequencing note (2026-08-29):** Payroll — the remainder of this
Phase 0J entry — is now being built under a separately-numbered
initiative ("Phase 9") on `feature/phase-9-payroll`, mirroring Phase
8A's own exception. See ADR 0034 and `docs/modules/PAYROLL.md` for the
full decision record and scope boundary. Checkpoint 9.6 (statutory
PF/ESI/TDS) remains gated on the `[LEGAL REVIEW REQUIRED]` flag in
`docs/security/DATA-CLASSIFICATION.md` and will not close without an
explicit legal sign-off or an explicit user-approved scope-narrowing
decision — this entry will not be marked complete while that checkpoint
remains open.

**Closure (2026-09-03):** Phase 9 (9.0–9.5, 9.7–9.12) is engineering-
implementation complete — see `docs/modules/PAYROLL.md`'s "Phase 9.12
Closure" section for the full reconciliation/migration-compatibility/
regression-attribution record, including a rigorous zero-Phase-9-
regression proof against a genuinely separate `main` environment.
**Checkpoint 9.6 (statutory PF/ESI/TDS) remains BLOCKED/DEFERRED**,
gated on the `[LEGAL REVIEW REQUIRED]` flag in
`docs/security/DATA-CLASSIFICATION.md` — this Phase 0J entry is
therefore **not** marked fully complete; it is "engineering
implementation complete through non-statutory scope, statutory Payroll
deferred." (Historical as of that closure.)

**Publication (2026-09-03):** Phase 9 non-statutory Payroll was published
to `main` (`99cb641`, "merge: publish Phase 9 Payroll (non-statutory
scope) into main"). Checkpoint 9.6 (statutory PF/ESI/PT/LWF/TDS,
9.6A–9.6K) was then implemented under the accepted legal basis
`SCH/PAY/REG/2026-9.6` (ADR 0036) and published (`a8916cd`, "merge:
publish Phase 9.6 Statutory Payroll to main"). One statutory item
remains deliberately deferred: the ESI disability special threshold
(₹25,000), `DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED` (ADR
0036). Phase 0J is therefore engineering-complete including statutory
scope, with that single disclosed legal deferral.

## Phase 0K — Operational Modules

Transport, Library, Inventory, Canteen, Hostel, Health, Visitor, Safety
— roughly independent of each other, sequenced by product priority once
reached, not strict dependency order.

**Phase 10A — Library (complete):** the first Phase 0K checkpoint —
catalogue (Title/Copy) + physical circulation (checkout/check-in), a
database-enforced single-active-loan-per-Copy invariant proven under
real concurrency, `library.catalogue.*`/`library.circulation.*`
capabilities, `/api/v1` administrative API, and a session-authenticated
Inertia UI. Full design and closure record: `docs/modules/LIBRARY.md`.
Deliberately excludes fines/Finance integration (Finance/Phase 0G was
not yet on `main` when this checkpoint closed), reservations/holds/renewals, Documents-module
integration, and any Guardian/Student-facing surface — all explicitly
deferred, not gaps in this checkpoint's own closure.

**Phase 10B — Transport (complete):** the second Phase 0K checkpoint —
Routes + ordered Stops, Vehicles, the historical Route↔Vehicle↔Driver
operational assignment (auto-replace semantics, driver = existing HR
Employee referenced by id, never duplicated), and Student Transport
assignment (explicit-end-required semantics, a database-enforced
Stop-belongs-to-Route composite FK, and a one-active-assignment-per-
Student invariant proven under real concurrency),
`transport.routes.*`/`transport.vehicles.*`/`transport.assignments.*`
capabilities, `/api/v1` administrative API, and a session-authenticated
Inertia UI. Full design and closure record: `docs/modules/TRANSPORT.md`.
Deliberately excludes GPS/live-tracking (Student Transport location is
Sensitive data — a dedicated privacy/architecture review is required
before any future checkpoint attempts it), bus boarding/attendance,
Transport fees/Finance integration (Finance/Phase 0G was not yet on
`main` when this checkpoint closed),
Documents-module integration for vehicle/driver documents, and any
Guardian/Student-facing surface — all explicitly deferred, not gaps in
this checkpoint's own closure.

**Phase 10C — Visitor (complete):** the third Phase 0K checkpoint — a
Visitor directory (reference records) and check-in/check-out Visit
lifecycle against a Campus with an optional HR Employee host
(referenced by id, never duplicated), a database-enforced one-active-
Visit-per-Visitor invariant proven under real concurrency,
`visitor.directory.*`/`visitor.visits.*` capabilities, `/api/v1`
administrative API, and a session-authenticated Inertia UI. Full design
and closure record: `docs/modules/VISITOR.md`. Deliberately excludes
government-ID numbers/scans, biometrics/facial recognition, retained
photographs, blocklist/watchlist/risk-scoring (`status=inactive` is an
ordinary reference-lifecycle flag, not a security blocklist —
`docs/modules/VISITOR.md` §5), billing, public kiosk/self-registration/
pre-registration flows, Documents/Communications integration, and any
Guardian/Student-facing surface — all explicitly deferred, not gaps in
this checkpoint's own closure. Safety/incident management was
deliberately NOT built as part of this checkpoint — see below.

**Phase 10D — Hostel (complete):** the fourth Phase 0K checkpoint —
a Hostel directory belonging to exactly one Campus, HostelRoom and
HostelBed (capacity/occupancy always derived from active Bed/
residency-assignment rows, never a stored counter), and Student
residency assignment (explicit-end-required semantics, database-
enforced composite FKs at every level of the Campus → Hostel →
HostelRoom → HostelBed → HostelResidencyAssignment hierarchy — all
RESTRICT on delete, never CASCADE, per the Phase 10C Visitor
historical-integrity correction), two database-enforced invariants
(one active residency per Bed AND per Student) proven under real
concurrency with a documented deterministic lock order,
`hostel.directory.*`/`hostel.residency.*` capabilities, `/api/v1`
administrative API, and a session-authenticated Inertia UI. Full
design and closure record: `docs/modules/HOSTEL.md`. `HostelRoom` is a
deliberately independent model, not a reuse of Academic Structure's
teaching-space `Room`. Deliberately excludes Hostel fees/billing/
deposits (no Hostel↔Fees integration was built in this checkpoint,
regardless of Finance/Fees now being on `main` — see below), warden/
staff management, meal plans/Canteen integration, Health/Safety data,
Documents/Communications integration, and any Guardian/Student-facing
surface — all explicitly deferred, not gaps in this checkpoint's own
closure.

**Phase 10E — Inventory (complete):** the fifth Phase 0K checkpoint —
an Item catalogue and Location directory (Location's Campus optional,
mirroring Library Copy/Transport Route's precedent, not Hostel's
required-Campus special case), a quantity stock lifecycle (receive/
issue/transfer) backed by a stored, authoritative
`InventoryStockBalance` (one row per Item x Location) reconciled by
construction against an immutable, append-only `StockMovement` ledger
— proven never to drift by a dedicated reconciliation test. A
database-enforced non-negative-stock invariant, a concurrency-safe
missing-balance-row creation primitive (`INSERT ... ON CONFLICT DO
NOTHING` then re-read, never a naive `firstOrCreate()`+
`lockForUpdate()`), and a deterministic ascending-balance-id transfer
lock order (never a fixed source-then-destination role order, which
would deadlock opposing concurrent transfers) are each proven under
real two-process concurrency — three required scenarios: over-issue,
concurrent first-ever receipts, and opposing concurrent transfers, all
passing with no deadlock. `inventory.directory.*`/`inventory.stock.*`
capabilities, `/api/v1` administrative API (command-style receive/
issue/transfer only, never a generic movement-creation endpoint), and
a session-authenticated Inertia UI. Full design and closure record:
`docs/modules/INVENTORY.md`. Deliberately scoped to quantity/
consumable stock only — individually tracked assets, custody
(Employee/Student), procurement/suppliers/purchase orders, costing/
valuation/Finance journal posting, Fees/Payments, Canteen consumption
integration, barcode/RFID/mobile scanning, reorder automation, and any
Guardian/Student-facing surface all remain deliberately deferred, not
gaps in this checkpoint's own closure — see `docs/modules/INVENTORY.md`
§25 for the full list.

**Phase 10F — Canteen (complete):** the sixth Phase 0K checkpoint — an
Outlet directory (each backed by exactly one InventoryLocation,
structurally immutable after creation, with a database-enforced
Campus-consistency composite FK), a menu Item catalogue with a
per-Item recipe evaluated AT FULFILLMENT time (never snapshotted at
placement — a deliberate, documented asymmetry with the price/location
snapshot Order placement DOES take), and a Student order lifecycle
(place → fulfill → cancel) where fulfillment is the cross-domain
orchestration boundary: `CanteenOrderService::fulfill()` calls a new,
additive `InventoryStockService::issueMany()` method (issuing multiple
Items' stock against one Location atomically, deterministic
ascending-balance-id lock order, proven deadlock-free under real
concurrency — `docs/modules/INVENTORY.md` §5.1) and Fees'
`ChargeService::assess()`, inside one outer transaction, with no
internal capability re-check. A database-enforced one-Charge-per-Order
partial unique index, a one-Movement-claimed-by-one-Order consumption
link, and four concurrency scenarios (double fulfillment, scarce-stock
racing, cancel/fulfill race, recipe-mutation-vs-fulfillment torn read)
are each proven under real two-process concurrency.
`canteen.directory.*`/`canteen.orders.*`/`canteen.settings.*`
capabilities (the settings pair deliberately School-Admin-only by
default, mirroring `finance.charges.*`), `/api/v1` administrative API,
and a session-authenticated Inertia UI. During closure, a
capability-boundary bug the UI-building checkpoint had honestly
flagged (two picker endpoints reusing Inventory's own
`inventory.stock.manage`-gated search routes rather than a
Canteen-scoped one) was fixed with regression tests, and a full
security-review pass against the checkpoint's own checklist found no
other real issues. Full design and closure record:
`docs/modules/CANTEEN.md`. Deliberately excludes wallets/prepaid
balances, dietary/allergen/medical data, refunds/financial reversal of
a fulfilled Order, recipe versioning, and any Guardian/Student-facing
ordering surface — all explicitly deferred, not gaps in this
checkpoint's own closure — see `docs/modules/CANTEEN.md` §16 for the
full list.

The remaining Phase 0K modules (Health, Safety) are **not started**.
Health remains blocked on the `docs/security/DATA-CLASSIFICATION.md`
[LEGAL REVIEW REQUIRED] gate; and Safety is blocked pending its own
legal/security readiness decision, since "incident records" may fall
under that same unresolved Health gate (`docs/modules/VISITOR.md` §18,
§24) — ideally resolved alongside Health's own review rather than
separately. Neither Health/Safety legal blocker is resolved by
Canteen's closure.

**Phase 0K status: Closed with explicitly deferred legally/security-blocked
scope** (2026-08-29 readiness audit, `docs/modules/PHASE-10-CLOSURE.md`).
This is not the same thing as "complete" — Phase 0K's originally
documented scope was eight modules, and two of them (Health, Safety)
remain wholly unimplemented, blocked on the unresolved legal/security
prerequisites recorded above, not on remaining engineering work. This
status means: the six modules that were not legally/security-blocked
(Transport, Library, Inventory, Canteen, Hostel, Visitor) are each
individually complete per their own module docs, and there is
currently no further Phase 0K engineering work authorized to start —
Health and Safety each require their own recorded legal/security
resolution (see `docs/modules/PHASE-10-CLOSURE.md` for the exact
reopening criteria) before a fresh readiness gate, not implementation,
can begin for either. Phase 0K as a whole is **not** complete, and
Health/Safety are not cancelled, removed from scope, or retroactively
optional.

## Phase 0L — Oversight

Compliance, Analytics, Automation (Layer 5) — read-mostly consumers of
everything built so far; deliberately sequenced after there's
meaningful data/events for them to work with.

**Phase 0L.1 (2026-09-05): Analytics Domain Contract frozen, zero
implementation.** Analytics only — see ADR 0040
(`docs/architecture/adr/0040-analytics-domain-contract.md`) for the
full ten-decision record and `docs/modules/ANALYTICS.md` for the living
operational reference. No migration, model, controller, service,
route, or capability exists yet. Single-School (tenant-local) Analytics
only in v1; cross-School/platform-wide Analytics explicitly deferred to
its own future checkpoint. Aggregate/derived data inherits the
strongest classification tier among its sources by default
(`docs/security/DATA-CLASSIFICATION.md`); a minimum-cohort-size
suppression threshold for small-group re-identification risk is
recorded as an explicit, still-open **[LEGAL/PRODUCT/SECURITY REVIEW
REQUIRED]** gate — no Analytics read model capable of producing a
small-cohort cell may ship until it is set. Compliance and Automation
remain wholly unscoped by this checkpoint and require their own future
contracts.

**Recommended next checkpoint: Phase 0L.2 — Analytics Foundation**
(one or two already-stable Layer 0–4 data sources, a real, minimal,
capability-gated, single-School read surface, following ADR 0040
exactly — no dashboard/API/schema exists yet). **Readiness gate
recorded 2026-09-23 — still BLOCKED** on the owner decisions listed in
`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7 (minimum cohort
size, suppression mode, policy scope, counsel review for Student-data
sources, capability grants, first source); implementation does not
start until they are recorded.

**Phase 0L.2-1 — Analytics Foundation + Curriculum Coverage: COMPLETE
(2026-09-23).** Owner-approved interim scope (ADR 0040 2026-09-23
amendment): `App\Domain\Analytics` (read gate, read-model declaration,
fail-closed person-cohort policy, registry), `analytics.view` for School
Admin and Principal, `analytics.export` seeded but unused, and one
non-person report — Curriculum Coverage (`/app/analytics/curriculum-coverage`)
— reading Curriculum Delivery's new aggregate contract. **Person-counting
Analytics remains BLOCKED** on gate decisions 7.1–7.4; no export; no
cross-School Analytics. The full regression checkpoint that followed it
passed and was published at `8eb5b74`.

**Phase 0L.2 — Analytics Foundation: COMPLETE (2026-09-23).** Closed
with the one non-person source built in 0L.2-1 — ADR 0040 asks for "one
or two already-stable Layer 0–4 sources", and export was optional and
not chosen (gate decision 7.6). Completeness matrix and evidence:
`docs/architecture/PHASE-0L2-ANALYTICS-FOUNDATION-CLOSEOUT.md`. This
closes the non-person foundation only: **person-counting Analytics
remains BLOCKED** on gate decisions 7.1–7.4 as a future, separately
gated checkpoint, and Analytics export, cross-School Analytics,
Compliance and Automation remain unscoped. No later Phase 0L checkpoint
is defined or authorized by this closeout.

**Phase 0L.3 (2026-09-24): Compliance Domain Contract frozen, zero
implementation.** The product owner chose Compliance ahead of
Automation and named this checkpoint. ADR 0042
(`docs/architecture/adr/0042-compliance-domain-contract.md`) is the
decision record and `docs/modules/COMPLIANCE.md` the living reference
(inventory, gates, plan). Compliance owns read-only evidence and
regulatory-reporting views over records other modules keep; it owns no
source record, calculates and files nothing (Payroll keeps statutory
calculation and its data-preparation exports — ADR 0034/0036, which
also supersede the Phase 0J line above about "Compliance module
involvement"), deletes nothing, sets no retention and claims no legal
compliance. Single-School only; `compliance.*` reserved, not seeded;
audit-log review uses the existing `school.audit.view`. Retention,
legal holds, data-subject requests and statutory-reporting scope remain
**[LEGAL REVIEW REQUIRED]**. **Proposed next (not started, needs its
own go-ahead): Phase 0L.4 — Compliance Foundation: School Audit-Log
Review**, gated on the audit-record classification/metadata decision
and confirmation of the `school.audit.view` grant
(`COMPLIANCE.md` §5–§6). Automation remains unscoped and needs its own
contract.

**Phase 0L.4 — Compliance Foundation: School Audit-Log Review: COMPLETE
(2026-09-24).** Owner-approved decisions (ADR 0042 amendment): School
audit records Highly Sensitive for this surface (conservative v1), empty
metadata allowlist, `school.audit.view` for School Admin and Principal
only. `App\Domain\Compliance` (read-only) plus the ledger's read contract
`App\Support\Audit\SchoolAuditEventReader`; `/app/compliance/audit-log`
shows envelope fields only, newest first, keyset-paginated, and audits
each review. No migration, capability seed, filter, export, API, platform
or cross-School view. The page does not assert legal compliance. No
further Compliance checkpoint is scheduled: the remaining candidates
(`COMPLIANCE.md` §6) are each blocked on their own gates.

**Phase 0L.5 (2026-09-24): Automation Domain Contract frozen, zero
implementation.** The product owner set the number and title; ADR 0043
(`docs/architecture/adr/0043-automation-domain-contract.md`) is the
decision record and `docs/modules/AUTOMATION.md` the living reference.
Automation runs code-catalogued, School-scoped rules (one domain-event or
schedule trigger, one fixed condition over source read contracts, one
action through an approved Application-layer entry point); Schools
enable and own rule instances but never author rules. Executions act as
the rule's accountable owner, re-verified every run and limited to the
action's declared capability — no new principal, no service identity.
Only informational review items are allowed in v1; internal
notifications and automation-safe source commands are gated;
approval-required actions are out of v1; financial, payroll,
student/employee status, grants, deletion, consent, external or
Emergency communications are prohibited. `automation.*` reserved, not
seeded; execution-log retention **[LEGAL/POLICY REVIEW REQUIRED]**.
**Proposed next (not started, number to be assigned): Automation
Foundation**, blocked on the owner decisions in `AUTOMATION.md` §5 (first
rule type, roles, authority-model confirmation, opt-in).

**Phase 0L.6 — Automation Foundation: Academic Year Setup Review:
COMPLETE (2026-09-24).** Owner decisions (ADR 0043 amendment): the one
rule `academic_year.setup_review` (existing `academic_year.activated.v1`
event → tier 0 review item in Automation's own table), accountable-owner
authority re-verified per execution, `automation.view` for School Admin
and Principal and `automation.manage` for School Admin only, School
opt-in flag `automation.rules` default off (Demo School on, Annexe off).
Proves one trigger, one tier 0 effect, School scope, authority
re-check/suspension, idempotency (unique execution, lease claim, bounded
domain retry) and audit — not general Automation. Also fixed a latent
`FeatureFlagResolver` worker-scope defect. **A full regression
checkpoint is required before the next development unit.**

**Phase 0L — Oversight: COMPLETE (2026-09-24).** Each Layer 5 module has
a published contract and foundation: Analytics (0L.1/0L.2), Compliance
(0L.3/0L.4), Automation (0L.5/0L.6). The full regression after 0L.6
passed at `8a9825f` (5,457 tests, 0 failures, only the ESI-12 legal
skip). Everything not built is optional future scope or blocked on a
recorded decision — person-counting Analytics (gate 7.1–7.4), Compliance
retention/legal holds/data-subject requests/statutory reporting, and
Automation tiers 1–3 among them — and none is scheduled. Closeout,
matrix, gates and known debt:
`docs/architecture/PHASE-0L-CLOSEOUT.md`. Next dependency: Phase 0M,
gated on the provider data-handling legal/compliance review; its
readiness-gate document is the next task, not Phase 0M itself.

## Phase 0M — AI Platform: Real Agents

First real model-provider integration (ADR 0013, with the
**[LEGAL/COMPLIANCE REVIEW REQUIRED]** provider data-handling review
from `docs/security/DATA-CLASSIFICATION.md` completed first), first
real agent and tool with an actual Laravel-side *write* effect (most
likely fee-reminder drafting, given the worked example in
`docs/ai/AI-SECURITY.md` — Phase 0B's `school.echo` tool is read-only
by design), first real human-approval workflow for financial/
irreversible actions. AI Gateway-side durable audit is no longer an
open item here -- Phase 0C's audit write-back already closed it.

**Readiness gate recorded 2026-09-24 — BLOCKED.**
`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` sets out the
reusable per-provider review matrix, the data that could reach a
provider (none may before approval), the fail-closed state today (only
`NullProvider`; no production caller of `AiGatewayClient`), four gaps to
close before any real provider (`/v1/complete` without a context token,
model calls not durably audited, response bodies in gateway logs/errors,
no off-by-default provider switch), and the provider/legal, product and
security decisions that must be recorded before implementation. No
provider is selected. (Clarifies the audit note above: tool calls are
written through durably; model calls are not yet.)

**AI Gateway fail-closed hardening (2026-09-24, NullProvider only):**
the four gaps are closed — completions need a Laravel-verified context
token, every model call is audited durably (no output without its
audit), gateway logs and errors carry no bodies, and an external
provider can be neither registered nor selected while
`REAL_PROVIDERS_ENABLED` is off (its default). Phase 0M remains
**BLOCKED** on the provider/legal and product decisions.

**Owner scope decision (2026-09-29, ADR 0061).**
- **Status:** Phase 0M is **CLOSED FOR PHASE ZERO — REAL PROVIDERS / REAL
  AGENTS DEFERRED POST-v1**. The "BLOCKED" records above stay true for
  their dates.
- **Not complete:** Phase 0M is not complete, and its gate is not cleared.
- **Safe state unchanged:**
  - `NullProvider` only;
  - no provider SDK or credentials;
  - no production caller;
  - `REAL_PROVIDERS_ENABLED` off;
  - no real agent, AI write tool or AI approval workflow.

  The G1–G4 hardening, context token, capability checks, durable AI audit
  and service authentication all stay.
- **Reopening gate:** the unresolved section 18 decisions of
  `docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` (18A, 18B, 18C).
  No School data may reach a real provider before that gate is passed
  (ADR 0061 §3.5).

## Phase 0N — Multi-School Management

Group/Trust cross-school administration and reporting, building on
Phase 0B's explicit-elevation model (ADR 0004) and its structural
foundation (`school_groups`, `school_group_members`) — the actual
"enter a member School's context as a group/platform admin" workflow is
still unbuilt after Phase 0B, deliberately (see that phase's own
summary above).

**Readiness audit recorded 2026-09-24 — BLOCKED.**
`docs/architecture/PHASE-0N-READINESS.md` records what exists (multi-School
membership and switching, RLS with no runtime bypass, a Platform Super
Admin with capabilities but no School scope and no UI, group tables with
no behaviour), that the elevation workflow, group principal model and
group reporting are unspecified, and the architecture, product and
security decisions required first. It also records a prerequisite found
during the audit: with no School selected — every user after sign-in —
135 of 141 School pages return 500. The proposed first checkpoint fixes
that and adds a platform-scope landing, without elevation.

**Phase 0N.1 — Safe School Context & Platform Landing (done, 2026-09-24).**
D9(a) and D10(a) approved and implemented: every School-scoped web route
requires a valid selected School before anything School-scoped runs
(`school-context`, `RequireSchoolContext`; pages return to `/app`,
mutations/JSON get 409 `school_context_required`), stale selections are
cleared, `/app` is a context-neutral landing (explicit selection, or a
neutral state for accounts with no School, including the Platform Super
Admin), and a route guard test keeps new School pages inside the
boundary. No elevation, group, reporting or platform-management work.
Phase 0N itself stays **BLOCKED** on D1–D8 and D11–D18.

**Phase 0N.2 — Cross-Tenant Elevation Contract (done, 2026-09-24,
documentation only).** The owner approved D2–D8, D14 and D17;
**ADR 0044** freezes the contract for a platform actor temporarily
establishing one School's context: explicit, confirmed, reason-coded,
MFA-assured, bounded, visible, audited; a persistent elevation record
(one active per actor, no reactivation); elevation establishes tenant
context but grants **no** School capability and authorizes **zero**
source modules — School routes refuse elevated context unless an
operation opts in through its own ADR; web session only, never
`/api/v1`, internal APIs or AI; not cross-School reporting, group
administration or School lifecycle. Nothing is built. Before the
proposed substrate checkpoint (Phase 0N.3, ADR 0044) the owner must set
the elevation duration, the reason-code catalog, MFA re-verification vs.
re-login and target selection. Phase 0N stays **BLOCKED** on D1, D11,
D12, D13, D15, D16 and D18.

**Phase 0N.3 — Platform Elevation Substrate (done, 2026-09-24).** Owner
values: fixed 30 minutes; a fresh in-session MFA re-verification on every
start; reason codes `operational_support`, `security_investigation`,
`configuration_assistance`, `incident_response`; exact target (verified
School domain or School UUID), no directory. Built: the
`platform.schools.elevate` capability (Platform Super Admin only), the
database-guarded `school_elevations` record (one active per actor, 30-
minute CHECK, no reactivation, undeletable), start/confirm/exit, the
elevated-access banner, per-request validation with forced termination,
an expiry sweep, the five platform audit events and
`school_audit_events.elevation_id`. **Zero** School or source-module
routes accept elevated context: an elevated Platform Admin gets 403 on
every School page and API route. Phase 0N stays **BLOCKED** on D1, D11,
D12, D13, D15, D16 and D18.

**Phase 0N.4 — Group/Trust Governance Contract (done, 2026-09-24,
documentation only).** The owner approved D1 and D18; **ADR 0045**
records a distinct Group scope (`group` roles, `group.*` capabilities, a
new `group_role_assignments` grant — never a platform role, School role or
membership), Group authority that grants no School capability,
platform-governed Group membership and grants (a Group Admin changes
neither; no self-grants), multiple Groups per School with single-Group
attribution, Group archive instead of deletion, and Group-derived School
entry only through ADR 0044 elevation recording the authorizing Group and
grant, ended immediately by removal, revocation or archive. No
cross-School reporting. Proposed next: Phase 0N.5 Group Authority
Foundation, gated on classifying Group records (ADR 0045 §13a). Phase 0N
stays **BLOCKED** on D11, D12, D13, D15 and D16.

**Phase 0N.5 — Group Authority Foundation (done, 2026-09-24).** Owner
classifications: Group details, Group membership and member-School identity
Confidential; human Group grants Sensitive. Built: exactly three role
scopes enforced by the database (and roles may hold only their own scope's
capabilities); the `group_admin` role (`group.schools.view`,
`group.schools.elevate`); history-keeping, never-self-granted
`group_role_assignments`; Groups archived, never deleted; platform
governance (`platform.school_groups.view`/`.manage`,
`platform.school_group_grants.manage`) with its seven audit events; the
Group Admin's read-only Group view; and Group-derived ADR 0044 elevation
recording the authorizing Group and grant, checked by the database at
start and ended immediately by removal, revocation or archive (proven
against real concurrent processes). A School may belong to several Groups.
**Zero** School routes accept elevated context. Phase 0N stays **BLOCKED**
on D11, D12, D13, D15 and D16.

**Phase 0N.6 — Platform Authority & Audit Governance Contract (done,
2026-09-24, documentation only).** The owner approved D12 and D16;
**ADR 0046** records: `platform_super_admin` as the root/bootstrap role,
never granted or revoked in-app; School creation root-only (its meaning
stays D11); runtime-assignable, code-approved non-root platform roles
only — v1 `platform_auditor` — with no self-grant and root-reserved
capabilities (`platform.role_grants.manage`, `platform.schools.manage`);
history-keeping platform role grants; and the platform audit review
(`platform.audit.view`, context-neutral, Highly Sensitive, seven envelope
fields, empty metadata allowlist, keyset paging, one
`platform.audit_log.viewed` event per review, no export). Proposed next:
Phase 0N.7 Platform Authority & Audit Foundation. Phase 0N stays
**BLOCKED** on D11, D13 and D15.

**Phase 0N.7 — Platform Authority & Audit Foundation (done, 2026-09-25).**
Owner decisions: platform audit review requires the existing MFA
assurance (no fresh code per page); the metadata allowlist stays empty;
the root stays out of band. Built: `platform.audit.view`,
`platform.role_grants.manage` (root only), the `platform_auditor` role
(the only runtime-assignable one); `roles.runtime_assignable` with
database guards keeping root-reserved capabilities off it; history-keeping
`platform_role_assignments` (no self-grant/-revoke, no runtime grant or
revocation of the root role, no deletion, RESTRICT FKs);
`/app/platform/roles` to grant/revoke the auditor with audited refusals;
and `/app/platform/audit-log` — seven envelope fields, keyset pages of 50,
one `platform.audit_log.viewed` per review, no School context. Phase 0N
stays **BLOCKED** on D11, D13 and D15.

**Phase 0N.8 — School Lifecycle & Bootstrap Administration Contract
(done, 2026-09-25, documentation only).** The owner decided D11 and D13
for v1; **ADR 0047** records: the existing `schools.status` as the
lifecycle (new `provisioning` value; `provisioning → active → suspended →
active`; `archived` kept with no transition; database-enforced
transitions); root-only CREATE with a mandatory bootstrap School Admin (a
real ordinary membership and `school_admin` assignment for an exact,
existing, enabled user, established or replaced only while
`provisioning`, closed permanently at first activation); explicit
ACTIVATE requiring a qualifying active admin; SUSPEND with closed reason
codes, eager elevation termination (`school_suspended`) and
execution-time enforcement per substrate (webhooks/communications
deferred, automation skipped, announcements held, AI and invitation
acceptance refused, platform safety work continuing); RESUME with no
global replay; `platform.schools.manage` plus fresh MFA re-verification
and confirmation for every lifecycle action; platform-ledger-only audit;
no ongoing platform membership administration; no application School
delete and a revoked runtime `DELETE` on `schools`; archive/delete behind
a retention/legal decision. Proposed next: Phase 0N.9 School Lifecycle
Foundation. Phase 0N stays **BLOCKED** on D15 only.

**Phase 0N.9 — School Lifecycle Foundation (done, 2026-09-25).** Built
ADR 0047: `schools.status` CHECK, default `provisioning` and a transition
trigger; `REVOKE DELETE ON schools` from the runtime role (test teardowns
moved to the admin connection); `SchoolLifecycleService` and
`SchoolBootstrapAdministrationService` behind `platform.schools.manage`,
explicit confirmation and a fresh MFA code, with audited refusals;
`/app/platform/schools` (create, bootstrap administrator while
provisioning, activate, suspend with reason code, resume); eager
termination of elevations (`school_suspended`) and an elevation INSERT
guard; `SchoolOperationalGuard` execution-time checks (webhooks and
communications deferred without consuming attempts, automation skipped,
announcements held, AI minting and internal AI endpoints refused, Guardian
invitations unusable); real two-process race proofs. No archive, delete,
break-glass or platform membership administration. Next: Phase 0N.10
Cross-School Reporting Contract (D15). Phase 0N stays **BLOCKED** on D15.

**Phase 0N.10 — Cross-School Reporting Contract (done, 2026-09-25,
documentation only).** The owner decided D15; **ADR 0048** records a
narrow Group reporting model: explicit Group authority
(`group.reporting.view`, v1 held by `group_admin`; never implied by
platform roles, School roles, multi-School membership or elevation); an
Analytics-owned, code-registered Group-safe report registry containing only
`curriculum.coverage` (syllabus-unit counts, Confidential, no persons);
per-School execution with exactly one `TenantContext` at a time, member
Schools re-checked FOR SHARE and only `active` ones read; sums of counts and
a recomputed unit-weighted percentage (never averaged percentages); current
MFA assurance; `platform.school_group_report.viewed` / `.failed` on the
platform ledger; failures fail closed (partial results only for expected
unavailable Schools); no persistence, no export, no Compliance, Automation
or AI cross-School access; no RLS change. ADR 0048 is also ADR 0040 §4's
cross-School ADR for that one report. **Architecture decisions complete —
first cross-School report implementation remains:** Phase 0N stays in
progress until Phase 0N.11 Group Curriculum Coverage Reporting Foundation
is built.

**Phase 0N.11 — Group Curriculum Coverage Reporting Foundation (done,
2026-09-25).** Built ADR 0048: `group.reporting.view` (held by
`group_admin` only); Analytics' separate Group-safe registry
(`curriculum.coverage` only), `GroupSafeReportGate` and an active-year-only
School summary; `GroupCurriculumCoverageReportService` observing each
member School in its own transaction (Group, grant and membership FOR
SHARE; School FOR SHARE; exactly one `TenantContext`, cleared after each);
unit-weighted totals recomputed from summed counts; `unavailable` and
`no active academic year` states; fail-closed authority loss (404) and
source failure (503), audited; `/app/groups/{schoolGroup}/reports/curriculum-coverage`
with current MFA assurance; no export, cache or persistence; real
two-process race and raw-SQL RLS proofs. **Phase 0N implementation
complete — ready for the Phase 0N closeout audit.**

**Phase 0N — Multi-School Management: COMPLETE (2026-09-25).** Every
roadmap item is built and every readiness decision (D1–D18) has a recorded
disposition: the safe no-School landing (0N.1), explicit temporary
platform elevation granting no School capability and accepted by no School
route (ADR 0044, 0N.3), Group/Trust authority with Group-derived elevation
(ADR 0045, 0N.5), platform authority and the MFA-protected platform audit
review (ADR 0046, 0N.7), School lifecycle with bootstrap administration and
execution-time suspension (ADR 0047, 0N.9), and the first Group
cross-School report, Curriculum Coverage (ADR 0048, 0N.11). The full
regression passed at `fc6d799` (5,685 tests, 0 failures, only the ESI-12
legal skip). Everything not built is future scope deferred by an ADR or
gated on a recorded decision — School archive/delete, break-glass
recovery, further Group reports and export, cross-School Compliance,
Automation and AI, person-counting Analytics among them — and none is
scheduled. Closeout, ledger, decision matrix and deferred register:
`docs/architecture/PHASE-0N-CLOSEOUT.md`. Next roadmap phase: Phase 0O,
which has no readiness audit yet; Phase 0M remains BLOCKED on its
legal/compliance gate.

## Phase 0O — External Surface and Production Readiness

Public developer API hardening (rate limiting, partner API keys,
building on Phase 0C.2's documented rate-limit/idempotency
interaction), a real observability backend (ADR 0015, building on
whatever instrumentation-only foundation Phase 0C's core substrate
lands), production secrets/infrastructure (ADR 0016,
`infrastructure/terraform`), and broader third-party integrations
(ADR 0018) beyond the payment gateway from Phase 0G.

> **Correction (2026-09-28, ADR 0057).** The premise "the payment gateway
> from Phase 0G" was false: no gateway exists. O2 defers the first real
> gateway from Phase 0 / production v1. O15 fixes the v1 status of every
> other ADR 0018 category and of production partner API scopes.

**Readiness audit recorded 2026-09-25 — PARTIALLY READY; SOME
CHECKPOINTS MAY START.** `docs/architecture/PHASE-0O-READINESS.md` maps
the four scope items against what exists (a production-grade outbound
webhook subsystem, instrumentation-only observability, env-only
configuration, strong tenancy/RLS and authorization foundations) and what
does not: no `/api/v1` token issuance or expiry, no partner keys, no
default API limiter, no trusted-proxy/security-header/CORS policy, no
observability backend, no production images, IaC, release runbook or
backup/restore, no fail-closed production configuration (the AI context
signing key and the AI Gateway service token have unsafe fallbacks), and
no production root-provisioning command. It records sixteen owner,
security, provider and operator decisions (O1–O16), a deploy-gated register
under the stop gates below, and a roadmap discrepancy: no Phase 0G payment
gateway was ever integrated. Only **0O.1 — Production Bootstrap &
Fail-Closed Configuration Foundation** is ready to start; the rest waits on
those decisions. Phase 0M stays BLOCKED and must not be approached through
Phase 0O.

**0O.1 — Production Bootstrap & Fail-Closed Configuration Foundation:
COMPLETE (2026-09-25).** Operator-only root provisioning
(`platform:provision-root`, ADR 0046 §2), a production boot check that
refuses unsafe configuration, a fail-closed AI context signing key, an AI
Gateway that refuses the development token outside local/testing and
reports 503 when not ready, a guarded `ServiceIdentitySeeder` plus operator
issue/disable commands, the CI role-provisioning fix, and the production
process and release contract (`docs/architecture/PRODUCTION-RELEASE.md`).
Phase 0O stays PARTIALLY READY; decisions O1–O16 remain open
(`PHASE-0O-READINESS.md` §13).

**0O.1A — Root Bootstrap Boundary Correction (2026-09-25).** 0O.1 was
first published with a residual: the runtime database role could insert a
grantor-less root assignment, and production had no first-account path.
The database now accepts an out-of-band platform grant only from the
administrative (table-owner) role, and `platform:bootstrap-root` creates the
first platform account on a fresh installation (interactive, hidden
password, first boot only). 0O.1 is COMPLETE; O14 password reset stays
open (`PHASE-0O-READINESS.md` §14).

**0O.2 — External API & Browser Hardening Contract (2026-09-25).** ADR 0049
resolves O7 (human API tokens and School-bound partner API clients:
expiring, scoped, re-checked per request, MFA-protected management,
deny-by-default partner surface) and O11 (exact-origin CORS allowlist,
enforced CSP, security headers, production-only HSTS), and defines Phase
0O.3 — External API & Browser Hardening Foundation. Five numeric values
(token/credential lifetimes, HSTS `max-age`) are owner values required
before 0O.3.

**0O.3 — External API & Browser Hardening Foundation: COMPLETE
(2026-09-25).** Owner values 30/90-day human tokens, 90/365-day partner
credentials, HSTS 31,536,000 s. Human API tokens (Account page, fresh MFA,
scoped, re-checked per request, no platform/Group authority), the partner
API-client substrate (School-bound, hashed, rotating, revocable; no
production partner route — O15 open), universal `/api/v1` throttling,
exact-origin CORS, enforced CSP and the security-header baseline. Phase 0O
stays PARTIALLY READY (`PHASE-0O-READINESS.md` §16).

**0O.4 — Production Infrastructure, Secrets & Recovery Contract
(2026-09-25).** ADR 0050 resolves O3 (provider-neutral containerized
single-primary hosting, explicit trusted proxies), O4 (external managed
secret store, vendor-neutral), O6 (fixed `school_os_app`), O8 (private,
encrypted, versioned S3-compatible storage) and O10 (PostgreSQL RPO 15 min
/ RTO 4 h, objects RPO 24 h / RTO 8 h, quarterly restore drills). It found
that a Redis loss strands queued work without a reconciliation path and
that releases need a maintenance window; Phase 0O.4A implements the
repository side. Nothing is provisioned.

**0O.4A — Production Infrastructure & Recovery Foundation: COMPLETE
(2026-09-25).** Production application and AI Gateway images (verified
locally, never pushed), a role entrypoint and process manifest with
per-process secret groups, explicit `TRUSTED_PROXIES`, the ADR 0050 §15
guard extensions (plus shared maintenance mode, a PostgreSQL default
connection and environment separation), a production PostgreSQL bootstrap
proven on a throwaway PostgreSQL 16 cluster, PostgreSQL-driven Redis-loss
reconciliation (outbox acknowledgement + reconciler, stale `pending`
webhook/Communication deliveries) proven on real Redis, read-only
database/storage/restore verification commands, and runbooks
(`docs/operations/`). Not deployed; no real secret, bucket or backup
policy; **the real restore drill is still outstanding**. Phase 0O stays
PARTIALLY READY (`PHASE-0O-READINESS.md` §18).

**0O.5 — Observability & Alerting Contract (2026-09-26).** ADR 0051
resolves O12: a vendor-neutral external backend fed by sanitized JSON logs
(30-day retention) and low-cardinality OpenMetrics metrics on a private
scrape port (90-day retention), no tracing in v1, no identifier metric
labels, a SEV-1/2/3 alert catalog with thresholds derived from runtime
cadence and the O10 recovery objectives, and deployment-fed backup and
restore-drill metrics. It amends ADR 0015 and records verified debt for
Phase 0O.5A (duplicate queue-heartbeat listener, unwatched heartbeats and
`notifications` queue, operations status failing during a database
outage, raw exception text, unbounded request ids). Nothing is collected
or activated; O16 must be resolved before any image registry push.

**0O.5A — Observability & Alerting Foundation: COMPLETE (2026-09-26).**
Central JSON logging with one sanitizer and safe exception handling,
validated request ids, a closed low-cardinality metrics catalog on a
private token-protected scrape listener, worker canaries and full scheduler
heartbeats, trigger-maintained durable-work backlog signals (no RLS
bypass), outage-tolerant operations status, the OBS-01…OBS-26 alert catalog
as deterministic conditions plus a provider-neutral rule file, deployment
evidence ingestion for backups and drills, structured Gateway logs, and
runbooks. No backend, retention or alert routing is active; that evidence
remains deploy-gated. Phase 0O stays PARTIALLY READY
(`PHASE-0O-READINESS.md` §20).

**0O.6 — Supply Chain & Artifact Security Contract (2026-09-26).** ADR 0052
resolves O16: build once and promote one immutable OCI digest,
digest-pinned bases, SHA-pinned CI actions, hash-verified locks, final-image
SPDX SBOM, vulnerability gate (Critical blocks; High with a fix blocks;
≤ 30-day exceptions), secret and image-history scanning, SLSA-style
provenance, cosign-compatible signing and a fail-closed verifier before any
promotion. Nothing is signed, pushed or promoted; Phase 0O.6A implements the
repository side.

**0O.6A — Supply Chain & Artifact Security Foundation (COMPLETE,
2026-09-26).** Digest-pinned bases, SHA-pinned actions, lock-only installs
(no Composer plugins/scripts, npm `ignore-scripts`, a hash-verified
wheels-only Python lock), `infrastructure/release/qualify` (same-run
regression, audits, OCI-archive builds, SBOM, fresh-database scan, image
secret/history scans, provenance, ephemeral NON-PRODUCTION signing) and the
fail-closed `verify-artifact`; release-qualification and SBOM re-scan
workflows; release and incident runbooks. Both images currently FAIL the
vulnerability policy (unfixed Debian bookworm Criticals, OpenSSL/PCRE2 fixes
in newer bases, Starlette 0.47.3) — remediation is an owner decision. NO
PRODUCTION REGISTRY OR SIGNING IDENTITY IS CONFIGURED; NO IMAGE HAS BEEN
PUSHED OR PROMOTED.

**0O.6B — Release Vulnerability Remediation (BLOCKED — RELEASE VULNERABILITY,
2026-09-26).** Debian 13 bases for both images, the PHP image's build
toolchain purged from the application runtime, FastAPI 0.133.0 / Starlette
1.3.1 (security-only). Application 40 C / 112 H → 9 C / 65 H; Gateway 10 C /
75 H → 0 C / 50 H. Blocked by genuine libcurl/libxml2 CRITICALs linked by
the official PHP binary (no Debian fix) and, for the Gateway, a CPython 3.12
HIGH fixed only in 3.14 plus unapproved HIGH-without-fix exceptions. Nothing
VERIFIED, pushed or promoted.

**0O.6C — Patched Runtime Libraries & Python 3.14 (BLOCKED — RELEASE
VULNERABILITY, 2026-09-26).** Gateway on CPython 3.14.7 (pydantic 2.12.0,
the only forced change): 0 CRITICAL, 0 HIGH with a fix; residual Debian
HIGH-without-fix set in an inactive decision pack. Application stopped before
an ABI-breaking change: libxml2's HIGH fixes exist only in 2.15.4
(`libxml2.so.16`), which the official PHP binary cannot load — owner decision
(recommended: rebuild PHP 8.3.35 against curl 8.22.0 + libxml2 2.15.4).

**0O.6D — Custom PHP Runtime & ABI Remediation (TECHNICAL REMEDIATION
COMPLETE — AWAITING HIGH VULNERABILITY EXCEPTION DECISION, 2026-09-26).**
Repository-built PHP 8.3.35 against curl 8.22.0 and libxml2 2.15.4 (verified
sources, no PHP patch, extension parity, native smoke in the image);
Lycenza maintains the runtime. Application 0 CRITICAL / 0 HIGH with a fix;
Gateway unchanged at the same state; the remaining Debian Essential-package
HIGH-without-fix advisories await per-advisory approval. Nothing VERIFIED,
pushed or promoted.

**0O.6F — Runtime Hardening, Approved Exceptions & Artifact Requalification
(2026-09-26).** Owner decision `OWNER-0O6E-2026-09-26` accepts the 12
residual Debian 13 advisories exactly, per package (97 records, expiring
2026-10-10 for util-linux #1/#3/#4 and 2026-10-26 for the others). Five are
conditional on the new provider-neutral runtime security contract:
- never privileged, ALL capabilities dropped, no-new-privileges, non-root;
- `mount`/`umount` setuid removed;
- proven from `/proc` in `verify-images.sh`.

Tooling:
- approval linkage;
- conditional activation only with the artifact's hardening evidence;
- a fix becoming available blocks again.

The requalification digests are in the 0O.6F remediation record. O16
repository controls are complete; deployment evidence is outstanding.
PUBLISHED = NONE, PROMOTED = NONE.

**0O.7 — Service-to-Service Authentication & Rotation Contract (2026-09-27,
documentation only).** ADR 0053 resolves O5.
- **Scope:** both internal directions (Laravel → Gateway, Gateway →
  Laravel) move from one shared symmetric token to per-request,
  request-bound Ed25519 service assertions (`Authorization:
  Lycenza-Service`), with one keypair per calling service.
- **Receivers:** audience-exact, with a closed route scope. Laravel
  consumes each `jti` once; the Gateway's bounded replay window is a
  recorded residual risk.
- **Rotation:** rings with at most 24 h of overlap, 90-day keys, and
  emergency revocation by key removal.
- **Separation:** the service assertion is strictly separate from the AI
  context token and never sets School context.

**0O.7A — Service-to-Service Authentication & Rotation Foundation
(COMPLETE, 2026-09-27).** ADR 0053 is implemented in both services.
- **Assertions:** per-request, request-bound Ed25519 assertions, with one
  keypair per calling service.
- **Shared token removed:** a closed route and scope map, with the service
  scopes out of the human capability catalog (database CHECK).
- **Replay:** Laravel's jti is consumed once in Redis; the Gateway's
  cross-replica replay is a recorded residual.
- **Key lifecycle:** rotation, rollback and revocation are tested, with
  90-day keys and OBS-27.
- **Hardening and tooling:** a `ResolveSchoolContext` leak on service
  routes was fixed, and operator tooling plus a runbook were added.
- **Proof:** `verify-images.sh` has 111 checks, including both directions
  across real containers.

O5 repository portion complete; deployment evidence outstanding. Remaining
open: O1, O2, O9, O13, O14, O15.

**0O.8 — Custom School Domains & TLS Contract (2026-09-27, documentation
only).** ADR 0054 resolves O9.
- **Surface:** custom domains are a browser School surface only; `/api/v1`
  stays on the platform host.
- **Ownership:** a persistent DNS TXT proof (frozen syntax, 24 h
  challenge) with an explicit database-enforced lifecycle; pending claims
  reserve the hostname, and there is one primary with redirecting aliases.
- **Routing and TLS:** the deployment edge owns TLS; the application
  proves routing and TLS with an IP-pinned probe before `active`.
- **Drift:** daily drift checks with confirmation rules.
- **Requests:** exact host classification (421 otherwise); host-decides,
  membership-still-required tenancy; canonical-origin URL generation that
  closes Host-header poisoning.

Remaining open: O1, O2, O13, O14, O15.

**0O.8A — Custom School Domains & TLS Foundation (2026-09-27, COMPLETE —
repository).** ADR 0054 implemented (implementation amendment in the ADR).
- **Host boundary:** exact classification right after trusted proxies;
  unknown and non-active Hosts answer one fixed 421 before any session;
  aliases 308 to the stored primary; the School host serves a closed browser
  surface (platform, Group, API, internal, health, storage: 404).
- **Domains:** normalized ASCII-only hostnames, the pinned Public Suffix List
  (`jeremykendall/php-domain-parser` 6.4.0), reserved hosts; one claiming row
  per hostname and at most 3 per School (database-enforced, raced with real
  processes); the database-enforced lifecycle and primary invariant
  (deferred constraint trigger under a per-School advisory lock).
- **Proof:** persistent DNS TXT ownership (`mikepultz/netdns2` 2.0.8,
  bounded), separate routing validation, an IP-pinned TLS probe with a
  per-deployment HMAC; drift suspension and automatic recovery.
- **Sessions:** host-only cookies kept; a one-time, 60 s, server-side
  cross-host sign-in handoff for School switches; canonical-origin URLs (the
  invitation Host-poisoning finding fixed).
- **Operations:** `school.domains.view`/`.manage` (fresh MFA), queued
  rate-limited checks, operator commands, OBS-28–30, the CUSTOM-DOMAINS
  runbook, production guards; disabled by default.

O9 repository implementation complete; deployment evidence outstanding.
Remaining open: O1, O2, O13, O14, O15. The next step is chosen by inspecting
the repository among O13 (production email), O14 (account recovery) and
O2/O15 (external/payment integrations) — not started.

**0O.9 — Production Email & Deliverability Contract (2026-09-27,
documentation only).** ADR 0055 resolves O13.
- **Identity:** mail is sent only from a Lycenza-controlled,
  deployment-configured sending domain with a closed From mailbox catalog
  and a sanitized School display name, and no School Reply-To in v1. A
  School web domain (ADR 0054) authorizes nothing about email.
- **Provider:** one provider-neutral adapter at a time; no vendor chosen.
- **Durable email layer:** messages, attempts, events and suppression
  beneath both senders. The invitation leaves its business transaction
  (outbox), and Communications projects delivered/bounced state.
- **Deliverability:** authenticated, deduplicated provider events;
  monotonic states; global suppression; SPF, aligned DKIM and DMARC
  staged to at least `p=quarantine` before readiness.
- **Operations:** per-School budgets, tracking off, and a production
  guard.

Remaining open: O1, O2, O14, O15. Next: **0O.9A — Production Email &
Deliverability Foundation** (repository only).

**0O.9A — Production Email & Deliverability Foundation (2026-09-27, COMPLETE —
repository).** ADR 0055 implemented (implementation amendment in the ADR).
- **Outbox:** invitations queue their email in their own transaction and
  submit it after commit; Communications hands off (`accepted`), with state
  projected back.
- **Durable layer:** messages, attempts, events and suppression, with a
  trigger-enforced state graph and content purge.
- **Adapters:** the fake and a hardened SMTP adapter; `MAIL_PROVIDER=none`
  is an explicit disabled mode.
- **Events:** a bounded, authenticated, deduplicated webhook (fake adapter
  only until a vendor exists).
- **Suppression:** a global suppression list with an HMAC key ring.
- **Fairness and throttles:** budgets with a reserved critical slot; the
  invitation throttle.
- **Operations:** the production guard, OBS-31..38 and operator commands.

Repository implementation complete; deployment evidence outstanding.
Remaining open: O1, O2, O14, O15. Recommended next: **0O.10 — Account
Recovery Contract (O14)**.

**0O.10 — Account Recovery Contract (2026-09-28, documentation only).**
ADR 0056 resolves O14.
- **Scope:** identity-level self-service password recovery on the
  canonical platform host only, for active, non-root human Users. Root
  recovery stays on the operator console.
- **Request:** enumeration-resistant generic response with asynchronous,
  encrypted-job issuance and keyed rate limits.
- **Credential:** a 256-bit selector+secret (secret in the URL fragment,
  SHA-256 stored), 30 minutes, single use, at most 3 active, never
  consumed by GET.
- **Reset:** a transaction that bumps a per-User `credential_version`
  (every session on every host ends), revokes human personal access
  tokens and elevations, and preserves MFA, with no auto-login.
- **Delivery:** ADR 0055 critical email only.

Remaining open: O1, O2, O15. Next: **0O.10A — Account Recovery Foundation**
(repository only) — not started. O2 and O15 are best reviewed together
afterwards.

**0O.10A — Account Recovery Foundation (2026-09-28, COMPLETE — repository).**
ADR 0056 implemented (implementation amendment §24; runbook
`docs/operations/ACCOUNT-RECOVERY.md`; CLAUDE.md rule 90).
- **Identity:** canonical, database-checked emails; case-insensitive login;
  failed logins audit a keyed fingerprint only.
- **Recovery:** platform-host-only, generic responses, keyed limits,
  encrypted asynchronous issuance, fragment secrets, a POST-only reset.
- **Revocation:** `users.credential_version` with database triggers, stamped
  at every sign-in path and enforced on every host; one password writer
  (`CredentialChangeService`); MFA preserved.
- **Operations:** the operator console reset (root's only path), status and
  prune commands, identity-level email, OBS-39..41, a production guard.
- **Legacy:** the stock Laravel reset broker was removed.

Repository implementation complete; deployment evidence outstanding
(`ACCOUNT_RECOVERY_ENABLED=false` until then). Recorded debt: a signed-in
password change, self-service lost-MFA recovery, and production staff
account provisioning. Remaining open: O1, O2, O15. Recommended next: a fresh
repository audit of O2 and O15 together, proposing the next contract
checkpoint.

**0O.11 — Broader Third-Party Integrations & Payment Gateway Scope
Contract (2026-09-28, documentation only).** ADR 0057 resolves O2 and O15
after a read-only audit.
- **O2:** the first real payment gateway is **deferred** from Phase 0 /
  production v1. No processor, checkout, callback, credential, refund or
  PCI-bearing UI. A future gateway needs its own ADR (provider, merchant
  scope, jurisdiction, PCI, reconciliation…).
- **Manual/offline payment recording** is a **required v1 Finance
  correction**. Staff record a payment that already happened outside
  Lycenza, through the existing immutable settlement model. It is not a
  provider integration.
- **O15:** email is in v1 (O13). SMS, WhatsApp, push, government/board
  systems, Tally/accounting and other school software are **deferred**; SSO
  is not in v1; LMS interoperability stays cancelled. **No production
  partner API scope** is approved.
- **Documentation drift corrected:** Phase 0G premise, FINANCE.md 0G.8,
  PRODUCTION-RELEASE §7, inbound-webhook statements, rule 34 wording, and
  the webhook test event.

Remaining open: **O1** only. Next: **0O.11A — Manual / Offline Payment
Recording Foundation** — not started.

**0O.11A — Manual / Offline Payment Recording Foundation (2026-09-28,
COMPLETE — repository).** An authorized School Finance user
(`finance.payments.record`, School Admin by default) records cash, bank
transfer or cheque money the School has **already received**. Lycenza moves
no money.
- **Shared core.** `SettledPaymentRecorder` serves both the verified
  provider ingress (unchanged) and the new `ManualPaymentRecordingService`.
  Manual Payments carry their own provenance (`source`, method, reference,
  recorded-by, idempotency key) and never manufacture a provider event.
- **Invariants and flow.** The same immutable allocation and ledger
  invariants apply: several Charges per payment, an active asset account,
  INR only. The flow ends in a pre-post confirmation, and a suspended School
  is refused.
- **Owner decisions:** posted manual Payments have **no correction action in
  v1** (the correction contract is an open Finance follow-up). Payment-owned
  journal entries can no longer be reversed through the generic ledger
  action.
- **Records.** ADR 0031 implementation amendment; CLAUDE.md rule 91; runbook
  `docs/operations/MANUAL-PAYMENT-RECORDING.md`; PHASE-0O-READINESS §39.

O2 stays **RESOLVED — real payment gateway deferred**; O15 stays
**RESOLVED**. Remaining open: **O1** only. Next: a fresh **read-only O1
production-readiness closeout audit** against the repository and the
deployment-evidence register — not started. Phase 0O is not complete merely
because repository implementation is finished.

**0O.12 — Production Readiness Definition & Closeout Contract (2026-09-28,
docs-only, ADR 0058).** O1 is **RESOLVED AS DEFINITION OF DONE — NOT
SATISFIED**.
- **Meaning.** Phase 0O complete means the repository and readiness evidence
  are complete enough to authorize a **separate** production deployment
  decision. It never means go-live, real School data or Phase 0M.
- **Register.** One evidence register (E01–E29) supersedes
  `PHASE-0O-READINESS.md` §9.
- **Findings:**
  - `main` is **not protected** on GitHub; protection plus a fresh
    qualification are required before the first promotion;
  - a fresh production install **cannot create its first School**; no path
    provisions staff or School-admin logins (now an O1 blocker);
  - email has no vendor event adapter yet (a mandatory provider tail).
- **Owner decisions:**
  - legal retention for v1 categories is required **before** closeout
    (option A);
  - the AI Gateway and production custom domains are **not** required;
  - one non-production custom-domain exercise **is** required, for O14;
  - email is mandatory;
  - manual-payment correction stays debt.
- **Mandatory evidence outstanding:**
  - protected `main`;
  - the ADR 0050 environment and a **real restore drill**;
  - the observability backend and routing;
  - registry, signing and promotion;
  - email provider, DNS authentication and drills;
  - O14 drills;
  - legal retention;
  - staff/School-admin provisioning.

Phase 0O: **CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
PROVISIONING EVIDENCE OUTSTANDING**. Recommended next: **0O.12A —
Staff / School-Admin Account Provisioning** (contract first) — not started.

**0O.12A — Staff / School-Admin Account Provisioning Contract (2026-09-28,
docs-only, ADR 0059).** This decides ADR 0058 row E24.
- **Flow A:** an interactive operator console command creates a
  credential-less bootstrap User and displays a one-time activation link
  once, with no email. Root still grants School authority through the
  ADR 0047 path, and activation now needs an activated administrator.
- **Flow B:** an active School invites staff. Issuing needs
  `school.members.manage` + `school.roles.manage`, fresh MFA and critical
  email. Roles come from the closed School catalog, within the issuer's
  own capabilities. Invitations are School-owned and bound to one email.
  Acceptance creates an active membership and the roles; an existing User
  must be signed in. The flow never reveals anything about other Schools.
- **Unchanged:** User ≠ Employee; no elevation, Group or platform staff
  management after activation.
- **New O1 finding:** staff off-boarding is **DECISION REQUIRED**.

Next: **0O.12B — Staff / School-Admin Account Provisioning Foundation**
(one checkpoint) — not started. E24 stays blocking until then.

**0O.12B — Staff / School-Admin Account Provisioning Foundation (2026-09-28,
executable).** ADR 0059 is implemented, and off-boarding is added by owner
decision. E24 now covers the full staff-account lifecycle.
- **Onboarding:** a console-provisioned, credential-less bootstrap
  administrator with a one-time activation link; School activation needs an
  activated administrator; School staff invitations use fresh MFA and
  critical email; acceptance handles new or existing (signed-in) Users.
- **Off-boarding:** suspension revokes every School role grant; explicit
  reactivation grants newly chosen roles; role grants keep history; a
  concurrency-safe last-administrator invariant; no self-administration.
- **Proof:** a fresh-install scenario through the real console, plus real
  PostgreSQL races. E24 becomes REPOSITORY_COMPLETE with this unit's full
  regression and O16 qualification.

**0O.12B — COMPLETE (qualified 2026-09-29).** O16 run
`local-20260929T002558Z-08871c71` of `main` `e52c4c4`:
- the same-run regression passed: 6,500 tests, 0 failures, only the ESI-12
  skip;
- `verify-images.sh` passed 133/133;
- 0 audit advisories and 0 secret findings;
- the application (`sha256:d03a3e4d…159729`) and AI Gateway
  (`sha256:db5ae74d…24e502`) images are **VERIFIED**;
- PUBLISHED = NONE, PROMOTED = NONE.

ADR 0058 row **E24 is REPOSITORY_COMPLETE** (`PHASE-0O-READINESS.md` §44).
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **Phase 0O:** CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE /
  PROVIDER EVIDENCE OUTSTANDING.
- **Phase 0M:** BLOCKED.
- **Full-regression checkpoint:** `e52c4c4` (counter 0/5).

Next: a fresh O1 evidence-register review (which blockers can proceed in
parallel, and the email provider tail) — not started.

**O1 evidence-register review (2026-09-29, read-only).**
- **The only mandatory code tail left is E18,** the email provider
  adapter.
- **Can start now:** E03 (protect `main`), E16 (the exception clock) and
  E17/E21 (decisions).
- **E21.2 — Retention policy adopted for implementation (2026-10-01):**
  `docs/security/E21-RETENTION-DETERMINATION.md`. It is project-adopted and
  pending final legal/compliance ratification. Engineering is E21.2A
  (operational pruning), then E21.2B–F, then the E21.2G audit. **E21 stays
  OPEN** and O1 stays blocked.
- **E21.2A — Operational retention implemented (2026-10-01):**
  - mail L1–L4 fixed and 180 d;
  - webhooks delivered 30 / failed 90;
  - new outbox and failed-job prunes, 30 d each;
  - the School hold seam.

  Released-suppression expiry moved to E21.2B.
- **E21.2B — Audit, authority-history and released-suppression retention
  (2026-10-01): closed.**
  - narrow `SECURITY DEFINER` retention functions (fixed predicates, a
    database age floor, a tenant tie; no runtime DELETE);
  - `platform:audit-prune` (7 y), `platform:email-suppressions-prune` (1 y)
    and `platform:authority-history-prune` (7 y: grants, TeachingAssignments,
    elevations);
  - `RETENTION_HOLD_PLATFORM`;
  - LMS owner/audience kept with its parent.

  E21.2A and E21.2B are closed.
- **E21.2C — Communications & Documents retention (2026-10-01): closed.**
  - `platform:communications-prune`: content 3 y after the end of its
    sending Academic Year (gap/overlap fails closed), delivery telemetry
    1 y after the terminal state; policy decisions through one more narrow
    retention function;
  - `platform:storage-orphans-prune`: proven orphans in the managed
    keyspace after 30 d;
  - Documents: inherited retention, every owner deferred (no age purge).

  E21.2A–E21.2C are closed.
- **E21.2D — Student / Academic retention (2026-10-01): closed.**
  - `platform:student-retention-prune`, from a dated final exit (`inactive`
    plus the last `completed`/`withdrawn` placement; anything ambiguous is
    kept; re-entry restarts the clock);
  - operational (7 y): attendance records, rollover items, Guardian
    relationships;
  - core (25 y): identity, placements, subject enrollments and Student
    Documents, only when no other retained row references the Student
    (FK-catalog check, never a cascade);
  - School academic content, attendance sessions, Guardian personal data,
    processing authorizations and Admissions are recorded for E21.2G.

  E21.2A–E21.2D are closed.
- **E21.2E — Finance & HR retention (2026-10-01): closed.**
  - `platform:employee-retention-prune`, from final separation (terminal
    EmploymentRecords; rehire restarts the clock):
    - ancillary sub-records: 2 y;
    - Payroll's compensation and statutory rows, then the Employee with its
      employment evidence and Documents: 8 y, only when no other retained
      row references it;
  - **D8 Finance: audited, expiry blocked.** Every balance is derived from
    all postings, and no financial-year close or carried-forward balance
    exists. Nothing financial is deleted (`FinanceRetentionGuardTest`). The
    design prerequisite is recorded for E21.2G.

  E21.2A–E21.2E are closed.
- **E21.2F — Erasure & tenant closure orchestration (2026-10-01): closed.**
  - **Reviewed erasure cases** (`erasure_cases`, operator console):
    execution removes only what D7 and D9 already released. Holds, Finance
    and retained dependencies win. Guardian and User erasure stay
    `policy_unresolved`. The 30-day target is visibility only.
  - **School Close/Reopen** (ADR 0047 amendment): a freeze through
    `suspended` with a durable closure record. Nothing is deleted.
  - **Read-only closure readiness** (`platform:school-closure-status`):
    fails closed on unclassified tables and is never purge-ready.
  - **Tenant destruction is NOT AUTHORIZED.**

  E21.2A–E21.2F are closed.
- **E21-RH — retention privilege hardening (ADR 0066): a pre-production
  security blocker -- CLOSED by RH.7 (2026-10-05; see the RH.7 completion
  report).**
  - RH.1–RH.3 published (dedicated `school_os_retention` identity; HRX on
    it; authoritative database holds).
  - **RH.4 (2026-10-05, ADR 0066 §12):** the eleven standalone legacy
    functions run only as the retention identity, with the platform and
    School holds enforced in the database. Published.
  - **RH.5 (2026-10-05, ADR 0066 §13):** the Payroll and LMS units run whole
    on the retention identity (Documents through one database unit), with
    database holds. Published.
  - **RH.6 (2026-10-05, ADR 0066 §14):**
    - no retention function is runtime-executable (the Finance unit, Student
      and Guardian core evidence moved);
    - every PHP retention unit, including erasure and the email, outbox,
      webhook-delivery and failed-job prunes, runs whole as the retention
      identity, and PostgreSQL refuses its deletes under a hold;
    - runtime DELETE is revoked where only retention used it (LMS included);
    - the separation, exit, `erasure_cases` and lifecycle-marker sources are
      database-guarded, and D7/D9 count from the recorded end date.

    Published.
  - **RH.7 (2026-10-05, ADR 0066 §15):**
    - retention counts only from times PostgreSQL recorded: an anchor on
      every retention-relevant table, re-recorded on any clock, status or
      link change; the database refuses deleting a row recorded within the
      unit's declared period;
    - the RH.6 eligibility guards and the RH.7 anchors are fenced against
      rollback.

    Published.
- **E21.4 — User Identity Minimization & Database Safety (2026-10-03):
  published / closed.** `docs/security/E21-RETENTION-DETERMINATION.md`
  §5.11, E21-L1 §10:
  - F1 fixed: the runtime role cannot delete a User;
  - non-login, immutable tombstone (`users.minimized_at`) that can never
    become a current principal again (database-enforced);
  - approved platform erasure cases only, blocked by any current purpose or
    hold in any School; never scheduled; no physical deletion;
  - all 87 User references classified, with a live-schema guard.

  **E21 ENGINEERING: COMPLETE. E21 PROJECT POLICY: India-aligned,
  project-adopted for development. Qualified legal/compliance
  ratification: pending pre-production review.** Production retention
  configuration pending deployment. Tenant destruction NOT AUTHORIZED. E33
  / TCH-L1 open independently.
- **E21.3F — Payroll Evidence Retention & Employee Release (2026-10-02):
  published / closed.** `docs/security/E21-RETENTION-DETERMINATION.md`
  §5.10, ADR 0064 §25–§30:
  - posted payroll evidence (results with lines and statutory results,
    adjustments, LWF charges) 8 y after final separation, once every run
    and posting is that old (`payroll-retention-prune`, two narrow,
    floored functions);
  - emptied runs release their journal entries to D8; Finance alone
    deletes them, once their period is 8 y closed (D8 × D9 matrix proven);
  - paid Employees released by the next `employee-retention-prune`;
  - payslips 404, runs flag `resultsExpiredAt`, statutory exports refuse
    (409) after expiry.

  **E21 ENGINEERING: COMPLETE** (no retention-mechanism checkpoint left).
  **E21 — OPEN / LEGAL DECISION REMAINS:** User-identity erasure (I5) needs
  a legal decision; final ratification deferred to the pre-production
  closeout; production retention configuration separate. Tenant
  destruction NOT AUTHORIZED. E33 / TCH-L1 open independently.
- **E21.3E — Communications & Platform Residuals (2026-10-02): published /
  closed.** `docs/security/E21-RETENTION-DETERMINATION.md` §5.9:
  - never-sent cancelled/rejected announcements and empty threads, 1 y
    (`communications-prune --only=residual`);
  - ended driver assignments 7 y, checked-out visits (visitor with its last
    visit) 1 y, completed automation executions 1 y
    (`operations-retention-prune`);
  - ended API credentials, D6 7 y after LEAST(revoked_at, expires_at)
    (narrow function, `authority-history-prune`);
  - memberships, membership preferences, Inventory tenant lifetime;
    notifications not applicable (re-verified); Canteen follows D8.

  **E21 — OPEN.** Remaining engineering: **E21.3F — Payroll Evidence
  Retention & Employee Release** (the only mechanism checkpoint left).
  User-identity erasure: legal decision pending. Final ratification
  deferred to the pre-production closeout. Tenant destruction NOT
  AUTHORIZED. E33 / TCH-L1 open independently.
- **E21.3D — Year-Bound Academic Operations (2026-10-02): published /
  closed.** `docs/security/E21-RETENTION-DETERMINATION.md` §5.8:
  - `platform:academic-retention-prune`: curriculum deliveries, empty
    attendance register headers, unreferenced timetable entries and LMS
    Learning Content/Assignments (audiences, Documents, D6 owner/audience
    minimum) 7 y after the end of their authoritative Academic Year;
  - Academic Year dates frozen; one-year-per-LMS-resource invariant pinned;
  - syllabus and examination configuration tenant lifetime;
  - teaching Employees released by those rows' own expiry.

  **E21 — OPEN.** Remaining engineering: **E21.3E** (communications &
  platform residuals) and **E21.3F — Payroll Evidence Retention & Employee
  Release** (provisional). Final ratification deferred to the
  pre-production closeout. Tenant destruction NOT AUTHORIZED. E33 / TCH-L1
  open independently.
- **E21.3C — Admissions & Guardian Lifecycle Markers (2026-10-02):
  published / closed.** `docs/security/E21-RETENTION-DETERMINATION.md`
  §5.7:
  - database-owned, immutable `admission_applications.terminal_at`;
    `platform:admissions-retention-prune` (rejected/withdrawn 1 y after it;
    applicant once nothing else remains);
  - durable `guardians.no_relationship_since` maintained by a relationship
    trigger (re-link clears, next final unlink restarts);
    `platform:guardian-retention-prune` (Guardian personal data 1 y after
    it, unless a retained dependent remains);
  - `platform:lifecycle-markers-backfill` from audit evidence only; undated
    rows unresolved and kept (readiness `retention_trigger_unresolved`);
  - Guardian erasure cases follow G1; Guardian Documents follow their
    Guardian.

  **E21 — OPEN.** Remaining engineering: **E21.3D** (year-bound academic
  operations), **E21.3E** (communications & platform residuals) and
  **E21.3F — Payroll Evidence Retention & Employee Release** (provisional).
  Final ratification deferred to the pre-production closeout. Tenant
  destruction NOT AUTHORIZED. E33 / TCH-L1 open independently.
- **E21.3B — Student-Linked Evidence & Operational Modules (2026-10-02):
  published / closed.** `docs/security/E21-RETENTION-DETERMINATION.md`
  §5.6:
  - D7 operational (7 y after final exit): returned Library loans, ended
    Transport assignments, ended Hostel residencies; an open one keeps the
    Student;
  - with the Student core record (25 y), in its unit: processing
    authorizations and the Student subject's consent events (two narrow,
    core-floored database functions), converted admission applications
    with applicants that have nothing else, Student-subject domain
    preferences, Guardian relationships an authorization names;
  - `platform:portal-invitations-prune`: ended portal invitations 7 days
    after their canonical end;
  - one composition (`StudentRetention`) for the scheduled run and erasure;
    readiness keeps the E21.3C rows `mechanism_pending`.

  **E21 — OPEN.** Remaining engineering: **E21.3C** (Admissions & Guardian
  lifecycle markers), **E21.3D** (year-bound academic operations),
  **E21.3E** (communications & platform residuals) and **E21.3F — Payroll
  Evidence Retention & Employee Release** (provisional; the recorded
  payroll D8 × D9 intersection). Final ratification is deferred to the
  pre-production closeout. Tenant destruction NOT AUTHORIZED. E33 / TCH-L1
  open independently.
- **E21.3A2 — Finance Retention Cutover & Historical Expiry (2026-10-02):
  published / closed.** ADR 0064 §14–§24 and
  `docs/operations/FINANCE-RETENTION.md`:
  - carry-forward reads (`LedgerBalanceReader`, `ChargeStateReader`);
  - D8 eligibility from `closed_at` + 8 calendar years;
  - settled, dependency-safe unit expiry through one DB-floored function;
  - `platform:finance-retention-prune` (off by default), holds, dry run,
    per-unit accounting proof;
  - expired records are ordinary 404s; the statement shows detail expiry;
  - metric labels consolidated into families.

  **D8 — IMPLEMENTED.** Recorded residual: payroll-linked detail stays while
  D9 payroll evidence references it (no Payroll D9 mechanism). **E21 —
  OPEN:** E21.3B–E21.3E and final ratification remain.
- **E21.3A — Financial Year Close & Retention Foundation (2026-10-02):
  published / closed.** ADR 0064 adds:
  - `financial_periods`, with a period on every journal entry (DB trigger,
    `FOR SHARE`);
  - an irreversible, audited, MFA-gated close (`finance.periods.manage`)
    with one lock order;
  - immutable account and charge baselines;
  - dual-read verification;
  - a fail-closed backfill and the operator commands;
  - Finance → Financial periods.

  **D8: FOUNDATION READY — RETENTION CUTOVER STILL REQUIRED.** No Finance
  evidence is deleted. Next: **E21.3A2 — Finance Retention Cutover &
  Historical Expiry**, then E21.3B–E21.3E. **E21 — OPEN.**
- **E21.2G — Final retention closure audit (2026-10-01): closed.**
  `docs/security/E21-CLOSURE-AUDIT.md`:
  - D0–D13 matrix;
  - settings, command, hold, privilege and concurrency audits;
  - a project decision for every remaining category (User-identity
    erasure stays a legal decision);
  - technical-TTL dry runs;
  - readiness `mechanism_pending`;
  - three stale docs corrected.

  **E21 — OPEN / TECHNICAL BLOCKERS REMAIN.**
  - D8 was BLOCKED; **E21.3A** (above) has since built the foundation.
  - Then the mechanism checkpoints E21.3B–E21.3E.
  - Final ratification is deferred to the pre-production closeout.
- **E21.1 — Production retention policy audit (2026-10-01, docs only):**
  published / closed. It inventoried every v1 category and recorded 14 open
  decisions with neutral options, a determination template and no immediate
  retention-safety defect. **E21 remains OPEN — owner/legal/compliance
  decisions required.** It also needs an email-prune code tail (L1–L3)
  before `MAIL_RETENTION_DAYS` is set. *(2026-10-08: L1–L3 fixed in
  E21.2A.)*

**0O.13 — Transactional Email Provider Selection & Integration Contract
(2026-09-29, docs-only, ADR 0060).**
- **Selected: Twilio SendGrid.**
  - HTTPS Mail Send API; ECDSA-signed Event Webhook; `sg_event_id`
    deduplication.
  - Provider-hosted 2048-bit DKIM with a custom return path; tracking off.
- **Rejected: Postmark** (1024-bit DKIM, unsigned webhooks). **Fallback:**
  SES.
- **E17 is LEGAL_REVIEW_REQUIRED:** the processor/legal review is
  outstanding.
- **ADR 0058 E02 corrected:** it now requires E03, E18, every other
  mandatory executable change, and E16.
- **Drift fixed:** in several runbooks, plus a new operator evidence
  template.

Next: the legal/processor review of SendGrid. Then **0O.13A — SendGrid
Transactional Email Adapter** (E18), which is not started. In parallel,
outside the repository: E03, the E16 plan, and the E19 DKIM clock once the
owner authorizes the account.

**E16 fresh-scan audit (2026-09-29, read-only).**
- **Scan:** both images still PASS with 0 blocking findings. The 12
  excepted advisories are unchanged.
- **No fix:** Debian trixie and trixie-security have none (`no-dsa` /
  postponed / unfixed), and no newer base digest exists. So no refresh can
  remove them.
- **Whole-file expiry.** An expired record invalidates the whole exception
  file, so all 97 records stop working on 2026-10-10 (the last passing day
  is 2026-10-09). A fresh owner/security decision is required first.
- **E16:** DECISION_REQUIRED.

**E16 exception replacement (2026-09-29).** Owner/security approved
`OWNER-0O-E16-2026-09-29`: a new decision after the fresh scan, with the
same 12 advisories and 97 records, all expiring **2026-10-29** (last passing
day 2026-10-28). It replaces the `OWNER-0O6E-2026-09-26` records. E16 stays
DECISION_REQUIRED for the final E02/E15 dates. The O16 qualification of the
merged commit is in `PHASE-0O-READINESS.md` §47 and after.
- **Qualified:** `main` `be69b55`, both images VERIFIED (0 blocking; 48 and
  49 excepted). Regression 6,500 tests with only the ESI-12 skip.
- **Full-regression checkpoint:** `be69b55` (counter 0/5, readiness §48).

**Phase Zero scope closure (2026-09-29, docs-only, ADR 0061).** Phase 0H
and Phase 0M are closed for Phase Zero, with their remaining scope deferred
post-v1. **Phase 0O is the only active Phase Zero closeout area.** See
"Phase Zero closeout status" at the top of this roadmap.

## Cross-cutting, ongoing (not a single phase)

- Data classification and authorization reviews (root `CLAUDE.md`) on
  every module that touches Sensitive/Highly Sensitive data.
- Security regression tests added alongside every fix
  (`docs/architecture/ARCHITECTURE.md` §11).
- ADRs added/updated whenever a phase makes a decision this roadmap
  didn't anticipate.

## Explicit stop gates that apply to every phase

No phase — including Phase 0C onward — deploys to production, provisions
cloud resources, purchases services, configures production secrets,
sends external communications, or connects real school data without
separate, explicit authorization at that time. Each phase's own kickoff
should restate this, not assume it carries over silently.
