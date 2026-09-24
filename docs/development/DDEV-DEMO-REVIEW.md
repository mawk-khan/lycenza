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
- daily retention prunes: `platform:idempotency-prune` and
  `platform:webhook-deliveries-prune` (the latter deletes nothing unless
  `WEBHOOKS_DELIVERY_RETENTION_DAYS` is set; try
  `ddev artisan platform:webhook-deliveries-prune --days=1 --dry-run`).

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
| Syllabus / delivery | 5 units each for English, Mathematics, Science per grade; Section A: 2 units completed, 1 in progress; Section B: Mathematics 4 completed, Science 1 completed + 1 in progress, English not started (Hindi, Social Studies, Computer Science have no syllabus) |
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

### Login-page shortcuts

In the DDEV demo environment the login page shows a **Demo accounts**
panel under the normal form. Clicking an account **only fills the email and
password fields** of that same form and focuses **Sign in** -- you still
sign in through the normal `POST /login` (same validation, throttling, MFA
and audit). There is no auto-login route.

The panel is decided server-side by `App\Support\Demo\DemoLoginPanel`
using the same fail-closed `DemoEnvironmentGuard` as the demo seeder
(`APP_ENV=local` AND `IS_DDEV_PROJECT=true` AND both database connections
on DDEV's private `db` database). Anywhere else the page receives
`demo: null`: no email or password reaches the browser, and the compiled
JS bundle contains no credentials. Only accounts that exist are listed
(nothing before `ddev demo-reset`). The account list lives in
`database/seeders/Demo/DemoAccountCatalog.php`.

To switch persona, use **Log out** in the account bar at the top of every
signed-in page (it shows the account's name and email, whether or not a
School is selected; 403 pages carry their own Log out button). Signing
out returns to the login page, where the shortcuts can be used again:

Log out → a freshly loaded login screen (a full page load, not an
in-place swap) → browser **Back** must not reveal the previous account's
page (it lands on the login screen again; Forward likewise) → choose
another demo persona.

If Back ever shows the previous name/email or page data after logout,
that is a privacy defect, not a demo quirk: see "After logout" in
`docs/security/AUTHORIZATION.md` (encrypted Inertia history cleared on
logout, `no-store` on signed-in pages). The check needs HTTPS
(`https://lycenza.ddev.site`); Inertia's history encryption is
unavailable on a plain-HTTP origin.

**When a session ends without Log out** (it expired after
`SESSION_LIFETIME` minutes of inactivity, or was signed out in another
tab), the open page stays as it is until it next talks to the server.
The next click, filter or form then loads a fresh login screen that says
"Your session has ended. Please sign in again.", and Back/Forward must
not reveal the previous account's page. To review this without waiting
two hours, sign in in one tab, sign out in a second tab of the same
browser, then use the first tab.

The product's login limiter allows **6 sign-in attempts per minute per
IP** -- switching accounts very quickly shows "Too Many Attempts"; wait a
minute.

### Accounts

| Persona | Email | Purpose | Main access | Known limitations |
|---|---|---|---|---|
| School Admin | `school.admin@example.test` | Broad review of every module | `school_admin` system role (111 capabilities): all modules incl. Finance and Payroll administration | HR departments/positions/categories, payslips and statutory screens are 403 (no system role holds them) |
| Principal | `principal@example.test` | Academic administration | `principal` system role (80): academics, students, admissions, communications, operations, LMS | Finance, Payroll, HR org structure, canteen settings, comms analytics/audit are 403 |
| HR & Payroll *(demo-only role)* | `hr.payroll@example.test` | HR and payroll depth | `demo.hr_payroll_officer` (37 existing `hr.*`/`payroll.*` capabilities): HR incl. sensitive records, payroll runs, payslips, statutory | No students/academics/finance access |
| Multi-school Admin | `multi.school@example.test` | School switching, tenant isolation | Principal at Demo School, School Admin at Annexe | Must pick a School after every login |
| Annexe School Admin | `annexe.admin@example.test` | Tenant isolation | `school_admin` at the Annexe only (3 students) | Demo School records return 404 |
| Platform Admin | `platform.admin@example.test` | Platform scope | `platform_super_admin` (12 `platform.*` capabilities, incl. `platform.schools.elevate` and the three School Group governance ones) | `/app` shows a neutral "platform account, no School access" state, **Enter a School (elevated access)** (step 13) and **School Groups (platform)** (step 13a); School URLs return to `/app` without elevation and are 403 under it. Not a Group Admin |
| Group Admin | `group.admin@example.test` | Group scope: Lycenza Demo Trust | `group_admin` Group grant (`group.schools.view`, `group.schools.elevate`), granted by the Platform Admin | **Your School Groups** -> the Trust's two Schools (name, status); enter one through elevated access (needs MFA). No School membership or School permission; cannot change the Group |
| Teacher / Staff | `teacher@example.test` | Current teacher experience | School member with no role, linked to Employee EMP-000003 | **No teacher portal exists**: dashboard, School setup index, preferences, account security only; modules 403 |
| Student | `student@example.test` | Current student experience | Member with no role, linked to Student LDS-0025 | **No student portal exists**: same as Teacher |
| Guardian | `guardian01@example.test` | Current parent experience | Member with no role, linked to Guardian Priya Sharma (activated via the real invitation flow) | **No parent/guardian portal exists**: same as Teacher |
| Finance Officer *(demo-only role)* | `finance.officer@example.test` | Finance in isolation | `demo.finance_officer`: only `finance.*` (6) -- ledger, journals, charges, payments | Everything else 403 |
| Librarian *(demo-only role)* | `library.operator@example.test` | Library in isolation | `demo.librarian`: only `library.*` (4) | No menu link: use `/app/library/titles`, `/app/library/circulation` |
| Transport Coordinator *(demo-only role)* | `transport.operator@example.test` | Transport in isolation | `demo.transport_coordinator`: only `transport.*` (6) | No menu link: use `/app/transport/routes` |
| Reception / Visitor Desk *(demo-only role)* | `reception@example.test` | Visitors in isolation | `demo.reception`: only `visitor.*` (4) | No menu link: use `/app/visitor/directory`, `/app/visitor/visits` |
| Hostel Warden *(demo-only role)* | `hostel.warden@example.test` | Hostel in isolation | `demo.hostel_warden`: only `hostel.*` (4) | No menu link: use `/app/hostels`, `/app/hostel-residency` |
| Canteen & Stores *(demo-only role)* | `canteen.operator@example.test` | Canteen + inventory | `demo.canteen_stores`: only `canteen.*` (6) + `inventory.*` (4) | Inventory has no menu link: `/app/inventory-stock` |
| Communications Coordinator *(demo-only role)* | `communications@example.test` | Communication Hub in isolation | `demo.communications_coordinator`: exactly the Principal's 7 `communications.*` capabilities | Analytics/failed/audit/channel settings 403 (same as Principal) |
| *(pending invitation)* | `guardian02@example.test` | Guardian activation flow | -- | Not an account until the Mailpit invitation is accepted; not in the login panel |

`DatabaseSeeder` also creates its pre-existing local convenience user
`test@example.com` / `password` (no School membership). It is not part of
the demo and not in the login panel.

**Selecting a School is required after every login.** Sign-in lands on
the context-neutral `/app` page, and the application never selects a
School for you: `/app` lists your memberships under "Select a School" --
click one. Until you do, a School page (a menu link, a bookmark or a
typed URL) sends you back to `/app` with "Select a School to continue"
instead of opening, and a form submitted without a School is refused
(409, nothing saved). The same happens if the selected School stops being
usable (membership suspended or removed, School suspended): the selection
is cleared and you choose again. An account with no School membership --
including the Platform Admin -- gets a neutral `/app` with no School data.
Phase 0N.1; see `docs/architecture/PHASE-0N-READINESS.md` section 11.

### About the demo-only roles

The seeded system roles are only `platform_super_admin`, `school_admin` and
`principal`, and the application has **no role-management UI**. Several
implemented screens are therefore unreachable by any system role (HR
org-structure and sensitive records, payslips, statutory payroll), and no
account shows a single module in isolation. The demo creates non-system
school roles -- all keys `demo.*`, all names `Demo: ...` -- through the real
`roles` / `role_capabilities` / `membership_role_assignments` tables (the
same mechanism the test suite uses), each holding **only capabilities that
already exist** in `CapabilityAndRoleSeeder`. They are created solely by
the guarded `DemoSeeder`, never by `DatabaseSeeder`, and are **not**
product personas.

Considered and not created: a "canteen student" or any student/guardian
self-service desk (no student- or guardian-facing capability exists), a
teacher role (the product has none), an examinations/timetable/attendance
desk (those capabilities are held by the Principal already, and nothing new
would become reviewable), and a platform-operations account beyond the
Platform Admin (there is no platform UI to review).

## 11. Implemented persona matrix

| Persona | Exists in code? | Login? | Tenant scope | Capabilities | Usable UI |
|---|---|---|---|---|---|
| Platform super admin | Yes (`platform_role_assignments`) | Yes | Platform | 12 `platform.*` | The neutral `/app` landing, platform elevation (enter one School for 30 minutes; opens no School page yet) and School Group governance -- no other platform pages; MFA-reset is a POST-only endpoint; operations status is API-only |
| Group admin | Yes (`group_role_assignments`, Phase 0N.5) | Yes | One School Group per grant | 2 `group.*` | Read-only Group view; Group-derived elevation (opens no School page yet) |
| School admin | Yes (system role `school_admin`) | Yes | One School per membership | 111 of the 153 catalog capabilities | Full admin UI |
| Principal | Yes (system role `principal`) | Yes | One School | 79 capabilities: academic/student/ops subset | Most admin UI except Finance, Payroll, HR org structure, canteen settings, comms analytics/failed/audit |
| Custom school role | Yes (non-system `roles`), DB-seeded only | Yes | One School | Any catalog subset | Whatever its capabilities unlock (the demo's `demo.*` roles) |
| School member, no role (teacher/staff) | Yes (membership only); `employees.user_id` link | Yes | One School | None | Dashboard, School setup index, communication preferences, account security (MFA) |
| Guardian | Yes (Phase 5D.3 invitation -> membership + account link) | Yes | One School | None | Same as "no role" -- **no guardian portal** |
| Student | Yes (Phase 5B account link) | Yes | One School | None | Same as "no role" -- **no student portal** |
| Teacher (as a role) | **No** -- there is no teacher role or teacher portal | -- | -- | -- | -- |
| Finance / Library / Transport / Reception / Hostel / Canteen / Communications officers | **No** product roles -- each is a real, complete capability family, reviewable through a `demo.*` role | Yes (demo) | One School | One family each | That module only |

## 12. Recommended review walkthrough

1. `ddev demo-reset`, open https://lycenza.ddev.site/login, click
   **School Admin** in the Demo accounts panel, press **Sign in**, then
   click **Lycenza Demo School**.
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
   `/app/inventory-stock`, `/app/learning-content`, `/app/assignments`,
   `/app/account/security`.
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
13. **platform.admin@example.test** (Phase 0N.3 platform elevation,
    ADR 0044): `/app` says this is a platform account with no School
    access and shows no School data; typing a School URL (for example
    `/app/students`) returns to `/app`. To review elevation:
    - The demo Platform Admin has **no MFA factor**, and every elevation
      needs a fresh MFA code. Enroll one first at **Account security**
      (`/app/account/security`) with any authenticator app, then sign out
      and in again.
    - There is **no School directory** by design; the target must be
      named exactly -- a verified School domain (the demo has none) or
      the School's id:
      `ddev exec 'psql -h db -U db -d db -At -c "select id from schools where name = \$\$Lycenza Demo School\$\$"'`.
    - `/app` -> **Enter a School (elevated access)** -> paste the id,
      pick a reason (operational support, security investigation,
      configuration assistance, incident response) -> **Continue** -> the
      confirmation page names the School -> tick the confirmation, enter
      a current code -> **Enter School**.
    - Every page now shows the amber **Elevated access** banner (School,
      end time, minutes left, "no School permissions are granted
      automatically", **Exit elevated access**). Elevation grants no
      School permissions and no School page accepts it yet, so
      `/app/school-setup`, `/app/students`, `/app/finance` and every other
      School page return **403** -- that is the intended Phase 0N.3
      state, not a defect.
    - **Exit elevated access** returns to `/app` with no School context.
      It also ends after 30 minutes, on logout, or if the account loses
      the capability or its MFA factor. The start and confirm steps are
      rate-limited (8 per minute).
13a. **School Groups** (Phase 0N.5, ADR 0045). The demo has one Group,
    **Lycenza Demo Trust**, holding both Schools.
    - As **platform.admin@example.test**: `/app` -> **School Groups
      (platform)**. Create a Group (name + slug), add a School by its exact
      id or verified domain (a name is refused -- there is no search),
      grant **Group Admin** to a person by exact email (granting to
      yourself is refused), revoke it, archive the Group (never deleted).
      The Platform Admin is not a Group Admin: `/app/groups/...` is 404.
    - As **group.admin@example.test** (enroll MFA at Account security
      first, then sign in again): `/app` -> **Your School Groups** -> the
      Trust shows its two Schools with name and status only. **Enter
      (elevated access)** on one -> reason -> confirmation naming the School
      and "School Group: Lycenza Demo Trust" -> tick, code -> the banner
      reads "... (via Lycenza Demo Trust)". Every School page is still
      403.
    - While the Group Admin is elevated, the Platform Admin removing that
      School from the Trust, or revoking the grant, ends it at once; the
      Group Admin's next page says why. Re-add / re-grant afterwards (or
      run `ddev demo-reset`).
    - **multi.school@example.test** sees no School Groups at all.
14. **Analytics** (Phase 0L.2-1): as **School Admin** or **Principal**,
    Dashboard -> **Analytics: Curriculum Coverage**
    (`/app/analytics/curriculum-coverage`). It shows, for the active
    2026-27 year, syllabus coverage as completed / in progress / not
    started syllabus units -- totals, by grade level, and by Subject
    Offering (expand a row for its Sections). Every figure counts syllabus
    units, never students or staff. Switch the year selector to 2025-26 to
    see an empty report. Any other persona (HR & Payroll, the `demo.*`
    desks, teacher/student/guardian) gets **403**; the Annexe's admin sees
    only the Annexe's own (empty) coverage. **Student/person Analytics
    (enrollment, attendance, admissions, results, fees, payroll, HR, ...)
    is deliberately NOT available**: no minimum person-cohort size has
    been approved, so any report that counts people fails closed
    (`docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md`). There is no
    export.
15. **Compliance: Audit log** (Phase 0L.4): as **School Admin** or
    **Principal**, Dashboard -> **Compliance: Audit log**
    (`/app/compliance/audit-log`). It lists the School's audit events,
    newest first, 50 per page ("Older events ->"), showing only the event
    envelope -- time, event type, actor user id, subject type and id,
    request id, event id. Event details (metadata) are never shown. Each
    visit adds one `compliance.audit_log.viewed` event, which appears at
    the top on the next visit. Every other persona (teacher, HR & Payroll,
    the `demo.*` desks, student, guardian, platform admin) gets **403** and
    has no link. The Annexe's admin sees only Annexe events (initially
    none but their own reviews); `multi.school` sees whichever School is
    selected. The page records what happened -- it does not certify legal
    compliance.
16. **Automation** (Phase 0L.6): as **School Admin**, Dashboard ->
    **Automation** (`/app/automation`). The Demo School has Automation
    switched on and the one rule, **Academic year set-up review**, enabled
    with the School Admin as accountable owner. `demo-reset` activates two
    academic years *after* enabling it, so once the queue worker has run
    (about a minute) the page shows **2 review items** and 2 succeeded
    executions -- produced by the real event path (activation event ->
    outbox -> Automation consumer -> execution job), not seeded. To trigger
    it yourself: School setup -> Academic years -> **Activate** 2027-28
    (this closes 2026-27 and changes the active year for every other
    review step until the next `demo-reset`); within a minute a third
    review item appears. A review item only points at the year to review;
    nothing is set up or sent. Disable / Re-enable are audited
    (`automation.rule.*`). **Principal** sees the page without controls;
    every other persona gets **403**. The **Annexe** has Automation
    switched off (no rule runs there) as the negative control.

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
| LMS: Learning Content, Assignments | `/app/learning-content`, `/app/assignments` (no nav link) | Admin, Principal | Yes (empty -- not seeded) | Submission is cancelled and has no screen |
| Analytics: Curriculum Coverage | Dashboard -> Analytics: Curriculum Coverage (`/app/analytics/curriculum-coverage`) | Admin, Principal | Yes | Counts syllabus units only; no person Analytics, no export (Phase 0L.2-1) |
| Compliance: Audit log | Dashboard -> Compliance: Audit log (`/app/compliance/audit-log`) | Admin, Principal | Yes | Envelope fields only, no metadata; each visit is itself audited (Phase 0L.4) |
| Automation | Dashboard -> Automation (`/app/automation`) | Admin (manage), Principal (view) | Yes | One rule (academic year set-up review), tier 0 review items only; Demo School on, Annexe off (Phase 0L.6) |
| Account security (MFA) | `/app/account/security` (no nav) | Any | Yes | MFA is opt-in; nothing requires it by default |
| Guardian activation | Mailpit invitation link | (invitee) | Yes | |

## 14. Implemented but not reviewable in the browser

- **Platform administration**: `platform.*` capabilities exist; the only
  platform pages are platform elevation (step 13 in section 12), which
  opens no School page yet, and School Group governance (step 13a).
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
- **Student Processing Authorization registry** (Phase 0H.4D-P2, registry
  and its lock correction both published): JSON-only routes under
  `/app/students/{student}/processing-authorizations`, gated by
  `students.processing_authorizations.*` (School Admin, Principal) **and**
  the `mfa` middleware; its catalog feature flag
  (`students.processing_authorizations`) is seeded default-off. There is no
  Vue page. To exercise it: enrol MFA for the School Admin at
  `/app/account/security`, then call the JSON endpoints. No demo
  authorization records are seeded.

## 15. Not implemented / deferred / unpublished

Everything that was unpublished when this environment was first created
has since been published to `main` (2026-09-23 consolidation): the
Phase 0H.4D-P2 lock correction, Phase 0I LMS (ADR 0039: Learning Content
and Assignments; Submission **cancelled / out of scope**), and the Phase
0L.1 Analytics domain contract (ADR 0040). Phase 0L.2-1 has since added
the first Analytics report (Curriculum Coverage, section 12 step 14). The demo dataset does not seed any LMS
records; the LMS screens (section 13) start empty.

Documented but not implemented on main: StudentMark / marks, results,
report cards and transcripts (legally gated); Lesson Planning (deferred);
Health and Safety (deferred, legally/security blocked); person-counting
Analytics (blocked on the unapproved minimum cohort size), Analytics
export, cross-School Analytics, Compliance beyond the School audit log (0L.4), Automation beyond the one academic-year review rule (0L.6); real AI agents (0M); multi-school management
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
`school_os_test` database (a guaranteed-empty start) and then runs the
canonical `platform:test-db-reset`.

Known environment gaps inside DDEV: the MinIO integration tests
(`DocumentMinioStorageTest`, `DocumentReadMinioIntegrationTest`,
`DocumentHttpMinioIntegrationTest`) need a real MinIO, which DDEV does not
run -- use the docker-compose `minio` service for those.

## 17. Troubleshooting

| Symptom | Fix |
|---|---|
| Every module page returns to `/app` ("Select a School to continue") | No School is selected yet, or the selection became invalid: select the School on `/app` (see section 10). A 500 on a School page is now a bug -- report it. |
| 500 on login: `Target class [...] does not exist` | `vendor/` is out of date for this branch: `ddev composer install`. |
| Blank page / missing assets | `ddev exec npm ci && ddev exec npm run build` (or `ddev demo-reset --build`). |
| `ddev npm ...` cannot find `package.json` | Use `ddev exec npm ...` or run from `apps/platform`. |
| `ContactLookupKeyNotConfiguredException` | `ddev restart` (container environment changes need a restart). |
| Invitation link says invalid/expired | Links from earlier resets are dead; `ddev demo-reset` and use the newest Mailpit message. |
| "Page expired" (419) | Session was flushed by a reset -- reload `/login`. |
| "Too Many Attempts" on sign-in | The product login limiter allows 6 attempts/minute/IP; wait a minute. |
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
4. **The login-page Demo accounts panel** (`App\Support\Demo\DemoLoginPanel`)
   is decided server-side by the same `DemoEnvironmentGuard::assertLocalDdev()`;
   in any other environment the page receives `demo: null`, so no demo
   email or password is ever sent to the browser, and the compiled JS
   bundle contains no credentials. It only prefills the normal form --
   there is no auto-login route (`DemoLoginPanelTest` proves both).
5. Nothing in the demo bypasses authorization or RLS: data is created on
   the NOBYPASSRLS runtime connection inside `TenantContext`, lifecycle
   records go through their Application services, and accounts receive
   capabilities only through real role assignments.
