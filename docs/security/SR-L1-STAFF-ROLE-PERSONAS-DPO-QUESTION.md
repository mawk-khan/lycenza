# SR-L1 — Explicit staff personas for already-built Highly Sensitive data: DPO question

**Status at drafting (2026-10-09): DRAFT QUESTION — NOT SENT, NOT ANSWERED.**
This document asks a question. It records no determination, and nothing in
it may be read as one.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Register item:** ADR 0058 row **E47 (SR-L1)**.
- **Engineering contract:**
  `docs/architecture/adr/0071-staff-role-catalogue-least-privilege-contract.md`
  (SR.0, documentation only; nothing is built).

## Background
Today a School can give a staff member access to its finance, HR, payroll or
admissions data only through the broad `school_admin` role (or, for some
modules, `principal`). Some HR and payroll data is reachable by **no** role
at all.

The proposed catalogue adds fixed, narrower School staff roles. Each is a
subset of capabilities that already exist and are already used for the same
School purposes:
- `hr_officer` and the add-on `hr_sensitive_records`;
- `payroll_officer`;
- `accountant`;
- `cashier`;
- `admissions_officer`;
- operational desk roles (library, transport, hostel, front office, stores,
  canteen, communications).

It introduces no new data, no new purpose, no new recipient outside the
School and no automated decision. Each School decides who receives which
role, with fresh MFA and audit.

## The question
Does introducing explicit staff personas that can access these
already-built Highly Sensitive datasets require any of the following?
1. a new or updated **privacy notice** (to Employees, Guardians or
   applicants);
2. a **processing-register** update (new recipient categories within the
   School);
3. **additional conditions** (for example confidentiality undertakings,
   training, MFA, access reviews, or a ceiling on how many holders);
4. **no additional legal action**, because the processing, purposes and
   data are unchanged and only the internal access granularity narrows.

Please answer separately for each data set:
- (a) Employee **government identifiers** and other Highly Sensitive HR
  records (`hr.employees.sensitive.*`);
- (b) Employee **bank information**;
- (c) individual **salary and compensation amounts** and payroll run
  results (`payroll.compensation.sensitive.*`);
- (d) **children's fee, charge and payment information** (accountant,
  cashier);
- (e) **admissions data** about child applicants and their Guardians,
  including the conversion of an applicant into a Student record
  (`admissions_officer`).

## What this does not ask
- It does not ask about statutory payroll (PF/ESI/TDS identifiers and
  exports, Checkpoint 9.6, E45), StudentMark or results (E35–E44), the
  Guardian portal (E46), retention periods (E21) or teacher Attendance
  (E33). Each stays separate.
- No existing gate is relaxed by this catalogue.

## Requested form of answer
For each of (a)–(e):
- one of **NO ACTION / ACTION REQUIRED (state which of 1–3) / NOT
  APPROVED**;
- design, development and production permission, stated separately;
- conditions;
- date, name and role.

## Recording the answer (engineering instructions)
- Record the answer verbatim in a new dated
  `docs/security/SR-L1-STAFF-ROLE-PERSONAS-DETERMINATION.md`.
- Update row E47 by a dated, reviewed change.
- Engineering never fills in or infers any part of the answer.
