# SIS 11.7 — Student Communities & Groups Foundation — House Pilot

Status: **IMPLEMENTED (local DDEV)** · 2026-10-31 · branch `integration/sis-11-7`

| Item | Value |
|---|---|
| Baseline | `main = 86a2ba86` (SIS 11.H1) |
| Owner decision | **OD-SIS-4: House is the first authorized Student Group pilot** |
| Reference family | SR-17 (Student groups), part |
| New tables | `student_groups`, `student_group_memberships`, `student_group_responsibilities` |
| New capabilities | `students.houses.view`, `students.houses.manage`, `students.houses.assign` |
| Core SIS score | 96/100, not rescored |

## 1. OD-SIS-4 (owner decision)

> **House is the first authorized Student Group pilot.**

What the decision allows, and what it does not:

- **Allowed:** School-owned Houses; explicit, effective-dated, historical
  Student membership (at most one current House); descriptive House
  responsibilities; a Student-profile House card; a House directory with
  operational counts; a House roster report.
- **Not allowed in this checkpoint:** clubs, activities, projects,
  scholarships, generic custom groups, tags, segments, demographic analytics
  (boys/girls per House, untagged analysis), group-based authorization,
  portal exposure, bulk "assign to House", automatic allocation, House
  capacity, XLSX/PDF, dashboards, badges and schedules.
- **Not a group engine.** The foundation is shaped so a later, separately
  authorized type is a reviewed migration (the closed CHECK) plus code, but
  no type other than `house` exists, and there is no type configuration.

## 2. Audit of existing schema

Before anything was added, the repository was searched for House, club and
group concepts. There was none: academic cohorts are Grade + Section
(Academic Structure); "groups" elsewhere are School Groups (platform
Networks, rule 113), Elective Groups (subject choice) and fee targeting.
None of them models a Student community, and none was reused or changed.

## 3. Data model

Migration `2027_01_23_090000_create_student_groups_tables` (rollback proof
IDENTICAL, `platform:verify-database` failed=0).

### `student_groups` (Houses)
- School-owned (RLS, `school_id` RESTRICT), no Campus: **Houses are
  School-wide**, so Students of several Campuses share a House and a Campus
  transfer never changes it.
- `group_type` CHECK `IN ('house')` — the closed type registry, mirrored by
  `StudentGroupType`.
- Name (trimmed, no control characters) unique per School and type,
  case-insensitively; code normalized upper-case (rule 74) and unique.
- `status` active/inactive, `display_order`, `description`, `version`
  (+1 on every change, trigger). School and type are immutable.
- No runtime DELETE (rule 73).

### `student_group_memberships`
- Effective-dated on School-local calendar days, **inclusive** `ends_on`
  (the placement convention). A same-day change records the empty interval
  `ends_on = starts_on - 1` (ADR 0078).
- **At most one current membership per Student and type** (partial unique
  index `student_group_memberships_one_current`) and **no overlapping
  non-empty intervals** (trigger; the repository uses no EXCLUDE/btree_gist).
- Closed `start_reason`/`end_reason` vocabulary (`HouseMembershipReason`):
  assignment, reassignment, correction, left_school, school_decision.
- Same-School composite keys to the House `(id, group_type, school_id)` and
  the Student `(id, school_id)` (rule 70). The Student key cascades: the
  record goes with the Student core record (retention).
- Identity and start are immutable; an ended row is final history; ending is
  refused while a current responsibility exists; `version` +1. No runtime
  DELETE. `started_by_user_id`/`ended_by_user_id` RESTRICT (rule 97).

### `student_group_responsibilities`
- Descriptive labels (`HouseResponsibility`): House Captain, Vice Captain,
  Prefect. **Never a permission.**
- Bound to one membership of the same Student, House and School (a
  four-column composite key), and started only on a current membership
  (trigger).
- **Captain and Vice Captain are unique per House while current** (partial
  unique index); a Prefect is not; a Student holds a responsibility at most
  once at a time.
- Immutable identity, final when ended, `version` +1, no runtime DELETE.

## 4. Behaviour

| Operation | Rule |
|---|---|
| Create / edit House | whole-School `students.houses.manage`; unique name and code; edits carry the House `version` (stale = 409); audited with changed field names only |
| Deactivate House | refused (`HOUSE_HAS_MEMBERS`) while it has current members; the House row is locked FOR UPDATE, so a racing assignment is either seen or waits. Reactivation allowed. Never deleted |
| Assign | starts **today** (School-local); no back-dating; only an **active** Student and an **active** House |
| Change | needs a closed reason (`reassignment` "Moved to another House" or `correction`); the current membership and its responsibilities end **yesterday**; the new one starts today |
| End | closed reason (School decision, Left the School, Correction); allowed for any Student status |
| Responsibility | only on a current membership of an active Student; Captain/Vice unique per House; ends with its membership; ending one carries its `version` |
| Stale form | every membership write carries `StudentHouseVersion` (Student, current membership id, House, version), compared under the Student row lock; stale or forged = 409 (web `refusal`), missing = 422 |

**Retries (rule 29 review):** no `idempotent` middleware is needed. A
retried assign, change or end carries the version the first attempt already
consumed and is refused as stale (409); a retried responsibility is
`RESPONSIBILITY_HELD`; a retried House create hits the unique name/code; a
retried status change returns the House unchanged. Nothing can apply twice.

**Locking:** the Student (FOR NO KEY UPDATE, rule 101) first, and authority
re-read fresh there (`holdsInSchoolNow`), then the current membership, then
the House (FOR SHARE to join, FOR UPDATE for a unique responsibility).
Deactivation takes only the House.

**Lifecycle independence.** Houses are not placements:
- a Student **status change** (inactive / active) keeps the House unchanged;
  an inactive Student cannot receive a new House or responsibility;
- a **Campus transfer** keeps it (Houses are School-wide);
- **rollover** keeps it (membership is not per academic year);
- **Admissions conversion** creates no membership.

None of those services reads or writes Student Groups
(`StudentGroupArchitectureTest`).

**Deviation from the SIS.EXP-7 sketch.** The roadmap sketch proposed
membership "per academic year". The approved brief requires rollover to
neither change nor duplicate House membership, so membership is
effective-dated and independent of the year. The sketch was a proposal, not
an ADR; the roadmap row is updated.

**Deferred: staff mentors.** A mentor needs a staff picker over
`CurrentStaffMembers`, a new user reference and a "My House" read path, and
no workflow uses one yet. Responsibilities are implemented; mentors are not.

## 5. Authorization

| Capability | Holders | Scope |
|---|---|---|
| `students.houses.view` | `school_admin`, `principal` | **Campus-adopted** (`CampusScopeCatalogue`, family `students`) |
| `students.houses.manage` | `school_admin`, `principal` | whole School |
| `students.houses.assign` | `school_admin`, `principal` | whole School |

- **Never** a Teacher, operational, Guardian, Group, Network or platform
  role. No portal, API or export surface.
- **House directory** (`GET /app/students/houses`): Campus-aware
  (`Coverage::campus`). A Campus viewer sees every House, but **counts only
  the Students in their scope**, so the School-wide size never leaks.
- **A Student's House page** (`GET /app/students/{student}/house`): a
  declared whole-School operation of the adopted key
  (`CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS`) — the full history is
  School-wide — plus whole-School `students.view` (else NOT FOUND).
- **Student 360 card:** `StudentHouseService::summary()` under the viewer's
  `students.houses.view` scope; absent (never hidden) without it.
- **Writes:** route floor `capability:students.houses.manage|assign`, then
  the service re-checks under the lock.
- **Privilege separation:** a House or responsibility never changes
  `CapabilityResolver`'s answer (`a_house_or_responsibility_never_changes_anyones_capabilities`),
  and no authorization, tenancy, Campus, navigation, portal or API code
  names Student Groups (`StudentGroupArchitectureTest`).
- **Audit** (`school_audit_events`): `student_group.created/updated/
  deactivated/reactivated`, `student_house.assigned/changed/ended`,
  `student_house.responsibility_assigned/_ended` — ids and closed codes only,
  never names.

## 6. Reporting

`students.houses` (SR-17) is a RENDERED `StudentReport`
(`HouseRosterReport`) in `StudentReportRegistry`:
- **Authority:** `students.houses.view`, CAMPUS_AWARE through
  `StudentAccess` (a Campus reader lists only Students whose current Campus
  is in scope).
- **Filters** (closed `StudentReportFilter` vocabulary): House, search,
  responsibility, Campus, Grade, Section. A forged House id matches nothing;
  a malformed one is a validation error.
- **Columns:** Student, House, Class (RESTRICTED: `enrollments.view`),
  Responsibility, Member since. No demographic column, count breakdown,
  percentage or chart.
- **Export/print: deferred.** House membership is relationship information
  that needs its own D-11 export review; the report is browser-only and is
  not in `EXPORTERS`.

## 7. Tests

| Suite | Proves |
|---|---|
| `StudentHouseTest` (11) | administration, history, stale forms (service and web), inactive Students and Houses, deactivation refusal, responsibilities, Campus transfer + status changes, authorization matrix (admin, principal, Campus principal, Teacher, Librarian, view-only, assign-only, house-view-only), other School, profile card, privilege separation |
| `StudentGroupDatabaseTest` (5) | every DB backstop with raw SQL on the runtime role: closed type, one current, no overlap, empty interval, immutability, final history, composite keys, Captain uniqueness, no DELETE, RLS isolation |
| `StudentHouseConcurrencyTest` (3, two OS processes) | one Student two Houses → one current; two Captains → one; deactivation racing an assignment → refused |
| `HouseRosterReportTest` (4) | governed, no export; filters only narrow; Campus scope; other School |
| `StudentGroupArchitectureTest` (4) | closed vocabularies equal the DB; only declared readers; only the two writers; lifecycle services untouched; least privilege; route surface |

**Browser journeys (local DDEV demo, Gachibowli): 38/38 checks.**
- **A–F:** create (normalized codes, duplicate refused), assign, change (the
  same-day row labelled), a stale second tab refused with nothing saved,
  Captain, a second Captain refused, Prefect.
- **G:** no mentor surface (deferred).
- **H:** Early Years Campus Principal — Campus-only counts, no management,
  history 403, roster in scope only.
- **I:** another School — 404, no Houses shown.
- **J:** Campus transfer through the API keeps the House.
- **K:** rollover is covered by automated tests only (not run in the
  browser).
- **L:** inactivate/reactivate through the UI keeps the House and Captain;
  no assign while inactive; nothing duplicated.
- **M:** deactivation refused with members; an empty House deactivates; no
  delete control.
- **N:** history with reasons and human dates, no ISO dates or UUIDs.
- **O:** Teacher 403 everywhere.
- **P:** no horizontal scroll at 390px on three pages; keyboard reach;
  Escape cancels.

The demo was reset afterwards.

## 8. 318-screen reconciliation (group coverage)

SR-17 covered 52 reference screens (one group hub in nine type tabs).
SIS 11.7 meets the **House** part only:

| SR-17 element | Status |
|---|---|
| House master (name, code, description, order, active) | **Met** |
| Student House membership with history | **Met** (effective-dated, not per year — §4) |
| Responsibilities (captain, prefect) | **Met** (Captain, Vice Captain, Prefect) |
| House roster | **Met** (`students.houses`) |
| Staff mentors | Deferred (§4) |
| Bulk "assign to House", auto allocation | Not adopted (OD-SIS-4 boundary) |
| Clubs, custom, project, finance and store groups | Not adopted (separate owner decisions; finance/store targeting belongs to Fees) |
| Logo, badges, schedules | Not adopted (logo waits for the governed media pipeline, rule 115) |
| Boys/girls and untagged analysis | Not adopted (demographic analytics, ADR 0040) |

SR-17 moves from `OWNER_DECISION_REQUIRED` to **partially met (House)**; the
remaining group types stay owner-gated. The core SIS score is not rescored.

## 9. Not built

Everything listed in §1 "Not allowed", plus: no House capacity, no House
points or competitions, no Guardian or Student view, no API, no import, no
export or print, no notification.
