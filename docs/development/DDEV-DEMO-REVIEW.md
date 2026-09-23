# DDEV local demo / review environment

A resettable, browser-reviewable copy of the **currently published**
School OS (`apps/platform`), with one fictional demo School, realistic
records in every published module, and a login for every user persona the
application actually implements.

This is developer/review infrastructure only. It is not a staging
environment, it is never deployed, and nothing in it is real school data.

> **LOCAL DEMO CREDENTIALS -- NEVER USE IN PRODUCTION.**
> Every demo account uses the password **`Demo1234!`**. The seeder that
> creates them refuses to run anywhere except a local DDEV container (see
> [Security guards](#security-guards)).

---

## 1. Prerequisites

- Docker (Docker Desktop, Colima, OrbStack, or Docker CE on Linux/WSL2).
- [DDEV](https://ddev.com/) **v1.24 or newer** (verified with v1.25.3) --
  `web_extra_daemons` is required for the queue worker/scheduler.
- `mkcert -install` run once, so `https://lycenza.ddev.site` is trusted.
- No local PHP, Node, PostgreSQL or Redis is needed -- everything runs in
  DDEV's containers.

## 2. What DDEV provides

Configuration lives in `.ddev/` (`config.yaml`, `docker-compose.redis.yaml`,
`commands/web/demo-reset`, `commands/web/test`).

| Concern | DDEV setup | Source of truth for the version |
|---|---|---|
| PHP | 8.3 (nginx-fpm), `pdo_pgsql`, `bcmath`, `gd`, `zip` | `platform.Dockerfile`, CI (`php-version: "8.3"`) |
| Framework | Laravel 13 (13.26.1 locked), Inertia 3 + Vue 3 | `composer.lock` |
| Docroot | `apps/platform/public` (composer root `apps/platform`) | repo layout |
| Database | PostgreSQL 16 (DDEV `db` service, database `db`) | `docker-compose.yml`, CI |
| Runtime DB role | `school_os_app` -- NOSUPERUSER, NOBYPASSRLS (created by `ddev demo-reset`) | ADR 0021 |
| Migration DB role | DDEV's `db` superuser, via `--database=pgsql_admin` only | ADR 0021 |
| Redis | Valkey 8 as the private `redis` service (sessions, cache, queue) | `docker-compose.yml`, CI |
| Node | 22 (Vite 8 build) | CI (`node-version: "22"`) |
| Mail | DDEV's built-in **Mailpit** captures everything (`MAIL_MAILER=smtp` -> `127.0.0.1:1025`) | -- |
| Queue | `php artisan queue:work --tries=3 --queue=notifications,default,integrations` (DDEV `web_extra_daemons`) | `docker-compose.yml` `queue` service, `QueueName` |
| Scheduler | `php artisan schedule:work` (DDEV `web_extra_daemons`) | `routes/console.php` (4 every-minute tasks) |
| Files | Local disk (`FILESYSTEM_DISK`/`DOCUMENTS_DISK`/`COMMUNICATION_ATTACHMENTS_DISK=local`); no MinIO needed | `config/documents.php`, `config/communications.php` |

Connection settings are injected as **container environment variables**
(`web_environment` in `.ddev/config.yaml`), which Laravel prefers over
`apps/platform/.env`. `apps/platform/.env` is still shared with the
docker-compose workflow and is **not** rewritten by DDEV
(`disable_settings_management: true`); it only supplies `APP_ENV=local`
and `APP_KEY` (`ddev demo-reset` creates it from `.env.example` and
generates a key if it is missing).

Nothing in DDEV can reach a non-DDEV database: `DB_HOST=db` resolves only
on DDEV's private project network, and no Redis port is published.

## 3. First-time setup

From the repository root:

```bash
ddev start
ddev composer install
ddev exec npm ci          # `ddev exec` runs in apps/platform (see note below)
ddev demo-reset --build   # database + demo data + frontend build
```

Note: `ddev npm ...` runs in the container directory matching your **host
shell's current directory**; from the repo root that is not
`apps/platform`. Use `ddev exec npm ...` (always `apps/platform`), or
`cd apps/platform` first.

## 4. Start / stop

```bash
ddev start      # also starts the queue worker and scheduler
ddev stop       # keeps the database volume
ddev launch     # opens https://lycenza.ddev.site in the browser
ddev describe   # URLs and service status
```

## 5. `ddev demo-reset`

**Destructive** -- only ever for this project's own DDEV database.

```bash
ddev demo-reset            # reset data; builds assets only if no build exists
ddev demo-reset --build    # also always runs `npm run build`
```

What it does, in order:

1. **Refuses to run** unless: `IS_DDEV_PROJECT=true`; `DB_HOST=db`,
   `DB_DATABASE=db`, `DB_ADMIN_USERNAME=db`, no `DB_URL`/`DB_ADMIN_URL`;
   the PostgreSQL server that answers is the address `db` resolves to; and
   Laravel resolves `APP_ENV=local`.
2. Creates `apps/platform/.env` from `.env.example` and an `APP_KEY` if
   missing.
3. Creates/re-asserts the `school_os_app` runtime role (NOBYPASSRLS),
   drops and recreates schema `public` in DDEV database `db`, and re-grants
   default privileges.
4. `php artisan migrate --database=pgsql_admin --force`.
5. Flushes this project's private Redis (stale sessions, caches and
   queued jobs) and empties DDEV's Mailpit -- both **before** seeding, so
   the jobs and invitation e-mails the seed produces survive.
6. `php artisan db:seed` -- the canonical reference seed set
   (`DatabaseSeeder`: capability/role catalog, AI service identity,
   education boards, statutory rule versions).
7. `php artisan db:seed --class=Database\Seeders\Demo\DemoSeeder` -- the
   demo School, demo data and demo accounts (prints the account table).
8. `optimize:clear`, `queue:restart`, and ensures the supervised queue
   worker and scheduler are running (they process the seeded deliveries).
9. Builds frontend assets if requested or missing, then prints the URL.

## 6. URLs

| What | URL |
|---|---|
| Application | https://lycenza.ddev.site (login at `/login`) |
| System status (public) | https://lycenza.ddev.site/ |
| Mailpit | https://lycenza.ddev.site:8026 (or `ddev mailpit`) |

## 7. Queue and scheduler behaviour

Both run automatically inside the web container after `ddev start`
(check with `ddev exec supervisorctl status`). They make these flows
behave as they would in a real deployment:

- announcement / conversation deliveries (status, Failed page, Analytics);
- `communications:publish-scheduled` (scheduled announcements);
- `platform:outbox-dispatch` (domain-event outbox), webhook and
  communication redispatch.

Logs: `ddev exec tail -f storage/logs/laravel.log`.

## 8. Frontend assets

`ddev demo-reset --build` or `ddev exec npm run build` produces
`apps/platform/public/build`. For hot reload during UI work, `ddev exec npm
run dev` is possible but not configured for DDEV's HTTPS router; the demo
uses the production build.

---

## 9. The demo dataset

Built by `apps/platform/database/seeders/Demo/` through the application's
own Application services and `TenantContext` on the RLS-bound runtime
connection -- the same rules as `tests/Concerns/CreatesTenancyFixtures`.
Every name, e-mail (`*.test`) and phone number is fictional. "Today" for
the dataset is 2026-09-22.

**Lycenza Demo School** (`lycenza-demo`, Asia/Kolkata, one campus "Main Campus"):

| Area | What exists |
|---|---|
| Academic years | 2025-26 (closed), **2026-27 (active)**, 2027-28 (draft); Term 1 / Term 2 |
| Structure | Grades 6, 7, 8; Sections A and B per grade (2026-27) + next-year sections; 4 academic departments; 7 rooms |
| Subjects | English, Hindi, Mathematics, Science, Social Studies, Computer Science (core); French / Sanskrit (Grade 8 "Third Language" elective group) |
| Subject offerings | 18 core offerings + 2 Grade 8 electives |
| Students | 36 (6 per section), active enrollments, Grade 8 elective choices |
| Guardians | 24 (siblings share a guardian), e-mail + mobile contacts, legal-guardian relationships |
| HR | 12 employees (8 teachers + accountant, librarian, driver, office assistant), departments, positions, categories, employment records, assignments |
| Timetable | 5 periods, 150 entries (6 sections x 5 days x 5 periods, no teacher clashes) |
| Attendance | 12 submitted first-period registers (Grade 6-A and 7-A, 14-21 Sept) with some absent/late marks |
| Syllabus / delivery | 5 units each for English, Mathematics, Science per grade; Section A: 2 units completed, 1 in progress |
| Examinations | Unit Test 1 (July) and Term 1 Examination (28 Sept - 6 Oct), 30 papers each; active 8-band grade scale + a draft Pass/Fail scale |
| Communications | 2 templates; 2 published announcements (school-wide; Grade 8 guardians) + 1 draft; 2 conversations (staff; guardian) |
| Finance | 11 ledger accounts, opening and stationery journal entries, 36 Term-1 tuition charges, 30 payments (full and part), canteen charges |
| Payroll | 3 components, active structure, 8 compensation assignments; **August 2026 run posted**, **September 2026 run calculated** (awaiting approval) |
| Library | 6 titles, 12 copies, 4 active loans + 1 returned |
| Transport | North Route (3 stops), 2 buses, driver assignment, 8 students assigned |
| Visitor | 3 visitors; 2 completed visits, 1 currently on site |
| Hostel | Aravali Hostel, 2 rooms, 6 beds, 4 residents |
| Inventory | 2 locations, 5 items, receipts, an issue and a transfer |
| Canteen | Main Canteen outlet, 3 menu items with recipes, billing configuration, 5 orders (3 fulfilled) |
| Admissions | 4 applications: draft, submitted, accepted, rejected |
| Rollover | Draft enrollment rollover plan 2026-27 -> 2027-28 |

**Lycenza Demo Annexe School** (`lycenza-demo-annexe`) -- a second tenant
with one campus and 3 students, used to review School switching and
tenant isolation.

## 10. Demo accounts

Password for every account: **`Demo1234!`** (LOCAL DEMO ONLY).

| Persona | Email | School/Tenant | Start page | Key access |
|---|---|---|---|---|
| Platform Super Admin | `platform.admin@example.test` | none (platform scope) | `/app` (empty dashboard) | Platform capabilities only; **no platform web UI exists** |
| School Admin | `school.admin@example.test` | Lycenza Demo School | `/app` -> select School | `school_admin` system role: every module incl. Finance and Payroll administration |
| Principal | `principal@example.test` | Lycenza Demo School | `/app` -> select School | `principal` system role: academics, students, admissions, communications, operations; no Finance/Payroll |
| HR & Payroll Officer *(demo-only role)* | `hr.payroll@example.test` | Lycenza Demo School | `/app` -> select School | HR incl. departments/positions/categories and sensitive records; payroll incl. payslips and statutory screens |
| Multi-school member | `multi.school@example.test` | Both schools | `/app` -> choose a School | Principal at Demo School, School Admin at Annexe |
| School Admin (second school) | `annexe.admin@example.test` | Lycenza Demo Annexe School | `/app` -> select School | `school_admin` at the Annexe only |
| Teacher / staff (no role) | `teacher@example.test` | Lycenza Demo School | `/app` -> select School | No capabilities; linked to employee EMP-000003 (Kavya Reddy) |
| Student account | `student@example.test` | Lycenza Demo School | `/app` -> select School | No capabilities; linked to student LDS-0025 |
| Guardian account | `guardian01@example.test` | Lycenza Demo School | `/app` -> select School | No capabilities; linked to guardian Priya Sharma (activated through the real invitation flow) |
| *(pending invitation)* | `guardian02@example.test` | Lycenza Demo School | Mailpit invitation link | Not an account yet -- accept the invitation from Mailpit to review guardian activation |

`DatabaseSeeder` also creates its pre-existing local convenience user
`test@example.com` / `password` (no School membership). It is not part of
the demo.

**Selecting a School is required after every login.** The application does
not auto-select a School: the dashboard lists your memberships -- click
the School. Until you do, every module URL returns **500**
(`TenantContextRequiredException`) -- existing application behaviour, not a
demo defect.

### About the "HR & Payroll Officer" role

No seeded system role holds `hr.departments.*`, `hr.positions.*`,
`hr.categories.*`, the HR sensitive/personal-manage/assignments/documents/
notes/qualifications capabilities, `payroll.compensation.sensitive.*` or
any `payroll.statutory.*` capability, and the application has **no role-
management UI**. Without a custom role those implemented screens could not
be reviewed at all. The demo therefore creates one non-system school role,
`demo.hr_payroll_officer` ("Demo: HR & Payroll Officer"), through the real
`roles` / `role_capabilities` / `membership_role_assignments` tables -- the
same mechanism the test suite uses. It is **not** a product persona.

## 11. Implemented persona matrix

| Persona | Exists in code? | Login? | Tenant scope | Capabilities | Usable UI |
|---|---|---|---|---|---|
| Platform super admin | Yes (`platform_role_assignments`) | Yes | Platform | `platform.*` | **None** -- no platform pages; MFA-reset is a POST-only endpoint; operations status is API-only |
| School admin | Yes (system role `school_admin`) | Yes | One School per membership | 102 of the 137 catalog capabilities | Full admin UI |
| Principal | Yes (system role `principal`) | Yes | One School | 72 capabilities: academic/student/ops subset | Most admin UI except Finance, Payroll, HR org structure, canteen settings, comms analytics/failed/audit |
| Custom school role | Yes (non-system `roles`), DB-seeded only | Yes | One School | Any catalog subset | Whatever its capabilities unlock |
| School member, no role (teacher/staff) | Yes (membership only); `employees.user_id` link | Yes | One School | None | Dashboard, School setup index, communication preferences, account security (MFA) |
| Guardian | Yes (Phase 5D.3 invitation -> membership + account link) | Yes | One School | None | Same as "no role" -- **no guardian portal** |
| Student | Yes (Phase 5B account link) | Yes | One School | None | Same as "no role" -- **no student portal** |
| Teacher (as a role) | **No** -- there is no teacher role or teacher portal | -- | -- | -- | -- |
| Finance / Payroll / Operations officers | **No** distinct personas -- capabilities attached to a role | -- | -- | -- | -- |

## 12. Recommended review walkthrough

1. `ddev demo-reset`, open https://lycenza.ddev.site, sign in as
   **school.admin@example.test**, click **Lycenza Demo School**.
2. Dashboard navigation: Students -> open a student (guardians, enrollment,
   account link); Guardians; Enrollments; Enrollment Rollovers (draft plan);
   Admissions (4 states); Subject Offerings (Grade 8 elective group).
3. **School setup**: profile, campus, academic years (2026-27 active),
   grade levels, subjects.
4. **Communication Hub**: inbox, announcements (published/draft),
   conversations (staff + guardian thread), templates, approvals,
   analytics.
5. **Finance**: ledger accounts, journal entries, charges (paid / part-paid
   / outstanding), payments.
6. **HR**: employees -> an employee profile. **Payroll**: structures,
   compensation, periods -> August (posted) and September (calculated) runs.
7. Type these URLs (implemented but **not linked from any menu**):
   `/app/attendance`, `/app/syllabus`, `/app/syllabus-delivery`,
   `/app/examinations`, `/app/examinations/grade-scales`,
   `/app/library/titles`, `/app/library/circulation`,
   `/app/transport/routes`, `/app/transport/vehicles`,
   `/app/transport/assignments`, `/app/visitor/directory`,
   `/app/visitor/visits`, `/app/hostels`, `/app/hostel-residency`,
   `/app/inventory-items`, `/app/inventory-locations`,
   `/app/inventory-stock`, `/app/account/security`.
8. Sign out; sign in as **principal@example.test**: same School, but no
   Finance/Payroll links, and `/app/finance/ledger-accounts` returns 403.
9. **hr.payroll@example.test**: HR -> departments/positions/categories,
   an employee's sensitive sections; Payroll -> August run -> a payslip;
   Statutory.
10. **multi.school@example.test**: choose the Annexe -> 3 students only;
    switch School from the dashboard.
11. **teacher@example.test**, **student@example.test**,
    **guardian01@example.test**: dashboard with only "School setup";
    every module page returns 403 -- this is the current product state.
12. Open **Mailpit** -> "You're invited to Lycenza Demo School" for
    `guardian02@example.test` -> follow the link -> set a password ->
    you are signed in as a newly activated guardian.
13. **platform.admin@example.test**: empty dashboard, no School -- the
    platform administration UI is not built yet.

## 13. Interactive review map

| Module | URL / navigation | Persona | Reviewable? | Notes |
|---|---|---|---|---|
| School settings / setup | Dashboard -> School settings, School setup | School Admin | Yes | Terms, sections, rooms, departments are API-only (no pages) |
| Students / Guardians | Dashboard -> Students, Guardians | Admin, Principal | Yes | Account link + guardian invitation actions on detail pages |
| Enrollments / Rollovers | Dashboard -> Enrollments, Enrollment Rollovers | Admin (Principal view-only rollovers) | Yes | |
| Admissions | Dashboard -> Admissions | Admin, Principal | Yes | |
| Subject offerings / electives | Dashboard -> Subject Offerings | Admin, Principal | Yes (read) | No create page for offerings |
| Communications | Dashboard -> Communication Hub | Admin, Principal | Yes | Student conversations need `communications.conversations.students` (no role has it) |
| Finance / Fees / Payments | Dashboard -> Finance | School Admin | Yes | Payments are read-only (recorded via provider-settlement service) |
| HR | Dashboard -> HR | Admin, Principal, HR & Payroll | Partly | Org structure + sensitive sections need the demo HR & Payroll role |
| Payroll | Dashboard -> Payroll | Admin, HR & Payroll | Yes | Payslips/statutory need the demo role; statutory configuration is not seeded |
| Timetable | Dashboard -> Timetable Periods / Schedule | Admin, Principal | Yes | |
| Attendance | `/app/attendance` (no nav link) | Admin, Principal | Yes | |
| Syllabus / Curriculum delivery | `/app/syllabus`, `/app/syllabus-delivery` (no nav) | Admin, Principal | Yes | |
| Examinations / Grade scales | `/app/examinations`, `/app/examinations/grade-scales` (no nav) | Admin, Principal | Yes | No marks/results (not implemented) |
| Library | `/app/library/titles`, `/app/library/circulation` (no nav) | Admin, Principal | Yes | |
| Transport | `/app/transport/routes`, `/vehicles`, `/assignments`, `/operations` (no nav) | Admin, Principal | Yes | |
| Visitor | `/app/visitor/directory`, `/app/visitor/visits` (no nav) | Admin, Principal | Yes | |
| Hostel | `/app/hostels`, `/app/hostel-residency` (no nav) | Admin, Principal | Yes | |
| Inventory | `/app/inventory-items`, `/-locations`, `/-stock` (no nav) | Admin, Principal | Yes | |
| Canteen | Dashboard -> Canteen Outlets / Items / Orders / Settings | Admin (Principal: no settings) | Yes | |
| Account security (MFA) | `/app/account/security` (no nav) | Any | Yes | MFA is opt-in; nothing requires it by default |
| Guardian activation | Mailpit invitation link | (invitee) | Yes | |

## 14. Implemented but not reviewable in the browser

- **Platform administration**: `platform.*` capabilities exist; no pages.
  Operations status is `GET /api/internal/operations/status` (API only).
- **API-only surfaces** (`/api/v1/schools/{school}/...`): academic terms,
  sections, academic departments, rooms, campus CRUD, subject-offering
  creation, **Documents** (incl. employee/sensitive documents), **webhooks**
  (endpoints/deliveries), education boards. The API is not reachable from
  a browser session (no session-authenticated API; no token-issuing UI) --
  a Sanctum token must be minted in `ddev artisan tinker`.
- **Guardian / student / teacher self-service**: account links, guardian
  invitation/activation and communication audiences are implemented, but
  there are no guardian, student or teacher portals -- linked users land on
  an empty dashboard.
- **Role and member management**: no UI; roles/assignments are DB-only.
- **Feature flags**: tables and resolver exist; no flags seeded or used.
- **Statutory payroll** (Phase 9.6): screens exist; no statutory
  configuration/identifiers are seeded, so they render empty.
- **Domain-event outbox / webhooks fan-out**: runs in the background
  (scheduler + queue); no UI beyond webhook API.
- **Student Processing Authorization registry** (Phase 0H.4D-P2, published
  in `7419b8f`): JSON-only routes under
  `/app/students/{student}/processing-authorizations`, gated by
  `students.processing_authorizations.*` (School Admin, Principal) **and**
  the `mfa` middleware; its catalog feature flag
  (`students.processing_authorizations`) is seeded default-off. There is no
  Vue page. To exercise it: enrol MFA for the School Admin at
  `/app/account/security`, then call the JSON endpoints. No demo
  authorization records are seeded.

## 15. Not implemented / deferred / unpublished

Not part of this demo because they are **not in `origin/main`** at the
time this environment was created (base `7419b8f`):

- Phase 0H.4D-P2 **lock correction** (`a9a2a5d`, unmerged branch). The P2
  registry itself (`7419b8f`) *is* published -- see section 14.
- Phase 0I LMS -- Learning Content, Assignments (unmerged branches;
  Submission is cancelled on those branches).
- Phase 0L.1 Analytics domain contract (unmerged branch).

Documented but not implemented on main: StudentMark / marks, results,
report cards and transcripts (legally gated); Lesson Planning (deferred);
Health and Safety (deferred, legally/security blocked); Analytics,
Compliance, Automation (0L); real AI agents (0M); multi-school management
UI (0N); external surface / production readiness (0O); portal logins.

## 16. Running tests inside DDEV

```bash
ddev test --reset-db                      # first time: create + migrate + seed school_os_test
ddev test                                 # full suite
ddev test tests/Feature/Demo              # scoped
ddev test --filter=DemoEnvironmentGuardTest
```

`ddev test` runs against a separate **`school_os_test`** database inside
DDEV's PostgreSQL -- never the demo database. `phpunit.xml` forces every
safety-relevant value (`APP_ENV=testing`, `DB_DATABASE=school_os_test`,
array cache/session, sync queue, array mail); `ddev test` only redirects
the connection *target* to DDEV's `db` service through the explicit
`PHPUNIT_DB_HOST` / `PHPUNIT_DB_PORT` / `PHPUNIT_DB_ADMIN_*` overrides
(`tests/bootstrap.php`), and resets with the canonical
`platform:test-db-reset`. A raw `ddev exec php artisan test` fails
closed: its forced `DB_HOST` is docker-compose's `postgres`, which does
not resolve inside DDEV.

`ddev test --reset-db` drops and recreates the DDEV-private
`school_os_test` database before running `platform:test-db-reset`:
`migrate:fresh` drops tables but not the PL/pgSQL functions some
migrations create, so re-running it on an already-migrated database fails
with `function ... already exists` (a limitation of the canonical reset
command itself, outside DDEV).

Known environment gaps inside DDEV: the MinIO integration tests
(`DocumentMinioStorageTest`, `DocumentReadMinioIntegrationTest`,
`DocumentHttpMinioIntegrationTest`) need a real MinIO, which DDEV does not
run -- use the docker-compose `minio` service for those.

## 17. Troubleshooting

| Symptom | Fix |
|---|---|
| 500 on every module page after login | Select the School on the dashboard (see section 10). |
| 500 on login: `Target class [...] does not exist` | `vendor/` is out of date for this branch: `ddev composer install`. |
| Blank page / missing assets | `ddev exec npm ci && ddev exec npm run build` (or `ddev demo-reset --build`). |
| `ddev npm ...` cannot find `package.json` | Use `ddev exec npm ...` or run from `apps/platform`. |
| `ContactLookupKeyNotConfiguredException` | `ddev restart` (container environment changes need a restart). |
| Invitation link says invalid/expired | Links from earlier resets are dead; `ddev demo-reset` and use the newest Mailpit message. |
| "Page expired" (419) | Session was flushed by a reset -- reload `/login`. |
| Port 80/443 conflicts | Stop the conflicting service or see `ddev config global --router-http-port`. |
| Queue/scheduler not running (deliveries stay `pending`) | `ddev exec supervisorctl status`; `ddev exec supervisorctl start 'webextradaemons:*'` or `ddev restart`. `ddev start` on an already-running project can skip them; `ddev demo-reset` starts them. |

## 18. Complete DDEV reset

```bash
ddev delete --omit-snapshot --yes   # removes this project's containers AND its database volume
ddev start
ddev composer install
ddev exec npm ci
ddev demo-reset --build
```

`ddev delete` affects only the `lycenza` DDEV project; the docker-compose
volumes (`school-os_*`) and other DDEV projects are untouched.

## 19. Relationship to docker-compose

`docker-compose.yml` remains the documented alternative local workflow
(`CLAUDE.md`). DDEV does not read `docker-compose.yml` or
`docker-compose.override.yml`. A machine-local `docker-compose.override.yml`
(e.g. host port remaps) is git-ignored by the repository root `.gitignore`.

## Security guards

Fixed-password demo users and the destructive reset cannot run in
production because:

1. **`ddev demo-reset`** is a DDEV *web container* command; it verifies
   `IS_DDEV_PROJECT=true`, `DB_HOST=db`, `DB_DATABASE=db`, the admin user
   `db`, the absence of connection URLs, that the answering PostgreSQL
   server is the address `db` resolves to, and that Laravel resolves
   `APP_ENV=local` -- before touching anything.
2. **`DemoSeeder`** calls `DemoEnvironmentGuard::assertLocalDdev()`:
   `APP_ENV` must be exactly `local` **and** `IS_DDEV_PROJECT=true` **and**
   both `pgsql` and `pgsql_admin` must resolve to host `db` / database `db`
   with no URL. `DatabaseSeeder` never calls it.
3. **`DemoDataBuilder`** (the code that creates the accounts) repeats the
   guard itself; its only other allowed environment is `testing` against
   the dedicated test database -- which `TestDatabaseGuard` already enforces
   at boot.
4. Nothing in the demo bypasses authorization or RLS: data is created on
   the NOBYPASSRLS runtime connection inside `TenantContext`, lifecycle
   records go through their Application services, and accounts receive
   capabilities only through real role assignments.
