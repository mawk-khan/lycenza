# Reference ERP parity matrix (ORG.9A, 2026-10-23)

Status: **parity baseline, documentation only** (ADR 0076 §66). Nothing here
is a capability, a role, a module, a Campus adoption or an implementation
decision. It maps the reference product's user-visible functions to
Lycenza's architecture so the owner can choose implementation waves.

Companion documents:
- `reference-parity/functions.csv` — the full function-level matrix (772
  rows, one per business function, with workbook row references);
- `PARITY-V1-BACKLOG.md`, `PARITY-POST-V1-BACKLOG.md`,
  `PARITY-DO-NOT-BUILD.md`;
- `REFERENCE-SCREENSHOT-REGISTER.md` (screenshot evidence, ORG.9A-R1), with
  `reference-parity/screen-families.csv`, `screen-filters.csv`,
  `screen-columns.csv` and `screen-contradictions.csv`.

*ORG.9A-R1 (2026-10-23, ADR 0076 §67) refined this baseline with 970
indexed reference screens. Results are in §15, and §6 keeps the ORG.9A
figures.*

## 1. Rules frozen for this audit

- **Reference evidence is not architecture.** Reference menu ≠ Lycenza
  module; reference menu permission ≠ capability; reference role ≠ Lycenza
  role; reference "branch" = Lycenza Campus, never a new branch table or
  tenant layer. The School is the only tenant; School Groups are governance
  and reporting only.
- **Security invariants:** role ≠ scope; capability ≠ menu item; module
  entitlement ≠ capability; platform authority ≠ School business authority;
  navigation is a projection of authorization. A reference "Yes" never
  becomes a Lycenza grant.
- **No competitor material in the repository** (ADR 0075 §1, ADR 0076
  §31.8). This document and the CSV describe every function in Lycenza's
  own words and point at the owner-held workbook by `sheet!row`; no
  reference menu path, wording, layout or screenshot is committed. The
  verbatim per-path working file stays outside the repository with the
  workbook.

## 2. Evidence

| Source | Status | Use |
|---|---|---|
| `Menu_Permissions_Master.xlsx` (owner-held; SHA-256 `96cf8596…b32b47a`, verified 2026-10-23, identical to ADR 0076 §31.8) | available | primary evidence: menu exposure only (E2) |
| 19 raw "Manage Menus" exports (owner-held) | available | not re-read: the workbook consolidates them (one duplicate export dropped by the owner) |
| Reference screenshot packs (HR, Finance, Inventory, Transport, SIS, MIS) | ORG.9A: not available. **ORG.9A-R1: available** — 1,005 owner-held files, 970 reference screens, 87 families | E3–E5 per function (§15, `REFERENCE-SCREENSHOT-REGISTER.md`) |
| Lycenza repository (ModuleCatalogue, CapabilityAndRoleSeeder, CampusScopeCatalogue, routes, services, schema, module docs, ADRs, UAT, LC audit) | available | every P0/P1/P2 claim was checked against code; every cited capability key exists in the seeder |
| Legal/production gate register (ADR 0058 §6, E01–E47) | available | gate per function |

**Evidence strength (ORG.9A; R1 raises 303 functions to E3+, see §15).** Every mapped function is **E2** (menu + exposure
evidence): every workbook path is switched on for at least one reference
persona. No workflow-level claim (E4/E5) is made from a menu label; where
the repository answers the question, the Lycenza side is code-verified.

## 3. Workbook audit

- **Sheets:** Read Me + three role-group sheets — Corporate (1,021 menu
  rows), Branch - Main (1,061), Branch - Principal Set (881).
- **Rows:** 2,963 menu rows after removing the three per-sheet "total"
  footers (the raw count 2,966 includes them); **1,596 unique paths**
  (Module/Section/Menu), 7 module groups, 52 reference modules.
- **Presence:** 663 paths appear in one sheet, 501 in two, 430 in three;
  two paths appear twice inside one sheet (6 in-sheet duplicate rows in
  total, all in Branch - Main).
- **Role columns:** 22 reference personas (7 Corporate, 10 Branch - Main,
  5 Branch - Principal Set). Values: Yes 13,626, No 7,872, "—" (persona not
  in that export) 664.
- **Sensitive flag:** 88 unique paths (163 rows) flagged by the owner.
- **Never exposed:** no path is "No" for every persona.
- **Irregular paths:** 86 paths have a menu equal to its section or a
  non-standard depth (headings or single-screen modules, e.g. a
  question-bank or class-wall screen); 15 Organisation rows are stored
  under a settings prefix and one row has a leading space — mapped on the
  normalized Module/Section/Menu.
- **Report-like paths:** 540 (section or menu names a report).
- **Exposure is not least privilege.** Several reference personas see
  almost everything (Accountant (Corporate), Computer Operator and
  Receptionist: 860 of 881; SysAdmin, Academic Incharge and MCB Support:
  ~all 1,021; a Branch Accountant sees 180 gradebook menus). The workbook
  therefore says what the product *offers*, never what a persona *should
  hold*.

## 4. Reference persona analysis (business intent only)

No Lycenza role was inferred. Each persona maps to business functions that
Lycenza already expresses through its fixed catalogue (ADR 0071, rule 93):

| Reference persona(s) | Business intent implied by exposure | Lycenza expression |
|---|---|---|
| SysAdmin, MCB Support, Franchise BDM | whole-product administration incl. vendor and franchise support | platform plane (root, Platform Manager) for tenant lifecycle and entitlements; never School business authority (rules 83–85, 99) |
| Academic Incharge (Corporate) | cross-School academic oversight | School Group governance and Group-safe reporting only (rule 84); no tenant writes |
| Admin, Principal, School Manager | School administration and approvals | `school_admin`, `principal` (whole-School or Campus-scoped per rule 101) |
| Accountant, Accountant (Corporate) | fees, collections, ledger | `accountant`, `cashier` (Campus-scopable), `school_admin` for approvals (ORG.8) |
| HR Manager, HR Team | staff records, leave, attendance, payroll | `hr_officer`, `hr_sensitive_records`, `payroll_officer`, with grant classes (rule 94) |
| Class Teacher, Class Coordinator, Staff | registers, LMS, marks | `teacher` (TeachingOwnership), `staff_self_service` |
| Front Office, Receptionist, Computer Operator | visitors, admissions intake, data entry | `front_office`, `admissions_officer` |
| Admission Incharge | admissions | `admissions_officer` |
| Librarian, Transport Incharge, Stores | module operations | `librarian`, `transport_coordinator`, `stores_officer` / `canteen_operator` |

## 5. Methodology

1. Each of the 1,596 unique paths was assigned to one **business function**
   (several paths may share a function; board-specific gradebook variants
   collapse into common functions), then mapped to the Lycenza domain,
   module entitlement, existing capability, scope, assurance model and legal
   gate, verified in the repository.
2. **Status** (exactly one, P0–P8 as defined in the brief) and **decision**
   are separate. A path that repeats an earlier function elsewhere in the
   reference navigation is **P8** and inherits that function's mapping.
3. **Product severity** (C0 critical … C4 optional) is recorded for gaps
   only (P2, P3, P4, P6); it is not security severity. **Complexity**
   (XS–XL) is relative, from repository evidence.
4. The function's primary status is the most frequent non-duplicate status
   among its paths.
5. Six domain clusters were mapped in parallel and then merged, validated
   (one output per path, valid codes, every cited capability present in the
   seeder) and normalized (one severity scale, one domain vocabulary).

### Coverage formulas

Let *n(Pk)* be the number of **functions** with status Pk.

- **Functional coverage** = (P0 + P1 + P7 + ½·P2) / (P0 + P1 + P2 + P3 + P4 +
  P6 + P7). Not-adopted (P5) and duplicate (P8) functions are excluded from
  the denominator. Strict variant: without the ½·P2 term.
- **V1-required coverage** = (P0 + P1 + P7 + ½·P2) / (P0 + P1 + P2 + P3 + P7):
  only functions that exist or are judged v1 candidates; post-v1 (P4) and
  legally blocked (P6) are outside the v1 denominator.
- These are coverage of the **reference's menu surface**, not application
  completeness: one reference product's packaging of 52 modules is not
  Lycenza's product scope.

## 6. Results

**Paths (1,596):** P0 67 · P1 113 · P2 109 · P3 28 · P4 319 · P5 152 · P6 210 ·
P7 66 · P8 532.

**Functions (772 rows; 723 distinct non-duplicate functions):** P0 57 ·
P1 89 · P2 80 · P3 26 · P4 234 · P5 103 · P6 92 · P7 42 · P8 49.

| Metric | Value |
|---|---|
| Functional coverage (weighted / strict) | **36.8 % / 30.3 %** (denominator 620) |
| V1-required coverage (weighted / strict) | **77.6 % / 63.9 %** (denominator 294) |
| Decisions (functions) | BUILD_POST_V1 214 · KEEP_EXISTING 160 · DO_NOT_BUILD 98 · LEGAL_DECISION_REQUIRED 89 · OWNER_DECISION_REQUIRED 75 · IMPROVE_EXISTING 56 · MERGE_INTO_EXISTING 35 · REPORT_ONLY 30 · BUILD_V1 15 |

Reading the numbers: the low overall figure is the reference's breadth
(LMS add-ons, six board gradebooks, a store, CRM, telephony, procurement),
most of it consciously post-v1, not adopted or legally gated. Within what a
School needs to run Lycenza's existing modules, ~78 % is present or partly
present; the remaining v1 gaps are mostly read models, filters and exports
on existing data, plus a small set of owner-gated features (certificates,
Student documents, opening balances, expenses).

## 7. Domain heatmap (functions)

Coverage % = weighted functional coverage per domain (formula above); "—" when nothing is in scope.

| Domain | Functions | P0 | P1 | P2 | P3 | P4 | P5 | P6 | P7 | P8 | Coverage % |
|---|---|---|---|---|---|---|---|---|---|---|---|
| fees_finance | 105 | 9 | 22 | 18 | 5 | 26 | 11 | 4 | 9 | 1 | 53 |
| communications | 85 | 6 | 9 | 8 | 1 | 27 | 9 | 2 | 1 | 22 | 37 |
| examinations | 66 | 1 | 3 | 3 | 0 | 12 | 3 | 43 | 0 | 1 | 9 |
| transport | 52 | 4 | 3 | 2 | 1 | 27 | 0 | 7 | 5 | 3 | 27 |
| inventory | 51 | 4 | 5 | 7 | 0 | 31 | 0 | 0 | 1 | 3 | 28 |
| sis | 50 | 4 | 8 | 3 | 3 | 13 | 6 | 7 | 6 | 0 | 44 |
| hr | 50 | 9 | 4 | 12 | 1 | 19 | 3 | 2 | 0 | 0 | 40 |
| none (not adopted) | 43 | 0 | 0 | 0 | 0 | 1 | 37 | 0 | 1 | 4 | 50 |
| organization | 39 | 1 | 15 | 5 | 0 | 1 | 10 | 0 | 2 | 5 | 85 |
| attendance | 33 | 2 | 3 | 3 | 3 | 6 | 4 | 9 | 2 | 1 | 30 |
| hostel | 33 | 2 | 3 | 0 | 0 | 21 | 0 | 0 | 4 | 3 | 30 |
| library | 32 | 3 | 1 | 8 | 6 | 6 | 0 | 1 | 3 | 4 | 39 |
| lms | 26 | 2 | 1 | 1 | 0 | 12 | 7 | 0 | 2 | 1 | 31 |
| admissions | 25 | 1 | 1 | 2 | 1 | 12 | 4 | 2 | 2 | 0 | 24 |
| academics | 18 | 7 | 5 | 3 | 0 | 2 | 1 | 0 | 0 | 0 | 79 |
| analytics | 16 | 0 | 0 | 0 | 1 | 2 | 1 | 12 | 0 | 0 | 0 |
| documents | 15 | 0 | 0 | 2 | 4 | 6 | 2 | 1 | 0 | 0 | 8 |
| curriculum | 14 | 0 | 2 | 3 | 0 | 6 | 2 | 0 | 1 | 0 | 38 |
| timetable | 8 | 1 | 2 | 0 | 0 | 2 | 0 | 0 | 3 | 0 | 75 |
| payroll | 4 | 1 | 1 | 0 | 0 | 0 | 0 | 2 | 0 | 0 | 50 |
| portal | 4 | 0 | 0 | 0 | 0 | 0 | 3 | 0 | 0 | 1 | — |
| canteen | 2 | 0 | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 50 |
| visitor | 1 | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 |
| **Total** | 772 | 57 | 89 | 80 | 26 | 234 | 103 | 92 | 42 | 49 | 36.8 |

## 8. Domain findings

**Finance and Fees (105 functions, 53 % weighted).** Strong: fee
structures, fee runs, charges, concessions (maker/checker), late fees,
offline payment recording, receipts, payment corrections (request →
approve), fee statements, outstanding and Campus cashier collection, ledger,
manual journals, periods. Partial: fee-head filter and totals across
charges, dues and collections (FIN-033, also the transport and hostel fee
reports), ageing buckets (FIN-054), billed/paid columns per Student
(FIN-039), month trend and as-at-date dues (FIN-031/034), account ledger
and daily cash book read pages (FIN-013/014), dishonoured-cheque reason and
list (FIN-042), finance audit-log filters (FIN-062). Missing for v1: trial
balance / balances page (FIN-012, reads `LedgerBalanceReader`) and opening
balances for ledger and legacy Student dues (FIN-009, owner decision
OD-SWC-5 / OD-PPR-20). Post-v1: expenses and payees (FIN-003/004, owner
scope), petty cash, cash deposits, bank reconciliation, budgets,
accounting-software export, payment gateway (E28). Not adopted: the school
store (Lycenza's Canteen is not a store), editing or deleting posted
payments, auto-debit and parent bank details (rule 91), configurable
approval settings (rule 102).

**HR and Payroll (54 functions, 40–50 %).** Strong: employees, employment,
assignments, reporting lines, leave (shared maker/checker), staff
attendance (direct with closed reason), payroll runs (shared maker/checker,
OD-8.4 production policy), statutory PF/ESI/PT, payslips, self-service.
Partial: HR list filters (status, multi-Campus, joining, reporting manager,
leave details), staff export (D-11), staff activity log, modified-attendance
report. Missing for v1: monthly staff attendance summary (HR-003),
month-wise leave report (HR-042), an HR overview page (HR-041 — staff counts
are not Student person-counting, but the read model must avoid HS fields).
Legally blocked: biometric/face/GPS attendance and late-comer feeds
(HRX-L2), loss-of-pay feed (HRX-L4), health reasons in leave (HRX-L1).
Post-v1: recruitment, appraisal and classroom observation (needs HR
sensitivity classification first), exit clearance, comp-off and hourly
permissions, regularization requests (owner decision).

**SIS (50 functions, 44 %).** Strong: directory, profile, identity edit,
status change with assurance (ORG.8E/8G), Section and Campus transfer,
rollover, historical placement closure, number allocator, electives,
Guardians. Missing/partial for v1: certificates from templates with a
numbered issue register (CRT-001) and the transfer/leaving certificate
(CRT-002) — owner decision on the Student document owner type (OD-SWC-2) and
retention (E21); sibling list (SIS-019), nominal roll with export
(SIS-016, D-11), class family-contact list (SIS-020, encrypted contacts,
ADR 0041 review). Post-v1: bulk Campus transfer (SIS-008), learner profile,
discipline, mentors, PTM and appointments, quotas, gate pass. Not adopted:
bulk approval of inactivation/reactivation (each change needs its own
reason, fresh MFA and current authority — ORG.8E).

**Admissions (25 functions, 24 %).** Strong: applicants, applications,
decisions, conversion, fee selections. v1: correcting applicant and
application details (FIN-026 / ADM — this is LC-5 M5), date-range and
joiner/cancellation views (ADM-004/005). Post-v1 and the largest breadth
gap: enquiry/lead capture, follow-up and counsellors (about 60 reference
paths; ADMISSIONS §3/§9.4 defer it; privacy review), entrance tests,
applicant self-service links. Pre-admission application/registration fees:
owner decision (no applicant charge subject, ADR 0067 §29).

**Attendance (33 functions, 30 %).** Strong: timetable-bound registers,
teacher entry (TeachingOwnership, E33 production conditions), Campus
management, corrections with closed neutral reasons (ORG.8E — no approval
and no window, kept). v1: classes with no register for a date (ATT-002 =
LC-6 SB-01), one Student's attendance over a range (ATT-003 = LC-6 ATT-07).
Owner decision: daily (non-period) attendance (EXM-058; Lycenza registers
are timetable-bound by design). Legally gated: absence alerts to guardians
(COM-054, E46 for the family-facing side; email E17–E20 for production),
percentages and threshold reports (V11.1 person-counting gate, E44).

**Examinations and academics (84 functions).** Six board gradebooks
collapse into ~25 common functions (exam definition, schemes, weightage,
grade boundaries, descriptor scales, marks entry, remarks, report-card
settings/generation/publication, result computation, promotion,
transcripts, registers, analytics). Existing: exams and papers (also the
exam timetable), grade scales (percentage bands), marks entry for
development only with ownership, MFA, paper lock and maker/checker
corrections. **43 functions are P6:** E36/E37 (marks production), E39
(results), E40 (report cards), E41 (transcripts), E42+E46 (publication to
families), E44 (statutory academic rules). Post-v1 without a gate: hall
tickets via Documents, room allocation and invigilation, descriptor scales,
learning-outcome catalogue. Report-card publication, when allowed, must be
a domain-owned workflow on the shared foundation (rule 102) — the reference
has only publish toggles.

**Library (32 functions, 39 %).** Strong: titles, copies, circulation
(Campus), fines with the ORG.8E/8G void assurance (E34). v1 operational
completion: maximum loans per borrower (LIB-019), default loan period
(LIB-021), renewal (LIB-010), web title edit (LIB-012, API exists),
category/language/publisher masters (LIB-022/024/025), loan filters
(LIB-031/035/037). Owner decision: staff borrowing (LIB-011; loans are
Student-only).

**Inventory (51 functions, 28 %) and Canteen.** Strong: items, locations
(Campus frozen once used), balances, append-only movements, Canteen
consumption. Partial: issue with recipient and reason, damaged write-off as
a new movement kind (INV-011/014 — never a correction), stock filters,
monthly stock read model. Post-v1: procurement (purchase orders, goods
inward, vendors, contract approvals — a domain-owned workflow if built),
barcodes.

**Transport (52) and Hostel (33).** Strong: routes, stops, vehicles, rider
assignments (Campus), hostels, rooms, beds, residencies; fees through the
OPF selection seam. v1: bulk rider upload through
`TransportStudentAssignmentService` per row (TRN-036), route riders vs
capacity (TRN-015), fee-head filters for transport/hostel dues. Legally
gated: GPS/vehicle tracking and address maps (privacy review). Post-v1:
transport attendance/scanners, maintenance and fuel, hostel attendance,
gate pass, mess, warden assignment (HOSTEL §16 non-scope).

**Documents and certificates (15).** No module per document type.
Certificates and the TC are one SIS template/number/register feature;
no-dues reads Fees; report cards and promotion certificates are results
outputs (E39/E40); staff certificates belong to HR. Student-owned uploads
wait for the Student document owner type (OD-SWC-2); ID cards and photos
need the privacy review (ADR 0075 §10.2 register).

**Communications (85, 37 %) and Portal.** Strong: announcements with
approval and emergency publish, conversations, templates, in-app and email
(durable layer). v1: a staff-only audience (COM-016/034 — today the
School-wide audience is every active membership, Guardian and Student
accounts included; the preview shows the breakdown, but after E46 a
staff-intended notice could reach families), fee-due reminder emails
(COM-045, a new `EmailPurpose` reviewed under ADR 0055), a School events
calendar (COM-024 / LMS-115). Post-v1: SMS, WhatsApp, voice, push (E28).
Not adopted: walls, birthday features (date of birth), marketing campaigns,
configurable menus and user types, credit recharge. Portal: Guardian
access, multi-child and reply inbox exist (development-only until E46);
family views of calendars, diary and policies wait for E46.

**Organization, identity and platform (39, 85 %).** Strong by design and
mostly P1: School settings, Campuses, staff accounts and invitations,
security settings fixed in code, platform registry/groups/entitlements.
Not adopted: billing, invoices, KYC, merchant registration, support
tickets, vendor app menus, username aliases (canonical email, rule 90).
Owner decision: a School-scoped sign-in log (login events are
identity-level on the platform ledger; a School view needs a new minimal
read model).

**Reporting and MIS.** 540 reference paths are reports. Classification of
report functions: existing report (P0/P7), FILTER_GAP, NEW_READ_MODEL,
EXPORT_GAP (D-11 — no export exists anywhere in Lycenza today),
DASHBOARD_GAP, POST_V1_ANALYTICS; person-counting dashboards stay behind
the V11.1 gate (D-01..D-04, D-14 for money). A report never needs a new
write capability.

## 9. Approval parity

| Function | Reference approval | Lycenza | Difference justified | Gap |
|---|---|---|---|---|
| Offline payment | approve before effect | direct recording: closed method, fresh MFA, server idempotency; corrections request → approve (ORG.8C) | yes — records money already received; correction is the controlled path | none |
| Refund / reversal / reallocation | approve | request → another person approves (ORG.8C) | equal | none (bulk refund not adopted) |
| Fee concession | approve | maker/checker + Guardian conflict (ORG.8A) | equal or stronger | G-3 LOW |
| Charge cancel / unassign | approve | DIRECT_WITH_ASSURANCE (ORG.8G) | yes (OD-8.3) | none |
| Journal voucher | approve | direct with MFA; subledger guard | yes; reverser ≠ poster deferred (OD-8.2) | OD-8.2 |
| Expenses / petty cash / cash deposit | approve | not built | — | post-v1 domain-owned workflow if built |
| Bank reconciliation | approve | not built | — | post-v1 |
| Payroll | approve | shared maker/checker (ORG.8B) | equal | OD-8.4 (production policy) |
| Leave | approve | shared maker/checker, whole-School (ORG.8D) | equal | none |
| Staff attendance modification | approve | direct, closed reason, self refused | yes (design) | owner may add regularization (post-v1) |
| Student attendance correction | approve | direct, closed neutral reason (ORG.8E) | yes (8E-D2) | none |
| Marks correction | approve | domain maker/checker (E36/E37) | equal | gated |
| Report-card publish | publish toggles | not built | — | E39/E40/E42; rule 102 workflow if built |
| Transfer certificate | approve/generate | not built | — | CRT-002 (owner) |
| Student inactivate/reactivate | approve (incl. bulk) | DIRECT_WITH_ASSURANCE (ORG.8E/8G) | yes (8E-D1) | none |
| Inventory purchase / contracts | approve, configurable chains | not built | configurable chains not adopted | post-v1 |
| Transport | none evidenced | — | — | none |

Reference approval is not assumed safer: several reference approvals sit on
operations Lycenza makes structurally safe (immutable money records,
DB-enforced evidence) or decides with stronger assurance (fresh MFA,
current authority, related-party refusal).

## 10. Filters, bulk actions, imports and exports

- **Filters** (tracked as FILTER_GAP, never as a missing workflow): fee
  head, concession category, month, as-at date, cancelled/reversed state,
  dishonoured cheques; application date range; HR employment status,
  joining date, multi-Campus, reporting manager; stock by location/date/type;
  loans by Student/title/date/Campus; audit-log filters and Campus
  dimension (never widening who sees the actor).
- **Bulk actions seen:** bulk rider upload (v1, per-row service), bulk
  Campus transfer (post-v1: whole-School only, per-Student lock order,
  transfer blockers, per-item outcome, never silent skip), bulk refunds and
  bulk fee unassign (not adopted: money corrections are per-item with
  assurance), bulk inactivate/reactivate approval (not adopted), bulk
  report-card publish (gated), bulk receipts (not adopted).
- **Imports:** every bulk import must call the canonical service per row
  with the importer's authority; no import may write tables directly. Fee
  receivable uploads are not adopted (they would bypass fee runs and the
  ledger).
- **Exports:** none exist in Lycenza today; SIS and HR exports are the open
  owner decision D-11 (data minimisation, audit, watermarking); an
  accounting-software export is post-v1 (E28).

## 11. Duplicates and navigation variants

532 reference paths (P8) and 42 functions (P7) are the same business
function reached from another menu (board gradebooks, the same report under
analysis and reports, lead-CRM screens repeated across admissions sections,
fee screens inside transport, hostel and library). One Lycenza function
satisfies each group; the CSV records `reference_paths` per function and
`duplicate_of` per path in the owner-held working file.

## 12. Intentionally different (P1)

These functions exist in Lycenza but deliberately differ from the reference
(stronger authorization, the Campus model, domain-owned workflows, merged
functionality, legal constraints, no duplicate modules):

| ID | Domain | Function | Why different |
|---|---|---|---|
| FIN-019 | payroll | Post payroll to the books | Payroll posts through Finance from the Payroll module (payroll->finance), not from expenses (ADR 0076 §31.8). |
| FIN-016 | fees_finance | Carry account balances into the next financial year | Period close writes carried-forward baselines automatically and verifies them; no manual carry-forward step (ADR 0064). |
| FIN-020 | fees_finance | Move a recorded payment to another Student | Corrections across Students not built by design; reverse (approved) then record anew for the right Student. |
| FIN-080 | sis | Campus transfer of a Student | Campus transfer is a direct dual-scope action with blockers and audit, no request queue (matrix §2). Charges keep stored Campus. |
| FIN-051 | fees_finance | Approve offline payments before they count | Offline payments are recorded directly (fresh MFA, server idempotency, immutable); mistakes go through approved corrections. |
| FIN-024 | fees_finance | Remove (cancel/void) an assessed fee from a Student | Charge cancel/void is DIRECT_WITH_ASSURANCE (OD-8.3 closed): closed reason, fresh MFA, Guardian refusal; no request queue. |
| FIN-049 | fees_finance | Charge and collect other (ad-hoc) fees | Ad-hoc charge then offline payment; Campus cashier only for one stored Campus. |
| FIN-057 | fees_finance | Assign optional/miscellaneous fees to selected Students | Competitive exam fee = optional fee-head line selected per Student and billed by a run, or an ad-hoc charge. |
| FIN-058 | fees_finance | Bill Students from fee structures (bulk, by instalment) | Fee assessment runs bill every placed Student of a grade structure per instalment (preview, overlap review, execute). |
| FIN-059 | fees_finance | Change instalment due dates | Due dates live on instalment schedules, immutable once active; change by a successor structure (E31 for in-year changes). |
| FIN-066 | fees_finance | Carry previous-year dues into the new year | Charges persist across years and stay collectable; no carry-over posting needed. Pre-Lycenza dues are FIN-009. |
| FIN-085 | fees_finance | Fees for Students who moved Campus | Charge Campus is stored and never moved by a transfer (ORG.7G-1A); whole-School staff collect. |
| FIN-067 | fees_finance | Campus-specific fee settings | Campus override structures; Campus is a scope, not a finance entity or tenant. |
| FIN-069 | fees_finance | Fee heads (types) incl. exam/competitive/misc fees | Exam fees are ordinary fee heads; no separate fee-type catalogue. |
| FIN-084 | canteen | Canteen meal pricing and orders for Students | Canteen item prices for Students; staff meals not adopted. |
| FIN-071 | fees_finance | Receipt format and numbering | One fixed acknowledgement receipt; form changes only after E30. |
| FIN-048 | fees_finance | Admission fee after conversion | Admission fee head selected at conversion, billed by run, paid as an offline payment. |
| STR-008 | inventory | Store stock levels | Inventory stock balances by Location cover stock levels; no store-specific view. |
| ADM-017 | sis | Student/admission number allocation | Forward-only School counter, never reused or typed; no configurable format/register series. |
| ADM-002 | admissions | Accept or reject an application | Decision by a holder of admissions.manage; no separate selection-approval stage. |
| ADM-015 | fees_finance | Admission fee after conversion and its payment | Admission fee is charged only after conversion and collected via manual payment recording (OPF.3, ADR 0067). |
| ADM-016 | transport | Transport fee for newly admitted students | Charges need a Student; transport assignment and fee intent follow conversion. |
| SIS-032 | sis | Re-admit a former student | Reactivation plus new placement; Admissions conversion always creates a new student (ADMISSIONS s10). |
| SIS-004 | sis | Deactivate or reactivate a student | Reference request/approve replaced by direct action with closed reason, fresh MFA, row lock, lifecycle event (ORG.8E 8E-D1). |
| SIS-007 | sis | Move a student to another Campus | Transfer needs scope over BOTH Campuses at write time instead of inward/outward approvals; Campus-request option open (s44.25). |
| TTB-001 | timetable | Bell/period structure | Register times come from timetable periods; no separate attendance clock. |
| ATT-001 | attendance | Take student attendance | Daily roll is replaced by period registers bound to the timetable (ADR 0076 s31.8 boundary). |
| SIS-013 | academics | Assign electives, languages and streams | Elective groups plus subject enrollments; a named stream package is not modelled (no pilot need). |
| SIS-027 | communications | Email address syntax checking | Validation happens on entry; delivery problems are handled by the email suppression layer (ADR 0055). |
| FEE-004 | fees_finance | Move students into next year's fees | No separate financial promotion: fee runs follow the new placement. |
| TTB-005 | timetable | Teacher weekly teaching load | Weekly entries per teacher visible; no contracted workload data, never labelled workload compliance (V11 K3/K4). |
| SIS-018 | sis | Student record change history | Actor deliberately hidden; who acted is in the audited School audit log (school.audit.view). |
| TTB-006 | hr | Teacher assignment change history | V11 K5/L2: timeline with actor plus dated assignments. |
| TTB-007 | organization | Teaching staff activity log | Actions are audited; no separate staff-surveillance report (V11 K6). |
| SIS-024 | sis | Reasons for student deactivation | Closed code catalogue (no other/free text) by design (ORG.8E); not School-configurable. |
| SIS-011 | sis | Promotion rules configuration | Per-plan grade/section and subject mappings replace a global promotion settings page. |
| EXM-001 | examinations | Define an exam window and its subject papers | A competitive/olympiad exam is just another Examination window; external entrance results are not modelled. |
| EXM-048 | academics | Subject streams | Streams map to ElectiveGroups of Offerings. |
| EXM-002 | examinations | Classify exams by type (unit test, term exam) | Code/name distinguishes exam kinds; a typed category only matters for result weighting, which is E39-gated. |
| EXM-055 | lms | Projects set as assignments | LMS assignments with Section audiences and teacher ownership cover project setting. |
| EXM-050 | academics | Assign teachers to subjects and classes | TeachingAssignment (Section x Offering) and elective assignments; source of TeachingOwnership. |
| EXM-032 | examinations | Exam timetable | Papers carry date and start/end time, Offering-wide (no Section/room); Campus exam-papers hub is a known UX gap (§53.16). |
| EXM-068 | academics | Academic departments | AcademicDepartment groups Subjects; higher-ed programme departments map here. |
| EXM-063 | curriculum | Curriculum / syllabus | Syllabus units per Offering plus delivery tracking. |
| HR-010 | attendance | Correct a staff attendance record | Parity diff: reference approves; Lycenza corrects directly (closed reason, CAS, append-only row, self refused). ADR 0076 §64.25 candidate. |
| HR-019 | attendance | Attendance correction reason list | Closed code-owned reasons, never health; not School-configurable by design. |
| HR-023 | hr | Leave approval routing settings | Topology is code-owned: reporting-line manager or admin path; no configurable chains (ADR 0065, rule 102). |
| HR-026 | communications | HR email templates | Communication templates exist; system email purposes are a closed catalog (rule 89), not free HR templates. |
| HR-037 | hr | Leave allocation and balance updates | Annual allocation runs plus adjustments instead of monthly edits; admin never adjusts own leave (G-4). |
| HR-062 | hr | Outsourced staff | Handled as employment type/category, not a separate register. |
| LMS-100 | academics | School subject catalogue | One School-scoped catalogue under the School board, not per-board menus. |
| LMS-114 | curriculum | Teaching plan status review | Coverage by offering/section is the status review; no reviewer sign-off. |
| COM-013 | communications | Classify announcements by type | Priority, required flag and templates instead of a free type list |
| COM-033 | communications | Send email to selected recipients | Email is a channel of an announcement through the durable email layer, not a free-form mailer |
| COM-035 | communications | Email a class, grade or subject group | Grade, section and subject-offering audiences, guardian addresses resolved server-side |
| COM-036 | organization | Send account access to staff and guardians | One-time invitation/activation links only; credentials are never sent |
| COM-039 | communications | Email channel settings | Channel and timing policy per School; sender identity is platform-fixed, no per-School SMTP |
| COM-044 | communications | General email templates | One generic template catalogue with activate/deactivate |
| IDN-001 | organization | Two-factor authentication for staff | Per-user TOTP MFA with step-up on sensitive actions; no School toggle forcing enrolment |
| IDN-002 | organization | Two-factor authentication for guardians | Guardian portal pages require current MFA; portal is development-only until E46 |
| IDN-004 | organization | Password policy configuration | Password rules are platform-fixed in code, not School-configurable |
| IDN-005 | organization | Reset a user's password | Self-service recovery plus operator console reset; a School never resets a cross-School identity |
| IDN-007 | organization | Issue guardian portal access | Guardian account invitations; credentials are never displayed or stored readable |
| ORG-002 | organization | Enable learning modules per School | Module entitlements are platform-managed with fresh MFA; no curriculum-specific variants |
| ORG-012 | organization | School operational settings | A reference branch maps to a School (tenant) or a Campus (scope); settings are name, timezone, locale and profile |
| ORG-015 | organization | Guided School onboarding | Platform provisioning, bootstrap administrator and the School Setup hub |
| ORG-020 | organization | Manage School administrator accounts | Platform sets the first administrator only while provisioning; afterwards only the School grants access |
| ORG-022 | organization | Manage the multi-branch organisation | Platform registry and School Groups; Group authority never becomes School authority |
| ORG-030 | organization | School security settings | Security controls are fixed in code, not School-toggleable |
| ORG-032 | organization | Personal account preferences | Account security page and communication preferences |
| ORG-037 | organization | Report of access per role | Role-to-capability visibility exists; menus are not the access model |
| COM-071 | communications | Chat with students | Staff-to-student conversations under School policy; no student-to-student chat |
| HST-002 | hostel | Place hostel on a campus | Hostel belongs to one Campus (frozen once it has Rooms). Cross-School/corporate sharing of a building is not a tenant concept. |
| HST-006 | fees_finance | Record hostel fee payment | Hostel fees are FEE charges from fee-run optional selections; payment recorded in Fees, not Hostel. |
| HST-013 | hostel | Vacate or move a resident's bed | Move = explicit end() then fresh assign(); no in-place transfer by design. |
| HST-014 | communications | Announce to hostel residents | Communications announcements (grade/section audiences, approval). No hostel-resident audience. |
| HST-015 | inventory | Hostel store item master | Hostel stock items belong in Inventory items + a hostel Location. No item categories (deferred). |
| HST-023 | inventory | Hostel consumables stock in/out | Receive/issue at a hostel Location covers quantities; purchase cost/vendor not modelled (procurement deferred). |
| HST-025 | hostel | Residents lacking enrolment | Assign requires an active, currently enrolled Student under lock, so the exception list is structurally prevented. |
| HST-048 | fees_finance | Per-student hostel money transactions | Student fee statement shows all charges/payments incl. hostel lines; deposits/wallets not modelled. |
| INV-009 | inventory | Stock on hand by campus | Balances per Location; Location carries Campus (frozen once used); Campus scope filters. |
| INV-025 | inventory | Transfer stock between campuses | Atomic Location-to-Location transfer, scope needed over both; cross-School transfer impossible by tenancy. |
| LIB-005 | fees_finance | Record library fine payment | Fines are FEE event charges (OPF.4 seam); paid via Fees payment recording, never in Library. |
| LIB-017 | library | Define library per campus | Copy Campus acts as branch library; multiple libraries within one Campus not modelled. |
| TRN-028 | transport | Route by fee slab | Slab = route tier -> fee head mapping; assignment selects fee line. Amounts live only in FEE. |
| TRN-030 | transport | Place vehicle on a campus | Vehicle carries optional Campus; fleet stays whole-School. |
| TRN-041 | fees_finance | Select student's transport fee | Fee intent recorded on assignment via FEE selection seam; charge only from fee runs. |
| TRN-042 | fees_finance | Route-to-fee mapping | Route -> fee head (no amount); amounts in FEE structures. |
| TRN-043 | fees_finance | Record transport fee payment | Paid through Fees payment recording; Transport holds no money. |

## 13. Architecture and security review of the recommendations

Every recommendation was checked against the Lycenza invariants. Reference
designs that would violate them are DO_NOT_BUILD or need a redesign:

| Reference design | Conflict | Resolution |
|---|---|---|
| copying tests, ECA or catalogues "to other branches", an organisation content planner or catalogue | cross-School writes (rule 84: Groups never write tenant data) | DO_NOT_BUILD / per-School only |
| branch-shuffled report cards, organisation MIS | marks or person counts across Schools | P6 (E39/E42, ADR 0048 Group-safe reports only) |
| menus, user types, user-type preferences, per-student portal menu toggles | menu-as-security | DO_NOT_BUILD: navigation follows capabilities and entitlements (rules 98, 100) |
| configurable approval chains, a pending-requests inbox | generic workflow engine | DO_NOT_BUILD: approval topology is code-owned, domain-owned (rule 102) |
| admin users and security settings in the School area after activation | platform-to-tenant authority | split: platform plane vs School-owned staff access (ADR 0047 D13) |
| branch-wise expenses / cost centres | a Campus dimension on journal lines | needs its own ADR; Campus is never a tenant |
| fee screens inside transport, hostel, library; fee-receivable uploads | Finance source ownership | P1 via OPF seams; uploads not adopted |
| mentor or class-teacher links | a new ownership authority | needs its own contract (ADR 0063) |
| classroom observation, teacher ratings, teacher-wise analysis | sensitive-HR expansion / staff profiling | post-v1 after HR sensitivity classification; ratings not adopted |
| login statistics per School | identity-level events leaking across Schools | owner decision: a minimal School-member read model |
| bulk inactivate/reactivate approval, bulk refunds | approval/assurance bypass | not adopted |

No recommendation adds a tenant layer, a branch table, a role cloned from a
reference persona, a capability for parity, a Campus adoption or a platform
path into tenant business data.

## 14. Relation to LC-5 … LC-10 (paused)

| Parity item | LC item | Recommendation |
|---|---|---|
| Correct applicant / application details (FIN-026) | LC-5 (M5) | reuse LC-5; do not duplicate |
| Undo a deactivation | LC-5 | already the ORG.8E reactivation path |
| Classes with no register (ATT-002), one Student's range (ATT-003) | LC-6 (SB-01, ATT-07) | reuse LC-6 |
| Daily (non-period) attendance | LC-6 ATT-08 (owner decision) | one owner decision for both |
| Student status confirmation (STU-14), correction reason (ATT-06) | LC-8 / LC-6 | delivered by ORG.8E |
| Library small UX (LIB-05), enrollment UX (ENR-01) | LC-8 | fold the library operational items into the same slice |
| Operations edits in the browser (web title edit LIB-012, staff documents web upload) | LC-9 (S-09) | reuse LC-9 per module family |
| Cashier day reconciliation | LC-4 (done) | delivered |

ORG.8 closure is not a prerequisite for any LC item; LC-5 … LC-10 stay
paused until the owner resumes them.

## 15. ORG.9A-R1 — screenshot evidence refinement (2026-10-23)

R1 indexed the owner's screenshot pack visually, grouped the screens into
87 families, and reconciled every family against this matrix and the
repository. The registers are in `REFERENCE-SCREENSHOT-REGISTER.md`.

**Function rows.** There were 772 rows, and there are now 787: 15 new
screenshot-proven functions, each with `reference_paths = 0`. The path total
is still 1,596.

Five duplicate ids published by ORG.9A were renumbered: HST-050, DOC-016,
DOC-017, DOC-018 and TRN-055.

| Status | ORG.9A | R1 | Δ | Traced to |
|---|---|---|---|---|
| P0 | 57 | 57 | 0 | +ACA-002, −FIN-061 |
| P1 | 89 | 93 | +4 | +FIN-061, +HR-083, +HR-087, +HR-088 |
| P2 | 80 | 81 | +1 | +HR-090 |
| P3 | 26 | 25 | −1 | FIN-036 → P8 |
| P4 | 234 | 242 | +8 | +ORG-035, −HR-005; new HR-084, HR-086, TRN-056, SIS-036, ACA-003, INV-043, FIN-090, FIN-091 |
| P5 | 103 | 103 | 0 | −FIN-088, +HR-089 |
| P6 | 92 | 94 | +2 | +HR-005, +HR-085 |
| P7 | 42 | 41 | −1 | ORG-035 → P4 |
| P8 | 49 | 51 | +2 | +FIN-088 (of FIN-057), +FIN-036 (of FIN-033) |

| Coverage | ORG.9A | R1 | Δ |
|---|---|---|---|
| Functional, weighted | 36.8 % (228 / 620) | 36.6 % (231.5 / 633) | −0.2 |
| Functional, strict | 30.3 % | 30.2 % | −0.1 |
| V1-required, weighted | 77.6 % (228 / 294) | 77.9 % (231.5 / 297) | +0.3 |
| V1-required, strict | 63.9 % | 64.3 % | +0.4 |

The deltas are small and explained by the table above. Screenshots
mostly confirmed the ORG.9A classifications rather than overturning them.

**Duplicates recheck.** P8 functions rose from 49 to 51: FIN-088 and
FIN-036. Duplicate paths are 532 plus the 4 paths of those two rows. Path
remaps the screens revealed are recorded only, in
`screen-contradictions.csv`; the ORG.9A path counts are unchanged:
- FIN-007 → FIN-028
- FIN-031 → FIN-038
- HR-008 → TTB-007
- TRN-037 → TRN-033

**P2/P3 revalidation.** Every P2 and P3 function with screen evidence was
re-read without build bias: 69 rows across the three reconciliations. One
moved (FIN-036 to P8). The proposed move of HR-047 (P6 to P2) was rejected (§6 of the register). The PV1-01 … PV1-04 functions keep their status and are gated in `PARITY-V1-BACKLOG.md`. The 103 not-adopted
functions stay at 103.

**Evidence.**
- E3+ covers 303 of 787 functions (38.5 %); E5 (screen plus repository comparison) covers 150.
- Workflow was observed on a mapped screen for 35 functions.
- By domain: Finance 68/107 E3+, HR 41/55, Transport 36/53, Inventory 35/52, SIS 23/51.

**ORG.9B.** The Finance deep review and the final proposed scope (9B-1 …
9B-4, read-only, existing capabilities) are in `PARITY-V1-BACKLOG.md`.
- PV1-01 is NEEDS_REDESIGN with owner decision OD-9.1, because Charges carry no fee head.
- Ageing and the trial balance are INSUFFICIENT_EVIDENCE.

## 16. ORG.9B — Finance office read models built (2026-10-23)

ORG.9B (ADR 0076 §68) built the four authorized read models: read-only, on existing capabilities, with no schema change.

| Item | Function | ORG.9A-R1 | After ORG.9B |
|---|---|---|---|
| 9B-1 billed / concession / paid / balance per Student, filtered totals | FIN-039 | P7 | P7 (improved) |
| 9B-2 outstanding as at a School-local date (posting-date basis) | FIN-034 | P2 | **P1** (intended difference: posting date, not received date or month) |
| 9B-3 account ledger with opening, running and closing balance | FIN-013 | P2 | **P0** |
| 9B-3 cash / bank book variant | FIN-014 | P2 | P2 — **stopped**: no structural cash or bank classification of ledger accounts |
| 9B-4 audit-log filters | FIN-062 | P2 | **P1** (intended difference: correction records, never in-place before/after) |

Statuses are now P0 58, P1 95, P2 78; the rest are unchanged.

| Coverage | After R1 | After ORG.9B |
|---|---|---|
| Functional, weighted / strict | 36.6 % / 30.2 % | **36.8 % / 30.6 %** |
| V1-required, weighted / strict | 77.9 % / 64.3 % | **78.5 % / 65.3 %** |

The delta comes from three P2 functions becoming present (+1.5 weighted, +3 strict).

**Unchanged by ORG.9B:**
- OD-9.1 (fee-head dimension, FIN-033);
- dues ageing (FIN-054) and the trial balance (FIN-012), both INSUFFICIENT_EVIDENCE;
- D-11 (exports).

## 17. ORG.9C-1 — Daily operations read models built (2026-10-23)

ORG.9C-1 (ADR 0076 §69) built the authorized PV1-05, PV1-06 and PV1-07 reads. They are read-only, on existing capabilities and Campus contracts, with no schema change, export or new authority.

| Function | Before | After ORG.9C-1 | Note |
|---|---|---|---|
| ATT-002 classes with no register for a date | P3 | **P1** | intended difference: neutral submitted / pending / not submitted, never "missing" (no Student holiday or closure calendar exists) |
| ATT-003 one Student's attendance over a range | P2 | **P0** | records only, never a rate (ADR 0040) |
| HR-003 monthly staff attendance summary | P3 | **P1** | intended difference: no late, no-punch or loss-of-pay figure (HRX-L2/L4) |
| HR-040 inactive / separated staff | P2 | **P0** | employment-state filter |
| HR-042 month-wise staff leave | P3 | **P0** | approved units, never a reason (HRX-L1) |
| HR-043 staff leave details | P2 | **P0** | employee, type, date filters |
| HR-044 reporting manager listing | P2 | **P1** | intended difference: a reports-to filter, not a tree |
| HR-045 multi-Campus staff | P2 | **P0** | |
| HR-056 staff joining report | P2 | **P0** | |
| LIB-031 / LIB-035 / LIB-037 loans by period, Campus, Student | P2 | **P0** | E2 evidence only (no Library screenshot pack) |
| LIB-010, -012, -019, -021, -022, -024, -025 | unchanged | **stopped** | renewal, title edit, borrower limit, default loan period, masters: transactional or configuration, not a read slice |

Statuses are now P0 67, P1 98, P2 69, P3 22; the rest are unchanged.

| Coverage | After ORG.9B | After ORG.9C-1 |
|---|---|---|
| Functional, weighted / strict | 36.8 % / 30.6 % | **38.0 % / 32.5 %** (240.5 / 633) |
| V1-required, weighted / strict | 78.5 % / 65.3 % | **81.0 % / 69.4 %** (240.5 / 297) |

**Unchanged by ORG.9C-1:**
- PV1-08 Inventory, PV1-09 Transport bulk upload and ORG.9D Communications: not started.
- D-11 exports, dues ageing and the trial balance: still open or INSUFFICIENT_EVIDENCE.
- PV1-19 daily (non-period) attendance: the LC-6 ATT-08 owner decision.

**New owner question (OD-9.3):** a Student school calendar (holidays, closures, cancelled periods). It would make a "missing register" status structurally decidable. It also overlaps PV1-12.

## 18. ORG.9C-2 — Inventory issue and write-off (2026-10-23)

ORG.9C-2 (ADR 0076 §70) built PV1-08's two transactional items on the existing ledger, with one narrow schema change (`stock_movements`: a `write_off` type with a closed reason, an optional issue Department recipient, a form key).

| Function | Before | After ORG.9C-2 | Note |
|---|---|---|---|
| INV-011 write off damaged stock | P2 | **P0** | `write_off` movement; damaged, expired or lost; fresh MFA and fresh authority; ordinary stock only |
| INV-014 issue stock out of a store | P2 | **P1** | intended difference: terminal issue with an optional HR Department recipient; no person, no returnable issue |
| INV-007 issued items listing | P2 | P2 | the web history now filters by type and shows the Department; no person or date filter |
| INV-008 / INV-022 / INV-024 staff and Student issue | P4 | P4 | person recipients **stopped**: tenant-lifetime movements carry no personal data (E21.2G O5); E21/owner decision |
| INV-034 configurable approval chains | P5 DO_NOT_BUILD | unchanged | write-off is DIRECT_WITH_ASSURANCE |
| INV-003 fixed-asset register / disposal | P4 | unchanged | no asset model; no disposal built |

Statuses are now P0 68, P1 99, P2 67; the rest are unchanged.

| Coverage | After ORG.9C-1 | After ORG.9C-2 |
|---|---|---|
| Functional, weighted / strict | 38.0 % / 32.5 % | **38.2 % / 32.9 %** (241.5 / 633) |
| V1-required, weighted / strict | 81.0 % / 69.4 % | **81.3 % / 70.0 %** (241.5 / 297) |

**Remaining PV1-08 items (not authorized in ORG.9C-2):**
- stock filters and the monthly stock view (INV-016, INV-023, INV-020);
- a count or stocktake correction;
- returnable issue.

**OD-9.3 CLOSED for v1 (owner, ORG.9C-2 brief):** Attendance register states stay submitted, pending and not submitted. There is no "missing" state until a canonical Student academic calendar or closure model exists.

## 19. ORG.9C-3 — Inventory read and reporting completion (2026-10-23)

ORG.9C-3 (ADR 0076 §71) built the three remaining read rows of PV1-08. They are read-only, on `inventory.stock.view` with the existing Location scope; there is no schema change, export or new authority.

| Function | Evidence | Before | After ORG.9C-3 | Note |
|---|---|---|---|---|
| INV-016 stock ledger by Campus | SF-INV-05 (Campus register: Campus, Item, as-on date or date range) | P2 | **P0** | register filtered by Campus |
| INV-023 stock ledger by store | SF-INV-05 (store register: store, Item, date range) | P2 | **P0** | register filtered by Location |
| INV-020 monthly stock by Campus | SF-INV-05 (month view: opening, received buckets, out buckets) | P2 | **P1** | intended difference: no category or average price (no model), issues not split by person type (OD-9.4), no cross-Item totals (units differ) |

Statuses are now P0 70, P1 100, P2 64.

| Coverage | After ORG.9C-2 | After ORG.9C-3 |
|---|---|---|
| Functional, weighted / strict | 38.2 % / 32.9 % | **38.4 % / 33.3 %** (243.0 / 633) |
| V1-required, weighted / strict | 81.3 % / 70.0 % | **81.8 % / 71.0 %** (243.0 / 297) |

**Owner decisions recorded (ORG.9C-3 brief):**
- **OD-9.4 CLOSED for v1:** an Inventory issue keeps no Student or Employee recipient; the HR Department is the only v1 recipient. Individual custody, Employee or Student issue and returnable issue wait for an explicit custody or asset-assignment model (retention, erasure, return, reassignment, loss, handover).
- **OD-9.5 CLOSED for v1:** no free-text write-off remark; the closed reasons stay damaged, expired and lost.

**Remaining Inventory gaps:**
- returnable issue / custody (OD-9.4);
- count or stocktake correction;
- categories, average price and fixed assets;
- procurement (post-v1);
- exports (D-11);
- configurable approval chains stay DO_NOT_BUILD.

## 20. ORG.9C-4 — Transport bulk rider import (2026-10-23)

ORG.9C-4 (ADR 0076 §72) built PV1-09's bulk rider assignment: TRN-036, E2 evidence, with no reference screenshot family.

**TRN-036: P3 → P1** (intended difference). The import is:
- add-only and all-or-nothing;
- matched by student number, route code and stop number;
- UTF-8 CSV of at most 500 rows;
- web only.

TRN-015 (route riders vs capacity) is unchanged: it is a read item not authorized in this slice.

Statuses are now P1 101 and P3 21.

| Coverage | After ORG.9C-3 | After ORG.9C-4 |
|---|---|---|
| Functional, weighted / strict | 38.4 % / 33.3 % | **38.5 % / 33.5 %** (244.0 / 633) |
| V1-required, weighted / strict | 81.8 % / 71.0 % | **82.2 % / 71.4 %** (244.0 / 297) |

**Remaining Transport gaps:** TRN-015 capacity summary; GPS, maps and tracking (privacy-gated); trips and zones; maintenance and costs; fee receivable reports.

## 21. ORG.9D-1 — Staff-only Communications audience (2026-10-23)

ORG.9D-1 (ADR 0076 §73) built PV1-10.

| Function | Before | After | Note |
|---|---|---|---|
| COM-016 announce to staff only | P2 | **P0** | `staff` audience: current linked Employees, resolved at publish, School-wide approval rule applies |
| COM-034 email staff members | P2 | **P1** | intended difference: through staff User accounts on the existing email channel; never HR contact fields; production email gated (E17–E20) |

Statuses are now P0 71, P1 102, P2 62.

| Coverage | After ORG.9C-4 | After ORG.9D-1 |
|---|---|---|
| Functional, weighted / strict | 38.5 % / 33.5 % | **38.7 % / 33.8 %** (245.0 / 633) |
| V1-required, weighted / strict | 82.2 % / 71.4 % | **82.5 % / 72.1 %** (245.0 / 297) |

Not built: PV1-11 (fee-due reminder email) and PV1-12 (events calendar).

## 22. ORG.9D-2 — Fee-due reminder email (2026-10-25)

ORG.9D-2 (ADR 0076 §74) built PV1-11.

| Function | Before | After | Note |
|---|---|---|---|
| COM-045 send fee-due reminders | P3 | **P1** | manual (no schedules, OD-9.6); amounts = the Outstanding fees page, re-read at publish; current Guardian accounts only, one email each; always approved, preparer never decides; email only |
| COM-042 templates for fee reminder emails | P3 | **P1** | intended difference: one fixed Lycenza template plus a per-reminder School note; no editable template variables (owner brief §14) |

Statuses are now P0 71, P1 104, P2 62, P3 19.

| Coverage | After ORG.9D-1 | After ORG.9D-2 |
|---|---|---|
| Functional, weighted / strict | 38.7 % / 33.8 % | **39.0 % / 34.1 %** (247.0 / 633) |
| V1-required, weighted / strict | 82.5 % / 72.1 % | **83.2 % / 72.7 %** (247.0 / 297) |

Not built: PV1-12 (events calendar). Production email stays gated (E17–E20).
