# SIS demo plan

Status: **plan only** (SIS.EXP-0, 2026-10-29). No demo data was changed.
The standing contract: the canonical Organization demo world
(`docs/demo/ORGANIZATION-DEMO-DATA.md`, ORG.12E-D1) is the shared
demonstration world. SIS waves **extend** it; they never create an
unrelated SIS universe, and every scenario survives `ddev demo-reset`
deterministically (verified by the demo verifier the wave extends).

## 1. Current SIS demo data (DDEV, after the ORG.12E-D1 reset)

| School | Students | Inactive | Guardians | Relationships | Placements (ended) | Campuses used | Lifecycle events | Portal links | Applications | Rollover plans | Student documents |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Lycenza Demo School (LDS) | 36 | 0 | 24 | 36 | 36 (0) | 1 | 0 | 2 | 4 | 1 | 0 |
| Lycenza Demo Annexe (LDA) | 3 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| Northstar Gachibowli (NIS-GCB, 2 Campuses) | 0 | — | — | — | — | — | — | — | — | — | — |
| Greenfield, Horizon (3 Campuses), other Northstar Schools | 0 | — | — | — | — | — | — | — | — | — | — |

Other Student-linked data, all in LDS:
- 12 Guardians with more than one Student (sibling families);
- 0 Students with more than one Guardian;
- 8 Transport riders;
- 4 Hostel residencies;
- 5 Library loans;
- 39 Charges and 30 Payments;
- 78 attendance records;
- 12 subject enrollments.

Personas that exercise SIS today:
- LDS: `school.admin`, `principal`, `teacher` (owned classes only),
  `guardian01` (portal), `student` (403 everywhere), `multi.school`,
  `annexe.admin` (isolation);
- Northstar: `gachibowli.admin`, `network.shared` (Principal of
  Gachibowli and a Network Viewer), `gachibowli.cashier` (Main Campus
  only).

**Gaps against the brief's scenarios:**
- no withdrawn, transferred, inactive or completing Student;
- no multi-Guardian Student;
- no Student on a second Campus (so no Campus-scoped Student scenario);
- no Student documents (not supported).

The SIS 10.1 UAT created such states at runtime; a reset removes them.

## 2. Principles for SIS waves

- **Northstar International School — Gachibowli** (2 Campuses, an owned
  Network member) becomes the **canonical SIS School**. It already has
  School Admin, Principal (`network.shared`) and a Campus-scoped persona,
  and its Campus split makes Campus scope demonstrable.
- **Lycenza Demo School** keeps its existing population as the regression
  fixture (UAT history, portal personas); waves may add to it, never
  rewrite it.
- **Greenfield and Horizon stay without Students**, as the "empty School"
  contrast for Setup readiness.
- **Data:** fictional names only; no real identifiers; all dates relative
  to the School-local date of the reset.
- **Reset:** every SIS scenario is created by the demo builder; the
  verifier checks counts and states after each reset (byte-identical
  topology across two resets, as in ORG.12E-D1).

## 3. Scenarios per wave

### 3.1 SIS.EXP-1 — Student 360 & directory

| Item | Value |
|---|---|
| School | NIS-GCB: two Sections on the Main Campus, one on Early Years; about 20 Students |
| Personas | `gachibowli.admin` (whole School); `network.shared` (Principal); `gachibowli.cashier` (Campus Main, Finance only) |
| Sample family | A two-child family (siblings across both Campuses) with two Guardians (one primary, one emergency contact), a rider assignment, an outstanding Charge and a Library loan for one child |
| Positive | The admin opens the elder child's profile and sees Guardians and the sibling block, the current class, subjects, an attendance link with recent records, the outstanding balance, the route and stop and the open loan. The directory filtered by Campus Early Years shows the younger child |
| Negative | `network.shared` without Finance sees no Fees section. `gachibowli.cashier` holds no Student capability, so no Student page opens; in Finance it still sees only Main Campus Charges. The Annexe admin gets 404 on a Gachibowli Student. A second editor's stale identity form is refused |
| Reset | Builder creates the family, placements, rider, Charge and loan; verifier asserts the counts and the sibling link |

### 3.2 SIS.EXP-2 — Movement & exit register

| Item | Value |
|---|---|
| School | NIS-GCB |
| Sample | Four built events: a withdrawn Student (`left_school`), a Campus transfer Main → Early Years, a cancelled-before-start placement (`did_not_join`), and a reactivated Student (`returned_to_school`). One converted applicant (new enrollment). One completing Student (`completed` placement + `completed_studies`) |
| Positive | The register for the current month lists each event with its reason label and Campus. Filtering by action narrows it |
| Negative | A Campus-only persona is refused (whole-School register). No Guardian contact or actor appears |
| Reset | Events come from the real services during the build (never raw inserts), so lifecycle evidence triggers are satisfied |

### 3.3 SIS.EXP-3 — Student documents (after OD-SIS-1)

| Item | Value |
|---|---|
| School | NIS-GCB |
| Sample | The elder sibling holds a birth certificate (verified), a previous-school report (unverified), and an expired document |
| Positive | Upload, view, verify, archive; the class checklist shows missing documents |
| Negative | No document capability → no section and 404 on the file. Foreign School → 404. Guardian visibility only per the decision |
| Reset | Builder uploads fixture files to the local object store; the real-MinIO limitation is recorded as in the Documents tests |

### 3.4 SIS.EXP-4 — Leaving certificate (after OD-SIS-2)

| Item | Value |
|---|---|
| Sample | The withdrawn Student from 3.2 |
| Positive | Issue a numbered certificate; it appears in the register and as a Document |
| Negative | A second issue for the same exit is refused. An active Student is refused. Numbers are never reused |
| Reset | Certificate issued by the builder through the service |

### 3.5 SIS.EXP-5 — Bulk import (after OD-SIS-3)

| Item | Value |
|---|---|
| School | Greenfield (empty) as the target, so the import is visible |
| Sample | A fixture CSV of ten Students with first placements and Guardians |
| Positive | Preview, then commit all ten |
| Negative | One invalid row rolls back all ten. A replayed commit writes nothing. A Campus-scoped actor is refused |
| Reset | Greenfield returns to empty (the import is a walkthrough step, not seeded) |

### 3.6 SIS.EXP-6 — Annual confirmation (after OD-SIS-5)

| Item | Value |
|---|---|
| School | LDS (portal personas exist) |
| Sample | `guardian01` confirms one child's record for the next year |
| Negative | A Guardian of another family cannot see or confirm it. A second confirmation is idempotent |
| Reset | One confirmed and one pending child |

### 3.7 SIS.EXP-7 — House membership (after OD-SIS-4)

| Item | Value |
|---|---|
| School | NIS-GCB |
| Sample | Four Houses; Main Campus Students assigned; one Student unassigned |
| Negative | A second House for the same Student and year is refused; no gender split shown |
| Reset | Seeded memberships |

### 3.8 SIS.EXP-8 — Closure

Full demo re-verification across every scenario above that was built,
on two consecutive resets.
