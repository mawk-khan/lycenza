# SIS Final Local UAT and Closure Record — 2026-10-28

**Result: PASS — READY FOR OWNER LOCAL SIS SIGN-OFF.**

The implemented SIS is ready for owner-led local acceptance and sign-off on the
canonical DDEV environment. This does not claim staging, production, cloud or
unrestricted legal-role readiness.

| Item | Value |
|---|---|
| Product candidate | `1f42d2ca` (SIS 10.2). No product code changed during this UAT |
| Closure-documentation commit | the commit that adds this file (documentation only) |
| Environment | canonical DDEV (`https://lycenza.ddev.site`), Docker Desktop engine; `ddev env-check` PASS before and after |
| Browser | real Chromium (Playwright 1.62.1) on `ddev_default` |
| Score | **96 / 100** (from 82 / 100, §8) |
| Owner sign-off | not yet given; this record is the input to it |

## 1. History

| Checkpoint | Candidate | Result |
|---|---|---|
| SIS 10.1 (first run) | `aedffd2a` | **PARTIAL.** One deterministic defect: D-1, the Student status message was inverted. Four MINOR findings: F-1 an Inertia refusal without an Accept header became a 500; F-2 placement refusals showed ISO dates; F-3 two roll-number editors for the current placement; F-4 Rollover Create showed ISO dates. Nothing was published; the score was withheld. |
| SIS 10.2 | `1f42d2ca` | **PASS.** All five corrected, each with a regression test (`tests/Feature/App/SisUatCorrectionTest.php`). No schema, capability or policy change. |
| SIS 10.1 (rerun, this record) | `1f42d2ca` | **PASS.** Fresh run on a freshly reset demo; nothing reused from the first run's evidence. |

## 2. Method and controls

- **Code freeze.** No product file changed. `git status` stayed clean.
- **SQL.** Read-only checks (baseline, post-run, outcomes), plus one controlled setup script, deleted afterwards:
  - TOTP and recovery codes for the admin and teacher;
  - a North Campus with Grade 6 and Grade 8 Sections;
  - Reception granted Admissions Officer for Main Campus only;
  - one North Campus application;
  - one pre-rule Applicant with a future date of birth (a state no current service can create);
  - same-named Student, Guardian and Applicant in the Annexe School;
  - one API token for the F-1 API check.
- **No completion through SQL.** No journey was finished through SQL or the API.
- **Demo.** `ddev demo-reset` before and after. The post-run baseline is identical to the pre-run baseline.

## 3. Baseline (canonical demo, read-only)

- **Schools:** 2.
- **Academic years:** 2025-26 closed, 2026-27 active, 2027-28 draft.
- **Records:** 36 Students (plus 3 in the Annexe School), 24 Guardians, 48 contacts, 4 Applicants (draft, submitted, accepted, rejected), 36 active placements, 4 Hostel and 8 Transport assignments, 1 draft rollover plan.
- **Integrity:** every count 0 — duplicate or case-only numbers, possible duplicates, future Student or Applicant dates of birth, placements or Terms outside their year, duplicate active primaries, more than one active placement.

## 4. SIS 10.2 regression gate (run first)

| Finding | Evidence | Status |
|---|---|---|
| D-1 | Wrong code refused, status unchanged. "Student marked inactive." with Inactive badge, then "Student marked active." with Active badge. Audit `active->inactive`, `inactive->active`; lifecycle `student_deactivated` / `student_reactivated`; history lists both. | CLOSED |
| F-1 | Real client: form-level refusal. Crafted `X-Inertia` without Accept: 422 with the same message. API: 422 `GUARDIAN_CONTACT_INACTIVE`, no Inertia page. Contact still inactive. | CLOSED |
| F-2 | 31 Mar 2026 and 1 Apr 2027: "…2026-27 (1 Apr 2026 to 31 Mar 2027)." API: 422 `INVALID_ENROLLMENT_DATE_RANGE`, same text. | CLOSED |
| F-3 | One editor (one input, one Save). Conflict is a field error; a free number gives "Roll number corrected." and an audit row. | CLOSED |
| F-4 | "2027-28 (1 Apr 2027 – 31 Mar 2028)". The only target offered is the draft year. Clean at 390 px. | CLOSED |

## 5. Journey matrix

All journeys were run in the browser.

| Area | Journey | Result |
|---|---|---|
| Student Master | No number field; numbers allocated by the School counter; future DOB refused (`aria-invalid`); "8 Aug 2016"; duplicate warning (Annexe twin never listed); Cancel creates nothing; acknowledged creation; success messages | PASS |
| Guardian | Create and link from the Student; full-name search in either word order; a contact value finds nothing; another School's Priya Sharma never listed | PASS |
| Contacts | Add, make primary, correct, deactivate (dialog), correct while inactive, reactivate (comes back non-primary), make primary. Forged primary on an inactive contact is refused. Same row reused, no duplicate; values encrypted with a 64-character lookup hash; audit metadata is field names only | PASS |
| Admissions | Applicant (future DOB refused, Campus/year options labelled, full-name search); correction of applicant, context and decision note; stale edit refused; draft discarded (slot freed); Submitted → Accepted → duplicate-warning conversion → Converted | PASS |
| Admissions atomicity | Out-of-year start refused (F-2 wording), nothing written. Legacy future-DOB application refused, stays Accepted and unconverted. Counts after: Students +4, placements +1 (the conversion), fee selections 0 | PASS |
| Placement | Before- and after-year starts refused; roll conflict refused; valid placement; one roll-number editor; correction audited | PASS |
| Campus transfer | Transport rider (LDS-0007) refused, naming the Transport assignment; ended in Transport; transfer recorded, history kept. Hostel resident (LDS-0031) refused, naming Hostel and the active elective; residency ended in Hostel, elective withdrawn in Subject Offerings; transfer recorded. No dependent record was changed by the transfer | PASS |
| Pre-start cancellation | Draft-year and active-year future placements; fixed reason list; wrong code refused; recovery code accepted; "Cancelled before it started (was to start …)"; empty interval kept as history; never on the Attendance roster before or after | PASS |
| Student lifecycle | Both directions with reason, wrong-code refusal, recovery code, badge, audit, history and correct message (D-1) | PASS |
| Academic Year / Term | Draft year: end-before-start and overlap refused; valid correction and restore. Term added to the draft year: wrong order and outside-year refused; valid correction. Shrinking the year over the Term refused ("Correct the Term dates first"). Active and closed years: no Correct dates link; their dates page returns to the list; forged correction refused ("…its calendar is fixed") | PASS |
| Closed-year structure | New Term and new Section in 2025-26 refused; the closed year stays readable | PASS |
| Activation rule | No Activate control for 2027-28 ("Can be activated from 1 Apr 2027"); forged activation refused. Wording shows an ISO date (§7, U-1). Concurrency and boundary are covered by `AcademicYearActivationDateTest` and the SIS 5.2 two-process tests | PASS |
| Rollover | 2026-27 → 2027-28 in the browser: mappings, Run Validation, bulk Promote/Exclude decisions, re-validate (5 ready, 0 review, 0 blocked), Start. Plan `completed`: 5 succeeded, 33 skipped. Target still draft (no activation), source placements unchanged. Bulk Promote refused roll-number collisions in the merged target Section (residual R-3) | PASS |
| Invalid rollover target | Only the draft year offered; forged active and closed targets refused. Mid-run target drift and no-partial-placement are pinned by the SIS 5.2 tests (`EnrollmentRollover*`) | PASS |
| Attendance (office) | Registers 0 → 1 submitted; Take with the new Student on the roster; correction dialog (Cancel leaves the mark unchanged; Confirm gives "Late (Corrected)"); stale second session refused; Student attendance history | PASS |
| Future Attendance | Date limited to today; notice shown, no classes listed; forged future submission refused, nothing written | PASS |
| Teacher | Own class only, with readable times; register submitted; future date refused; another class's entry not loadable; every SIS admin page 403; `/app/my-classes` 404 | PASS |
| Data Health | All 12 checks open; no counts on the overview; only true findings (acknowledged duplicate DIYA R iyer / LDS-0002; the two new North Sections have no timetable; Students with no current placement); links go to the owning pages; placing a Student clears its finding; the earlier duplicate cleared after a name correction | PASS |

## 6. Authorization, tenancy, privacy and accessibility

- **Authorization:**
  - **Admin:** full SIS access.
  - **Reception** (front office plus Admissions Officer @ Main): Admissions 200 and only Main Campus listed; North application 404; Applicants directory 403; Students, Guardians, placements, rollover, Attendance, Data Health and School setup all 403.
  - **Librarian:** every SIS page 403.
  - **Teacher:** see §5.
  - **Principal:** holds `students.manage` in the reviewed staff catalogue, so the controls are shown; a status change still needs that person's own fresh MFA.
- **Tenancy:**
  - Another School's Student, Applicant, Guardian, attendance history and Student history all return 404 by ID.
  - Student, Guardian and Applicant searches and the duplicate warnings never show the other School's records; Data Health never shows `ANX-`.
- **Malformed IDs:** 12 routes, including the all-zero UUID and an unknown check, all return 404.
- **Privacy (ADR 0041):**
  - No search by contact value.
  - Contacts encrypted and hashed.
  - Audit metadata carries no contact values.
  - Data Health shows no phone, email or date of birth.
- **Accessibility:**
  - The dialog takes focus; Escape returns it to the trigger.
  - Fields are labelled and linked to their error text (`aria-describedby`), and `aria-invalid` is set on refusal.
  - Status messages are announced.
  - No UUIDs; dates and times are human-readable.
- **390 px:** Student, Guardian, conversion, placement, years, Rollover Create and plan, register and teacher Take — all clean, no page-level overflow.

## 7. Remaining findings (none blocking)

| Id | Category | Finding |
|---|---|---|
| U-1 | Product (MINOR) | Academic Year activation refusal says "starts on 2027-04-01" (ISO date) |
| U-2 | Product (MINOR) | Subject Offering elective Withdraw/Cancel uses a native browser confirm (that page is outside the SIS 9.1 page set) |
| U-3 | Product (MINOR) | A Student whose only target-year placement was cancelled before it started is sent to review by rollover validation (documented rule: target-year history without an active placement is reviewed). Safe path: Exclude, then place manually in the draft year (proven) |
| U-4 | Non-SIS observation | The Hostel residency list shows its start date in ISO form |
| U-5 | Optional UX | No success message after adding a rollover mapping or elective decision, or creating a Term/Section (the list updates) |

## 8. Formal rescore (weights unchanged from SIS 1.1 §21)

| Area (weight) | Score | Deduction (category) |
|---|---|---|
| Student Master (15) | 14.5 | Student-number case policy (owner decision −0.5) |
| Admissions (12) | 12 | — |
| Guardian / relationships (8) | 7.5 | Guardian status-reason catalogue (owner decision −0.5) |
| Enrollment / Placement (15) | 15 | — |
| Lifecycle / Rollover (12) | 10.5 | Bulk Repeat / Manual Review / bulk explicit roll numbers / stale-plan notice (optional −1); U-3 (minor −0.25); U-5 (optional −0.25) |
| Attendance incl. Teacher (12) | 11.5 | Teacher My Classes (optional −0.5). Mark All Present is an owner decision and, as in SIS 1.1, not deducted |
| Search / operational visibility (8) | 8 | — |
| Data Health / integrity (8) | 8 | — |
| UX / accessibility / browser operability (7) | 6.5 | U-1 (minor −0.25); U-2 (minor −0.25) |
| Documentation / demo (3) | 2.75 | the 9.2 guide and role-matrix review was folded into each slice, not done as its own pass (−0.25) |
| **Total (100)** | **96.25 → 96** | |

Deductions by category:
- **Owner decision:** 1.0.
- **Optional convenience:** 1.75.
- **Product (MINOR):** 0.75.
- **Documentation:** 0.25.
- **External/legal gates and environment:** 0 (not SIS product items).

## 9. Owner decisions — none required for local sign-off

| Decision | Local sign-off needs it? | Why |
|---|---|---|
| Mark All Present (ATT-08) | No | Student-by-Student marking was fully operable for office and teacher |
| Guardian status-reason catalogue | No | Guardian status changes are safe, audited and operable |
| Student-number case policy | No | Numbers are allocated by the counter (no typing); the Data Health case-collision check works |
| Teacher My Classes (SIS 6.3) | No — OPTIONAL / POST-SIS | My Attendance covers every required teacher task |
| Rollover residual | No — OPTIONAL / POST-SIS | The core annual transition completed in the browser |

## 10. Browser-only operability

| Workflow | Browser | API-only | SQL-required |
|---|:-:|:-:|:-:|
| Student create / correct / lifecycle | ✓ | — | — |
| Guardian create / link / contacts | ✓ | — | — |
| Admissions (full cycle, correction, discard, conversion) | ✓ | — | — |
| Placement, roll correction, transfer, pre-start cancel | ✓ | — | — |
| Academic Year / Term date correction | ✓ | — | — |
| Rollover (configure, validate, decide, execute) | ✓ | — | — |
| Attendance take / correct / history (office, teacher) | ✓ | — | — |
| Data Health review and domain-owned repair | ✓ | — | — |

No ordinary SIS workflow needs SQL or the API.

## 11. Regression evidence

- **Exact-SHA CI on `1f42d2ca`:** `CI gate` green (run 40585994410). 4 shards cover the canonical inventory exactly once: 9,802 cases, 9,801 passed, 1 skipped (ESI-12, legal deferral), 373,878 assertions. Static checks, images, supply chain and full-history secrets scan green.
- **Local full regression on `1f42d2ca`:** 9,764 passed / 35 DDEV-environment failures / 1 skipped (§11.1).
- **Static gates on the identical tree** (SIS 10.2): Pint, Larastan, vue-tsc, ESLint (0 errors), Prettier, build, whitespace, gitleaks — all PASS. Architecture, route-coverage and authorization guards PASS in the focused suites and in CI.

### 11.1 Local full regression

`ddev test --reset-db` on `1f42d2ca`: 9,800 cases — **9,764 passed, 35 failed, 1 skipped** (ESI-12), 373,503 assertions, 2,717 s.

All 35 failures are the DDEV environment set already classified in the first run; the list of failing tests is identical, test for test. None is a product failure, and CI on the same commit is green.

| Count | Area | Cause |
|---|---|---|
| 18 | Identity | tests pin `http://localhost:8000`; DDEV's APP_URL is `https://lycenza.ddev.site` |
| 6 | Documents | need real MinIO |
| 4 | Retention | use the real-S3 storage disk |
| 3 | StagingParity / SecurityHeaders | HSTS behaviour under DDEV |
| 3 | CustomDomains | hand-off goes to the DDEV host |
| 1 | TrustedProxy | DDEV host configuration |

The local total rose from 9,794 (first run) to 9,800: the six SIS 10.2 tests.

## 12. Readiness statement

The implemented SIS is ready for owner-led local acceptance and sign-off on the
canonical DDEV environment. This does not claim staging, production, cloud or
unrestricted legal-role readiness: E33/E35/E37 and the other legal gates, the
PPR/staging programme and AWS remain as recorded elsewhere.

**Next:** owner local SIS sign-off. No further development checkpoint starts
automatically.
