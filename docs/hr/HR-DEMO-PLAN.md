# HR demo plan (HR.EXP-0) — DRAFT

Status: **draft, documentation only.** Nothing is seeded by HR.EXP-0.

## 1. Current canonical HR demo

| Item | Where |
|---|---|
| HR data | **Lycenza Demo School only** (`DemoDataBuilder::buildHr`): 12 Employees (8 teaching, 4 non-teaching), 2 categories, 3 Departments (ACAD, ADMIN, TRANS), 6 Positions, one Campus each, employment from 2023-06-01, one primary assignment |
| Payroll | `DemoModuleData::payroll`: components BASIC/HRA/PF, structure STAFF-STD, 8 compensation assignments; August 2026 run posted; September 2026 run calculated, awaiting approval |
| Personas | `hr.payroll@example.test` (hr_officer + hr_sensitive_records + payroll_officer + demo statutory); `school.admin@` (approves and posts); `principal@` (HR, Leave, attendance; Payroll 403); `teacher@` (self-service, EMP-000003); `annexe.admin@`, `multi.school@` (tenancy) |
| Missing | reporting lines; Leave types, years, requests and balances; staff attendance; recruitment; any HR in the Organization world (Northstar Gachibowli, Greenfield, Horizon); an HR walkthrough document |

**Known demo defect (S-13).** The August run is posted as `hr.payroll`, who
lacks `payroll.runs.post`. HR.HARDEN-0 corrects it, and the demo never
fabricates an MFA proof.

## 2. Rule for future waves

- **Extend the canonical world, never a separate HR world:**
  - Lycenza Demo School for Payroll continuity;
  - **Northstar Gachibowli** (two Campuses) for Campus-scoped HR, so SIS and
    HR are reviewed in the same School.
- **Fictional data only.**
- **Seed through the canonical services.**
- **Never fabricate** an MFA proof or a lifecycle event.
- **Cleared by `ddev demo-reset`.**

## 3. Scenarios per wave (provisional)

| Wave | School / Campus | Persona and record | Positive workflow | Negative authority | Reset |
|---|---|---|---|---|---|
| HR.HARDEN-0 | Demo School | `school.admin@` approves the September run through the chosen review projection; a `payroll_officer` override visible to the checker | approve with visibility (fresh MFA, live) | `hr.payroll@` cannot approve its own prepared run; `principal@` sees no payroll | live actions removed |
| HR.EXP-1 | Gachibowli (Main + Early Years) | a new `gachibowli.hr@` (hr_officer) and Gachibowli Employees on both Campuses with reporting lines | Staff 360 with Leave, attendance and teaching sections | an Early Years Campus HR grant sees only Early Years Staff; Network Manager and Platform see nothing | seeded |
| HR.EXP-2 | Gachibowli | a Department head and a reporting chain | head shown, never approving | a head without `hr.leave.approve` cannot decide | seeded |
| HR.EXP-3 | Gachibowli | a teacher Employee linked to an account | own profile; a contact-change request | another Employee's id is a 404 | live request removed |
| HR.EXP-4 | Gachibowli | leave types, a year, allocations, an approved and a pending request | balance after approval; carry-forward preview | a Campus HR grant cannot decide Leave | seeded |
| HR.EXP-5 | Gachibowli | a vacancy and candidates (fictional) | interview → offer → conversion to an Employee | a candidate is not Staff; a public submission is rate-limited | walkthrough conversions removed |
| HR.EXP-7 | Demo School | run detail at scale | constant query budget | statutory paths refused in production | seeded |
