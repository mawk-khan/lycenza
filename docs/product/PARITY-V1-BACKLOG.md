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

| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-01 | Fee-head dimension: filter and totals on charges, dues and collections (incl. transport/hostel fee views, concession category) | FIN-033, FIN-037, TRN-012, TRN-044, HST-018, HST-041 | finance office answers "how much for transport/exam/admission fees" without a spreadsheet | lists filter by Student/year/date/method only | existing `finance.charges.view`, `finance.payments.view`; read-only | follows ORG.7G-1 stored Charge Campus | none | none (E30/E31 production only) | none | S |
| PV1-02 | Dues ageing, billed/paid/outstanding per Student, dues as at a date or month | FIN-054, FIN-039, FIN-034, FIN-031 | collections follow-up and month-end | Outstanding shows outstanding/overdue only | existing view keys; read-only | FinanceCampusAccess | none | none | PV1-01 shares the read model | S–M |
| PV1-03 | Ledger read pages: trial balance, account ledger with running balance, daily cash book | FIN-012, FIN-013, FIN-014 | accountant can close a day/month in-product | `LedgerBalanceReader` exists, no page | existing `finance.ledger.view`; read-only | whole-School (ledger is CAMPUS_INCOMPATIBLE) | none | none | none | S–M |
| PV1-04 | Finance audit-log filters (entity, event, date) | FIN-062 | review of fee/fine/correction history | audit log has no filters | existing `school.audit.view`; never widen who sees the actor; no Campus dimension without an owner decision (SIS-035) | none | none | none | none | S |

## Wave ORG.9C — Daily operations views and completion

| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-05 | Attendance operations: classes with no register for a date; one Student's attendance over a range | ATT-002, ATT-003 | daily follow-up of missing registers and a Student's record | single-day exceptions only | existing `attendance.*` keys, Campus-adopted reads, teacher ownership unchanged | stored register Campus | none | E33 for teacher production | **is LC-6 (SB-01, ATT-07)** — reuse | S |
| PV1-06 | HR operational reports: monthly staff attendance summary, month-wise leave, HR list filters (status, joining, multi-Campus, reporting manager, leave details) | HR-003, HR-042, HR-040, HR-043, HR-044, HR-045, HR-056 | HR month-end without exports | per-employee history only | existing HR view keys; no HS fields; no health reasons (HRX-L1) | HrEmployeeAccess (current assignments) | none | HRX-L1 (no reasons shown) | none | S–M |
| PV1-07 | Library circulation completion: loans per borrower limit, default loan period, renewal, web title edit, category/language/publisher masters, loan filters | LIB-019, LIB-021, LIB-010, LIB-012, LIB-022, LIB-024, LIB-025, LIB-031, LIB-035, LIB-037 | a librarian can run circulation without workarounds | due date typed per loan, no renewal, edit API-only | existing `library.*` keys | loan Campus fixed at checkout (ORG.7D-5) | none | E34 unchanged | overlaps LC-8 LIB-05 / LC-9 | M |
| PV1-08 | Inventory issue and write-off: recipient and reason on issue, damaged write-off as a new movement kind, stock filters, monthly stock view | INV-011, INV-014, INV-007, INV-016, INV-023, INV-020 | stores can account for issued and damaged stock | issue has no recipient/reason | existing `inventory.*` keys; movements stay append-only (ORG.7H-1R), Location Campus frozen | Location Campus re-checked | none (a write-off approval would be an owner decision) | none | migration (movement kind) | M |
| PV1-09 | Transport: bulk rider upload through `TransportStudentAssignmentService` per row; route riders vs vehicle capacity | TRN-036, TRN-015 | start-of-year rider setup | one-by-one assignment; route index counts stops only | existing transport keys; per-row service, per-row outcome, no direct writes | rider Campus rules (ORG.7D-5) | none | none | none | M |

## Wave ORG.9D — Communications for operations

| ID | Title | Functions | Value | Current gap | Security | Campus | Approval | Gate | Dependency | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| PV1-10 | Staff-only audience for announcements and email | COM-016, COM-034 | staff notices that never reach families | School-wide = every active membership incl. Guardian/Student accounts (preview shows it) | existing `communications.*`; audience resolver only | none | existing announcement approval | must land before E46 production | none | S |
| PV1-11 | Fee-due reminder email | COM-045, COM-042 | fewer overdue dues | no purpose, no Fees → Communications link | a new closed `EmailPurpose` reviewed under ADR 0055; Fees publishes an event, Communications sends | none | none | E17–E20 for production delivery | ADR 0055 purpose amendment | M |
| PV1-12 | School events and holiday calendar (staff; family view later) | COM-024, LMS-115, COM-026, COM-027 | one calendar for terms, holidays, exams | years/terms/staff holidays exist separately | existing academic-structure view keys; family view behind E46 | Campus filter, not authority | none | E46 (family view) | none | M |

## Owner decisions before build (not scheduled)

| ID | Title | Functions | Decision needed | Gate | Size |
|---|---|---|---|---|---|
| PV1-13 | Opening balances (ledger and legacy Student dues) | FIN-009 | OD-SWC-5 / OD-PPR-20: migration method and authority for a first real School | none | M |
| PV1-14 | Certificates from templates with a numbered issue register; transfer/leaving certificate | CRT-001, CRT-002 | template ownership, statutory TC format, who issues; Student document owner type (OD-SWC-2) | E21 retention | L |
| PV1-15 | Student documents (repository, received checklist, additional uploads) | DOC-009, DOC-010, DOC-011, DOC-002 | OD-SWC-2 Student owner type; categories; who may manage | E21 | M |
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
