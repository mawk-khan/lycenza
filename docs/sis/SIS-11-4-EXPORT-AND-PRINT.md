# SIS 11.4 — Governed SIS Export & Print Foundation

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-4`

This is the decision **D-11** (SIS export) in the scoped form the V11 closure
audit recommended: *a per-dataset capability, output identical to the
screen, an audit event per export, CSV formula-injection guarding, no Highly
Sensitive column without its own capability*
(`docs/product/V11-CLOSURE-AUDIT.md` §9). The core SIS score stays 96/100.

## 1. What existed before (audit)

- **No shared export framework.** The only downloads were:
  - payroll statutory exports, each building a whole file in memory, with
    no formula-injection guard (the open HR P3);
  - the Student and Transport import templates;
  - Documents and Communications attachments (file downloads, not data
    exports).
- **No library:** no spreadsheet writer. The one PDF engine is the
  locked-down dompdf for certificates (SIS.EXP-4), not a report renderer.
- **No capability:** `analytics.export` is granted to no role.
- **Rules 103–104** (Finance office and daily-operations read models) say
  "never add an export" to *those* surfaces. They are unchanged: SIS 11.4
  exports none of them. Rule 123 deferred SIS exports to the D-11 contract,
  which this checkpoint is.

## 2. Architecture

```
Report registry (StudentReportRegistry, closed)
  └── EXPORTERS: report key → exporter (built on that report's OWN query)
        └── StudentReportExportService
              authorize (report authority + export capability)
              → the page's own filter/sort rules
              → declared, reachable columns
              → row count ≤ ceiling
              → audit (identifiers only)
              → streamed CSV  |  print view
```

- **Pieces:**
  - `App\Support\StudentReports\Export\` — `StudentReportExporter`
    (contract), `StudentReportExportDefinition` (capability, columns,
    filename base, formats, ceilings), `StudentReportExportColumn`,
    `CsvCell` (injection guard), `StudentReportExportService`;
  - `StudentReportAccess::authorizeExport()` / `mayExport()`.
- **Routes:** `POST /app/students/reports/{report}/export` (CSRF, audited,
  `private-no-store`) and `GET /app/students/reports/{report}/print`. Both are
  C3 route-coverage contracts.
- **Shared queries.** The page and its export read exactly the same rows:
  - Directory: `StudentDirectoryQuery` (new; the page now uses it too);
  - Movements: `StudentMovementRegister::rows()` / `rules()`;
  - Admissions: `AdmissionApplicationReadService::directoryQuery()` /
    `listRules()`;
  - Lifecycle and Section capacity: the read models' own `query()`.
- **Pagination vs export:** the screen shows one page; the export contains
  the whole filtered, authorized result (up to the ceiling) in the screen's
  sort.

## 3. Capability model (D-11)

| Capability | Dataset | Granted to | Notes |
|---|---|---|---|
| `students.export` | Student directory, lifecycle, movements, Section capacity | `school_admin` | whole School; not Campus-adopted; class `children` |
| `admissions.export` | Admission applications | `school_admin` | whole School; not Campus-adopted; class `children` |

- **An export needs all of:**
  1. the report's own authority (e.g. `students.view`; Campus-aware where
     the report is);
  2. the dataset's export capability, for the whole School;
  3. every chosen column within the viewer's reach.
- **No "export everything":** `students.export` never opens Admissions, and
  `admissions.export` never opens Student reports.
- **Viewing never implies exporting:** the controls are absent without the
  capability, and a direct request is refused (403).
- **Campus:** export authority is whole-School, but rows always follow the
  viewer's resolved VIEW scope. A user granted `students.export` with a
  Campus-scoped `students.view` exports only that Campus; a forged Campus
  matches nothing.
- **Not granted to:** Principal, office, Admissions, Teacher, Librarian,
  Campus roles. Grant through the normal reviewed catalogue change if a
  School needs it.

## 4. Exportable reports and columns

| Report | Export columns (all default) | Not exported |
|---|---|---|
| `students.directory` | Student number, name, status; academic year, Campus, Grade, Section, roll number (these need `enrollments.view`) | date of birth, Guardian, contacts |
| `students.lifecycle` | Recorded on, Student number, name, action, reason; placement (needs whole-School `enrollments.view`) | acting user, free text, MFA/audit data |
| `students.movements` | Date, Student number, name, category, movement, from, to, reason | actor, Guardian, date of birth |
| `admissions.applications` | Applicant, status, academic year, Campus (inactive labelled), Grade, created on, converted (Yes/No) | Applicant date of birth, contacts, decision note, fees, the Student's name or number |
| `students.section_capacity` | Academic year, Campus, Section, advisory capacity, Students, seats, counted on | any Student name |
| `students.siblings` | **not exportable** | family relationships stay on screen (the 2026-10-31 SIS-019 / PV1-17 decision approved a names-only list on screen, not its export) |
| `students.placements` | **not exportable** (not in the pilot) | — |

- **Column rules.** Columns are declared, never inferred.
  - `StudentReportExportColumn` refuses every
    `StudentReportColumn::PROHIBITED` key, plus any key naming an id, uuid,
    date of birth, phone, email, contact, address, gender, religion, caste,
    HMAC or token.
  - A request may choose among the declared columns (`columns[]`). A forged,
    unknown or out-of-reach column is a validation error, never silently
    dropped or included.
- **Values.** Exports carry human values (names, numbers, labels), never ids
  or raw codes. **Dates are ISO `YYYY-MM-DD`** (stable for spreadsheets;
  deliberately different from the screen's "31 Oct 2026"). There are no
  times.

## 5. CSV contract and formula injection

- **Encoding:** UTF-8 with a BOM, RFC 4180 quoting (`fputcsv`, no escape
  character), a header row of the column labels.
- **Formula guard (`CsvCell`):** a cell whose first character after any
  leading spaces or control characters is `=`, `+`, `-` or `@`, or that
  starts with a tab or carriage return, is prefixed with `'` so spreadsheets
  show it as text. A plain number (e.g. `-7`) is left as it is. The guard
  applies to every cell, labels included.
- **Filename:** `{fixed-slug}-{School-local date}.csv` (e.g.
  `student-directory-2026-10-31.csv`). No request input reaches it, and it
  is built by `HeaderUtils::makeDisposition`.
- **Response headers:** `Cache-Control: no-store, private`,
  `X-Content-Type-Options: nosniff`.

## 6. Scale

- **Ceiling:** synchronous and streamed, at most **10,000 rows** per download
  (`MAX_ROWS`) and **1,000** per print view (`MAX_PRINT_ROWS`). Above that
  the viewer is asked to narrow the filters (a validation message showing
  the count).
- **Why 10,000:** the largest realistic K-12 School holds a few thousand
  Students, and a year of lifecycle or movement events is of the same order.
- **Streaming:** rows are produced in chunks of 500 (one page query plus one
  eager-loaded read each), so memory stays bounded.
- **No background job** and **no file is written**: nothing persists except
  the audit event, so there is no retention, signed URL or ownership model
  to build.
- **Snapshot semantics:** an ordinary request, with each chunk read at
  generation time. Authorization and scope are resolved in the same request
  that generates the file, so a revoked grant cannot be used later.

## 7. Audit

One `student_report.exported` School audit event per CSV download and per
print view:

- **Recorded:** actor and School (envelope), `report`, `format` (`csv` or
  `print`), `columns` (keys), `rowCount`, and `filters` (ids, codes and
  dates; a typed search is recorded only as `(search used)`).
- **Never recorded:** a row value, name or number.
- **When:** the event is written before the CSV streams, once the count is
  known.
- **Tests** find the event by type, actor and content, never by
  same-second timestamp order (the SIS 11.3 finding).

## 8. Print

`/print` renders the same plan (authority, filters, sort, columns, ceiling)
as a print-ready page:

- the School name, report title, active filter summary (ids shown as this
  School's own names), the generation time and row count;
- no navigation or controls when printed (the layout's header and menu are
  `print:hidden`), and table headers repeat on each printed page.

It is browser printing only: not an official PDF. A downloadable PDF and
XLSX are deferred: no approved writer exists, and certificate-grade PDF
rules are their own contract (ADR 0081).

## 9. Boundaries

- **Not exported:** demographics, Guardian or Applicant contacts, dates of
  birth, statutory identifiers, Finance figures, Data Health findings,
  internal ids.
- **Not built:** an API export, analytics, or an arbitrary table or SQL
  export.
- **Schema:** none. Capabilities change only through the seeder
  (`students.export`, `admissions.export`), plus the role snapshot and
  capability classes.

## 10. Coverage effect

The reference pack's report families gain a governed CSV download and print
view where their SIS-owned report is exportable:

- SR-03 (directory);
- SR-10 / SR-12 (lifecycle, exits);
- SR-36 / SR-37 (movements);
- SR-24 (operational capacity only);
- SR-39 (the Admissions operational list).

Deferred or rejected fields (contacts, demographics, family dues, the
analytics halves) are **not** covered merely because an exporter exists. The
SIS-owned report subset stays 6/8 governed and 8/8 dispositioned. XLSX and
PDF stay deferred.
