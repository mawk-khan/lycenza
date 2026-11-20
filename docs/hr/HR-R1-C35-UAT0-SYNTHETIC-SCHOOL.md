# HR.R1-C35-UAT0: the synthetic HR UAT School (D-RF2-23, RF2 §10.2; 2026-11-20)

**An operator record. It prepares UAT; it does not pass it.** One synthetic School, its actors and the HR fixtures the RF2 §10.1 acceptance matrix needs were created in local DDEV through the canonical services. No browser walkthrough, axe scan or screen-reader pass was run for acceptance: each remains to be done. HR.R1 stays **OPEN**; no baseline is selected.

| | |
|---|---|
| Authority | D-RF2-23 (owner, 2026-11-20: the synthetic School "approved in principle", run after WO-24 and the DDEV migration) and RF2 §10.2. The owner's UAT0 go-ahead (2026-11-20) and three owner answers given during this unit: **(1)** enrol a TOTP factor on the local demo Platform Admin (no platform account had one; creation, module changes and activation need a fresh code); **(2)** the owner runs the interactive `platform:provision-school-admin-account` himself; **(3)** the pre-WO-20 legacy kept-access record is **Not configured** (only a write below the database guards could create it, which §10.2 forbids) |
| Source | `58ae0236` = `main`; no code, migration or test changed. Clean tree throughout (operator files are git-ignored) |
| Environment | DDEV `lycenza`, database `db`, PostgreSQL 16.14, `local`; 402/402 migrations before and after; the `jurimatterlaw` project untouched |
| Not done | No `demo-reset`, reset, migration, seed or raw SQL write; no existing School, User or business row changed; no real person, statutory identifier or external delivery; no Payroll run; no MFA bypass (every proof came from `FreshMfaRequirement` with a real TOTP code); no test-only code path; no UAT sign-off |

## 1. How it was built

- **Operator scripts:** `apps/platform/storage/app/private/uat0/` (git-ignored, never committed; the E19 operator-script precedent). Each runs in the DDEV web container, refuses anything but `local` + database `db`, writes only through Application services, and records a manifest. SHA-256 prefixes:

  | Script | Hash |
  |---|---|
  | `lib.php` | `8334c6fa8cbe6125` |
  | `phase0_platform_mfa.php` | `769ef45f8bbb00bb` |
  | `phase1_school.php` | `c32438ad42249441` |
  | `phase2_accounts.php` | `409a11be2c9f7bd7` |
  | `phase3_mfa.php` | `28b54fefe271bdfa` |
  | `phase4_hr.php` | `7e583be719d14846` |
  | `phase5_leave.php` | `f4c4929c77f60b03` |
  | `phase6_separation.php` | `29a1f86a904864d0` |
  | `phase7_tasks.php` | `befa40e3533551e7` |
  | `phase8_identity.php` | `7853507331c68c0c` |
  | `verify_states.php` (read only) | `d92633f3e7f5cb8b` |

- **Credentials** (passwords, TOTP secrets, `otpauth` URIs, recovery codes) live only in `secrets.json` there (mode 0600, git-ignored). The School administrator's password was set by the owner in the browser and is held by the owner alone.
- **Phases:**
  - **0.** `MfaEnrollmentService` begin/confirm on `platform.admin@example.test` (owner answer 1).
  - **Owner.** `platform:provision-school-admin-account uat-hr.admin@example.test`, activated in the browser.
  - **1.** `SchoolLifecycleService::create` (bootstrap administrator: that account); `SchoolModuleEntitlementService::enable` for `leave`, `staff_attendance`, `finance`, `payroll` (reason `onboarding_configuration`); `activate`. Each step used its own fresh code.
  - **2.** 14 accounts through `StaffInvitationService::issue` (by the School administrator) and `StaffInvitationAcceptanceService::accept` as new accounts.
    - The link was read from each invitation's sealed email, the content Mailpit receives.
    - The invitation send limiter (10 a minute) stopped the first run after 10 accounts. It was respected: the rerun waited for the window.
  - **3.** TOTP factors for the principal and the HR officer (the two MFA test identities) and for Employee A's second account, whose refusals sit behind the routes' fresh-code step.
  - **4.** Campus, staff calendar, Employees, employments and account links:
    - Campus `UAT-MAIN`;
    - staff calendar (`setWeeklyPattern`: Mon–Fri full, Sat first half, Sun off; history from the School's today, 2026-11-21);
    - 13 Employees and employments (`EmployeeService`, `EmploymentService`; the officer's own employment created by the other HR account);
    - 11 account links by the School administrator.
  - **5.** Leave:
    - a leave year;
    - a monthly-accrual type and policy (2 units, period end) and an annual one (24 units), with four assignments;
    - an annual allocation with a real fresh code;
    - accrual runs for April to August.
  - **6.** Two clearance requirements and eight separation cases through record → approve (principal, fresh code) → finalize (officer, fresh code) → clear → kept / complete.
  - **7.** A task category, a self and a reviewed daily template, today's generation, and submit / return / resubmit / review.
  - **8.** A link correction (officer requests, principal approves with a fresh code) and a revoked add-on role.
- **Dates:** the School's timezone is Asia/Kolkata, so its "today" was 2026-11-21 while the server's UTC date was 2026-11-20. A first task-template call with the UTC date was refused (`TASK_VERSION_EFFECTIVE_FROM`), correctly; the scripts now use `SchoolTimezone::today()`. Case notice dates of 2026-11-20 are valid (on or before the School's today).

## 2. The School and its actors

**School:** `UAT Synthetic HR School (fixture, not real)`, slug `uat-synthetic-hr`, code `UAT-HR-SYN`, id `01a1faac-070d-7323-b814-9cee47e7d59f`, `active`; Campus `UAT-MAIN`. Optional modules enabled: `leave`, `staff_attendance`, `finance`, `payroll` (`staff_records` and `identity_access` are core).

Every account is `uat-hr.<key>@example.test`; names are synthetic.

| Key | School roles | Purpose | MFA |
|---|---|---|---|
| admin | `school_admin` (bootstrap) | School administration, Staff accounts, kept-access recording | Owner enrols (Staff accounts needs it) |
| officer | `hr_officer` | Employment, separation manage, Leave, tasks; correction requester; viewer without `school.roles.view` (kept roles shown as a count) | Yes |
| principal | `principal` | Independent separation approver; correction reviewer; Staff Attendance lock-key holder | Yes |
| linemanager | `staff_self_service`, `line_manager` | Leave approval add-on | No |
| teammanager | `staff_self_service`, `team_task_manager` | Team task add-on | No |
| payrollreviewer | `staff_self_service`, `payroll_approval_reviewer` | Payroll reviewer add-on (no run exists) | No |
| staff-a | `staff_self_service` | Employee A, current account; task assignee | No |
| staff-a-alt | `hr_officer` | Employee A, second account (ended, uncorrected link): known-same-person refusals | Yes |
| staff-b | `staff_self_service` | Employee B | No |
| leaver-open | `staff_self_service` | E07: still holds a role after finalization | No |
| leaver-kept | `staff_self_service`, `line_manager` | E09: two roles kept on purpose | No |
| person-p | `staff_self_service` | E10: current account, unlinked after finalization | No |
| person-p-old | `staff_self_service`, `line_manager` | E10: old account (ended, uncorrected) still holding roles | No |
| wrong | `staff_self_service` | E11: link corrected as another person's | No |
| revoked | `staff_self_service` (`team_task_manager` revoked) | Stale permission | No |

**Links:** 7 current, 3 ended and uncorrected (staff-a-alt → A; person-p-old and person-p → P), 1 corrected (wrong → C, reason `wrong_person`).

## 3. Fixtures

| Key | Employee | Staff type | Start | State |
|---|---|---|---|---|
| E01 | EMP-000001 | non_teaching | 2025-06-01 | Officer's own |
| E02 | EMP-000002 | teaching | 2025-06-01 | Principal's own |
| E03 | EMP-000003 (A) | teaching | 2026-04-01 | Active; monthly accrual; tasks |
| E04 | EMP-000004 (B) | teaching | 2026-04-01 | Case **requested** (last day 2026-12-31); access ATTENTION; monthly accrual + annual 24-unit allocation; tasks |
| E05 | EMP-000005 | non_teaching | 2025-01-01 | Case **approved**; basis `non_teaching`, nothing skipped |
| E06 | EMP-000006 | **unclassified** | 2025-01-01 | Case **approved**; basis unclassified, **one requirement skipped** (`UAT-LAB`, teaching only) |
| E07 | EMP-000007 | teaching | 2025-01-01 | Case **finalized**; access **OPEN** (1 role) |
| E08 | EMP-000008 | outsourced | 2025-01-01 | Case **completed** |
| E09 | EMP-000009 | teaching | 2025-01-01 | Case **finalized**, access kept: **RETAINED**, bound set of 2 grants |
| E10 | EMP-000010 (P) | teaching | 2025-01-01 | Case **finalized**; current account unlinked; access **OPEN** (3 roles across both accounts); completion refused until off-boarded |
| E11 | EMP-000011 (C) | non_teaching | 2025-01-01 | Link corrected; case **finalized**; access **CLEAR** although the corrected account still holds a role |
| E12 | EMP-000012 | teaching | 2025-01-01 | Active, unlinked: an independent death record (positive control; not executed) |
| E13 | EMP-000013 | teaching | 2025-06-01 | Line manager's own; monthly accrual |

**Clearance requirements:** `UAT-KEYS` (all staff, HR, mandatory), `UAT-LAB` (teaching only, HR, mandatory).

**Leave:**
- types `UAT-ML` (monthly) and `UAT-AL` (annual);
- accrual month statuses from `LeaveAccrualMonthStatusReader` at setup: April **complete**, August **complete**, September **missed**, October **missed**, November **not_due**.

**Tasks** (`UAT-SELF`, `UAT-REVIEWED`, daily):
- 16 generated for the 8 current classified employments; the unclassified one gets none;
- A's reviewed task: submitted → **returned** → resubmitted, awaiting the reviewer;
- A's self task: completed;
- B's reviewed task: completed by review;
- every `submitted`/`returned` event carries its task version (WO-23).

**Not configured, with reason:**

| Item | Reason |
|---|---|
| A pre-WO-20 legacy kept record | Owner answer 3; covered by `SeparationRetainedAccessConcurrencyTest` |
| A `failed` accrual run | No canonical way to make a run fail; `running`, `incomplete`, `nothing_to_credit` and `unknown` not produced either |
| Reporting lines | None created, so the `team_task_manager` and `line_manager` decision paths have no subordinate |
| A Leave request awaiting a decision | Not created |
| A corrected-link task reviewer | The corrected account holds no reviewer role |
| A revoked HR-Leave permission actor | Not created |
| The same-count / different-set role change, off-boarding, locking October | Left for the tester: these are the actions under test |

## 4. Scenario manifest (RF2 §10.1)

**Status key:** **Ready** = prerequisites exist and the step can be executed now. Nothing below has been executed as acceptance.

| ID | Requirement | Actor | Fixture | Action | Expected | Status |
|---|---|---|---|---|---|---|
| U-05 | WO-5 calendar (C22 already did browser, keyboard and axe; manual screen reader pending) | principal / officer | History start 2026-11-21 | Configure a future week, a holiday, a past-dated correction | As C22; past dates ask reason + code | Ready (screen reader needs a reviewer) |
| U-06 | WO-6 month lock, C18-A12 manual screen reader | principal (lock key, MFA) | October 2026 unlocked in this School; Demo School's locked October for locked views | Lock October; request a locked-month correction | As C20; screen-reader output recorded | Ready (needs an accessibility reviewer and assistive technology) |
| U-09 | WO-9 accrual status | officer | Months as above | Open the accrual workspace | complete / missed / not_due shown, read only | Ready (failed: not configured) |
| U-19a | WO-19 allocation with fresh code | officer | E04 annual (already allocated once); E03/E13 monthly | Opening or adjustment with a code | Accepted with a valid code | Ready |
| U-19b | WO-19 refusals | officer | Same | No code, wrong code, a reused step | Refused, nothing written | Ready |
| U-20a | WO-20 kept roles | admin vs officer | E09 | View the case | Admin sees role names; officer sees a count | Ready |
| U-20b | WO-20 same count, different set | admin (MFA) | E09 | Revoke one kept role, grant another | OPEN; completion refused | Ready |
| U-20c | WO-20 legacy record | — | — | — | — | Not configured (owner answer 3) |
| U-21 | WO-21 unclassified | officer | E06 vs E05 | View both cases | "Unclassified"; `UAT-LAB` named as not evaluated, never cleared | Ready |
| U-22a | WO-22 own task | staff-a | A's reviewed task | Attempt to review | Refused | Ready (staff-a has no reviewer role: shows the capability refusal) |
| U-22b | WO-22 known-same reviewer | staff-a-alt | A's submitted task | Review | `TASK_SAME_PERSON_REVIEW` | Ready |
| U-22c | WO-22 independent review | officer | A's submitted task | Complete or return | Recorded with history | Ready |
| U-22d | WO-22 revoked manager | revoked | — | Open the team view | Refused | Ready |
| U-23 | WO-23 task history | officer | A's task | View the timeline | submitted@v, returned@v, submitted@v | Ready |
| U-24a | WO-24 unlink does not clear | officer | E10 | View the case; complete | OPEN (3); `SEPARATION_OBLIGATIONS_OPEN` | Ready |
| U-24b | WO-24 correction releases | officer | E11 | View; complete | CLEAR; completes once items are cleared | Ready |
| U-24c | WO-24 known-same unlink | staff-a-alt | E03 | Remove A's account link | `HR_KNOWN_SAME_PERSON` | Ready |
| U-24d | WO-24 death record | staff-a-alt; officer | E03; E01 (officer's own); E12 | Record a death | alt: `HR_KNOWN_SAME_PERSON`; own: `HR_SELF_ADMINISTRATION`; E12: allowed | Ready (E12 is destructive: ends that employment) |
| U-24e | Off-boarding resolves | admin (MFA) | E07 or E10 | Suspend the account(s) | CLEAR; completion allowed | Ready |
| U-MFA | MFA-gated HR/Payroll (GOV-11, TRR-10, R-HR16-2/3) | principal, officer | Factors enrolled | Each fresh-code prompt | Prompt, refusal, success | Ready (no Payroll run: Payroll paths not configured) |

**Automated accessibility:** no axe scan was run here; each Ready row's pages still need it. **Manual screen reader:** not performed; it needs a named human reviewer (RF2 §10.3).

## 5. Verification

- **Existing data preserved.** Every row of all 304 public tables was hashed before (4,615 rows in 176 non-empty tables) and after.
  - **0 pre-existing rows changed or removed**, apart from 13 `scheduler_heartbeats` rows rewritten by the DDEV scheduler daemon itself.
  - **0 new rows in any other School.**
  - **544 new rows in the synthetic School**, in 35 tables: HR, Leave, Separation, tasks, memberships, 21 role grants, invitations, email, 165 School audit events and 47 outbox events, all produced by the services.
  - **88 new global rows:** 1 School, 15 Users, 1 activation credential, 4 MFA factors and 40 recovery codes, and 27 platform audit events (setup and the smoke sign-ins).
- **Idempotent.** Rerunning all eight phases created nothing: two full row snapshots were identical apart from the scheduler heartbeats. The one duplicate the reruns found (a task category created by a failed first run before its manifest entry) was fixed by looking every catalogue item up by its stable code.
- **Unchanged:** the October 2026 Staff Attendance lock (md5 `851693a2…`), 256 forced-RLS tables, runtime-role attributes and `school_os_app`'s 934 grants (identical hash before and after).
- **Migrations:** 402/402, 0 pending. `platform:verify-database` **failed=0**; the only operator-evidence row is connection encryption.
- **Email.** 14 invitation messages through the email layer:
  - 12 were cancelled by the layer because each was accepted before submission;
  - 2 were submitted to the local Mailpit (`127.0.0.1:1025`);
  - every recipient is `@example.test`; nothing reached a real address or an external provider.
- **Smoke (read only).** The HR officer signed in over HTTPS with a real TOTP step and loaded, all 200:
  - Employees and the Separations index;
  - three case pages (E09, E10, E06);
  - the task monitor;
  - the Leave accrual workspace and the Leave configuration.

  A staff self-service account got 403 on the Separations and Leave configuration pages. This is not UAT.
- **States read back** through the canonical services (`verify_states.php`): as in §3.

## 6. Cleanup and retention

The School stays for the UAT. Its cleanup is RF2 §10.2's: suspend, then close, through `SchoolLifecycleService` (root, fresh code). Audit history is never deleted, and no database-wide reset is ever used. The demo Platform Admin's TOTP factor stays until the owner resets it through the platform's MFA-reset path. The DDEV snapshot `lycenza-pre-wo21-wo24-402` (taken before the migration) is preserved; UAT0 took no new snapshot.

## 7. Verdict and what remains

**SYNTHETIC HR UAT SCHOOL READY — FIXTURES VERIFIED.** The School, actors, fixtures and scenario manifest are in place; existing data is preserved; School isolation and database verification pass. The "Not configured" rows of §3 and §4 are recorded limits, not hidden gaps.

**Before UAT sign-off (RF2 §10.1, §10.3):**
- the browser walkthrough of each Ready row with screenshots and persisted state;
- automated axe on those pages;
- keyboard paths;
- the manual screen-reader pass (C18-A12 and the WO-5 pages) by a named accessibility reviewer.

All are committed with the UAT record, never left in a scratchpad.

**Before C36 selection:** nothing further from UAT (D-RF2-23: operational evidence is a UAT sign-off prerequisite, not a baseline one). The source, database and local-environment dimensions are eligible for consideration; none is selected.

**Unchanged:**
- RF2's 81 decisions, the 61 audit identifiers and the 361 capability rows;
- WO24-N1..N3 and WO25-N1/N2;
- RG-ID-1 (open), R1-02 (PARTIAL), C33-H1..H5, XRP-3 and the Legal/DPO gates;
- staging PAUSED and production NO-GO.
