# ADR 0071: Staff Role Catalogue & Least-Privilege Access Contract (SR.0)

- Status: **Accepted — SR.0 contract (2026-10-09). SR.1 CATALOGUE DATABASE
  HARDENING BUILT (2026-10-09, §23). SR.2 GRANT AUTHORITY, CONCURRENCY &
  AUDIT BUILT (2026-10-09, §24).** SR.3 needs separate owner authorisation.
- Date: 2026-10-09
- Programme: **SR — Staff Role Catalogue & Least-Privilege Access**
  (`MASTER-ROADMAP.md`, post-foundation programme 7).
- Amends:
  - ADR 0059 §1, §6.1 and the 0O.12B owner amendment (the closed School role
    catalogue and the no-escalation rule; §10 here);
  - ADR 0063 §14 T2 (non-teaching staff roles, now contracted here).
- Leaves unchanged:
  - ADR 0063 §14 T3 (tenant-custom roles stay future);
  - ADR 0063 D-02 (class teacher / homeroom stays deferred).
- Reaffirms:
  - the four authorization scopes (`platform`, `school`, `group`,
    `guardian`; ADR 0045 as amended by ADR 0070 §8.2 / POR.1);
  - CLAUDE.md rules 24, 25 and 92;
  - ADR 0046/0047 (no platform or Group path into School staff access).
- Evidence: the *Staff Role Catalogue & Least-Privilege Operations Access —
  Architecture & Contract Audit* (2026-10-09, baseline `3359496`, read-only).

## 1. Context (verified at `3359496`)

### 1.1 The production School catalogue
- **School-scope system roles:** exactly four, `school_admin`, `principal`,
  `teacher` and `staff_self_service`. The `guardian` role is a separate
  scope (ADR 0070 §8.2) and never part of the staff catalogue.
- **Where roles live:** roles are global rows (`roles` has no `school_id`,
  and `key` is unique platform-wide). Grants belong to School memberships
  (`membership_role_assignments`).
- **No runtime writer:** nothing at runtime writes `roles` or
  `role_capabilities` (ADR 0059 §1). A School cannot create, edit, compose or
  configure a role.

### 1.2 Twenty-six School capabilities are held by no role
- **The list:** `hr.categories.*`, `hr.departments.*`, `hr.positions.*`,
  `hr.employees.{assignments,qualifications,documents,notes}.*`,
  `hr.employees.personal.manage`, `hr.employees.sensitive.*`,
  `payroll.compensation.sensitive.*`, `payroll.statutory.*` (five keys),
  `analytics.export` and `communications.conversations.students`.
- **Why they are unheld:** each was withheld deliberately ("nobody by
  default": HR 8A.0 P1, PAYROLL, ANALYTICS, Phase 5D.1).
- **The false assumption:** the module documents assumed a School "can
  compose a custom role" or "must add" them through its own role
  configuration. That configuration does not exist (§1.1), so the
  assumption was false. §21 corrects them.

### 1.3 The EmploymentRecord production gap (O1-relevant finding)
1. `EmploymentService` (`app/Domain/HR/Application/EmploymentService.php`)
   is the only creator of EmploymentRecords. `create`, `update` and `end`
   each require `hr.employees.assignments.manage`.
2. `EmployeeImportService` also requires `hr.employees.assignments.manage`
   for any row carrying employment data.
3. No production role holds that capability (§1.2).
4. So no ordinary production School can establish an EmploymentRecord, and
   without one `ActingEmployeeResolver` resolves nothing. The surfaces built
   on ActingEmployee are unusable in production, independent of their own
   legal gates:
   - TCH teacher identity (`*.teacher`);
   - `staff_self_service` (HRX);
   - reporting-line leave decisions (`hr.leave.approve`);
   - staff attendance;
   - payroll runs. Payroll also cannot record salary amounts:
     `payroll.compensation.sensitive.manage` has no holder.
5. **This is not a defect** in TCH, HRX, Payroll or any closed programme.
   It is a missing production authorization-catalogue prerequisite,
   discovered later. This programme closes the authorization side.
   TCH, HRX, RES and POR are not reopened.

### 1.4 Catalogue database weaknesses
- **Writable catalogue:** the runtime role `school_os_app` holds INSERT,
  UPDATE and DELETE on `roles`, `role_capabilities` and `capabilities`
  (default privileges; no revoke).
- **History deletable through a cascade:**
  `membership_role_assignments.role_id` is `ON DELETE CASCADE`. Referential
  actions run with the table owner's rights, the history guard fires only on
  INSERT and UPDATE, and the retention DELETE guard is a no-op outside the
  retention session. So deleting a role would erase its whole grant history.
  This is confirmed from the database structure; no DELETE was executed.

### 1.5 Issuer-escalation weaknesses
- **Application-only today:** the no-escalation rule
  (`StaffRoleCatalog::within`) is a subset check on the issuer's fresh
  capabilities. The database enforces no grantor authority.
- **Gaps:**
  - `revokeRole` has no equivalent check;
  - `grantRole` and `reactivate` evaluate `grantable()` before the
    transaction;
  - invitation issue and acceptance do not take the School access lock;
  - a zero-capability role is trivially grantable;
  - the catalogue lists every `scope = 'school'` row, including non-system
    (demo/test) roles;
  - `school.roles.view` is seeded but checked nowhere.

## 2. Decision summary (owner, 2026-10-09)

| # | Decision |
|---|---|
| D1 | **Option A:** an expanded fixed system-role catalogue with class-scoped grant authority. Tenant-custom roles (B) and the hybrid (C) are not built in v1; ADR 0063 T3 stays future. No runtime role creation or editing |
| D2 | The v1 catalogue is the thirteen roles in §4, with exact keys |
| D3 | Sensitive appointment uses **class-scoped grant rights** (§6). The HR-sensitive and payroll-sensitive capabilities are **not** placed on `school_admin`; the "nobody by default" privacy decisions stand |
| D4 | No dual-administrator rule for role assignment in v1. Fresh MFA plus the explicit class grant right is sufficient. Existing domain maker/checker rules are preserved (§9) |
| D5 | Every staff-role mutation keeps fresh MFA. Step-up MFA on Highly Sensitive operational actions is contracted for SR.4 (§8) |
| D6 | `school.roles.view` is kept and activated as the capability for viewing the School role catalogue and role assignments (§10.4) |
| D7 | The demo moves to the production catalogue; covered `demo.*` roles are removed; no problematic combinations are recreated (§17) |
| D8 | Deferred: academic coordinator, exams officer, statutory payroll role, tenant-custom roles (§5) |

## 3. Scope and invariants
- **Scope:** every catalogue role is `scope = 'school'`, `is_system = true`,
  global, and granted per membership. No new authorization scope.
- **Capabilities, not roles:** application code never branches on a role
  key; authorization stays capability checks (rule 24).
- **Scope separation:** `portal.*` never sits on a School role, and no staff
  key sits on `guardian`. The database already refuses both
  (`trg_role_capabilities_scope`, the namespace checks).
- **Staff detection:** still "holds an active `school`-scope grant"
  (`MembershipRoleAssignment::scopeStaff()`), so an operational role
  correctly makes its holder staff.
- **Roles are additive:** a person needing two jobs receives two roles; no
  merged super-role.
- **Never on an operational role:** a capability in the classes
  `owned-scope`, `legal-gated` or `authority` (§7). In particular never
  `*.teacher`, `examinations.marks.*`, `payroll.statutory.*`,
  `analytics.export`, `communications.conversations.students`, `portal.*`,
  `school.{members,roles}.manage` or `school.roles.grant.*`.

## 4. The v1 system-role catalogue (exact keys, verified at `3359496`)

Every key below exists in `capabilities` (namespace `school`). Columns:
- **Held by `school_admin`:** whether `school_admin` holds the key today,
  which decides whether the plain subset rule can cover it (§6).
- **Grant right:** the grant right that covers the key when
  `school_admin` does not hold it.

### 4.1 `hr_officer` — class HR (23 keys)
- `hr.employees.view`, `hr.employees.manage`
- `hr.employees.personal.view`, `hr.employees.personal.manage`
- `hr.employees.assignments.view`, `hr.employees.assignments.manage`
- `hr.employees.qualifications.view`, `hr.employees.qualifications.manage`
- `hr.employees.documents.view`, `hr.employees.documents.manage`
  (Restricted tier only; highly-sensitive documents need §4.2)
- `hr.employees.notes.view`, `hr.employees.notes.manage`
- `hr.departments.view`, `hr.departments.manage`
- `hr.positions.view`, `hr.positions.manage`
- `hr.categories.view`, `hr.categories.manage`
- `hr.leave.view`, `hr.leave.manage`, `hr.leave.configure`
- `hr.staff_attendance.view`, `hr.staff_attendance.manage`

**How it can be granted:**
- Held by `school_admin`: `hr.employees.{view,manage,personal.view}`, leave
  and staff attendance.
- Covered by `school.roles.grant.hr`: the other fifteen.

**Excludes:**
- `hr.employees.sensitive.*`;
- `hr.leave.approve` (reporting-line decision authority, not HR
  administration);
- all `payroll.*`, `finance.*` and `school.*`;
- `*.self` and `*.teacher`.

**Identity substrate:** `hr.employees.manage` (User↔Employee linking) and
`hr.employees.assignments.manage` (EmploymentRecords) create the facts
ActingEmployee resolves. They grant no teacher or self-service authority on
their own, which still needs a role grant (`school.roles.manage`) and, for
teachers, a TeachingAssignment (`teaching.assignments.manage`); neither is
on this role. SR.4 must verify that an `hr_officer` cannot use them to give
themselves authority, for example by linking their own User or ending
another officer's employment unchecked.

### 4.2 `hr_sensitive_records` — class HR-sensitive (add-on, 2 keys)
- **Keys:** `hr.employees.sensitive.view`, `hr.employees.sensitive.manage`.
- **Grant:** only through `school.roles.grant.hr_sensitive`.
- **Use:** never on its own; it adds to `hr_officer`.

### 4.3 `payroll_officer` — class payroll-sensitive (9 keys)
- `payroll.structures.view`, `payroll.structures.manage`
- `payroll.compensation.view`
- `payroll.compensation.sensitive.view`, `payroll.compensation.sensitive.manage`
- `payroll.periods.manage`
- `payroll.runs.view`, `payroll.runs.prepare`
- `payroll.accounting.manage`

**How it can be granted:** two keys through
`school.roles.grant.payroll_sensitive`; the rest are held by
`school_admin`.

**Excludes:**
- `payroll.runs.approve`, `payroll.runs.post` and `payroll.runs.reverse`
  (checker side; §9);
- `payroll.statutory.*` (legal-gated);
- all `hr.*` and `school.*`.

**Semantics confirmed:**
- `payroll.compensation.sensitive.view` also covers payroll **run results**,
  so the officer needs it;
- `payroll.periods.manage` creates, opens **and closes** payroll periods;
- `payroll.accounting.manage` is the Finance-account configuration for
  payroll.

The owner approved both "periods" and "accounting integration"; SR.4
re-confirms both are needed for ordinary operation.

### 4.4 `accountant` — class financial (12 keys)
- `finance.accounts.manage`
- `finance.ledger.view`, `finance.ledger.post`
- `finance.charges.view`, `finance.charges.manage` (assess and cancel charges)
- `finance.fee_structures.view`, `finance.fee_structures.manage`
- `finance.fee_assessments.run`
- `finance.fee_concessions.view`, `finance.fee_concessions.request`
- `finance.payments.view`, `finance.payments.record`

**Excludes:**
- `finance.ledger.reverse`;
- `finance.periods.manage` ("Close Finance financial periods
  (irreversible)": closure authority);
- `finance.fee_concessions.approve` (checker);
- `school.*`, `school.roles.*`, `hr.*` and `payroll.*`.

### 4.5 `cashier` — class financial (3 keys)
- **Keys:** `finance.payments.view`, `finance.payments.record`,
  `finance.charges.view`.
- **No Student capability:** the offline-payment form's Student search is
  authorized by `finance.payments.record` itself
  (`ManualPaymentController`), so no `students.*` key is needed. SR.4
  re-verifies this.

### 4.6 `librarian` — class operational (5 keys)
- **Keys:** `library.catalogue.view`, `library.catalogue.manage`,
  `library.circulation.view`, `library.circulation.manage`,
  `library.fines.view`.
- **Fines:**
  - `library.fines.manage` means **"Publish Library fine policy
    versions"**: financial policy authority, not fine handling. Excluded.
  - `library.fines.void` stays privileged. Excluded.
  - Fines are assessed automatically at check-in
    (`LibraryLoanService` → `LibraryFineService::assessOnCheckIn`), so
    `library.fines.view` is the only fines key ordinary work needs.

### 4.7 Operational desk roles
| Role | Keys | Side effect through a trusted seam (no finance key needed) |
|---|---|---|
| `transport_coordinator` | `transport.routes.view`, `transport.routes.manage`, `transport.vehicles.view`, `transport.vehicles.manage`, `transport.assignments.view`, `transport.assignments.manage` | Assignment creates or withdraws the OPF transport fee selection (`TransportFeeSelectionService`) |
| `hostel_warden` | `hostel.directory.view`, `hostel.directory.manage`, `hostel.residency.view`, `hostel.residency.manage` | Residency creates or withdraws the OPF hostel fee selection |
| `front_office` | `visitor.directory.view`, `visitor.directory.manage`, `visitor.visits.view`, `visitor.visits.manage` | — |
| `stores_officer` | `inventory.directory.view`, `inventory.directory.manage`, `inventory.stock.view`, `inventory.stock.manage` | — |
| `canteen_operator` | `canteen.directory.view`, `canteen.directory.manage`, `canteen.orders.view`, `canteen.orders.manage` | Orders bill through Canteen's existing settlement. **Excludes** `canteen.settings.*` (billing configuration) and every `inventory.*` key |

No hidden platform or authority operation exists in these namespaces. Each
key's label is a module-local operation.

### 4.8 `admissions_officer` — class children's data (2 keys)
- **Keys:** `admissions.view`, `admissions.manage` ("create, update,
  decide, convert").
- **Existing dependency, recorded rather than broadened:**
  - conversion is gated only by `admissions.manage`;
  - it creates the Student and Guardian through `StudentService` and
    `GuardianService`, which perform no capability check of their own, and
    the OPF.3 admission fee selection;
  - so the role creates Student and Guardian records through conversion
    without holding `students.manage`.
- **SR.4:** verifies this and either documents it as the intended admission
  boundary or narrows it. `students.*` is not added.

### 4.9 `communications_coordinator` — class sensitive (inherits content; 6 keys)
- **Keys:** `communications.view`, `communications.send`,
  `communications.reply`, `communications.announce`,
  `communications.templates.manage`,
  `communications.conversations.guardians`.
- **Excludes:**
  - `communications.approve` (checker);
  - `communications.emergency` and `communications.manage` (authority);
  - `communications.audit.view`;
  - `communications.conversations.students` (legal-gated, unheld).

### 4.10 Existing roles (unchanged)
- `school_admin`, `principal`, `teacher` and `staff_self_service` keep
  their capability sets. In SR.2, `school_admin` additionally receives the
  three grant rights.
- `principal` receives no grant right.
- `guardian` is outside the catalogue.

## 5. Explicitly deferred
- **Academic coordinator:** `teaching.assignments.manage` is authority over
  teacher authority and intersects D-02.
- **Exams officer:** `examinations.marks.*` stays with the RES contract.
  RES-L0 covers specifically authorised administrative staff, and a
  material scope expansion requires further review. Marks remain refused in
  production.
- **Statutory payroll role:** `payroll.statutory.*` is legal-gated
  (Checkpoint 9.6, E45).
- **Tenant-custom roles:** ADR 0063 T3; beyond v1.

## 6. Grant-authority model (class-scoped grant rights)

### 6.1 New capabilities (authority class; created in SR.2)
| Grant right | Covers |
|---|---|
| `school.roles.grant.hr` | `hr.categories.*`, `hr.departments.*`, `hr.positions.*`, `hr.employees.{assignments,qualifications,documents,notes}.*`, `hr.employees.personal.manage` |
| `school.roles.grant.hr_sensitive` | `hr.employees.sensitive.*` |
| `school.roles.grant.payroll_sensitive` | `payroll.compensation.sensitive.*` |

- **Initial holder:** `school_admin` only (seeded).
- **Never held by:** `principal`, any operational role, `teacher`,
  `staff_self_service` or `guardian`.
- **No other grant right:** none exists in v1. In particular nothing covers
  `payroll.statutory.*`, `analytics.export`,
  `communications.conversations.students`, `examinations.marks.*`, any
  `*.teacher`/`*.self` key, or any authority-class key.

### 6.2 The rule
A role may be granted (and, under §10.2, revoked) by an issuer in a School
only when **every** capability of the role is either:
1. held by the issuer in that School now (fresh resolution, cache
   bypassed); or
2. mapped to a grant right (§6.1) that the issuer holds in that School now.

### 6.3 Invariants
- **No data access from a grant right:** no module checks a
  `school.roles.grant.*` key for data access; an architecture guard pins
  this.
- **Authority can't be manufactured:** no authority-class capability maps
  to a grant right. A role carrying an authority-class key is grantable only
  by an issuer holding that key.
- **The usual protections hold:**
  - no self-administration (existing);
  - no cross-School grant (membership and School bound);
  - no scope crossing (School roles only; `guardian` keeps its own grant
    path);
  - a role with **zero** capabilities is never grantable or listed.
- **Last-admin protection:** a qualifying administrator remains
  `school.members.manage` + `school.roles.manage`
  (`SchoolAdministrators`). Grant rights are not part of that definition.

## 7. Capability-class model
- **Closed, code-owned map:** every School capability maps to one or more
  classes from a closed set:

  | Class | Meaning |
  |---|---|
  | `operational` | Routine module operation |
  | `sensitive` | Sensitive personal data, or content inheriting it |
  | `children` | Children's personal data (Highly Sensitive per DATA-CLASSIFICATION when combined) |
  | `financial` | Money, ledgers, fees or payroll |
  | `hr` | Employee administration |
  | `hr-sensitive` | Highly Sensitive Employee data |
  | `payroll-sensitive` | Individual compensation amounts and run results |
  | `authority` | Authority over authority: members, roles, grant rights, domains, settings, profile, campuses, integrations, automation, teaching assignments, processing authorizations, emergency, approvals, rollovers |
  | `legal-gated` | Capabilities behind an open legal or product gate: `payroll.statutory.*`, `examinations.marks.*`, `analytics.export`, `communications.conversations.students` |
  | `owned-scope` | `*.teacher`, `*.self` |

- **Where the class lives:**
  - the class set is code (one PHP map, validated by an exhaustive test:
    every `school` capability classified, no unknown key, no key missing);
  - the **grant-right coverage** is also stored in the database
    (`capabilities.grant_right`, nullable; SR.1), so the database coverage
    check (§11.6) can read it;
  - a test pins that the code map and the database column agree.
- **The full v1 map is Appendix A.** Namespace alone never decides a class:
  for example `library.fines.manage` is financial policy, and
  `hr.employees.manage` is identity substrate.

## 8. MFA
- **Staff-role mutations:** every one (invite, grant, revoke, suspend,
  reactivate) keeps **fresh MFA re-verification** (`FreshMfaRequirement`,
  ADR 0059). That does not change.
- **Action step-up, contracted for SR.4:** today these run with no MFA
  step-up:
  - HR-sensitive read and write;
  - payroll-sensitive read and write;
  - payroll approve, post and reverse;
  - ledger post and reverse;
  - offline payment recording;
  - concession approval;
  - webhook administration and secrets;
  - emergency communications.

  For each, SR.4 records the chosen assurance (current `mfa`/`mfa-page`
  assurance for reads, fresh re-verification for consequential writes)
  using the existing MFA architecture only, and implements the adopted set.
  No parallel MFA mechanism.

## 9. Separation of duties (existing rules preserved)
| Pair | Existing rule | Effect of the catalogue |
|---|---|---|
| Fee concession request / approve | App + DB (`fee_concessions_sod_check`) | `accountant` requests; approval stays with holders of `.approve` (`school_admin`). Two actors remain required |
| Payroll prepare / approve | App + DB (`payroll_runs_sod_check`) | `payroll_officer` prepares; `school_admin` approves. Post and reverse also stay with `school_admin`. Approve ≠ post has no actor rule today, and none is added in SR (owner may decide later) |
| Ledger post / reverse | No actor rule | `accountant` posts; reverse stays with `school_admin` |
| Library fine void | No actor rule | Void stays with `school_admin`; `librarian` never holds it |
| Marks correction request / approve | App + DB | Untouched (no operational role carries marks) |
| Communications send / approve | App only | `communications_coordinator` sends; approve stays with `school_admin`/`principal` |

No operational role holds both sides of a pair the domain separates.

## 10. Grant, revoke and catalogue rules (amends ADR 0059)
### 10.1 Grant (invitation, `grantRole`, reactivation)
- **The rule:** §6.2, evaluated **inside** the mutation transaction, after
  the locks (§14).
- **The catalogue offered:** only School-scope, system, non-retired roles
  with at least one capability.

### 10.2 Revoke a single role (`revokeRole`)
- **Coverage:** the issuer must be able to grant that role now (§6.2), so
  they can't remove a role outside their administrative authority.
- **Unchanged:** self-administration ban and last-qualifying-admin
  protection.

### 10.3 Whole-membership off-boarding and reactivation
- **Off-boarding unchanged:** suspension revokes every staff grant, as an
  emergency safety mechanism, under `school.members.manage` +
  `school.roles.manage` with fresh MFA. It is **not** subject to role
  coverage, so a sensitive role never makes a person impossible to
  off-board.
- **Reactivation:** grants new roles under §10.1.

### 10.4 Viewing
- **Catalogue and assignments:** viewing the School role catalogue and role
  assignments requires `school.roles.view`; the staff list stays
  `school.members.view`.
- **No authority from viewing:** viewing never confers grant authority.

## 11. Catalogue database hardening (SR.1)
1. **Runtime catalogue writes revoked:** `REVOKE INSERT, UPDATE, DELETE` on
   `roles`, `role_capabilities` and `capabilities` from `school_os_app`.
   Seeders and migrations use the admin connection. Tests prove the runtime
   role is refused.
2. **History preserved:** `membership_role_assignments.role_id` becomes
   `ON DELETE RESTRICT`. Roles are retired, never deleted. A raw-SQL test
   proves a role with history can't be deleted.
3. **Immutable identity:** `roles.key` and `roles.scope` can't change after
   insert (trigger).
4. **Retirement:**
   - `roles.retired_at` (nullable);
   - a retired role accepts no new grant (trigger on grant INSERT) and is
     excluded from the catalogue;
   - existing grants stay until revoked.
5. **Catalogue filter:** `StaffRoleCatalog` offers only
   `scope = 'school' AND is_system AND retired_at IS NULL` roles with at
   least one capability.
6. **Database grantor coverage:** a trigger on `membership_role_assignments`
   INSERT for `scope = 'school'` roles requires the `assigned_by_user_id`
   User's **active `school`-scope grants in the same School** to cover every
   capability of the role, either directly or through a held grant right
   (`capabilities.grant_right`).
   - **Grantor-less inserts:** refused for the runtime role.
   - **Two sanctioned exceptions:**
     - (a) the admin/migration connection (seeding, tests through
       `pgsql_admin`);
     - (b) the **ADR 0047 bootstrap**: role `school_admin`, on a School
       whose `status = 'provisioning'`, assigned by a User who holds
       `platform.schools.manage` through an active platform grant. This is
       what `SchoolBootstrapAdministrationService` writes today; it
       records the platform actor as `assigned_by_user_id`.
   - **Guardian-scope grants** keep their own link rule, unchanged.
   - Test fixtures that grant roles without an issuer must move to the
     admin connection or a covering issuer.
7. **Guards:** raw-SQL tests for each of the above, plus rollback and
   re-apply proofs.

## 12. Lifecycle and retirement
- **Roles:** created only by a reviewed release (seeder and migration);
  never created, edited, renamed in meaning or deleted at runtime. A role
  with history is retired, never deleted.
- **Catalogue changes:** happen only by release, and reach every holder at
  deploy. A **catalogue snapshot test** (role → exact capability set)
  forces every change to be visible in review. The 60-second capability
  cache window applies; the deploy runbook may flush it.
- **Capability keys:**
  - permanent identifiers, never reused for another meaning;
  - a retired capability is removed from active roles by reviewed seeder
    sync;
  - a **retired-capability list** pins that a retired key is never
    re-created;
  - grant history is unaffected (grants reference roles, not
    capabilities).
- **Grants:** ADR 0059 history unchanged. Revoke, never DELETE; revoked
  rows are immutable; re-grants are new rows.

## 13. Audit
- **Kept:** `school.membership.role_assigned`, `school.membership.role_revoked`,
  `school.membership.suspended`, `school.membership.staff_offboarded`,
  `school.membership.reactivated`, `staff.account_invited`.
- **New: `school.membership.role_grant_refused`** on an escalation refusal
  (grant, invitation, reactivation or revoke). Metadata:
  `schoolMembershipId` (target), `roleKey`, `refusal` (`not_covered`,
  `retired`, `empty_role`, `self_administration`), and the uncovered
  **classes**, never the capability list of another person or any
  personal data. The actor, School, time and request id come from the
  envelope.
- **Sensitive grants:** `role_assigned` additionally records `classes` and
  the `grantRight` used, when one was used.
- **Catalogue changes:** release events (git and seeder), not runtime audit;
  there is no runtime writer.
- **Retention:** E21 governs; no new period.

## 14. Concurrency
- **Lock order (unchanged):**
  1. School access advisory lock (`SchoolAccessLock`);
  2. School FOR SHARE (operational guard);
  3. target membership FOR UPDATE;
  4. its grants FOR UPDATE;
  5. User FOR UPDATE (reactivation).
- **Corrections (SR.2):**
  - invitation **issue** and **acceptance** take the School access lock
    first;
  - §6.2 is re-evaluated inside the transaction after the locks;
  - a real-process race test covers a grant racing the revocation of the
    issuer's own covering grant: either the grant commits first, or the
    revocation commits first and the grant is refused. The database
    coverage trigger is the final word.
- **No role-edit races:** there is no runtime role editing to race.

## 15. Multi-School
- **Unchanged and reaffirmed:** grants belong to memberships; capabilities
  resolve per School (`CapabilityResolver` keys School + User); there is no
  cross-School union; one School per session; off-boarding is per School.
- **Example:** "librarian in School A, accountant in School B" are two
  independent grants.
- **No global or user-level operational role.**

## 16. Teacher, staff self-service and Guardian
- **`teacher`:** unchanged and ownership-gated (TCH-L1/E33 conditions
  intact). No operational role carries `*.teacher`. A teacher who is also a
  librarian holds both roles; teacher authority still needs ActingEmployee
  plus a TeachingAssignment.
- **`staff_self_service`:** unchanged; its three keys stay self-only, and
  no operational role absorbs them. An operational employee typically holds
  `staff_self_service` + their operational role(s).
- **`guardian`:** separate scope, untouched. Dual staff + Guardian
  off-boarding (ADR 0070 §24.3) is unchanged, since operational roles are
  ordinary `school`-scope grants.

## 17. Demo transition (SR.3)
- **Persona mapping:** demo personas switch to the production roles:

  | Demo persona | Production role(s) |
  |---|---|
  | finance officer | `accountant` |
  | librarian | `librarian` |
  | transport coordinator | `transport_coordinator` |
  | reception | `front_office` |
  | hostel warden | `hostel_warden` |
  | communications coordinator | `communications_coordinator` |
  | HR & Payroll officer | **two people or two additive grants**: `hr_officer` + `hr_sensitive_records`, and separately `payroll_officer` |
  | canteen & stores | **`canteen_operator` + `stores_officer`** |

- **Not recreated:** the combined HR+payroll super-role, a finance role
  with ledger reversal, and canteen+stores as one role.
- **Clean-up:** covered `demo.*` role definitions are removed. The demo
  guard (`DemoEnvironmentGuard`) stays.

## 18. Legal and privacy boundary
- **Authorization, not legal authority:** this catalogue is authorization
  machinery, not authority to process data, and it makes **no** legal
  determination.
- **Narrower than today:** it narrows access compared with the only
  production option today (`school_admin`).
- **Gates unchanged:**
  - TCH-L1 (E33): assigned teachers only;
  - RES-L0 (E35): marks not expanded, no exams role;
  - statutory payroll (Checkpoint 9.6, E45) stays gated;
  - HRX-L1 to L4 (health is structurally absent; biometrics, statutory leave
    and loss of pay are open);
  - E21 retention;
  - POR-L1 (E46).
- **SR-L1 (ADR 0058 E47):** a DPO question, **drafted, not sent**, asks
  whether explicit staff personas reaching already-built Highly Sensitive
  datasets need a notice, a processing-register update or conditions
  (`docs/security/SR-L1-STAFF-ROLE-PERSONAS-DPO-QUESTION.md`). Development
  is not blocked; the production effect is the owner's to decide on the
  answer.

## 19. Test guards (to be built in SR.1–SR.4)
**Catalogue and classification:**
- no application authorization by role key (existing guard, extended to
  the new keys);
- every `school` capability classified (exhaustive); code map and the
  `grant_right` column agree;
- no `portal.*` key on a School role; no staff key on `guardian`;
- operational roles carry no `legal-gated`, `owned-scope` or `authority`
  key;
- catalogue snapshot: the exact role → capability set for all 17 School
  roles (13 new + 4 existing);
- only system, non-retired, non-empty School roles are offered.

**Database:**
- a retired role can't receive a grant;
- the runtime role can't INSERT, UPDATE or DELETE `roles`,
  `role_capabilities` or `capabilities` (raw SQL);
- a role with grant history can't be deleted (raw SQL);
- `roles.key` and `roles.scope` are immutable (raw SQL);
- a cross-School grant is refused (application and raw SQL).

**Grant authority:**
- an issuer can't grant beyond held capabilities or grant rights, in the
  application and in raw SQL (coverage trigger);
- a grantor-less runtime insert is refused; the admin connection and the
  ADR 0047 bootstrap (`school_admin`, provisioning School, platform
  `platform.schools.manage`) still work;
- a grant right confers no data access (no module checks
  `school.roles.grant.*`);
- revoke requires coverage; off-boarding still revokes everything;
- every staff-role mutation needs fresh MFA; a refused grant is audited
  (`role_grant_refused`, no personal data);
- the grant vs issuer-revoke race (real processes).

**Behaviour:**
- additive operational roles leave teacher ownership rules unchanged;
- staff detection stays `school`-scope-grant based;
- **employment chain, production profile:** an `hr_officer` (granted by a
  `school_admin` holding `school.roles.grant.hr`) creates an Employee, links
  a User and creates an EmploymentRecord. ActingEmployee then resolves; a
  `teacher` grant plus a TeachingAssignment allow exactly the
  already-authorised teacher surfaces, and nothing else.

## 20. Slice plan
| Slice | Content | Regression |
|---|---|---|
| **SR.0** | This contract; owner decisions; doc corrections; ADR 0058 finding; SR-L1 draft. Docs only | None |
| **SR.1** | Catalogue database hardening (§11): privilege revokes, FK `RESTRICT`, key/scope immutability, retirement, catalogue filter, `capabilities.grant_right`, database grantor coverage with the two sanctioned exceptions, raw-SQL guards, rollback proof | **Early canonical full regression after SR.1** (shared authorization schema, privileges and triggers) |
| **SR.2** | Grant authority and concurrency: seed the three grant rights on `school_admin`; §6.2 in `StaffRoleCatalog`; in-transaction re-check; the revoke rule (§10.2); invitation locking; `role_grant_refused`; sensitive-grant audit metadata; `school.roles.view` activation; race tests | Focused + broad |
| **SR.3** | Production catalogue: seed the thirteen roles (§4); catalogue snapshot guard; class and sensitivity shown in Settings → Staff accounts; demo transition (§17) | Focused + broad (seeders, demo) |
| **SR.4** | Module-by-module least-privilege verification of every role against real paths, including the full employment chain; resolve the cashier Student lookup, admission conversion boundary, librarian fines, accounting and payroll period/accounting needs, front-office lookups, `hr.employees.manage` self-link; implement the adopted action-level MFA (§8) | Focused + broad |
| **SR.5** | Closure audit (architecture, authorization, database privileges, cross-School, races, docs) + canonical full regression | Full |

## 21. Documentation corrections made in SR.0
Dated SR.0 notes now follow every claim that a School can compose, create,
configure or build a custom role, in:
- HR.md (two places);
- PAYROLL.md;
- LIBRARY.md;
- FINANCE.md;
- TRANSPORT.md;
- HOSTEL.md;
- VISITOR.md;
- INVENTORY.md;
- CANTEEN.md;
- STUDENT-ENROLLMENT.md;
- AUTHORIZATION.md (the cashier-style role);
- PHASE-5D-1 (Student conversations).

Each note names the fixed role that covers the persona (or states that
none does), and that tenant-custom roles are deferred. The historical text
is kept.

## 22. Alternatives considered
- **B — tenant-custom roles:**
  - needs School-owned role rows, runtime role writing (reversing §11.1),
    a closed palette, versioned definitions and cache invalidation;
  - an edit would expand every holder;
  - the highest escalation surface.
  - Deferred (T3).
- **C — hybrid:** inherits B's runtime-writing surface. Worth contracting
  only after A shows what tailoring Schools need.
- **Placing the sensitive keys on `school_admin`:** rejected (D3). It
  reverses the HR 8A.0 P1 and Payroll "nobody by default" decisions.
- **Dual administrator for every grant:** rejected for v1 (D4). Fresh MFA
  plus an explicit grant right suffices; domain maker/checker stays.
- **A new authorization scope for operational staff:** rejected. Operational
  staff are School staff; the four scopes are sufficient.

## 23. SR.1 — catalogue database hardening, as built (2026-10-09)

### 23.1 Migration `2026_12_14_090000_harden_staff_role_catalogue`
| Change | As built |
|---|---|
| Runtime catalogue writes | `REVOKE INSERT, UPDATE, DELETE, TRUNCATE` on `roles`, `role_capabilities`, `capabilities` from `school_os_app` (SELECT kept) |
| Grant history | `membership_role_assignments_role_id_foreign` is now `ON DELETE RESTRICT` (was CASCADE). The other references to `roles` (`platform_role_assignments`, `group_role_assignments`, `staff_account_invitation_roles`) were already RESTRICT; `role_capabilities` stays CASCADE (a definition, not history; deleting a role with history is refused anyway) |
| Identity | `trg_roles_identity`: `key`, `scope` **and `is_system`** never change. `is_system` is included because flipping it would move a row into or out of the staff catalogue. Retirement is one-way. Display `name` stays mutable (administrative role only) |
| Retirement | `roles.retired_at`. A retired role receives no new grant (any scope, any writer); active grants stay active and keep authorizing until revoked (§11.4: retirement stops NEW grants only) |
| Grant-right metadata | `capabilities.grant_right` (nullable self-FK, RESTRICT; CHECK not self); `trg_capabilities_grant_right` refuses chains (a grant right is never itself covered). **No grant-right capability exists yet:** SR.2 creates `school.roles.grant.*` |
| Grantor coverage | `trg_membership_role_assignments_grantor` (BEFORE INSERT), below |

### 23.2 The grantor-coverage trigger
**Every new grant:** the role must not be retired and must hold at least
one capability.

**For a `school`-scope role written by the runtime role:**
- `assigned_by_user_id` is required;
- the assigner must be an enabled User, not the grantee, with an ACTIVE
  membership in the **same** School, holding **`school.roles.manage`** (held,
  never covered);
- every capability of the role must be held through the assigner's
  active `school`-scope grants, or covered by a held grant right;
- `guardian`-scope grants never count toward this authority.

**Locking:** the assigner's membership and active grants are read FOR SHARE.

**Exceptions, both narrow:**
- (a) the administrative boundary (`pg_has_role(current_user, owner)`, the
  0O.1A pattern);
- (b) the ADR 0047 bootstrap: `school_admin` on a `provisioning` School,
  assigned by an enabled User holding `platform.schools.manage` through an
  active platform grant.

**Other scopes:** Guardian-scope grants keep their link rule; other scopes
are refused by the scope trigger, which still runs.

| Case | Result |
|---|---|
| School Admin grants `teacher` in their School | allowed |
| Assigner lacks a capability of the role (no covering grant right) | refused: "does not hold or cover every capability" |
| Assigner covers the role but lacks `school.roles.manage` | refused |
| Assigner is a School Admin of another School | refused: "no active membership in this School" |
| Assigner's membership suspended, user disabled, or grant revoked | refused |
| Assigner's only grant is the Guardian role | refused: no `school.roles.manage` |
| Grant to oneself | refused |
| Target role retired | refused: "is retired" |
| Target role has no capability | refused: "has no capabilities" |
| Platform root grants `school_admin`, School `provisioning` | allowed (bootstrap) |
| Platform root grants `principal`, School `provisioning` | refused (no exception; normal path fails) |
| Platform root grants `school_admin`, School `active` | refused |
| Platform auditor or other non-School user grants `school_admin`, School `provisioning` | refused |
| `assigned_by_user_id` NULL from the runtime role | refused: "must name its assigning user" |
| Written below the administrative boundary | allowed (retired and empty still refused) |

**Honest limit:** the database checks the authority of the **recorded**
assigner. It cannot authenticate who that is: there is no per-person
database identity, and every request uses `school_os_app`. A
compromised application can therefore still forge an assigner that does
hold the authority. The rule stops a bug, a raw script or a misrouted call
from writing a grant beyond the recorded assigner's authority, and the
recorded assigner stays accountable in the immutable history and audit.
The bootstrap exception has the same property: it runs on the runtime
role, as every platform request does, but only for `school_admin`, only on
a `provisioning` School, and only for an assigner holding
`platform.schools.manage`. School authority can never satisfy it, since
School roles can never hold platform capabilities (rule 25).

### 23.3 Race safety
- **The race:** a grant racing the revocation (or suspension) of its
  assigner's authority.
- **Resolution:** the assigner's grants and membership are locked FOR
  SHARE, so the revocation either commits first (the grant re-reads under
  the lock and is refused) or waits for the grant to commit.
- **Proof:** two real OS processes with an observed lock wait, both orders
  (`StaffRoleGrantorRaceTest`).
- **Lock order:** the trigger locks only the **assigner's** rows, after
  the application has locked the target's. Every staff-access path takes the
  School access lock first, and invitation acceptance, which does not yet
  take it (SR.2), locks membership then grants in the same order. No cycle
  arises.
- **Left for SR.2:** the application's pre-transaction `grantable()`
  window and the invitation locking. The database is the backstop meanwhile.

### 23.4 Catalogue and seeding
- **Staff catalogue filter:** `StaffRoleCatalog` now offers only
  `scope = 'school'`, `is_system`, non-retired roles with ≥ 1 capability.
  Production is unchanged: the same four roles.
- **Seeding:** `CapabilityAndRoleSeeder` writes through `pgsql_admin`
  (`CATALOGUE_CONNECTION`). CI's seed step carries the admin credentials;
  deployment seeding needs the admin connection, exactly as migrations do
  (ADR 0021).

### 23.5 The local/testing fixture seam
`App\Support\Testing\LocalCatalogueFixtures` handles fixture roles and
grantor-less fixture grants inside the caller's transaction.
- **Mechanism:** an owner-privileged `local_fixtures.exec` function replays
  only role-catalogue and role-grant writes, captured in pretend mode.
- **Where it can be installed:**
  - only in a local or testing environment (refused in code);
  - only through the verified admin connection, by `TestCase` once per
    process and by `ddev demo-reset` (`platform:install-local-fixtures`).
- **Guards:**
  - `platform:verify-database` FAILS if the `local_fixtures` schema exists
    anywhere else;
  - an architecture guard registers it as a sanctioned grant writer.
- **What used it:** the central test helpers (`assignSchoolRole`,
  `createUserWithCapabilities`), 68 adapted test files, the
  database-invariant probes (now run as owner, so the constraint under test
  still fires), and the guarded demo builder.
- **Changed fixture:** one test that granted a zero-capability role now
  uses a plain member, since empty roles are not grantable.

### 23.6 Verification tooling
`DatabaseRoleVerifier` adds these checks:
- `role_catalogue_runtime_read_only`;
- `role_grant_history_restricted`;
- `role_identity_immutable`;
- `role_grantor_coverage_enforced`;
- `role_lifecycle_columns`;
- `local_fixture_seam_absent_outside_development`.

### 23.7 Rollback
- `down()` refuses while any role is retired or any grant right is mapped.
- Otherwise it restores the exact previous state: privileges, CASCADE,
  triggers and columns. That makes a rollback a true inverse, following the
  history-guard migration's convention; documented, since the inverse
  reinstates the pre-SR.1 weaknesses.
- Rollback and re-apply proved IDENTICAL.

### 23.8 Not in SR.1
- the thirteen roles (SR.3);
- the `school.roles.grant.*` capabilities and the application class-grant
  rule, in-transaction re-check, revoke rule, invitation locking and
  `role_grant_refused` audit (SR.2);
- demo persona transition (SR.3);
- the EmploymentRecord gap stays open until SR.3–SR.4.

## 24. SR.2 — grant authority, concurrency and audit, as built (2026-10-09)

### 24.1 The grant rights (exact)
Three `school`-namespace capabilities, class `authority`, seeded by
`CapabilityAndRoleSeeder` (admin connection) onto `school_admin` only. The
coverage lives in one code map, `App\Support\Authorization\CapabilityClasses::GRANT_RIGHTS`,
and the seeder writes it to `capabilities.grant_right` (clearing any mapping
no longer in the map); a test pins code = database = the list below.

| Grant right | Covers (exactly) |
|---|---|
| `school.roles.grant.hr` (15) | `hr.categories.view`, `hr.categories.manage`, `hr.departments.view`, `hr.departments.manage`, `hr.positions.view`, `hr.positions.manage`, `hr.employees.assignments.view`, `hr.employees.assignments.manage`, `hr.employees.qualifications.view`, `hr.employees.qualifications.manage`, `hr.employees.documents.view`, `hr.employees.documents.manage`, `hr.employees.notes.view`, `hr.employees.notes.manage`, `hr.employees.personal.manage` |
| `school.roles.grant.hr_sensitive` (2) | `hr.employees.sensitive.view`, `hr.employees.sensitive.manage` |
| `school.roles.grant.payroll_sensitive` (2) | `payroll.compensation.sensitive.view`, `payroll.compensation.sensitive.manage` |

- `school_admin` holds none of the 19 covered keys (D3 stands); `principal`,
  `teacher`, `staff_self_service` and `guardian` hold no grant right.
- No grant right is covered, chains or self-covers (SR.1 trigger + tests);
  none covers an `authority`, `legal-gated` or `owned-scope` key, `portal.*`,
  `examinations.marks.*` or `payroll.statutory.*`.
- **Class map:** `CapabilityClasses::CLASSES` classifies all 177 `school`
  capabilities (Appendix A's 174 + the three grant rights) from the closed
  set; an exhaustive test fails on an unclassified or unknown key. Classes
  are audit/display metadata, never an authorization input.
- **No data access:** an architecture guard pins that only the code map
  names a `school.roles.grant.*` key in `app/`.

### 24.2 The application decision (`RoleGrantAuthority`)
The application mirror of the SR.1 trigger (which stays the final
backstop). A School role is grantable by an issuer when:
1. the role is not retired and has ≥ 1 capability (grant only);
2. the grantee is not the issuer;
3. the issuer is enabled with an ACTIVE membership in this School;
4. the issuer **holds** `school.roles.manage` there;
5. every capability is held there, or covered by a held grant right.

"Held" is read fresh from the database (never the 60-second cache), from
the issuer's active **`school`-scope** grants only, exactly the trigger's
join, so a `guardian` grant (and a dual persona's portal keys) never counts.
Inside a mutation the issuer's membership and active grants are read
`FOR SHARE`. No role key is an authorization input (guard extended: only
`SchoolBootstrapAdministrationService` names a School role key in `app/`).

Refusal codes (closed, `RoleGrantDecision::REFUSALS`): `inactive_issuer`,
`not_role_manager`, `self_administration`, `retired`, `empty_role`,
`not_covered`, `database_backstop`. User-facing outcomes stay bounded
(`not_authorized` → 403; `role_escalation`, `revoke_escalation`,
`role_unavailable`, `self_administration` → 422) and never name a
capability, grant right, SQL or database role.

### 24.3 Where it is decided, and the lock order
Every path takes the School access lock first; the decision runs **after**
the target locks, inside the transaction. Role keys are resolved before the
transaction only as validation (system `school` roles; retired and emptied
system roles resolve so the decision can refuse them explicitly).

| Path | Order inside the transaction |
|---|---|
| `grantRole` | access lock → School FOR SHARE → `school.roles.manage` (fresh) → target membership FOR UPDATE → already-granted check → **decision** (issuer rows FOR SHARE) → INSERT (trigger) |
| `reactivate` | access lock → School → members+roles manage → target FOR UPDATE → **decision for every chosen role** → User FOR UPDATE → reset revocations → INSERTs |
| `revokeRole` | access lock → School → `school.roles.manage` → target FOR UPDATE → its grant FOR UPDATE → **revoke decision** → UPDATE |
| `suspend` (off-boarding) | unchanged; not coverage-gated (§10.3) |
| invitation `issue` | access lock → School → **decision for every role** → members+roles manage → pending-row lock → INSERT |
| invitation `resend` | access lock → School → pending-row lock → **decision for every role, as the resender** → end + re-issue |
| invitation `revoke` | access lock → School → pending-row lock (no role decision) |
| `accept` | access lock → School → invitation FOR UPDATE → **issuer's current decision for every role** + `school.members.manage` (fresh) → User FOR UPDATE → membership + grants |

No cycle: the issuer's rows are always taken FOR SHARE after the target's
rows and before any User row, and all of these paths already serialize per
School on the access lock.

### 24.4 Revoke model
- **Single-role revoke (§10.2):** the issuer must be able to grant that role
  now (same rule, `forRevoke`), so nobody removes a role outside their own
  administrative authority. A **retired or emptied role stays revocable**
  (retirement stops new grants only).
- **Deliberate asymmetry:** off-boarding (`suspend`) and the reactivation
  reset revoke every staff grant without coverage (§10.3) -- the emergency
  path.
- **Grant-right loss:** an administrator who loses a grant right can no
  longer grant or revoke roles that needed it (`revoke_escalation`), but the
  grant is never stranded: any administrator holding the right can revoke
  it, and off-boarding always removes it. Since the grant rights sit only
  on `school_admin`, and the last-qualifying-administrator rule keeps one,
  a School always has a holder unless a release changes that.

### 24.5 Invitations
- **Issue and resend** run under the access lock and decide every role
  inside it. **Resend re-issues under the RESENDER's authority**: the
  resender becomes the invitation's issuer of record (`invited_by_user_id`,
  unchanged behaviour), so a resend now needs `school.roles.manage` and
  coverage of every role, not only `school.members.manage`. Without this a
  member manager could create an invitation that acceptance would refuse
  anyway. Clarification of ADR 0059 §6, recorded here.
- **Acceptance re-validates the issuer's CURRENT authority** (decided under
  the lock, FOR SHARE): stale invitation-time authority never grants. A
  revoked issuer, a lost grant right, a retired or emptied role all make the
  invitation `invalid` (it stays pending until it expires or is revoked).
- **Retirement concurrency:** retirement is a release action on the admin
  connection and takes no School lock. A retirement committed before the
  grant statement is seen (application decision and trigger both read
  committed state) and refuses; a retirement still uncommitted when a grant
  commits orders after it (the grant predates the retirement and stays
  active until revoked, §11.4). Proven sequentially; there is no lock to
  observe a real-process wait on.

### 24.6 `school.roles.view`
- **Settings → Staff accounts:** the staff list stays `school.members.view`;
  the role catalogue, each member's active roles and an invitation's roles
  are sent only with `school.roles.view` (`canViewRoles`).
- **Catalogue payload:** key, name and `grantable` only -- never capability
  keys, grant rights, retired or non-system rows.
- **No mutation:** every grant/revoke/invite/reactivate route still checks
  `school.roles.manage` (and `school.members.manage` where it did); a
  viewer is 403.
- **Effect:** `principal` (members.view, not roles.view, unchanged) now sees
  who has staff access but not their roles -- the D6 decision applied.

### 24.7 Audit
- **`school.membership.role_grant_refused`:** one event per refused
  decision, emitted only by the deciding service (never for validation --
  unknown role, missing field, already granted, not found -- and never by the
  HTTP capability gate). A refusal inside a mutation is recorded in its own
  transaction after the rollback; an acceptance refusal in the committing
  acceptance transaction. Metadata: `stage` (`grant`, `revoke`,
  `reactivation`, `invitation`, `invitation_resend`,
  `invitation_acceptance`), `schoolMembershipId` or `invitationId`,
  `roleKey`, `refusal`, and for `not_covered` the `uncoveredClasses` -- never a
  capability list, address or personal data. A trigger refusal after an
  allowed decision (never expected) is translated once into
  `database_backstop`.
- **`school.membership.role_assigned`:** when a grant right was used, adds
  `grantRights` (sorted, every right used) and `classes` (sorted classes of
  the capabilities they covered); an ordinary grant is unchanged.
- **Unchanged:** `role_revoked`, `suspended`, `staff_offboarded`,
  `reactivated`, `staff.account_invited`.
- **Clarification of §13:** the refusal set is wider than the four codes
  named there (`inactive_issuer`, `not_role_manager`, `database_backstop`
  added), and the sensitive-grant field is the list `grantRights`.

### 24.8 MFA, bootstrap, scopes
- Every staff-role mutation route still requires a fresh MFA code (test
  over all seven routes). Action-level step-up stays SR.4.
- The ADR 0047 bootstrap is untouched: `SchoolBootstrapAdministrationService`
  writes the first `school_admin` directly (trigger exception unchanged);
  no grant right is involved and this class never runs there.
- Teacher, `staff_self_service` and Guardian semantics are unchanged.

### 24.9 Proof
- `StaffRoleGrantAuthorityTest` (22): mapping, classes, guards,
  grantability, revoke, reactivation, invitations, acceptance, backstop, MFA,
  `school.roles.view`.
- `StaffRoleGrantParityTest`: 16 cases, application decision = raw
  runtime INSERT outcome.
- `StaffGrantAuthorityConcurrencyTest` (real processes, observed waits, both
  orders): grant vs issuer revoke; grant vs grant-right revoke; acceptance vs
  issuer revoke; revoke vs reactivation; revoke vs off-boarding; and
  invitation issue and acceptance waiting on the School access lock ITSELF
  (the holder touches no row they read, so only that lock can serialize).
- **Mutation checks (11, all caught, all restored):** grant-right coverage
  disabled; an authority key added to the grant-right map; an authority
  class on a covered key; the `school.roles.manage` check removed (parity);
  the in-transaction grant decision bypassed; the acceptance re-decision
  bypassed; `school.roles.view` replaced by `school.members.view`; the
  sensitive-grant metadata always written; the refusal audit dropped; the
  revoke rule disabled; the access lock removed from acceptance and from
  issue. The acceptance-lock removal first SURVIVED the issuer-revoke race
  (the revocation's grant-row lock still serialized it), which is why the
  lock-only race above was added.

### 24.10 Not in SR.2
- the thirteen roles, catalogue snapshot and class display (SR.3);
- demo personas (SR.3);
- module-by-module verification and action-level MFA (SR.4);
- the EmploymentRecord gap stays open until SR.3–SR.4.

## Appendix A — capability-class map (v1, all 174 `school` capabilities)
Grant-right coverage appears only where `school_admin` does not hold the key
and the owner approved coverage (§6.1). The three `school.roles.grant.*`
keys (SR.2) are class `authority` and are themselves never covered.

| Capability | Classes | Covered by grant right |
|---|---|---|
| `academics.structure.manage` | operational, sensitive | — |
| `academics.structure.view` | operational, sensitive | — |
| `academics.subjects.manage` | operational, sensitive | — |
| `academics.subjects.view` | operational, sensitive | — |
| `academics.years.manage` | operational, sensitive | — |
| `academics.years.view` | operational, sensitive | — |
| `admissions.manage` | children | — |
| `admissions.view` | children | — |
| `analytics.export` | legal-gated, sensitive | — |
| `analytics.view` | sensitive | — |
| `attendance.manage` | children | — |
| `attendance.teacher` | owned-scope | — |
| `attendance.view` | children | — |
| `automation.manage` | authority, sensitive | — |
| `automation.view` | sensitive | — |
| `canteen.directory.manage` | operational | — |
| `canteen.directory.view` | operational | — |
| `canteen.orders.manage` | children, financial | — |
| `canteen.orders.view` | children, financial | — |
| `canteen.settings.manage` | financial | — |
| `canteen.settings.view` | financial | — |
| `communications.announce` | sensitive | — |
| `communications.approve` | authority, sensitive | — |
| `communications.audit.view` | sensitive | — |
| `communications.conversations.guardians` | sensitive | — |
| `communications.conversations.students` | legal-gated, sensitive | — |
| `communications.emergency` | authority, sensitive | — |
| `communications.manage` | sensitive | — |
| `communications.reply` | sensitive | — |
| `communications.send` | sensitive | — |
| `communications.templates.manage` | sensitive | — |
| `communications.view` | sensitive | — |
| `curriculum.delivery.manage` | operational, sensitive | — |
| `curriculum.delivery.teacher` | owned-scope, sensitive | — |
| `curriculum.delivery.view` | operational, sensitive | — |
| `enrollments.manage` | children | — |
| `enrollments.rollovers.manage` | authority, children | — |
| `enrollments.rollovers.view` | children | — |
| `enrollments.view` | children | — |
| `examinations.definitions.manage` | sensitive | — |
| `examinations.definitions.view` | sensitive | — |
| `examinations.grade_scales.manage` | sensitive | — |
| `examinations.grade_scales.view` | sensitive | — |
| `examinations.marks.correction.approve` | children, legal-gated, sensitive | — |
| `examinations.marks.correction.request` | children, legal-gated, sensitive | — |
| `examinations.marks.lock` | children, legal-gated, sensitive | — |
| `examinations.marks.manage` | children, legal-gated, sensitive | — |
| `examinations.marks.teacher` | legal-gated, owned-scope, sensitive | — |
| `examinations.marks.view` | children, legal-gated, sensitive | — |
| `examinations.papers.manage` | sensitive | — |
| `examinations.papers.view` | sensitive | — |
| `finance.accounts.manage` | financial | — |
| `finance.charges.manage` | financial | — |
| `finance.charges.view` | financial | — |
| `finance.fee_assessments.run` | financial | — |
| `finance.fee_concessions.approve` | financial | — |
| `finance.fee_concessions.request` | financial | — |
| `finance.fee_concessions.view` | financial | — |
| `finance.fee_structures.manage` | financial | — |
| `finance.fee_structures.view` | financial | — |
| `finance.ledger.post` | financial | — |
| `finance.ledger.reverse` | financial | — |
| `finance.ledger.view` | financial | — |
| `finance.payments.record` | financial | — |
| `finance.payments.view` | financial | — |
| `finance.periods.manage` | financial | — |
| `guardians.manage` | children | — |
| `guardians.view` | children | — |
| `hostel.directory.manage` | operational | — |
| `hostel.directory.view` | operational | — |
| `hostel.residency.manage` | children | — |
| `hostel.residency.view` | children | — |
| `hr.categories.manage` | hr | `school.roles.grant.hr` |
| `hr.categories.view` | hr | `school.roles.grant.hr` |
| `hr.departments.manage` | hr | `school.roles.grant.hr` |
| `hr.departments.view` | hr | `school.roles.grant.hr` |
| `hr.employees.assignments.manage` | hr | `school.roles.grant.hr` |
| `hr.employees.assignments.view` | hr | `school.roles.grant.hr` |
| `hr.employees.documents.manage` | hr | `school.roles.grant.hr` |
| `hr.employees.documents.view` | hr | `school.roles.grant.hr` |
| `hr.employees.manage` | hr | — |
| `hr.employees.notes.manage` | hr | `school.roles.grant.hr` |
| `hr.employees.notes.view` | hr | `school.roles.grant.hr` |
| `hr.employees.personal.manage` | hr | `school.roles.grant.hr` |
| `hr.employees.personal.view` | hr | — |
| `hr.employees.qualifications.manage` | hr | `school.roles.grant.hr` |
| `hr.employees.qualifications.view` | hr | `school.roles.grant.hr` |
| `hr.employees.sensitive.manage` | hr-sensitive | `school.roles.grant.hr_sensitive` |
| `hr.employees.sensitive.view` | hr-sensitive | `school.roles.grant.hr_sensitive` |
| `hr.employees.view` | hr | — |
| `hr.leave.approve` | hr | — |
| `hr.leave.configure` | hr | — |
| `hr.leave.manage` | hr | — |
| `hr.leave.self` | owned-scope | — |
| `hr.leave.view` | hr | — |
| `hr.positions.manage` | hr | `school.roles.grant.hr` |
| `hr.positions.view` | hr | `school.roles.grant.hr` |
| `hr.staff_attendance.manage` | hr | — |
| `hr.staff_attendance.self` | owned-scope | — |
| `hr.staff_attendance.view` | hr | — |
| `integrations.api_clients.manage` | authority, sensitive | — |
| `integrations.api_clients.view` | sensitive | — |
| `integrations.webhooks.manage` | authority, sensitive | — |
| `integrations.webhooks.view` | sensitive | — |
| `inventory.directory.manage` | operational | — |
| `inventory.directory.view` | operational | — |
| `inventory.stock.manage` | operational | — |
| `inventory.stock.view` | operational | — |
| `library.catalogue.manage` | operational | — |
| `library.catalogue.view` | operational | — |
| `library.circulation.manage` | children | — |
| `library.circulation.view` | children | — |
| `library.fines.manage` | children, financial | — |
| `library.fines.view` | children, financial | — |
| `library.fines.void` | children, financial | — |
| `lms.assignments.manage` | children, sensitive | — |
| `lms.assignments.teacher` | owned-scope | — |
| `lms.assignments.view` | children, sensitive | — |
| `lms.content.manage` | sensitive | — |
| `lms.content.teacher` | owned-scope | — |
| `lms.content.view` | sensitive | — |
| `payroll.accounting.manage` | financial | — |
| `payroll.compensation.sensitive.manage` | financial, payroll-sensitive | `school.roles.grant.payroll_sensitive` |
| `payroll.compensation.sensitive.view` | financial, payroll-sensitive | `school.roles.grant.payroll_sensitive` |
| `payroll.compensation.view` | financial | — |
| `payroll.payslips.self` | owned-scope | — |
| `payroll.periods.manage` | financial | — |
| `payroll.runs.approve` | financial | — |
| `payroll.runs.post` | financial | — |
| `payroll.runs.prepare` | financial | — |
| `payroll.runs.reverse` | financial | — |
| `payroll.runs.view` | financial | — |
| `payroll.statutory.exports.generate` | financial, legal-gated | — |
| `payroll.statutory.identifiers.manage` | financial, legal-gated | — |
| `payroll.statutory.identifiers.view` | financial, legal-gated | — |
| `payroll.statutory.manage` | financial, legal-gated | — |
| `payroll.statutory.view` | financial, legal-gated | — |
| `payroll.structures.manage` | financial | — |
| `payroll.structures.view` | financial | — |
| `school.audit.view` | sensitive | — |
| `school.campuses.manage` | authority | — |
| `school.campuses.view` | operational | — |
| `school.domains.manage` | authority | — |
| `school.domains.view` | operational | — |
| `school.members.manage` | authority | — |
| `school.members.view` | operational | — |
| `school.profile.manage` | authority | — |
| `school.profile.view` | operational | — |
| `school.roles.manage` | authority | — |
| `school.roles.view` | sensitive | — |
| `school.settings.manage` | authority | — |
| `school.settings.view` | operational | — |
| `students.manage` | children | — |
| `students.processing_authorizations.manage` | authority, children | — |
| `students.processing_authorizations.view` | children | — |
| `students.view` | children | — |
| `syllabus.manage` | operational, sensitive | — |
| `syllabus.view` | operational, sensitive | — |
| `teaching.assignments.manage` | authority, sensitive | — |
| `teaching.assignments.view` | sensitive | — |
| `timetable.periods.manage` | operational, sensitive | — |
| `timetable.periods.view` | operational, sensitive | — |
| `timetable.schedule.manage` | operational, sensitive | — |
| `timetable.schedule.view` | operational, sensitive | — |
| `transport.assignments.manage` | children | — |
| `transport.assignments.view` | children | — |
| `transport.routes.manage` | operational | — |
| `transport.routes.view` | operational | — |
| `transport.vehicles.manage` | operational | — |
| `transport.vehicles.view` | operational | — |
| `visitor.directory.manage` | operational, sensitive | — |
| `visitor.directory.view` | operational, sensitive | — |
| `visitor.visits.manage` | operational, sensitive | — |
| `visitor.visits.view` | operational, sensitive | — |
