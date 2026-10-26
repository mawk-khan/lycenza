# ORG.LOCAL-CLOSE — Organization programme final local closure (2026-10-26)

Status: **closure record** (ADR 0076 §82). Documentation-only audit at
`origin/main` = `76dfaa03`. No product code, schema, capability, role or
infrastructure changed. This record links to the detailed records and does
not repeat them.

**Result: LOCAL ORGANIZATION DEVELOPMENT = COMPLETE** for the authorized
local scope, with recorded non-blocking issues (§8).
**STAGING = NOT STARTED / NOT PROVISIONED. PRODUCTION = NO-GO.**

## 1. Baseline and evidence anchors

| Item | Value |
|---|---|
| Audit baseline | `76dfaa03` (HEAD = `main` = `origin/main`), clean tree, one worktree |
| Latest application-code change | `ca4769aa` (ORG.10-R1); nothing under `apps/platform/{app,database,routes,resources}` changed since |
| Latest qualified code SHA | `66be83a6` (ORG.11-R1: runtime PHP 8.3.36 / curl 8.23.0) |
| Latest docs SHA before this audit | `76dfaa03` |
| Protected historical items | `integration/e16-r2` = `fb29aff5` (RETIRED / HISTORICAL, untouched); stash `phase-5d3-preflight` (untouched) |

## 2. Programme sequence (ADR 0076)

| Area | Sections | Status |
|---|---|---|
| Contract and owner decisions | §1–§26 (ORG.0, 2026-10-17) | adopted |
| Foundation: hardening, route coverage guard, School Registry, operator personas, module entitlements, Auditor oversight | §27–§32 (ORG.1A–ORG.6) | built |
| Campus authority (foundation, adoption waves, SIS, HR, Finance, Inventory immutability) | §33–§55 (ORG.7A–ORG.7H-1R) | **CLOSED** (§53; §54–§55 post-closure hardening) |
| Approval authority and assurance | §56–§65 (ORG.8–ORG.8G) | **technically CLOSED** (§65) |
| Reference parity (baseline, screenshots, Finance, daily operations, Communications) | §66–§77 (ORG.9A–ORG.9D-1R1) | **CLOSED for the authorized v1 waves** (9B, 9C-1..4, 9D-1..3 + R1s) |
| Integrated local UAT | §78–§79 (ORG.10, ORG.10-R1) | **CLOSED** |
| Release artifact | §80–§81 (ORG.11, ORG.11-R1) | **VERIFIED**; STG-06 CLOSED |

## 3. Domain closure (authorized local scope)

- **Organization foundation:** CLOSED.
  - School is the only tenant (§4).
  - Lifecycle is database-enforced (rule 86).
  - Platform and School authority are separate (§5–§8, rules 85, 99).
  - Module entitlements intersect capabilities (rule 100).
  - Roles and capabilities are a read-only catalogue (rules 93–94).
  - Navigation is a projection (§15).
  - School Groups are their own scope (rule 84).
  - Verified in this audit: no `tenant_id`, `organisation_id` or
    `organization_id` column or table exists in `database/migrations` or
    `app`, and no `organizations`, `organisations` or `tenants` table.
- **Campus:** CLOSED (§53). Campus is the only Branch concept; no Branch
  table exists (verified). Historical Campus semantics stay documented in
  rule 101:
  - stored Charge Campus;
  - Library loan Campus;
  - Transport route Campus once used;
  - Inventory Location freeze;
  - immutable placements and the named Campus transfer.
- **HR:** CLOSED (§42–§43, §62, §69).
  - Built: Employee identity, Employment, current assignments
    (`CurrentEmployeeAssignments`), Campus, reporting lines, Leave and its
    approval, Staff Attendance, month reports, and current staff
    (`CurrentStaffMembers`).
  - Open: ORG10-D01 (UAT-MINOR, §8).
- **SIS / Guardian / Attendance:** CLOSED (§44–§47, §63, §65, §69).
  - Built: Student, Guardian, enrollment, Campus, Grade and Section.
  - Student lifecycle is direct with assurance, DB-required lifecycle event.
  - Attendance follows its frozen contract: registers are timetable-bound,
    corrections are absence-neutral, history is records only.
  - Leavers are collectable; history is preserved.
- **Finance:** CLOSED for v1 (§49–§51, §58, §61, §65, §68, §74).
  - Built: Charges, Payments, concessions, payment corrections (requested
    and approved), Charge cancellation (assured), Outstanding, as-at,
    account ledger, Campus Finance isolation and the fee-due reminder.
  - Intentional or deferred differences: no universal Charge fee-head
    (PV1-01); no Cash/Bank Book classification for v1; no dues ageing; no
    Trial Balance; no received-date as-at.
- **Inventory:** CLOSED for v1 (§54–§55, §70–§71).
  - Built: receipt, transfer, Department issue, write-off, current balance,
    movement register, Campus/Location views and monthly stock.
  - **OD-9.4:** `stock_movements` has no person column; the only recipient
    is `recipient_department_id`.
  - **OD-9.5:** `write_off_reason` is a closed CHECKed code, and there is
    no remarks or notes column.
- **Transport:** CLOSED for v1 (§41, §72).
  - Built: routes and stops, single rider assignment, Campus guard, fee
    intent, and bulk rider import (signed preview, add-only, one-transaction
    all-or-nothing commit).
  - Deferred: TRN-015 capacity read, reassignment by import, partial import
    and error export, GPS/maps.
- **Approval / assurance:** CLOSED (§65). There is no generic approval
  engine: each domain owns its workflow over `ApprovalDecisionAuthority`,
  `MakerCheckerPolicy` and `FreshMfaRequirement`, registered in
  `ApprovalWorkflowRegistry` (rule 102). Covered:
  - payment corrections;
  - Leave;
  - Student lifecycle;
  - Library fine void;
  - Charge cancel;
  - Inventory write-off.
- **Parity:** the ORG.9A baseline, the screenshot register and the
  classification files remain authoritative (`docs/product/REFERENCE-*`,
  `PARITY-*`). Items were built only where Lycenza's model held; there was
  no blind clone (`PARITY-DO-NOT-BUILD.md`).
- **Communications:** ORG.9D CLOSED.
  - Built: Staff-only audience, recipient consumption, fee-due reminder,
    Events Calendar.
  - Separate capabilities stay separate: `communications.view` (Hub
    management visibility), `communications.inbox.view` (own received
    items) and `communications.events.view` (calendar).
  - **OD-9.7:** `ConsumerCapabilities::SCOPE_INDEPENDENT` is exactly those
    two consumer keys. `ConsumerCapabilityActivationArchitectureTest` pins
    it.
  - `CampusScopeCatalogue` was last changed in ORG.9C-2 (`026b4a69`), not by
    OD-9.7.

## 4. Integrated local UAT (ORG.10, `docs/uat/org-10/`)

Results:
- 149 scenarios: 146 PASS, 2 findings, 1 observation.
- ORG10-D02 (UAT-MAJOR) is **CLOSED** by ORG.10-R1 (DDEV retest `cp_r1.py`).
- ORG10-D01 stays **UAT-MINOR**.
- No UAT-BLOCKER or UAT-MAJOR is open.

Cross-School and Campus isolation:
- 12 School A record types return the unknown 404 from School B, both for
  the shared user and for the School B admin. No School A Communication
  appears in School B.
- Raw-SQL RLS backstops pass (70 real-PostgreSQL backstop tests).
- Campus boundaries passed across Student, Finance, Library, Inventory and
  Transport.
- OD-9.7 retest: Campus-only staff consume their own Staff Communications,
  while out-of-Campus Charges, Students and Finance management stay
  refused.

## 5. Security position

**Application security:**
- Unresolved HIGH = **0**; unresolved MEDIUM = **0** (security reviews
  §77.10 and §79.8; ORG.7H's MEDIUM closed in §53).
- Accepted LOWs:
  - **L-1:** unescaped `ILIKE` in recipient search.
  - **L-2:** a staff member who is also a Guardian sees their own Guardian
    mail in the staff Hub.
  - **L-3:** GET requests move read markers.
  - **P-1:** unread-badge cost at volume.
  - **R1-L1:** up to 60 s inactive-Campus consumer window.
  - **R1-L2:** Automation owner diagnostic code.
  - **R1-N1** (informational): `holdsNow()` vs `can()` for the consumer
    keys.
  - F-D1-3: readiness takes about 10 s on a hanging Redis.
  - §77.10 L-4 is superseded by OD-9.7.

**Supply chain** (separate from product defects; run 40327776824):
- No blocking Critical or High finding.
- Non-blocking: `league/flysystem` ≤3.35.2 (Low, Composer) and
  `postcss-selector-parser` (Moderate, npm, build-time).
- Debian no-fix exceptions: 60 (app) and 55 (ai-gateway) under
  `OWNER-0O-E16-2026-10-16`.

## 6. Release artifact and provenance

Chain: `66be83a6` → CI gate 40325590541 (9,585 cases, 9,584 passed + ESI-12
skip, 365,639 assertions) → qualification run 40327776824 (same commit;
same-run regression 9,585, 1 skip, 366,384 assertions) → `verify-artifact`.

| Image | Digest | Runtime | State |
|---|---|---|---|
| app | `sha256:7d2591e62d45baa8f2905f724194d72e63563caea54e1f250e98492c9f110868` | PHP 8.3.36, curl 8.23.0 | **VERIFIED** (NON-PRODUCTION signing) |
| ai-gateway | `sha256:ef524d9be34e3a5b14c3f5fa9f2393a4f5dfbcc4d48e0fbc87a5e1f1ab166aed` | — | **VERIFIED** |

STG-06 is CLOSED. Nothing was pushed to a registry, published, promoted or
deployed. The qualified code SHA (`66be83a6`) differs from the docs SHAs
after it (`76dfaa03` and this audit), which change documentation only.

## 7. Environment and gates (not local-closure blockers)

- **STG-04:** OPEN LOCAL-ENVIRONMENT OPERATIONAL ISSUE
  (`docs/operations/ORG-11-STAGING-READINESS.md` §9.4).
  - The browser is served by Docker Desktop with the ORG.10 data.
  - The CLI points at the stale WSL docker-ce Lycenza project (a 2026-09-29
    volume, preserved). The two databases are not proven identical.
  - **It must be reconciled before any future stateful local testing or
    development.** No engine was changed by this audit.
- **STG-01:** OPEN INFRASTRUCTURE PREREQUISITE (blocks staging promotion
  only).
  - Staging is **NOT PROVISIONED**: no AWS session, no Terraform
    implementation, no ECR, no Secrets Manager resource, no DNS, no staging
    database, no deployment.
  - **AWS account / Organization state: UNKNOWN / NOT AUTHENTICATED.**
- **Owner decisions kept for future work** (decisions only, nothing
  provisioned):
  - OD-PPR-4: AWS Secrets Manager.
  - OD-PPR-5: Amazon ECR + durable AWS-backed signing custody.
  - OD-PPR-8: `staging.<approved-canonical-product-domain>` (FQDN
    unresolved).
- **D-11 (exports, PV1-16):** OPEN PRODUCT DECISION / SEPARATE WORKSTREAM.
  - No export was added by ORG.
  - The only CSV responses are the pre-ORG, legal-gated Payroll statutory
    exports (`99ae7a58`, 2026-09-03) and ORG.9C-4's blank Transport import
    template, which contains no School data.
  - (V11's unrelated "D-11 duplicate-recipient detection" row shares the
    label.)
- **E16:**
  - E16-R2 is **RETIRED / HISTORICAL / ABANDONMENT CONDITION MET**
    (`fb29aff5`, kept).
  - The current decision is valid through **2026-11-12**. **A successor is
    required before 2026-11-13** (future release-governance work; it does
    not block local closure today).
- **Production-only gates:**
  - E17–E20 (email provider), E21 (retention), E30–E34, E36–E47;
  - HRX legal-activation gates;
  - the fee-reminder production email purpose (§74.11, ADR 0055);
  - M-1, OD-8.4, OD-8.9;
  - production infrastructure (PPR);
  - the E16 successor.
- **Owner decisions before build:** PV1-13..PV1-22 (opening balances,
  certificates, Student documents, exports, family lists, LC-5, daily
  attendance, staff borrowing, dishonoured cheque, expenses).
- **Post-v1** (`docs/product/PARITY-POST-V1-BACKLOG.md`):
  - Inventory custody/returnables, stocktake, fixed assets, procurement;
  - Transport GPS/maps/tracking and capacity;
  - Communications recurrence and automatic reminders; Campus-targeted
    Events; Student Portal; academic closure calendar;
  - Library renewal, borrower limits and masters;
  - dues ageing; Trial Balance.

## 8. Remaining local defects

| Class | Items |
|---|---|
| UAT-BLOCKER | none |
| UAT-MAJOR | none (ORG10-D02 closed) |
| UAT-MINOR | **ORG10-D01**: Staff directory "Current" (stored state) lists a future hire stored `active`; authority is date-based and unaffected — ACCEPTED FOR STAGING TESTING |
| Operational | **STG-04** (local engines); demo realism notes in `ORG-10-INTEGRATED-UAT.md` §6 |

## 9. Completion (separate, never one number)

| Area | Complete | Basis |
|---|---|---|
| Organization local product development | **100%** of authorized local scope | every authorized slice built and published; latest code regression green |
| Organization local integrated UAT | **100%** | ORG.10 + R1 closed; 1 UAT-MINOR accepted |
| Release-artifact readiness (qualification) | **100%** | both images VERIFIED; registry publication/signing custody are staging infrastructure (OD-PPR-5), counted below |
| Staging infrastructure | **0%** | nothing provisioned; R-00a not started |
| Staging validation | **0%** | no environment |
| Production readiness | **NO-GO** (not expressible as a percentage) | the application is qualified; staging, production infrastructure and the open legal/release gates in §7 all remain |

## 10. Regression evidence

This audit is documentation-only. The full suite was deliberately not rerun.
- Latest exact-SHA regression on code: `66be83a6`, 9,585 cases, 9,584
  passed, 1 ESI-12 skip, 0 failures.
- Latest application-code checkpoint: `ca4769aa`, 9,584 passed + ESI-12
  skip.
- Regression counter: **0/5**.

## 11. Owner options (none started)

- **A.** Pause Organization and start another ERP-development phase.
  Reconcile STG-04 first if that phase is stateful.
- **B.** Later resume staging through R-00a (authenticated AWS session),
  R-00b and a reviewed Terraform R-01, each with its own approval.
- **C.** Address a recorded item: ORG10-D01 (date-derived directory state),
  D-11 export policy, or a PV1-13..22 owner decision.

Infrastructure is not the default merely because it is next in deployment
order.
