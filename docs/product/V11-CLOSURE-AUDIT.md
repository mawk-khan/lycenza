# V11.7 — Commercial Readiness Closure Audit

- Date: 2026-10-15. Authorised by the owner as OD-V11-7C (audit, verification
  and documentation reconciliation only). **No product code changed.**
- Baseline: `origin/main` = local `main` = `HEAD` =
  `5f77ef62645b6a5987c695547925b148a94ec9bc`. The tree is clean, there is one
  worktree, and the historical stash is untouched. The exact-SHA `CI gate` is
  green (run 38770023076: 999 files, 8386 cases, 8385 passed, 1 skip ESI-12).
- Contract: [ADR 0075](../architecture/adr/0075-v11-commercial-readiness-programme-contract.md)
  (§13 records this closure). Evidence: [V11.0 audit](V11-COMMERCIAL-READINESS-AUDIT.md).
- This is not a production-readiness claim. PPR / Phase 0O (ADR 0074, ADR
  0058 O1) are unchanged and remain the production gate.

## 1. Programme status

| Checkpoint | Original intent | State | Published evidence | Remaining | Blocker |
|---|---|---|---|---|---|
| V11.0 | Commercial audit + contract | **Complete** | `bb3177e1` (ADR 0075 §1–§8) | — | — |
| V11.1 | Executive / enrollment intelligence | **Blocked** | ADR 0075 §4 | Whole checkpoint | Small-cohort gate 7.1–7.3 + counsel 7.4 (§4 below, D-01..D-04) |
| V11.2 | SIS search and operational visibility | **Complete within approved scope** | `80aa6bda` (§9) | Global search, SIS export, guardian lookup | D-11, D-12 (owner); guardian lookup rejected under ADR 0041 |
| V11.3 | Lifecycle approval and annual rollover | **Complete within approved scope (narrowed to R-1)** | `8756b16a` (§11); test fix `4e411e9f` | Prevention at year activation; lifecycle reason/effective date/approval | D-10 (owner); D-13 reopening PHASE-1E-0 (owner) |
| V11.4 | Communications administration | **Decision-gated** | ADR 0075 §5, audit Group D | Whole checkpoint | D-07, D-08 (ADR 0055 / 0043 amendments) |
| V11.5 | Data health and reconciliation | **Complete within approved scope (read-only)** | `1d53f672` (§10) | More checks (advisory) | D-05, D-06 for guardian-related checks |
| V11.6 | Learning spaces / capacity | **Decision-gated** | Audit Group F | Whole checkpoint | D-09 (owner) |
| X-01–X-04 | Cross-cutting corrections | **Complete** | `5f77ef62` (§12) | — | — |
| V11.7 | Closure audit | **Complete** (this record) | this commit | — | — |

Related publications: `e7428dc8` (CVE-2026-108082 dependency patch) and
`4e411e9f` (order-independent Leave test). Both are independent of V11 scope.

## 2. What V11 actually changed

- **Staff can find and distinguish records.**
  - Staff directory filters (campus, department, position, sort).
  - Current class on the Student list.
  - Teacher and Section filters on Teaching Assignments; the Employee profile
    links to the person's assignments.
  - Result totals on lists.
- **Operational visibility.**
  - A per-Student history read from the audit ledger, gated per category.
  - One day's absent, late and excused list across registers.
- **Data Health.** Eight domain-owned, read-only checks, recomputed on request
  and permission-filtered, each linking to its fix.
- **Rollover leftover correctable in the browser.**
  - Complete or Withdraw for a placement still open in a closed year.
  - Preview-then-complete for a whole closed year (per-row service calls,
    truthful outcomes).
- **Corrections.**
  - School timezone validated against PHP and PostgreSQL.
  - The 64 success messages are now shown.
  - The MFA and example email sender name now says Lycenza.
  - Timetable filters are choices, not ids.
- **Not added:** migrations, tables, capabilities, export, or person-counting
  Analytics.

## 3. End-to-end journeys (runtime, DDEV demo, 2026-10-15)

Verified in Chromium with the demo personas; the demo was restored with
`ddev demo-reset` afterwards. Login is limited to 6 per minute per IP
(`RateLimiterServiceProvider`); verification paced around it.

| Journey | Result | Evidence and gaps |
|---|---|---|
| **A. School onboarding** | **Partial** | Pass in the browser: settings with the validated timezone list, School setup, staff invitations, roles. Still needs an operator or developer: creating the first School administrator (console only, ADR 0059 by design); turning on a feature flag (SQL); no Student/Guardian import (OD-SWC-4); no opening balances (OD-PPR-20) |
| **B. Admission to active Student** | **Pass** (optional guardian) | Pass: application, decision, conversion with guardian none/create/link, placement. Gaps: applicant has no contact details; no applicant edit; manual admission number; no duplicate detection (V11.0 audit Group B/C). Guardian is optional by contract; not a blocker |
| **C. Academic cycle** | **Partial** | Pass: placement, timetable (now with selectors), registers, attendance exceptions, Student history, rollover with dry run, closed-year completion (runtime: 2027-28 activated, 36 placements flagged, 36 of 36 completed, check cleared). Blocked by legal/scope: marks (development-only, E36), results, report cards, transcripts (E39–E41). Electives cannot be timetabled, so they have no registers (design) |
| **D. Guardian finance** | **Partial** | Pass (staff): fee structures, assessment runs, payments, allocation, receipts (print), Student statement. Gaps: no payment correction or refund (owner decision 2026-09-28); no defaulter, collection or day-book report; no export. Guardian view is development-only (E46); online payment is post-v1 (E28) |
| **E. Teacher daily work** | **Partial** | Architecture complete. Development-usable: My curriculum delivery, learning content, assignments, leave; My attendance behind MFA (verified). Production: no teacher grants until the E33 conditions are re-verified (ADR 0063 §43). Marks are legally blocked (E35–E37). No own timetable or class-list page |
| **F. Employee operations** | **Pass** | Employees with filters, staff attendance, leave approvals, payroll (HR persona). Valid edge states (Employee between employments) are deliberately not flagged. Statutory payroll is gated (E45, F-1) |
| **G. School operations** | **Pass** | Library catalogue and circulation, inventory stock, timetable all render for their roles; other roles get 403 |
| **H. School leadership** | **Partial** | Pass: Student and Enrollment lists with totals, attendance exceptions, staff directory, assignments, Student history, Data Health, fee statements. Missing: a management dashboard with people counts (V11.1, legally blocked), and finance or attendance summaries and exports. **This is a commercial enhancement, not a UAT or staging blocker.** For a pilot it is a sales or acceptance risk, not a correctness defect |

Persona checks: every role reaches only its own pages (403 elsewhere). The
Annexe administrator gets **404** for a Demo School Student and that
Student's history (School isolation). A platform administrator is sent to the
platform landing from School pages. The guardian portal needs MFA for
attendance and fees.

## 4. V11.1 decision pack (person-counting Analytics)

**Current state.** `CohortSuppressionPolicy` returns 503 while
`analytics.minimum_person_cohort_size` is unset, and
`AnalyticsArchitectureGuardTest` refuses any read model that counts people
(re-run green in V11.7). The decisions are listed in
`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md` §7.

| ID | Decision | Options | Recommendation (not approval) |
|---|---|---|---|
| **D-01** (gate 7.1) | Minimum cohort size N | One N for every person-derived cell; a stricter N for children's data | A single platform-wide **N = 10** for any cell or denominator counting people, with **N = 10 also for Student cells**. Ten is a common floor in public-sector small-cell rules. It keeps grade-level totals visible in typical Schools while hiding most single Sections of a small School. A legal reviewer may set a higher Student value |
| **D-02** (gate 7.2) | Suppression behaviour | Omit the cell; show "fewer than N"; decline the grouping; complementary suppression | Show **"fewer than N"** for a small cell. Apply **complementary suppression** (also hide the next-smallest cell, or the total) so nothing can be recovered by subtraction. Suppress any rate or percentage whose numerator or denominator is suppressed. Allow **month-level** date windows only. This already fits the gate's §6 contract (marker `{"suppressed": true}`) |
| **D-03** (gate 7.3) | Policy scope | Platform floor only; a School may make it stricter, never looser | **Platform floor; Schools may only raise N.** It applies to every Analytics read model, dashboard, export and API that counts people (Students, Guardians, Employees, applicants, visitors). Operational lists and their own row totals (ADR 0042 §11) stay outside it |
| **D-04** (gate 7.4) | Counsel review for Student-data Analytics | Request now; build behind a default-off flag and review before enabling; exclude Student data | **Request the review now** and build later behind the existing `FeatureFlagResolver`, default off |

Draft question for counsel (to be sent by the owner, not answered here):

> "Lycenza proposes aggregate dashboards over Student enrolment, for School
> administrators of one School only: counts by campus, grade and month, and
> admissions and withdrawals by month. Every cell below N = 10 (configurable
> upwards only) is shown as 'fewer than 10', with complementary suppression
> and no drill-down beyond what the viewer may already see record by record.
> Under the applicable children's-data rules (DPDP and any state rules) and
> the School's processing basis, (a) is this processing permitted, (b) is N =
> 10 an acceptable floor for children's data, (c) must Guardians be informed,
> and (d) may the same aggregates cover staff (Employees)?"

V11.1 is still blocked until D-01–D-03 are recorded as an ADR 0040
amendment and D-04 is answered.

## 5. Guardian and contact decision pack

| ID | Question | Options | Effects | Recommendation |
|---|---|---|---|---|
| **D-05a** | Must a Student have a guardian? | Optional (today); at least one; at least one legal guardian; only at a lifecycle stage | **Admissions:** conversion would refuse "no guardian". **Existing records:** need a Data Health check first, not a hard constraint. **Imports:** none exist. **Exceptions:** state wards, institutional care and adult Students (18+) need an explicit path | Keep it **optional in the data model**. Add an **advisory** Data Health check "active Student with no active guardian link" once approved. Never a write-time constraint (exceptional family structures, adult Students) |
| **D-05b** | Must a Student have a legal guardian? | as above | Affects portal eligibility (POR uses legal guardian), which is legally gated (E46) | **Defer** to the POR-L1 answer (E46): no requirement before the portal is legally cleared |
| **D-06a** | Guardian email | mandatory / optional / conditional / advisory | Email is the only digital channel; E17–E20 keep it off in production anyway | **Advisory only** (a Data Health "no email on any guardian" check); never mandatory, because many families are phone-first |
| **D-06b** | Guardian phone | same | No SMS channel exists (E28) | **Advisory only** |
| **D-06c** | Postal or communication address | same | Not modelled for guardians beyond contacts; address on Students is deferred (ADMISSIONS §4) | **Not now.** Requires privacy review before modelling |
| **D-06d** | Duplicate guardian and contact detection | none (today); sweep over `lookup_hash`; at link time only (today) | **Value:** fewer split households. **Technical:** `guardian_contacts.lookup_hash` (HMAC) allows exact-match grouping **without decrypting** (ADR 0041). **But** ADR 0041 and ADMISSIONS.md §14 sanction the lookup only at link time, "never a staff-facing search feature". **Privacy:** shows that two records share a contact. **Performance:** one GROUP BY on an indexed column | Possible without decryption, but it **requires an ADR 0041 amendment** (a new purpose for the lookup hash) and a privacy decision. Recommended as an advisory, School-scoped Data Health check visible only to `guardians.manage`, if approved |

## 6. V11.4 decision pack (communications administration)

Re-audited against ADR 0055, CLAUDE.md rule 89 and ADR 0043 §12.

| Capability | Class | Basis |
|---|---|---|
| Organisation sender identity (fixed `notifications@MAIL_SENDING_DOMAIN`) | **1. Possible now (exists)** | `SenderIdentity`; ADR 0055 §8.2 |
| Read-only "effective sender" and configured / not-configured view | **1. Possible now** | Derivable; no secret or provider id shown |
| School or campus From address | **2. Requires ADR 0055 amendment** + DNS evidence | §8.2, §8.4 |
| Campus or department display name | **2. Requires ADR 0055 §8.3 amendment** | fixed format |
| Reply-To / Cc / Bcc | **4. Prohibited** (rule 89, §13, guard test) | A verified Reply-To would need its own amendment and verification flow |
| Signatures | **3. Owner decision** (School-authored plain-text sign-off inside the body) | A transport-level signature conflicts with §13 → 4 |
| Test email | **2. Requires ADR 0055 §3 amendment** (new purpose); real sending also waits on E17–E20 | closed purpose catalogue |
| Event-driven staff notifications | **3. Owner decision (ADR 0043 §12)**, then tier-1 Automation rules through Communications | Guardian, Student or outsider notifications are **4. Prohibited** (tier 4) |
| Notification-policy visibility page | **5. Defer** until any notification exists | nothing to show |

Recommended minimal V11.4 if wanted:
- the read-only sender/configuration view (no amendment needed);
- decide **D-08** (tier-1 staff notifications) first;
- defer every sender override.

## 7. V11.6 decision pack (learning spaces)

**Exists:**
- `rooms.room_type` and `rooms.capacity` (advisory);
- `sections.capacity` (advisory);
- timetable room usage, with room double-booking prevented by the database.

**Absent:** examination seating (deliberately excluded) and learning-space
suitability (deferred, TIMETABLE.md).

| Proposed data | Business value | Schools can operate without it? | Needed for initial commercial use? | Schema / UI / reporting |
|---|---|---|---|---|
| Building | Find rooms on large campuses | Yes (room name and code) | No | column + filter; trivial reporting |
| Floor / block | Same | Yes | No | column |
| Home room (Section → room) | A Section's default room; less timetable entry | Yes (timetable room per class) | No | FK `sections.room_id` + pickers |
| Room in-charge | Accountability for labs | Yes | No | FK to Employee; HR coupling |

Recommendation (**D-09**): **no V11.6 now**. If a pilot asks, the minimum
useful scope is **home room only**. A capacity-versus-strength warning needs
the Analytics gate (it is a headcount).

## 8. Rollover prevention (D-10)

| Option | Operational risk | Usability | Accidental completion | Legacy | Audit | Reuse |
|---|---|---|---|---|---|---|
| 1. Keep post-activation reconciliation (today) | Low: Data Health flags it (Action required), the batch fixes it in minutes | Two clicks a year | None: explicit preview and confirm | Fine | Per row | — |
| 2. Optional prompt at activation | Lowest | Best | Low (still confirmed) | Fine | Per row | Full reuse of `ClosedYearPlacementClosureService` |
| 3. Require reconciliation before activation | Low | Blocks activation on stragglers (e.g. a disputed withdrawal date) | Pressure to bulk-complete wrong rows | Blocks Schools with legacy rows | Per row | Reuse |
| 4. Separate year-closing workflow | Low | More steps | Low | Fine | Per row | Partial |

**Classification:** an **operational warning**, not a blocker of any stage.
Data Health detects it and a safe correction exists. **Recommendation:**
option 2 (an optional, previewed prompt after activation) when convenient
(P2).

## 9. Global search and SIS export

- **SIS export (D-11).**
  - **Current state:** there is no export architecture for SIS:
    `analytics.export` is granted to no role, `compliance.export` does not
    exist, and only statutory payroll exports exist.
  - **A safe design needs:**
    - a new capability per dataset;
    - output identical to the screen;
    - an audit event per export;
    - CSV formula-injection guarding (an open HR P3);
    - no Highly Sensitive columns without their own capability.
  - **Likely datasets:** the enrollment directory, guardian contacts (needs
    a privacy decision), the attendance-exceptions day, and a fee
    outstanding list (which does not exist yet).
  - **Timing:** not needed for UAT or staging. **P1 for production** in
    practice, because Schools file returns from spreadsheets.
- **Global search (D-12).** Module-level search covers Students (name and
  number), Enrollments (placement), Guardians, Employees, Admissions, Library
  and Visitors. A cross-module box needs per-result-type authorization and no
  new index at current scale. **Not required for initial release (P3).**

## 10. Legal and privacy register

| Gate | Engineering complete? | Legal / privacy decision | Development effect | Production effect | Owner |
|---|---|---|---|---|---|
| ESI-12 / PAY-L1 (E45) | Branch not built (deferral) | ESI ceiling for employees with a disability | 1 skipped test | Statutory payroll not offered (no production role) | Owner → legal (PAY-L1 drafted) |
| Student Analytics (gate 7.1–7.4) | Fail-closed guard built | N, mode, scope (D-01–D-03); counsel (D-04) | V11.1 not buildable | No person-counting Analytics | Owner + counsel |
| E33 / TCH-L1 teacher Attendance | Yes (nine controls, ADR 0063 §43) | Approved with conditions | Usable | No teacher grants until production-candidate re-verification, E21 and the O1 checklist | Owner (process) |
| E35 / E36 / E37 StudentMark (RES-L0/L1/L2) | Development only | Production marks; teacher inclusion | Development only | Refused in code | Owner → DPO |
| E39–E44 results, report cards, transcripts, Student/Guardian access, retention, statutory rules | Not built | RES-L4…L9 | — | Absent | Owner → legal |
| E46 POR-L1 Guardian/Student portal | Guardian development only | Per-surface access, age-18 transition | Usable (MFA) | Refused in code | Owner → DPO (drafted, not sent) |
| E47 SR-L1 staff personas | Built | DPO answer before production personas? (OD-PPR-14c) | Usable | Owner decision | Owner |
| E21 retention | Built | Ratification of the determination | — | Retention config pending | Legal |
| E17–E20 email provider / DPA / domain / drills | Built, dormant | Processor review | Mailpit | Email refused in hardened environments | Owner → legal + operator |
| E30 / E31 / E32 / E34 receipts / GST, late-fee regulation, RTE, library fines | Built | Each question separately | Usable | Production depends on the answers or a code refusal (OD-PPR-14a) | Owner → legal |
| Guardian requirement (D-05) | — | Owner product decision; privacy for exceptional cases | — | — | Owner |
| Guardian contact sweep (D-06d) | — | ADR 0041 amendment + privacy | — | — | Owner + privacy |
| Health, safety, disciplinary records | Not built | `[LEGAL REVIEW REQUIRED]` | — | Absent | Owner |
| Communications sender / notifications (D-07, D-08) | Partial | ADR 0055 / 0043 amendments; Guardian notifications prohibited | — | — | Owner |

## 11. E16 supply-chain governance (operational, not V11)

- **What expires:** all 115 records in
  `infrastructure/release/vulnerability-exceptions.json`, approved by
  `OWNER-0O-E16-2026-10-11` (decided 2026-10-11, `max_days` 30). Each
  carries `expires_on` **2026-11-10**, so the **last valid day is
  2026-11-09**.
- **Consequence:**
  - `lycenza-ci publication-check` refuses a candidate whose E16 decision
    has expired;
  - the release evaluator stops excepting those findings;
  - **from 2026-11-10 nothing can be published to `main`**, V11 fixes
    included, until a successor decision exists.
- **Action and owner:** a successor E16 cycle (fresh scan, remediation where a
  fix exists, a new owner decision record). The successor is planned for
  about 2026-10-30. Owner decision + engineering scan. Not changed here.

## 12. PPR separation

- **What V11 improved:** product usability, operational visibility,
  reconciliation, and four integrity/UX defects.
- **What PPR still owns (ADR 0074):**
  - staging and production infrastructure (none exists; R-00a is blocked
    because there is no AWS session or operator);
  - the secret store, the image registry and signing, backup and the restore
    drill (E11, the hard blocker), observability backend and alert routing,
    the email PPE (E17–E20), domain and recovery drills, performance;
  - the legal and feature-gate closure (PPR.10), and final qualification;
  - **25 mandatory O1 rows open** (ADR 0058 note).
- **Handed to PPR (not fixed):**
  - roadmap PPR-row drift: it says "18 owner/legal decisions" against 21
    actual, and has the inconsistent "22 mandatory rows" wording (ADR 0074
    §1 versus the ADR 0058 note's 25);
  - SIS export (D-11) as a production-acceptance item;
  - a recommendation that PPR.9's tenant-support runbook cover
    first-administrator provisioning and feature-flag enablement (both need
    an operator today).

## 13. Readiness scorecard

Change is against the V11.0 working estimate (2026-10-15, before V11
implementation).

| # | Category | Score | Δ | Evidence | Remaining blocker | Confidence |
|---|---|---|---|---|---|---|
| 1 | Domain architecture | 93 | +1 | Domain-owned checks; no new truth tables; guards green | — | High |
| 2 | Backend / business logic | 87 | +2 | Reconciliation service; per-row orchestration | Payment correction contract; statutory payroll trigger (F-1) | High |
| 3 | Database / data integrity | 94 | +2 | Timezone validated at source; closed-year leftovers correctable | — | High |
| 4 | Authorization / security | 90 | +2 | Per-check gating; isolation 404 verified at runtime | Production teacher grants (E33 process) | High |
| 5 | Privacy / legal | 35 | 0 | No determination received during V11 | Register in §10 | High |
| 6 | Frontend / UI | 72 | +10 | Feedback banner, selectors, filters, phone tables | About 800 accessibility lint findings outside the SWC files; no end-to-end browser suite | Medium |
| 7 | End-to-end workflows | 74 | +9 | R-1 loop closed at runtime; journeys B, F, G pass | Marks/results (legal); payment correction | Medium |
| 8 | Reporting / operational visibility | 45 | +20 | Lists with totals, history, exceptions, Data Health | Exports; finance/attendance summaries; V11.1 (legal) | Medium |
| 9 | Automated testing | 82 | +2 | 8386 cases, sharded exact-SHA CI; 79 new V11 tests | No browser, performance or accessibility suite | High |
| 10 | Local UAT readiness | 85 | +13 | All approved staff workflows browser-operable; demo personas | See §14 limitations | High |
| 11 | Staging readiness | 15 | 0 | Plans only (PPR.2) | No infrastructure; R-00a blocked | High |
| 12 | Production infrastructure | 10 | 0 | Terraform empty by design | PPR.2–PPR.3 | High |
| 13 | Operations / observability | 40 | 0 | 41 alert rules, JSON logs | No backend, routing or on-call | High |
| 14 | Backup / recovery | 20 | 0 | Contract only | E10, E11 drill | High |
| 15 | Documentation / training | 32 | +2 | Strong engineering docs, decision records | No end-user or administrator guide | High |
| 16 | Commercial / product | 60 | +10 | Visibility and reconciliation for daily use | Exports, dashboard, payment correction, import | Medium |

**Overall commercial readiness: 58 %.**
- Weighting: product capability 55 % (categories 1, 2, 3, 4, 6, 7, 8, 9, by
  size) and go-live readiness 45 % (categories 5, 11, 12, 13, 14, 15, 16).
- Product capability ≈ **81 %**; go-live readiness ≈ **31 %**.
- Interpretation: a strong, testable product (local-UAT candidate). Staging,
  pilot and production are held by infrastructure (PPR) and legal
  determinations, not by V11 feature gaps.

## 14. Stage gates

| Stage | Determination | Why |
|---|---|---|
| **Local UAT** | **YES** | Every approved staff workflow is browser-operable; isolation and roles verified at runtime; demo data resettable. Known non-blocking limitations: no import (keyed data entry); marks, results and the guardian portal are development-only; no exports or management dashboard; email captured by Mailpit; teacher Attendance needs MFA enrolment |
| **Staging** | **NO** | No environment exists (PPR.2 R-00a blocked: no AWS session). The E16 successor is also due before 2026-11-10. V11 adds no staging blocker |
| **Controlled pilot** | **NO** | Needs staging/production infrastructure and backups (E10, E11); a working email channel (E17–E20); E21 retention ratification; the E30–E34 answers or code refusals; and in practice a payment-correction contract and Student import. V11 enhancements are not on this list |
| **Commercial production** | **NO** | Everything for the pilot, plus O1 qualification (E02, signing, promotion E13–E15, observability E07, domains E22, recovery E23) and the remaining legal register |

## 15. Remaining work

| Item | Priority | Kind |
|---|---|---|
| E16 successor decision before 2026-11-10 | **P0** (blocks every publication) | Operational governance |
| PPR infrastructure (R-00a onward), E10, E11 | **P0** for staging | Operational / PPR |
| Legal register items for pilot (E17–E21, E30–E34) | P0 for pilot | Legal / privacy |
| Payment correction / refund contract | P1 | Product decision, then engineering |
| Student / Guardian import + opening balances (OD-PPR-20) | P1 | Product decision, then engineering |
| SIS export (D-11) | P1 | Product + architecture decision |
| Defaulter / collection / day-book finance reports | P1 | Engineering (operational lists, no Analytics gate) |
| End-user / administrator guide | P1 | Documentation |
| V11.1 dashboard (D-01–D-04) | P2 | Legal / privacy, then engineering |
| Rollover prompt (D-10) | P2 | Product decision, then engineering |
| Guardian advisory checks (D-05, D-06) | P2 | Product / privacy |
| V11.4 read-only sender view; staff notifications (D-08) | P2 | Architecture decision |
| Teacher own timetable / class list | P2 | Authorization decision (ADR 0063) |
| Accessibility lint backlog (~800) | P2 | Engineering |
| V11.6 home room (D-09) | P3 | Product decision |
| Global search (D-12) | P3 | Architecture decision |

## 16. Do-not-build register

| Idea | Why not, under the current contract |
|---|---|
| Unrestricted guardian or contact search | ADR 0041 and ADMISSIONS.md §14: the lookup hash is for duplicate checks at link time only |
| Generic database editor or "fix all" repair | Corrections must go through owning services (ADR 0075 §6.4); Data Health is read-only |
| Automatic Data Health correction | No check has exactly one valid outcome (an end date or replacement needs a person) |
| Sender overrides, Reply-To, Cc, Bcc | ADR 0055 §8 and §13, rule 89, guard test |
| Guardian or Student event notifications | ADR 0043 tier 4, prohibited |
| Person-counting dashboards or totals before the gate | ADR 0040 and the small-cohort gate; never gate on `students.view` |
| Lifecycle reason, effective date or approval without a decision | Reopens PHASE-1E-0 §13.2 (D-13) |
| Automatic completion on year activation | Silent bulk state change; the open question in STUDENT-ENROLLMENT.md |
| Buildings, facilities, maintenance, work orders | Outside academic learning-space scope |
| Payment edit, delete or negative payment | Rule 91: posted payments are immutable; needs a Finance correction contract |
| Changing `APP_NAME` for branding | It is the cookie and cache namespace (ProductIdentity) |

## 17. Commercial strengths (repository evidence)

- **Tenant isolation:**
  - RLS forced on every School table, with the runtime role lacking
    `BYPASSRLS`;
  - Data Health and V11 lists re-verified: a foreign School's record is a
    404 at runtime.
- **Authorization by capability, never role:**
  - every V11 surface is gated by its source capability;
  - a hub can never widen what a viewer learns;
  - the hub test pins it.
- **Database invariants and lifecycle safety:**
  - one active placement per year;
  - compare-and-set transitions;
  - Section-first locks;
  - immutable terminal placements;
  - transfers create new rows.
- **Reconciliation:** problems are detectable (Data Health) and correctable in
  the browser, with per-row truthful outcomes and per-row audit.
- **Auditing:**
  - append-only ledgers;
  - an entity history read through an allow-listed contract;
  - audit-log reads themselves audited.
- **Fail-closed policy:** person-counting Analytics, production marks and the
  Guardian portal are refused in code until legal determinations exist.
- **Supply chain and publication:**
  - `main` moves only to an exact SHA with a green 4-shard CI gate (coverage
    proven) and a valid E16 decision;
  - a CVE was blocked and remediated during V11;
  - a nondeterministic test was root-caused, not re-run until green.

## 18. Documentation drift corrected in V11.7

1. **ADR 0075 §5 statuses** were stale ("Ready to authorise", for example).
   §13 now records the closure statuses; §5 keeps its historical text.
2. **Decision-ID collision.**
   - ADR 0075 §8 defined OD-V11-3 as "reopen PHASE-1E-0 §13.2" and OD-V11-5
     as "tier-1 staff notifications". The owner's approvals later used
     OD-V11-3 for the **narrowed R-1** checkpoint and OD-V11-5 for **V11.5
     Data Health**.
   - Neither §8 decision has been taken. §13 maps every ID and moves the
     still-open questions into the D-xx register below (D-13 lifecycle
     reopen, D-08 staff notifications).
3. **Roadmap V11 row** updated with the V11.7 closure.

**Drift reported, not changed** (outside V11):
- the PPR row counts (§12);
- `docs/modules/ADMISSIONS.md` §6 "broadly editable", although no edit path
  exists (V11.0 audit gap 2);
- the root `README.md` still reads "School OS … Phase 0A".

## 19. Recommended UAT matrix (supported roles only)

| Role (demo persona) | Highest-value flows |
|---|---|
| Platform administrator (`platform.admin`) | Create a School → bootstrap the administrator (console, ADR 0059) → activate; audit log; elevation |
| School administrator (`school.admin`) | Settings (timezone list); academic year, terms, grades, Sections, subjects, offerings; staff invitations and roles; admissions → conversion → placement; rollover dry run → execute → closed-year completion; Data Health; fee setup → assessment run → payment → receipt → statement |
| Principal (`principal`) | Student and Enrollment search; attendance registers and exceptions; teaching assignments; Student history; Data Health |
| Admissions officer (grant `admissions_officer` to a staff account) | Applicant → application → decision → conversion with guardian link |
| Teacher (`teacher`, enrol MFA first) | My curriculum delivery; My learning content and assignments; My attendance (MFA); My leave |
| Accountant / cashier (`finance.officer`) | Fee runs, offline payment (cash, cheque, bank) with allocation, receipt print, Student statement; the refusal of a second identical submission |
| HR and payroll (`hr.payroll`) | Employee directory filters; employment and assignments; staff attendance; leave approval; payroll run |
| Library / operations (`library.operator`, transport, hostel, front office, canteen and stores) | Catalogue → checkout → overdue → return; transport assignment; hostel residency; visitor check-in/out; inventory receive and issue |
| Guardian (`guardian01`, enrol MFA) | Messages; child's attendance and fees (development only); conversation reply; attempt another child's URL → refused |
| Student (`student`) | Login only: no portal (E46). Verify staff URLs are refused |

## 20. Final owner decision register

*Recommended answers are proposals, not approved decisions.*

| ID | Question | Why it matters | Recommended answer | Engineering impact | Privacy / legal impact | Blocks | Owner |
|---|---|---|---|---|---|---|---|
| D-01 | Minimum person-cohort N | Unblocks V11.1 | N = 10 platform-wide (Student N may be raised by counsel) | Config + read models | Legal sign-off required | V11.1 | Owner + counsel |
| D-02 | Suppression behaviour | Disclosure safety | "fewer than N" + complementary suppression + month windows | Suppression engine exists in shape | Legal / security | V11.1 | Owner + security |
| D-03 | Policy scope | Consistency | Platform floor, Schools may only raise; every person-counting output incl. export and API | Small | Legal / security | V11.1 | Owner |
| D-04 | Counsel review, Student Analytics | Children's data | Send the §4 question now | None until answered | Counsel determination | V11.1 production | Owner → counsel |
| D-05 | Guardian required? | Data completeness vs family reality | Optional; advisory Data Health check | One read-only check | Exceptional-family privacy | — | Owner |
| D-06 | Guardian email / phone / address required? | Reachability | Advisory only; address not modelled | Checks | Privacy for address | — | Owner |
| D-06d | Duplicate guardian contacts | Household hygiene | Allow an exact-hash advisory check via an ADR 0041 amendment | One check | ADR 0041 amendment + privacy | — | Owner + privacy |
| D-07 | ADR 0055 sender / test-email amendments | Branding, testing | Defer; build the read-only sender view only | Small | None | — | Owner |
| D-08 | Tier-1 staff notifications (ADR 0043 §12) | Operational alerts | Approve staff-only, opt-in, through Communications | Automation rules | None (staff only) | V11.4 | Owner |
| D-09 | Learning-space additions | Room planning | None now; home room if a pilot asks | Migration if approved | None | V11.6 | Owner |
| D-10 | Rollover prevention | Annual hygiene | Optional prompt after activation (reuse the V11.3 service) | Small UI | None | — | Owner |
| D-11 | SIS export | School returns | Approve a scoped design before pilot: per-dataset capability, audited, injection-safe | Medium | Data minimisation review | Production (practical) | Owner |
| D-12 | Global search | Convenience | Not now | — | — | — | Owner |
| D-13 | Lifecycle reason / effective date / approval (PHASE-1E-0 §13.2) | Reporting on leavers | Defer until a real requirement (e.g. transfer certificates) | Schema + services | Retention (E21 D7) | — | Owner |

## 21. Recommendation

**Begin structured local UAT** (the Local UAT / School Simulation brief).
- Every approved workflow is browser-operable, and V11 removed the visibility
  and reconciliation gaps a real School would hit first.
- Every remaining V11 item is decision-gated (D-01–D-13), and staging is
  blocked outside engineering (PPR R-00a).
- UAT will produce the evidence that should prioritise D-05–D-11.

**Separately, as governance and not the next phase:** schedule the E16
successor before 2026-11-10, or publication stops.
