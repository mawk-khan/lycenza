# SIS 11.R2 — Advanced SIS Expansion Closure Re-Audit & Next-Phase Decision

Status: **AUDIT COMPLETE — documentation only** · 2026-11-01 · baseline
`main = 562ee2c8` · no product code, schema, capability, test or behaviour
changed. Published docs-only through protected `main` on owner approval
(the HR.EXP-0R authorization).

This audit follows SIS 11.R1 (`SIS-11-R1-MID-EXPANSION-AUDIT.md`, after SIS
11.5). It does not rewrite earlier records; each stays valid for the scope
it tested. **Core SIS stays 96/100**; Advanced SIS coverage is reported
separately and is never blended into it.

## 1. Baseline and preflight

| Item | Value |
|---|---|
| `ddev env-check` | **PASS** (one engine, one database; DDEV migrations = 363) |
| HEAD / local `main` / `origin/main` | `562ee2c8` / `562ee2c8` / `562ee2c8` |
| Working tree | clean; this record is the only addition |
| Worktrees | one (primary) |
| Historical stash | `stash@{0}` (phase-5d3-preflight), untouched |
| Migrations on disk | 363 |
| Latest exact-SHA CI | run 41340419646 on `562ee2c8`: success; 10,318 cases = 10,317 passed + 1 ESI-12 skip; shard union proven |
| Latest `main` CI | run 41342231140 on `562ee2c8`: **success** (completed after the SIS 11.13 report) |
| E16-S2 | valid through **2026-11-28** (hard stop 2026-11-29; `publication-check`: `e16_last_valid_day 2026-11-28`) |
| Regression counter | 0/5 (the 11.13 exact-SHA full suite) |

## 2. Sources and reference verification

- **318-screen pack** (owner-held, `…/Lycenza/Screenshots Downloaded/Student
  Information System (SIS)`): re-fingerprinted this session — **318 files,
  318 PNG, SHA-256 `c7a3699eeda88538ed21226402e7423586525551fd1c663ef0416d370b1f6032`**,
  identical to the committed value (`SIS-REFERENCE-CAPABILITY-MAP.md` §1).
  The parity comparison therefore proceeds.
- `SIS-REFERENCE-CAPABILITY-MAP.md`, `SIS-EXP-0-CURRENT-STATE-AUDIT.md`,
  `SIS-EXPANSION-CLOSURE.md`, `SIS-11-R1-MID-EXPANSION-AUDIT.md`
  (114-capability inventory, Appendix A).
- Implementation records SIS 11.1–11.5, 11.H1, 11.7, 11.8, 11.10, 11.13.
- Code at `562ee2c8`: routes, report registry (dumped live, read-only),
  Student 360 assembler, navigation, migrations, seeder, tests.
- CLAUDE.md rules 118–129; ADR 0012, 0040, 0076 (§44, §47, §84), 0081.
- Administrator Guide, SIS roadmap, MASTER-ROADMAP, the HR/Finance/
  Transport/Inventory records (next-phase evidence, §16).
- Two read-only sub-audits fed this record: (1) schema, capability and
  route growth plus a security/performance sweep of the Advanced SIS code;
  (2) next-ERP readiness. Concrete findings quoted here were re-checked.

## 3. Capability inventory integrity

The **114-capability inventory of SIS 11.R1 is reused unchanged**: 110
reference capabilities + 4 export forms; navigation (SR-42) excluded. No
capability was split or merged. New Lycenza functions that the reference
expresses inside an existing capability are recorded against it:
- bulk Section change (11.13) belongs to C22/SR-09 shuffles;
- Club and Activity catalogues and memberships (11.8) belong to C49/C50/C56;
- register history by period/Subject and recorder (11.10) belong to C83/C86.

The denominator therefore does not move (§6).

## 4. R1 → R2 classification changes

| Id | Capability | R1 (after 11.5) | R2 | Delivered by / reason |
|---|---|---|---|---|
| C22 | Bulk Campus transfer | R6 | **R3** | SIS 11.13: per-Student authoritative preview, existing blockers, signed preview + fresh MFA, all-or-nothing, two-process concurrency proofs; a different target year is Rollover (accepted difference) |
| C49 | Typed groups (house, club, project) with description | R11 | **R4** | 11.7/11.8: House, Club, Activity (closed types, effective-dated history replaces per-year copies). Project and custom types not authorized |
| C50 | Membership rules and bulk assign | R11 | **R4** | membership rules met (one current House; any number of Clubs/Activities; per-group uniqueness; history). Bulk assign deliberately not adopted (rule 128) |
| C51 | Student responsibilities (captain, prefect) | R11 | **R1** | 11.7 House Captain/Vice Captain/Prefect; 11.8 Club President/VP/Secretary/Member Representative |
| C52 | Staff mentors of a group | R11 | **R6** | deferred by 11.7/11.8 until HR staff seams mature (§9.4) |
| C53 | Participation badges and schedules | R11 | **R6** | deferred (badges = recognition, §9.5; schedules = Timetable) |
| C56 | Group activities with participation and attachments | R11 | **R4** | Activity groups with participation and history (11.8); dated activity events and attachments not built (attachments of children's activities touch the photo gate) |
| C83 | Attendance not marked / staff marking compliance | R2 | **R1** | 11.10 "Recorded by" + registers not taken (OD-SIS-10A) |
| C86 | Period-wise and subject-wise views | R4 | **R1** | 11.10 `attendance.registers` (period, Subject, class filters) and Student history Subject filter |

All other 105 capabilities keep their R1 class (Appendix A of R1 stands).
SIS 11.H1 changed no capability class (engineering only, §5.1).

## 5. Impact of each checkpoint since R1

### 5.1 SIS 11.H1 (engineering)
Every R1 finding stays **closed** at `562ee2c8`:

| Finding | Status now | Evidence |
|---|---|---|
| L-1 applicant document key | closed | `applicant_documents_document_fk (document_id, school_id)` + insert-owner trigger (rule 127) |
| L-2 applicant entry points | closed | `ApplicantDocumentFloor` |
| L-3 converted purge | closed | locks applicants before the re-check; two-process proof |
| L-4 verify without view | closed | `decide()` needs `students.documents.view` + `.verify` |
| `APP_URL` parity | closed | `phpunit.xml` forces `APP_URL`; Identity **199/199 under DDEV** this session (SIS 11.13 run) |
| Object-storage classification | closed | `RequiresObjectStorage`: Documents **218 passed, 7 skipped** locally; ran in CI |
| Stale-form (Section, relationship, API PATCH) | closed | required version tokens (rule 127) |
| Same-second audit ordering | closed for the 3 high-likelihood tests; others classified | ordering by UUIDv7 id/content |
| Cross-cutting checklist | in use | `docs/development/CROSS-CUTTING-CHANGE-CHECKLIST.md` (§9a, §9b added since) |

None reopened. One related residual: occurred_at whole-second ties remain a
*pattern* risk for new tests (it caused the 11.3 and an ORG.8A-R1 first-
candidate miss); the checklist rule ("order by id or content") covers it.

### 5.2 SIS 11.7 Houses (OD-SIS-4)
C51 → R1; C49/C50 begin to move (finished classification in §4 after 11.8).
SR-17 OWNER → PARTIAL.

### 5.3 SIS 11.8 Clubs & Activities (OD-SIS-5A/5B)
C49, C50, C56 → R4 (types, multi-membership, participation); C51 R1
extended to Club roles. Mentors, levels, schedules, badges deferred.

### 5.4 SIS 11.10 Advanced Attendance reporting (OD-SIS-10A)
C83 R2 → R1, C86 R4 → R1. SR-29 stays PARTIAL (rates and the monthly grid
are Analytics-gated, the daily non-period model is an Attendance owner
decision).

### 5.5 SIS 11.13 Bulk Student operations
C22 R6 → R3. SR-09 PARTIAL → EXISTING_DIFFERENT_ACCEPTED (within-year moves
met; cross-year via Rollover). ADR 0076 §84 (§10.3).

## 6. Coverage

### 6.1 Counts (R2)

| Class | R1 (after 11.5) | Δ | **R2** |
|---|---|---|---|
| R1 Implemented directly | 20 | +3 (C51, C83, C86) | **23** |
| R2 Functionally equivalent | 9 | −1 (C83) | **8** |
| R3 Lycenza stronger | 10 | +1 (C22) | **11** |
| R4 Partial | 4 | +3 (C49, C50, C56) −1 (C86) | **6** |
| R5 Missing, still valuable | 1 | 0 | **1** |
| R6 Deferred intentionally | 6 | +2 (C52, C53) −1 (C22) | **7** |
| R7 Rejected | 17 | 0 | **17** |
| R8 Legally/security blocked | 10 | 0 | **10** |
| R9 Other ERP | 19 | 0 | **19** |
| R10 Superseded | 2 | 0 | **2** |
| R11 Owner decision | 16 | −6 (C49, C50, C51, C52, C53, C56) | **10** |
| **Total** | **114** | 0 | **114** |

Arithmetic: 23 + 8 + 11 + 6 + 1 + 7 + 17 + 10 + 19 + 2 + 10 = **114**.

### 6.2 Advanced SIS Core Operational Coverage (main metric)
- **Denominator** (R1 philosophy, unchanged): 114 − R7 17 − R8 10 − R9 19 −
  R10 2 − Analytics-owned R6 2 (C06 KPI counts, C87 percentages) = **64**.
- **Strict** = (R1 + R2 + R3) / 64 = (23 + 8 + 11) / 64 = **42 / 64 = 65.6 %**.
  Partials are **not** in the numerator.
- Remaining 22 operational capabilities = 6 R4 + 1 R5 + 5 non-Analytics R6
  (C52, C53, C106, X03, X04) + 10 R11.

### 6.3 Partial-credit coverage
Formula: (R1 + R2 + R3 + ½ × R4) / 64 = (42 + 3) / 64 = **45 / 64 = 70.3 %**.

### 6.4 Broad advanced coverage (for continuity)
B / (A − R7 − R10) = 42 / 95 = **44.2 %**.

### 6.5 Weighted family score (historical comparability only)
Family classes at R2: EDA 15 (14 scored + SR-42), PARTIAL 8, DOMAIN 7,
POST_V1 1, DO_NOT_BUILD 4, OWNER 2 (SR-23, SR-31), LEGAL 5 = 42.
Changes from R1: SR-09 PARTIAL → EDA; SR-17 OWNER → PARTIAL. SR-18 stays
DOMAIN_FUTURE (the reference "activities" are dated events with co-curricular
skill grading — Examinations — not Lycenza's participation groups).

| Measure | Formula | R2 |
|---|---|---|
| Overall weighted | (EDA 14 + ½·8) / 37 | **18 / 37 = 48.6 %** |
| Overall strict | 14 / 37 | **37.8 %** |
| v1 weighted | 18 / (14 + 8) | **18 / 22 = 81.8 %** |
| v1 strict | 14 / 22 | **63.6 %** |
| Screen-weighted, overall | (62 + ½·110) / 298 | **117 / 298 = 39.3 %** (strict 62 / 298 = 20.8 %) |

The screen-weighted jump is dominated by the 52-screen group hub moving to
PARTIAL; it is not the decision metric.

### 6.6 Three-point comparison

| Measure | Original 318 audit (SIS.EXP-0) | SIS 11.R1 (after 11.5) | **SIS 11.R2** | Δ since R1 | Δ since EXP-0 |
|---|---|---|---|---|---|
| Implemented/equivalent/stronger | 15 | 39 | **42** | +3 | +27 |
| Owner decisions (R11) | 33 | 16 | **10** | −6 | −23 |
| Core operational, strict | 15 / 63 = 23.8 % | 39 / 64 = 60.9 % | **42 / 64 = 65.6 %** | +4.7 pts | +41.8 pts |
| Core operational, partial credit | 18 / 63 = 28.6 % | 41 / 64 = 64.1 % | **45 / 64 = 70.3 %** | +6.2 pts | +41.7 pts |
| Advanced (B / A−R7−R10) | 15 / 97 = 15.5 % | 39 / 95 = 41.1 % | **42 / 95 = 44.2 %** | +3.1 pts | +28.7 pts |
| Family, overall weighted / strict | 31.1 % / 21.6 % | 45.9 % / 35.1 % | **48.6 % / 37.8 %** | +2.7 / +2.7 | +17.5 / +16.2 |
| Family, v1 weighted / strict | 76.7 % / 53.3 % | 81.0 % / 61.9 % | **81.8 % / 63.6 %** | +0.8 / +1.7 | +5.1 / +10.3 |
| Screen-weighted overall | 19.8 % / 10.1 % | 29.5 % / 18.8 % | **39.3 % / 20.8 %** | +9.8 / +2.0 | +19.5 / +10.7 |

(The EXP-0 operational denominator was 63; R1 recomputed it as 64 when
capabilities were first split; R2 keeps 64.)

### 6.7 Score integrity
No capability definition changed; the denominator is identical to R1 (64,
and 95 for the broad measure). Every movement in §4 cites the shipped
checkpoint. Four movements *out of* R11 land in R4 or R6, not in the
numerator — the R11 drop (−6) is larger than the numerator gain (+3) by
design.

## 7. Pillar reconciliations

### 7.1 Communities (SR-17, 52 screens)

| Reference element | R2 status | Class |
|---|---|---|
| House catalogue | implemented (11.7) | met |
| House membership (history) | implemented, one current House | met |
| House responsibilities | Captain, Vice Captain, Prefect | met |
| Clubs | implemented (11.8) | met |
| Club membership | several at once, per-group uniqueness, history | met |
| Club responsibilities | President, VP, Secretary, Member Representative | met |
| Activities | implemented, closed broad category | met |
| Activity participation | several at once, history | met (dated events/attachments not) |
| Mentors | deferred (HR) | R6 |
| Skills / levels | deferred; co-curricular grading is Examinations | R6 / R9 |
| Schedules | Timetable | R9 (Timetable) |
| Badges | deferred (§7.3) | R6 |
| Awards | deferred (§7.3) | R6 |
| Projects | not authorized | owner decision (low value) |
| Scholarships | Finance (concessions/scholarships, FEE.3) | R9 |
| Custom Groups | not authorized (generic engine risk) | R7-adjacent / owner |
| System Tags (SR-19) | profiling of children | R7 |
| Student Segments (SR-20) | fee segments | R9 (Fees) |
| Sub Class Groups | practical batches / sub-cohorts | R9 (Timetable, SIS-022) |
| Group analytics (counts by group) | Analytics-gated | R6 (Analytics) |
| Demographic charts (gender split) | not adopted | R7 |
| Bulk group assignment | not adopted (rule 128 explicit only) | R4 residual / owner |

**Architecture: foundation complete.** Three closed types, one catalogue
writer, two membership writers, DB-enforced cardinality, effective-dated
history, no authority (`StudentGroupArchitectureTest` pins that no
authorization, tenancy, Campus, navigation, portal or API code reads Student
Groups). A new concept is a reviewed migration plus code plus an owner
decision — it cannot drift into authorization, segmentation, analytics or
free tagging. Remaining items are deferrals or other owners, not structural
gaps.

### 7.2 Mentor reassessment → **WAIT FOR HR**
Mentors must be *current staff* (`CurrentStaffMembers`, rule 108) and need
an HR-owned notion of staff role/assignment continuity (a mentor leaving
must end the link). HR is the recommended next ERP; building mentors now
would hand-roll staff lifecycle coupling inside SIS. Value: moderate
(co-curricular accountability), not urgent.

### 7.3 Participation recognition → **useful enhancement, not before another ERP**
Badges, awards and participation levels have real but **non-operational**
value (motivation, report-card colour). They overlap the Examinations
co-scholastic domain (SR-18, E40) and the governed media pipeline (badge
images). Classification: useful enhancement bordering cosmetic parity; do
not prioritise because the reference has screens.

### 7.4 Attendance (SR-28/29/30/31)

| Capability | R2 |
|---|---|
| Student history (status, Subject, range) | R1 |
| Class/Section history | R1 (register history) |
| Subject / session reporting | R1 (period + Subject) |
| Register activity (recorded by/at) | R1 (OD-SIS-10A) |
| Registers not taken | R1/R2 (submitted/pending/not submitted) |
| Corrections | R3 (closed absence-neutral reasons, append-only) |
| Daily exceptions | R2 |
| Monthly totals (typed) | R7 (no evidence) |
| Percentages / averages | R6 (Analytics gate, ADR 0040) |
| Trends | R6 (Analytics) |
| Biometrics | R8 |
| RFID | R8 |
| Examination attendance | R9 (Examinations) |
| Professional cohort reports | R7 (K-12) |
| Absence communications | R9 (Communications, E28) |

**Is everyday operational Student Attendance complete enough for v1
without Analytics, biometrics or Leave? YES.** Registers (office and
teacher), corrections, exceptions, registers not taken, Student and register
history are operational and Campus-safe. Not missing for v1: rates are an
Analytics decision. Owner-decision residual (not an SIS gap): a *daily,
non-period* register mode for Schools that do not take period attendance
(PV1-19 / ATT-08, Attendance-owned).

### 7.5 Bulk operations

| Operation | R2 |
|---|---|
| Student import | R3 (add-only, signed preview, atomic MFA commit) |
| Upload preview | R3 |
| Bulk Section assignment | R3 (11.13 bulk Section change; import placement) |
| Bulk Campus transfer | R3 (11.13) |
| Cross-year movement | R2 (Rollover: plan, dry run, execute) |
| Bulk inactive/reactivate | R10 (direct-with-assurance) |
| Bulk lifecycle approval | R10 |
| Bulk community assignment | not adopted (rule 128) — R4 residual of C50 |
| Bulk document operations | R7 (bulk ZIP of Highly Sensitive files rejected) |
| Bulk promotion | R2 (Rollover bulk decisions) |

**Import + 11.13 cover the meaningful competitor bulk workflow.** No import
rebuild is warranted. The only plausible residual is bulk House allocation
at year start (an owner decision against rule 128's "explicit only").

**ADR 0076 §84 reconciliation:** the later owner authorization (the SIS
11.13 brief, §42–44, §108) explicitly answered §44.25's open parity
question, and §84 records the supersession as rule 15 requires. Authority
stays the single-Student authority: Section change whole-School only; a
Campus actor transfers only between two Campuses both in scope, today,
under `CampusStudentTransferService`; execution re-reads authority fresh
(`holdsInSchoolNow` / `holdsInCampusNow` both Campuses). No capability,
Campus adoption or role changed (`BulkStudentOperationsArchitectureTest`
pins no `bulk` capability; the Campus-write route pin lists exactly the five
bulk routes). **No broader Campus bulk privilege was introduced.**

### 7.6 Documents (11.5 + H1)

| Item | R2 |
|---|---|
| Requirements | R2 (closed dimensions, immutable rules) |
| Compliance states | R1 (derived, never stored) |
| Applicant evidence | R1 (Campus-scoped with the application) |
| Student evidence | R1 |
| Verification | R1 (closed reasons, fresh MFA, final) |
| Rejection / replacement | R1 (supersede) |
| Conversion continuity | R3 (move, never copy) |
| Cohort checklist | R1 (states only) |
| Health exclusions | R8 (by design) |
| Identity documents | R8 (D-15) |
| Photos | R8 (DOC-002) |
| Document export | R7 (no bulk export of Highly Sensitive files) |
| Parent uploads | R11 (Portal) |

**Functionally strong enough for v1: YES** for staff-operated compliance.
Grouped counts (C35, C42) wait on a counts decision; parent self-service is
Portal.

### 7.7 Agreements → **NOT WORTH BUILDING NOW**

| Model | Status |
|---|---|
| Operator-recorded acknowledgement | buildable; low value without a paper-workflow policy |
| Click-through acknowledgement | Portal + OD-SIS-5 + wording review |
| Electronic acceptance (time + IP, the reference's function) | legally blocked |
| Electronic signature | legally blocked |
| Legally binding contract | legally blocked |
| Fee-gated promotion agreement | rejected (C100) |

### 7.8 Student Leave (D-16) → **BLOCKED**
Health data in remarks (C92 R8 as free text), Portal-led intimation, the
Attendance boundary (intimation never writes `attendance_records`; no
automatic `excused`), attachments (medical notes = health tier), approval
need undecided. Needs D-16 + the health-tier review + Portal scope.

### 7.9 Certificates

| Item | R2 |
|---|---|
| Transfer Certificate | built (SIS.EXP-4); production legally gated (wording) |
| Leaving Certificate | built; same gate |
| Bonafide | not in the pack; owner format/numbering decision + wording |
| Status certificate | not in the pack; owner decision |
| Character certificate | not in the pack; judgement content (discipline-adjacent, C97 R8) |
| Requisition | R2 (eligibility from the recorded exit) |
| Approval | not adopted (no queue needed) |
| Serial | R1 (forward-only per School and type) |
| Issue | R1 |
| Reissue | R1 (linked, new number) |
| Revoke / correct | R1 (void with closed reason + reissue) |

**Blockers:** TC/LC production = statutory/legal wording review (ADR 0081
§7, `StudentCertificateAvailability`). Bonafide/status = policy decision
(format, numbering) plus wording; no reference evidence. Character =
policy + privacy (judgement data). **No certificate slice is buildable
today with evidence**; the highest-value next step is the legal wording
review, not code.

### 7.10 Student 360 → **NO further SIS-only checkpoint**
Current cards (each read through its owner): identity, Guardians/family,
current placement and history, Subjects, Attendance, Fees, Transport,
Hostel, Library, Documents, House, Clubs & Activities, Certificates.
Waiting on other ERPs: Timetable personal view, Examinations results (E42),
HR class-teacher/mentor context, Finance family view (D-14).

### 7.11 Reporting (live registry dump)

| Key | Owner | Scope | Filters | Export / print |
|---|---|---|---|---|
| students.directory | Students | Campus-aware | name, number, status, Campus, Grade, Section | CSV + print |
| students.document_compliance | Students (Documents) | whole School | q, year, Campus, Grade, Section, category, status | browser-only |
| students.siblings | Guardians (sibling rule) | whole School | q, Campus, Grade, Section | browser-only |
| students.houses | Students (Houses) | Campus-aware | House, q, responsibility, Campus, Grade, Section | browser-only |
| students.clubs | Students (Communities) | Campus-aware | Club, q, responsibility, Campus, Grade, Section | browser-only |
| students.activities | Students (Communities) | Campus-aware | Activity, category, q, Campus, Grade, Section | browser-only |
| students.placements | Students (Enrollment) | Campus-aware | year, Campus, Grade, Section, status, number, name, roll | browser-only |
| students.movements | Students (Enrollment) | whole School | q, category, type, year, from, to, Campus, Grade, Section | CSV + print |
| students.section_capacity | Academic Structure · Students | Campus-aware | year, Campus, Grade, status | CSV + print |
| students.lifecycle | Students | whole School | q, action, reason, Campus, from, to | CSV + print |
| admissions.applications | Admissions | Campus-aware | name, status, year, Campus, Grade, created from/to | CSV + print |
| attendance.register_status | Attendance | Campus-aware | date, Grade, Section, status | browser-only |
| attendance.exceptions | Attendance | Campus-aware | date, Section, status | browser-only |
| attendance.registers | Attendance | Campus-aware | year, Campus, Grade, Section, Subject, from, to, recorded by, corrected | browser-only |
| attendance.corrections | Attendance | Campus-aware | q, year, Campus, Grade, Section, from, to, reason, corrected by | browser-only |

15 governed reports (7 governed dedicated pages + 8 rendered), 5 exporters.
**No obvious operational gap.** Observation: Attendance and community
rosters are browser-only by decision (Sensitive data needs its own dataset
capability before export); if Schools ask for a printable class roster
with House/Club, that is an export decision, not a missing report.

### 7.12 Export / print
CSV (formula-safe, capped 10,000) + print (1,000) on 5 reports remain
**sufficient for v1**. XLSX adds a library for no new data (CSV opens in
every spreadsheet); downloadable PDF duplicates print-to-PDF. Both stay R6.

### 7.13 Primary Guardian (C08) — **still missing (R5)**
- Current state: the directory (`StudentDirectoryController`, `students.directory`
  report) has no Guardian column (verified: no Guardian field in the
  directory controller, report or Vue page).
- Value: modest (E2) — who to call, at a glance; the full Profile already
  shows Guardians.
- Privacy/authorization: name only (never a contact value); whole-School
  `guardians.view` in addition to `students.view`; never on a Campus
  surface (rule 101: no Guardian data on Campus surfaces); never exported
  without a dataset decision.
- **Worth a dedicated checkpoint: NO** — too small to justify a slice ahead
  of the next ERP; bundle it with the next touch of the directory or a
  future SIS correction checkpoint.

## 8. Remaining capabilities

### 8.1 R5 — missing and still valuable (exactly **1**)
1. **C08** primary-Guardian (point-of-contact) column on the Student
   directory (§7.13).

### 8.2 R4 — partial (exactly **6**)

| Id | Exists | Remains | Why partial | Worth doing now |
|---|---|---|---|---|
| C35 | per-Student/cohort document states | grouped counts per class | counts decision (OD-SIS-6 style) | no |
| C49 | House, Club, Activity types | Project / custom types | not authorized; generic-engine risk | no |
| C50 | membership rules, history | bulk assign | rule 128 explicit only | no (owner may revisit for House year-start allocation) |
| C56 | Activity participation | dated events, attachments | events = scheduling; attachments = media/photo gate | no |
| C73 | capacity report + applications report | joined vacancy-vs-pipeline view | analytics-adjacent | no |
| C108 | LC/TC issue, register, void/reissue | production use | legal wording review (ADR 0081) | legal, not code |

### 8.3 R11 — owner decisions (exactly **10**)

| Decision | Capability | Options | Recommended | If undecided |
|---|---|---|---|---|
| OD-SIS-7 | C12 Student home address | adopt (classified field) / not | adopt later with classification; low urgency | no address on record (Guardian contacts only) |
| OD-SIS-8 (D-13) | C26 back-dated status changes | allow bounded back-dating / today only | today only (keep) | status changes stay effective today |
| OD-SIS-5 | C34 parent re-verification fields | Portal confirmation / none | defer to Portal programme | none |
| Portal | C38 parent update log | Guardian self-service writes / none | defer (Portal) | staff-entered changes only |
| Portal | C39 parent-uploaded documents | Portal upload / staff only | defer (Portal + malware scanning) | staff upload only |
| counts | C42 per-class applicant counts | allow operational counts / none | decide together with C35 | per-application lists only |
| SIS-020 | C67 class-wide family contact list | purpose-bound list / none | keep none until a purpose is named | contacts per Student only |
| D-16 | C90 leave intimation | Portal intimation / none | Portal-led, non-health catalogue | no Student Leave |
| D-16 | C91 leave types + notification | closed catalogue | with C90 | — |
| D-16 | C93 leave report | Attendance-owned report | with C90 | — |

### 8.4 Legal / privacy gates (not reopened)
Electronic acceptance (C37), certificate wording (ADR 0081), Leave/health
data (C92, D-16 health tier), biometrics/RFID (C80, HRX-L2), demographics
(C01, C13), government IDs (C14), photos (C64, C66), immunisation (C63),
discipline (C97), Student portal areas beyond the current Guardian portal.

## 9. Cross-ERP ownership and duplication

| ERP | Capabilities | SIS behaviour |
|---|---|---|
| Finance | dues by family, concessions, fee segments, scholarships, refunds, exit revenue | SIS reads nothing financial; certificates never blocked by dues |
| HR | mentors, class-teacher context, staff workload | SIS uses `CurrentStaffMembers` only where needed (Communications); mentors deferred |
| Timetable | schedules, sub-class groups, practical batches | Attendance reports read the register's stored period only |
| Examinations | co-curricular skills, exam attendance, results | none in SIS |
| Transport / Hostel | rider and residency records | only as transfer *blockers* (read through their `StudentTransferBlocker`); bulk moves never mutate them |
| Communications | absence messages, reminders | none in SIS |

**Does SIS duplicate another ERP's authoritative truth? NO.** Communities
are SIS-owned descriptive data; capacity is Academic Structure's single
column; Attendance reports read Attendance's own tables through its read
path; the bulk service writes only placements through the Students
primitives.

## 10. Engineering review

### 10.1 Schema growth since core closure (`c5e3dd23..562ee2c8`)

| Migration | Structures | Owner | RLS / `down()` |
|---|---|---|---|
| `2027_01_20_090000_guard_section_advisory_capacity` (11.3) | `sections` capacity CHECK + closed-year trigger | Academic Structure | existing RLS / yes |
| `2027_01_21_090000_add_document_compliance` (11.5) | `documents` applicant owner arm; `student_documents` verification; new `applicant_documents`, `student_document_requirements` | Documents / Students / Admissions | `TenantRls::enable` / yes, refuses when evidence exists |
| `2027_01_22_090000_harden_applicant_document_integrity` (11.H1) | `applicant_documents_document_fk (document_id, school_id)`, owner trigger | Documents / Admissions | — / yes |
| `2027_01_23_090000_create_student_groups_tables` (11.7) | `student_groups`, `student_group_memberships`, `student_group_responsibilities` | Students | RLS on all three / yes |
| `2027_01_24_090000_extend_student_groups_to_clubs_and_activities` (11.8) | types, per-group unique index | Students | unchanged / yes, refuses when Club/Activity rows exist |

11.1, 11.2, 11.4, 11.10 and 11.13 added **no** migration. Every structure
has a clear owner; none duplicates another ERP's data. INFO:
`student_document_requirements.subject` holds both `student` and
`applicant` rules (a Students table serving Admissions compliance, read
through `DocumentCompliance::forApplication`) — a documented seam, not a
duplicate.

### 10.2 Capability growth
11 keys since core closure, all classed `children`: `students.export`,
`admissions.export` (school_admin only, never Campus-adopted);
`students.documents.verify` (school_admin, principal; whole School);
`students.houses.view` and `students.communities.view` (Campus-adopted
reads); `students.houses.manage/.assign`, `students.communities.manage/
.assign` (whole School); `admissions.documents.view/.manage` (school_admin,
principal, admissions_officer; Campus-adopted with the application). 11.10
and 11.13 added none. **No accidental role expansion**: admissions_officer
gained only pre-admission document keys; the staff catalogue is still 17
roles (`StaffRoleCatalogueSnapshotTest`).

### 10.3 Route growth since R1 (web only; API unchanged)
Houses, Communities (hub, Clubs, Activities, one Student's memberships and
responsibilities) and Bulk operations. Every route is either
`capability:` middleware or a `SchoolRouteCoverage` entry; whole-School
Student pages are declared operations. No duplicated workflow. LOW naming
drift: `house.show` (singular) vs `houses.index`; `communities.index` is the
hub (includes Houses) while `communities.show` is one Student's Clubs and
Activities; `communities.leave` maps to `/end`; responsibility assign and
end paths are asymmetric. LOW authorization *shape* inconsistency: the
Student document decision route carries `capability:students.documents.verify`
middleware, the applicant decision route relies on its coverage floor
(`admissions.documents.view`) plus the service's `verify` check — both
correct, not uniform.

### 10.4 Security review (Advanced SIS)

| Level | Findings |
|---|---|
| HIGH | **none** |
| MEDIUM | **none** (security) |
| LOW | S-1: the bulk **review GET** re-runs the preview — real primitives, row locks and audit/outbox inserts inside a transaction that is always rolled back (`BulkStudentPlacementService` preview). Nothing persists and no non-transactional side effect exists, but a GET takes locks; a rate limit or POST-only re-check would be tidier. S-2: route naming and decision-route authorization shape (§10.3). |
| INFO | Every rendered report reads through the viewer's resolved scope / `StudentAccess` (no hand-built `CampusScope` — guarded); exports need the whole-School dataset key and never exceed the screen; document reads `mfa-page`, writes fresh MFA; Student Groups referenced by no authorization, tenancy, navigation or portal code; bulk moves only through the primitives with fresh authority and Student locks; roster responsibility lookups omit an explicit `school_id` predicate but run under RLS on scoped ids; print GET audits per load (R1 L-5, unchanged); `AttendanceCorrectionReport` joins `students`/`users` directly under `attendance.view` scope (OD-SIS-10A); Club `assignResponsibility` takes no version (DB unique indexes decide races). |

**Privilege leak: NO. Tenant-isolation issue: NO. Campus isolation:
sound** (Campus surfaces read through `StudentAccess`; Campus bulk moves
only between in-scope Campuses).

### 10.5 Performance

| Area | Evidence | Concern |
|---|---|---|
| Report pages | bounded ≤ 50 per page; one joined query (11.10 pinned: 10 registers on 5 dates ≤ 2 dates' queries) | none |
| Export | OFFSET chunks of 500, capped 10,000 | low–medium (R1 §8.7, unchanged) |
| Capacity | `sectionStrengthQuery` aggregates the School's non-closed Sections | low |
| Compliance matrix | four EXISTS per cohort Student | low–medium at School scale |
| Community rosters | one roster query (shared) | low |
| Bulk preview / execute | 100 Students ≈ 1.1 s / 1.1 s; 18 statements per Student; fixed part ≤ 30 (pinned) | none at the 100 cap |
| Attendance report filter options | `AttendanceCorrectionReport::options()` / `AttendanceRegisterHistoryReport::options()` derive year, Campus and Grade with semi-joins over **all in-scope `attendance_records`/sessions**, uncached, on every page view | **MEDIUM (likely, unmeasured)**: a School-year can hold millions of records; measure, and derive options from sessions or reference tables if slow |
| Compliance cohort | `DISTINCT ON` over the year's placements + per-category EXISTS; counts scan the cohort | low–medium (R1, unchanged) |

No measured performance defect. **Bulk performance is sufficient:** a Section rarely exceeds 60 Students;
the execution holds two Section row locks for about a second (Attendance
submission for those two classes waits that long).

### 10.6 Concurrency
Protected: Student/Guardian profile (keyed versions), Campus name edit,
Section edit and relationship edit (required tokens, rule 127), API PATCHes,
every document write (integer version), verification (current version, MFA),
House/Club/Activity membership and responsibility writes (versions, Student
lock FOR NO KEY UPDATE), placements (Section-before-Enrollment, ascending
Section ids), bulk movement (placement-id binding, signature, Students FOR
SHARE in id order, all-or-nothing; two-process proofs), import (content
digest unique), certificates (Student lock, forward-only counters).
Remaining last-write-wins: reference data only (year/term rename, Grade,
Subject, Department, Room, Offering, Campus, School profile; R1 §8.5 "low").

### 10.7 Test reliability and first-candidate record

| Checkpoint | First candidate |
|---|---|
| 11.1 | failed (route-parameter classification) |
| 11.2 | passed |
| 11.3 | failed (same-second audit tie) |
| 11.4 | failed (capability count pin) |
| 11.5 | failed (user-reference pins; hidden by the DDEV Identity gap) |
| 11.H1 | failed (pre-existing unordered-journal flake, fixed in the helper) |
| 11.7 | failed (Student 360 card pin → checklist §9a) |
| 11.8 | passed |
| 11.10 | passed |
| E16-R3/S2 | passed |
| E16-R4 | passed |
| 11.13 | passed |

Five consecutive first-candidate passes since the checklist gained §9a/§9b.
Each earlier miss maps to a checklist line now in force. 11.13's local
catches (Campus-scope construction, current-placement definition, Campus-
write pin, one class per test file, IST "today") were found **before** the
candidate by the guards — the discipline is stable.

Known reliability items, all closed: DDEV Identity `APP_URL`, object-storage
classification, audit ordering, first-candidate pins (checklist), stale
frontend assets (§9b). New pattern note: tests comparing "School-local
today" with UTC `now()` flake after 18:30 UTC; use `SchoolTimezone::today()`.

### 10.8 Regression cadence
Counter **0/5** after 11.13's exact-SHA full suite (10,318 cases). The next
checkpoint starts at **1/5**; every code-lane candidate still runs the full
sharded suite remotely.

### 10.9 UX / navigation
The "People" group holds 13 entries: 8 SIS (Admissions, Students, Guardians,
Enrollments, Student movements, Student reports, Import Students,
Enrollment rollovers) and 5 HR. Bulk operations, Communities and the
document checklist open from the Students page and the reports hub, not the
menu. No duplication defect, but the group will crowd as HR grows —
consider splitting "Students" and "Staff" groups when HR expands
(informational). Student movements appear both as a menu item and as a
governed report (same page; acceptable). Student 360 has 13+ cards; absent-
not-hidden keeps each viewer's set smaller.

### 10.10 Accessibility / mobile
Core SIS UAT (SIS 9.1, 10.1 rerun PASS); Documents, Communities, Attendance
reports (11.10: 390 px on six pages, keyboard filters) and Bulk operations
(11.13: 390 px, keyboard-only move, labels, live count; one sr-only
overflow found and fixed) all have browser evidence. **No significant
remaining accessibility gap.**

## 11. Competitive comparison

| Pillar | Reference | Lycenza current | Gap | Lycenza advantage |
|---|---|---|---|---|
| Student Master | ~150-field personal schema | identity, one Guardian model, encrypted contacts | address (OD-SIS-7); demographics (legal) | minimization, keyed stale-form, precise audit |
| Guardians | parent blocks | typed relationships, flags, contacts, self-administration guard | — | conflict guards, encrypted contacts |
| Admissions | applications, docs, concessions | applications, corrections, conversion, documents, verification | per-class counts; concessions are Finance | duplicate-safe conversion, document continuity |
| Enrollment | placements | frozen placements, transfer, pre-start cancel | — | DB-frozen history |
| Movement | shuffles + log | single + bulk moves, movement register | cross-year in bulk (Rollover by design) | blockers, both-Campus authority, all-or-nothing |
| Academic rollover | promotion lists | plan → dry run → execute | — | staleness-safe execution |
| Reporting | many dashboards | 15 governed reports | analytics views (gated) | closed catalogue, privacy-classed columns, Campus scope |
| Export / Print | Excel, PDF, print | CSV + print on 5 | XLSX / PDF (low value) | per-dataset capability, formula-safe, audited |
| Capacity | strength matrices | advisory capacity, strength, overage | trends (Analytics) | one predicate, never blocks |
| Documents | checklist, verify, parent contracts | requirements, verify, checklist, applicant continuity | parent upload; e-acceptance (legal) | MFA, closed reasons, move-not-copy |
| Communities | 9-tab group hub with gender analytics | House, Club, Activity, responsibilities, rosters | mentors, badges, projects, bulk assign | no authority, no profiling, history |
| Attendance | 10-tab reports with % | registers, status, exceptions, history, register history, corrections | rates (gated), daily mode (owner) | registers are the evidence; recorder accountability |
| Bulk operations | import, bulk transfer, bulk approvals | import + bulk Section/Campus moves | bulk approvals (not adopted) | signed preview, atomic, MFA, concurrency-proven |
| Certificates | TC requisition | LC/TC issue, void, reissue, register | production legal gate | immutable numbering, never dues-blocked |
| Agreements | e-acceptance with IP | none | legal | — |
| Leave | parent intimation, free text | none | D-16 / health | Attendance boundary defined |
| Student 360 | scattered tabs | one assembled profile over owners | Timetable / Exams / HR cards | absent-not-hidden |

**Competitive quality** (beyond screen counts): DB-enforced integrity
(frozen placements, immutable rules, certificate numbering, community
cardinality), append-only audit, RLS tenant isolation, Campus authorization
by construction, MFA on Highly Sensitive reads/writes, stale-form and
two-process race proofs, explicit domain ownership, accessibility evidence,
governed exports.

### 11.1 Lycenza strengths (concrete)
Placement immutability (`student_enrollments_freeze_placement`), atomic
signed bulk operations, document move-not-copy with verification MFA,
closed-reason lifecycle with DB evidence, report catalogue with privacy-
classed columns, Communities that can never become authority
(`StudentGroupArchitectureTest`), Campus-safe reads (`StudentAccess`), RLS.

### 11.2 Remaining weaknesses (business need still unsolved)
1. Attendance **percentages/monthly grid** — real need, gated on Analytics
   (ADR 0040 D-01..D-04).
2. **Student Leave** — real need, D-16/health/Portal.
3. **Parent self-service** (uploads, data re-verification) — Portal.
4. **Count dashboards** (documents per class, status KPIs) — counts decision.
5. **Certificates in production** — legal wording.
6. **Primary-Guardian column** — small, ungated.
7. **Bulk House allocation** — owner decision vs rule 128.

### 11.3 Gap severity

| Gap | Severity |
|---|---|
| Primary-Guardian directory column (C08) | MINOR |
| Attendance percentages / monthly grid | LEGAL/ANALYTICS → DECISION |
| Daily (non-period) attendance mode | DECISION (Attendance owner) |
| Student Leave | DECISION + LEGAL (health) |
| Agreements / e-acceptance | LEGAL |
| Certificates production wording | LEGAL |
| Bonafide / status certificates | DECISION |
| Parent uploads / data re-verification | DECISION (Portal) |
| Count dashboards (C06, C35, C42) | DECISION |
| Vacancy vs pipeline (C73) | OPTIONAL |
| Mentors | OTHER ERP (HR first) |
| Badges / awards / levels | OPTIONAL |
| Projects / custom groups | OPTIONAL |
| Bulk House allocation | DECISION |
| XLSX / PDF | OPTIONAL |
| Address (OD-SIS-7), back-dating (OD-SIS-8), family contact list | DECISION |
| Finance/Timetable/Exams/HR cards | OTHER ERP |

**BLOCKER gaps open: NO. MAJOR gaps open: NO.**

## 12. Decisions

- **V1 SIS readiness: YES WITH NON-BLOCKING GAPS.** Every everyday
  operational workflow (admissions → conversion → placement → movement →
  rollover → exit/certificates; documents and compliance; attendance
  operations; communities; governed reporting and export; bulk moves) is
  implemented, browser-proven and protected. Remaining gaps are decisions,
  legal gates, other ERPs or optional parity.
- **Advanced SIS closure: A — Close Advanced SIS for now.** No BLOCKER or
  MAJOR gap; the single R5 item fails the §86 "more important than the next
  ERP" test; no real defect requires hardening (§87).
- **Remaining SIS action:** none as a checkpoint. Carry C08 (primary
  Guardian) as a small item for the next directory touch; record the owner
  decisions in §8.3 for when Portal/Analytics/legal answers arrive.
- **Next ERP: HR** (§13).

## 13. Next ERP and sequence

Ranking by dependency value, leverage, value, integration, readiness,
legal readiness, reference readiness and risk:

| Rank | Phase | Rationale |
|---|---|---|
| 1 | **HR ERP** | Recorded owner recommendation (`SIS-EXPANSION-CLOSURE.md` §11; MASTER-ROADMAP). Deepest cross-ERP fan-out (Teaching ownership, Leave, Staff attendance, Payroll → Finance, Communications Staff audiences, Transport drivers, Inventory issue to Departments, future SIS mentors/class teacher). HR.EXP-0 repo-side audit published (`3a54892c`); a dedicated **HR pack of 335 PNGs** now exists (captures dated 10-30 to 11-01 — possibly still in progress); `HR-REFERENCE-CAPABILITY-MAP.md` not yet built. Development is not legally blocked (HRX-L1–L4, PAY-L1/ESI gate production only); OD-HR-1 (S-1, Payroll checker approves what it cannot see) is the one P0 owner decision before Payroll work |
| 2 | **Finance ERP** | Strong leverage (Payroll posting, OPF fee seams, future procurement); most v1 build done (FEE.0–5, OPF.0–5, ORG.8C/8E, ORG.9B); production gated on E30–E32/E21 legal answers, and D-14 limits cohort money views; pending Finance captures need re-indexing first. Candidate targets: trial balance, cash/bank book classification (stopped in ORG.9B), dues ageing within D-14 |
| 3 | **Inventory ERP** | No legal gate, clean ledger (rules 105–106), 120 reference screens; procurement and assets need owner decisions and fit after Finance payables. Transport is a close fourth: thinnest evidence (51 screens, no observed workflow) and its headline features (GPS, parent messaging) are legal/E28-gated |

**Organization** is complete and is not a dependency to reopen.

**E16 scheduling:** the recent cadence is several checkpoints per day
(11.7, 11.8, 11.10, E16-R3/S2, E16-R4, 11.13 within ~36 hours). Between now
and the review window (~2026-11-20) several checkpoints fit comfortably, but
the window itself (11-20 → 11-28) should be reserved for E16-S3 work, so a
large schema-heavy HR wave should be sequenced to reach a publishable
boundary before ~11-20 (e.g. HR reference map + HR.HARDEN-0 + one HR.EXP
slice), not span the window.

**Local / staging:** the programme stays local (owner direction 2026-10-17).
Architecturally the application looks ready for a future product-wide local
UAT before staging (SIS 96/100 core, ORG closed, Advanced SIS closed) once
HR's first wave lands; not run here.

## 14. New findings

| # | Severity | Area | Blocks closure | Recommended action |
|---|---|---|---|---|
| R2-F1 | INFO (doc drift) | `SIS-11-R1-MID-EXPANSION-AUDIT.md`: the "SIS 11.8 reconciliation" heading opens with the SIS 11.10 paragraph | no | fix in the next SIS docs touch |
| R2-F2 | LOW (doc drift) | `docs/hr/HR-LEGAL-SECURITY-GATE-REGISTER.md` still states E16 last valid day 2026-11-12 (current: 2026-11-28) | no | refresh at HR.EXP-0 resumption |
| R2-F3 | INFO (test pattern) | IST "today" vs UTC `now()` comparisons flake after 18:30 UTC (found in 11.13) | no | add to the cross-cutting checklist at the next touch |
| R2-F4 | INFO (UX) | "People" navigation group (13 entries) will crowd as HR grows | no | consider Students/Staff split during HR |
| R2-F5 | MEDIUM (performance, likely) | Attendance report filter options scan all in-scope attendance records per view (§10.5) | no | measure at realistic volume; derive options from sessions/reference tables (fold into the next Attendance or SIS touch) |
| R2-F6 | LOW (security hygiene) | bulk review GET runs the rolled-back trial (locks + rolled-back inserts) on every visit (§10.4 S-1) | no | consider a throttle on the review route |
| R2-F7 | LOW (consistency) | Communities/House route naming drift; applicant vs Student document decision route authorization shape (§10.3) | no | normalise at the next Communities/Documents touch |
| R2-F8 | INFO (doc drift) | `SIS-11-2-OPERATIONAL-REPORTS.md` still calls SR-21 deferred (superseded by 11.5); `SIS-11-1-REPORTING-FOUNDATION.md` "SIS 11.2 not started"; `SIS-11-8` "## 9a" heading; a `CampusScopeCatalogue` comment now sits above unrelated 11.5/11.7/11.8 entries | no | docs/comment tidy at the next touch |

## 15. Exact next checkpoint

**HR.EXP-0R — HR reference-pack capability map & HR next-wave decision
(read-only)**: confirm the HR pack is complete, fingerprint it, build
`HR-REFERENCE-CAPABILITY-MAP.md` against the already-published HR.EXP-0
current-state audit, and ask OD-HR-1 (S-1). Not started; needs owner
authorization.

## 16. Questionnaire

| Q | Answer |
|---|---|
| Q1 | **114** (unchanged R1 inventory) |
| Q2 | **23** R1 |
| Q3 | **8** R2 |
| Q4 | **11** R3 |
| Q5 | **6** R4 |
| Q6 | **1** R5 (C08 primary Guardian column) |
| Q7 | **7** R6 |
| Q8 | **17** R7 |
| Q9 | **10** R8 |
| Q10 | **19** R9 |
| Q11 | **2** R10 |
| Q12 | **10** R11 |
| Q13 | **YES**: 23+8+11+6+1+7+17+10+19+2+10 = 114 |
| Q14 | **42 / 64 = 65.6 %** |
| Q15 | **45 / 64 = 70.3 %** ((42 + ½×6) / 64) |
| Q16 | +41.8 pts strict (23.8 % → 65.6 %) |
| Q17 | +4.7 pts strict (60.9 % → 65.6 %) |
| Q18 | **NO** |
| Q19 | **NO** |
| Q20 | **YES** |
| Q21 | **YES** |
| Q22 | **PARTIAL** (foundation complete; mentors/badges deferred, projects/custom not authorized) |
| Q23 | **YES** (staff-operated); parent self-service is Portal |
| Q24 | **YES** for SIS-owned operational reporting; analytics gated |
| Q25 | **NO** |
| Q26 | **NO** |
| Q27 | **NOT NOW** |
| Q28 | **BLOCKED** |
| Q29 | **NO** (TC/LC built; production waits on legal wording; other types lack evidence and a format decision) |
| Q30 | **NO** |
| Q31 | **YES**, but MINOR — not worth its own checkpoint |
| Q32 | **NO** |
| Q33 | **NO** |
| Q34 | **NO** |
| Q35 | **NO** measured issue; one likely MEDIUM concern to measure (R2-F5) |
| Q36 | **NO** (reference-data last-write-wins only, low) |
| Q37 | **YES WITH NON-BLOCKING GAPS** |
| Q38 | **YES** |
| Q39 | — |
| Q40 | **HR ERP** |
| Q41 | HR → Finance → Inventory (Transport fourth) |
| Q42 | **HR.EXP-0R — HR reference-pack capability map & next-wave decision (read-only)** |
