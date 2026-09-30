# ADR 0063: Teacher Identity and Ownership-Based Authorization Contract

- Status: Accepted as a contract (TCH.0, documentation only, closed).
  **TCH.1, TCH.2 and TCH.3 are implemented and closed** (§29–§31).
  **TCH.4 is implemented** (owned teacher Attendance access, §32).
  **Teacher Attendance functionality is implemented but production
  enablement remains blocked by TCH-L1 until the required legal/compliance
  determination is recorded** (§26). **TCH.5A — the LMS teacher ownership
  contract — is published and closed** (audit §33, owner resolution §34;
  D-14 resolved, docs only). TCH.5B onward are **not** implemented: LMS
  remains admin-only, and no LMS teacher capability or access exists.
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

**TCH-L1 status: OPEN** (TCH.4, §32). Development blocker: **no**.
Production blocker: **yes**, for the teacher Attendance surface
(`attendance.teacher`). No determination has been recorded, and none is
implied by the TCH.4 implementation or its tests.

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
  submission or correction vs an assignment end, a membership suspension,
  an unlink, an archive and an employment end, in both orders.
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
contract; where §33 differs, this section wins. **Nothing here is
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
| **TCH.5B** | LMS ownership & audience persistence foundation: owner fields, audience bridges, structural integrity, RLS/immutability, the parent-authorization seam; the Sensitive re-tier. **No** teacher capability or access | **NEXT — NOT IMPLEMENTED** |
| **TCH.5C** | Learning Content teacher adoption: `lms.content.teacher`, owned reads/writes, teacher API/UI, attachment integration | Planned |
| **TCH.5D** | Assignment teacher adoption: `lms.assignments.teacher`, owned reads/writes, teacher API/UI, attachment integration | Planned |
| **TCH.6** | TCH closure audit | Planned |

Each implementation checkpoint is separately authorized. LMS Submission
stays outside every one.
