# Parity v1 backlog (ORG.9A, 2026-10-23)

Status: **proposed, not authorized.** Built from `REFERENCE-PARITY-MATRIX.md`
and `reference-parity/functions.csv` (function ids below). Every item traces
to reference evidence (workbook, E2), a business reason and a code-verified
Lycenza absence or partial implementation. Nothing here creates a
capability, role, module, Campus adoption or approval workflow; where an
item would need one, it is marked as an owner decision.

Columns: **Value** = business value; **Security** = the Lycenza model it
uses; **Campus** = Campus impact; **Approval** = approval impact.

## Wave ORG.9B — Finance office read models (recommended first)

*ORG.9A-R1 (2026-10-23, ADR 0076 §67): screenshot evidence reconciled.*
The ORG.9A rows PV1-01 … PV1-04 below are kept for traceability. The
**gate decision** follows them. ORG.9B is **not authorized** by R1.

| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-01 | Fee-head dimension: filter and totals on charges, dues and collections (incl. transport/hostel fee views, concession category) | FIN-033, FIN-037, TRN-012, TRN-044, HST-018, HST-041 | finance office answers "how much for transport/exam/admission fees" without a spreadsheet | lists filter by Student/year/date/method only | existing `finance.charges.view`, `finance.payments.view`; read-only | follows ORG.7G-1 stored Charge Campus | none | none (E30/E31 production only) | none | S |
| PV1-02 | Dues ageing, billed/paid/outstanding per Student, dues as at a date or month | FIN-054, FIN-039, FIN-034, FIN-031 | collections follow-up and month-end | Outstanding shows outstanding/overdue only | existing view keys; read-only | FinanceCampusAccess | none | none | PV1-01 shares the read model | S–M |
| PV1-03 | Ledger read pages: trial balance, account ledger with running balance, daily cash book | FIN-012, FIN-013, FIN-014 | accountant can close a day/month in-product | `LedgerBalanceReader` exists, no page | existing `finance.ledger.view`; read-only | whole-School (ledger is CAMPUS_INCOMPATIBLE) | none | none | none | S–M |
| PV1-04 | Finance audit-log filters (entity, event, date) | FIN-062 | review of fee/fine/correction history | audit log has no filters | existing `school.audit.view`; never widen who sees the actor; no Campus dimension without an owner decision (SIS-035) | none | none | none | none | S |

### Finance deep review (R1, screens + repository at 5be8f815)

**Code facts.**
- **Fee head.** `charges` (Fees) has no fee-head column. A fee head reaches a Charge only through:
  - `fee_assessments.fee_head_id` (fee-run Charges; frozen);
  - the late-fee rule's configuration;
  - the Library fine policy, which may change;
  - nothing at all for Canteen and manual Charges.

  The Student statement shows a fee-head name only for fee-run Charges (`ChargeService.php:464-489`).
- **Outstanding page.** `/app/finance/outstanding` (`OutstandingFeesReadService`) shows outstanding, overdue, open count, earliest overdue and next due. It already filters by status, year, Student text, grade, section (whole-School `enrollments.view` only), standing and stored-Charge Campus. It has no billed, concession or paid column and no as-at date.
- **Ledger.** The ledger pages list accounts and journal entries; the entries filter by account and posted date. `LedgerBalanceReader` (the one authoritative balance read) has no page. Ledger reads are audited per call.
- **Audit log.** The School audit log pages by cursor only. It shows event type, actor id, subject and request id — never an IP address.

| Item | A visible? | B distinct? | C kind | D Lycenza equivalent | E lacks | F closest | G change class | Gate |
|---|---|---|---|---|---|---|---|---|
| PV1-01 fee-head dimension (FIN-033) | yes, strongly (E3): SF-FIN-01…06, -14, -15, -19 | no, a dimension | grouping / filter | fee heads exist; no list groups by head | **data**: a stored fee head on every Charge | `ChargeService::collectionFacts()`, `OutstandingFeesReadService`, `DailyCollectionsQuery` | **new persisted data + owner decision** | **NEEDS_REDESIGN + OWNER_DECISION_REQUIRED (OD-9.1)** |
| PV1-02a billed / concession / paid / balance per Student (FIN-039) | yes (E3): SF-FIN-05/-06/-12/-17/-27, SF-TRN-08, SF-MIS-02 | no, columns on the outstanding list | report variant | outstanding page | an aggregation per Student | `OutstandingFeesReadService`, `liveAdjustmentTotalsFor()`, effective allocation | query only | **READY_FOR_IMPLEMENTATION** |
| PV1-02b outstanding as at a date / month (FIN-034) | yes (E3): SF-FIN-05, SF-FIN-07 | no, a filter | filter | today only | a date bound on Charges, concessions, settlements and corrections | same | query only | **READY_FOR_IMPLEMENTATION** |
| PV1-02c ageing buckets (FIN-054) | **no** — menu entry only | — | — | none | — | — | — | **INSUFFICIENT_EVIDENCE** |
| PV1-03a account ledger with opening and running balance (FIN-013) | yes (E3): SF-FIN-08 (month-wise and voucher-level cash and bank ledgers) | yes | read page | journal list per account, no balances | opening + running balance for a range | `LedgerReadService`, `JournalEntryQuery`, `LedgerBalanceReader` | query only | **READY_FOR_IMPLEMENTATION** |
| PV1-03b cash / bank book (FIN-014) | yes (E3), as SF-FIN-08 | variant of PV1-03a on settlement accounts | report variant | none | same as PV1-03a | same | query only | **REPORT_VARIANT_ONLY** (ships with PV1-03a) |
| PV1-03c trial balance (FIN-012) | **no** | — | — | `LedgerBalanceReader` without a page | a page | `LedgerBalanceReader` | query only | **INSUFFICIENT_EVIDENCE** |
| PV1-04 finance audit-log filters (FIN-062) | yes (E4): SF-FIN-24 change logs, SF-FIN-23 cancellation actors | no; Lycenza has no in-place money edits, so history = correction records + audit log | filter | `/app/compliance/audit-log`, paging only | event type/family, occurred date range, subject type | `AuditLogReviewService`, `SchoolAuditEventReader` | query only | **FILTER_ONLY** |

Why PV1-01 is not a filter. A query over `fee_assessments` alone would
give fee-head totals that silently leave out late fees, Library fines,
Canteen and manual Charges. A correct dimension needs an immutable fee-head
attribution stored on every Charge. It would be written once by
`ChargeService::assess()` from each creator, in the same way as the stored
Charge Campus (ORG.7G-1A), plus a backfill.

**OD-9.1** asks the owner which head the non-fee-run Charges carry, and
whether a manual Charge must name one. This is a schema change, so it never
belongs in a read-only wave.

### ORG.9B built (2026-10-23, ADR 0076 §68)

ORG.9B built 9B-1, 9B-2 and 9B-4 as specified below, and 9B-3's account ledger. The **cash/bank book variant stopped**: ledger accounts carry no structural cash or bank classification, so a variant would have had to infer it from account names. That classification is now an owner decision.

FIN-013 became P0; FIN-034 and FIN-062 became P1. Matrix §16 has the coverage.

### ORG.9B proposed scope (as authorized; kept for traceability)

| ID | Business function | Screen evidence | Level | Current route / service | Exact gap | Capability | Scope / Campus effect | Data source | Schema? | Security / legal | UI work | Backend work | Tests |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 9B-1 (PV1-02a) | Billed, concession, paid and balance per Student, with totals | SF-FIN-05, -06, -12, -17, -27 | E5 | `app.finance.outstanding.index` → `OutstandingFeesReadService` | no billed/concession/paid columns or totals | existing `finance.charges.view,campus` ∩ `finance.payments.view` | unchanged: stored Charge Campus through `FinanceCampusAccess`; the Campus filter is a filter, never authority; class/section only for whole-School `enrollments.view` | Charges via `ChargeService::collectionFacts()`, live concession totals via `liveAdjustmentTotalsFor()`, effective allocation | none | read-only; the read stays audited; no export (D-11); no legal effect in development (E30/E31 production) | columns and totals on the existing page | widen the Fees fact read through Fees' Application layer (Payments → Fees, no new direction) | allow/deny per capability, Campus in/out of scope, NULL-Campus whole-School only, cross-School (Eloquent + RLS), effective allocation after correction, cancelled Charges excluded |
| 9B-2 (PV1-02b) | Outstanding as at a School-local date | SF-FIN-05, -07 | E5 | same | no as-at bound | same | same | same records bounded to "recorded on or before the date": Charge recognized, concession live, Payment settled, correction recorded | none | same | an as-at date control | date-bounded facts | the as-at boundary on each record kind, a correction after the date not counted, the School timezone date, the same allow/deny/Campus/RLS matrix |
| 9B-3 (PV1-03a + 03b) | Account ledger with opening and running balance; a cash/bank book view of settlement accounts | SF-FIN-08 | E5 | `app.finance.journal-entries.*` → `LedgerReadService`, `JournalEntryQuery`, `LedgerBalanceReader` | no opening or running balance | existing `finance.ledger.view` | whole-School (ledger keys are Campus-incompatible); no Campus filter | journal lines + the closed-period baseline; a range inside an expired closed period shows the baseline only | none | read-only, audited per call like today; no export | a ledger page per account and date range | a Finance-owned read beside `LedgerBalanceReader` (one balance definition) | opening equals the balance reader, running sums, reversals, a period close, a retention-expired range, allow/deny, cross-School |
| 9B-4 (PV1-04) | Audit-log filters | SF-FIN-24, -23 (also SF-HR-08, SF-SIS-03) | E5 (E4 screens) | `app.compliance.audit-log` → `AuditLogReviewService` → `SchoolAuditEventReader` | cursor only | existing `school.audit.view` | whole-School; no Campus dimension | `school_audit_events` (event type, occurred at, subject type) | none (an index only if the query plan proves it necessary) | never metadata, IP or user agent; the review stays audited (`compliance.audit_log.viewed`) | filter controls | validated filters in the reader; a cursor stable under filters | invalid filter refused, the filter combined with the cursor, allow/deny, cross-School, metadata never returned |

**Excluded from ORG.9B:**
- PV1-01 fee-head dimension: OD-9.1, schema.
- PV1-02c ageing and PV1-03c trial balance: INSUFFICIENT_EVIDENCE, pending the remaining Finance captures.
- Every export: EXPORT_GAP — owner decision D-11.
- Collector, settlement account, date-basis and cancellation-reason filters on collections, the payment-method totals matrix, month grouping (FIN-031) and concession requester/decider filters: FILTER_ONLY or report variants with ORG.9C as their wave (`screen-filters.csv`), for owner choice.
- Cheque lifecycle (PV1-21), online settlement (E28) and multiple collecting entities (FIN-090): owner decisions or gated.
- Anything transactional: concession limits, fee removal, payment edits, deletes and moves, refunds. These are design differences (rules 9, 91, 102), never parity items.

**Later-wave impact (not authorized):**
- ORG.9C keeps PV1-05 (= LC-6, the LC overlap is preserved) … PV1-09.
- The R1 HR evidence confirms that HR-003, HR-041 and HR-042 read models must exclude late, no-punch and loss-of-pay states (HRX-L2/L4).
- Transport fee views (SF-TRN-08) wait on OD-9.1.
- Inventory approval chains stay DO_NOT_BUILD.

## Wave ORG.9C — Daily operations views and completion

### ORG.9C-1 built (2026-10-23, ADR 0076 §69)

**Owner decisions recorded with the ORG.9C-1 brief:**
- **OD-9.1** CLOSED for v1: no universal Charge fee-head dimension.
- **OD-9.2** DEFERRED: the cash/bank classification of ledger accounts waits for a bank or reconciliation design.
- **Posting time** is the canonical as-at basis; there is no received-date view.
- D-11, dues ageing and the trial balance stay open.

**PV1-05, built:**
- **ATT-003:** one Student's records over a range, corrections shown, read-audited, never a rate.
- **ATT-002:** a neutral register-status view (submitted / pending / not submitted).

**PV1-05, stopped:** a "missing register" classification. No Student holiday, closure or cancelled-period model exists, so whether a register was expected cannot be determined structurally. This is owner question **OD-9.3** (Student school calendar; overlaps PV1-12). There is also no teacher filter (`TeacherAttendanceArchitectureGuardTest`).

**PV1-06, built:**
- the directory employment-state, several-Campuses, joined-in-period and reports-to filters (HR-040, -045, -056, -044 as a filter);
- the staff attendance month summary, by the register's own states (HR-003);
- the leave month report (HR-042);
- the leave request filters (HR-043).

None of these shows a late, no-punch, threshold or loss-of-pay state, or a leave reason.

**PV1-07, built:** circulation filters (LIB-031, -035, -037).

**PV1-07, stopped as transactional or configuration** (separate owner-authorized phase): LIB-010 renewal, LIB-012 web title edit, LIB-019 borrower limit, LIB-021 default loan period, and the LIB-022/-024/-025 masters.

**Not started:** PV1-08, PV1-09 and ORG.9D. Matrix §17 has the coverage.


| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-05 | Attendance operations: classes with no register for a date; one Student's attendance over a range | ATT-002, ATT-003 | daily follow-up of missing registers and a Student's record | single-day exceptions only | existing `attendance.*` keys, Campus-adopted reads, teacher ownership unchanged | stored register Campus | none | E33 for teacher production | **is LC-6 (SB-01, ATT-07)** — reuse | S |
| PV1-06 | HR operational reports: monthly staff attendance summary, month-wise leave, HR list filters (status, joining, multi-Campus, reporting manager, leave details) | HR-003, HR-042, HR-040, HR-043, HR-044, HR-045, HR-056 | HR month-end without exports | per-employee history only | existing HR view keys; no HS fields; no health reasons (HRX-L1) | HrEmployeeAccess (current assignments) | none | HRX-L1 (no reasons shown) | none | S–M |
| PV1-07 | Library circulation completion: loans per borrower limit, default loan period, renewal, web title edit, category/language/publisher masters, loan filters | LIB-019, LIB-021, LIB-010, LIB-012, LIB-022, LIB-024, LIB-025, LIB-031, LIB-035, LIB-037 | a librarian can run circulation without workarounds | due date typed per loan, no renewal, edit API-only | existing `library.*` keys | loan Campus fixed at checkout (ORG.7D-5) | none | E34 unchanged | overlaps LC-8 LIB-05 / LC-9 | M |
| PV1-08 | Inventory issue and write-off: recipient and reason on issue, damaged write-off as a new movement kind, stock filters, monthly stock view | INV-011, INV-014, INV-007, INV-016, INV-023, INV-020 | stores can account for issued and damaged stock | issue has no recipient/reason | existing `inventory.*` keys; movements stay append-only (ORG.7H-1R), Location Campus frozen | Location Campus re-checked | none (a write-off approval would be an owner decision) | none | migration (movement kind) | M |
| PV1-09 | Transport: bulk rider upload through `TransportStudentAssignmentService` per row; route riders vs vehicle capacity | TRN-036, TRN-015 | start-of-year rider setup | one-by-one assignment; route index counts stops only | existing transport keys; per-row service, per-row outcome, no direct writes | rider Campus rules (ORG.7D-5) | none | none | none | M |

### ORG.9C-2 built (2026-10-23, ADR 0076 §70)

**OD-9.3 CLOSED for v1 (owner):** register states stay SUBMITTED, PENDING and NOT_SUBMITTED. There is no MISSING state until a canonical Student academic-calendar or closure model exists.

**PV1-08, built:**
- **Write-off (INV-011 → P0).** A `write_off` movement with a closed reason (damaged, expired, lost), DIRECT_WITH_ASSURANCE: the existing `inventory.stock.manage` in the Location's scope, re-read fresh, plus fresh MFA, a web confirmation and a replay-safe form key.
- **Issue (INV-014 → P1).** Terminal issue with an optional HR Department recipient.

**PV1-08, stopped (owner decision):**
- **Person recipients (staff, Students).** Movements are tenant-lifetime without personal data (E21.2G O5), and a person FK would block E21-D9 Employee purge.
- **Write-off remarks.** The same reason: no free text is stored.
- **Returnable issue.** Custody is deferred with asset tracking (INVENTORY.md §14).
- **Count or stocktake correction.** There is no stocktake model.
- **Fixed-asset disposal.** There is no asset model.

**PV1-08, not authorized here:** stock filters and the monthly stock view (INV-016, INV-023, INV-020).

**Unchanged:**
- Configurable approval chains stay DO_NOT_BUILD (INV-034).
- PV1-09, ORG.9D and the Library write and configuration items are untouched.

Matrix §18 has the coverage.

### ORG.9C-3 built (2026-10-23, ADR 0076 §71)

**Owner decisions:**
- **OD-9.4 CLOSED for v1:** no Student or Employee recipient on an Inventory issue (HR Department only); custody, person issue and returnable issue wait for an explicit custody model.
- **OD-9.5 CLOSED for v1:** no free-text write-off remark (damaged, expired, lost).

**PV1-08 read rows built:**
- INV-016 and INV-023: the stock register, by Campus or Location, with School-local dates, Item and type.
- INV-020: stock by month (opening, in, out, closing per Item).

All are read-only on the existing scope; matrix §19 has the coverage.

**PV1-08 is now complete for v1.** The remaining Inventory gaps are post-v1 or owner decisions:
- returnable issue (OD-9.4);
- stocktake;
- categories and average price;
- fixed assets;
- procurement;
- exports (D-11).

### ORG.9C-4 built (2026-10-23, ADR 0076 §72)

**PV1-09:** TRN-036 bulk rider import, now P1.
- Add-only and all-or-nothing: UTF-8 CSV, then a signed preview, then an atomic commit through `TransportStudentAssignmentService::assign()`.
- Existing `transport.assignments.manage` and Campus contract; no schema and no new capability.

**Not built:** TRN-015 (route riders vs capacity, a read item), reassignment by import, partial import and error export (D-11). Matrix §20 has the coverage.

## Wave ORG.9D — Communications for operations

| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-10 | Staff-only audience for announcements and email | COM-016, COM-034 | staff notices that never reach families | School-wide = every active membership incl. Guardian/Student accounts (preview shows it) | existing `communications.*`; audience resolver only | none | existing announcement approval | must land before E46 production | none | S |
| PV1-11 | Fee-due reminder email | COM-045, COM-042 | fewer overdue dues | no purpose, no Fees → Communications link | a new closed `EmailPurpose` reviewed under ADR 0055; Fees publishes an event, Communications sends | none | none | E17–E20 for production delivery | ADR 0055 purpose amendment | M |
| PV1-12 | School events and holiday calendar (staff; family view later) | COM-024, LMS-115, COM-026, COM-027 | one calendar for terms, holidays, exams | years/terms/staff holidays exist separately | existing academic-structure view keys; family view behind E46 | Campus filter, not authority | none | E46 (family view) | none | M |

### ORG.9D-1 built (2026-10-23, ADR 0076 §73)

**PV1-10:** Staff-only audience (COM-016 P0, COM-034 P1).
- Current staff from HR's `CurrentStaffMembers`: linked active Employee with one current employment, never a role.
- The existing announcement pipeline, approval workflow and channels.
- No new capability, Campus targeting or contact model.

**Not started:**
- PV1-11 (fee-due reminder, ORG.9D-2): needs its own contract — recipients, financial facts, purpose and legal basis, schedule, duplicates.
- PV1-12 (events calendar, ORG.9D-3): new business state, and never the Attendance expectation calendar (OD-9.3).

## Owner decisions before build (not scheduled)

| ID | Title | Functions | Decision needed | Gate | Size |
|---|---|---|---|---|---|
| PV1-13 | Opening balances (ledger and legacy Student dues) | FIN-009 | OD-SWC-5 / OD-PPR-20: migration method and authority for a first real School | none | M |
| PV1-14 | Certificates from templates with a numbered issue register; transfer/leaving certificate | CRT-001, CRT-002 | template ownership, statutory TC format, who issues; Student document owner type (OD-SWC-2) | E21 retention | L |
| PV1-15 | Student documents (repository, received checklist, additional uploads) | DOC-009, DOC-010, DOC-011, DOC-017 | OD-SWC-2 Student owner type; categories; who may manage | E21 | M |
| PV1-16 | Exports (nominal roll, staff details, finance lists) | SIS-016, HR-048, finance | **D-11** export policy (minimisation, audit, watermark) | per data class | M |
| PV1-17 | Sibling list and class family-contact list | SIS-019, SIS-020 | contact minimisation (ADR 0041 review) | none | S–M |
| PV1-18 | Correct applicant and application details | FIN-026 | none new — **this is LC-5 (M5)**; resume LC-5 | none | S |
| PV1-19 | Daily (non-period) attendance | EXM-058 | LC-6 ATT-08 owner decision (registers are timetable-bound by design) | E33 | M |
| PV1-20 | Staff borrowing in Library | LIB-011 | borrower model (loans are Student-only) | none | M |
| PV1-21 | Dishonoured cheque reason and list | FIN-042 | adds a closed reason to the payment-correction contract (ADR 0031 amendment §4) | E30 | S |
| PV1-22 | Expenses and payees | FIN-003, FIN-004 | v1 or post-v1 scope; if built, a domain-owned approval on the shared foundation (rule 102) | none | L |

## Dependency map

- PV1-01 → PV1-02 (shared fee-head/date read model).
- PV1-03 independent (Finance ledger).
- PV1-05 = LC-6; PV1-19 is LC-6's ATT-08 decision.
- PV1-07 overlaps LC-8 (LIB-05) and LC-9 (web edits) — one slice.
- PV1-10 before E46 production; PV1-12's family view after E46.
- PV1-11 needs an ADR 0055 purpose amendment.
- PV1-13 belongs with pre-production data migration (PPR), not ORG.9.
- PV1-14 and PV1-15 share the Student document owner type (OD-SWC-2).

No item requires a new capability, a role change or a Campus adoption.
Owner decisions are required for PV1-13 … PV1-22; PV1-11 needs an email
purpose amendment; nothing in ORG.9B needs a legal clearance for
development.
