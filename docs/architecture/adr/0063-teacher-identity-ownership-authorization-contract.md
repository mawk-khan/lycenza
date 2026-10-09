# ADR 0063: Teacher Identity and Ownership-Based Authorization Contract

- Status: Accepted as a contract (TCH.0, documentation only, closed).
  **TCH is DEVELOPMENT CLOSED (TCH.6 closure audit, 2026-10-01, §38).**
  TCH.1, TCH.2, TCH.3, TCH.5A, TCH.5B, TCH.5C, TCH.5D and TCH.6 are closed;
  TCH.4 is development-closed (§29–§37). **Teacher Attendance functionality
  is implemented but production enablement remains blocked by TCH-L1 until
  the required legal/compliance determination is recorded** (§26). E21
  (retention) stays open. TCH.6 found and fixed one closure defect
  (non-identical not-found bodies, §38.3). **Production readiness (§39):
  PRODUCTION READY EXCEPT DOCUMENTED EXTERNAL GATES** — TCH-L1 (ADR 0058
  E33) and E21. **Owner decision (§40): no production `teacher` role grants
  while TCH-L1 / E33 is OPEN.** **LMS Submission remains cancelled and outside TCH.**
  **Amended 2026-10-07:** E33 APPROVED WITH CONDITIONS (§42); controls built
  (§43) and verified (§44), §40 superseded in part by §42; TCH-E elective
  ownership (§45); RES.4 teacher marks as a development-only consumer
  (§46). **Amended 2026-10-08 (S7, §47):** ending an employment ends the
  teaching ownership it granted, required and elective, in the same
  transaction; a rehire never revives it.
- Date: 2026-09-30
- Programme: **TCH — Teacher Identity & Ownership-Based Authorization**
  (`docs/roadmap/MASTER-ROADMAP.md`, "Post-foundation product programmes").
- Baseline: `origin/main` `1a4dc34` (the formal FEE development-closure
  baseline; regression checkpoint `1a4dc34`).
- Reopens, under ADR 0061 §2.5: the ADR 0061 §2.3 deferred item "teacher
  identity on a delivery, and ownership-based authorization" — **and only
  that item** (§1).
- Builds on, and does not rewrite:
  - ADR 0059 (User versus Employee; the closed School role catalog);
  - ADR 0039 decision 6 (LMS capability-only v1);
  - ADR 0037 (Staff MFA) and ADR 0038 (Student processing authorization);
  - `docs/security/AUTHORIZATION.md` (capability-based authorization).
- Related: CLAUDE.md rules 17–19, 21, 22, 24, 60, 70, 86.

Evidence convention: paths under `app/`, `database/` and `routes/` are
relative to `apps/platform/`. **[FACT]** is as built at `1a4dc34`;
**[DOC]** is a documented contract; everything else in §4 onward is this
ADR's contract.

## 1. Reopening and scope

The project owner authorized reopening the ADR 0061 §2.3 item "teacher
identity and ownership-based authorization" (read-only audit 2026-09-30,
then this docs-only contract). ADR 0061 carries a dated trace pointing
here.

**Still deferred or separately scoped — not reopened by TCH.** They may be
named only as downstream consumers or other programmes:
- Lesson Planning;
- StudentMark / marks entry and the RES programme;
- results, report cards and transcripts;
- POR — Student and Guardian portals;
- HRX — leave, staff attendance and Employee self-service;
- generic staff-role expansion (accountant, HR, librarian, reception,
  transport, admissions, a broad academic coordinator, …);
- tenant-custom roles;
- LMS Submission (cancelled, ADR 0039);
- every other ADR 0061 deferral.

## 2. As-built audit (2026-09-30)

### 2.1 Identity: User, membership, Employee, employment

- **User** is a global identity with no `school_id` and no RLS; disabled
  is `users.is_disabled` [FACT
  `database/migrations/0001_01_01_000000_create_users_table.php:27-28`;
  `app/Models/User.php:125-128`]. One User may belong to many Schools
  (`unique(user_id, school_id)`) [FACT
  `database/migrations/2026_08_22_090700_create_school_memberships_table.php:37`].
- **SchoolMembership** status is CHECK-constrained to
  `invited|active|suspended` [FACT
  `database/migrations/2026_10_28_090100_constrain_school_membership_status.php:27`];
  "active" means only `status = 'active'` [FACT
  `app/Models/SchoolMembership.php:64-67,80-83`]. Every School request
  re-checks, uncached: School active, User not disabled, active
  membership [FACT `app/Http/Middleware/RequireSchoolContext.php:94-108`].
- **Employee** links to a User through `employees.user_id`: nullable,
  RESTRICT, `unique(school_id, user_id)` [FACT
  `database/migrations/2026_08_23_100100_create_employees_table.php:47,54`].
  A User may be an Employee at several Schools, never twice in one.
  `record_status` (`active|archived`, no CHECK) is "this Employee row's own
  existence state, NOT employment/account status" [FACT
  `app/Domain/HR/Infrastructure/Employee.php:45-47`].
- **The current link check does not prove eligibility.**
  `EmployeeService::assertUserLinkageIsSafe()` checks only that a
  `school_memberships` row exists for `(user_id, school_id)` [FACT
  `app/Domain/HR/Application/EmployeeService.php:200-214`]. It does **not**
  prove that:
  - the membership is `active` (an `invited` or `suspended` row passes);
  - the User is enabled;
  - the Employee is active;
  - a current eligible employment exists.

  It runs **at link time only, by design**: "membership status changing
  later must not retroactively invalidate an already-established linkage"
  [FACT `app/Domain/HR/Application/Exceptions/UnrelatedUserLinkageException.php:12-15`].
  In `update()` the check runs before, and outside, the write transaction,
  and the audit records only the changed field names — no dedicated link
  or unlink event and no old/new `user_id` [FACT
  `EmployeeService.php:103-136`].
- **EmploymentRecord** has `starts_on` and a nullable `ends_on`; `status`
  (`draft|pre_joining|active|notice_period|separated|terminated|retired|deceased`)
  is a plain string with **no CHECK**, and overlap is prevented only by an
  application check under the Employee row lock [FACT
  `database/migrations/2026_08_23_100700_create_employment_records_table.php:42-68`].
  `status` is not validated on create; the only "current employment" logic
  is a read model that uses dates and ignores `status` [FACT
  `app/Domain/HR/Application/EmployeeDirectoryService.php:150-162`].
- **No code resolves "the acting User's Employee."** Authorization today is
  User + active membership + role grants [FACT
  `app/Support/Authorization/CapabilityResolver.php:71-105`]. Nothing infers
  an Employee from an email.

**Conclusion.** The repository does **not** yet support `User → active
membership → Employee → eligible employment` for authorization. TCH.1 must
build it (§4–§6).

### 2.2 Teaching identity on resources

| Resource field | Meaning today | Ownership authority? |
|---|---|---|
| `timetable_entries.teacher_id` → `employees` | A standing **weekly** slot, rewritten in place by `TimetableScheduleService::update()` with no history row and no dates [FACT `app/Domain/Timetable/Application/TimetableScheduleService.php:185-195`] | **No** (§8) |
| `attendance_sessions.teacher_id` | Snapshot, at submission, of who was scheduled [FACT `app/Domain/Attendance/Application/AttendanceSubmissionService.php:229`] | **No** — provenance |
| `attendance_sessions.submitted_by_user_id` | Who submitted | **No** — audit author |
| `curriculum_deliveries` | Deliberately no teacher, Employee or User column [DOC `docs/modules/ACADEMICS.md:620-623`] | n/a |
| `learning_content`, `assignments` | No author or owner column; `subject_offering_id` only | n/a |
| Examinations, papers, grade scales, syllabus units | No person column | n/a |

- **No class-teacher, homeroom or teaching-assignment concept exists**
  anywhere. `subject_offerings` is AcademicYear × Campus × GradeLevel ×
  Subject and is "Deliberately NOT attached to Section" [FACT
  `database/migrations/2026_08_23_091000_create_subject_offerings_table.php:16-19`].
- `sections_context_unique` and `subject_offerings_context_unique` — both
  `(id, school_id, academic_year_id, campus_id, grade_level_id)` — already
  exist for 5-column composite foreign keys [FACT
  `database/migrations/2026_09_12_090100_*:21-24`,
  `database/migrations/2026_09_12_090000_*:26-29`].
- ADR 0039's premise still holds: no teacher→Offering ownership record
  exists [DOC `docs/architecture/adr/0039-lms-domain-contract.md:327-357`].

### 2.3 Roles and capabilities

- **Production School roles are exactly `school_admin` and `principal`**
  [FACT `database/seeders/CapabilityAndRoleSeeder.php:859,1127`]. The
  platform side has `platform_super_admin`, `platform_auditor` and the
  Group-scope `group_admin`. No path creates roles outside the demo
  seeders.
- Demo-only roles (`demo.finance_officer`, `demo.librarian`, …) are created
  only behind `DemoEnvironmentGuard`. The demo teacher is a member **with no
  role**, linked to an Employee: "no teacher portal exists; modules are
  403" [FACT `database/seeders/Demo/DemoDataBuilder.php:663-671`].
- Every academic capability is held by `school_admin` and `principal` only,
  and the seeder records each teacher gap in code comments, e.g. "no
  `attendance.teacher` — v1 is admin-only" [FACT
  `CapabilityAndRoleSeeder.php:662-664`]. The same holds for Curriculum
  Delivery (696-698), Examinations (719-721, 734-736, 750-752) and LMS
  (769-774, 784-786).
- Capabilities reach a User **only** through role assignments [FACT
  `CapabilityResolver.php:81-105`]. Without a system Teacher role, a teacher
  could be given access only through `principal` or `school_admin`.

### 2.4 Relationship precedent

`ConversationParticipantAuthorizationService` composes capability, School
policy, target existence and an active account link **with AND**, and states
that a link proves reachability, "never, by itself, authorization" [FACT
`app/Domain/Communications/Application/ConversationParticipantAuthorizationService.php:62-82`].

TCH reuses:
- the AND composition in a fixed, fail-closed order;
- status-based history rather than deletion;
- fresh queries (the precedent caches nothing).

TCH does **not** copy its check-once-at-creation timing: ownership is
re-checked on every protected operation (§15).

### 2.5 MFA, processing authorization, classification

- The `mfa` middleware is on no academic route today [FACT
  `routes/web.php:289,553-565` are its only uses]. ADR 0037 defers
  "teacher/class-scoped enforcement" [DOC `adr/0037…:265-267`].
- The ADR 0038 registry has one purpose, `academic_records`, for the unbuilt
  StudentMark [FACT
  `app/Domain/Students/Domain/ProcessingAuthorizationPurpose.php:18`]. It
  gates nothing that TCH will adopt.
- Timetable and Attendance are **Sensitive**; Curriculum Delivery is
  **Confidential** [DOC `docs/security/DATA-CLASSIFICATION.md:45-48`].

## 3. Decision summary

| # | Decision | Disposition |
|---|---|---|
| T1 | Production Teacher role | **APPROVED** — one minimum system role (§12) |
| T2 | Non-teaching staff roles | **APPROVED — outside TCH** (§14) |
| T3 | Tenant-custom roles | **EXISTING DECISION** — remain future (§14) |
| T4 | Generic Employee identity vs teacher ownership | **APPROVED** — boundary in §14 |
| D-01 | Ownership fact | **APPROVED** — dated Employee × Section × SubjectOffering/context assignment (§7) |
| D-02 | Class-teacher / homeroom | **APPROVED — DEFERRED** (§7) |
| D-03 | TimetableEntry authority | **APPROVED** — not ownership authority (§8) |
| D-04 | Lifecycle | **APPROVED** — dated, immutable identity, end rather than delete (§9) |
| D-05 | Electives | **APPROVED — excluded initially** (§7) |
| D-06 | First adopter | **APPROVED** — Curriculum Delivery (§16) |
| D-07 | Owned-scope capability family | **APPROVED IN PRINCIPLE** — `.teacher` naming allowed, with non-role semantics (§13) |
| D-08 | Eligible employment | **APPROVED** (§5) |
| D-09 | Link governance | **APPROVED** — explicit, locked, audited link and unlink (§6) |
| D-10 | ActingEmployee home | **APPROVED** — HR Application layer (§4) |
| D-11 | Assignment administration | **APPROVED** — dedicated capabilities (§15) |
| D-12 | MFA | **CLARIFIED** — no new universal MFA; RES stays outside TCH (§17) |
| D-13 | Denial semantics | **APPROVED** — non-disclosing (§18) |
| D-14 | LMS ownership semantics | **DEFERRED** to the LMS adoption checkpoint (§16). **RESOLVED BY TCH.5A (2026-09-30, §34):** teacher-authored Learning Content and Assignments use an immutable owner Employee, an immutable one-or-more Section audience, current TeachingAssignment eligibility, owner-only writes and audience-based published reads. Legacy/admin rows remain Offering-wide with no Employee owner. Offering-only teacher write authority is rejected. |
| D-15 | Substitutes | **APPROVED** — temporary cover is a short dated assignment (§9) |
| D-16 | Timetable ↔ assignment consistency | **APPROVED** — no hard coupling in v1 (§8) |
| D-17 | Co-teaching | **APPROVED** — allowed (§9) |

**Audit correction (adopted).** The audit's proposed partial unique
"open-assignment" index (`… WHERE ended_at IS NULL`) is **not** adopted. It
conflicts with pre-creating a future-dated replacement while the current
assignment is still valid. TCH.2 enforces non-overlap under serialization
instead (§10).

## 4. The verified identity boundary: ActingEmployee (TCH.1)

The reusable authorization prerequisite is:

```text
User
→ active SchoolMembership for the target School
→ linked Employee of that School
→ active Employee record
→ eligible current EmploymentRecord
```

- **One canonical resolver** (name finalized in TCH.1, e.g.
  `ActingEmployeeResolver`): `(User, School, asOf) → Employee + current
  eligible EmploymentRecord`.
  - It lives in the **HR Application layer (D-10)**. Employee and
    EmploymentRecord are HR facts, HR already owns link validation, and the
    teaching modules already depend on HR (DOMAIN-MAP).
- **It fails closed** when any of these holds:
  - the User is disabled;
  - the School is unavailable or not operational;
  - the membership is not `active`;
  - no Employee exists for `(school_id, user_id)`;
  - `Employee.record_status` is not `active`;
  - no eligible current EmploymentRecord exists (§5).
- **It is resolved server-side only.** It is never inferred from an email,
  work email, personal email, employee number, name, request-supplied
  `employee_id` or `school_id`, a role name, or UI state (rule 19).
- **It identifies; it authorizes nothing.** A resolved ActingEmployee grants
  no access to any resource by itself.
- **Checked at use time.** Every state-changing consumer re-checks the
  identity facts inside its authoritative transaction (§20). Link-time
  validation (§6) is necessary but never sufficient.

## 5. Employment eligibility (D-08)

An EmploymentRecord is authorization-eligible at the School-local operation
date when:

```text
starts_on <= operation_date
AND (ends_on IS NULL OR ends_on >= operation_date)
AND status IN ('active', 'notice_period')
```

`draft`, `pre_joining`, `separated`, `terminated`, `retired` and `deceased`
are not eligible.

- TCH.1 validates `employment_records.status` against the closed list on
  every write.
- TCH.1 may add CHECK constraints for `employment_records.status` and
  `employees.record_status`, but only after verifying the existing rows.
  The migration **refuses and reports** invalid legacy values; it never
  rewrites them.
- This hardens existing HR facts that become authorization inputs. It is
  not HRX scope.

## 6. Employee↔User link governance (D-09)

- **Explicit operations.** Link and unlink become explicit Application
  operations, still under `hr.employees.manage`. The generic Employee update
  stops being an authority-bearing path for silently changing `user_id`.
- **Linking requires** an enabled User and an **active** membership in the
  same School.
- **Checked inside the write transaction.** The membership row is locked
  `FOR SHARE` and the Employee row `FOR UPDATE`, following existing locking
  conventions.
- **Audited separately.** Dedicated link and unlink audit events record the
  old and new identity ids — never profile data.
- **Unlinking deletes nothing.** Historical HR facts stay.
- **No Employee schema redesign**, unless the TCH.1 implementation audit
  proves one necessary. `unique(school_id, user_id)` already exists.

## 7. Authoritative teaching ownership (D-01, D-02, D-05)

TCH.2 introduces one dedicated, School-owned fact:

```text
TeachingAssignment
  Employee
  × Section
  × required SubjectOffering
  × AcademicYear / context (Campus, GradeLevel)
  × effective date range
```

It means: *this eligible Employee is authorized as a teacher for this
Section + SubjectOffering teaching context during this period.*

- It is independent of role membership. Holding a role never creates or
  implies an assignment.
- **Class-teacher / homeroom authority is DEFERRED (D-02).** None of the
  first planned adopters needs Section-wide authority independent of an
  offering. A later checkpoint may reopen it for a concrete surface. It is
  never faked through TimetableEntry or an Employee's position or title.
- **Electives are EXCLUDED initially (D-05).** No Section-independent
  teaching cohort exists (electives are Student-level enrollments, not
  Section-wide slots) [DOC `docs/modules/TIMETABLE.md:96-100`]. The model is
  not weakened to fit them; they are downstream scope.

## 8. TimetableEntry is not authorization authority (D-03, D-16)

`TimetableEntry` is scheduling evidence, not the ownership root:
- it is a standing weekly occurrence with no dated lifecycle;
- it is rewritten in place (§2.2);
- temporary differences and substitutions are explicitly out of its scope
  [DOC `docs/modules/TIMETABLE.md:32-40`];
- Attendance already snapshots it as provenance.

**`timetable_entries.teacher_id` never grants teacher access by itself.**

In v1 there is no hard consistency coupling between the timetable and
TeachingAssignments (D-16). An optional mismatch report may come later.

## 9. Lifecycle, co-teaching and temporary cover (D-04, D-15, D-17)

- **Dates.**
  - Assignments may be future-dated.
  - A future assignment grants nothing before `starts_on`.
  - An ended assignment grants nothing after `ends_on`.
- **Ended, never deleted.** Runtime DELETE is revoked.
- **Immutable identity.** The Employee, Section, SubjectOffering, context
  and `starts_on` of a row never change. Correcting or replacing authority
  creates a new row; an old row is never re-pointed.
- **Co-teaching is allowed.** Several Employees may hold valid ownership of
  the same Section + SubjectOffering context at once.
- **Temporary cover** is a short dated TeachingAssignment. There is no
  separate substitute entity in v1.

## 10. Overlap and uniqueness contract (corrected)

There is **no** partial unique "one open row" index (`… WHERE ended_at IS
NULL`): it would block a future-dated replacement. Instead TCH.2 must
provide:

1. **Serialization.** Writes to the same assignment key (School, Employee,
   Section, SubjectOffering) serialize deterministically — a
   transaction-scoped PostgreSQL advisory lock on that key (the repository's
   existing pattern, e.g. `fees.settings:{school}`), never a Redis lock for
   correctness.
2. **Overlap validation** under that serialization.
3. **Rejection** of an effective period that overlaps an existing live row
   for the same Employee + Section + SubjectOffering context.
4. **Optionally**, exact-duplicate uniqueness where useful.

No EXCLUDE constraint is introduced for novelty. The repository uses none,
and HR's employment-overlap rule uses the same lock-then-check pattern.
TCH.2 finalizes the exact constraint shape.

## 11. Two capability tiers and the authorization formula

**Tier 1 — existing School-wide (administrative) capabilities.**
`attendance.manage`, `curriculum.delivery.manage`, `lms.content.manage`,
etc. keep their current meaning and grants. TCH does **not** add ownership
to School Admin or Principal behaviour; that would be a breaking change.

```text
administrative School-wide resource access
    = active actor + required School-wide capability
```

**Tier 2 — owned-scope teacher capabilities.** New and narrow. The
capability alone is never sufficient:

```text
owned teacher resource access
    = authenticated User
      AND trusted School context
      AND active SchoolMembership
      AND verified ActingEmployee (linked, active, eligible employment)
      AND required owned-scope capability
      AND authoritative TeachingAssignment ownership on the operation date
      AND same-School / resource-context match
      AND any applicable surface-specific gate
```

Every part is required; any missing part fails closed.

## 12. Production Teacher role (T1)

A later checkpoint (TCH.3) adds one minimum production system School role,
`teacher` (key finalized there).

- It is only a convenience bundle of narrow owned-scope capabilities.
- **It is never an enforcement condition.**
  - `if role == teacher` is forbidden (rule 24).
  - "Teacher role ⇒ all academic resources" is forbidden.
  - A Teacher without qualifying ownership reaches no owned resource.
- **Nothing broad comes with it.** It grants no Student administration, HR,
  payroll, finance, settings, safeguarding, role-governance,
  processing-authorization, or other unrelated capability — and no Tier 1
  `*.manage`.
- **Grantable like any other School role.** It is granted through the
  existing staff role path (ADR 0059; `StaffRoleCatalog` no-escalation, fresh
  MFA). Tenant-custom roles are not required.

## 13. Owned-scope capability naming (D-07)

- The repository already anticipates names such as `attendance.teacher`,
  `curriculum.delivery.teacher` and `lms.content.teacher` (§2.3), and this
  naming may continue.
- **`.teacher` is part of a capability key, not a runtime role check.**
  Authorization still uses the ordinary `CapabilityResolver`, plus
  ActingEmployee, plus TeachingAssignment ownership.
- If another system role were ever explicitly granted the same capability
  and satisfied the same ownership contract, the logic must not branch on
  the role label.
- The capability set grows **one adopter at a time**, never as a broad
  Teacher bundle up front.

## 14. Scope guard (T2, T3, T4)

- **T2 — other staff roles: outside TCH.** Accountant, HR Staff, Librarian,
  Receptionist, Transport Staff, Admissions Staff, a broad Academic
  Coordinator and other non-teaching personas are excluded.
  - The production least-privilege gap is recorded as a finding for a
    future, separate staff-authorization programme. For example, FEE.3
    maker/checker needs two School Admin actors in production (§2.3).
  - *(Amended by ADR 0071 / SR.0, 2026-10-09: that programme is SR. It
    contracts thirteen fixed non-teaching School system roles with
    class-scoped grant rights. T3 (tenant-custom roles) and D-02 are
    unchanged, and no operational role carries a `*.teacher` key.)*
- **T3 — tenant-custom roles: future (existing decision).** TCH creates no
  School-owned roles, no role designer and no arbitrary bundles, and it
  keeps the global `roles` ownership model.
- **T4 — the boundary:**

| Primitive | Owner | Answers | Authorizes by itself? |
|---|---|---|---|
| ActingEmployee | TCH.1, HR Application layer | "Who is this User as an eligible Employee in this School?" | **No** |
| Teaching ownership | TCH.2 onward | "Does this eligible Employee hold the teaching capability AND own this teaching context?" | Only as the full §11 conjunction |
| Own leave, own payslip, direct-report approval, manager and self-service relationships | HRX (future) | HR self-service semantics | Not TCH. HRX may reuse ActingEmployee |

## 15. Administering TeachingAssignments (D-11)

- New capabilities `teaching.assignments.view` and
  `teaching.assignments.manage` (namespace finalized in TCH.2 if a naming
  review finds a reserved prefix). Default grants: `school_admin` and
  `principal`.
- `timetable.schedule.manage` is **not** reused: scheduling and durable
  teaching authority are separate responsibilities.
- Creating an assignment validates, under lock (§20), an active Employee
  and an employment record covering `starts_on` (exact rule in TCH.2).
  Use-time eligibility (§4) stays authoritative.

## 16. Adoption order (D-06, D-14)

1. **Curriculum Delivery — first adopter (TCH.3).**
   - Confidential data with no Student payload.
   - A natural Section × SubjectOffering key.
   - An existing compare-and-swap write model.
   - It proves the whole identity + capability + ownership chain at the
     lowest disclosure risk.
2. **Attendance (TCH.4).** The high-value teacher workflow, on Sensitive
   Student data.
   - Ownership is the TeachingAssignment covering the session's Section +
     SubjectOffering on its date.
   - `timetable_entries.teacher_id` and `attendance_sessions.teacher_id`
     stay schedule and provenance facts.
3. **LMS content and assignments (TCH.5)**, only after its own ownership
   decision (D-14, **DEFERRED**; resolved by TCH.5A, §34). LMS rows carry an offering but no Section
   or author, so co-teacher editing needs its own rule (ADR 0039 decision
   6). The owner may remove TCH.5 from the programme.
4. **RES** only when separately reopened; it is not part of TCH.

## 17. MFA, classification, processing authorization (D-12)

- **MFA.**
  - No new universal MFA requirement for the first teaching surfaces.
  - Existing Curriculum Delivery and Attendance security requirements stay
    in force; TCH lowers none.
  - TCH does not decide marks-entry MFA: RES is not reopened.
  - ADR 0037 and ADR 0038 stay authoritative for their own scope and are
    re-evaluated when RES reopens.
- **Classification.**
  - TeachingAssignment names a person against a class, so it is expected to
    be **Sensitive** (the Timetable precedent); its classification row lands
    with TCH.2.
  - Curriculum Delivery stays Confidential if no teacher column is stored
    (the actor is recorded in audit only). Storing one triggers the
    documented re-tier [DOC `docs/modules/ACADEMICS.md:620-623`].
- **Processing authorization (ADR 0038)** does not apply to the TCH
  adopters.

## 18. Denial semantics (D-13)

For owned-scope teacher APIs:
- **Lists** return only what the teacher is authorized to see.
- **Direct lookup** of a valid but unowned teaching resource answers with
  non-disclosing not-found, consistent with the repository's existing
  negative-disclosure behaviour.
- **Stale lifecycle or expected-state conflicts** use the existing 409
  conventions.

School Admin and Principal administrative visibility (Tier 1) is unchanged.

## 19. Tenancy and schema direction (TCH.2)

`teaching_assignments` is School-owned and requires:
- `BelongsToSchool` (rule 17);
- `TenantRls::enable` plus `revokeDelete` (rule 18);
- no client-trusted `school_id` (rule 19);
- a composite `(employee_id, school_id)` foreign key to `employees`;
- 5-column composite foreign keys to `sections_context_unique` and
  `subject_offerings_context_unique`, so the Section and the offering share
  one AcademicYear/Campus/GradeLevel at database level (rule 70);
- RESTRICT where historical ownership must survive parent changes.

Expected conceptual columns (not a finalized migration):

```text
id, school_id, employee_id,
academic_year_id, campus_id, grade_level_id   -- as the composite keys require
section_id, subject_offering_id,
starts_on, ends_on (nullable),
created_by_user_id, ended_at (nullable), ended_by_user_id (nullable),
end_reason (nullable), timestamps
```

A trigger freezes the identifying columns (§9). The `down()` order is
trigger → function → RLS → table. TCH.2 finalizes the exact columns and
constraints, **without** the rejected open-row unique index (§10).

## 20. Concurrency contract

TCH.1 and TCH.2 must close these races:

| Race | Required protection |
|---|---|
| Employee link changed or unlinked while authorization executes | The consumer reads the Employee `FOR SHARE` in its transaction; link and unlink take it `FOR UPDATE` |
| Membership suspended during a teacher write | The consumer reads the membership `FOR SHARE`, uncached; suspension already takes it `FOR UPDATE` (`StaffAccessService`) |
| Employment ends while a TeachingAssignment is created | Creation reads the EmploymentRecord `FOR SHARE`; `EmploymentService::end` locks it `FOR UPDATE` (and, since S7 §47, then ends the Employee's assignment rows `FOR UPDATE` in the same transaction) |
| A TeachingAssignment ends during an owned-resource write | The consumer reads the assignment `FOR SHARE`; ending takes it `FOR UPDATE` |
| Two overlapping assignments created concurrently | Advisory lock on the assignment key + overlap check (§10) |

- **Documented lock order:** School `FOR SHARE` (rule 86) → membership →
  Employee → EmploymentRecord → TeachingAssignment → the resource's own
  locks. TCH.1 and TCH.2 confirm it against the adopting services' existing
  orders.
- A state-changing teacher operation validates eligibility and ownership
  **inside its authoritative transaction**, so a revocation cannot race past
  the write.

## 21. Caching

- `CapabilityResolver` stays the canonical capability resolver. Its
  tenant-aware cache keys follow rule 22.
- ActingEmployee and TeachingAssignment decisions use **fresh** relationship
  resolution in the initial implementation — no relationship cache.
- Membership and ownership revocations take effect for state-changing
  teacher operations without waiting for any TTL, because those operations
  re-read under lock (§20).
- **Recorded discrepancy.** School capability sets are cached for 60 s with
  membership status read *inside* the cached value [FACT
  `CapabilityResolver.php:40,77-89`].
  - HTTP requests re-check membership uncached (§2.1), and
    `StaffAccessService` calls `forgetCache` on suspension.
  - A non-HTTP caller relying on the resolver alone may see a suspended
    membership for up to 60 s.
  - `AUTHORIZATION.md` is corrected to say so. The cache substrate is not
    redesigned here.

## 22. Audit and events

- **TCH.1:** dedicated Employee link and unlink audit events (ids only;
  old and new values).
- **TCH.2:** `teaching_assignment.created` and `teaching_assignment.ended`
  (ids and dates only).
- **TCH.3:** the Teacher role grant reuses the existing role-grant audit
  (`school.membership.role_assigned`).
- **No outbox events.** Ownership is read live and has no consumer; any
  event later needs its own reviewed purpose (rules 45 and 77).

## 23. API, OpenAPI and UI

- **TeachingAssignment administration** (TCH.2): list, create and end under
  `/api/v1/schools/{school}/teaching-assignments`, with no DELETE. The UI
  selects Section, SubjectOffering and AcademicYear from trusted School
  context; cross-School ids fail at application and database level.
- **Linking** (TCH.1): an explicit link/unlink surface that shows linkage
  safely (identity ids and display names, never credentials). It replaces
  `user_id` on the generic update.
- **Contracts.** Each checkpoint updates `packages/contracts` OpenAPI and
  the generated shared types for the operations it adds or changes.

## 24. Test obligations

- **Identity:**
  - disabled User; missing, `invited` or `suspended` membership;
  - no Employee link; archived Employee;
  - no current, future-only or ended employment; an ineligible status;
  - a User linked at two Schools; cross-School resolution;
  - no email, name or employee-number inference.
- **Capability and role:**
  - a Teacher role without the capability is denied;
  - a capability without ActingEmployee is denied;
  - a capability without ownership is denied;
  - ownership without the capability is denied;
  - the role label alone never authorizes;
  - Tier 1 behaviour is unchanged.
- **Ownership:**
  - the matching Section + SubjectOffering is allowed;
  - the wrong Section or wrong offering is denied;
  - a future assignment is denied before its start;
  - an ended assignment is denied;
  - co-teachers are supported;
  - temporary cover obeys its dates.
- **Tenancy:** application cross-School denial, raw RLS denial, and the
  composite foreign key refusing cross-School references.
- **Races:**
  - unlink vs protected write;
  - suspension vs protected write;
  - employment end vs assignment creation;
  - assignment end vs protected write;
  - concurrent overlapping creation.

  All use real separate processes with verified overlap.
- **Cache and revocation:** capability revocation, membership suspension
  and ownership end each leave no stale owned-resource access after the
  authoritative change.

## 25. Checkpoint plan

| Checkpoint | Scope |
|---|---|
| **TCH.0** | Architecture audit and this contract (docs only) |
| **TCH.1** | Verified ActingEmployee identity boundary: resolver, hardened link/unlink, employment-status validation and CHECKs |
| **TCH.2** | Authoritative TeachingAssignment foundation: table, service, administration capability, API/UI, no consumer |
| **TCH.3** | Production Teacher role + Curriculum Delivery adoption |
| **TCH.4** | Attendance teacher adoption |
| **TCH.5** | LMS teaching adoption, only after the LMS ownership decision (D-14); removable by the owner. Split by TCH.5A (§34.13): **TCH.5A** ownership contract (docs, closed) → **TCH.5B** ownership & audience persistence foundation → **TCH.5C** Learning Content adoption → **TCH.5D** Assignment adoption |
| **TCH.6** | TCH closure audit |

No TCH checkpoint includes generic staff-role expansion, tenant-custom
roles, HRX, RES, POR, Lesson Planning or LMS Submission.

## 26. Legal register

| ID | Question | Surface | Blocks development? | Blocks production? |
|---|---|---|---|---|
| TCH-L1 | Does expanding access to identifiable Student attendance from the current administrative actors to assigned teachers require an updated children's-data/privacy assessment, processing record or equivalent production approval? A **required production legal/compliance determination**; no statutory conclusion is drawn here. | Attendance adoption (TCH.4) | **No** — not TCH.0, TCH.1, TCH.2 or Curriculum Delivery adoption | Yes, for the Attendance teacher surface, until determined |
| E21 | Retention for link history and TeachingAssignment history | TCH.1, TCH.2 | No | Yes (existing ADR 0058 E21) |

No other item here is legal: roles, ownership shape, MFA and denial
semantics are product and architecture choices.

**TCH-L1 status: OPEN** (TCH.4, §32; carried into the ADR 0058 §6
register as **E33** on 2026-10-01, §39). Development blocker: **no**.
Production blocker: **yes**, for the teacher Attendance surface
(`attendance.teacher`). No determination has been recorded, and none is
implied by the TCH.4 implementation or its tests.

**Update (7 October 2026, §42):** TCH-L1 is **DETERMINED — APPROVED WITH
CONDITIONS** (`docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`).
Development: permitted within scope. Production: only after the
determination's controls are verified (§42.4). The text above is kept as the
historical state.

## 27. Findings recorded for other programmes (not TCH scope)

- **Production least privilege.** Only two School roles exist, so every
  non-teaching staff member is over-privileged or has no access, and FEE.3
  maker/checker needs two School Admins. → a future staff-authorization
  programme.
- **LMS.md:135-136** says an Employee is referenced "for authorship"; no
  such reference is stored. → LMS adoption checkpoint.
- **HR.md:2411-2413** says there is no `record_status` write path;
  `EmployeeService::archive()`/`restore()` exist. **ADR 0059:58-60** says
  membership status has no CHECK; one now exists. → HR and Identity
  documentation owners.
- **Link-check messages** ("no active membership") overstate the check (§2.1).
  → fixed by TCH.1.

## 28. Consequences

- Teachers still cannot use the system until TCH.3. This ADR changes no
  behaviour.
- Once built, teacher authority is least-privilege by construction:
  capability AND verified identity AND dated ownership, with no role-name
  branch.
- HR's Employee link becomes authority-bearing, which is why TCH.1 hardens
  it first.
- Existing administrative access is unchanged at every step.

## 29. TCH.1 implementation (as built)

TCH.1 builds §4–§6 and the TCH.1 rows of §20–§22. It adds **no** teacher
access: no TeachingAssignment, no Teacher role, no `*.teacher` capability,
and no teaching module changes. Decisions D-01 to D-17 are unchanged.

**Resolver (§4, D-10).** `App\Domain\HR\Application\ActingEmployeeResolver`
is the one path from `(User, School, asOf)` to an
`App\Domain\HR\Application\ActingEmployee` (ids only: School, User,
Employee, EmploymentRecord, `asOf`; never persisted).
- `resolve()` reads fresh, without locks. `hold()` must run inside the
  caller's transaction and reads every row of the chain `FOR SHARE`, so it
  is the primitive a future state-changing consumer calls inside its
  authoritative transaction.
- Chain, in lock order: School operational (`SchoolOperationalGuard`) →
  membership `status = 'active'` → User re-read and not disabled →
  Employee by `employees(school_id, user_id)` → `record_status = 'active'`
  → exactly one eligible EmploymentRecord. Each failure is
  `ActingEmployeeUnavailableException` (403, `HR_ACTING_EMPLOYEE_UNAVAILABLE`,
  one message) carrying an internal reason: `school_not_operational`,
  `membership_not_active`, `user_unavailable`, `not_linked`,
  `employee_not_active`, `no_eligible_employment`, `ambiguous_employment`.
- `asOf` is a `Y-m-d` School-local date; the default is today in
  `SchoolTimezone::resolve()`.
- Nothing is cached. `CapabilityResolver` is unchanged and has no HR
  dependency (architecture guard).

**Eligibility (§5, D-08).** `starts_on <= asOf AND (ends_on IS NULL OR
ends_on >= asOf) AND status IN ('active', 'notice_period')`, both ends
inclusive. Two eligible rows (possible only in corrupt or legacy data) fail
closed as `ambiguous_employment`. `EmploymentService::create()` now validates
`status` against the closed catalogue (`HR_INVALID_EMPLOYMENT_STATUS`), and the
API rejects any other value.

**Status constraints (§5).** Migration
`2026_11_03_090000_constrain_hr_identity_statuses` adds
`employees_record_status_check` (`active`, `archived`) and
`employment_records_status_check` (the eight documented statuses). It never
rewrites a row. PostgreSQL validates a new CHECK against every row regardless
of forced RLS, so one out-of-catalogue legacy row makes the migration refuse
(`<constraint>: refusing ... No row was changed`). `down()` drops only the two
constraints. Rollback and re-apply were verified byte-identical with
`pg_dump --schema-only`.

**Link governance (§6, D-09).**
- `EmployeeService::linkUser()` and `unlinkUser()` are the explicit
  operations, under `hr.employees.manage`. `create()`'s optional `user_id`
  goes through the same private link primitive in the creation transaction.
- `update()` refuses `user_id` (`HR_USER_LINK_NOT_EDITABLE`); the API PATCH
  marks it `prohibited`. The web forms never carried it.
- Changing a link is unlink, then link. Linking an already-linked Employee is
  `HR_EMPLOYEE_ALREADY_LINKED`, and linking an archived Employee is
  `HR_EMPLOYEE_NOT_ACTIVE`. Unlinking an archived Employee is allowed, and
  unlinking deletes nothing else.
- Link validation runs inside the transaction: membership `FOR SHARE` (must
  be `active`), then User `FOR SHARE` (must be enabled), then Employee
  `FOR UPDATE`. Every User-side refusal is one non-enumerating
  `HR_UNRELATED_USER_LINKAGE` answer.
- `employees_school_id_user_id_unique` remains the only guarantee of one User
  per Employee per School, surfaced as `HR_USER_ALREADY_LINKED`. A User may
  still be an Employee at several Schools.
- Audit (§22): `employee.user_linked` and `employee.user_unlinked`, written in
  the same transaction, with metadata `employeeId`, `previousUserId` and
  `newUserId` only. `EmployeeUpdated` (fields `['user_id']`) is still emitted
  for linkUser/unlinkUser, as the old update path did. No new domain event.
- API (§23): `POST /api/v1/schools/{school}/employees/{employee}/link-user`
  (body `user_id`) and `.../unlink-user`, both `idempotent`, in the OpenAPI
  contract (`linkEmployeeUser`, `unlinkEmployeeUser`, `EmployeeCoreRecord`).
  No web link surface is added in TCH.1. Selecting a member from the HR screen
  would need a new member-listing disclosure, which is left to a later
  checkpoint that needs it.
- Import: a row's `user_id` goes through the same `create()` link. The
  same-User race is re-detected as `duplicate_exact`.

**Concurrency (§20).** Two-process tests with forced, observed overlap
(`ActingEmployeeConcurrencyTest`) prove both serial orders of each race:
- link vs membership suspension;
- unlink, employment end, Employee archive and membership suspension, each
  vs `hold()`;
- one User linked to two Employees;
- two Users linked to one Employee.

In none of them does a decision taken after an ineligibility commits succeed.

## 30. TCH.2 implementation (as built)

TCH.2 builds §7–§10, §15, §19 and the TCH.2 rows of §20–§23. The
TeachingAssignment is **dormant authorization substrate**:
- no Teacher role, no `*.teacher` capability;
- no teaching surface reads it (Attendance, Curriculum Delivery, LMS and
  Timetable are unchanged and admin-only);
- no teacher gains access to anything.

Decisions D-01 to D-17 are unchanged. Module record:
`docs/modules/TEACHING-ASSIGNMENTS.md`.

**Module (DOMAIN-MAP "Teaching Assignments").** `App\Domain\TeachingAssignments`
depends on HR and Academic Structure; neither depends on it. It never
derives ownership from `TimetableEntry` or `ActingEmployeeResolver`
(architecture guard). HR gains one neutral read,
`App\Domain\HR\Application\EmploymentCoverage`: an active Employee with a
`pre_joining`/`active`/`notice_period` employment covering a date, locked
`FOR SHARE`. It knows nothing about teaching.

**Table (§19).** Migration `2026_11_04_090000_create_teaching_assignments_table`:
- **Columns:** `id`, `school_id`, `employee_id`, `academic_year_id`,
  `campus_id`, `grade_level_id`, `section_id`, `subject_offering_id`,
  `starts_on`, `ends_on`, `created_by_user_id`, `ended_at`,
  `ended_by_user_id`, `end_reason`, timestamps.
- **Foreign keys:**
  - `teaching_assignments_employee_fk` `(employee_id, school_id)` →
    `employees(id, school_id)`;
  - `teaching_assignments_section_fk` `(section_id, school_id,
    academic_year_id, campus_id, grade_level_id)` → `sections_context_unique`;
  - `teaching_assignments_subject_offering_fk` over the same four stored
    context columns → `subject_offerings_context_unique`;
  - all RESTRICT; the users FKs RESTRICT; `school_id` CASCADE.
- **CHECKs:** `teaching_assignments_date_range_check` (`ends_on IS NULL OR
  starts_on <= ends_on`) and `teaching_assignments_end_shape_check` (the three
  end columns set together, an ended row has `ends_on`, closed reasons
  `completed`/`reassigned`/`employment_ended`).
- **History trigger:** `trg_teaching_assignments_history` refuses an
  already-ended insert, any change to an identity column (School, Employee,
  context, Section, Offering, `starts_on`, creator, `created_at`), any update
  other than the single end, an extension of an existing `ends_on`, and any
  change to an ended row.
  - *Amended by S7 (§47):* with `end_reason = 'employment_ended'` only, the
    CHECK also admits a void row (`ends_on = starts_on - 1`) and the trigger
    lets an ended row's `ends_on` move earlier. Both tables.
- **Tenancy and indexes:** forced RLS, `TenantRls::revokeDelete`. Indexes
  `unique(id, school_id)`, the key index `(school_id, employee_id,
  section_id, subject_offering_id, starts_on)` and the context index
  `(school_id, section_id, subject_offering_id)`.
- **Rollback / re-apply:** verified byte-identical with
  `pg_dump --schema-only`.

**Overlap (§10, as corrected).** There is no partial unique
`… WHERE ended_at IS NULL` and no exact-duplicate unique index. Every create
and end of one key serializes on the transaction-scoped advisory lock
`teaching.assignment:{school}:{employee}:{section}:{offering}`
(`pg_advisory_xact_lock(hashtextextended(…))`, the FeeSettingsService
convention). Under that lock the inclusive overlap
`existing.starts_on <= new.ends_on(∞) AND new.starts_on <= existing.ends_on(∞)`
is refused (`TEACHING_ASSIGNMENT_OVERLAP`, 409). Consequences:
- adjacent periods and future-dated replacements are allowed;
- an open-ended row blocks every later period until it is ended;
- ended rows count with their final `ends_on`;
- co-teaching (another Employee) is another key.

**Creation rules (§7, §15).**
- An active Section and an active **required** SubjectOffering of the same
  context (`TEACHING_ASSIGNMENT_REQUIRED_OFFERING_ONLY`, `_CONTEXT_MISMATCH`,
  `_CONTEXT_INACTIVE`).
- An open (draft or active) AcademicYear containing the dates.
- An active Employee with a covering planned or current employment on
  `starts_on` (`_EMPLOYEE_NOT_ASSIGNABLE`, 422). No linked User is required.
- An other-School or unknown id is a 404.

The "exact rule in TCH.2" left open by §15 is this: planned
(`pre_joining`) employment counts for planning, and use-time ActingEmployee
eligibility still governs any future access.

**Lifecycle (§9).**
- `end()` sets `ends_on` (on or after `starts_on`, never later than an
  existing end), `ended_at`, `ended_by_user_id` and a closed `end_reason`,
  once (`TEACHING_ASSIGNMENT_ALREADY_ENDED`, 409, under the row lock).
- There is no update, no delete and no cancellation.
- **Remaining limitation:** a future assignment cannot be cancelled; the
  earliest end is its start date. This is recorded rather than simulated
  with an inverted range.

**Authorization (§11, §15, D-11).** `teaching.assignments.view`/`.manage`
are seeded and granted to `school_admin` and `principal` only. They are
checked by route middleware and again in both services. Administrators need
no ActingEmployee. There is no role-name check.

**Concurrency (§20).** Two-process races with forced, observed overlap
(`TeachingAssignmentConcurrencyTest`):
- two overlapping creates → one created, one overlap;
- two ends → one ended, one already-ended;
- an end, then a create that waited → the create sees the shortened period;
- an employment end vs a create, and an Employee archive vs a create →
  both orders serialize, and a create after the ineligibility commits is
  refused.

**API/UI/audit (§22, §23).**
- `GET/POST /api/v1/schools/{school}/teaching-assignments`,
  `GET …/{id}` and `POST …/{id}/end`, with `private-no-store` and
  `idempotent` on writes; no PATCH/DELETE. In the OpenAPI contract as
  `listTeachingAssignments`, `getTeachingAssignment`,
  `createTeachingAssignment` and `endTeachingAssignment`, with regenerated
  shared types.
- An administrative page `/app/teaching-assignments` with directory-tier
  pickers.
- Audit events `teaching_assignment.created` and `teaching_assignment.ended`
  (ids, dates and the closed reason). No outbox event.
- Classification: Sensitive (DATA-CLASSIFICATION.md).

## 31. TCH.3 implementation (as built)

TCH.3 builds §12, §13 and §16.1: the production Teacher role, and
Curriculum Delivery as the first owned adopter. It is the first executable
instance of the §11 conjunction. D-01 to D-17 are unchanged. Attendance
(TCH.4, legal determination TCH-L1 for production) and LMS remain
admin-only. TCH.3 adds no legal gate: Curriculum Delivery stores no Student
or teacher identity, and E21 stays the general retention item.

**Teacher role (§12, T1).** The system School role `teacher` ("Teacher"),
seeded by `CapabilityAndRoleSeeder`, carries exactly
`curriculum.delivery.teacher`:
- It is granted and revoked through the ordinary staff role path
  (`StaffAccessService`, `StaffRoleCatalog`); there is no special
  endpoint.
- Under the existing no-escalation rule an actor may grant only a role
  whose every capability they hold. `school_admin` therefore also holds
  `curriculum.delivery.teacher`. That adds no School-wide reach: used alone
  it still needs an ActingEmployee and a TeachingAssignment, and School
  Admin already holds `curriculum.delivery.manage`. `principal` does not
  hold it and cannot grant roles.
- A role grant creates no Employee link and no TeachingAssignment. Neither
  of those creates a role grant.
- No code checks the role key (architecture guard). Any role carrying the
  capability works the same (tested with a non-`teacher` role).

**Capabilities (§13).** `curriculum.delivery.teacher` is the owned-scope
(Tier 2) capability. `curriculum.delivery.view`/`.manage` keep their
School-wide (Tier 1) meaning and grants unchanged. Administrators need no
Employee record, ActingEmployee or TeachingAssignment.

**The executable chain.** Authenticated User → trusted School route context
with an active membership (the School-route middleware) →
`curriculum.delivery.teacher` → `ActingEmployeeResolver` (today,
School-local) → `TeachingOwnership` for the exact Section + SubjectOffering
on every date the operation involves.

- **Actor date:** today.
- **Ownership dates:** the delivery's own dates:
  - `started_on` to start;
  - `completed_on` to complete;
  - the cleared completion date to reopen;
  - every written AND replaced date to correct.
- **Read visibility:** a teacher period overlapping
  `[started_on, completed_on]` (open while in progress).

**Ownership read (TeachingAssignments).**
`App\Domain\TeachingAssignments\Application\TeachingOwnership`:
- `periods()` is fresh. It returns an Employee's ownership periods as
  `OwnedTeachingPeriod`, ids and dates only.
- `hold()` runs in the transaction. It reads the one covering assignment
  of the key `FOR SHARE`; zero or several covering rows fail closed.
- It is never cached, never derived from `TimetableEntry` and never aware
  of roles.
- Curriculum Delivery is the only consumer and uses only these two classes
  (architecture guard).

**Curriculum Delivery integration.**
- **Reads:** `TeacherDeliveryAccess::scope()` (capability + fresh
  ActingEmployee + periods) feeds `TeacherDeliveryReadService`, which
  filters in the query.
- **Writes:** the same `CurriculumDeliveryService` receives an optional
  `DeliveryWriteGuard`. The Tier 2 `TeacherDeliveryGuard` runs inside its
  transaction before the insert or row lock: capability, then
  `ActingEmployeeResolver::hold()`, then visibility (404), then
  `TeachingOwnership::hold()` per date
  (`CURRICULUM_DELIVERY_OUTSIDE_TEACHING_ASSIGNMENT`, 422).
- **Lock order:** School → membership → User → Employee → EmploymentRecord
  → TeachingAssignment → `curriculum_deliveries`, matching §20. If a
  concurrent correction changed the row's dates between the unlocked
  guard read and the row lock, the guard runs again.
- **Unchanged:** `curriculum_deliveries` gains no `teacher_id`, the audit
  events and actor attribution are the existing ones, and the
  classification stays Confidential.

**Surfaces (§23).**
- API `/api/v1/schools/{school}/my/curriculum-delivery-contexts`,
  `/my/curriculum-deliveries` (GET list for one owned class, POST) and
  `/my/curriculum-deliveries/{id}` (GET, PATCH, POST `…/transition`), with
  `private-no-store`. They are in the OpenAPI contract, with regenerated
  shared types. The Tier 1 routes are unchanged.
- Page `/app/my-curriculum-delivery` ("My Curriculum Delivery"), linked by
  the capability. A capability holder who is not an eligible Employee sees
  an empty state and every write is refused.
- Teachers get no Attendance, LMS or Timetable surface, and no
  TeachingAssignment administration.

**Tests.**
- Authorization matrix, `TeacherCurriculumDeliveryAccessTest`:
  - capability, identity or ownership missing;
  - role alone; a non-`teacher` role;
  - wrong Section or Offering; inclusive date bounds; a future assignment;
  - historical ownership and hand-over; co-teaching; temporary cover;
  - timetable independence; non-disclosure;
  - role revoke, assignment end and five off-boarding cases;
  - Tier 1 unchanged.
- `TeacherRoleRegistryTest`, `MyCurriculumDeliveryUiTest` and
  `TeacherDeliveryArchitectureGuardTest`.
- Two-process races (`TeacherDeliveryConcurrencyTest`): a teacher write vs
  an assignment end, a membership suspension, an unlink, an archive and an
  employment end, in both orders.
- The existing Curriculum Delivery concurrency test passes unchanged.

**Demo.** Inside the demo builder's environment guard, the demo teacher
(Kavya Reddy) holds the production `teacher` role and one assignment:
G8-A Mathematics for the current year. There is no `demo.teacher` role.

## 32. TCH.4 implementation (as built)

TCH.4 builds §16.2: Attendance as the second owned adopter, on Sensitive
Student data. D-01 to D-17 are unchanged. **Teacher Attendance
functionality is implemented but production enablement remains blocked by
TCH-L1 until the required legal/compliance determination is recorded.**
TCH-L1 stays **OPEN** (§26): not a development blocker, a production
blocker. This section draws no statutory conclusion.

**Capability and role (§12, §13).**
- New owned-scope (Tier 2) capability `attendance.teacher`.
- The production `teacher` role now carries exactly
  `curriculum.delivery.teacher` and `attendance.teacher` — nothing else, and
  in particular no `attendance.view`/`.manage` and no `students.view`.
- `school_admin` also holds `attendance.teacher`, only so the no-escalation
  rule lets it grant the Teacher role (§31). It adds no School-wide reach:
  School Admin already holds `attendance.manage`. `principal` does not hold
  it.
- `attendance.view`/`.manage` keep their School-wide (Tier 1) meaning,
  grants and routes unchanged. Administrators need no Employee record,
  ActingEmployee or TeachingAssignment.
- No code checks the role key (architecture guard).

**The executable chain.** Authenticated User → trusted School route context
with an active membership → `attendance.teacher` → `ActingEmployeeResolver`
(today, School-local; `hold()` inside the write transaction) →
`TeachingOwnership` for the exact Section + SubjectOffering on the
register's `attendance_date`.

- **Actor date:** today.
- **Ownership date:** the one `attendance_date`, for submission and for
  correction alike. A class's current teacher therefore cannot correct an
  earlier teacher's register, and a register taken under cover stays with
  the cover teacher's dates.
- **Read visibility:** a register is the teacher's when they own its Section
  + SubjectOffering on its `attendance_date`. Lists are filtered in the
  query (`TeacherAttendanceScope::constrain()`), never in the browser.
- **Class selection:** a register names its class through a
  `TimetableEntry`. The entry names the class; the TeachingAssignment
  authorizes it. The scheduled classes and roster preview list only entries
  whose Section + SubjectOffering the teacher owns on the date.

**Timetable and provenance (§8).** `timetable_entries.teacher_id` and
`attendance_sessions.teacher_id` authorize nothing. The session still
snapshots the entry's scheduled teacher as provenance. So a temporary cover
teacher (assignment, no timetable slot) can take the register, and the
session still names the timetabled teacher. A timetabled teacher without an
assignment gets 404. Co-teachers (two assignments for one class) both
qualify. No Attendance code filters or authorizes on a teacher column
(architecture guard).

**Attendance integration.**
- **Reads:** `TeacherAttendanceAccess::scope()` (capability + fresh
  ActingEmployee + `TeachingOwnership::periods()`).
- **Writes:** the same `AttendanceSubmissionService` and
  `AttendanceCorrectionService` receive an optional `AttendanceWriteGuard`.
  There is no forked submission or correction logic. The Tier 2
  `TeacherAttendanceGuard` runs inside their transaction, before any
  Attendance lock:
  1. the capability;
  2. `ActingEmployeeResolver::hold()`;
  3. visibility: the class for submission, the register on its date for
     correction — unowned is a non-disclosing 404;
  4. `TeachingOwnership::hold()` on the date — outside it is
     `ATTENDANCE_OUTSIDE_TEACHING_ASSIGNMENT` (422).
- **Lock order:** School → membership → User → Employee → EmploymentRecord
  → TeachingAssignment → TimetableEntry → AcademicYear → Section →
  StudentEnrollments, matching §20 and the existing Attendance order. If
  the entry was repointed at another class between the unlocked read and
  its lock, the guard runs again for the class it names now.
- **Unchanged:** the schema, the Attendance audit events and actor
  attribution, the duplicate-register unique indexes, the roster rules and
  the Sensitive classification.

**Idempotency (CLAUDE.md rules 29, 32).** The teacher submit is
deliberately **not** `idempotent`. A stored-response replay is served by the
middleware before the handler re-verifies the ActingEmployee and the
TeachingAssignment. Duplicate registers are already refused by the
database: `ATTENDANCE_SESSION_ALREADY_SUBMITTED` /
`ATTENDANCE_SECTION_SLOT_ALREADY_SUBMITTED` (409). The Tier 1 submit keeps
its idempotency.

**Denial semantics (§18).**
- **403:** no `attendance.teacher`, or not an eligible Employee today
  (`HR_ACTING_EMPLOYEE_UNAVAILABLE`).
- **404:** an unowned class or register, another School's id, an unknown id
  and a malformed id — all the same response.
- **422 / 409:** an owned class outside its assignment dates, and the
  existing Attendance rules.

**Surfaces (§23).**
- **API**, under `/api/v1/schools/{school}/my/`, every route with
  `capability:attendance.teacher` and `private-no-store`:
  - `attendance-sessions` — GET list, POST submit;
  - `attendance-sessions/scheduled-classes`;
  - `attendance-sessions/roster-preview`;
  - `attendance-sessions/{id}`;
  - `attendance-records/{id}/correct` — POST.

  They are in the OpenAPI contract, with regenerated shared types. The Tier
  1 routes are unchanged.
- **Page** `/app/my-attendance` ("My Attendance"): the existing Attendance
  Index, Take and Show pages with a base URL, linked from the dashboard by
  the capability. A capability holder who is not an eligible Employee sees
  an empty list, and every other page is refused.
- **Not given to teachers:** the Student directory (`students.view`), the
  administrative Attendance pages, Timetable administration and
  TeachingAssignment administration.

**MFA and processing authorization (§17).** None is added. The Tier 1
Attendance surface has neither, and TCH lowers nothing.

**Tests.**
- Authorization matrix, `TeacherAttendanceAccessTest`:
  - the positive path, with provenance;
  - capability, identity or ownership missing; a role alone; a non-`teacher`
    role;
  - wrong Section or Offering; inclusive date bounds; a future assignment;
  - hand-over; temporary cover; timetable teacher without an assignment;
    co-teaching;
  - list filtering; non-disclosure;
  - role revoke, assignment end and off-boarding;
  - Tier 1 unchanged; no Tier 1, Student or TeachingAssignment access.
- `MyAttendanceUiTest`, `TeacherRoleRegistryTest`,
  `TeacherAttendanceArchitectureGuardTest` and
  `TeachingAssignmentArchitectureGuardTest`. Attendance may use only
  `TeachingOwnership`/`OwnedTeachingPeriod`.
- Two-process races (`TeacherAttendanceConcurrencyTest`): a teacher
  submission vs an assignment end, a membership suspension, an unlink, an
  archive and an employment end, in both orders; a correction vs an
  assignment end (end first) and a membership suspension (both orders).
  *(Corrected by the TCH.6 audit, which found the earlier wording claimed
  every correction race in both orders. Correction runs the same
  `holdActor()`/`TeachingOwnership::hold()` primitives as submission, so
  the protection is the same code path; §38.6.)*
- The existing Attendance concurrency tests pass unchanged.

**Demo.** Inside the demo builder's environment guard, the demo teacher
(Kavya Reddy, production `teacher` role, G8-A Mathematics) now also has one
administratively submitted G8-A Mathematics register to review and correct
under My Attendance.

## 33. TCH.5A — LMS teacher ownership audit and decision register

**Status: the TCH.5A audit record (2026-09-30, baseline `b55ca3e`),
published while the owner decision was open. Resolved by §34, which is
authoritative wherever the two differ** (§34 narrows §33.5's draft reads
and adds audience immutability). This section is documentation only. It
adds no capability and authorizes no LMS teacher implementation. LMS stays
admin-only (Tier 1). Submission stays cancelled (ADR 0039 cancellation
addendum) and is outside every TCH checkpoint.

### 33.1 As-built LMS resource shape [FACT at `b55ca3e`]

Both LMS resources have the same shape.

| | Learning Content | Assignment |
|---|---|---|
| Table | `learning_content` (`database/migrations/2026_10_13_090000_create_learning_content_table.php:63-91`) | `assignments` (`…_090200_create_assignments_table.php:80-108`) |
| School | `school_id`, `unique(id, school_id)`, `TenantRls::enable` (`:65`, `:73`, `:91`) | same (`:82`, `:90`, `:108`) |
| Parent | one `subject_offering_id`, composite FK to `subject_offerings(id, school_id)` RESTRICT (`:66`, `:81-83`) | same (`:83`, `:98-100`) |
| Year / Campus / Grade | only through the Offering, never stored (`:19-23`) | same (`:19-23`) |
| Section | **none** | **none** |
| Author / owner | **none** — deliberately (`:35-42`) | **none** — deliberately (`:35-43`) |
| Dates | `created_at`/`updated_at` only | `due_on` (School-local date, nullable, editable, informational only — `:86`, `:45-61`; ADR 0039 §9) |
| Lifecycle | `draft → published → archived → published` (`LearningContentService.php:66-70`) | `draft → published → closed → published` (`AssignmentService.php:67-71`) |
| Delete | none (no route; status retirement) | none |
| Tier 1 checks | route `capability:lms.content.view/.manage` (`routes/api.php:1997-2013`) and controller (`LearningContentController.php:40-109`) | route `capability:lms.assignments.view/.manage` (`routes/api.php:2047-2064`) and controller (`AssignmentController.php:42-111`) |
| Grants | `school_admin`, `principal` (`CapabilityAndRoleSeeder.php:1136-1140`, `:1314-1316`); not `teacher` (`:1340`) | same |
| UI | `/app/learning-content` (`routes/web.php:1406-1412`) | `/app/assignments` (`routes/web.php:1419-1425`) |
| Audit | `lms.learning_content.created/updated/published/archived`, actor = the User (`LearningContentService.php:106`, `:138`, `:161`, `:171`) | `lms.assignment.created/updated/published/closed`, actor = the User (`AssignmentService.php:109`, `:154`, `:188`, `:213`) |
| Offering kind | required **and** elective (no `is_required` check, `LearningContentService.php:86`) | same (`AssignmentService.php:87`) |

Guards that pin the "no identity" shape:
- `LearningContentArchitectureGuardTest.php:60` and
  `AssignmentArchitectureGuardTest.php:64` forbid `teacher_id`,
  `employee_id`, `user_id` and `created_by_employee_id` columns.
- `TeachingAssignmentArchitectureGuardTest` forbids LMS from referencing
  TeachingAssignment.

**Audience.** LMS has no audience or roster code, and no Student-facing
surface exists (Submission is cancelled; there are no Student accounts).
The *contracted* audience is the whole Offering roster: an Assignment is
work "a SubjectOffering's roster is expected to complete" (ADR 0039 §2). ADR
0039 names the Section-agnostic `SubjectOfferingRosterReadService` as that
roster seam, and alternative 1 **rejected** Section scoping.

**Documents** (ADR 0039 §8). Attachments hang off the exclusive owner arc
(`learning_content_id`, `assignment_id`). Authorization is **capability-only,
by owner type**:
- read: `DocumentReadService.php:147-154` — `lms.content.view` /
  `lms.assignments.view`;
- write and archive: `DocumentService.php:258`, `:278`, `:284-290` —
  `.manage`.

The generic `/documents/{document}` show/content/archive routes resolve the
owner type from the stored row. `documents.uploaded_by_user_id` is uploader
provenance, not ownership. Documents already depends on LMS
(`DocumentService.php:13-14`).

**Legacy rows.**
- No seeder or demo builder creates LMS rows; they exist only in test
  fixtures.
- Every LMS row any environment can hold was created through a Tier 1
  `.manage` grant. No stored fact records which Employee authored it.

### 33.2 SubjectOffering spans Sections [FACT]

- **The Offering has no Section.** `subject_offerings` is unique per
  `(school_id, academic_year_id, campus_id, grade_level_id, subject_id)`
  (`2026_08_23_091000_create_subject_offerings_table.php:38-42`), and a
  Section is one of many per `(school, year, campus, grade)`
  (`…_090900_create_sections_table.php:36`).
- **One required Offering therefore serves every Section of its grade,
  campus and year.** Its implied roster filters year, grade and campus
  only (`SubjectOfferingRosterReadService.php:75-85`).
- **An elective Offering's roster crosses Sections too.** It comes from
  `student_subject_enrollments` (`:99-118`).
- **TeachingAssignment is per Section and required-only.** It is Employee ×
  Section × **required** Offering (`TeachingAssignmentService.php:105`).

So Teacher A (Section A × Offering X) and Teacher B (Section B × Offering X)
share every existing LMS row of Offering X.

### 33.3 Ownership models evaluated

**A — Offering ownership** (any TeachingAssignment for the Offering ⇒
authority over the Offering's LMS rows). **Rejected: unsafe.** Under §33.2,
Teacher A could:
- edit, archive or close Teacher B's and the administrators' rows;
- publish or reopen an Assignment whose contracted audience includes Section
  B's students;
- change shared material for every Section.

This is exactly the failure ADR 0039 §6 named.

**B — Per-row owner Employee.** Solves "who may edit": only the owner, and
only while they teach the class. It does **not** solve "who is it for". A
teacher-owned row that stays Offering-wide still reaches every Section
(§33.2). **Necessary, not sufficient.**

**C — One Section on the row.** A single `section_id` makes the audience
explicit. The cost: a teacher of three Sections must create three copies
(and three attachment sets). It also contradicts ADR 0039 §2 and
alternative 1.

That rejection's premise ("the only roster seam is Section-agnostic") is
weaker today. Attendance's `StudentEnrollmentRosterReadService` provides an
as-of-date Section roster. But the Section-agnostic nature of electives
still holds.

**D — Audience bridge.** Rows `resource → Section` inside the row's
Offering context, with no rows meaning Offering-wide. This models "one
Section", "several of my Sections" and "the whole Offering" without copies,
and keeps audience separate from owner. It has more moving parts than C: a
bridge table, same-context composite FKs, RLS and an immutability rule.

| Model | Safe alone | Complexity | Migration | Co-teaching | Handover | Legacy rows |
|---|---|---|---|---|---|---|
| A | **No** | none | none | unsafe | unsafe | silently teacher-editable |
| B | edits yes, audience **no** | low | 1 nullable column | owner-only | owner-only | owner NULL |
| C | yes (with B) | medium | column + ADR 0039 amendment | per Section | per Section | Section NULL = Offering-wide |
| D | yes (with B) | medium-high | bridge + ADR 0039 amendment | per Section | per Section | no audience rows = Offering-wide |

### 33.4 The owner decision (LMS-T3)

The blocking question is **audience**: whom a teacher-authored LMS row is
for. Repository evidence cannot settle it. ADR 0039 (Accepted) fixed LMS
rows as Offering-wide and rejected Section scope. Changing that is an
architectural deviation that needs an explicit decision (CLAUDE.md
rule 15), not an inference from TeachingAssignment having a Section.

The options:
1. **Section-targeted teacher rows (recommended).** A teacher-owned row
   targets one or more Sections (model D, or C if the owner prefers the
   simpler schema). The author must own every targeted Section for the row's
   Offering. Tier 1 rows keep the ADR 0039 Offering-wide meaning. ADR 0039
   §2 is amended to "Offering-wide by default, Section-targeted when
   teacher-owned".
2. **Offering-wide teacher rows for sole teachers only.** A teacher may
   publish only when they own every active Section of the Offering. This is
   fragile: adding a Section or a co-teacher changes the answer after
   publication. Not recommended.
3. **Teacher drafts, administrator publication.** Teachers author and edit
   their own drafts (model B only); publication stays Tier 1 and
   Offering-wide. There is no ADR 0039 amendment, but teachers cannot run
   their own class, and publication becomes an approval workflow.

Consequence: TCH.5B cannot start until one option is chosen.

### 33.5 Proposed contract (applies once LMS-T3 is decided; option 1 assumed)

The formula, per operation:

```text
teacher LMS access =
    authenticated User + trusted School context + active membership
  + lms.content.teacher | lms.assignments.teacher        (owned-scope, Tier 2)
  + ActingEmployee (resolve() for reads; hold() FOR SHARE inside every write)
  + TeachingOwnership::hold() for every audience Section × the row's Offering,
    on TODAY (School-local)                                  -- teaching relationship
  + row.owner_employee_id = ActingEmployee.employeeId        -- resource ownership (writes)
```

- **Three facts, never collapsed** (LMS-T4). The audit actor is the
  authenticated User, as today. The resource owner is an Employee. The
  TeachingAssignment proves the teaching relationship. None stands in for
  another, and `created_by`, audit rows, `uploaded_by_user_id` and
  `TimetableEntry.teacher_id` are never ownership.
- **Date anchor: today** (LMS-T9). LMS rows have no teaching-effective date.
  `created_at` is not one, and `due_on` is nullable, editable and
  informational. So every teacher write and lifecycle action (create,
  edit, publish, archive, close, reopen) requires ownership today of every
  audience Section. This differs deliberately from Curriculum Delivery
  (the delivery's dates) and Attendance (`attendance_date`).
- **Reads are broader than writes** (LMS-T8). While an eligible
  ActingEmployee, a teacher may read:
  - their own rows, in any status;
  - **published** rows whose audience includes a Section they own today
    (co-teachers' and predecessors' rows);
  - **published** Tier 1 Offering-wide rows of an Offering where they own
    a Section today.

  Never another teacher's or an administrator's draft. Unowned or unknown
  rows are the same 404 (§18).
- **Writes are owner-only.** A co-teacher reads a colleague's published row
  and never edits or transitions it (LMS-T5).
- **Ownership is immutable, never transferred** (LMS-T6). On
  handover (A to 30 June, B from 1 July):
  - B reads A's published rows for B's Section;
  - B cannot edit, close or archive them;
  - A loses write access when A's assignment ends, even if A is still
    employed;
  - reuse is a new row owned by B (a copy action, if ever built, is its own
    decision);
  - lifecycle clean-up of a departed teacher's rows is Tier 1.
- **Tier 1 rows** (owner NULL) are School/administrative material (LMS-T7).
  Teachers read them when published and never write them. There is no
  transfer and no fabricated owner. Administrators keep full School-wide
  authority over every row, owned or not, without ActingEmployee or
  TeachingAssignment (§11).
- **Electives:** TeachingAssignment is required-only, so elective LMS stays
  Tier 1 until an elective ownership fact exists.
- **Timetable never participates.**

### 33.6 Documents seam (LMS-T10)

The capability-only owner-type check (§33.1) must **not** simply accept
`lms.*.teacher`. That would let a teacher read or archive any LMS
attachment in the School.

The future seam:
- Documents asks an LMS-published owned-access port whether this actor may
  read, or may write, the Document's LMS owner row.
- The port applies §33.5's read or write rule to the **parent row**. That
  includes `hold()` inside the Documents write transaction.
- The generic `/documents/{document}` routes accept Tier 2 only through that
  port.
- Tests must prove that teaching the same Offering, a co-teacher's draft,
  and an unowned Section each yield 404, for both list and content
  downloads.

### 33.7 Persistence and migration outline (TCH.5B)

- **Owner column.** `owner_employee_id` (nullable) on both tables. Composite
  FK `(owner_employee_id, school_id)` → `employees(id, school_id)`
  RESTRICT. Immutable after insert (database trigger). NULL means a Tier 1
  / School row.
- **Audience.** Per LMS-T3: a bridge per table, or a nullable `section_id`.
  - The Section must be pinned to the row's Offering context by a composite
    FK (CLAUDE.md rule 70).
  - Forced RLS; history kept.
  - An owned row needs at least one audience Section. That is a service
    invariant plus a test, because a CHECK cannot span tables.
- **Backfill: none** (LMS-T11). Every existing row stays owner NULL /
  Offering-wide. Owners are never derived from audit actors, `created_by`,
  email, `TimetableEntry` or a current TeachingAssignment. Doing so would
  fabricate history.
- **Rollback.** `down()` must refuse while any owned row exists. Silently
  dropping ownership would widen a teacher row to Offering-wide Tier 1
  material.
- **Guard updates.** The LMS architecture guards and
  `TeachingAssignmentArchitectureGuardTest` are changed deliberately in
  that checkpoint. LMS may use only `TeachingOwnership`/`OwnedTeachingPeriod`
  and `ActingEmployeeResolver`.
- **Classification.** Storing an owner Employee names an identifiable person
  against the resource and, with an audience, a class. Under this table's
  Timetable/TeachingAssignment reasoning, Learning Content and Assignment
  re-tier to **Sensitive** in the same branch. Attachments keep their fixed
  `internal` Documents tier; they do not name the owner.

### 33.8 Capabilities and role (LMS-T12)

- Two separate owned-scope capabilities: `lms.content.teacher` (author and
  manage own Learning Content) and `lms.assignments.teacher` (author,
  publish and close own Assignments). They are distinct acts, and a School
  may want one without the other.
- **Neither exists yet.** Each is added by its own adoption checkpoint. The
  `teacher` role then gains it, and `school_admin` holds it for grantability
  only (§31).
- Teachers never receive `lms.content.view/.manage` or
  `lms.assignments.view/.manage`.

### 33.9 Legal and classification

- **No new legal/compliance item.** Learning Content and Assignment are
  staff-authored and hold no Student data. TCH-L1 is Attendance-only and is
  not copied.
- E21 (retention) covers owner history, as it does TeachingAssignment
  history.
- Submission's cancelled status is unchanged.
- The Sensitive re-tier (§33.7) is a classification change, not a legal
  gate.

### 33.10 Decision register

| ID | Decision | State |
|---|---|---|
| LMS-T1 | Learning Content ownership model | **Proposed:** B (immutable owner Employee) + LMS-T3 audience; A rejected. Depends on LMS-T3 |
| LMS-T2 | Assignment ownership model | **Proposed:** same as LMS-T1; no evidence requires a stronger model, but Assignment adopts after Learning Content. Depends on LMS-T3 |
| LMS-T3 | Section/audience scoping of teacher rows | **OWNER DECISION REQUIRED** (§33.4). Recommendation: option 1, model D (C acceptable). Alternatives: option 2 (not recommended), option 3. Consequence: amends ADR 0039 §2 / alternative 1 (options 1, 2) or confines teachers to drafts (option 3). Needed before TCH.5B |
| LMS-T4 | Author Employee vs teaching-context ownership | **Frozen:** User = audit actor; owner Employee = resource ownership; TeachingAssignment = teaching relationship; never collapsed |
| LMS-T5 | Co-teacher edits | **Proposed default:** owner-only writes; co-teachers read published rows. Confirm with LMS-T3 |
| LMS-T6 | Handover | **Proposed default:** no transfer; successor reads, cannot write; predecessor loses writes when the assignment ends; clean-up is Tier 1. Confirm with LMS-T3 |
| LMS-T7 | Tier 1 / shared rows | **Frozen:** owner NULL = School material; teachers read when published, never write; no fabricated owner; admins unchanged |
| LMS-T8 | Read vs write | **Frozen:** reads broader (own + published rows for currently owned Sections/Offerings), writes owner-only |
| LMS-T9 | Date anchor | **Frozen:** today (School-local) for every teacher write and lifecycle action; no LMS row has a teaching-effective date |
| LMS-T10 | Documents attachments | **Frozen:** attachments follow the parent row's owned read/write rule through an LMS-published port; capability alone never authorizes |
| LMS-T11 | Legacy rows | **Frozen:** no backfill; existing rows stay owner NULL / Offering-wide |
| LMS-T12 | Capability split | **Frozen:** `lms.content.teacher` and `lms.assignments.teacher`, separate, added one checkpoint at a time |
| LMS-T13 | Checkpoints | **Proposed:** TCH.5B persistence foundation → TCH.5C Learning Content adoption → TCH.5D Assignment adoption → TCH.6 closure audit. Starts only after LMS-T3 |

"Frozen" entries hold under every LMS-T3 option (option 3 simply has no
teacher publication). Nothing in this section is implemented.

## 34. TCH.5A resolution — LMS teacher ownership contract (owner decision)

**Status: RESOLVED AND CLOSED (2026-09-30, owner decision; docs only).**
D-14 is resolved. This section is the authoritative LMS teacher ownership
contract; where §33 differs, this section wins. *(Since implemented by
TCH.5B–TCH.5D, §35–§37; the next sentences record the state when this
section was written.)* **Nothing here is
implemented.** No `lms.*.teacher` capability exists, teachers have no LMS
access, and LMS stays admin-only (Tier 1) until TCH.5C/TCH.5D. Submission
stays cancelled and outside every checkpoint.

### 34.1 Audience model (LMS-T3): Section-targeted, audience bridge

Adopted: **model D** (§33.3). Teacher-authored rows target Sections through
an audience bridge.

Rejected: Offering-only teacher write authority. A SubjectOffering spans
every Section of its grade (§33.2), so owning one Section of the Offering
would let a teacher alter material that reaches other Sections and other
teachers.

A teacher-authored row carries two **distinct** facts:
- an immutable owner Employee (`owner_employee_id`, conceptually);
- one or more immutable Section audience rows.

TCH.5B fixes the exact names under repository conventions. It prefers
concrete, FK-backed bridge tables (one per resource) over a polymorphic
audience table, unless the repository offers an equally safe structural
alternative.

### 34.2 Learning Content and Assignment (LMS-T1, LMS-T2)

Both use the same ownership model: owner Employee plus Section audience.
They are adopted in **separate** checkpoints: Learning Content first
(TCH.5C), then Assignment (TCH.5D). A shared persistence contract is not a
reason to combine the implementations.

### 34.3 Legacy and administrative rows (LMS-T7, LMS-T11)

- **Legacy/admin state:** `owner_employee_id = NULL` with **no** Section
  audience rows. That state keeps today's SubjectOffering-wide meaning.
- **Every existing row stays in that state.** There is no backfill from a
  `created_by` User, an audit actor, an email, a `TimetableEntry` or a
  current TeachingAssignment.
- **Tier 1 is unchanged.** Administrators keep School-wide
  `lms.content.view/.manage` and `lms.assignments.view/.manage` over every
  row, owned or not, with no ActingEmployee, TeachingAssignment or owner
  Employee.
- **Admin-created rows remain Offering-wide with no owner** throughout the
  initial teacher-adoption programme. No Section-targeted admin authoring
  workflow is introduced without a later product decision.

### 34.4 Teacher creation

A teacher may create a teacher-owned row only when **all** of these hold at
execution time:

```text
owned-scope LMS capability (lms.content.teacher | lms.assignments.teacher)
AND valid ActingEmployee (held FOR SHARE in the write transaction)
AND the row's owner Employee = that ActingEmployee
AND at least one Section audience is selected
AND every selected Section belongs to the row's SubjectOffering context
AND a TeachingAssignment covers every selected Section × that SubjectOffering
    on the School-local current date
```

Several Sections may be targeted only if the teacher owns every one of
them.

### 34.5 Teacher writes (owner-only)

Edit, publish, archive, close, reopen and any later adopted lifecycle
action all require, at execution time:

```text
valid ActingEmployee
AND the required lms.*.teacher capability
AND row.owner_employee_id = ActingEmployee.employeeId
AND a current TeachingAssignment covers every audience Section × the Offering
```

- The owner field never replaces TeachingAssignment eligibility, and a
  TeachingAssignment never replaces owner identity.
- Legacy/admin rows (owner NULL) are never teacher-writable.

### 34.6 Immutability

- **Owner.** `owner_employee_id` is immutable once the row is created.
  - There is no teacher ownership transfer and no reassignment on handover.
  - An audit `created_by`/actor User is never reinterpreted as ownership.
  - If the owner loses the teaching relationship, an administrator does any
    lifecycle clean-up.
- **Audience.** The Section audience is immutable after creation. A
  resource is never widened (Section A to A + B), narrowed or retargeted,
  before or after publication. A different audience means a new resource.
  This keeps history and publication meaning deterministic.

### 34.7 Reads (LMS-T8), co-teaching (LMS-T5), handover (LMS-T6)

Reads are intentionally broader than writes. Every teacher read also
requires an eligible ActingEmployee and the owned-scope capability.

| Row | A teacher may read it when |
|---|---|
| Teacher-owned, unpublished | they are the owner **and** currently own **every** audience Section. Historical authorship alone does not keep access once the teaching relationship ends. |
| Teacher-owned, published | they currently own **at least one** Section in its audience. They need not be the owner. |
| Legacy/admin (Offering-wide), published | they currently own at least one Section of its SubjectOffering |
| Anything else | never — the same non-disclosing 404 (§18) |

Tier 1 reads stay School-wide under the existing capabilities.

- **Co-teaching.** Teachers A and B of one Section each own only their own
  resources. Each may read the other's published resources that target a
  Section they currently teach. Neither may edit the other's. Co-teaching
  never implies shared ownership.
- **Handover** (A owns the Section January–June, B from July):
  - B reads A's published resources that target B's current Section;
  - B may not edit, archive or close them;
  - A loses teacher write access once A no longer owns every audience
    Section;
  - A also loses A's own unpublished drafts, because historical authorship
    does not preserve access;
  - ownership never moves from A to B;
  - Tier 1 administrators keep full management authority.

### 34.8 Date anchor (LMS-T9), identities (LMS-T4), Timetable

- **Date anchor.** Teacher LMS authorization uses the **School-local current
  date** for both ActingEmployee eligibility and TeachingAssignment
  coverage. Never `created_at`, `due_on`, an audit timestamp or a
  `TimetableEntry`. LMS rows have no trustworthy teaching-effective date.
  This differs deliberately from Curriculum Delivery (the delivery's dates)
  and Attendance (`attendance_date`).
- **Three distinct facts.** The authenticated User is the audit actor.
  The owner Employee is the resource owner. The TeachingAssignment is the
  teaching relationship. None substitutes for another.
- **Identity and ownership reads.** Identity comes only through TCH.1's
  `ActingEmployeeResolver` (`hold()` inside every authoritative write
  transaction); there is no second User→Employee resolver. Ownership comes
  only through `TeachingOwnership`.
- **Timetable never participates.**

### 34.9 Structural integrity, Documents seam, migration (TCH.5B obligations)

- **Audience integrity is database-enforced** (CLAUDE.md rules 18, 70).
  Every audience Section must match the resource's School, AcademicYear,
  Campus and GradeLevel, and so its SubjectOffering context. Enforcement is
  by composite foreign keys, with forced RLS on each bridge. Application
  validation alone is not enough.
- **The owner Employee** has a composite FK to `employees(id, school_id)`
  and a database-enforced immutability rule. The audience is immutable too.
- **Documents (LMS-T10).** `lms.content.teacher`/`lms.assignments.teacher`
  are **never** added to the capability-only Documents owner check
  (§33.1). Teacher attachment access goes through the parent resource:

  ```text
  Document operation → LMS parent-authorization port → ActingEmployee
    → owned-scope capability → owner/audience rule → TeachingAssignment
  ```

  TCH.5B defines that port; TCH.5C/TCH.5D wire it. Administrative Documents
  behaviour is unchanged.
- **Migration (LMS-T11).** Ownership and audience persistence is added with
  no backfill, and legacy/admin rows are preserved. `down()` must refuse
  while any owner or audience data exists, unless an equally safe
  non-destructive rollback exists. A rollback must never reinterpret a
  teacher-owned, Section-targeted row as Offering-wide admin material.
  TCH.5B finalizes and tests the exact strategy.
- **Guards.** The LMS architecture guards (no-identity columns) and
  `TeachingAssignmentArchitectureGuardTest` (no LMS reference) are changed
  deliberately in TCH.5B/TCH.5C, and never widened beyond
  `TeachingOwnership`/`OwnedTeachingPeriod` and `ActingEmployeeResolver`.

### 34.10 Capabilities (LMS-T12)

- **Two separate future capabilities.** `lms.content.teacher` (TCH.5C) and
  `lms.assignments.teacher` (TCH.5D), each added by its own adoption
  checkpoint.
- **Neither exists today, and TCH.5A adds neither.** When each lands, the
  `teacher` role gains it and `school_admin` holds it for grantability only
  (§31).
- **Teachers never receive `lms.content.view/.manage` or
  `lms.assignments.view/.manage`** for owned workflows.

### 34.11 Classification and legal

- **Classification.** Authoritative Employee ownership and a Section
  audience on teacher-authored rows name an identifiable person against a
  class. Under the existing Timetable/TeachingAssignment reasoning,
  Learning Content and Assignment therefore move to **Sensitive**, recorded
  in the same branch as the persistence (TCH.5B). This is a classification
  consequence, not by itself a legal gate. LMS attachments keep their fixed
  `internal` Documents tier.
- **Legal.** No new LMS-specific legal/compliance gate was identified.
  - TCH-L1 is Attendance-only and is not copied.
  - E21 (retention) remains the existing production issue for owner and
    audience history.
  - LMS Submission remains separately cancelled and out of scope.

### 34.12 Final decision register

| ID | Disposition |
|---|---|
| LMS-T1 | **RESOLVED** — Learning Content = Employee owner + Section audience |
| LMS-T2 | **RESOLVED** — Assignment = Employee owner + Section audience (adopted separately, after Learning Content) |
| LMS-T3 | **RESOLVED** — Section-targeted teacher rows using an audience bridge (model D); Offering-only teacher write authority rejected |
| LMS-T4 | **RESOLVED** — User actor, Employee resource owner and TeachingAssignment remain separate facts |
| LMS-T5 | **RESOLVED** — co-teachers may read applicable published rows; owner-only writes |
| LMS-T6 | **RESOLVED** — no transfer on handover; successor reads published applicable rows; admin handles clean-up |
| LMS-T7 | **RESOLVED** — admin/legacy rows have a NULL owner and Offering-wide meaning |
| LMS-T8 | **RESOLVED** — reads broader than writes (§34.7) |
| LMS-T9 | **RESOLVED** — School-local current date for teacher LMS authorization |
| LMS-T10 | **RESOLVED** — Documents delegate to parent LMS authorization for teacher access |
| LMS-T11 | **RESOLVED** — no fabricated legacy owner/audience backfill |
| LMS-T12 | **RESOLVED** — separate `lms.content.teacher` and `lms.assignments.teacher` |
| LMS-T13 | **RESOLVED** — split implementation checkpoints (§34.13) |

No owner decision remains open.

### 34.13 Implementation sequence

| Checkpoint | Scope | State |
|---|---|---|
| **TCH.5A** | LMS teacher ownership contract (docs only) | **PUBLISHED / CLOSED** |
| **TCH.5B** | LMS ownership & audience persistence foundation: owner fields, audience bridges, structural integrity, RLS/immutability, the parent-authorization seam; the Sensitive re-tier. **No** teacher capability or access | **CLOSED** (§35) |
| **TCH.5C** | Learning Content teacher adoption: `lms.content.teacher`, owned reads/writes, teacher API/UI, attachment integration | **CLOSED** (§36) |
| **TCH.5D** | Assignment teacher adoption: `lms.assignments.teacher`, owned reads/writes, teacher API/UI, attachment integration | **CLOSED** (§37) |
| **TCH.6** | TCH closure audit | **CLOSED** (§38) |

Each implementation checkpoint is separately authorized. LMS Submission
stays outside every one.

## 35. TCH.5B implementation (as built)

TCH.5B (2026-09-30) builds the persistence and parent-authorization
foundation §34 froze. **No LMS teacher capability or teacher access exists
yet.** No `lms.*.teacher` capability, teacher route, page or attachment path
exists; the Teacher role is unchanged; administrators use LMS exactly as
before. Submission stays cancelled.

**Persistence.** One migration adds the same shape to both
`learning_content` and `assignments`
(`database/migrations/2026_11_05_090000_add_lms_ownership_and_section_audiences.php`).

- **Parent columns:**
  - `owner_employee_id` (nullable) — composite FK
    `(owner_employee_id, school_id) → employees(id, school_id)`;
  - `ownership_txid` (`xid8`, nullable).
- **New parent key:** `(id, school_id, subject_offering_id)`, for the
  bridge.
- **Audience bridges:** `learning_content_section_audiences` and
  `assignment_section_audiences`.
  - Columns: `school_id`, the parent id, `subject_offering_id`,
    `academic_year_id`, `campus_id`, `grade_level_id`, `section_id`,
    `created_at`.
  - `unique (parent, section_id)`, forced RLS.
  - The runtime role holds `SELECT, INSERT` only
    (`TenantRls::makeAppendOnly`).
- **Structural context (CLAUDE.md rule 70).** Three composite FKs per
  bridge pin an audience Section to its parent's Offering context:
  - parent `(id, school_id, subject_offering_id)`;
  - `subject_offerings_context_unique` via `(subject_offering_id,
    school_id, academic_year_id, campus_id, grade_level_id)`;
  - `sections_context_unique` via `(section_id, school_id,
    academic_year_id, campus_id, grade_level_id)`.

  A Section of another School, grade, campus or year, or an audience row
  naming another Offering, is refused by the database.
- **Delete semantics.** These FKs are NO ACTION (checked at statement end)
  rather than RESTRICT. A School's cascade removes children and parents
  together; any lone deletion is still refused.
- **Models:** `LearningContentSectionAudience`, `AssignmentSectionAudience`
  (`BelongsToSchool`), plus a `sectionAudiences()` relation on each parent.
  `owner_employee_id` is not fillable.

**Valid states, database-enforced** (the journal-posting pattern of
`2026_08_31_090300`):

| State | Owner | Audience rows |
|---|---|---|
| Offering-wide (legacy/admin) | NULL | 0 |
| Teacher-owned | an Employee of the School | ≥ 1, written by the creating transaction |

- **Owner NULL with audience rows is refused.** An insert-guard trigger on
  each bridge checks this.
- **An owner with zero audience rows is refused at COMMIT.** A
  `DEFERRABLE INITIALLY DEFERRED` constraint trigger checks this; a failed
  commit surfaces as a raw `PDOException`.
- **The audience is written only in the creating transaction.**
  `ownership_txid` is set by an unconditional BEFORE INSERT trigger to
  `pg_current_xact_id()` for an owned row, NULL otherwise, overwriting any
  caller value. The bridge guard requires it to equal the current
  transaction. A savepoint does not change it. A later transaction's insert
  is refused, even one that also updates the parent.
- **Owner immutability (every role).** Any change to `owner_employee_id` or
  `ownership_txid` is refused: teacher → other, teacher → NULL,
  NULL → teacher. Every other field keeps its ordinary LMS rules, and the
  existing lifecycles are unchanged.
- **Audience immutability.**
  - An update trigger binds every role.
  - The runtime role has no UPDATE or DELETE.
  - An owned parent cannot change its Offering (the parent FK).
- **RLS at commit.** The trigger functions are SECURITY INVOKER and read the
  same School's rows under the caller's tenant context (the journal
  precedent). An owned row must therefore be checked or committed with its
  School context set. Without it, the deferred check fails closed.

**Application layer (LMS, internal).**
- `Ownership\SectionAudience`: the owner Employee plus one or more distinct
  Sections.
- `LearningContentService::create()` and `AssignmentService::create()` take
  an optional `SectionAudience`. With one, they write the owner and, through
  `Ownership\SectionAudienceWriter`, every audience row in the same
  transaction.
  - The writer refuses a Section that is not an active Section of the
    Offering's context (`LMS_AUDIENCE_SECTION_OUTSIDE_OFFERING`, 422).
  - A foreign owner is translated from the FK
    (`LMS_OWNER_EMPLOYEE_INVALID`, 422).
  - The audit metadata gains `ownerEmployeeId` and `audienceSectionIds`.
- **No transport passes a `SectionAudience`** (architecture guard).
  Administrative creation still writes Offering-wide rows.
- **`Ownership\LmsResourceOwnershipReader`** returns `LmsResourceOwnership`:
  owner, audience, `isOfferingWide()`/`isEmployeeOwned()`. It is a fresh
  read, never an authorization decision.
- **Serialization is unchanged.** The admin API and pages expose no
  ownership field, and OpenAPI is unchanged.

**Documents seam.** `App\Domain\LMS\Application\LmsParentResourceAuthorization`
(`authorizeRead`/`authorizeWrite(actor, school, parentType, parentId)`) is
the LMS-owned decision for LMS-owned Documents.
- `DocumentService`, `DocumentReadService` and `DocumentListingService` call
  it at all eight sites that previously named an LMS capability.
- The direction is the existing Documents → LMS one. LMS never depends on
  Documents, and Documents names no LMS capability, owner, audience or
  TeachingAssignment (architecture guard).
- **Today it applies exactly the Tier 1 rule** (`lms.content.view/.manage`,
  `lms.assignments.view/.manage`), so attachment behaviour is unchanged.
- TCH.5C/TCH.5D add the owned branch inside it.

**No backfill.** Every existing row stays owner NULL with no audience. Demo
data creates no teacher-owned row.

**Rollback.** `down()` probes with CHECK constraints (RLS-proof, because
constraint validation scans every row). It refuses while any owned row or
audience row exists ("tch5b rollback: refusing"), changing nothing.

Evidence on the DDEV database (`pg_dump --schema-only`):
- before migrating vs after rollback: **byte-identical**;
- after migrating vs after re-applying: **byte-identical**.

`LmsOwnershipMigrationRollbackTest` proves the refusal for both resources,
and the clean down/up with only Offering-wide data.

**Classification.** Learning Content and Assignment are **Sensitive**
(`docs/security/DATA-CLASSIFICATION.md`). This is not a legal gate. There is
no new LMS legal item; E21 is unchanged.

**Tests.**
- `LmsOwnershipDatabaseInvariantsTest` (raw SQL, runtime role, both
  resources): states, cardinality, owner and audience immutability,
  cross-School owner and Section, grade/campus/year/Offering context, RLS.
- `LmsOwnershipCommitSemanticsTest`: real commits, later-transaction
  refusals, the admin-role update trigger, the lifecycle of an owned row
  through the services.
- `LmsOwnershipPersistenceTest`: services, reader, errors, API
  serialization.
- `LmsOwnershipMigrationRollbackTest`.
- `LmsOwnershipArchitectureGuardTest`.
- Updated: the LMS column guards, and `ElevationRlsIsolationTest` (forced-RLS
  tables 166 → 168).

**Next:** TCH.5C — Learning Content teacher adoption (not implemented).

## 36. TCH.5C implementation (as built)

TCH.5C (2026-09-30) builds §34 for **Learning Content only**. **Learning
Content teacher adoption is implemented. Assignment teacher access remains
unimplemented** (TCH.5D). **Submission remains cancelled.** Learning
Content stays Sensitive (§35). There is no new legal gate, and TCH-L1
remains Attendance-only.

**Capability and role.**
- New owned-scope capability `lms.content.teacher`.
- The `teacher` role now carries exactly `curriculum.delivery.teacher`,
  `attendance.teacher` and `lms.content.teacher`.
- `school_admin` also holds it, only for no-escalation grantability (§31).
  It already holds `lms.content.manage`, and Tier 1 wins wherever both
  apply. `principal` does not hold it.
- There is no `lms.assignments.teacher`. Teachers get no
  `lms.content.view/.manage`, no `lms.assignments.*`, no
  `teaching.assignments.*` and no `students.view`.
- No code reads the role key. A test proves a non-`teacher` role carrying
  the capability works the same.

**The executable formula** (`App\Domain\LMS\Application\TeacherLearningContentAccess`,
`TeacherLearningContentScope`, `TeacherLearningContentGuard`).

Common to every rule: `lms.content.teacher`, an ActingEmployee, and
TeachingAssignment coverage on the **School-local current date**
(`ActingEmployee::asOf`). Never `created_at`, a publication or audit
date, `due_on` or the Timetable.

| Operation | Rule |
|---|---|
| **create** | the owner is the ActingEmployee (server-derived; any owner field in the request is ignored) AND ≥ 1 audience Section AND a current TeachingAssignment for **every** audience Section × the row's Offering. An Offering with no taught Section is 404; an untaught or foreign Section, including one of several, is 422 `LMS_AUDIENCE_SECTION_NOT_TAUGHT`. |
| **write** (edit, publish, archive, re-publish) | the row is visible (else 404) AND `owner_employee_id` = ActingEmployee (else 403 `LEARNING_CONTENT_NOT_OWNED`) AND a current TeachingAssignment for **every** audience Section (else 422 `LEARNING_CONTENT_OUTSIDE_TEACHING_ASSIGNMENT`) |
| **read: own row, any status** | owner AND teaches **every** audience Section |
| **read: published teacher-owned row** | teaches **any** audience Section; the reader need not be the owner |
| **read: published Offering-wide row** (owner NULL) | teaches any Section of its Offering |
| **anything else** | 404: another teacher's draft or archived row, an unpublished Offering-wide row, an untaught class, another School, unknown or malformed ids |

The owner field and a TeachingAssignment never substitute for each other.
- **Co-teachers** read each other's published rows and never write them.
- **Hand-over:** the successor reads the predecessor's published rows for
  their Section and cannot edit, archive or re-publish them. The
  predecessor loses write access, and their own unpublished rows, when
  their assignment ends. Ownership never moves.
- **Multi-Section rows:** writable only while every audience Section is
  taught. Losing one keeps a published row readable (any Section), but
  never writable. The audience is never shrunk.
- **Ending a TeachingAssignment** changes no owner, audience or history.
- **Revoking the role** removes access through the normal cache
  invalidation, and deletes nothing.

**Service reuse and lock order.**
- The same `LearningContentService` runs everything. There is no teacher
  copy of the lifecycle.
  - `createOwned()` calls the guard inside its transaction, then the same
    private `insert()` as administrative creation. The row, owner and
    audience are written atomically (TCH.5B's deferred check).
  - `update()`/`publish()`/`archive()` take an optional
    `LearningContentWriteGuard`, run before the row lock.
- **Lock order:** School → membership → User → Employee → EmploymentRecord
  (`ActingEmployeeResolver::hold`) → the TeachingAssignment of each
  audience Section in **ascending Section id** (`TeachingOwnership::hold`,
  FOR SHARE) → the `learning_content` row → the Document row.
- The client's Section order never decides lock order.
  `TeacherLearningContentConcurrencyTest` proves it:
  - a creation naming the Sections in descending order waits on the lower
    Section's assignment;
  - meanwhile the higher Section's assignment can be ended without
    blocking.
- **Reads** use fresh, non-locking `resolve()`/`periods()`. Lists are
  filtered in SQL (`TeacherLearningContentScope::constrain()`).
- **Commit context.** An owned row's deferred audience check runs at
  COMMIT under RLS (§35), so the creating transaction commits inside the
  School context. Every request does: the School-route middleware holds
  context for the request, and `TenantContext::withSchool()` restores it.

**Documents.** `LmsParentResourceAuthorization` now carries the Learning
Content teacher branch. It applies only when the actor lacks the Tier 1
capability and holds `lms.content.teacher`.
- **Read** (list, metadata, content): the parent row's read rule. Otherwise
  404, the same as an unknown row.
- **Write** (upload, archive), in two steps:
  - `authorizeWrite()`: a fresh check before any bytes are stored;
  - `holdWrite()`: the authoritative check, called by `DocumentService`
    inside its write transaction. It runs the write guard; a refusal rolls
    back and the existing compensation removes the stored object.
- Reading a row never allows writing its attachments.
- **The Assignment branch is unchanged, Tier 1 only.** A teacher gets 403.
- Documents names no LMS capability, owner, audience or TeachingAssignment
  (architecture guard).

**Surfaces.**
- **API** under `/api/v1/schools/{school}/my/`, all with
  `capability:lms.content.teacher` + `private-no-store`, with no
  Idempotency-Key (as Tier 1):
  - `learning-content-contexts` (GET) — the taught Offerings, each with
    only the taught Sections, a self projection;
  - `learning-content` (GET list, POST create);
  - `learning-content/{id}` (GET, PATCH);
  - `…/{id}/publish`, `…/{id}/archive` (POST).

  They are in OpenAPI (`MyLearningContent`, `MyLearningContentContext`,
  `MyLearningContentCreateInput`), with regenerated shared types. The owner
  Employee is never serialized: responses carry `mine`, `offeringWide`,
  `canEdit` and the audience Sections.
- **Page** `/app/my-learning-content` ("My Learning Content").
  - It is linked from the dashboard by `lms.content.teacher`.
  - It lists the readable rows and creates Section-targeted rows (the
    picker shows only taught Sections).
  - It edits, publishes and archives the teacher's own rows; everything
    else is read-only.
  - A capability holder who is not an eligible Employee sees an empty page,
    and every write is refused.
  - There is no attachment UI (the Documents API, as for administrators),
    no Assignment page and no Submission.

**Audit and events.** The existing `lms.learning_content.*` events. The
actor is the User; the created event carries `ownerEmployeeId` and
`audienceSectionIds` (TCH.5B). No new event or outbox entry.

**Demo.** Kavya Reddy (production `teacher` role, G8-A Mathematics) holds
the three capabilities. The demo adds a published Offering-wide G8
Mathematics reading (School Admin) and her own G8-A draft, created by her
through the owned path. There is no Assignment teacher data.

**Tests.**
- `TeacherLearningContentAccessTest` (API matrix):
  - create with a server-derived owner; multi-Section every-Section;
    untaught or foreign audiences;
  - the lifecycle; owner-without-assignment and assignment-without-owner;
    partial multi-Section; hand-over;
  - Offering-wide read-only and draft denial; server-side list filtering;
  - contexts and Timetable independence; non-disclosure; the capability
    and a non-`teacher` role;
  - five ActingEmployee failures; role revocation; Tier 1 unchanged.
- `TeacherLearningContentDocumentsTest`: owner, co-reader, not-visible,
  lost teaching and Assignment branch.
- `MyLearningContentUiTest`.
- `TeacherLearningContentConcurrencyTest` (real processes):
  - an edit vs an assignment end, and one of two ends;
  - an attachment vs an assignment end;
  - a creation vs a suspension, unlink, archive and employment end, in both
    orders;
  - the lock-order proof.
- Updated: `TeacherRoleRegistryTest`, `LmsOwnershipArchitectureGuardTest`,
  `LearningContentArchitectureGuardTest` (route surface),
  `TeachingAssignmentArchitectureGuardTest` (LMS is the third adopter),
  `MyAttendanceUiTest` (nav) and `DemoDataBuilderTest`.

**Next:** TCH.5D — Assignment teacher adoption (not implemented).

## 37. TCH.5D implementation (as built)

TCH.5D (2026-10-01) builds §34 for **Assignments**. **Learning Content
teacher adoption is implemented. Assignment teacher adoption is
implemented. LMS Submission remains cancelled and outside TCH.**
Assignments stay Sensitive (§35). There is no new legal gate: E21 is
unchanged, and TCH-L1 remains Attendance-only.

**Capability and role.**
- New owned-scope capability `lms.assignments.teacher`.
- The `teacher` role now carries exactly `curriculum.delivery.teacher`,
  `attendance.teacher`, `lms.content.teacher` and `lms.assignments.teacher`.
- `school_admin` also holds it, only for no-escalation grantability (§31).
  It already holds `lms.assignments.manage`, and Tier 1 wins. `principal`
  is unchanged.
- Teachers get no `lms.*.view/.manage`, `teaching.assignments.*` or
  `students.view`.
- A non-`teacher` role carrying the capability works the same (tested).

**Lifecycle and the meaning of `closed`.** The lifecycle is unchanged:
`draft → published → closed → published`. Re-publication is the same
publish action, and publishing still requires a due date.
- `published` is the **only shared status**.
- `closed` is the retiring state (ADR 0039 §9: "`closed` already serves
  the retiring purpose" — there is no separate archive). It is therefore
  treated like Learning Content's `archived` and like `draft`: **owner-only**
  for teachers. That means the owner, while teaching every audience
  Section.
- No Student-facing meaning is added. Tier 1 reads of every status are
  unchanged.

**The formula.** The §36 Learning Content rule applies unchanged under
`lms.assignments.teacher`, on the School-local current date.
- **Create:** the owner is the ActingEmployee (any owner field in the
  request is ignored), with ≥ 1 audience Section, every one taught today.
  An untaught or elective Offering is 404 (TeachingAssignments are
  required-only, so electives stay Tier 1); an untaught Section is 422
  `LMS_AUDIENCE_SECTION_NOT_TAUGHT`.
- **Write** (edit, publish, close, re-publish):
  - visible, else 404;
  - owner, else 403 `ASSIGNMENT_NOT_OWNED`;
  - every audience Section taught, else 422
    `ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT`.
- **Read:** own rows in any status while teaching every Section; a
  published teacher row for any taught Section; a published Offering-wide
  row of a taught Offering; anything else is 404.
- **`due_on` is never an authorization date.** It stays informational and
  is validated against the Academic Year as before.
- **Visibility before validation.** The teacher controllers check
  visibility (fresh) before a write, so the service's pre-transaction
  `due_on` validation can never reveal a row the teacher may not read.

Co-teaching, hand-over, multi-Section behaviour, immutability and
revocation are exactly §36's.

**Shared implementation (refactor, behaviour unchanged).**
- **Scope:** `TeacherLmsScope` (abstract) holds the read rule and the SQL
  filter. `TeacherLearningContentScope` and `TeacherAssignmentScope` only
  name their table and bridge.
- **Guard:** `TeacherLmsGuard` (abstract) holds the create/write rule and
  the lock order. `TeacherLearningContentGuard` and `TeacherAssignmentGuard`
  supply the capability, the ownership read and their exceptions.
- **Contexts:** `TeacherTeachingContexts` is the shared audience-picker
  projection.
- Every TCH.5C test passes unchanged.

**Service reuse and lock order.**
- `AssignmentService::createOwned()` runs the guard first in its
  transaction, then validates `due_on`, then calls the same private
  `insert()` as administrative creation.
- `update()`/`publish()`/`close()` take an optional `AssignmentWriteGuard`,
  run before the row lock.
- Lock order is the §36 order: identity → TeachingAssignments in ascending
  Section id → the `assignments` row → the Document row.

**Documents.** `LmsParentResourceAuthorization` routes each parent kind to
its own owned capability: Learning Content → `lms.content.teacher`,
Assignment → `lms.assignments.teacher`.
- **Read** follows the parent's read rule.
- **Write** runs a fresh check before storage, then `holdWrite()` inside the
  Documents transaction.
- A role holding one kind's capability reaches only that kind's
  attachments (tested both ways).
- There is no Submission parent.

**Surfaces.**
- **API** under `/api/v1/schools/{school}/my/`, all
  `capability:lms.assignments.teacher` + `private-no-store`, with no
  Idempotency-Key:
  - `assignment-contexts`;
  - `assignments` (GET, POST);
  - `assignments/{id}` (GET, PATCH);
  - `…/{id}/publish`, `…/{id}/close`.

  They are in OpenAPI (`MyAssignment`, `MyAssignmentCreateInput`; the
  contexts reuse `MyLearningContentContext`), with regenerated shared
  types. The owner Employee is never serialized.
- **Page** `/app/my-assignments` ("My Assignments"), linked by the
  capability.
  - Taught classes only; create for taught Sections, with an optional due
    date.
  - Edit, publish and close the teacher's own rows; shared rows are
    read-only.
  - No Submission inbox, Student list, grading or attachment UI.
- **Teacher navigation:** My Curriculum Delivery, My Attendance, My
  Learning Content, My Assignments.

**Audit and events.** The existing `lms.assignment.*` events. The actor is
the User; the created event carries `ownerEmployeeId` and
`audienceSectionIds`. No new event.

**Demo.** Kavya Reddy holds the four capabilities. The demo adds a
published Offering-wide G8 Mathematics worksheet (School Admin) and her
own G8-A draft homework, created by her. There is no Submission data.

**Tests.**
- `TeacherAssignmentAccessTest` (19): §36's matrix, plus `closed` being
  owner-only, `due_on` independence, the elective boundary, and 404
  before validation.
- `TeacherAssignmentDocumentsTest` (6): includes the Learning Content and
  admin regression, and per-kind capability.
- `MyAssignmentsUiTest` (4).
- `TeacherAssignmentConcurrencyTest` (5, real processes):
  - an edit vs a TeachingAssignment end, and one of two ends;
  - an attachment vs an end;
  - a creation vs a suspension, unlink, archive and employment end, in both
    orders;
  - the reverse-client-order lock proof.
- Updated: `TeacherRoleRegistryTest`, `LmsOwnershipArchitectureGuardTest`
  (shared primitives, Submission excluded, 14 owned operations pinned to
  OpenAPI), `AssignmentArchitectureGuardTest` (route surfaces),
  `TeachingAssignmentArchitectureGuardTest`, `MyAttendanceUiTest`,
  `TeacherLearningContentDocumentsTest` and `DemoDataBuilderTest`.
- **Test hygiene fix.** The TCH.5C/TCH.5D race tests now delete their whole
  tenant storage directory. The isolated suite runs as root, and leftover
  root-owned directories on the bind mount broke host tooling.

**Next:** TCH.6 — TCH closure audit (§38, closed).

## 38. TCH.6 — closure audit and programme closure (2026-10-01)

**Status: CLOSED. TCH — Teacher Identity & Ownership-Based Authorization —
is DEVELOPMENT CLOSED.** Baseline `origin/main` `2d5bc9c` (regression
checkpoint `2d5bc9c`, 7,095 tests). The audit was repository-wide and
read the code, not the completion reports. It found **one closure defect**,
fixed here (§38.3), and no authorization bypass. Development closure is
**not** production clearance (§38.8).

### 38.1 Final scope

TCH built exactly:
- the ActingEmployee identity boundary (TCH.1);
- TeachingAssignment ownership (TCH.2);
- the production `teacher` role (TCH.3, grown one adopter at a time);
- owned teacher access to Curriculum Delivery (TCH.3), Attendance (TCH.4),
  Learning Content (TCH.5C) and Assignments (TCH.5D), on the LMS
  owner/audience persistence (TCH.5B).

Outside TCH, unchanged:
- **Cancelled:** LMS Submission (ADR 0039 addendum). There is no Submission
  model, table, route, capability, UI or Documents parent (guarded by
  `LmsOwnershipArchitectureGuardTest`).
- **Deferred by design:** class teacher / homeroom (D-02) and elective
  ownership (D-05). Elective LMS stays Tier 1, because a TeachingAssignment
  is for a required Offering only.
- **Other or future programmes:** Lesson Planning; RES (StudentMark,
  results, report cards, transcripts); POR; HRX (own payslip, leave,
  manager hierarchy, Employee self-service — ActingEmployee is reusable,
  but TCH uses none of these); tenant-custom roles; generic non-teaching
  staff roles. `teacher` is the only production staff role TCH added.

### 38.2 Final contracts as built

**Identity.** One resolver, `ActingEmployeeResolver`: authenticated User →
School operational → membership `active` → User not disabled → the
Employee of `employees(school_id, user_id)` → `record_status = 'active'` →
exactly one EmploymentRecord with `starts_on <= asOf <= ends_on` (open end
allowed) and status `active` or `notice_period`.
- `asOf` is the School-local date.
- Zero or two eligible rows fail closed.
- `resolve()` is fresh; `hold()` throws outside a transaction and takes
  School → membership → User → Employee → EmploymentRecord `FOR SHARE`.
- Nothing is cached. No other code resolves a User's Employee
  (`ActingEmployeeArchitectureGuardTest`).
- Link and unlink are explicit, locked and audited. The generic update and
  the PATCH API refuse `user_id`, and import goes through the same link
  primitive.

**Ownership.** `teaching_assignments` is Employee × Section × required
SubjectOffering × year/campus/grade context × an inclusive date range.
- Future rows are allowed; co-teaching is another key; cover is a short
  dated row.
- Rows end and are never deleted (forced RLS, `revokeDelete`).
- A history trigger freezes the identity columns and permits one shortening
  end.
- Composite foreign keys enforce the same-School Employee and the
  same-context Section and Offering.
- There is no "one open row" index. Overlap is refused under the
  transaction-scoped advisory lock
  `teaching.assignment:{school}:{employee}:{section}:{offering}`.
- `TeachingOwnership::periods()` is fresh. `hold()` requires exactly one
  covering row `FOR SHARE`.
- The module never reads the Timetable, ActingEmployee or a consumer.

**Teacher role.** Exactly `curriculum.delivery.teacher`,
`attendance.teacher`, `lms.content.teacher` and `lms.assignments.teacher`.
- No `*.view`/`*.manage` of those modules, no `teaching.assignments.*`, no
  `students.view`, and nothing from HR, finance, payroll, settings or role
  governance.
- `school_admin` also holds the four capabilities, only so the
  no-escalation rule lets it grant the role. It already holds every Tier 1
  capability, and Tier 1 wins wherever both apply. `principal` holds none of
  the four.
- No code reads a role key. Guards forbid it in `app/` and
  `resources/js/`, and a non-`teacher` role carrying a capability is tested
  to behave the same.
- The demo Teacher (Kavya Reddy) uses the production role. No `demo.teacher`
  exists.

**New TCH capabilities (exactly six):** `teaching.assignments.view`,
`teaching.assignments.manage`, `curriculum.delivery.teacher`,
`attendance.teacher`, `lms.content.teacher` and `lms.assignments.teacher`.

**Authorization matrix.** No role is ranked. AE = verified ActingEmployee;
TA = TeachingAssignment ownership.

| Surface | School Admin | Principal | Teacher |
|---|---|---|---|
| TeachingAssignment admin | `teaching.assignments.view/.manage` (no AE/TA) | same | none |
| Curriculum Delivery | Tier 1 `curriculum.delivery.view/.manage` (no AE/TA) | same | `curriculum.delivery.teacher` + AE (today) + TA on the delivery's own dates |
| Attendance | Tier 1 `attendance.view/.manage` (no AE/TA) | same | `attendance.teacher` + AE (today) + TA on `attendance_date`; **production gate TCH-L1** |
| Learning Content | Tier 1 `lms.content.view/.manage` (no AE/TA) | same | `lms.content.teacher` + AE + owner/audience rule + TA (School-local today) |
| Assignment | Tier 1 `lms.assignments.view/.manage` (no AE/TA) | same | `lms.assignments.teacher` + AE + owner/audience rule + TA (School-local today) |

**LMS owner/audience rule** (both kinds; §34, §36, §37):
- **Create, write, own-row read:** owner Employee = AE AND a TA for
  **every** audience Section.
- **Published teacher row:** a TA for **any** audience Section.
- **Published Offering-wide row:** a TA for any Section of the Offering.
- **Never visible to teachers:** admin drafts, closed Assignments and
  archived Learning Content.
- **Lock order:** TAs are held in ascending Section id, never in the
  client's order.
- **Database:** the owner and audience are immutable and RLS-forced. The
  valid states (owner NULL + no audience, or owner + ≥ 1 audience) are
  enforced, and `down()` refuses while owned data exists.
- **Attachments:** Documents asks `LmsParentResourceAuthorization`, per
  parent kind with its own capability (no cross-kind substitution).

### 38.3 Closure defect found and fixed — non-identical not-found answers

**Finding.** D-13/§18 require an unowned resource to answer like an unknown
one. Every status was already 404, but on several owned paths the **error
body** differed, because Laravel echoes a `ModelNotFoundException`'s model
and ids into the API message:
- **Attendance correct:** an unowned register answered
  `…[AttendanceSession] <session-id>` against `…[AttendanceRecord].` for an
  unknown record. It disclosed that the record exists and **leaked the
  parent register's id**.
- **Attendance submit and roster preview:** an unowned class answered
  differently from an unknown TimetableEntry.
- **Curriculum Delivery update/transition:** an unowned delivery's message
  carried its id.
- **Documents** (`/documents/{id}`, `/content`, `/archive`, and the LMS
  list/upload routes): for a teacher, an LMS parent they may not see
  answered with the LMS model and **the hidden parent row's id**, against
  `DOCUMENT_NOT_FOUND` / `DOCUMENT_OWNER_NOT_FOUND` for unknown ids.
- **Web `/app/my-curriculum-delivery/{id}`:** a malformed id reached the
  database as a uuid cast (an error, not a 404).

**Risk.** Low: ids are UUIDv7 and nothing was readable or writable. But it
was an existence oracle and an id leak on Sensitive surfaces, contrary to
the contract.

**Fix (minimal, no behaviour change for permitted actors):**
- **Attendance:** the hidden case is mapped onto the unknown-id answer by
  the service that owns that answer (`AttendanceSubmissionService`,
  `AttendanceCorrectionService`, the roster preview). The guard no longer
  names a register id, and it still never names a TimetableEntry.
- **Curriculum Delivery:** `TeacherDeliveryGuard` throws the same id-less
  not-found as the service's unknown path.
- **Documents:** Documents maps an LMS-port not-found onto its own
  `DOCUMENT_NOT_FOUND`/`DOCUMENT_OWNER_NOT_FOUND`, naming only the id the
  caller sent. An unknown archive now also answers `DOCUMENT_NOT_FOUND`, the
  same as show/content (it was an empty 404). LMS still never depends on
  Documents.
- **Web Curriculum Delivery:** a malformed id is a 404.

**Tests:** whole-body comparisons (`requestId` excluded, the requested id
normalized), plus "the hidden id never appears":
- `TeacherAttendanceAccessTest::an_unowned_class_or_register_answers_with_the_unknown_ids_exact_body`;
- `TeacherCurriculumDeliveryAccessTest::an_unowned_delivery_or_class_answers_with_the_unknown_ids_exact_body`;
- `MyCurriculumDeliveryUiTest::a_malformed_unknown_or_unowned_delivery_id_is_not_found_on_the_page`;
- the strengthened `attachments_of_a_row_the_teacher_may_not_read_are_not_found`
  in both `Teacher*DocumentsTest` files.

All five failed on the unfixed code and pass on the fix.

**Intentional differences that remain:**
- A syntactically malformed id is refused before any lookup with an empty
  404 message. It depends on no School data.
- After visibility is established: 403 `*_NOT_OWNED`, 422
  `*_OUTSIDE_TEACHING_ASSIGNMENT` / `LMS_AUDIENCE_SECTION_NOT_TAUGHT`, the
  existing 409 conventions, and 403 `HR_ACTING_EMPLOYEE_UNAVAILABLE` for an
  actor who is not an eligible Employee.
- Request-only checks that run before the guard (a future
  `attendance_date`, duplicate enrollments, an out-of-year `due_on` on a
  visible Assignment) answer 422 from the request alone, disclosing nothing.

### 38.4 Audit results by area (all PASS after §38.3)

- **Identity (TCH.1):**
  - one resolver;
  - the closed eligibility rule, database-constrained;
  - `hold()` transaction-only, with lock order unchanged;
  - no identity cache;
  - link governance and import closed;
  - races proven by real two-process tests in both orders
    (`ActingEmployeeConcurrencyTest`): link vs suspension; unlink, archive,
    employment end and suspension vs `hold()`.
- **Ownership (TCH.2):**
  - schema, FKs, RLS, `revokeDelete`, history trigger, advisory lock and
    inclusive overlap all as built;
  - races proven (`TeachingAssignmentConcurrencyTest`): create vs create,
    end vs end, end vs a waiting create, employment end and archive vs
    create;
  - raw-SQL invariants proven (`TeachingAssignmentDatabaseInvariantsTest`):
    cross-School Employee/Section/Offering, wrong context, RLS, no context,
    no DELETE, no repointing.
- **Curriculum Delivery (TCH.3):**
  - the chain, the date model and SQL list filtering;
  - writes hold inside the service transaction, with a re-check on a
    concurrent date change;
  - no `teacher_id`; Tier 1 unchanged;
  - races vs end, suspension, unlink, archive and employment end, both
    orders; `CurriculumDeliveryTransitionConcurrencyTest` unchanged.
- **Attendance (TCH.4):**
  - the chain, `attendance_date` ownership and an owned-only roster;
  - no `students.view`;
  - `teacher_id` (timetable and session) is never authority: every use in
    `app/` is a schedule write, a provenance snapshot, a display, or a
    Tier 1 admin filter;
  - cover via assignment works, and a timetabled teacher without one is
    refused;
  - the teacher submit is deliberately not `idempotent`;
  - race coverage is as corrected in §32.
- **LMS (TCH.5B–5D):**
  - states, owner and audience integrity, RLS and rollback refusal
    (`LmsOwnership*Test`);
  - SQL and PHP read rules agree;
  - every write holds inside the service transaction in ascending Section
    order (reverse-client-order proofs, real processes);
  - co-teaching and hand-over tested for both kinds;
  - `due_on` is never an authorization date;
  - `closed` is owner-only.
- **Cross-cutting:**
  - no role-name authorization;
  - `CapabilityResolver` is the only capability cache and has no HR,
    TeachingAssignment or LMS dependency, and role grant/revoke clears it;
  - ActingEmployee, ownership and owner/audience are never cached;
  - all 26 `/my/` operations carry the owned capability, `private-no-store`
    and OpenAPI + shared types, and the `/app/my-*` pages are
    `no-store, private`;
  - teacher navigation is capability-driven: the four "My …" links only,
    with no Students, TeachingAssignment, LMS admin, Timetable or
    Submission links;
  - dependency directions match DOMAIN-MAP (HR ← consumers;
    TeachingAssignments ← consumers; Documents → LMS port; Timetable never
    authority).

### 38.5 Audit and events

- **TCH audit actions:** `employee.user_linked`, `employee.user_unlinked`,
  `teaching_assignment.created` and `teaching_assignment.ended`.
  - Metadata is ids, dates and closed reasons only.
  - The actor is always the authenticated User, never an Employee.
- **Reused audit actions:**
  - the role grant/revoke events;
  - `curriculum_delivery.*`;
  - the Attendance submission and correction events;
  - `lms.learning_content.*` and `lms.assignment.*` (the created events add
    `ownerEmployeeId`/`audienceSectionIds`);
  - `document.*`.
- **No new domain or outbox event.** Link and unlink keep emitting the
  pre-existing `EmployeeUpdated` (`changedFields: ['user_id']`). Nothing is
  webhook-registered.

### 38.6 Non-blocking observations (recorded, not defects)

- **Test gaps, behaviour verified by reading:**
  - no teacher test exercises Curriculum Delivery *reopen* or the
    concurrent date-change re-check;
  - no list test covers a draft multi-Section LMS row after one Section
    ends;
  - `TeachingOwnership::hold()` failing closed on two covering rows is
    tested only through consumers (the overlap rule makes that state
    unreachable);
  - correction is raced against two of the five revocations (§32
    corrected).
- **Lock-order wording.** `TeachingAssignmentService::create()` takes its
  key advisory lock before its Employee/EmploymentRecord `FOR SHARE` reads.
  No cycle exists: HR never locks assignments, and consumers take only
  `FOR SHARE` there. §20's order describes the consumer side.
- **`EmployeeService::archive()`** relies on its `UPDATE` row lock (which
  does conflict with `FOR SHARE`) rather than an explicit
  `lockForUpdate()`. That is race-safe for authorization, but two concurrent
  archives could audit twice.
- **Guards that could be widened:**
  - nothing forbids TeachingAssignments from importing a consumer module in
    future;
  - the role-name guard forbids `teacher` comparisons but not
    `principal`/`school_admin` ones (none exist).
- **Pre-existing Documents → LMS model lookups** (existence checks, Phase
  0I.3) remain beside the authorization port.
- **Test hygiene.** The TCH.5C/5D race tests clean their tenant storage;
  the other race helpers commit and purge their own rows. The
  pre-existing (Phase 0H.3B, not TCH) `CurriculumDeliveryTransitionConcurrencyTest`
  leaves one committed User and one non-system `test.capability_grant.<uuid>`
  role after its School cleanup. This is classified **harmless, not fixed**:
  the keys are unique, no test counts non-system roles, and the full
  isolated suite resets the database.

### 38.7 Decision audit

| Decision | Final state |
|---|---|
| T1 Production Teacher role | **IMPLEMENTED** (TCH.3–TCH.5D) |
| T2 Non-teaching staff roles | **OUTSIDE TCH** |
| T3 Tenant-custom roles | **OUTSIDE TCH** (existing future decision) |
| T4 Identity vs ownership boundary | **IMPLEMENTED** (HRX outside TCH) |
| D-01 TeachingAssignment | **IMPLEMENTED** (TCH.2) |
| D-02 Class teacher / homeroom | **DEFERRED BY DESIGN** |
| D-03 Timetable not authority | **IMPLEMENTED** (architecture guards) |
| D-04 Lifecycle | **IMPLEMENTED** (limitation: a future assignment cannot be cancelled, §30) |
| D-05 Electives | **DEFERRED BY DESIGN** (elective LMS stays Tier 1) |
| D-06 Adoption order | **IMPLEMENTED** (Curriculum Delivery → Attendance → LMS) |
| D-07 Owned-scope capabilities | **IMPLEMENTED** (four `*.teacher`) |
| D-08 Employment eligibility | **IMPLEMENTED** |
| D-09 Link governance | **IMPLEMENTED** |
| D-10 ActingEmployee in HR | **IMPLEMENTED** |
| D-11 Assignment administration | **IMPLEMENTED** |
| D-12 MFA | **IMPLEMENTED as clarified** (no new MFA; RES outside TCH) |
| D-13 Non-disclosure | **IMPLEMENTED** (remediated by TCH.6, §38.3) |
| D-14 LMS ownership | **IMPLEMENTED** (TCH.5A–TCH.5D) |
| D-15 Temporary cover | **IMPLEMENTED** (dated assignment) |
| D-16 Timetable ↔ assignment consistency | **IMPLEMENTED** (no coupling); the optional mismatch report is **DEFERRED** |
| D-17 Co-teaching | **IMPLEMENTED** |
| TCH-L1 | **OPEN PRODUCTION GATE** (teacher Attendance) |
| E21 | **OPEN PRODUCTION GATE** (existing ADR 0058 retention item) |

The §27 findings for other programmes:
- **Resolved:** `LMS.md` authorship wording (corrected at TCH.5B);
  `HR.md` `record_status` (annotated at TCH.6); the link-check messages
  (TCH.1); ADR 0059's catalog line (annotated at TCH.6).
- **Unchanged:** the production least-privilege finding, for a future staff
  programme.

### 38.8 Production blockers (development closed ≠ production cleared)

| ID | State | Affects |
|---|---|---|
| **TCH-L1** (ADR 0058 **E33**, §39) | **OPEN** — no legal/compliance determination is recorded; none is implied by the implementation or its tests | Production enablement of teacher Attendance (`attendance.teacher`). Development blocker: no |
| **E21** | **OPEN** (ADR 0058) | Retention of link history, TeachingAssignment history and LMS owner/audience history |

- No new legal or security blocker was found. Learning Content and
  Assignments are Sensitive, a classification rather than a gate.
- Production release gating (ADR 0052) applies to TCH as to everything
  else.

### 38.9 Closure evidence

- **Focused closure suites:**
  - `tests/Feature/{HR, TeachingAssignments, Authorization,
    CurriculumDelivery, Attendance, LMS, Documents, Tenancy, Postgres,
    Demo, App, Identity/Staff}`;
  - these include every TCH concurrency, architecture-guard, raw-RLS and
    demo test;
  - **3,225 tests, 0 failures** (31,928 assertions).
- **Full isolated regression** on the closure code:
  - command: `SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test --reset-db`,
    then `SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test`;
  - result: **7,098 tests, 0 failures, 1 skip** (175,960 assertions; 7,095 at `2d5bc9c` plus the three new TCH.6 tests);
  - the one skip is the deliberate ESI-12 legal gate.
- **The closure commit is the new regression checkpoint** (counter 0/5).

No further TCH implementation checkpoint remains.

## 39. Production readiness and open gates (post-closure audit, 2026-10-01)

**Status: read-only audit of `ff867e6`, docs only.** No TCH feature is
reopened, and no code changed. **Result: PRODUCTION READY EXCEPT DOCUMENTED
EXTERNAL GATES.** TCH's implementation and technical controls are complete.
Two external decisions remain, and both are mandatory production blockers
in the ADR 0058 §6 register:
- **E33 = TCH-L1** (legal) — teacher Attendance;
- **E21** (legal; retention) — platform-wide, including TCH history.

Production also stays subject to the platform's other O1 items (ADR 0058:
E02, E03, E05–E20, E22, E23, E29–E32). Those are not TCH items and are
listed only as context. Development closure (§38) is not production
clearance.

### 39.1 Blocker types (never combined)

| Item | Type | TCH-specific? | Blocks |
|---|---|---|---|
| TCH-L1 (ADR 0058 E33) | Legal/compliance decision required | Yes | Production use of the `attendance.teacher` surface, and so (§40) any production `teacher` role grant |
| E21 | Legal retention-policy decision required (an external policy decision; any later purge is owning-module implementation) | No — platform-wide; TCH history is part of "others used in v1" | ADR 0058 O1, and so any production deployment decision (ADR 0058 §2, §4.12) |
| Role-bundle coupling (§39.3) | Owner/product decision — **RESOLVED by §40** (no production `teacher` grants while E33 is open; no role split or gate) | Yes | — (decided) |
| Technical implementation | None required | — | — |
| Documentation | Done by this section (ADR 0058 E33 + Note; roadmap; ATTENDANCE.md) | — | — |

### 39.2 TCH-L1 — what the reviewer assesses (facts, no conclusion)

**Exact question (§26).** Does expanding access to identifiable Student
attendance, from the current administrative actors to assigned teachers,
require an updated children's-data/privacy assessment, processing record or
equivalent production approval?

**Data teachers see.** Only for a Section + required SubjectOffering they
own on the register's `attendance_date`:
- the register header: date, class, period, the timetabled teacher's id and
  name (provenance), and the submitter's user id;
- per Student: enrollment id, Student id, roll number, composed full name,
  status (`present`/`absent`/`late`/`excused`) and `correctedAt`;
- for an owned class on a date, the roster preview (enrollment id, Student
  id, roll number, full name).

Nothing else is exposed:
- no reason, note or health data (none exists in the module);
- no Guardian data;
- no Student directory (`students.view` is not granted).

The data is **Sensitive** (`DATA-CLASSIFICATION.md`), unchanged by TCH.4.

**Actions teachers take.**
- Submit one complete register for an owned class and date. It is refused
  by the database if one exists already.
- Correct one record's status with an expected-status compare-and-swap.
- Nothing else: no delete, no edit of the register header, no
  administrative Attendance page.

**Who and where.**
- Only School staff with an eligible Employee record: no Guardian or
  Student access, and no portal.
- Nothing leaves the tenant. Attendance has no outbox event, no webhook
  registration and no export, and every row is RLS-scoped to its School.
- This is purely a widening of the role able to reach existing data. There
  is no new data, purpose, recipient or transfer.

**Technical controls already in place.** These are the reviewer's inputs:
- **Capability.** The `attendance.teacher` capability is least-privilege,
  with no Tier 1 Attendance and no `students.view`.
- **Verified ActingEmployee:**
  - active membership and enabled User;
  - linked active Employee;
  - exactly one `active`/`notice_period` employment on the School-local
    date.
- **Ownership.**
  - TeachingAssignment ownership of the exact Section + SubjectOffering on
    the register's `attendance_date`;
  - the Timetable confers nothing;
  - cover works only through a dated assignment.
- **Server-side filtering.** Lists, scheduled classes and roster preview
  are filtered on the server, owned classes only.
- **Non-disclosure.**
  - Another class, another School or an unknown id answers the identical
    404 (§38.3).
  - Responses are `private, no-store`, and pages are `no-store, private`.
- **Audit.**
  - `attendance.session.submitted` and `attendance.record.corrected`, with
    the User as actor; a correction records the previous and new status.
  - Role grant and revoke are audited.
- **Revocation without TTL.** Revoking the role clears the capability
  cache. Suspension, unlink, Employee archive, employment end and
  assignment end refuse the next write: identity and ownership are held
  `FOR SHARE` inside the write transaction, proven by two-process tests.
- **Tenancy and integrity.**
  - Forced RLS on every table involved.
  - Composite same-School foreign keys.
  - Session headers are immutable after insert.
  - Records are never hard-deleted by the application.

**Production gating mechanism: A — documentation/process only.**
- No feature flag, configuration or environment switch disables teacher
  Attendance. Comments mark the routes and controllers as gated by TCH-L1.
- ADR 0063 deliberately treats TCH-L1 as a production-approval gate, not a
  runtime flag. This audit keeps that, and adds no flag.
- The repository's `FeatureFlagResolver` exists, but no ADR assigns it to
  TCH-L1.

### 39.3 The operational consequence of the role bundle

The only production School role carrying any `*.teacher` capability is
`teacher`, and it bundles all four (§38.2). There are no tenant-custom roles,
and `school_admin` already holds Tier 1. So, in production before E33 is
answered:
- granting `teacher` to anyone also enables teacher Attendance for their
  assigned classes;
- no supported path gives a teacher Curriculum Delivery or LMS access
  without it.

**Owner decision required** (not decided here). Before E33 is answered,
either:
- (a) grant no production `teacher` role; or
- (b) approve a separately reviewed change that decouples
  `attendance.teacher` from production teacher access, for example a
  production role variant without it, or a dedicated feature gate.

Option (b) would be new work under its own approval. **This audit implements
neither.**

**Resolved (owner decision, 2026-10-01, §40): option (a).** There are no
production `teacher` grants while E33 is open, and option (b) is declined.

### 39.4 The TCH-L1 decision record the authorized reviewer must complete

Record it as `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`, in
the shape of `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`. Then update §26
and ADR 0058 E33 by a dated, reviewed change (ADR 0058 §6). Engineering does
not fill in any field below.

```text
Decision:          Teacher Attendance production access — APPROVED /
                   REJECTED / APPROVED WITH CONDITIONS
Scope:             assigned teachers only (attendance.teacher + verified
                   ActingEmployee + TeachingAssignment on attendance_date);
                   no Student/Guardian access
Data:              identifiable Student attendance roster (enrollment id,
                   Student id, roll number, name) and status for owned
                   classes/dates (§39.2)
Actions:           submit an owned register; correct a record's status
Technical controls reviewed:  §39.2 list (and any reviewer additions)
Jurisdiction / policy basis:  to be supplied by the authorized reviewer
Conditions:        to be supplied by the authorized reviewer (e.g. any
                   required processing record, notice or assessment)
Approving authority:          role of the authorized reviewer
Decision date:     the actual date of the decision
Outcome:           APPROVED / REJECTED / APPROVED WITH CONDITIONS
Does not approve:  anything outside the stated scope
```

### 39.5 E21 — retention, as it applies to TCH

**Authoritative definition.** ADR 0058 §6, E21: "Retention decisions for v1
categories (mail, webhook deliveries, Documents, audit, others used in v1)".
It is Legal, Mandatory and `LEGAL_REVIEW_REQUIRED`. Its required evidence is
a "qualified decision recorded; settings implemented where they exist". It
blocks O1 (§4.12: mandatory before Phase 0O closeout; engineering never
invents periods). ADR 0042 §7 and §13 items 4–6 add three open legal
questions:
- the retention period per category, including audit ledgers;
- whether audit must survive School deletion, and legal holds;
- data-subject access, correction and erasure.

**Production impact (repository semantics, not a guess).** E21 is a
**platform-wide O1 blocker**. Under ADR 0058 §2 it therefore blocks
authorizing **any** production deployment, TCH included.
- It is not a TCH-specific gate.
- It does not block development, and it blocks no TCH surface separately.

**Retention and history matrix** (verified in code; "purge" = none
anywhere):

| Record | Class. | Immutability | Runtime DELETE | FK behaviour | History kept by | E21 |
|---|---|---|---|---|---|---|
| Employee↔User link (`employees.user_id`) | Sensitive | None on the column. Link and unlink are explicit and locked, and unlink overwrites with NULL | granted; no app path deletes an Employee | users RESTRICT; School CASCADE | **audit only** (`employee.user_linked/unlinked`, old and new ids). There is no link-history table | yes |
| `employment_records` | Sensitive | status CHECK only | granted; no app delete path | employees CASCADE; School CASCADE | the rows plus HR audit | yes (HR) |
| `teaching_assignments` | Sensitive | history trigger: identity frozen, one shortening end | **revoked** | Employee, Section, Offering and users RESTRICT; School CASCADE | the rows plus `teaching_assignment.*` audit | yes |
| LMS owner (`owner_employee_id`) | Sensitive | trigger, every role | granted on the parent tables; no app delete path | composite NO ACTION; School CASCADE | the row | yes |
| LMS Section audiences | Sensitive | trigger; append-only | **revoked** (with UPDATE) | NO ACTION; School CASCADE | the rows | yes |
| `attendance_sessions` / `attendance_records` | Sensitive | no trigger. The header is immutable by service. A record's `status` is overwritten on correction (no corrector column) | granted; no app delete path | RESTRICT to Employee, user, entry and enrollment; School CASCADE | the rows. The previous status and the corrector are **audit only** | yes |
| `curriculum_deliveries` | Confidential | lifecycle by CAS; dates correctable | granted; no app delete path | RESTRICT; School CASCADE | the rows. The actor is **audit only** | yes |
| `membership_role_assignments` (Teacher grants) | — | history guard; revoke, never delete | **revoked** | membership CASCADE; grantor and revoker declared `ON DELETE SET NULL`, **but the history guard refuses that change, so deleting a grantor or revoker User is refused** (corrected by E21.2) | the rows | yes |
| `school_audit_events` / `platform_audit_events` | (ADR 0042 open item 1) | append-only | **revoked** (with UPDATE) | actor users **SET NULL**; school audit School **CASCADE** | permanent today | yes |

*Update (E21.2B, 2026-10-01):* "purge = none" no longer holds for every
row above:
- Revoked role grants, ended TeachingAssignments, finished elevations and
  audit events now expire after 7 years through narrow database retention
  functions (`docs/security/E21-RETENTION-DETERMINATION.md` §5.1).
- The runtime role still cannot delete them directly.
- LMS owner/audience is never removed on its own.

**Lifecycle analysis (production paths only).**
- **User deletion.** No application path deletes a `users` row. If one were
  deleted out of band:
  - RESTRICT blocks it when the User is linked to an Employee, created or
    ended an assignment, or submitted Attendance;
  - otherwise it would CASCADE the User's memberships and grants, and
    SET NULL the audit actor (a referential action bypasses the REVOKE).

  That is the open ADR 0042 item 6 (erasure) question, not a TCH defect.
- **Employee archival** changes only `record_status`, and access stops
  through ActingEmployee. Assignments, LMS owner and audience rows,
  Attendance provenance and audit are untouched. No path deletes an
  Employee.
- **Membership removal** is suspension plus grant revocation. No path
  deletes a membership.
- **School deletion.** The runtime role cannot delete a School (DELETE
  revoked; no `archived` transition; `SchoolLifecycleService`: "no archive
  and no delete"). Only an admin-connection test helper deletes one,
  cascading every TCH table and `school_audit_events`. Whether history must
  outlive a School is the open ADR 0042 item 5. **Production behaviour
  today: School deletion is not an operation.**
- **LMS parents ageing.**
  - Owner and audience rows never change.
  - Teacher reads use only TeachingAssignment date coverage, with no
    Section, Offering or Year status filter, so a historical owner keeps no
    access once their assignment ends.
  - Tier 1 reads are unaffected by an archived Section, an inactive
    Offering or a closed Year.

**Audit-log retention today:**
- the ledgers are append-only and kept indefinitely;
- there is no purge and no configurable period;
- there is no documented duration;
- School audit is deleted only with its School, which is not a production
  operation.

E21 and ADR 0042 items 4–5 are the open policy.

**E21 reduced to exact questions for TCH** (owner, legal and compliance).
Engineering answers none.
1. How long must each TCH history category be retained after it stops
   granting authority? The categories are:
   - link history (audit);
   - ended TeachingAssignments;
   - LMS owner and audience rows;
   - teacher-written Attendance and Curriculum Delivery history;
   - the Teacher role grant history.
2. When, if ever, may each be purged, and by which owning-module job?
3. Must audit evidence of teacher actions (actor User ids) survive a User's
   erasure? Today an out-of-band User delete would SET NULL the actor.
4. Must TCH history and audit survive the removal of a School tenant, or
   be held?
5. How do data-subject requests by a Student, Guardian or teacher interact
   with authority-bearing history?

These are **OPEN — RETENTION POLICY DECISION REQUIRED**. No duration,
purge, archive or hold is invented here.

### 39.6 Other blockers, observations and the readiness matrix

**No additional TCH production blockers found.** I searched docs, ADRs,
module and security docs and the roadmap for `TCH-`, `E21`, `BLOCKER`,
production blocker, teacher and ownership. Nothing changed since `ff867e6`
on any TCH control, so the §38 audit stands:
- non-disclosure, RLS and cross-School FKs;
- the role-name prohibition;
- transactional holds and caching;
- the role bundle.

**Non-blocking observations** (no adopted contract is violated; none is
fixed here):
- `DatabaseRoleVerifier::NO_RUNTIME_DELETE` (the `platform:verify-database`
  deployment check) lists 9 core tables. It omits `teaching_assignments`
  and the LMS audience bridges, as it omits most delete-revoked tables
  (journal entries, payments, fee tables, …). Those tables' migrations and
  raw-SQL tests prove the protection. A complete, derived verifier list
  would be a future platform hardening.
- Two in-place overwrites keep their prior value only in audit, by design:
  - the Employee link on unlink;
  - an Attendance record's status on correction.

  Their retention is therefore the audit's retention (E21).

**Production-readiness matrix:**

| Area | Development complete | Technical controls complete | External decision required | Production blocker | Next owner / action |
|---|---|---|---|---|---|
| ActingEmployee identity | Yes | Yes | E21 (link history via audit) | E21 (platform-wide) | Legal + Owner: E21 |
| TeachingAssignment administration | Yes | Yes | E21 | E21 (platform-wide) | Legal + Owner: E21 |
| Curriculum Delivery teacher access | Yes | Yes | E21; no TCH-specific legal gate | E21 (platform-wide); no production `teacher` grant while E33 is open (§40) | Legal: E33, then E21 |
| **Attendance teacher access** | Yes | Yes | **TCH-L1 (E33)**; E21 | **Yes — E33**; E21 | Legal + Owner: §39.4 record |
| Learning Content teacher access | Yes | Yes | E21; no TCH-specific legal gate | E21 (platform-wide); no production `teacher` grant while E33 is open (§40) | Legal: E33, then E21 |
| Assignment teacher access | Yes | Yes | E21; no TCH-specific legal gate | E21 (platform-wide); no production `teacher` grant while E33 is open (§40) | Legal: E33, then E21 |
| E21 historical retention | n/a (no purge built, by design) | Immutability and delete protection as in §39.5 | **Yes** — §39.5 questions | Yes (O1, platform-wide) | Legal + Owner |

**Required external decisions:**
1. **TCH-L1 / E33:** the §39.4 record.
2. ~~**The operating choice before E33:** §39.3 (a) or (b).~~ **Decided by
   the owner (§40): (a).**
3. **E21:** the §39.5 questions, together with ADR 0042 items 4–6.

Items 1 and 3 are not answered here.

## 40. Owner decision — no production Teacher-role grants while TCH-L1 is open (2026-10-01)

**Status: DECIDED (owner, 2026-10-01; docs only).** This resolves §39.3. No
application code, capability, role, seed, route or test changes.

*Superseded in part (7 October 2026, §42): TCH-L1 / E33 is now determined
APPROVED WITH CONDITIONS. The "while E33 is OPEN" premise no longer holds;
the APPROVED WITH CONDITIONS row of the rule table below governs, and §42.5
applies it. This section is kept as the historical decision.*

**Decision.** While TCH-L1 / ADR 0058 **E33** is OPEN, production must not
grant the `teacher` system role to any user. No role split and no
Attendance feature gate is introduced to work around the open legal
determination.

**Reasons:**
- The production `teacher` role intentionally represents the complete
  adopted bundle: `curriculum.delivery.teacher`, `attendance.teacher`,
  `lms.content.teacher` and `lms.assignments.teacher`. Granting it while
  TCH-L1 is unresolved would enable teacher Attendance.
- TCH-L1 is an external approval gate, not a missing technical control.
- E21 independently blocks platform production sign-off (ADR 0058 O1), so
  extra role or gate engineering would enable nothing in production now.

**State:**

| Question | Answer |
|---|---|
| Teacher role technically implemented | **YES** |
| Teacher role technically production-capable | **YES** |
| Teacher role currently permitted to be granted in production | **NO** |
| Reason | TCH-L1 / E33 remains **OPEN** |
| Technical feature deficiency | **NO** |
| External decision required | **YES** (E33; and E21 for platform sign-off) |

**Production role-grant rule:**

| Situation | Rule |
|---|---|
| Development, demo, test | The existing `teacher` role continues unchanged. |
| Production while E33 / TCH-L1 is OPEN | **Do not grant the `teacher` role.** |
| TCH-L1 APPROVED | The existing role may be granted unchanged, subject to E21 and the platform production checklist (ADR 0058). |
| TCH-L1 APPROVED WITH CONDITIONS | Evaluate the recorded conditions before enabling the role. Add controls only if the determination actually requires them. |
| TCH-L1 REJECTED | Do not silently alter the existing role. Open a new, explicit product/architecture decision for the future production Teacher-role model. |

**Not implemented, by decision:**
- no `teacher_without_attendance`, `teacher_lms`, `teacher_academics` or
  other production-only role or bundle;
- no feature flag for `attendance.teacher`;
- no environment-conditional capability seeding;
- no role-name authorization.

The Teacher capability bundle and the Curriculum Delivery, Attendance,
Learning Content and Assignment authorization are unchanged.

**Enforcement.** This rule is enforced by process, like E33 itself (§39.2):
the operator and the School administrators who grant roles follow it. No
code enforces it.

**Still external, unanswered here:**
- **E33 / TCH-L1** is OPEN. The authorized legal/compliance reviewer records
  APPROVED, REJECTED or APPROVED WITH CONDITIONS in the §39.4 record.
- **E21** is OPEN and platform-wide. It covers the §39.5 questions and ADR
  0042 items 4–6. No retention duration, purge schedule, erasure policy,
  tenant-deletion retention or audit duration is invented.

## 41. Note — TCH-L1 review request drafted (2026-10-07)

**Docs only; E33 / TCH-L1 stays OPEN and §40 is unchanged.** The request that
asks the authorized reviewer to complete §39.4 is drafted as
`docs/security/TCH-L1-TEACHER-ATTENDANCE-REVIEW-REQUEST.md` (not sent). It is
sent alongside the teacher StudentMark requests (ADR 0068 §22) and asks for a
separate outcome: a TCH-L1 answer decides teacher Attendance only, never
teacher marks. ADR 0068 §22.2 records how teacher marks would use this ADR's
ownership seams; no TCH code, role or capability changes.

## 42. TCH-L1 / E33 determined — APPROVED WITH CONDITIONS (7 October 2026)

**Docs only. No code, capability, role, seed, route or test changes.** The
controlling record is `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`
(Lead Privacy Counsel & DPO, 7 October 2026), completing the §39.4 decision
record. §26, §39 and §40 are kept as history, with dated pointers here.

### 42.1 Outcome
**APPROVED WITH CONDITIONS** for teacher **Attendance** processing: an
authenticated individual teacher with a verified, current, School-maintained
assignment to the controlled scope; deny by default outside it. The teacher
role or same-School membership alone is never enough.

### 42.2 Development
**Permitted** for the approved scope, conforming to this ADR and the
determination: ownership verification, tenant controls, teacher
capabilities, MFA enforcement, read/write restrictions, auditing, revocation
behaviour and related security controls. It broadens no authority.

### 42.3 Mapping to this ADR (as built, TCH.4)
| Determination condition | This ADR | State |
|---|---|---|
| Individual teacher identity | Signed-in User; individual API tokens (ADR 0049) | Met |
| Verified current assignment | ActingEmployee (§10) + `TeachingOwnership::hold()` on `attendance_date` (§9), held `FOR SHARE` in writes | Met |
| Permitted actions | Owned roster preview, submit an owned register, correct a record, view own sessions (§39.2) | Met; nothing broader exists |
| Not authorised (School-wide browsing, export, unrelated classes, analytics, override, admin capabilities) | `attendance.teacher` has no Tier 1; no export, analytics or override on the teacher surface; unowned = identical 404 (§18) | Met |
| End of assignment | Next write refused after end, suspension, unlink, archive or employment end (two-process tests); recorded Attendance is kept | Met |
| School-scoped, no cross-tenant fallback, per-School authority | Forced RLS, composite FKs, School-bound routes; capabilities and ActingEmployee resolved per School | Met |
| Audit (writes, material changes) | `attendance.session.submitted`, `attendance.record.corrected` (actor, School, record, previous/new status); audit is append-only | Met |
| Privileged / exceptional access | Tier 1 `attendance.manage` is separate administrative authority, not derived from `attendance.teacher`; elevation is refused on School routes (CLAUDE.md rule 83) | Met on record |
| Data minimisation | §39.2 data set only; no notes, health or Guardian data | Met |

### 42.4 Production conditions — what is NOT yet evidenced (`91450ea`)
Production is permitted **only after** the nine controls of determination §9
are implemented and verified. Two are not met by the current implementation:
1. **MFA (§9.5).** No teacher Attendance route requires MFA. The web routes
   `app/my-attendance/*` carry no `mfa` middleware, and the `/api/v1`
   `…/my/attendance-*` routes are bearer-token routes, which carry no MFA
   assurance (ADR 0049). D-12 (§17) deliberately added no MFA to teaching
   surfaces. The determination now requires MFA, or a formally approved
   equivalent control, for production teacher accounts.
2. **Audit of teacher reads (§9.6).** Writes and corrections are audited;
   teacher reads (the session list, a session, the scheduled classes, the
   roster preview) are not.

Also to be verified at production readiness, not assumed:
- secured MFA recovery and reset that cannot bypass ownership or School
  authorization;
- no development/test authentication bypass reachable in production;
- that the remaining controls are re-verified on the production
  candidate.

**This audit claims no production readiness.** Closing items 1–2 is a
separate, explicitly scoped engineering slice under this ADR (MFA on the
teacher Attendance surfaces, session-only or an approved equivalent for the
API; read audit), followed by the verification evidence. That slice needs no
further privacy approval for this scope (determination §9).

### 42.5 Production `teacher` role grants (§40 applied)
§40's rule table already has the row for this outcome: **APPROVED WITH
CONDITIONS → evaluate the recorded conditions before enabling the role; add
controls only if the determination requires them.** It requires MFA and read
audit, which §42.4 shows are missing. So:
- **no production `teacher` grant** until §42.4 items are implemented and
  verified, and E21 and the ADR 0058 platform checklist allow;
- still no role split, Attendance feature flag or environment-conditional
  seeding (§40 unchanged on these).

### 42.6 Re-review
No fixed expiry. Triggers: determination §11 (ownership model change,
School-wide or cross-tenant access, weakened MFA or audit, a new
integration or recipient, analytics/profiling/automated decisions/AI, a new
jurisdiction, a change in law, a material incident, or a material change to
this ADR's assumptions). Ordinary fixes that keep the conditions do not
trigger it.

### 42.7 No effect on StudentMark
This determination does **not** authorise teacher marks entry or reads,
satisfy RES-L2 (E37) or the teacher-scope RES-L0 re-review (E35), establish
any StudentMark processing basis, or let any marks capability inherit from
an Attendance capability. **RES.4 remains NOT AUTHORISED** (ADR 0068 §22.9,
§23).

## 43. E33 production controls built — MFA and teacher read audit (2026-10-07)

**Executable slice, independent of RES.4.** It closes the two gaps §42.4
recorded and nothing else: no new teacher function, no StudentMark change, no
legal-outcome change. The determination
(`docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`) is unchanged.

### 43.1 Inventory (as built at `f7e6fc7`)
| Surface | Route | Kind | Auth | Audit before | Now |
|---|---|---|---|---|---|
| Web | `GET /app/my-attendance` | read (own registers) | session | none | `capability:attendance.teacher` + `mfa-page`; `attendance.teacher.sessions_listed` |
| Web | `GET /app/my-attendance/take` | read (owned classes; roster of one) | session | none | same gates; `.classes_listed`, `.roster_viewed` |
| Web | `GET /app/my-attendance/{session}` | read (one register) | session | none | same gates; `.session_viewed` |
| Web | `POST /app/my-attendance` | write (submit) | session | `attendance.session.submitted` | same gates; write audit unchanged |
| Web | `POST /app/my-attendance/records/{record}/correct` | write (correct) | session | `attendance.record.corrected` | same gates; write audit unchanged |
| API | `GET …/my/attendance-sessions`, `…/{id}`, `…/scheduled-classes`, `…/roster-preview` | reads | bearer | none | `teacher-attendance-api` (development only); reads audited (`surface: api`) |
| API | `POST …/my/attendance-sessions`, `…/my/attendance-records/{id}/correct` | writes | bearer | as web | `teacher-attendance-api` (development only) |

Ownership is unchanged on every route: `TeacherAttendanceAccess::scope()`
(capability → ActingEmployee → TeachingOwnership periods) for reads, and
`TeacherAttendanceGuard` inside the write transaction for writes.

### 43.2 MFA
- **Session (web):** the new `mfa-page` middleware (`RequireMfaForPage`) runs
  RequireMfa's two checks — an active factor, and current sign-in assurance
  inside the ADR 0037 window — and on failure renders the existing
  `MfaRequired` page (403 not enrolled, 401 step-up) instead of RequireMfa's
  JSON, because these are full pages. Composed after `capability:`. No fresh
  per-action code: neither ADR 0063 nor the determination asks for one. MFA
  never substitutes for ownership (tested), and a revoked factor (an MFA
  reset) cannot ride on an older sign-in assurance (tested).
- **Bearer (API): a token cannot prove MFA.** ADR 0049 records that a
  bearer token carries no MFA assurance; issuance needs a fresh code but use
  does not, and no equivalent control is formally approved. This slice does
  not invent one. **Policy: the owned teacher Attendance API is development
  only.** `EnsureTeacherAttendanceApiDevelopmentOnly`
  (`teacher-attendance-api`) answers a fixed 403
  `TEACHER_ATTENDANCE_API_UNAVAILABLE` before any capability, identity or
  ownership work unless `config('attendance.teacher_api_development_enabled')`
  (env `TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED`, default false) **and**
  `app()->environment(['local','testing'])` — the DevOnlySchoolHeaderResolver
  double guard, so a production variable alone cannot open it. The OpenAPI
  403 descriptions say so. A production teacher API needs a separate,
  approved authentication design (for example token-bound MFA assurance); it
  is not part of this slice.

### 43.3 Read audit
`App\Domain\Attendance\Application\TeacherAttendanceReadAudit` records one School
audit event per successful owned read, after the ownership decision:

| Event | When | Metadata (identifiers and counts only) |
|---|---|---|
| `attendance.teacher.sessions_listed` | the register list | acting Employee, date filter, page, result count, surface |
| `attendance.teacher.session_viewed` | one register (subject = the session) | acting Employee, session, Section, SubjectOffering, date, record count, surface |
| `attendance.teacher.classes_listed` | owned classes of a date | acting Employee, date, class count, surface |
| `attendance.teacher.roster_viewed` | a roster preview | acting Employee, timetable entry, Section, SubjectOffering, date, member count, surface |

The actor is the User and the School is the event's own column. No Student id,
name, roll number or status is stored (guard- and test-pinned). A refused,
not-found or concealed read records nothing. Audit is append-only, and no
teacher capability can modify it. The write events (`attendance.session.submitted`,
`attendance.record.corrected`) are unchanged: they already carry actor,
School, the register or record, the timetable entry or session context and
previous/new status, which is the determination's "class / Section / subject
or equivalent context".

### 43.4 The nine E33 production controls (determination §9), re-evaluated
| # | Control | Status | Evidence |
|---|---|---|---|
| 1 | Individual teacher authentication | **PASS** | Per-User session sign-in; per-User tokens (dev only); no shared-account path |
| 2 | Authoritative assignment / ownership | **PASS** | ActingEmployee + `TeachingOwnership` on `attendance_date` (`TeacherAttendanceAccessTest`, `TeacherAttendanceProductionControlsTest`) |
| 3 | School / tenant isolation | **PASS** | Forced RLS, School-bound routes, per-School capability and identity; multi-School identity test |
| 4 | Deny by default | **PASS** | Capability first, identical 404 for unowned/other-School/unknown, no fallback |
| 5 | MFA for production teacher accounts | **PASS** | `mfa-page` on all five web routes (guard-pinned); the bearer surface is refused outside development |
| 6 | Auditable reads, writes, material changes | **PASS** | §43.3 read events + existing write events; append-only audit |
| 7 | Revocation ends future authority | **PASS** | Assignment end and role revocation tests; TCH.4 two-process tests; history kept |
| 8 | Exceptional access cannot bypass the controls | **PASS** | No teacher override or MFA/ownership bypass; Tier 1 `attendance.manage` is separate administrative authority; elevation refused on School routes (rule 83); dev-only mechanisms double-guarded |
| 9 | No authority beyond Attendance | **PASS** | `attendance.teacher` reaches only Attendance; the `teacher` role is pinned to its four owned keys, no `examinations.marks.*` |

### 43.5 Effect
- **E33's privacy conditions are met in the repository.** Per determination
  §9, no further privacy approval is needed for the approved Attendance scope.
- **Not a production go-live.** Production `teacher` grants still follow §40's
  APPROVED WITH CONDITIONS row together with **E21** and the ADR 0058 platform
  checklist (O1). This slice deploys nothing and grants nothing. Operators must
  also enroll teachers in MFA, and the controls are re-verified on the
  production candidate.
- **No effect on StudentMark** (§42.7): RES-L2 (E37) and the RES-L0 teacher
  re-review (E35) are unresolved; **RES.4 remains NOT AUTHORISED**.

### 43.6 Proof
- `TeacherAttendanceProductionControlsTest`: MFA refusals (not enrolled and
  step-up, reads and writes, nothing written); MFA never replaces the
  capability, identity or ownership; revoked-factor reset; read-audit metadata
  and no Student data; no audit on a refused or concealed read; the bearer
  gate (flag off, production with the flag on); assignment end and role
  revocation; multi-School independence; no Student data in logs.
- `TeacherAttendanceArchitectureGuardTest`: `mfa-page` on all five web routes,
  `teacher-attendance-api` on all six API routes, the double guard, an audit
  call in every read action after the ownership decision, no Student field in
  read-audit metadata, and the `teacher` role's exact four keys.
- Updated deliberately: `MyAttendanceUiTest` (teachers now have MFA) and
  `DemoDataBuilderTest` (demo accounts carry no factor, so the demo teacher
  sees `MfaRequired`).

## 44. E33 production-candidate verification (2026-10-07)

**Verification slice with three narrow corrections.** It re-checked the nine
determination §9 controls against the code at `bdf1afa`, from the call chain
rather than the route list, and fixed what contradicted an approved
condition. No legal outcome, RES state or teacher function changed. **This is
not production approval or go-live.**

### 44.1 Corrections
1. **MFA reset could be bypassed by a live session (control 5; determination
   §4 "MFA recovery and reset must be secured").** An administrative reset
   (`MfaAdminResetService`) revoked the factor, but the session kept its
   `mfa_verified_at`. Re-enrolling inside the 60-minute window satisfied
   `mfa`/`mfa-page` without a sign-in with the new factor.
   `MfaChallengeService::hasValidAssurance()` now rejects assurance older
   than the current factor's activation (ADR 0037 amendment). It is one
   shared seam, so the rule holds for every MFA-gated surface.
2. **Production refuses the development API flag (control 5, defence in
   depth).** `ProductionConfigurationGuard` reports
   `teacher_attendance_api_development_enabled`, so a production process with
   `TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED=true` does not boot. This is a
   third layer behind the middleware's flag-and-environment double guard.
   It is proven by a real production-environment boot
   (`ProductionBootSmokeTest`).
3. **No raw-SQL proof existed for the Attendance tables (control 3).**
   `AttendanceRlsIsolationTest` now proves `attendance_sessions` and
   `attendance_records` are forced-RLS. They are invisible to another School
   and to a session without tenant context, and are neither rewritable nor
   insertable across Schools.

### 44.2 Route inventory (unchanged since `bdf1afa`)
- **Web (5):** `GET app/my-attendance`, `GET …/take`, `GET …/{session}`,
  `POST app/my-attendance`, `POST …/records/{record}/correct`.
- **API (6):** `GET …/my/attendance-sessions`, `…/scheduled-classes`,
  `…/roster-preview`, `…/{session}`, `POST …/my/attendance-sessions`,
  `…/my/attendance-records/{record}/correct`.
- **Guard.** `TeacherAttendanceArchitectureGuardTest` pins the full set and
  now finds routes by controller and by `capability:attendance.teacher`, not
  only by URI. No route reaches either controller or carries the capability
  outside these eleven.

### 44.3 Call-chain evidence
- **Reads.** `MyAttendanceController` / `TeacherAttendanceController` call
  `TeacherAttendanceAccess::scope()`, which applies the capability, then
  `ActingEmployeeResolver::resolve()` (operational School, active
  membership, enabled User, linked active Employee, exactly one eligible
  employment today), then `TeachingOwnership::periods()`.
  `TeacherAttendanceScope::constrain()` / `ownsOn()` filter in the query on
  the register's `attendance_date`. The read audit runs after that.
- **Writes.** `TeacherAttendanceAccess::guard()` passes a
  `TeacherAttendanceGuard` into the Attendance services' own transaction:
  capability, then `ActingEmployeeResolver::hold()` (`FOR SHARE`), then
  visibility (404), then `TeachingOwnership::hold()` on the date (`FOR
  SHARE`). The write audit is part of the same transaction.
- **Middleware.** Route middleware is only the outer layer. Ownership never
  depends on it, and MFA never replaces it (tests).

### 44.4 Production configuration contract
| Item | Production value / rule |
|---|---|
| Teacher Attendance surface | `/app/my-attendance` only, for signed-in staff through the ordinary School context |
| MFA | Mandatory on every route (`mfa-page`). Teachers must enroll a factor; assurance comes only from a real sign-in with it |
| Teacher bearer API | Off. `TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED` unset/false; `true` refuses boot (`ProductionConfigurationGuard`); outside `local`/`testing` the routes answer 403 anyway |
| `APP_ENV` | `production` (environment separation is itself a guard violation code) |
| Dev / test mechanisms | `X-School-Id` header (`DevOnlySchoolHeaderResolver`, double-guarded); demo data (`DemoEnvironmentGuard`: local DDEV / testing only); MFA and idempotency demo routes (registered only in local/testing). None is reachable in production |
| Demo / test MFA shortcut | None. No factor or TOTP secret is seeded; the test fixtures are test-only code |
| Audit | Always on (`AuditRecorder`, append-only `school_audit_events`); no switch exists |

No secret or environment-specific value is added to the repository.

### 44.5 Nine controls — verified
| E33 control | Status | Evidence |
|---|---|---|
| Individual authentication | **PASS** | Anonymous requests redirect to sign-in. Every request resolves one signed-in User; there is no shared or generic account type. Production has no test-authentication path (§44.4) |
| Authoritative ownership | **PASS** | §44.3 call chain; per-register date semantics; MFA-qualified unrelated teacher refused (tests) |
| School isolation | **PASS** | `AttendanceRlsIsolationTest` (raw SQL); per-School capability and ActingEmployee; multi-School identity test; no global teacher fallback |
| Deny by default | **PASS** | Each missing predicate is refused: capability, Employee, assignment, School, register scope, MFA. 404 is identical for unowned, other-School and unknown |
| MFA / equivalent | **PASS** | `mfa-page` on all 5 web routes. Not-enrolled, expired and reset-then-re-enrolled sessions are refused. A recovery-code sign-in is ordinary assurance and still needs ownership. The bearer API is refused in production (middleware, configuration guard, boot test) |
| Audit | **PASS** | Read events (§43.3) with identifiers only and none on a refused read; write events in the write transaction; append-only, so no teacher path can alter audit |
| Assignment revocation | **PASS** | End, revocation, suspension, unlink, archive and employment end refuse the next write. Inclusive boundary dates. History kept (`TeacherAttendanceAccessTest`, `TeacherAttendanceConcurrencyTest`, production-controls tests) |
| Exceptional-access isolation | **PASS** | No teacher override and no MFA or ownership bypass. Tier 1 `attendance.view`/`.manage` is a separate administrative path (school_admin, principal). Platform elevation is refused on School routes (rule 83) |
| Attendance-only scope | **PASS** | `attendance.teacher` reaches only the owned Attendance surface. The `teacher` role has exactly four owned keys: no `examinations.marks.*`, no Finance, no Tier 1, no `students.view`, no export or analytics (guard-pinned) |

**Result: E33 TECHNICALLY READY FOR PRODUCTION-CANDIDATE SIGN-OFF.**
Repository evidence is complete. What remains for E33 is the deployment
re-verification of these controls on the actual production candidate.

### 44.6 What still prevents production
E33 readiness is not platform production readiness. O1 (ADR 0058) still has
every other "Blocks O1" row open:
- **Governance:** E02 (final regression and qualification of the closeout
  commit), E03 (`main` protection), E14 (signing custody), E29 (evidence
  hygiene).
- **Deployment:** E05, E07, E09–E12, E15, E19, E20, E22, E23.
- **Provider:** E08 (secret store), E13 (registry).
- **Decision:** E16 (vulnerability exceptions).
- **Legal:**
  - E17, E18 (email);
  - **E21** (retention, including TCH history, §39.5);
  - E30–E32 (fees);
  - E34 (library fines).

None is changed here.

### 44.7 The future enablement step (not taken)
- The `teacher` system role is seeded everywhere with its four owned keys.
  There is no feature flag, environment-conditional seeding or role split
  (§40).
- Production enablement for a person is a **School staff-role grant**:
  Settings → Staff accounts (ADR 0059), by an issuer holding
  `school.members.manage` + `school.roles.manage` and every capability of the
  role (no-escalation; `school_admin` holds the four `*.teacher` keys), with
  a fresh MFA code. It is audited and revocable.
- Nothing grants it automatically: production seeds no users, and demo data
  refuses to build outside local DDEV / testing.
- After the grant, the teacher still needs an enrolled factor, a sign-in with
  it, a linked eligible Employee and a dated TeachingAssignment before
  anything is visible.
- The grant stays **process-gated** (§40, §42.5): no production `teacher`
  grant until the production-candidate re-verification, E21 and the platform
  checklist allow it, under a rule-16 go-live authorization.

### 44.8 No effect on StudentMark
RES-L2 (E37) and the RES-L0 teacher re-review (E35) are unresolved. **RES.4
remains NOT AUTHORISED.**

## 45. TCH-E — dated elective teaching ownership (2026-10-07)

**Executable slice, authorised by the product owner as a technical
prerequisite.** It makes elective teacher ownership expressible. It
authorises no consumer: no StudentMark, Attendance, LMS or Curriculum
Delivery behaviour changes, and no legal or privacy approval is implied for
any future use. **RES.4 remains NOT AUTHORISED** (RES-L2 and the teacher-scope
RES-L0 revalidation are unanswered). §7's D-05 exclusion is lifted for the
ownership fact only.

### 45.1 Why electives were excluded (D-05, verified)
- §7: "No Section-independent teaching cohort exists. Electives are
  Student-level enrollments, not Section-wide slots."
- TIMETABLE.md: the timetable refuses electives
  (`RequiredSubjectOfferingOnlyException`).
- `TeachingAssignmentService` refuses them (`RequiredOfferingOnlyException`),
  because the TCH.2 fact is Section × required Offering.

Nothing in the repository gives an elective a Section, a teaching group or a
slot.

### 45.2 The model
A **parallel fact, not a change to `teaching_assignments`.** Required-subject
ownership is untouched and never reads it.
- **`elective_teaching_assignments`** — one row per Employee × elective
  SubjectOffering period, **Offering-wide**. The cohort is the Students
  enrolled in the elective (P3), across Sections. There is never one row per
  Student, and no Section, group or timetable restriction is invented.
- **Columns:** UUIDv7 id, `school_id`, Employee, the elective Offering with its
  year/campus/grade pins, `starts_on`, nullable `ends_on`, creator, and the
  end facts (`ended_at`, `ended_by_user_id`, closed `end_reason`). No free
  text.
- **Invariants:**
  - composite same-School FKs (Employee `(id, school_id)`, the Offering
    through its 5-column context key, RESTRICT);
  - `starts_on ≤ ends_on`;
  - end shape;
  - **elective only** — the database refuses a required Offering at insert,
    and the service refuses it first (`ElectiveOfferingOnlyException`);
  - identity frozen, one end that may only shorten, then immutable
    (`trg_elective_teaching_assignments_history`);
  - forced RLS; no runtime DELETE.
- **Overlap** per (School, Employee, Offering) is refused under the advisory
  lock `teaching.elective_assignment:{school}:{employee}:{offering}`.
  Different Employees may own the same elective at once.
- **Retention:** category `authority` (E21-D6), exactly like
  `teaching_assignments`:
  - anchored and delete-guarded;
  - expired 7 years after `ends_on` by
    `retention_expire_elective_teaching_assignments` (retention identity
    only, holds respected; wired into `platform:authority-history-prune`);
  - its actors are `RETAIN_REFERENCE`, and it keeps its Employee (D6
    authority history).

### 45.3 Temporal semantics
- Dates are School-local and inclusive; `ends_on` NULL is open-ended.
- An end shortens the period and never rewrites it, so "did E own O on D" for
  an earlier D stays true.
- Creation needs an active elective Offering, a draft or active year, dates
  inside the year, and an Employee employed on the start date
  (`EmploymentCoverage`).

### 45.4 Ownership seam (`TeachingOwnership`)
`periods()` and `hold()` are unchanged. Three additions:
- **`electivePeriods(School, employee)`** — `OwnedElectivePeriod`s (ids and
  dates, no Section).
- **`holdElective(School, employee, offering, date)`** — inside the caller's
  transaction, the one covering row `FOR SHARE`. An end either commits first
  or waits.
- **`holdOffering(School, employee, offering, ?section, date)`** — one
  decision for any Offering. It takes the Offering `FOR SHARE`, so a
  required↔elective change waits, then:
  - a required Offering uses `hold()` and needs the Section;
  - an elective uses `holdElective()` and takes no Section;
  - anything else is "not owned".

Consumers must not read either table directly (guard-pinned). The existing
adopters (Attendance, Curriculum Delivery, LMS) do not use the elective
reads: adopting electives is each consumer's own decision.

### 45.5 Co-teachers and cover
- **Co-teachers:** several Employees may hold the same elective at once, as
  equal owners. There is no lead/assistant model. A legal requirement to
  distinguish them would need new modelling.
- **Cover/substitute:** representable only as a short, ordinary dated
  assignment, indistinguishable from any other (as §9 for required
  subjects). No substitute classification exists.

### 45.6 Identity is separate
Ownership is a fact about an **Employee**. It never implies current
employment, a School membership, a role, a capability or authentication.
Every consumer must still compose ActingEmployee, its owned-scope
capability and its own gates.

### 45.7 Administration and audit
- **Page:** `/app/elective-teaching-assignments` (list per year, create, end;
  no edit, no delete), linked from the TCH.2 page.
- **Capabilities:** the existing `teaching.assignments.view` / `.manage`,
  checked in the controller and the service. No new capability. Teachers
  never assign themselves.
- **API:** no `/api/v1` surface in this slice; it is a follow-up if one is
  needed.
- **Audit:** `elective_teaching_assignment.created` / `.ended` — ids, dates
  and the closed reason; directory-tier projections only.

### 45.8 Proof
- **`ElectiveTeachingAssignmentTest`:**
  - creation rules: elective only, active, open year, inside the year, dates;
  - capability and cross-School 404;
  - overlap vs co-teachers;
  - end once and shorten only;
  - inclusive boundaries and historical truth;
  - per-Employee and per-School;
  - `holdOffering()` dispatch;
  - ownership without identity.
- **`ElectiveTeachingAssignmentsRlsIsolationTest`:**
  - raw-SQL forced RLS;
  - required Offering, cross-School Employee, wrong pins, bad interval and
    born-ended rows all refused;
  - identity rewrites and runtime DELETE refused;
  - immutable once ended.
- **`ElectiveTeachingOwnershipConcurrencyTest`** (real processes): two
  overlapping creates give one row; an end waits for a holder; a check behind
  an end sees the shortened period. Mutation checks:
  - removing `holdElective()`'s `FOR SHARE` fails both lock races;
  - removing the create advisory lock fails the overlap race.
- **`ElectiveTeachingAssignmentAdminUiTest`:** list, create and end; a
  required Offering is refused; the view-only, teacher and other-School
  cases.
- **Guards:**
  - one writer per ownership table;
  - `TeachingOwnership` reads exactly the two facts;
  - no Examinations or Students consumer;
  - no `examinations.marks.teacher`, results or new teaching capability;
  - no teacher marks route.
- **Pins:** user references 113 / 103 retained; forced RLS 205; Employee
  retention classification; standalone retention functions.

## 46. Ownership consumed by RES.4 — teacher marks, development only (2026-10-07)

**ADR 0068 §25 is the controlling record.** This section records only the
ownership side.
- **Fifth adopted surface: Examinations.** StudentMark entry, built on the
  product owner's engineering authorisation (not a legal determination;
  RES-L2 / E37 and the teacher RES-L0 re-review / E35 unresolved).
  - It is production-refused in code outside `local` / `testing`.
- **New owned key.** `examinations.marks.teacher` joins the `teacher` bundle
  (§13), with `school_admin` holding it for grantability only. The role
  still reaches nothing on its own, and no code reads the role key.
- **Consumption, through `TeachingOwnership` only:**
  - `holdOffering()` inside the marks write transaction, after P3 and
    before ADR 0038 — the first consumer of the TCH-E elective fact;
  - `periods()` / `electivePeriods()` for the read and the paper-visibility
    check.
  - Never the models, the tables or the administrative services
    (guard-pinned).
- **Ownership date.** The paper's `scheduled_on` (not today). The
  ActingEmployee is judged today, the §11 / Attendance precedent.
- **Co-teachers** (§9) are equal owners, and **cover** is an ordinary short
  dated assignment. Both are owner-adopted development rules pending RES-L2.
- **Lock order.** The identity chain (§20) comes first, then the marks rows
  (ADR 0068 §21.5) and the ownership row after P3; ADR 0068 §25.7 shows it
  is deadlock-free against assignment ends and employment ends.
- **No effect on E33 or teacher Attendance.** Attendance authority still
  never implies marks authority, and marks authority never implies
  Attendance.

## 47. S7 — ending employment ends teaching ownership (2026-10-08)

**Executable hardening slice (ADR 0068 §27.11 S7).** Before it, ending an
employment left the Employee's teaching assignments open. ActingEmployee
eligibility hid them only until a rehire of the same Employee row; after the
rehire, old open assignments granted ownership again — Attendance, LMS,
Curriculum Delivery and the RES.4 teacher marks path all honoured them.

### 47.1 Rule
- `EmploymentService::end()` ends, **in its own transaction**, every
  required (`teaching_assignments`) and elective
  (`elective_teaching_assignments`) row of the Employee that would grant
  ownership after the employment's last day `D`.
- A rehire owns nothing until a **new** assignment is created.
- History is never deleted and `starts_on` is never rewritten.

### 47.2 End-date semantics (per row, reason `employment_ended`, actor = the HR actor)
| Row before the end | After |
|---|---|
| Started on or before `D`, open or ending after `D` (scheduled or already ended) | `ends_on = D` — ownership holds through the last employed day (the inclusive HR interval), never after |
| Not started by `D` (`starts_on > D`) | **voided**: `ends_on = starts_on − 1`; it never covers a date and stays as a row |
| Already ending on or before `D` | untouched |

- `ended_at` / `ended_by_user_id` / `end_reason` record the latest end. The
  earlier end stays in its own audit event; the new
  `teaching_assignment.ended` / `elective_teaching_assignment.ended` event
  adds `previousEndReason` and `employmentEndsOn`.
- `TeachingOwnership::periods()` / `electivePeriods()` omit void rows; the
  `hold*()` reads never cover them; the create overlap check ignores them (a
  rehire may reassign the same key across their dates); the administrative
  list shows them as `past`, never `upcoming`.

### 47.3 Architecture
- **Port.** HR owns `EmploymentEndParticipant` (tag
  `hr.employment_end_participants`). `EmploymentService::end()` calls every
  participant after closing HR's own assignments and before
  `EmploymentEnded`. The binding lives in `AppServiceProvider` (the
  `FinancialPeriodCloseParticipant` precedent), so HR still never references
  Teaching Assignments (guard-pinned).
- **Participant.** `EmploymentEndedTeachingOwnership` calls
  `TeachingAssignmentService::endForEmployment()`, then
  `ElectiveTeachingAssignmentService::endForEmployment()`. One writer per
  table stays true.
- **Authorization.** The path re-checks `hr.employees.assignments.manage`,
  the capability that ends the employment. An HR operator needs no
  `teaching.assignments.manage`, and a teaching administrator cannot use
  this path.
- **Lock order.** EmploymentRecord `FOR UPDATE` (HR) → required rows → elective
  rows, each `FOR UPDATE` in id order. **No assignment-key advisory lock:**
  `create()` takes its key *before* the EmploymentRecord, so taking it here,
  after, could deadlock. A create either committed first (its row is seen
  and ended) or waits on the EmploymentRecord and then sees the end. This is
  the identity-first order every teacher use already takes (§20).
- **Create-time cap.** `EmploymentCoverage::coveringEndsOn()` (HR). A
  required or elective create whose `ends_on` is open or after the covering
  employment's last day is refused with 422
  `TEACHING_ASSIGNMENT_BEYOND_EMPLOYMENT`. Assignments for a fixed-term
  employment therefore cannot outlive it either.
- **ActingEmployee unchanged.** It stays the separate use-time predicate
  (§11); S7 changes ownership rows, never eligibility.

### 47.4 Database
Migration `2026_12_11_090000_end_teaching_ownership_with_employment`, both
tables, `employment_ended` only:
- the date-range CHECK also admits `ends_on = starts_on − 1` (written with
  `IS NOT DISTINCT FROM`, so a NULL reason cannot turn the CHECK NULL and
  slip through — caught by the existing raw-SQL invariant tests);
- the history trigger lets an ended row's `ends_on` move **earlier**;
  extending, another reason, or any identity change on an ended row stays
  refused.
- `down()` refuses while a void row exists and changes nothing else.

### 47.5 Unchanged
- The RES.4 paper-date rule (§46; RES-L2 Q5/Q10 open). A paper scheduled
  while the teacher owned the class stays theirs to read and, while open, to
  correct after a rehire.
- The ADR 0063 E33 conditions, the capabilities, the routes, and all
  legal-register statuses.

### 47.6 Residuals (recorded, not built)
- **Legacy rows.** Assignments created before S7 for a fixed-term
  employment (an `ends_on` set at hire, never `end()`ed) may still run past
  it. Development data only; production is not live.
- **Archive / restore.** An archived Employee owns nothing (ActingEmployee
  refuses); restoring them revives their assignments. This is intended: the
  employment never ended.
- **Raw SQL.** A raw `employment_records` update bypasses the port. The
  application has one writer (`EmploymentService`); it is not
  database-enforced.
- **Past dates after a rehire (recorded by the RES thread closure audit,
  2026-10-08).** Ownership is dated. A rehired teacher still owns dates up
  to the old employment's last day, so they can correct Attendance or
  record Curriculum Delivery for those dates. §47.5 decides this only for
  the RES.4 paper-date rule. Whether Attendance corrections and Curriculum
  Delivery should allow it is an open owner question. *(2026-10-08: for
  teacher Attendance, a DPO clarification is drafted, not sent:
  `docs/security/TCH-L1-HISTORICAL-DATE-CLARIFICATION-REQUEST.md`. It must
  be resolved before E33 production re-verification unless the
  determination already clearly authorises this. Curriculum Delivery and
  RES.4 are not part of it.)*
- **Employments ended before S7 (same audit).** The S7 migration changed
  constraints only. An employment `end()`ed before S7 can still have open
  assignments, which a rehire would revive. Development data only;
  production is not live.

### 47.7 Proof
- `EmploymentEndTeachingOwnershipTest` covers:
  - required and elective ends;
  - the inclusive boundary;
  - void, shortened and untouched rows;
  - rehire with no resurrection;
  - co-teacher and cross-School isolation;
  - audit;
  - authorization;
  - the create-time cap;
  - every surface: Attendance, LMS, Curriculum Delivery and RES.4.
- `EmploymentEndTeachingOwnershipConcurrencyTest` runs real-process races:
  - X1: authorization vs end;
  - X2 / X3: required / elective create vs end, both orders;
  - X4: rehire vs the stale assignment;
  - X5: teacher StudentMark vs end, both orders.
- `TeachingAssignmentEmploymentEndGuardTest` proves the raw-SQL
  CHECK/trigger shapes.
- `EmploymentEndArchitectureGuardTest` pins the structure.
- Mutation checks: omitting the required or the elective call, or skipping
  already-ended rows, fails the tests.
