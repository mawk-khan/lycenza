# Campus authorization — integrated UAT plan (ORG.7H, 2026-10-21)

Status: **plan, not evidence.** It defines the personas, fixture and cases
for structured local/UAT testing of the closed ORG.7 Campus authorization
programme (ADR 0076 §33–§53). Execution evidence belongs in a separate
dated record, like `LOCAL-UAT-2026-10-15.md`. It verifies software
behaviour on synthetic data only: testers never judge whether a fee, late
fee, receipt form, RTE treatment, fine or marks rule is lawful (E30–E37,
E42 stay with Legal + Owner).

Finance has its own detailed plan: `docs/modules/FINANCE.md`, "Finance
Campus UAT readiness" (22 cases). This plan covers every domain together.

## Rules every tester applies

- **The server decides.** A hidden menu item is never the check; open the
  URL or call the API directly.
- **Out of scope looks unknown.** An out-of-scope record answers like a
  missing one (404, or the domain's one generic refusal on a write). A
  route the actor holds no scope for at all is 403.
- **NULL is never "every Campus".** A record with no Campus is
  whole-School only.
- **History keeps its Campus.** A transfer, a leaver, an ended assignment
  or a closed Campus never moves an old register, loan or Charge.

## Personas (actual role templates)

| Persona | Grants | Purpose |
|---|---|---|
| `school_admin` | whole School | full control; grants Campus roles |
| `principal@A` | principal, Campus A | academic, SIS, HR, operations on A |
| `principal@A+B` | principal, Campus A and B (two grants) | union of A and B |
| `hr_officer@A` | hr_officer, Campus A | Staff directory, assignments on A |
| `cashier@A` | cashier, Campus A | Finance reads and payment entry on A |
| `cashier@A+B` | cashier, Campus A and B | one-Campus-per-payment rule |
| `librarian@A` | librarian, Campus A | circulation on A |
| `transport_coordinator@A` | transport_coordinator, Campus A | routes/riders on A |
| `hostel_warden@A` | hostel_warden, Campus A | residency on A |
| `canteen_operator@A` | canteen_operator, Campus A | orders on A |
| `admissions_officer@A` | admissions_officer, Campus A | applications on A |
| `front_office@A` | front_office, Campus A | Visitor visits on A |
| `stores_officer@A` | stores_officer, Campus A | Inventory stock on A |
| `accountant` | whole School | Finance whole-School (never Campus) |
| `teacher` | whole School, own classes | ownership non-regression |
| Guardian | linked to one Student | Guardian scope non-regression |

## Fixture (one School, deterministic)

- **Campuses:** A (active), B (active), C (inactive). A second School for
  cross-School probes.
- **Students:** S1 active on A; S2 active on B; S3 transferred A -> B this
  year; S4 leaver (inactive) formerly on A; S5 unplaced; S6 with placements
  on A last year and B this year.
- **Employees:** E1 assigned A only; E2 B only; E3 A + B (primary on B);
  E4 no current assignment; E5 notice period on A; E6 separated, formerly A.
- **Academic:** a Section and Offering on A and on B; a Room per Campus;
  a timetable entry, attendance register, curriculum delivery, LMS content
  and an exam paper on each.
- **Hostel:** a Hostel on A with a room/bed; S1 resident.
- **Transport:** a route on A (S1 rider), a route on B, a NULL (shared)
  route.
- **Library:** a copy on A, a copy on B, a NULL copy; an open loan of the A
  copy to S3 made before the transfer.
- **Canteen:** an Outlet on A, an Outlet with no Campus; an A order for S3
  made before the transfer.
- **Admissions:** an application on A and one on B.
- **Visitor / Inventory:** a visit on A and on B; a store on A, on B and a
  School-wide store.
- **Finance:** as the FINANCE.md fixture (A, B and NULL Charges; A-only,
  B-only, mixed and NULL-touching Payments; S3 old A and new B debt; S4 A
  debt).

## Positive cases

| # | Actor | Precondition | Action | Expected | Invariant |
|---|---|---|---|---|---|
| 1 | principal@A | fixture | Students directory | S1 (and S3 only before transfer); never S2, S5 | current operational Campus |
| 2 | principal@A+B | fixture | Students directory | union of A and B current Students | grants union |
| 3 | school_admin | S3 on A | Campus transfer of S3 to B | old placement ended, new on B, Student number unchanged | named dual-scope transfer |
| 4 | principal@A | after case 3 | open S3 | not found | former Campus loses current access |
| 5 | cashier@A | after case 3 | Outstanding fees / record payment for S3's old A Charge | listed and collectable; S3's new B Charge never shown | Finance history stays on A |
| 6 | librarian@A | after case 3 | open S3's A loan; return the A copy | loan visible, return allowed (loan Campus A) | loan Campus frozen at checkout |
| 7 | school_admin | S1 Hostel resident or rider | Campus transfer of S1 | refused generically until the residency/rider is ended | transfer blockers |
| 8 | canteen_operator@A | after case 3 | A Outlet order history | S3's old order still listed under A | resource Campus kept |
| 9 | hr_officer@A | E3 on A+B | Staff directory, open E3 | E3 reachable; only the A assignment visible; no B detail | union of current assignments |
| 10 | cashier@A | S4 leaver | debtor search, record payment | S4 found; normal School receipt; in A collections | leaver debt collectable |
| 11 | school_admin | — | grant accountant for Campus A | refused (`role_not_campus_scopable`) | protected Finance role |
| 12 | stores_officer@A | Campus A made inactive | any stock page | 403; reactivate A: works again; no record changed | inactive Campus confers nothing |
| 13 | hostel_warden@A | Hostel module disabled | residency pages | 403; re-enable: works again | entitlement higher-order |
| 14 | school_admin | — | every page of every domain | full behaviour unchanged | whole-School unaffected |
| 15 | each Campus persona | NULL route / copy / store / Outlet / Charge | open or act on it | not found / refused | NULL = whole-School |
| 16 | teacher | own class on A | attendance, LMS, marks | ownership rules unchanged; no Campus effect | ownership independent |
| 17 | Guardian | linked to S1 | portal | unchanged after S1/S3 moves | GuardianStudentScope independent |
| 18 | admissions_officer@A | A application | open, submit, accept | allowed; B application not found; conversion 403 | application Campus fixed |
| 19 | front_office@A | A visit | check in/out; B visit | A allowed; B not found; conflict answers identical | concealment |

## Negative / abuse cases (mandatory)

| # | Actor | Attempt | Expected |
|---|---|---|---|
| N1 | principal@A | add `campus_id=B` (or a B id) to any list filter | filter only narrows; B rows never appear |
| N2 | principal@A | open B Student, Section, Employee, application by id | not found |
| N3 | principal@A | open S3 after its transfer to B | not found |
| N4 | hr_officer@A | open or change E3's B assignment | not found / refused generically |
| N5 | librarian@A | lend the B copy, or lend to S2 | one generic refusal |
| N6 | transport_coordinator@A | assign S2 to the A route, or anyone to the B route | one generic refusal |
| N7 | hostel_warden@A | board S2 | one generic refusal |
| N8 | cashier@A+B | open the mixed Payment or its receipt; pay A+B in one payment | not found; refused generically |
| N9 | cashier@A | collect the NULL Charge | refused generically |
| N10 | school_admin | grant accountant (or school_admin) for one Campus; raw DB insert in the test harness | refused by application and database |
| N11 | any Campus-only persona | open a declared whole-School route (Student Profile, HR Profile, fee statement, Finance/HR/Data Health hubs, corrections, configuration) | 403 |
| N12 | cashier@A+B | replay a payment after the A grant is revoked; then with no grant | generic refusal; then 403 |
| N13 | any Campus persona | use a disabled module | 403 |
| N14 | stores_officer@C | Campus C inactive | 403 |
| N15 | any persona | ids of the second School | not found |
| N16 | school_admin | change the Campus of a store with stock history (A -> B, A -> none, shared -> A) | refused (409); an unused store can still be corrected |

## Navigation (projection only, never security)

- A Campus-only actor sees an entry only when every capability it names is
  Campus-adopted and its route is not a declared whole-School operation.
- Safe Campus landing pages: Students, Staff directory, Outstanding fees,
  Payments received, Library circulation, Transport riders, Hostel
  residency, Canteen orders, Admissions applications, Visitor visits,
  Inventory stock, Timetable, Attendance, Curriculum delivery, LMS.
- Hidden whole-School hubs: Finance, HR, Data Health (each names a
  non-adopted key).
- Known UX residuals (not security): a Campus-only exam-paper holder has
  no Examinations-list path to papers (link or API only); a Campus-only
  admissions officer cannot start a new application (Applicant creation is
  whole-School); a Campus cashier reaches Record payment from Payments
  received.
