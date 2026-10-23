# Reference screenshot register (ORG.9A-R1, 2026-10-23)

Status: **screenshot evidence ingested and reconciled** (ADR 0076 §67).

ORG.9A (§66) had no screenshots; every function there is E2. ORG.9A-R1
indexed the owner's screenshot pack by direct visual inspection and grouped
the screens into families. It then reconciled every family against
`reference-parity/functions.csv` and the repository.

Nothing here is a capability, role, module, Campus adoption or
implementation decision.

## 1. Provenance and handling

| Item | Value |
|---|---|
| Source | Owner-supplied captures of the owner's own organisation's tenant on the reference ERP, taken under one system-administrator persona |
| Location | Owner-held, outside the repository (the owner's Lycenza folder on the workstation) |
| Files | 1,005 PNG files; no byte-identical duplicates |
| Pack fingerprint | SHA-256 of the sorted per-file SHA-256 list: `552559c1c43ba0c4e851efa2d7dbb982ee1551b649d8d6b0a8a1a09dade3dfca` |
| Not reference evidence | 35 captures of unrelated products or tools (excluded) |
| Indexed reference screens | **970** |
| Method | Each image inspected visually; no bulk OCR |
| Committed | Normalized, Lycenza-worded findings only |
| Never committed | Screenshots, reference layouts, distinctive reference titles, the screen-id-to-file index |
| Personal data | None recorded: no person names, identifiers, contact details, amounts or school-specific values |

The screens show live school data. Indexing therefore recorded **structure
only**: filters, columns, totals, actions and states.

The screen ids (`S…`) in the registers point into the owner-held pack. The
index that maps an id to a file stays with the pack, outside Git.

**Further Finance captures** were announced by the owner during R1 and are
not yet in this pack. They are an open evidence prerequisite (§7).

## 2. Evidence packs

| Pack (brief) | Status | Families | Screens | Families with observed workflow (E4) |
|---|---|---|---|---|
| Finance (incl. reports) | **available** (further captures pending) | 29 | 387 | 11 |
| HR (incl. payroll, staff attendance, HR reports) | available | 20 | 252 | 5 |
| Student Information (incl. student attendance, documents, timetable) | available | 11 | 67 | 1 |
| Inventory | available | 10 | 120 | 1 |
| Transport | available | 8 | 51 | 0 |
| Reporting / MIS | available (small: dashboards and three report families) | 3 | 25 | 0 |
| *Organisation (not a required pack)* | *available* | *6* | *68* | *0* |
| **Total** | | **87** | **970** | **18** |

No required pack is EVIDENCE_UNAVAILABLE. The MIS pack is thin: three
families, with no general report catalogue opened. Report classes for the
other report-like functions stay as ORG.9A derived them from menu labels.

## 3. Registers (committed, Lycenza wording)

| File | Content |
|---|---|
| `reference-parity/screen-families.csv` | One row per screen family (87). Columns: pack, family id, reference area, screen purpose, user actions, workflow states, filters, columns, totals, exports, bulk actions, approval signals, role signals, navigation context, mapped function ids, evidence level, screen count, screen ids, one-line gap. |
| `reference-parity/screen-filters.csv` | Normalized filter register (117 rows): domain, screen family, reference filter, current Lycenza equivalent, missing?, security effect, wave. |
| `reference-parity/screen-columns.csv` | Column and total register (107 rows): domain, family, column or total, Lycenza equivalent, missing?, data-class note. |
| `reference-parity/screen-contradictions.csv` | 29 workbook contradictions (classified) and 57 design differences, each with both sides. |
| `reference-parity/functions.csv` | `evidence` raised to E3/E4/E5 where screens support a function; new `screen_families` column; 15 new functions; 6 status changes; 5 ids renumbered (§6). |

Wave values in the filter register:

| Wave | Count | Meaning |
|---|---|---|
| exists | 14 | already present in Lycenza |
| ORG.9B | 3 | the as-at filter, the ledger account/date range, the audit-log filters |
| ORG.9C | 41 | later read filters, for owner choice |
| owner | 24 | 20 general owner decisions, 3 under OD-9.1, 1 under PV1-21 |
| post-v1 | 17 | after v1 |
| never | 18 | rule-forbidden or privacy-blocked, e.g. a region tier above Campus, contact-number search, GPS |

## 4. Screen families (summary)

Full rows are in `screen-families.csv`. Evidence: E3 = screens only; E4 =
screens that show workflow or state, such as status tabs, decisions, before
and after values, or a lifecycle.

### Finance (29)

| Family | Purpose | Screens | Evidence |
|---|---|---|---|
| SF-FIN-00 | Finance navigation (menus only) | 10 | E3 |
| SF-FIN-01 | Collections overview and day summary (per collector × payment method) | 5 | E3 |
| SF-FIN-02 | Collection reports by date, month, fee head and instalment | 34 | E4 |
| SF-FIN-03 | Receipt register and receipt reprint | 12 | E4 |
| SF-FIN-04 | Dues by fee head and instalment | 6 | E3 |
| SF-FIN-05 | Outstanding as at a month or instalment due date | 6 | E3 |
| SF-FIN-06 | Billed / concession / paid / due per Student | 28 | E3 |
| SF-FIN-07 | Previous-year dues carried forward | 8 | E3 |
| SF-FIN-08 | Cash and bank account ledgers with running balance | 4 | E3 |
| SF-FIN-09 | Cheque and instrument lifecycle, deposit register | 19 | E4 |
| SF-FIN-10 | Bank clearance / reconciliation | 6 | E4 |
| SF-FIN-11 | Online payment settlement and gateway per Campus | 2 | E3 |
| SF-FIN-12 | Fee plan assignment to Students (incl. bulk) | 80 | E4 |
| SF-FIN-13 | Miscellaneous and event fees | 19 | E3 |
| SF-FIN-14 | Invoices and payment slips | 12 | E3 |
| SF-FIN-15 | Late fee (fine) rules and per-Student fines | 13 | E3 |
| SF-FIN-16 | Tax codes and tax rules | 9 | E3 |
| SF-FIN-17 | Concession entry, rules and bulk upload | 38 | E3 |
| SF-FIN-18 | Concession approval queue, summary and history | 6 | E4 |
| SF-FIN-19 | Concession reports and analytics | 11 | E3 |
| SF-FIN-20 | Fee removal requests and approvals | 5 | E4 |
| SF-FIN-21 | Payment changes: approval queue and direct edits | 23 | E4 |
| SF-FIN-22 | Refunds, refundable deposits, Student credit balance | 9 | E4 |
| SF-FIN-23 | Cancelled payments report | 10 | E4 |
| SF-FIN-24 | Fee change logs with filters | 2 | E4 |
| SF-FIN-25 | Fee data consistency checks | 3 | E3 |
| SF-FIN-26 | Bulk payment upload | 2 | E3 |
| SF-FIN-27 | Sibling dues view | 2 | E3 |
| SF-FIN-28 | Applicant details and application-fee correction | 3 | E3 |

### HR (20)

| Family | Purpose | Screens | Evidence |
|---|---|---|---|
| SF-HR-01 | Staff list, create and profile sections | 27 | E3 |
| SF-HR-02 | Bulk staff import | 8 | E3 |
| SF-HR-03 | Designation, department and qualification catalogues | 21 | E3 |
| SF-HR-04 | Staff hierarchy and reporting managers | 10 | E3 |
| SF-HR-05 | Staff reports (details, joining, inactive, headcount, workload, documents, retirement, cross-module) | 23 | E3 |
| SF-HR-06 | Staff exit / deactivation requests and approvals | 4 | E4 |
| SF-HR-07 | Staff Campus transfer | 2 | E3 |
| SF-HR-08 | Staff record change log | 1 | E3 |
| SF-HR-09 | Leave types, policies, allocation and holidays | 18 | E3 |
| SF-HR-10 | Leave, permission and compensatory-off approvals | 7 | E4 |
| SF-HR-11 | Leave balances and leave reports | 15 | E4 |
| SF-HR-12 | Staff attendance capture: shifts, devices, daily and range views | 26 | E4 |
| SF-HR-13 | Staff attendance corrections and regularisation | 11 | E3 |
| SF-HR-14 | Payroll month, attendance confirmation, pay setup and pay change log | 13 | E4 |
| SF-HR-15 | Professional development and recognition | 26 | E3 |
| SF-HR-16 | Staff tasks | 11 | E3 |
| SF-HR-17 | Staff conduct complaints | 8 | E3 |
| SF-HR-18 | Outsourced staff vendors and manpower contracts | 12 | E3 |
| SF-HR-19 | Staff groups and staff handbook | 8 | E3 |
| SF-HR-20 | Teacher substitution | 1 | E3 |

### Student Information (11), Inventory (10), Transport (8), MIS (3), Organisation (6)

| Family | Purpose | Screens | Evidence |
|---|---|---|---|
| SF-SIS-01 | Student list and record edit | 6 | E3 |
| SF-SIS-02 | Inactive Students, exit register, status-change approvals | 10 | E3 |
| SF-SIS-03 | Student data change log | 2 | E4 |
| SF-SIS-04 | Strength and vacancy reports | 7 | E3 |
| SF-SIS-05 | Student groups, batches and activities | 10 | E3 |
| SF-SIS-06 | Student agreement verification | 6 | E3 |
| SF-SIS-07 | Student documents and photos | 4 | E3 |
| SF-SIS-08 | Timetable setup and room allocation | 8 | E3 |
| SF-SIS-09 | Enrollment, promotion and transfer reports | 4 | E3 |
| SF-SIS-10 | Principal diary | 1 | E3 |
| SF-SIS-11 | Student attendance (period, exam, device, leave intimation) | 9 | E3 |
| SF-INV-01 | Item catalogue, categories, labels, fixed assets | 29 | E3 |
| SF-INV-02 | Stores, dashboard and navigation | 9 | E3 |
| SF-INV-03 | Goods inward and stock upload | 9 | E3 |
| SF-INV-04 | Stock transfers and transfer registers | 11 | E3 |
| SF-INV-05 | Stock as at a date, monthly stock, stock ledger | 10 | E3 |
| SF-INV-06 | Damaged stock | 5 | E3 |
| SF-INV-07 | Issues and store sales reports | 5 | E3 |
| SF-INV-08 | Vendor master and maintenance proposals | 10 | E3 |
| SF-INV-09 | Procurement: requests, quotations, purchase orders, contracts | 22 | E3 |
| SF-INV-10 | Configurable approval chain settings | 10 | E4 |
| SF-TRN-01 | Routes and stops (incl. upload, year rollover) | 6 | E3 |
| SF-TRN-02 | Zones and trips | 11 | E3 |
| SF-TRN-03 | Riders and vehicle capacity | 4 | E3 |
| SF-TRN-04 | Vehicles, crew, expenses, mileage | 6 | E3 |
| SF-TRN-05 | Boarding attendance, shared-vehicle checks, daily summary | 4 | E3 |
| SF-TRN-06 | GPS vendor settings and notifications | 3 | E3 |
| SF-TRN-07 | Transport dashboard and navigation | 5 | E3 |
| SF-TRN-08 | Transport fee plans, receivables and billed/paid reports | 12 | E3 |
| SF-MIS-01 | Campus × curriculum/board revenue and enrolment matrix | 12 | E3 |
| SF-MIS-02 | New-admission revenue funnel and fee-head detail | 10 | E3 |
| SF-MIS-03 | Student dashboard drill-downs | 3 | E3 |
| SF-ORG-01 | Module catalogue, launcher and switcher | 16 | E3 |
| SF-ORG-02 | User types, menu permissions and admin users | 29 | E3 |
| SF-ORG-03 | Integrations and devices | 5 | E3 |
| SF-ORG-04 | Feedback, FAQ, vendor support and billing | 10 | E3 |
| SF-ORG-05 | Settings and utilities | 4 | E3 |
| SF-ORG-06 | Messaging credit balance and sender settings | 4 | E3 |

## 5. Evidence coverage

Function rows: 772 in ORG.9A, 787 after R1. Evidence levels after R1:

| Level | Functions |
|---|---|
| E2 (menu only) | 484 |
| E3 | 150 |
| E4 | 3 |
| E5 | 150 |
| **E3 or higher** | **303** (38.5 %) |
| workflow actually observed on a mapped screen | 35 |

A function is E5 when a screen supports it **and** the repository was
compared, with code paths cited. E5 does not imply that workflow was
observed; that is counted separately in the last row.

| Domain | Functions | E3+ | E5 | Workflow observed |
|---|---|---|---|---|
| fees_finance | 107 | 68 | 44 | 17 |
| hr | 55 | 41 | 25 | 10 |
| payroll | 7 | 6 | 5 | 2 |
| transport | 53 | 36 | 10 | 0 |
| inventory | 52 | 35 | 13 | 1 |
| sis | 51 | 23 | 13 | 1 |
| attendance | 33 | 20 | 16 | 2 |
| organization | 39 | 17 | 5 | 1 |
| not adopted | 43 | 15 | 1 | 1 |
| analytics | 16 | 8 | 4 | 0 |
| documents | 15 | 7 | 2 | 0 |
| academics / timetable | 28 | 12 | 8 | 0 |
| admissions | 25 | 5 | 2 | 0 |
| communications | 85 | 6 | 2 | 0 |
| examinations | 66 | 2 | 0 | 0 |
| other (canteen, portal) | 6 | 2 | 0 | 0 |
| no screen evidence (lms, curriculum, library, hostel, identity and others) | 106 | 0 | 0 | 0 |

The screenshots supported 314 distinct function ids before validation. The
domain reconciliations rejected mappings that a screen did not actually
show; for example, menu-only captures stay E2. Every indexed screen maps to
at least one family.

## 6. Reconciliation outcomes

**Status changes (6 accepted, 1 rejected)** — each is recorded in the row's
`notes`:

| Function | From | To | Why |
|---|---|---|---|
| HR-005 | P4 | P6 (HRX-L2) | Every shift on screen is built on clock-time grace, late and early thresholds |
| HR-052 | P4 BUILD_POST_V1 | P4 OWNER_DECISION_REQUIRED | Conduct/discipline complaints with severity up to a memo: a new Highly Sensitive HR category |
| ORG-035 | P7 | P4 | A real ratings report over feedback capture that Lycenza lacks; P7 overstated coverage |
| FIN-088 | P5 | P8 (of FIN-057) | The path opens an event fee screen, not programme attendance |
| FIN-061 | P0 | P1 | Reference late fees are per-day or slab; Lycenza deliberately charges one capped late fee per rule |
| FIN-036 | P3 | P8 (of FIN-033) | The screens are collections per fee head and payment method |
| HR-047 (rejected) | P6 | kept P6 | The timing half needs clock times (HRX-L2); the salary half exists; split recorded |

**New functions (15).** Each is proven by screens, has no workbook path
(`reference_paths = 0`, `workbook_refs_sample = screenshot-only (R1)`), and
was searched for in the existing ids first.

| Id | Function | Status / decision |
|---|---|---|
| HR-083 | Move a staff member to another Campus as one operation | P1 OWNER_DECISION_REQUIRED (ADR 0076 §43 deferral) |
| HR-084 | Planned retirement date tracking | P4 OWNER_DECISION_REQUIRED |
| HR-085 | Staff health and physical identification details | P6 HRX-L1 (evidence only) |
| HR-086 | Staff dependants and statutory nominees | P4 OWNER_DECISION_REQUIRED |
| HR-087 | Per-staff pay basis | P1 KEEP_EXISTING |
| HR-088 | Staff statutory payroll profile | P1 KEEP_EXISTING |
| HR-089 | Choose the employing legal entity per staff member | P5 DO_NOT_BUILD (the School is the employer; rule 84) |
| HR-090 | Appointment and probation confirmation details | P2 OWNER_DECISION_REQUIRED |
| TRN-056 | Staff as transport riders | P4 OWNER_DECISION_REQUIRED |
| SIS-036 | Bulk import of existing Student records | P4 OWNER_DECISION_REQUIRED (OD-SWC-4 / OD-PPR-20) |
| ACA-002 | Room catalogue with capacity | P0 KEEP_EXISTING |
| ACA-003 | Standing home room per Section | P4 BUILD_POST_V1 |
| INV-043 | Item low-stock thresholds | P4 BUILD_POST_V1 |
| FIN-090 | Several collecting legal entities within one School | P4 OWNER_DECISION_REQUIRED (ADR + E30) |
| FIN-091 | Multi-year fee commitment from a starting year | P4 OWNER_DECISION_REQUIRED (E31; one screen) |

Rejected as new functions:
- Staff security deposit: evidenced by only one report column group.
- The per-accountant concession limit with auto-approval: a design difference (rule 102), not a gap.
- Gateway settlement and the post-dated cheque register: variants of FIN-046 and FIN-041.

**ORG.9A defect corrected.** Five ids were used twice in the published
`functions.csv`. The row that kept the id is the one the screens and the
other documents refer to.

| Old id | Renumbered row | New id |
|---|---|---|
| HST-001 | Hostel residents versus day scholars | HST-050 |
| DOC-001 | Unowned School-wide document repository | DOC-016 |
| DOC-002 | Track student certificates submitted | DOC-017 |
| DOC-003 | Employee document repository | DOC-018 |
| TRN-001 | Students using transport list | TRN-055 |

**Contradictions (29)** — workbook against screen, both sides recorded in
`screen-contradictions.csv`:

| Class | Count | Examples |
|---|---|---|
| WORKBOOK_STALE | 14 | FIN-088, FIN-036, ORG-013, ORG-017, TRN-037, CRT-011, HR-005, HR-008 path remaps |
| SCREEN_ROLE_DIFFERENCE | 3 | e.g. a menu absent for this persona |
| SCREEN_VERSION_DIFFERENCE | 2 | a menu entry not in the workbook; this tenant's configuration ticks a module for a persona |
| AMBIGUOUS | 10 | e.g. the five duplicate ids above; FIN-047/ADM-014 and HR-055/FIN-087 overlapping rows, kept for wave planning |

Path remaps are recorded only; the ORG.9A path counts are unchanged.

**Design differences (57).** These are reference behaviours that Lycenza
forbids by rule. They are recorded, never adopted:
- concession auto-approval within a per-accountant limit, and approvers editing amounts;
- in-place edits of assessed amounts, payments and receipt dates (back-dating);
- deleting, moving and force-succeeding payments; replace-mode concession uploads;
- configurable approval chains choosing approvers by role name;
- health reasons in attendance; network addresses shown to School users; role choice on the HR record;
- per-Campus email senders; edition upsell in the module catalogue; derived default vendor credentials;
- bulk export of national, tax, bank, caste and religion columns.

## 7. Open evidence prerequisites

| Prerequisite | Effect |
|---|---|
| Further Finance captures (owner) | Re-index and re-reconcile Finance before any change to the ORG.9B scope |
| Dues ageing (FIN-054): menu entry only | INSUFFICIENT_EVIDENCE; outside ORG.9B |
| Trial balance (FIN-012): no screen | INSUFFICIENT_EVIDENCE; outside ORG.9B |
| MIS report catalogue: only three families | Report classes stay label-derived |
| Examinations, Communications, LMS, Library, Hostel: few or no screens | These stay E2 (no pack was required for them) |
