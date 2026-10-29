# SIS domain ownership map

Status: **authoritative ownership record for the SIS expansion**
(SIS.EXP-0, 2026-10-29). It maps each reference Student-area function to
the Lycenza domain that owns it, using repository truth
(`docs/architecture/DOMAIN-MAP.md`, `apps/platform/app/Domain`). The
reference places all of these under one Student menu; Lycenza does not.

## 1. Rules

- **SIS = Students + Guardians domains:** the Student record, placements,
  lifecycle, rollover, numbering, duplicate warning, search, Guardian
  identity and relationships, and Student Data Health.
- **SIS may display** another domain's facts about a Student only:
  - through that domain's Application read service;
  - under that domain's capability and Campus rules;
  - never by reading its tables or models directly (rules 4, 101).
- **Records stay with their owner.** Charges, payments, registers,
  timetables, routes, loans, residencies, documents and messages are never
  copied into SIS.
- **Portal owns audience-specific UX.** The Guardian (and any future
  Student) experience belongs to Portal (`Identity/Portal`), over the
  owners' data.
- **Analytics owns grouped counts.** Grouped person counts and grouped
  money are Analytics, behind ADR 0040 and D-14.

## 2. Reference function → canonical owner

| Reference function (map row) | Canonical owner | SIS role | Note |
|---|---|---|---|
| Student list, record, identity edit (SR-03, SR-04) | **SIS (Students)** | owner | — |
| Parents/guardians on the Student record (SR-04) | **SIS (Guardians)** | owner | one Guardian model; no Parent model |
| Statutory identifiers (SR-05) | SIS (would be) | — | legally gated |
| Bulk Student import (SR-06) | **SIS** | owner | adapter over SIS write paths |
| Higher-education programmes (SR-07) | — | — | not adopted |
| Email validation (SR-08) | Communications / email layer | none | — |
| Campus transfer, bulk transfer (SR-09) | **SIS** | owner | bulk post-v1 |
| Inactivation/reactivation approvals (SR-10) | **SIS** | owner | direct assured, not approval |
| Exit revenue impact (SR-11) | Analytics (+ Fees data) | none | gated |
| Exit audit (SR-12) | **SIS** (register) + Fees, Attendance (Data Health checks) | owner of the register | — |
| "Student contracts": confirmation and acknowledgement (SR-13) | Portal (UX) + **SIS** (record) | record owner | decision-gated |
| Pre-admission documents, concession approvals (SR-14) | **Admissions** + Documents + Fees | none | — |
| Fee-discount agreements (SR-15) | **Fees** + Documents | none | — |
| Timetable (SR-16) | **Timetable** (Academic) | none | SIS never owns timetable |
| Student groups (SR-17) | Academic Structure (cohorts); Fees (fee groups); **SIS** (a named House/Club, if decided) | future owner of named memberships | no generic engine |
| Group activities, co-curricular skills (SR-18) | **Examinations** (co-scholastic) | none | — |
| Derived system tags (SR-19) | — | — | not adopted (profiling) |
| Fee segments (SR-20) | **Fees** | none | — |
| Student documents (SR-21) | **Documents** (Student owner type) | surfaces it on the profile | decision-gated |
| Photographs (SR-22) | Documents / SIS | — | legally gated |
| Contact report, column picker (SR-23) | **Guardians** (contacts) | owner, decision-gated | ADR 0041; D-11 |
| Strength reports (SR-24) | **Analytics**; Academic Structure (capacity) | none | gated |
| Sibling report (SR-25) | **SIS (Guardians)**; Fees for dues | owner of the family view | — |
| Strength audit (SR-26) | Fees + Analytics | none | D-14 |
| Branch-wise strength (SR-27) | Analytics (cross-School) | none | ADR 0080 §13.4 |
| Device attendance, absence messages (SR-28) | Attendance + Integrations; Communications | none | legally gated |
| Attendance reports (SR-29) | **Attendance** | link only | — |
| Manual attendance totals (SR-30) | — | — | not adopted |
| Student leave (SR-31) | Attendance + Portal | none | D-16 |
| Teacher lists (SR-32) | **Teaching Assignments** (Academic) | none | — |
| Data-change logs (SR-33) | Compliance audit; SIS history | owner of Student history | — |
| Withdrawal refunds (SR-34) | **Payments** | none | — |
| Suspension (SR-35) | — | — | legally gated |
| Movement history (SR-36) | **SIS** | owner | — |
| Promotion reports (SR-37) | **SIS** (rollover) | owner | — |
| Principal diary (SR-38) | — | — | not adopted |
| Admission analytics (SR-39) | Analytics (Admissions + Fees data) | none | gated |
| Hobbies/talents (SR-40) | SIS (Student field, after classification) | future | post-v1 |
| Leaving certificate (SR-41) | **SIS** (issue) + Documents (stored output) | owner | decision-gated |
| Navigation (SR-42) | Navigation presentation | — | menu ≠ permission |

## 3. Ownership by domain (summary)

| Domain | Owns (from the reference pack) |
|---|---|
| **SIS (Students, Guardians)** | SR-03, SR-04, SR-06, SR-09, SR-10, SR-12 (register), SR-13 (record), SR-17 (named House/Club only), SR-23, SR-25, SR-36, SR-37, SR-40, SR-41 |
| Admissions | SR-14, SR-39 (data) |
| Academic Structure / Timetable / Teaching Assignments | SR-16, SR-17 (cohorts), SR-24 (capacity), SR-32 |
| Attendance | SR-29, SR-31 (if decided), SR-28 (device) |
| Fees / Payments | SR-15, SR-20, SR-26, SR-34 |
| Documents | SR-14, SR-21, SR-22 (storage), SR-41 (stored output) |
| Examinations | SR-18 |
| Communications / Integrations | SR-08, SR-28 (channels) |
| Portal | SR-13 (UX), SR-31 (Guardian submission, if decided) |
| Analytics | SR-01, SR-11, SR-24, SR-26, SR-27, SR-39 |
| Not adopted | SR-07, SR-19, SR-30, SR-38 |

## 4. Dependency directions (unchanged)

Students → Academic Structure, Campuses, Schools. Guardians → Students.

Fees, Attendance, Transport, Hostel, Library and Communications read
Students through `StudentPurposeLookup`/read services.

The SIS.EXP-1 profile summaries read those domains through their **own**
Application read services. This adds no reverse dependency: the summary
assembler lives in the presentation layer
(`app/Http/Controllers/App`), which may depend on every domain, and never
in the Students domain itself. If that placement is impractical, the
SIS.EXP-1 brief must record the dependency direction in `DOMAIN-MAP.md`
in the same change (rule 4).
