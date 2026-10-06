# ADR 0061: Phase Zero Post-v1 Scope Deferral Contract — Academic Depth and Real AI

- Status: Accepted (owner product-scope decision; documentation only)
- Date: 2026-09-29
- Baseline: `origin/main` `b305b26` (executable checkpoint `e52c4c4`)
- Decides: the Phase Zero closure treatment of **Phase 0H** (Academic
  Operations) and **Phase 0M** (AI Platform: Real Agents).
- Amends, by note (no rewrite): `MASTER-ROADMAP.md` (the 0H and 0M status
  paragraphs), `docs/modules/ACADEMICS.md` §19,
  `docs/modules/EXAMINATIONS.md` §20,
  `docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` (dated addendum),
  ADR 0058 and ADR 0060 (the current Phase 0M status).
- Related:
  - 0H: ADR 0032, 0033, 0035, 0037, 0038;
    `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`.
  - 0M: ADR 0013, 0014, 0017, 0023, 0053; `docs/ai/AI-PLATFORM.md`;
    `docs/ai/AI-SECURITY.md`.
  - Closeout: ADR 0058, ADR 0060.

## 1. Owner decisions (2026-09-29)

The project owner explicitly approved:
1. **Phase 0H's remaining scope is DEFERRED TO POST-v1.**
2. **Phase 0M's real providers and real agents are DEFERRED TO POST-v1.**

These are **product-scope deferrals**. They are **not**:
- implementation completion;
- legal clearance;
- cancellation of the underlying future capability;
- production authorization;
- permission to remove any existing safety gate;
- permission to delete historical architecture or legal records.

## 2. Phase 0H — Academic Operations

### 2.1 Status

- **Historical status (unchanged, true for its date):** "Phase 0H as a
  whole is NOT complete" (`MASTER-ROADMAP.md`). "Academics is NOT
  complete" (Lesson Planning deferred). "Examinations has STARTED but is
  NOT complete" (marks, results, report cards and transcripts not
  implemented).
- **New Phase Zero status:** **CLOSED FOR PHASE ZERO — ACTIVE FOUNDATION
  SCOPE DELIVERED; REMAINING ACADEMIC / EXAMINATION DEPTH DEFERRED
  POST-v1.**

Phase 0H is **not** fully implemented. StudentMark, report cards and
Lesson Planning are **not** complete.

### 2.2 Delivered foundation scope (as built; not widened)

| Checkpoint | Record |
|---|---|
| Timetable Foundation (0H.1) | `docs/modules/TIMETABLE.md` |
| Student class Attendance Foundation (0H.2) | `docs/modules/ATTENDANCE.md` |
| Syllabus Foundation (0H.3A) | `docs/modules/ACADEMICS.md` §1–§17 |
| Curriculum Delivery (0H.3B) | `docs/modules/ACADEMICS.md` §18 |
| Examination Foundation (0H.4A) | `docs/modules/EXAMINATIONS.md` §1–§17; ADR 0032 |
| ExaminationPaper / Scheduling (0H.4B) | `docs/modules/EXAMINATIONS.md` §18; ADR 0033 |
| GradeScale / GradeBand mapping (0H.4C) | `docs/modules/EXAMINATIONS.md` §19; ADR 0035 |
| Staff MFA Foundation (0H.4D-P1) | ADR 0037 |
| Student Processing Authorization Registry (0H.4D-P2) | ADR 0038 |

### 2.3 Deferred to post-v1 (repository terminology)

**Academics** (`ACADEMICS.md` §17, §18.15, §19):
- Lesson Planning, including lesson plans and every lesson-level field;
- teacher identity on a delivery, and ownership-based authorization;
- elective delivery (it needs a Section-independent cohort concept).

**Examinations** (`EXAMINATIONS.md` §17, §19, §20):
- Phase 0H.4D-P3 — elective historical eligibility (not yet started);
- StudentMark / marks entry;
- grading beyond the existing GradeScale/GradeBand foundation;
- result calculation;
- result publication or revocation;
- report cards;
- transcripts;
- Student- and Guardian-facing marks and results surfaces;
- promotion decisions and assessment components/weighting;
- any dependent Documents or report-generation work not already built.

Nothing else is inferred.

*Reopening trace (2026-09-30):* the deferred "teacher identity and
ownership-based authorization" item was reopened under §2.5 by TCH.0 /
ADR 0063. The other §2.3 deferred items remain deferred unless separately
reopened.

### 2.4 StudentMark legal determination — preserved, not widened

`docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md` (Lead Privacy
Counsel & Data Protection Officer — India Operations, 2026-09-03):
- **Approves:** the architecture and backend implementation of StudentMark,
  internal staff processing only, with conditions.
- **Does not approve:** production enablement, Student-facing or
  Guardian-facing marks access, calculated-result publication, report cards
  or transcripts.

This deferral does **not** reinterpret it. It is not production approval,
Guardian- or Student-facing approval, report-card or transcript approval, or
unlimited children's-data clearance. It stays **historical input** for the
future post-v1 checkpoint.

**When StudentMark is reopened post-v1, that initiative must:**
1. verify the determination is still current with the approving authority;
2. verify the processing-authorization requirement (ADR 0038);
3. verify the MFA requirements (ADR 0037);
4. complete any platform prerequisite that still applies, including
   0H.4D-P3;
5. obtain every additional determination needed for production,
   Student/Guardian-facing access, results, report cards or transcripts.

**Deferral is not legal clearance.**

### 2.5 Reopening rule

**No deferred 0H item resumes automatically after Phase Zero.** A post-v1
initiative must:
1. perform a fresh read-only scope and readiness audit;
2. identify which old prerequisites still apply;
3. revalidate legal and security decisions that may have become stale;
4. define a new implementation checkpoint;
5. obtain explicit owner authorization.

No future phase is numbered here.

## 3. Phase 0M — AI Platform: Real Agents

### 3.1 Status

- **Historical status (unchanged, true for its date):** "BLOCKED —
  LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS REQUIRED" (gate §21,
  recorded 2026-09-24).
- **New Phase Zero status:** **CLOSED FOR PHASE ZERO — REAL MODEL
  PROVIDERS, REAL AGENTS AND AI WRITE TOOLS DEFERRED POST-v1.**

Phase 0M is **not** complete: its intended real-agent scope was not
implemented. This is an explicit product deferral.

### 3.2 Safe current state (preserved; fail-closed)

- **NullProvider only.** `ModelRouter` registers only `NullProvider`.
- **No provider SDK.** `services/ai/requirements.txt` contains no
  model-provider SDK.
- **No provider credentials** anywhere. Laravel never holds them (ADR
  0013).
- **No production caller.** `App\Support\Ai\AiGatewayClient` has no
  production caller in `app/` or `routes/`, so no School data goes to an
  external provider.
- **Provider switch off.** `REAL_PROVIDERS_ENABLED` defaults to off.
  While approvals are absent, no real provider may register or be
  selected.
- **Not built:** a real agent, a real AI write tool, and an AI
  financial/irreversible-action approval workflow.

**The built hardening stays unchanged:**
- context-token verification (ADR 0023);
- capability verification (ADR 0014);
- durable AI audit, tool and model calls (ADR 0017);
- sanitized errors and logging;
- service authentication (ADR 0053);
- the fail-closed provider switch (gate G1–G4).

### 3.3 Deferred to post-v1

- the first real model provider, including provider-specific credentials
  and runtime enablement;
- the first real agent, and the first tool with an actual business effect;
- any AI write action;
- the human-approval workflow for AI write, financial or irreversible
  actions;
- the Prompt Registry / output validation needed by the first agent;
- real-provider production enablement;
- per-School AI opt-in;
- any prompt or output persistence beyond the current approved audit
  metadata.

### 3.4 The gate stays authoritative

`docs/security/AI-PROVIDER-LEGAL-COMPLIANCE-GATE.md` is **not** passed,
cleared or deleted. It gains a dated addendum, "OWNER PRODUCT DECISION —
POST-v1 DEFERRAL". Its unresolved section 18 decisions become the
**required reopening gate**:
- **18A — Provider / legal:**
  - named provider approval;
  - prompt/output retention;
  - training/service-improvement use;
  - human review by provider staff;
  - processing/storage regions, subprocessors and cross-border transfers;
  - deletion;
  - confidentiality/security terms, breach notification and the DPA;
  - permitted data categories and tiers;
  - whether Highly Sensitive or children's data may be sent at all;
  - AI-artifact retention, if storage is introduced.
- **18B — Product:**
  - the first agent and first tool;
  - the first write scope;
  - the approval workflow;
  - invoker capabilities/roles;
  - per-School opt-in and default;
  - the prompt/output storage need.
- **18C — Security / architecture:**
  - authority-model confirmation;
  - provider-key custody and rotation;
  - Prompt Registry / output validation for the first real agent.

None of these is resolved here.

### 3.5 Reopening rule

A post-v1 AI initiative must begin with:
1. a fresh review of the AI provider legal/compliance gate;
2. confirmation that the G1–G4 hardening still holds;
3. provider/legal approval for the actual named provider, account and
   model (gate §20's form);
4. a product definition of the first agent and tool;
5. security approval;
6. explicit data-tier approval;
7. a provider credential and secret-store design;
8. a separate implementation checkpoint.

**No School data may leave for a real AI provider before that.**

## 4. Effect on Phase Zero

**Not blocking Phase Zero closure** (retained as post-v1 scope):
- Phase 0H Lesson Planning;
- StudentMark, results, report cards and transcripts;
- 0H.4D-P3;
- real AI provider integration;
- real agents;
- AI write tools.

**The only remaining active Phase Zero closeout area is Phase 0O —
External Surface and Production Readiness.**
- **No other Phase Zero product implementation phase is required for
  closure** beyond the accepted Phase 0O work and evidence programme
  (ADR 0058 §6).
- **Not reopened by 0O.** Closing 0O never reopens 0H or 0M.

**Definition.** "Phase Zero complete" means both:
- all active Phase Zero scope is complete or closed under its accepted
  scope decisions, including accepted deferrals;
- Phase 0O/O1 is satisfied.

It does **not** mean every future or deferred roadmap capability is
built.

**Phase Zero: NOT COMPLETE.** Phase 0O/O1 remains unsatisfied.

The authoritative Phase Zero A–O closeout table is kept in
`docs/roadmap/MASTER-ROADMAP.md` ("Phase Zero closeout status").

## 5. What this ADR does not do

- **No deletions.** It removes nothing:
  - the AI Gateway, `NullProvider`, AI service authentication, AI audit,
    the context token and the AI capability boundaries;
  - the Student Processing Authorization Registry and Staff MFA;
  - GradeScale, the Examination foundations and Curriculum Delivery;
  - any future-ready seam.
- **No approvals.** It approves no provider, legal determination,
  production enablement or deployment (rule 16).
- **No Phase 0O change.** E03, E16, E17, E18 and E21 keep their current
  statuses.
- **No code, configuration, migration or test changes.**

## Reopening trace — RES (2026-10-06)

*Dated addendum. §1–§5 above are unchanged and stay true for their date.*

- **§2.5 step 1 is done for RES.** RES.0 (2026-10-06, at `cfc08ad`)
  performed the fresh read-only scope and readiness audit for the deferred
  §2.3 Examinations items.
- **The reopening is partial.** ADR 0068 (Assessment & Results Reopening
  Contract) reopens **only**:
  - 0H.4D-P3, now defined as a Students-owned as-of-date SubjectOffering
    eligibility seam (ADR 0068 §5); and
  - **internal StudentMark processing** — administrative marks entry, a
    per-paper lock and append-only corrections (ADR 0068 §6–§8).
- **Still deferred under §2.3,** each until its own determination and
  contract exist: grading beyond GradeScale/GradeBand; result calculation,
  finalization, publication and revocation; report cards; transcripts;
  assessment components and weighting; Student- and Guardian-facing marks
  and results; promotion decisions; any dependent Documents or report
  generation.
- **Superseded:** the §2.3 Academics item "teacher identity on a delivery,
  and ownership-based authorization" was reopened and built by ADR 0063
  (TCH), as the 2026-09-30 trace records. Its use for marks entry is ADR
  0068 RES.4, which still needs legal item RES-L2.
- **§2.4 still binds.** The StudentMark determination is neither widened
  nor re-decided. Step 1 (re-confirmation with the approving authority) is
  open as **RES-L0** (ADR 0058 row E35), with the request at
  `docs/security/RES-L0-STUDENTMARK-REVALIDATION-REQUEST.md`. StudentMark
  implementation (ADR 0068 RES.2) waits for its answer. *Answered 2026-10-07:
  CURRENT WITH CHANGES; RES.2 authorised for development only (ADR 0068
  §19).* Steps 2–3 are
  re-verified in ADR 0068 (§6.2, §9.2); step 4 is P3 (RES.1); step 5 is
  register items RES-L1 – RES-L9.
- **Reopening is not legal clearance** and not production authorization.
