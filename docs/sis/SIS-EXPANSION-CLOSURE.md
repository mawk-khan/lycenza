# SIS expansion programme — closure record (SIS.EXP-CLOSE)

| | |
|---|---|
| Closure date | 2026-10-30 |
| Final code baseline | `d44cca48` (SIS.HARDEN-1), exact-SHA `CI gate` run 41098743785: **10,144 tests, 10,143 passed, 1 skipped (ESI-12), 0 failed**; regression counter **0/5** |
| Closure checkpoint | documentation and audit only. No code, migration, capability, role, Campus, API or UI change |
| Status | **SIS EXPANSION PROGRAMME CLOSED.** This is not production readiness, not Student Portal readiness and not any legal approval (§9) |

Companions:
- `SIS-EXP-0-CURRENT-STATE-AUDIT.md` (baseline);
- `SIS-EXP-R1-POST-P1-AUDIT.md` (post-P1 re-audit and the residual register);
- `SIS-REFERENCE-CAPABILITY-MAP.md` §5.7 (final map);
- `SIS-EXPANSION-ROADMAP.md`;
- `docs/demo/SIS-EXP-1-WALKTHROUGH.md` §9 (final owner path).

## 1. Delivered programme

| Checkpoint | Main | Result |
|---|---|---|
| SIS.EXP-0 full audit | `0e885dfb` | 318 screens in 42 families; three P1 gaps, all owner-gated |
| SIS.EXP-1 Student 360 & Directory | `75e1bbbd` | Student 360 (family/siblings and owner cards for Subjects, Attendance, Fees, Transport, Hostel, Library); Campus/Grade/Section directory filters; Student/Guardian `school_id`/`status` not mass assignable; keyed stale-form token on both Student identity edits; precise `student.updated` |
| SIS.EXP-2 Movement & Exit Register | `380c5d11` | A read-only, whole-School projection: entry/movement/exit, promotion and repeat, Section and Campus movement, closed reasons. HARDEN-1 added outside-year semantics |
| SIS.EXP-3 Student Documents | `6aab4eb8` | Student-owned documents on the one Documents repository: private storage, four closed categories, list/upload/attachment download/metadata edit/final archive, Highly Sensitive (current MFA to read, fresh code to write), a Student 360 count card |
| SIS.EXP-4 Leaving & Transfer Certificates | `ef3cf0e9` | LC and TC from the recorded exit: immutable bounded snapshot, forward-only per-School numbering, private generated PDF as a Student document, void and linked reissue, Student 360 history; Finance never blocks. **Production legally gated** (§6) |
| SIS.EXP-5 Bulk Student & Guardian Import | `74b28b93` | Add-only CSV of Students, Guardians, relationships and first placement: signed 30-minute preview, atomic commit after fresh MFA, idempotent digest claim |
| SIS.EXP-R1 Post-P1 re-audit | `e877efff` | P1 closure confirmed; OD-SIS-5 → legal review first; residual register R-1…R-13 |
| SIS.HARDEN-1 | `d44cca48` | Closed R-1 (Guardian stale edit + precise audit), R-3 (Enrollment mass assignment), R-4 (outside-year events), R-5 (directory Section options), R-6 (siblings authorized before the limit), R-7 (document archive conflict) |

Every original P1 gap is delivered: documents, certificates and import.

## 2. Final reference map (42 families, EXP-0 method)

| Class | Families | Ids |
|---|---|---|
| EXISTING_EQUIVALENT | 0 | — |
| EXISTING_DIFFERENT_ACCEPTED | 12 (incl. SR-42) | SR-02, 06, 08, 10, 12, 20, 32, 33, 34, 36, 37, 42 |
| PARTIAL | 7 | SR-03, 04, 09, 21, 25, 29, 41 |
| **SIS_BUILD** | **0** | — |
| DOMAIN_FUTURE | 8 | SR-11, 14, 15, 16, 18, 26, 27, 39 |
| PLATFORM_FUTURE | 0 | — |
| POST_V1 | 1 | SR-40 |
| DO_NOT_BUILD | 4 | SR-07, 19, 30, 38 |
| OWNER_DECISION_REQUIRED | 5 | SR-13, 17, 23, 24, 31 |
| LEGAL_DECISION_REQUIRED | 5 | SR-01, 05, 22, 28, 35 |

**Coverage** (unchanged formula; EXP-0 → close):

| Measure | EXP-0 | Close |
|---|---|---|
| Overall weighted | 31.1 % | **39.2 %** |
| Overall strict | 21.6 % | **29.7 %** |
| v1 weighted | 76.7 % | **80.6 %** |
| v1 strict | 53.3 % | **61.1 %** |

**Reference breadth is not SIS quality.**
- The 39 % measures how much of a competitor's Student surface Lycenza
  reproduces.
- 127 of its 298 scored screens are owner- or legal-gated, mostly
  demographic profiling and a generic group hub. Another 68 belong to other
  domains.
- Lycenza's own SIS score stays **96/100** against Lycenza's scope
  (`docs/product/SIS-FINAL-LOCAL-UAT-2026-10-28.md` §8). The missing 4 points
  are owner decisions and optional polish: the Student-number case policy and
  the Guardian status-reason catalogue (owner decisions), optional rollover
  bulk actions, Teacher My Classes, two date-wording items and one
  documentation pass. None is a defect introduced by the expansion. The
  expansion was not re-scored into that number.

## 3. Remaining PARTIAL families

| SR | Missing portion | Owner | Gate | Blocks closure? |
|---|---|---|---|---|
| SR-03 directory | Primary-Guardian ("point of contact") column | SIS post-v1 (P3) | owner authorisation | No |
| SR-04 master record | Home address (OD-SIS-7); demographics, blood group, income | Owner; Legal (D-15) | owner / legal | No |
| SR-09 Campus transfer | Bulk transfer | SIS post-v1 (ADR 0076 §44.25) | — | No |
| SR-21 documents | Statutory identity and medical categories; per-class checklist; Portal sharing; verification/expiry | Legal; Owner; Portal; Documents | legal / owner / domain | No |
| SR-25 siblings | School-wide sibling list (SIS-019/PV1-17); family dues (D-14) | Owner; Analytics/Finance | owner / D-14 | No |
| SR-29 attendance reports | Daily (non-period) model, mark-all, rates | Attendance (rates after the Analytics gate) | domain | No |
| SR-41 certificates | Production wording; non-Latin scripts; other certificate types | Legal; Owner | legal / owner | No |

## 4. Open owner decisions

| Id | Decision | Recommendation |
|---|---|---|
| OD-SIS-4 | House or club (D-17) | Wait for a pilot to name one concept; never a generic group engine |
| OD-SIS-5 | Annual record confirmation | **LEGAL_REVIEW_REQUIRED** first. Cross-domain: Portal (Guardian action), consent/Documents (terms acceptance), Admissions/re-enrollment (no fee-gated promotion), and a SIS confirmation record only if later authorised. Not SIS_BUILD |
| OD-SIS-6 | Section headcount next to advisory capacity | Capacity stays **advisory** (`sections.capacity`, read by no admission, enrollment, import, transfer or rollover path); display only if approved |
| OD-SIS-7 | Student home address | Not until a named purpose |
| OD-SIS-8 | Back-dated lifecycle | Keep current rules (status changes dated now; placement ends within the year, never in the future) |
| OD-SIS-9 | Cross-School transfer | **Do not build as a direct tenant-to-tenant transfer.** Source exit + Transfer Certificate + destination admission |
| OD-SIS-11 | Student-number case policy | Case-insensitive uniqueness for new numbers |
| OD-SIS-12 | Guardian status-change reason | A closed reason, like Student status |
| OD-SIS-13 | Student merge | **Do not build for v1.** `duplicate_record` deactivation and manual resolution |
| OD-SIS-14 | Admissions/Registrar import authority | Keep `students.import` School-Admin-only; the Admissions Officer creates Students through conversion; no Registrar role exists or is proposed |
| SR-23 (SIS-020 + D-11) | Class-wide family contact list and export | Owner decision; export is D-11 |
| SR-25 (SIS-019 / PV1-17) | School-wide sibling list | Owner decision |
| SR-31 (D-16) | Parent-submitted Student leave | D-16 pilot requirement; health-tier review |
| SR-03 remainder | Primary-Guardian directory column | P3, non-blocking |

## 5. Open legal decisions

| Item | Development | Production | Condition |
|---|---|---|---|
| EXP-4 certificate wording (ADR 0081 §7) | local/testing only, generic wording | **refused in code** (`StudentCertificateAvailability`, no flag) | legal approval of jurisdiction/format, title and statement, field list, reason labels, signatory and seal, non-Latin handling; then a reviewed code change |
| Student Portal (OD-SIS-10; POR-L1 Q16–Q21, E46) | Guardian portal built for development; **no Student portal** | No | POR-L1, E46. The Guardian portal is not Student Portal parity |
| Statutory identifiers (SR-05, D-15) | not stored | No | named statutory purpose, lawful basis, masked reveal, retention |
| Medical / immunisation (SR-21 part) | no category | No | health-tier review (DPO) |
| Annual record confirmation (SR-13) | not built | No | legal review of electronic acceptance, plus POR-L1 |
| Biometric / RFID (SR-28, HRX-L2) | No | No | privacy review |
| Demographics (SR-01), photographs (SR-22), discipline (SR-35) | No | No | D-15 / DOC-002 / DPO |

**The certificate legal gate:**
- **Generic:** the template title, statement and field list are
  development wording.
- **Not approved:** any board-prescribed or government (jurisdiction-specific)
  wording, signatory and seal requirements, and non-Latin script handling.
- **Blocked:** issue, void and reissue in production.
- **Required approval:** the legal review recorded in
  `docs/modules/STUDENT-ENROLLMENT.md`, "Leaving and Transfer certificates".

## 6. Transferred work (no floating gaps)

| Destination | Work |
|---|---|
| **SIS post-v1** | bulk Campus transfer (SR-09); primary-Guardian directory column (SR-03); hobbies (SR-40); R-14 (§7) |
| **Portal** | Student Portal; Guardian document sharing; the Guardian side of annual confirmation |
| **Admissions** | applicant documents and pre-admission concessions (SR-14); re-enrollment confirmation coupling |
| **Attendance** | daily model, mark-all, rates after the gate (SR-29); parent leave (SR-31, with D-16) |
| **Analytics** | composition, strength, exit revenue, fee strength, branch strength, admission analytics (SR-01, 11, 24, 26, 27, 39) |
| **Finance / Fees** | fee-discount agreements (SR-15); family dues (D-14) |
| **Timetable / Examinations** | timetable structure (SR-16); co-scholastic activities and skills (SR-18) |
| **Documents / platform security** | **EXIF stripping, PDF active-content sanitisation, malware scanning** (all owners, incl. HR and Communications); verification/expiry |
| **Legal** | every §5 item |
| **Test infrastructure** | each local Students-suite run leaves committed fixture Schools in the shared local test database (Platform Registry tests then page past them locally; CI builds a fresh database per shard and is unaffected; `ddev test --reset-db` clears it) |
| **HR/Payroll testing** | `PayrollUiTest` can fail at random: it searches the raw payslip payload for the substring `pf`, which a fake email can contain |
| **Do not build** | higher-education programmes (SR-07), derived family-circumstance tags (SR-19), typed monthly attendance (SR-30), principal diary (SR-38); direct cross-School transfer; v1 Student merge |

## 7. Security and residual findings

**Final integrated security review** (independent, read-only, at
`d44cca48`, across Student master, Guardian relationships, Student 360,
movement, Documents, certificates and import):
- **HIGH: none. MEDIUM: none.**
- **Confirmed:**
  - no cross-School Student IDOR;
  - no Campus scope escape;
  - no Network or Platform (incl. elevation) Student access;
  - no Guardian-to-Staff escalation;
  - no presentation-as-permission;
  - no public, signed or share Student document URL;
  - no production partner Student scope (`PartnerScopeRegistry::PRODUCTION = []`);
  - no merge or cross-School loophole.

**Intentional residuals:**

| # | Residual | Disposition |
|---|---|---|
| R-2 | The Student API PATCH has no stale-form token (last write wins). The **web** Student edits do have one (`StudentProfileVersion`) | **Deferred** until a partner Student write scope, a mobile Student write client or another production API consumer exists |
| R-8 | Certificate/document history lists rely on the page's capability floor | Accepted INFO (no bypass found) |
| R-10 | Certificate issuance serializes per School during PDF render (~0.1 s) | Accepted trade-off (the number is printed) |
| R-12 | Cascading School keys on `students`/`guardians` | Informational; the runtime role can never delete a School (rule 86) |
| **R-14 (new, LOW)** | Certificate issue/void/reissue check whole-School `students.view` before the transaction (through the capability cache) and re-read only `students.certificates.issue` fresh under the Student lock. CLAUDE.md rule 121 said both were re-read; its wording is corrected to the code | **SIS post-v1** (a one-line `holdsInSchoolNow` per entry point with a test). Impact is minimal: both keys sit on the same two roles, a fresh MFA code is required, and revocation invalidates the cache |
| INFO | A certificate form-key replay happens after the cached Gate, before the fresh re-read; the import preview can confirm a possible existing Student to a `students.import` holder (School Admin only); the certificates page has no `mfa-page` (the snapshot holds no DOB, Guardian or Finance data; downloads keep `mfa-page`); the Guardian detail lists linked Students under `guardians.view` (pre-expansion) | Accepted INFO; revisit if `students.import` or the certificate key ever reaches another role |

## 8. Architecture, tenancy and data (final state)

**Ownership.**
- **SIS owns:** the Student master, Guardian relationships,
  enrollment/placement, the Student lifecycle, movement projections, the
  Student-document relationship (`student_documents`), the certificate record
  and issuance, and import orchestration.
- **SIS does not own:** Finance, Attendance, Academic subjects, Transport,
  Library, Hostel, Communications, Portal, platform storage security or
  Analytics. Student 360 projects them only through each owner's read path
  (`StudentProfileArchitectureTest`).

**Tenancy.**
- School is the only tenant; Campus is a scope; Network/Group is governance
  only.
- No cross-School Student relationship exists.
- No Platform or Network role gained Student authority.

**Authorization.**
- Capabilities stay authoritative; navigation is presentation only.
- Documents and certificates are whole-School sensitive access.
- Import is School-Admin-only; the movement register is whole-School.
- The Guardian Portal is a separate `guardian` scope.

**Capability and role deltas** (182 → 186 School keys):

| Key | Roles |
|---|---|
| `students.documents.view` | `school_admin`, `principal` |
| `students.documents.manage` | `school_admin`, `principal` |
| `students.certificates.issue` | `school_admin`, `principal` |
| `students.import` | `school_admin` |

Every other role is unchanged, including `admissions_officer`, which has no
import authority. No Registrar role was created.

**Campus scope.**
- **One change:** `app.students.movements` is declared a whole-School
  operation of `enrollments.view`.
- It is restrictive: a Campus-only reader is refused.
- No key was adopted and no Campus authority widened.

**Schema.**

| Table | Wave | Tenancy and integrity |
|---|---|---|
| `student_documents` | EXP-3 | forced RLS; same-Student composite key to `documents`; trigger-frozen once archived; no runtime DELETE; goes with the Student core record |
| `student_certificates` | EXP-4 | forced RLS; bounded snapshot; ISSUED → VOIDED only (trigger); one issued per exit and type; no runtime DELETE |
| `student_certificate_sequences` | EXP-4 | forced RLS; forward-only per School and type; retained with the School |
| `student_import_batches` | EXP-5 | forced RLS, append-only; unique (School, digest); counts only, no PII |

- The RLS tenant-table count is **223** (`ElevationRlsIsolationTest`).
- Every table is registered in the retention catalogue and the
  user-reference catalogue.
- Rollback proofs were IDENTICAL, and `platform:verify-database` reported
  failed=0, at each wave.

**Student core.** Intact:
- enrollment, promotion, transfer and lifecycle (direct assured actions with
  closed reasons);
- Student numbering (the forward-only allocator; import never accepts a
  number);
- Guardian identity, and School isolation.

HARDEN-1 hardened the placement write surface without changing behaviour.

**Import product contract (frozen).**
- `student_guardian_import_v1`, at most **100** rows, School Admin only.
- No update, merge or history. No documents. No Finance, Attendance or
  Transport data. No Portal accounts, and no messages of any kind.
- The 100-row cap is deliberate: commit time grows faster than linearly past
  it. Raising it needs a redesign, not a constant.

**Performance** (development stack, measured):

| Workflow | Budget |
|---|---|
| Directory | 25 queries / 28 filtered |
| Student 360 (HARDEN-1 measurement, every module, 9–39 siblings) | 71 queries |
| Movement register | 25 / 26 with a year filter |
| Student documents list | 21 queries |
| Certificates page | 34 queries; PDF render ~74 ms |
| Import preview | 31–39 queries |
| Import commit | ~3.9 s for 100 rows (the slowest SIS workflow; bounded by the cap) |

## 9. Production and gates

**Not production readiness.** SIS expansion closure does **not** make
any of these ready or approved:
- production;
- the Student Portal;
- statutory or medical Student data;
- Attendance, Finance or Analytics completion;
- the Documents security programme;
- any legal approval.

Production stays governed by every project-wide staging, legal and security
gate.

**E16.**
- `OWNER-0O-E16-2026-10-16` covers 16 accepted no-fix base-image advisories
  (115 records).
- It was valid at this closure; the last valid publication day is
  **2026-11-12**.
- From **2026-11-13**, the CI `Supply chain` job and `publication-check`
  refuse every candidate.
- **A fresh-scan owner successor is a platform/release prerequisite for any
  later programme.** SIS closure does not renew it.

**ESI-12.**
- The one expected skip is a legal deferral (PAY-L1 ESI disability).
- It is unchanged and is not an SIS defect.

**EXP-4 gate.** Unchanged and not softened.

## 10. Demo (canonical world)

Verified on a fresh `ddev demo-reset` (2026-10-30, real browser, 16/16;
walkthrough §9). Northstar Gachibowli has:
- **Campuses and Students:** two Campuses and 18 Students.
- **Aarav Menon:** a Student 360 with every owner card, two Guardians
  (Lakshmi, Vikram), and Diya as a sibling.
- **Movements:** 24, with Meera's withdrawal and Arjun's completed placement as
  the exits.
- **Documents:** Aarav has three active and one archived.
- **Certificates:** Meera has LC-000001 (voided), LC-000002 and TC-000001.
- **Import:** the walkthrough fixtures, previewed here and not committed.
- **Refusals:** the Campus-scoped Principal, Cashier, Network Manager and
  Platform Admin are refused.

**Limitations (by design).**
- Seeders never fabricate MFA proofs or lifecycle evidence, so there is no
  seeded deactivation or withdrawal with a proof.
- The between-years event is proven in tests, not seeded.
- Certificate rows are direct, database-validated writes without fabricated
  MFA.
- Walkthrough imports and edits disappear on reset.

## 11. Next ERP programme (recommendation; owner authorisation required)

| Criterion | HR ERP | Finance ERP | Inventory ERP | Transport ERP |
|---|---|---|---|---|
| Dependency order | Staff identity already underpins Teacher ownership, Leave, Staff attendance, Payroll and Communications | Fees/Payments/Ledger closed for development (FEE, OPF, ORG.8–9) | Stock ledger only (ORG.7H, 9C) | Routes, stops and riders (ORG.7D-5, 9C-4) |
| Maturity (domain files / feature test files) | HR 155/86, Payroll 180/41, Leave 44/13, Staff attendance 14/8 | Fees 141/34, Payments 99/28, Finance 58/33 | 29/15 | 41/13 |
| Reference pack | **`HR` folder exists in the owner's pack directory (currently empty)** | none | none | none |
| Open legal gates | PAY-L1 (ESI-12), HRX-L1–L4, E33 | E21, E30–E32 | — | GPS/tracking and E28 messaging |
| Planning already done | HR.md (4,220 lines), HRX closure, Payroll docs | FINANCE.md (5,842 lines), closed programmes | INVENTORY.md | TRANSPORT.md |
| Value / risk | high value (every School has staff); Highly Sensitive pay data, legal gates known | high value but legally gated for production; much already built | moderate | moderate; tracking legally sensitive |

**Recommendation: HR ERP first**, opened like ORG.12 and SIS.EXP with a
read-only **HR.EXP-0 full audit against the HR reference pack**:
- it is the next pack the owner has started;
- it is the deepest dependency of other domains;
- its legal gates are already named.

Then **Finance ERP**, whose production is gated by E21/E30–E32 rather than
missing build. Inventory and Transport follow, once the owner supplies
reference packs.

**Prerequisites:**
- the owner populates the `HR` pack;
- the owner authorises HR.EXP-0;
- publication before 2026-11-13, or an E16 successor.
