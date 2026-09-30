# ADR 0063: Teacher Identity and Ownership-Based Authorization Contract

- Status: Accepted as a contract (TCH.0, documentation only, closed).
  **TCH.1 is implemented** (the ActingEmployee identity boundary, §29).
  TCH.2 onward are **not** implemented: no TeachingAssignment, Teacher role
  or owned-scope capability exists, and every teaching module stays
  admin-only until TCH.3 onward are built and closed.
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
| D-14 | LMS ownership semantics | **DEFERRED** to the LMS adoption checkpoint (§16) |
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
   decision (D-14, **DEFERRED**). LMS rows carry an offering but no Section
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
| Employment ends while a TeachingAssignment is created | Creation reads the EmploymentRecord `FOR SHARE`; `EmploymentService::end` locks it `FOR UPDATE` |
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
| **TCH.5** | LMS teaching adoption, only after the LMS ownership decision (D-14); removable by the owner |
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
