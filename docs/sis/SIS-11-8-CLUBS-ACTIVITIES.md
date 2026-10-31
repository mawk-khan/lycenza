# SIS 11.8 — Student Communities Expansion: Clubs & Activities Foundation

Status: **IMPLEMENTED (local DDEV)** · 2026-11-01 · branch `integration/sis-11-8`

| Item | Value |
|---|---|
| Baseline | `main = 69ae6dbc` (SIS 11.7 House pilot) |
| Owner decisions | **OD-SIS-5A: CLUB approved** · **OD-SIS-5B: ACTIVITY approved, with the constrained semantics below** |
| Still not authorized | Project, Scholarship cohort, Custom Group, System Tag, Student Segment, rule-generated groups |
| Reference family | SR-17 (Student groups), part |
| New tables | none (the SIS 11.7 tables are extended) |
| New capabilities | `students.communities.view`, `.manage`, `.assign` |
| Core SIS score | 96/100, not rescored |

## 1. Owner decisions

> **OD-SIS-5A — CLUB: APPROVED.** A School-defined Student community; a
> Student may belong to several at once.
>
> **OD-SIS-5B — ACTIVITY: APPROVED, constrained.** Structured
> extracurricular or co-curricular participation; a Student may take part in
> several at once. No scheduling, scoring, results, levels, fees, staff or
> documents in this checkpoint.

No arbitrary group type can be created. The closed registry is exactly
House, Club and Activity (`StudentGroupType`, two database CHECKs, pinned by
`StudentGroupArchitectureTest`).

## 2. Audit of the SIS 11.7 implementation (before any change)

| Aspect | SIS 11.7 (found) | SIS 11.8 decision |
|---|---|---|
| Group table | `student_groups` (School-owned, no Campus, version, deactivate-not-delete, RLS, no DELETE) | **kept**; type CHECK widened; optional `activity_category` |
| Type contract | CHECK `group_type IN ('house')` on groups and memberships; `StudentGroupType` | widened to `house, club, activity` (still closed) |
| Membership table | `student_group_memberships` (effective-dated, inclusive `ends_on`, empty interval for same-day, guards, version, cascade with the Student) | **kept** — no second memberships table |
| Current uniqueness | partial unique `(school, student, type) WHERE ends_on IS NULL` + no-overlap trigger per Student and type | House: same index, now `AND group_type = 'house'`; Club/Activity: new `(school, student, group) WHERE ends_on IS NULL`; trigger compares per group for Club/Activity |
| Responsibility table | `student_group_responsibilities` bound to one membership (four-column FK), unique holder index, insert needs a current membership | **kept**; kinds widened; the insert trigger also checks the kind belongs to the group's type |
| Stale mechanism | `StudentHouseVersion` (Student-wide, one House) | House unchanged; Club/Activity use the membership row `version` (see §5) |
| RLS / privileges | forced RLS, REVOKE DELETE | unchanged |
| Campus | Houses School-wide; `students.houses.view` Campus-adopted | Clubs and Activities School-wide; `students.communities.view` Campus-adopted |
| Reporting | `students.houses` (`HouseRosterReport`) | one shared roster query (`StudentGroupRosterReport`); House, Club and Activity rosters on it |
| Profile | House card | separate "Clubs and activities" card |
| Capabilities | `students.houses.*` | unchanged; new `students.communities.*` (§9) |

Working House infrastructure was not redesigned. The only House-touching
refactors are mechanical: the catalogue writes moved into the shared
`StudentGroupCatalogue` (House still through `HouseAdministrationService`),
and the Student-lock helpers moved into a shared trait. Every SIS 11.7 test
still passes unchanged in substance (29 House tests).

## 3. Schema

Migration `2027_01_24_090000_extend_student_groups_to_clubs_and_activities`:
- **Pre-check:** refuses if any non-House row exists. House rows are kept as
  they are.
- **Types:** `student_groups_type_check` and
  `student_group_memberships_type_check` cover `house, club, activity`.
- **`activity_category`:** nullable, closed (`sports`, `performing_arts`,
  `creative_arts`, `academic_enrichment`, `other`), Activities only (CHECK).
- **Reasons per type** (`student_group_memberships_reason_check`):
  - House keeps its five (start `assignment`/`reassignment`/`correction`);
  - Club/Activity start `joined` and end with `withdrew`, `completed`,
    `left_school`, `school_decision` or `correction`.
- **Uniqueness:**
  - `student_group_memberships_one_current` is now House-only;
  - new `student_group_memberships_one_current_per_group`;
  - the no-overlap trigger keeps House intervals apart per Student and type,
    and Club/Activity intervals apart per Student and group.
  - **Why race-safe:** both are partial unique indexes. Two concurrent
    inserts of the same current membership cannot both commit; the service
    additionally serializes on the Student row and maps a unique violation to
    a 409.
- **Responsibilities:**
  - kinds `house_captain`, `vice_captain`, `prefect`, `club_president`,
    `club_vice_president`, `club_secretary`, `member_representative`;
  - the unique-holder index covers Captain, Vice Captain, President, Vice
    President and Secretary;
  - the insert trigger refuses a kind that does not belong to the group's
    type (no responsibility on an Activity).
- **Rollback:** `down()` refuses while any Club or Activity row exists
  (deliberately: it would have to delete history), then restores the SIS 11.7
  definitions. Rollback proof IDENTICAL; `platform:verify-database` failed=0.

## 4. Behaviour

| | House (unchanged) | Club | Activity |
|---|---|---|---|
| Current per Student | at most one | any number, one per Club | any number, one per Activity |
| Start | today (School-local) | today, `joined` | today, `joined` |
| End | change or end: yesterday | leave: yesterday | end: yesterday |
| End reasons | House set | withdrew, school decision, left the School, correction | completed, withdrew, school decision, left the School, correction |
| Responsibilities | Captain, Vice (unique), Prefect | President, Vice President, Secretary (unique), Member Representative | none |
| Category | — | — | optional closed category |
| Deactivate with current members | refused | refused | refused |

- **History:** leaving and rejoining keeps both periods (a same-day leave is
  the empty interval, ADR 0078).
- **Inactive Student (decision, §44):** communities are **never changed
  automatically**. An inactive Student keeps current memberships and
  responsibilities until explicitly ended, but cannot join or take a
  responsibility. Reactivation adds nothing.
- **Lifecycle independence:** status changes, Campus transfer, rollover,
  Admissions conversion and the bulk import never read or write a community
  (architecture-pinned; transfer and status proven functionally).
- **Explicit only:** no bulk, import, rule-based or automatic allocation.

## 5. Concurrency and stale forms

- **Join:** no version token. Joining is additive and refused as
  `ALREADY_MEMBER` when the Student is already a current member. Joining a
  *different* Club at the same moment is valid by design (§37).
- **Leave and responsibility end:** carry the row's `version`, compared
  under the lock. A stale or forged value is a 409 with the SIS 11.H1 wording.
- **Locking:** the same order as Houses:
  1. the Student, FOR NO KEY UPDATE, with a fresh authority re-read;
  2. the membership;
  3. the group (FOR SHARE to join; FOR UPDATE for a unique responsibility
     and for deactivation).
- **Two-process proofs** (`StudentCommunityConcurrencyTest`; the contender
  is observed blocked, never timed):
  - same Student, same Club → one membership, the other `ALREADY_MEMBER`;
  - same Student, same Activity → one participation;
  - same Student, two Clubs → both memberships;
  - two Presidents → one, the other `RESPONSIBILITY_TAKEN`;
  - deactivation against a join → `COMMUNITY_HAS_MEMBERS`, the Club stays
    active.
  - The House race (`StudentHouseConcurrencyTest`) still passes on the
    generalized schema.
- **Retries (rule 29):** a retried join is `ALREADY_MEMBER`, a retried
  leave is stale, and a retried create hits the unique key. No `idempotent`
  middleware is needed.

## 6. Decisions on the optional parts

| Topic | Decision | Reason |
|---|---|---|
| Activity category | **Implemented** (optional, closed, five values) | Groups the directory and filters the roster; says nothing about any Student; no taxonomy |
| Skill / participation level | **Deferred** | Levels differ per activity; a closed per-Activity level list would need its own design and has no consumer yet |
| Schedule | **Deferred to Timetable** | No parallel scheduling engine (rooms, periods, staff load, conflicts belong to Timetable) |
| Staff mentors / coaches | **Deferred** | New user references and an HR/Timetable overlap; the community model does not need them. Future integration point: a descriptive staff link via `CurrentStaffMembers` (rule 108) |
| Activity responsibilities | **None** | No evidence of need |
| Badges, awards, results | **Deferred** | A separate participation/achievement checkpoint |

## 7. Surfaces

- **Students → Communities** (`/app/students/communities`): a hub that lists
  only the areas this viewer may open. It replaces the directory's "Houses"
  link; House URLs are unchanged.
- **Clubs** (`/app/students/clubs`), **Activities**
  (`/app/students/activities`): the one shared directory page (also used by
  Houses). It lists Campus-scoped counts and offers create, edit, deactivate
  and reactivate, with a roster link.
- **A Student's Clubs and activities** (`/app/students/{student}/communities`):
  - current memberships, Join Club / Leave Club, Add / End participation;
  - Club responsibilities;
  - separate Club and Activity history tables;
  - a read-only House line linking to the House page (House viewers only).
- **Student 360:** a separate "Clubs and activities" card. It shows at most
  four names per type, then "and N more", plus the link. The House card is
  unchanged.

## 8. Reporting and export

- **`students.clubs`** (Club roster) and **`students.activities`** (Activity
  participants): RENDERED, CAMPUS_AWARE, authority
  `students.communities.view`, on the same query as `students.houses`.
  Filters: group, search, responsibility or category, Campus, Grade,
  Section. No demographic column or breakdown. A Student in several Clubs
  appears once per Club.
- **Export/print review (§55, §56): A — browser-only remains correct.**
  - Who belongs with whom is relationship information about children, and no
    operational consumer has asked for a file.
  - A Campus roster file would also need its own D-11 column review.
  - The rosters are not in `StudentReportRegistry::EXPORTERS`; export and
    print return 404. House export stays deferred too.

## 9. Authorization

**Decision: three new keys, not a rename.** `students.houses.*` stay exactly
as they are, and `students.communities.view|manage|assign` cover Clubs and
Activities. Separate keys mean a School can later let a club coordinator run
Clubs without House authority (and vice versa), and no existing grant
silently widens.

| Key | Holders | Scope |
|---|---|---|
| `students.communities.view` | `school_admin`, `principal` | Campus-adopted (family `students`) |
| `students.communities.manage` | `school_admin`, `principal` | whole School |
| `students.communities.assign` | `school_admin`, `principal` | whole School |

- **Never** a Teacher, operational, Guardian, Group, Network or platform role;
  no portal or API.
- A Student's full community history is a declared whole-School operation
  (`app.students.communities.show`).
- The hub (`CommunityAdministrationService::hub()`, C3) opens on either view
  key.
- **Privilege separation (tests):**
  - House membership, Club membership, Activity participation and every
    responsibility leave `CapabilityResolver::schoolCapabilities()`
    unchanged for an administrator, a Teacher and a Campus Principal;
  - no authorization, tenancy, Campus, navigation, portal or API code names
    Student Groups.
- **Audit** (ids and closed codes only): `student_group.created`,
  `.updated`, `.deactivated` and `.reactivated` (with `groupType`), plus
  `student_community.joined`, `.left`, `.responsibility_assigned` and
  `.responsibility_ended`.

## 9a. Evidence

- **Tests:**
  - **Communities, 28 tests:** behaviour 12, database 5, two-process
    concurrency 5, rosters 5 (including a no-growth query bound for the roster,
    directory, Student community page and profile), executed-rollover
    integration 1.
  - **SIS 11.7 House suite (29) green** on the generalized schema.
  - **Broad local regression:** 5,102 passed (11 skips, all real object
    storage). The one failure was the Demo Student 360 card pin, fixed before
    the first candidate.
- **Rollback proof:** IDENTICAL; `verify-database` failed=0.
- **Browser journeys (local DDEV demo, Gachibowli): 52/52 checks.**
  - **House regression:** assign, change, Captain, deactivation refusal.
  - **A–F:** hub; Club create (normalized, duplicate refused); join two Clubs
    (profile card and roster agree); two tabs joining the same Club at once →
    one membership, "already a member"; leave and rejoin keeps both periods;
    President.
  - **G–J:** Activities with categories; several at once; two tabs adding the
    same Activity → one participation.
  - **K:** Early Years Campus Principal — Campus-only counts (1 of 2), no
    management, history 403, roster total in scope only.
  - **L:** another School 404.
  - **M:** Campus transfer through the **browser** Transfer form; House, Club
    and Activity unchanged (closes the SIS 11.7 API-only deviation).
  - **N:** rollover is automated only (`StudentCommunityRolloverTest`, a
    real executed rollover). The demo's rollover is already executed, and a
    second would rewrite the canonical demo world.
  - **O:** inactive keeps memberships, no join form; reactivation duplicates
    nothing.
  - **P:** empty Club deactivates; a Club and an Activity with members are
    refused; no delete control.
  - **Q:** Teacher 403 on all six surfaces.
  - **R:** no horizontal scroll at 390px on six pages; keyboard reach;
    Escape cancels.

  The demo was reset afterwards.

## 10. Boundaries kept

Not built in this checkpoint:
- mentors, schedules, levels, badges, awards, results;
- Projects, Scholarships, Custom Groups, tags, segments, rules;
- demographic analytics or leaderboards, automatic or bulk allocation;
- import columns, exports;
- Club/Activity fees, documents, announcements;
- portal views, API endpoints.

A missing Club or Activity is never a Data Health finding.

## 11. 318-screen reconciliation (Student groups, SR-17, 52 screens)

| SR-17 element | After SIS 11.8 |
|---|---|
| Houses, House membership, House responsibilities | **Implemented** (SIS 11.7) |
| Club catalogue | **Implemented** |
| Club membership (several at once, history) | **Implemented** |
| Club responsibilities | **Implemented** (President, Vice President, Secretary, Member Representative) |
| Activity catalogue (with broad category) | **Implemented** |
| Activity participation (several at once, history) | **Implemented** |
| Group rosters ("Student Group Details") | **Implemented** for House, Club and Activity |
| Staff mentors / coaches | Deferred |
| Skill levels | Deferred |
| Schedules | Deferred (Timetable) |
| Badges / awards | Deferred |
| Projects, Scholarships, Custom Groups | Not authorized |
| Tags, Student Segments, Sub Class Groups | Not authorized (not covered) |
| Gender / untagged analytics | Not adopted (ADR 0040) |
| Bulk "assign to group" | Not adopted |

SR-17 stays **partially met**, now House + Club + Activity. Student Segments
and Sub Class Group reports are not covered. The core SIS score is not
rescored.
