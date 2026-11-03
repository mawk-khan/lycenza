# HR implementation roadmap (HR.1–HR.16, HR.R1)

Status: **authoritative roadmap, issued by HR.0A** (2026-11-03).
- It supersedes the HR.EXP-0 draft waves (mapping in §4).
- Nothing is authorised: each phase needs the owner's explicit
  authorisation, and gated phases need their decision recorded first.
- **Never auto-start a phase.**

Inputs:
- `HR-0A-ARCHITECTURE-RECONCILIATION.md`;
- `HR-REFERENCE-CAPABILITY-MAP.md` (capability ids `Dn-nn`);
- `HR-LEGAL-SECURITY-GATE-REGISTER.md`;
- `HR-TEST-STRATEGY.md`.

## 1. Principles (unchanged from SIS.EXP and HR.EXP)

- **One outcome per phase.**
- **Domain ownership** (`HR-DOMAIN-OWNERSHIP-MAP.md`).
- **Name it or don't add it.** No capability, role, Campus adoption, export
  or schema unless the phase names it.
- **Extend the canonical demo world**, never a separate HR world.
- **Per phase:** owner walkthrough on a fresh `ddev demo-reset`;
  independent security review; tenancy tests; capability-map update.
- **Publication** through `integration/*` → exact-SHA `CI gate` →
  `publication-check` (rule 96). Then delete the integration branch.
- **Near a legal gate:** an explicit availability gate (the
  `StudentCertificateAvailability` pattern), never "no role holds it".

## 2. Execution order (evidence-adjusted)

The numbering follows the requested programme. **The execution order
differs**, because HR.4–HR.8 depend on OD-HR-14 (the HRX-L2 split), and
65 of their capabilities are gated:

```
HR.1 → HR.2 → HR.3 → HR.9 → HR.10 → HR.11 (ungated parts) → HR.12 → HR.13 → HR.14
      └─ OD-HR-14 decided? → HR.4 → HR.5 → HR.6 → HR.7 → HR.8 (L2b only)
HR.15 Payroll-integrity slice may be pulled forward as soon as OD-HR-1/2 are decided
HR.16 closes the implementation; HR.R1 closes HR after the wider ERP connects.
```

**Full regression** (rule 82, `HR-TEST-STRATEGY.md` §3): HR.1, HR.3,
HR.6, HR.10, HR.12, HR.15 and HR.16. Every other phase also runs its
exact-SHA `CI gate`.

## 3. Phases

Field order in each block:
1. Prerequisites
2. Scope
3. Schema
4. Service / domain
5. UI
6. Capability / authorization
7. Audit
8. Integrations
9. Tests
10. Migration / backfill
11. Reporting
12. Rollout gate
13. Done when

### HR.0 — Architecture & reconciliation

**DONE: this checkpoint (HR.0A).** It produced the contract, the
capability map, the gates, ownership, the test strategy and the HR.R1
reservation.

### HR.1 — Workforce foundation

> **HR.1A IMPLEMENTED (2026-11-03).** Delivered:
> - `employment_terms` (effective-dated, append-only, with a
>   database-maintained projection);
> - the closed `StaffType` and `EmploymentType`;
> - database overlap backstops for employments and primary assignments;
> - named placement changes (`PlacementChangeService` +
>   `employee_placement_changes`);
> - `WorkforceAsOfReader`;
> - S-8, S-9, S-10 (employment / primary), S-11, S-12 (type), S-15 and
>   ORG10-D01.
>
> **Not built in HR.1A:**
> - `employee_reference_codes` (D1-03), which moves to HR.2;
> - the X-1 consumer rewiring, which stays in HR.15.
>
> Record: `docs/modules/HR.md` "Workforce foundation (HR.1A)"; ADR 0028 HR.1A
> amendment; CLAUDE.md rule 130.

1. **Prerequisites:** owner authorisation; E16 valid; the owner
   classifies staff types for demo categories.
2. **Scope:**
   - D1-02/07/11/12;
   - D2-01…06, D2-10…13;
   - D18-05…08;
   - S-8, S-9, S-10 (employment), S-11, S-12 (`employment_type`), S-15;
   - ORG10-D01.
3. **Schema:**
   - new: `employment_terms`, `employee_placement_changes`,
     `employee_reference_codes`;
   - `employee_categories.staff_type` (closed CHECK);
   - triggers: no overlapping non-draft employments; at most one primary
     assignment per date; `employee_number` immutable;
   - remove `school_id` / `record_status` from `$fillable`.
4. **Service / domain:**
   - `EmploymentTermsService` (effective change, never in place);
   - `PlacementChangeService` (one named operation that ends one
     assignment and creates the next; lock `EmployeePlacementLock`
     first);
   - as-of readers on `CurrentEmployeeAssignments`;
   - `EmploymentService::update()` no longer writes terms.
5. **UI:**
   - profile "Employment" shows terms history;
   - a "Change placement" action (kind, reason, date);
   - the directory shows status by date (pre-joining distinct);
   - the link picker shows the account kind.
6. **Capability / authorization:** existing keys only:
   - `hr.employees.assignments.manage`;
   - a Campus actor needs both Campuses in scope (rule 101);
   - self-administration refused.
7. **Audit:** `hr.employment_terms.changed` and
   `hr.placement.changed` (ids, kind, reason code, field names).
8. **Integrations:** `CurrentStaffMembers` unchanged; Payroll reads terms
   through an HR reader, never the table.
9. **Tests:** strategy §2.1 + two-process tests for each trigger; RLS;
   allow/deny; as-of queries.
10. **Migration / backfill:** terms from current columns; unmapped
    `employment_type` values listed for the owner.
11. **Reporting:** directory as-of date; joiners and leavers by date.
12. **Rollout gate:** none (ungated).
13. **Done when:** the D2 rows are IMP; X-3 and S-8…S-15 (as listed) are
    closed; full regression green.

### HR.2 — Staff profile & records

> **HR.2A IMPLEMENTED (2026-11-03).** Delivered:
> - `employee_reference_codes` (D1-03; closed kinds, never reused);
> - archive-not-delete on the six ancillary profile tables (S-16,
>   OD-HR-13 decided);
> - the self-administration guard over every profile write (S-3);
> - stale-form tokens (`EmployeeProfileVersion`);
> - HR documents as governed uploads with closed categories (S-12, D1-26;
>   ADR 0029 amendment);
> - derived experience;
> - server-side contact normalization;
> - the composed Staff Profile with section navigation;
> - the full reference-field reconciliation (`docs/modules/HR.md`).
>
> **Not built in HR.2A:**
> - OD-HR-3 (self-service read);
> - OD-HR-9 (CSV import adapter);
> - Staff 360 summaries from Leave, StaffAttendance and Payroll;
> - every gated family.
>
> Record: `docs/modules/HR.md` "Staff profile foundation (HR.2A)"; ADR 0028
> and ADR 0029 HR.2A amendments; CLAUDE.md rule 132.

1. **Prerequisites:** HR.1; OD-HR-3 (self access); OD-HR-13 (archive vs
   delete); OD-HR-9 (CSV import).
2. **Scope:**
   - D1-03 (`employee_reference_codes`, moved here from HR.1A);
   - D1-17/19/26/27/29;
   - S-3, S-16 (profile), S-12 (document category);
   - a stale-form token on profile edits;
   - Staff 360 (each owner's summary under its own capability, rule 118
     pattern).
3. **Schema:**
   - archive columns on ancillary tables (no runtime DELETE);
   - `employee_document_categories` closed catalogue (code-owned);
   - document expiry date already exists.
4. **Service / domain:**
   - `EmployeeProfileVersion` (keyed);
   - self-guard extended to notes, documents, personal, addresses,
     contacts and verification;
   - `StaffProfileSections` assembler outside every domain.
5. **UI:** Staff 360; document categories; no bulk all-class grid
   (D1-30 NR).
6. **Capability / authorization:** existing section keys; sections are
   absent when not authorized.
7. **Audit:** field names only; document reads are already audited.
8. **Integrations:** Leave, StaffAttendance, Payroll (own tier only) and
   TeachingAssignments summaries via their readers.
9. **Tests:** section absence; Campus viewer; stale form 409; constant
   query budget.
10. **Migration / backfill:** document categories mapped.
11. **Reporting:** none new.
12. **Rollout gate:** none.
13. **Done when:** S-3, S-12 (documents) and S-16 (profile) are closed;
    Staff 360 is live.

### HR.3 — Reporting hierarchy

> **HR.3A IMPLEMENTED (2026-11-03).** Delivered:
> - `reporting_relationships`: dated, employment-to-employment, one
>   primary line;
> - database backstops for overlap, self and same-Employee lines, lines
>   outside either employment, and date-aware loops (all serialized);
> - named and reasoned assign, change, remove and correct operations, plus
>   employment-end closure;
> - `ReportingAsOfReader` (batch, chain, depth, tree, history);
> - the 8A.5 pointer removed (Option A), with a conservative backfill.
>   This deviates from item 3's "kept in sync for one release": a synced
>   pointer would drift with future-dated lines (ADR 0028 HR.3A §2);
> - the HR.1A `future_change_with_reports` refusal removed;
> - X-2, S-10 (reporting) and S-14 (G-9) fixed.
>
> **Not built in HR.3A:**
> - dotted, secondary and functional lines;
> - delegation (ADR 0065 §6);
> - the hierarchy tree UI with an as-of picker;
> - the reporting-managers report.
>
> They need an owner decision or a later slice.
>
> Record: `docs/modules/HR.md` "Reporting relationships (HR.3A)"; ADR 0028
> HR.3A amendment; CLAUDE.md rule 131.

1. **Prerequisites:** HR.1. Delegation needs the ADR 0065 §6 amendment
   (owner).
2. **Scope:** D4-04/05/07/11/12; D2-05; D20-10; S-10 (reporting); S-14
   (G-9 deadlock); delegation only if amended.
3. **Schema:**
   - `reporting_relationships` (primary / dotted, dated, reason) with a
     no-overlap trigger and a cycle trigger (bounded recursive check);
   - `reporting_delegations` (if amended);
   - the pointer kept in sync for one release, then dropped.
4. **Service / domain:**
   - `ReportingHierarchyService` writes relationships;
   - `ReportingLine` reads them as of a date;
   - chain and subtree readers (depth-bounded);
   - L-depth presentation.
5. **UI:** a hierarchy tree with an as-of date; manager change history;
   no fixed-level columns.
6. **Capability / authorization:** existing assignments keys; a manager
   relationship grants nothing; decision keys unchanged.
7. **Audit:** `hr.reporting.changed` (ids, kind, reason).
8. **Integrations:** Leave manager path (decision-time reporting line).
9. **Tests:** strategy §2.2, including the cycle race in two processes.
10. **Migration / backfill:** from pointers (`backfilled`; earlier
    history not reconstructible).
11. **Reporting:** reporting-managers report as of a date.
12. **Rollout gate:** none (delegation: ADR 0065 §6).
13. **Done when:** D4 has no MISS / PART except DEF; full regression
    green.

### HR.4 — Work calendar & shifts

1. **Prerequisites:** **OD-HR-14 decided (L2a)**; ADR 0065 §8 reopened;
   HR-AD-05 accepted.
2. **Scope:** D5 (all); D14-02…09/12; D2-07.
3. **Schema:**
   - `work_calendars`, `work_calendar_rules`, `work_calendar_holidays`;
   - `shifts`, `shift_versions`, `shift_assignments`;
   - the old calendar tables migrated.
4. **Service / domain:**
   - the WorkSchedule module;
   - `WorkingDayCalculator` fed by the effective calendar (Campus over
     School);
   - shift resolver as of a date.
5. **UI:** calendars per Campus; holiday publication; shift editor
   (sessions, grace, early allowance, work-hours type); bulk assignment
   with effective date.
6. **Capability / authorization:** proposed `hr.work_schedule.view` /
   `.manage` (catalogue release, rule 93).
7. **Audit:** calendar, holiday and shift version events.
8. **Integrations:** Leave (units), StaffAttendance (expected time),
   Payroll (working days).
9. **Tests:** strategy §2.3.
10. **Migration / backfill:** calendar copy.
11. **Reporting:** shift roster; Not-Assigned staff.
12. **Rollout gate:** L2a availability gate.
13. **Done when:** the D5 rows are IMP; Leave and attendance read the
    WorkSchedule.

### HR.5 — Attendance events

1. **Prerequisites:** HR.4; OD-HR-14.
2. **Scope:** D6-07…21 (manual and import sources first; device sources
   wait for HR.8).
3. **Schema:** `attendance_sources`, `attendance_events` (append-only;
   unique provider id per source).
4. **Service / domain:** the ingestion service (idempotent, TenantScoped
   jobs, `ModuleExecutionGuard`); a manual punch entry as a source.
5. **UI:** a source event log per employee and day (operator).
6. **Capability / authorization:** a proposed operator key (catalogue
   release).
7. **Audit:** imports audited per batch (counts and ids).
8. **Integrations:** none outward.
9. **Tests:** strategy §2.4 (ingestion half).
10. **Migration / backfill:** none (no fabricated events).
11. **Reporting:** a movement register (L2a).
12. **Rollout gate:** L2a.
13. **Done when:** events are immutable, idempotent and tenant-isolated.

### HR.6 — Attendance evaluation

1. **Prerequisites:** HR.5.
2. **Scope:** D7-07…13/16.
3. **Schema:** `attendance_day_evaluations` (versioned).
4. **Service / domain:** a pure evaluator (events + shift + calendar +
   leave + permission + policy) → day result; re-evaluation is explicit
   and deterministic.
5. **UI:** day and month views with late / early / no-punch states.
6. **Capability / authorization:** existing view keys.
7. **Audit:** evaluation runs (ids, version).
8. **Integrations:** Payroll HR inputs read evaluations (no money effect,
   HRX-L4).
9. **Tests:** strategy §2.4 (evaluation half); full regression.
10. **Migration / backfill:** manual records evaluated as a manual source.
11. **Reporting:** late / early reports (L2a).
12. **Rollout gate:** L2a.
13. **Done when:** the D7 rows are IMP or GATE (HRX-L4 only).

### HR.7 — Attendance operations

1. **Prerequisites:** HR.6. Manager correction needs the ADR 0065 §7
   reopening.
2. **Scope:** D8-03/04/10/13/14/15; S-6.
3. **Schema:** `attendance_regularization_requests`,
   `staff_attendance_period_locks`.
4. **Service / domain:** regularization on the approval foundation;
   lock / reopen with reason and fresh MFA; correction window.
5. **UI:** regularization queue; month confirm and reopen.
6. **Capability / authorization:** request (self) and decide
   (manager-path decision keys, OD-HR-5).
7. **Audit:** lock, reopen and decision events.
8. **Integrations:** Payroll reads locked months only.
9. **Tests:** locked-period refusal; reopen; maker/checker.
10. **Migration / backfill:** none.
11. **Reporting:** modified-attendance report.
12. **Rollout gate:** none for the lock; L2a for timing-based
    regularization.
13. **Done when:** D8-10 is IMP; S-6 is closed.

### HR.8 — Biometric / FR / GPS

1. **Prerequisites:** **HRX-L2b legal / security determination**; device
   provider review; Integration Hub family made available.
2. **Scope:** D6-01/03/04/05; D9 (all); D20-19/27/28/38.
3. **Schema:** `attendance_devices`, `attendance_identities`
   (Highly Sensitive, encrypted where applicable).
4. **Service / domain:** provider adapters (outbound calls in jobs, rule
   38); never Staff identity.
5. **UI:** device registry; code mapping.
6. **Capability / authorization:** new keys + MFA (catalogue release).
7. **Audit:** mapping changes (field names).
8. **Integrations:** the Integration Hub provider (rule 116).
9. **Tests:** uniqueness per School; replay; isolation.
10. **Migration / backfill:** none.
11. **Reporting:** biometric reports.
12. **Rollout gate:** L2b + production availability gate.
13. **Done when:** gated rows are opened only by the recorded decision.

### HR.9 — Leave policy

1. **Prerequisites:** HR.1 (staff type, terms). HR-L8 is needed only for
   gender / marital rules.
2. **Scope:** D10-03/09/10/11/13/14/15/16/20; D12-04/05; D14-11.
3. **Schema:** rule columns on the policy version; `leave_eligibility_rules`.
4. **Service / domain:** an eligibility resolution run (idempotent) →
   policy assignments; request validation; a pure sandwich / prefix /
   suffix unit calculator.
5. **UI:** policy-version editor; eligibility; an "unassigned staff" view.
6. **Capability / authorization:** `hr.leave.configure` (existing).
7. **Audit:** policy-version and eligibility events.
8. **Integrations:** none new.
9. **Tests:** strategy §2.5 (policy half).
10. **Migration / backfill:** existing policies get default rule values
    (no behaviour change).
11. **Reporting:** policy assignment list.
12. **Rollout gate:** HRX-L3 (no statutory label).
13. **Done when:** D10 has no MISS except DEF / GATE.

### HR.10 — Leave ledger & accrual

1. **Prerequisites:** HR.9.
2. **Scope:** D10-05/19; D11-01/03/06/16; D12-01/03/08; S-7.
3. **Schema:** ledger kinds `opening` and `accrual` (period key, unique);
   `leave_accrual_runs`.
4. **Service / domain:** monthly accrual (idempotent); proration;
   in-year carried expiry executed; fresh MFA for adjustments.
5. **UI:** monthly allocation grid (by joining cohort); ledger statement.
6. **Capability / authorization:** existing `hr.leave.manage`.
7. **Audit:** run events; adjustments.
8. **Integrations:** none.
9. **Tests:** strategy §2.5 (ledger half); full regression.
10. **Migration / backfill:** none.
11. **Reporting:** a statement that reproduces the balance identity.
12. **Rollout gate:** none.
13. **Done when:** D11 and D12 have no MISS / PART.

### HR.11 — Leave, comp-off & permission workflows

1. **Prerequisites:** HR.10. OD-HR-5 (manager approval). The ADR 0065 §6
   amendment for delegation and multi-level. L2a for permission times.
2. **Scope:** D15, D16, D17; D13-15…17/20; D11-09/10.
3. **Schema:** `compensatory_off_requests`, `staff_permission_requests`,
   `leave_special_windows` (+ periods), delegations.
4. **Service / domain:** each registered in `ApprovalWorkflowRegistry`;
   ledger postings; window resolution (most specific wins; ties refused).
5. **UI:** request and approval queues; windows editor.
6. **Capability / authorization:** request (self) and decide; catalogue
   changes per OD-HR-5.
7. **Audit:** decisions (closed codes).
8. **Integrations:** StaffAttendance (permission impact; L2a).
9. **Tests:** strategy §2.6; maker/checker.
10. **Migration / backfill:** none.
11. **Reporting:** comp-off and permission lists.
12. **Rollout gate:** L2a for time windows.
13. **Done when:** D15–D17 are IMP or GATE.

### HR.12 — Workforce lifecycle

1. **Prerequisites:** HR.1. HR-061 (separation approval) owner decision.
2. **Scope:** D18-03/04/10…13/15…17/20/21; D1-07, D1-33 (non-recruitment
   parts).
3. **Schema:** `employment_lifecycle_events`, `separation_requests`,
   `separation_clearance_items`.
4. **Service / domain:**
   - separation request / decide;
   - closed `SeparationReason`;
   - clearance asks each owner (Inventory, Library, Finance, Documents,
     Timetable, Transport) through their readers;
   - confirmation event;
   - Identity suspension stays Identity's (rule 92).
5. **UI:** exit workflow; clearance checklist; NOC document via HR
   documents.
6. **Capability / authorization:** existing assignments keys + a decision
   key if HR-061 chooses approval.
7. **Audit:** lifecycle events.
8. **Integrations:** cross-owner clearance readers.
9. **Tests:** offboarding end-to-end; rehire; no history loss; full
   regression.
10. **Migration / backfill:** separated employments get a backfilled
    `separated` event.
11. **Reporting:** leavers with reasons (closed codes).
12. **Rollout gate:** none (health remarks HRX-L1 refused).
13. **Done when:** D18 has no MISS / PART except DEF.

### HR.13 — Staff tasks

1. **Prerequisites:** HR.1 (staff type), HR.3 (reporting line).
2. **Scope:** D19 (all), D20-26.
3. **Schema:** `workforce_task_categories`, `_templates`, `_assignments`,
   `_submissions`; Documents owner type `workforce_task`.
4. **Service / domain:** the WorkforceTasks sub-domain (no Attendance or
   Leave dependency); lazily generated occurrences.
5. **UI:** my tasks; assigned by / to me; team tasks; monitor.
6. **Capability / authorization:** proposed `hr.tasks.*` (catalogue
   release); manager assignment needs the reporting line **and** the key.
7. **Audit:** template, assignment and submission events.
8. **Integrations:** Academic Structure readers (Section, SubjectOffering).
9. **Tests:** occurrence determinism; isolation; no ratings.
10. **Migration / backfill:** none.
11. **Reporting:** task reports (operational counts only).
12. **Rollout gate:** HR-L11 for any rating.
13. **Done when:** the D19 rows are IMP.

### HR.14 — Reports / MIS

1. **Prerequisites:** the phases whose data they read; the D-11 extension
   decided.
2. **Scope:** D20-02/06/11/23/33; D8-15; D9-15; D11-16.
3. **Schema:** none (reads); the export audit event.
4. **Service / domain:** `HrReportRegistry` (code-owned; OPERATIONAL /
   RESTRICTED columns; closed filters and sorts; as-of).
5. **UI:** the HR reports hub; column selector; detail drawers.
6. **Capability / authorization:** report authority = all of its data
   capabilities; dataset export key (owner decision).
7. **Audit:** `hr_report.exported`.
8. **Integrations:** none.
9. **Tests:** registry pins; column forgery 422; export ≤ screen;
   isolation.
10. **Migration / backfill:** none.
11. **Reporting:** this phase *is* reporting; person-count analytics stays
    with Analytics (ADR 0040).
12. **Rollout gate:** D-11, ADR 0040.
13. **Done when:** every non-gated D20 row is IMP, XDOM or DEF.

### HR.15 — Cross-ERP integrations

1. **Prerequisites:** OD-HR-1 / OD-HR-2 (Payroll integrity); HR-050 /
   HR-055 (staff-child / fee) owner decisions; an Inventory custody
   contract; Timetable substitution.
2. **Scope:**
   - **Payroll integrity slice:** S-1, S-2, S-4, S-5, S-13; the run-page
     N+1; HRX-L4 evidence only. **May be pulled forward.**
   - X-1 (Timetable, Transport, Visitor via HR readers);
   - the Payroll `EmploymentRecord` coupling decision;
   - D20-18/29/30/31/34/37.
3. **Schema:** per owner (substitution in Timetable; custody in
   Inventory).
4. **Service / domain:** owners' services; HR exposes readers only.
5. **UI:** owner pages.
6. **Capability / authorization:** owners' keys.
7. **Audit:** owners'.
8. **Integrations:** this *is* integration.
9. **Tests:** cross-domain allow/deny; isolation; full regression.
10. **Migration / backfill:** none.
11. **Reporting:** owner reports.
12. **Rollout gate:** OD-HR-1; E47 for staff-child data.
13. **Done when:** every XDOM row has an owner contract or a recorded
    deferral.

### HR.16 — Security / performance / closure

1. **Prerequisites:** HR.1–HR.15 (or recorded deferrals).
2. **Scope:**
   - independent security review;
   - query budgets on every HR page;
   - load test with 1,000 staff (local);
   - documentation drift (`HR.md`, HRX closure count of
     `staff_self_service` keys);
   - the capability map refreshed.
3. **Schema:** none expected.
4. **Service / domain:** fixes only.
5. **UI:** accessibility pass.
6. **Capability / authorization:** coverage guards.
7. **Audit:** catalogue complete.
8. **Integrations:** —
9. **Tests:** full regression.
10. **Migration / backfill:** rollback proofs for every HR migration.
11. **Reporting:** —
12. **Rollout gate:** production gates unchanged.
13. **Done when:** **HR implementation-complete.** HR is not closed until
    HR.R1.

### HR.R1 — Final cross-ERP reconciliation & closure

Reserved: `HR-R1-CLOSURE-CONTRACT.md`.

## 4. Mapping from the superseded HR.EXP draft waves

| HR.EXP wave | Now |
|---|---|
| HR.HARDEN-0 | split: S-8 / 9 / 10 / 11 / 12 (type) / 15 + ORG10-D01 → HR.1; S-3 / 16 / 12 (docs) → HR.2; S-14 → HR.3; S-6 → HR.7; S-7 → HR.10; S-1 / 2 / 4 / 5 / 13 → HR.15 Payroll-integrity slice |
| HR.EXP-1 Staff 360 | HR.2 |
| HR.EXP-2 Organization structure | HR.1 (staff type) + HR.3 (hierarchy history); department head stays deferred |
| HR.EXP-3 Self-service | HR.2 (own profile) + HR.11 (requests) |
| HR.EXP-4 Leave & calendar | HR.4 + HR.9 + HR.10 |
| HR.EXP-5 Recruitment | **deferred** (OD-HR-6, HR-L9); outside HR.1–HR.16 |
| HR.EXP-6 Documents & credentials | HR.2 |
| HR.EXP-7 Payroll expansion | HR.15 (bank / disbursement stay gated: HR-L5) |
| HR.EXP-8 Performance / training | **deferred** (OD-HR-7, HR-L11) |
| HR.EXP-9 Reports & closure | HR.14 + HR.16 + HR.R1 |

## 5. Owner decisions

| Id | Decision | Needed by |
|---|---|---|
| OD-HR-1 | Payroll checker visibility (recommendation: C + B, `HR-EXP-0R-REFERENCE-RECONCILIATION.md` §6) | HR.15 |
| OD-HR-2 | Compensation maker-checker | HR.15 |
| OD-HR-3 | HR self-access to one's own record | HR.2 |
| OD-HR-4 | Staff self-service scope | HR.2 / HR.11 |
| OD-HR-5 | Line managers approve leave (and comp-off, permission, regularization) | HR.7 / HR.11 |
| OD-HR-6 | Recruitment in v1 | — (deferred) |
| OD-HR-7 | Performance / appraisal / training | — (deferred) |
| OD-HR-8 | Disciplinary workflow (legal first) | — (deferred) |
| OD-HR-9 | Staff import format (CSV adapter?) | HR.2 |
| OD-HR-10 | Contracts and e-signature | — |
| OD-HR-11 | Headcount (Analytics gate) | Analytics |
| OD-HR-12 | Family / dependants (legal) | — |
| OD-HR-13 | Ancillary profile archive vs delete | HR.2 |
| **OD-HR-14** | **Split HRX-L2 into L2a (timing) and L2b (biometric / device / GPS / FR)** | HR.4–HR.8 |
| HR-050 / HR-055 | Staff-child and staff-ward fee reports | HR.15 |
| HR-061 | Separation approval | HR.12 |
| HR-083 | Named Campus transfer | HR.1 (proposed as HR-AD-02) |
| HR-084 | Retirement date | HR.12 |
| M-1 | Unlinked staff parent | HR.15 |
