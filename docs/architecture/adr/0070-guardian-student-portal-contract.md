# ADR 0070: Guardian/Student Portal Contract (POR.0)

- Status: **Accepted — POR.0 contract (2026-10-08). POR.1 IMPLEMENTED FOR
  DEVELOPMENT (2026-10-08, §24):** Guardian foundation + read-only
  Communications inbox, refused in code outside local/testing
  (`PortalAvailability`). **POR.2 IMPLEMENTED FOR DEVELOPMENT (2026-10-09,
  §25):** GuardianStudentScope per-Student authority + a linked Student's
  minimized Attendance (active academic year only, MFA). **POR.3 IMPLEMENTED
  FOR DEVELOPMENT (2026-10-09, §26):** a linked Student's fee statement and
  the payments applied to that Student (read-only, MFA, never a whole shared
  payment). **POR.4 IMPLEMENTED FOR DEVELOPMENT (2026-10-09, §27):** a
  Guardian's existing School conversations and idempotent, in-app-only text
  replies (MFA; no Guardian-initiated conversation). **POR.5 CLOSURE AUDIT
  (2026-10-09, §28): POR DEVELOPMENT — CLOSED / PRODUCTION GATED** (two
  executable corrections, §28.4). No legal status changes: **POR-L1 (ADR 0058 row E46) is a
  DRAFT REQUEST — NOT SENT, NOT ANSWERED**, and every production statement
  below waits on it.
- Date: 2026-10-08
- Programme: **POR — Guardian/Student portal** (`MASTER-ROADMAP.md`,
  post-foundation programme 6).
- Builds on:
  - ADR 0039 §5D (Guardian identity, `AccountLink`), corrected by this ADR
    (§3.2);
  - ADR 0038 (processing authorization) and its S5 lock order;
  - ADR 0045 (role scopes; amended by §8.2 when POR.1 is built);
  - ADR 0049 (bearer tokens carry no MFA);
  - ADR 0054 (host classification, School context);
  - ADR 0056 (account recovery, `credential_version`);
  - ADR 0059 (staff off-boarding; amended by §9.4 when POR.1 is built);
  - ADR 0063 (owner-scoped authorization: ActingEmployee + TeachingOwnership,
    identical not-found bodies);
  - ADR 0068 §25–§27 (the availability-block pattern);
  - ADR 0058 (register: E21, E28, E30–E32, E35–E42, new E46).
- Evidence: the POR Architecture & Readiness Audit (2026-10-08, baseline
  `8b0b795`, verdict READY FOR POR.0 CONTRACT).

## 1. Purpose and scope
POR gives Guardians, and later Students, a narrow, read-mostly, School-scoped
view of information the School already holds about **their own** linked
Students. This ADR fixes the contract before any code exists:
- identity;
- authorization;
- lifecycle;
- data access;
- audit;
- legal and production gates;
- the slice plan.

It is not a legal determination and not production approval.

## 2. Actors

| Actor | v1 status |
|---|---|
| **Guardian** (an adult with a recorded Guardian persona in one School, signed in through an activated account) | In scope (POR.1–POR.4) |
| **Student** (a Student signing in as themselves) | **Out of scope and blocked** (§16; POR-L1 Q19–Q21) |
| Staff | Unchanged; never gains a portal capability (§8) |
| Platform / Group actors | Never reach a portal surface (ADR 0044/0045 unchanged) |

## 3. Current-state baseline (verified at `8b0b795`)
### 3.1 What exists
- **Guardian accounts.**
  - `AccountInvitationService` issues `identity_account_invitations`.
  - `GuardianAccountActivationService::accept` creates, in one transaction:
    - a User;
    - an **active** SchoolMembership;
    - one active `student_guardian_account_links` row.
  - It grants **no role** (`GuardianAccountActivationService.php:124-156`).
- **Recovery and sessions.** Guardians are eligible for ADR 0056 recovery.
  `credential_version` is enforced on every web request.
- **Links.**
  - One active link per membership, Student or Guardian (partial unique
    indexes).
  - Revoked by status, never deleted. Composite same-School keys, forced RLS.
  - Managed under `guardians.manage` / `students.manage`.
  - **Nothing revokes a link automatically** when a relationship, Guardian,
    Student or membership changes.
- **Relationships.**
  - `student_guardian_relationships` carries `is_primary` and
    `is_legal_guardian`; there is no status or end column.
  - Unlink is a hard DELETE behind the S5 Student-first lock and the 409
    `GUARDIAN_RELATIONSHIP_IN_USE`.
- **Communications.** Announcements reach a Guardian in-app through
  active link → active membership → `recipient_user_id`
  (`AnnouncementService.php:1440-1496`). `CommunicationInboxReadModel` is
  scoped to one User.

### 3.2 What an activated Guardian can reach today
- **Capabilities:** a role-less membership resolves to `[]`
  (`CapabilityResolver.php:71-110`).
- **Communications is unreachable.** The inbox, conversations, threads and
  announcements all require `communications.view` before any recipient
  check. So, **contrary to ADR 0039:290-293**, a Guardian cannot open any
  in-app message. ADR 0039 is corrected by this ADR.
- **What they can reach:**
  - `/app` (School name and switcher only);
  - account security, MFA enrolment and API-token pages;
  - Communications preferences;
  - archiving a thread they take part in, and downloading a thread
    attachment;
  - `/api/v1/schools/{school}/context` (`capabilities: []`);
  - **`/app/school-setup`, which has no capability check** and shows five
    setup-progress flags to any member (`SchoolSetupController.php:45-58`).
    This is fixed in POR.1 (§9.6).
- **No Student actor exists.** There is no student role, and
  `identity_account_invitations.student_id` is never written.
- **Guardian access can't be fully revoked today.** A Guardian-only
  membership cannot be suspended: `StaffAccessService` refuses `not_staff`
  (`StaffAccessService.php:248-251`), and nothing else suspends it. §9
  makes off-boarding a POR.1 prerequisite.

## 4. Identity is not authority
> **A Guardian AccountLink proves which Guardian persona the authenticated
> User acts as in a School. It does not by itself authorize access to any
> Student.**

This keeps ADR 0039 / Phase 5D.1 ("reachability, never authority"). Every
portal access needs **all three** of the following. Each is checked on every
protected operation, in this order, and any missing or ambiguous part fails
closed:
1. **Capability:** a `portal.*` key resolved through `CapabilityResolver`
   for this School (§8).
2. **ActingGuardian:** who the User acts as (§5).
3. **GuardianStudentScope:** which Students, for this surface (§6). Only for
   Student-scoped surfaces.

## 5. ActingGuardian (Identity-owned)
### 5.1 Resolution
The resolution chain:

> authenticated User → its SchoolMembership in the **current** School →
> exactly one active Guardian AccountLink on that membership → the linked
> Guardian persona → at least one live Guardian↔Student relationship (§5.3).

It mirrors HR's `ActingEmployeeResolver`:
- resolved fresh from current rows on every request;
- **never cached** in the session or in TenantCache;
- held with `FOR SHARE` inside a transaction wherever a write depends on it
  (none in POR.1–POR.3).

### 5.2 What "active" means

| Part | Active when | Otherwise |
|---|---|---|
| User | authenticated, not `is_disabled`, current `credential_version` stamp | signed out (existing middleware) |
| School | `schools.status = 'active'` (`RequireSchoolContext`, `SchoolOperationalGuard`) | no School context |
| SchoolMembership | `status = 'active'`, `school_id` = current School | not a Guardian here |
| AccountLink | `status = 'active'`, `guardian_id` set, on that membership | not a Guardian here |
| Guardian persona | `guardians.status = 'active'`, same School | not a Guardian here |
| Relationships | ≥ 1 row for that Guardian in that School (§5.3) | not a Guardian here |

"Not a Guardian here" means:
- every portal surface in that School answers the fixed portal refusal
  (§18.2);
- the portal navigation is not offered.

### 5.3 Edge cases (deterministic)
- **Membership missing, suspended or inactive:** not a Guardian here.
- **Guardian persona missing or inactive:** not a Guardian here.
- **Zero active links:** not a Guardian here.
- **Multiple active links:** impossible; the `sgal_one_active_per_membership`
  index enforces it. If it ever happens, fail closed.
- **Stale link** (link active, Guardian has no relationships left): not a
  Guardian here. Losing the last relationship removes portal authority in
  that School without any staff action (§9.2).
- **Guardian in several Schools:** one ActingGuardian per School. Each
  School is resolved independently (§7).
- **Guardian in School A, staff in School B:** unrelated memberships. The
  portal in A and the staff UI in B never mix.
- **Guardian and staff in the same School:**
  - one membership carries both the staff roles and the Guardian link;
  - staff capabilities and portal capabilities stay disjoint (§8.2);
  - each UI shows only its own data (the Guardian inbox filters to the
    Guardian persona's deliveries, §10.1);
  - their lifecycles are separate (§9.4).

### 5.4 Where it lives
Identity owns `ActingGuardian` (the resolver and its value object). It reads:
- its own link and membership rows;
- the Guardians module's persona and relationship existence, through a
  Guardians read seam, never their tables.

Session School context (`ResolveSchoolContext`, re-validated on every
request) is enough; no new School resolver is introduced.

## 6. GuardianStudentScope (Guardians-owned)
### 6.1 Question answered
> Which Students may this ActingGuardian access, for this exact portal
> surface, in the current School?

### 6.2 Rules
- **Source of authority:** derived **live** from
  `student_guardian_relationships` for the ActingGuardian's persona.
  - Never from the AccountLink, invitation history, names, contact data,
    membership, role name, or cached Student ids.
  - Never cached, in the session or anywhere else, for authority.
- **Same School:** the relationship, Student and Guardian rows are all
  School-owned (composite keys, forced RLS). The scope reads only the
  current School, under `TenantContext`.
- **Eligibility predicate:** **`is_legal_guardian = true`** — *an
  engineering fail-closed default pending legal/privacy determination
  (POR-L1 Q6–Q9), not a legal conclusion.*
  - POR-L1 may broaden, narrow or replace it.
  - Any change needs an amendment of this ADR.
- **Ended or removed relationship:**
  - unlink is a hard DELETE, so the Student leaves the scope on the next
    request;
  - clearing `is_legal_guardian` removes the Student the same way;
  - a court restriction or restricted contact has **no field today**. Until
    POR-L1 answers Q9–Q10, a School enforces it by unlinking or clearing
    the flag. Whether POR.2 adds a per-relationship portal restriction is a
    decision recorded for POR-L1.
- **Student state:** a Student is in scope only while
  `students.status = 'active'`. Withdrawn, transferred, inactive and
  graduated Students are excluded.
  - Whether a Guardian keeps read access to history after a withdrawal is
    POR-L1 Q14–Q15.
  - Until then the default is: **no access**.
- **Guardian state:** an inactive Guardian has no ActingGuardian (§5.2).
- **Several Guardians or Students:**
  - each Guardian's scope is computed alone;
  - siblings appear only through their own qualifying relationship;
  - no Guardian sees another Guardian's activity (§13.4).
- **Several Schools:** scopes never span Schools (§7).
- **Stale links:** the link only names the persona. A link without a
  relationship grants no Student (§5.3).

### 6.3 Non-enumerability and object access
On Student-scoped surfaces, every Student reference comes **only** from the
scope's own list for that request. The following are indistinguishable:
- an unknown id;
- another Guardian's Student;
- another School's Student;
- a revoked or ended relationship;
- a Student excluded by policy or Student state.

All of these converge on **the identical 404 body**, following ADR 0063
TCH.6 (identical not-found bodies on owned surfaces). In addition:
- **Ids:** UUID-constrained routes; malformed ids get the framework's plain
  404.
- **No search, autocomplete, counts or totals** that span Students outside
  the scope.
- **Validation messages** never name a Student.
- **Receipt and attachment ids** are resolved through the owning surface's
  scope check, never fetched directly (§10.3, §10.1).
- **Timing:** checks are done in one query shape whether the Student exists
  or not, where reasonably avoidable. Exact timing equality is not promised.

### 6.4 Where it lives
Guardians owns `GuardianStudentScope` (`App\Domain\Guardians\Application`).
- Consumers call it the way TCH consumers call `TeachingOwnership`: Attendance
  (POR.2) and Payments/Fees (POR.3).
- Guardians never depends on them.
- Not reused from TCH: teacher ownership is dated and assignment-based;
  Guardian authority is current and relationship-based (§20 R15).

## 7. School resolution (multi-School Guardians)
- **One active School per session** (ADR 0054; `ResolveSchoolContext` on a
  School host or a re-validated session selection). There is no cross-School
  aggregate in v1.
- **Selecting a School:** on the platform host from the `/app` switcher, which
  lists only the User's **active** memberships in active Schools
  (`DashboardController.php:36-38`). On a School host, it is that School
  (only for a signed-in active member).
- **Re-validation:** every request re-validates the membership and the
  School. ActingGuardian and the scope are recomputed on top.
- **Losing the current School:** a membership suspended, a link revoked, or
  the last relationship ended means the next request in that School gets the
  portal refusal. A suspended membership drops out of the switcher.
- **Stale identifiers:** after switching School, a URL carrying a Student id
  from another School resolves through the new School's scope, so it gets the
  identical 404 (§6.3).
- **No bypass:** every portal query runs under `TenantContext` and RLS. No
  portal surface accepts a `school_id` from the client (rule 19).

## 8. Capability model
### 8.1 Catalogue (planned, not created in POR.0)

| Key | Slice | Grants (with ActingGuardian and, where named, the scope) |
|---|---|---|
| `portal.communications.view` | POR.1 | Read own Guardian deliveries: inbox, unread, announcement, attachment |
| `portal.attendance.view` | POR.2 | Read in-scope Students' attendance |
| `portal.fees.view` | POR.3 | Read in-scope Students' fee statements and their own receipts *(as built, §26.5: the payments applied to that Student, never a receipt)* |
| `portal.communications.reply` | POR.4 (built, §27) | Reply in a conversation the Guardian takes part in (always with `portal.communications.view`) |

### 8.2 Delivery: a closed Guardian system role in its own scope
- **The role:** one system role, `guardian`, carries **only** `portal.*`
  capabilities. It exists only to deliver them.
- **Authorization checks capabilities, never the role name** (rule 24). A
  guard test pins that no application code compares the role key.
- **Its own scope (amends ADR 0045 and rule 25 when POR.1 is built):**
  - the role gets its own `roles.scope = 'guardian'`;
  - `portal.*` keys get their own `portal` capability namespace *(as built,
    §24.1: the namespace is named `guardian`)*;
  - so `trg_role_capabilities_scope` refuses a `portal.*` key on any staff
    role, and any staff key on the Guardian role. That makes the separation
    **database-enforced**, as rule 25 requires.
  - `membership_role_assignments` accepts a `guardian`-scope grant **only**
    while the membership carries an active Guardian AccountLink (a database
    check in POR.1).
  - Rejected alternative: `portal.*` in the `school` namespace with a test
    only — convention, not database enforcement (§20 R2).
- **Who grants it:**
  - **Only** Guardian activation, and its revocation, through Identity's
    Guardian lifecycle service (§9).
  - Never staff-assignable: it is excluded from `StaffRoleCatalog`, staff
    invitations, `StaffAccessService` grant/reactivate, and any role picker.
  - It is never runtime-assignable by a person.
- **Staff vs portal:**
  - neither implies the other;
  - **"staff" means holding a `school`-scope role**;
  - `StaffAccountDirectory`, `StaffAccessService::lockStaffMembership` and
    `SchoolAdministrators` must filter by `scope = 'school'` in POR.1.
    Today they treat *any* grant as staff, so a Guardian would appear in the
    staff directory.
- **Scoping and history:** grants are per membership, so per School.
  `membership_role_assignments` keeps history (revoke, never delete,
  ADR 0059).
- **Cache:** every grant or revocation calls
  `CapabilityResolver::forgetCache($user, $school)`. Because ActingGuardian
  is checked live anyway, a stale cache can never extend authority past a
  link revocation.
- **Not tied to the User account:** losing portal authority never depends on
  deleting the User (§9).

## 9. Guardian lifecycle and off-boarding (mandatory before POR.1 is published)
### 9.1 Invariant
> A Guardian who no longer has an authorized portal relationship must have
> portal authority removed deterministically and without relying on
> staff-role lifecycle behavior.

### 9.2 Which mechanism controls which event

| Event | Controlling mechanism | Effect |
|---|---|---|
| **Loss of access to one Student** (relationship removed, legal-guardian status cleared, restriction recorded per POR-L1) | the Guardian↔Student relationship (Guardians), read live by GuardianStudentScope | That Student disappears on the next request. Other in-scope Students remain. No role, link or membership change |
| **Last relationship in a School ends** | the same, through ActingGuardian's "≥ 1 relationship" rule (§5.2) | All portal authority in that School stops on the next request. The role grant and link stay in place but grant nothing. Guardians never calls into Identity (rule 4); a School that wants a clean record runs Guardian off-boarding |
| **Loss of Guardian access to one School** (the School ends portal use for this Guardian) | **Guardian off-boarding** (new in POR.1, Identity), under `guardians.manage` AND `school.members.manage`, in one transaction: revoke the AccountLink (existing `unlinkGuardian`); revoke the `guardian` role grant (history kept); and **suspend the membership only if it holds no `school`-scope role** | All portal authority in that School stops. Audit, links and grant history remain. Staff access, if any, is untouched |
| **Complete Guardian account suspension** (compromise, incident, legal restriction) | Per School: the off-boarding above, or membership suspension. Account-wide: the existing credential reset (`CredentialChangeService` through `platform:user-password-reset` or recovery bumps `credential_version`, which signs out every session) | Every session ends at once. Access in each School ends with that School's off-boarding |
| **Reinstatement** | Guardian re-invitation and activation (new link, new grant row); membership reactivation | Never revives an old grant or link row. *(POR.5, §28.7: a Guardian-only membership suspended by off-boarding has no application reactivation path yet — staff reactivation refuses a non-staff membership — so re-activation leaves the portal refused. Fails closed; deferred.)* |

### 9.3 Why not one flag
- A **relationship** answers "which child".
- A **link** answers "which persona".
- A **role grant** answers "which capabilities".
- **Membership** answers "is this person in this School at all".

Overloading any one of them would break the others' guarantees: for
example, deleting relationships to end portal access would destroy
safeguarding data. User deletion is never a revocation tool.

### 9.4 Staff and Guardian in one membership (amends ADR 0059 when POR.1 is built)
- **Staff off-boarding** (ADR 0059) of a membership that also holds an
  active Guardian link revokes the **`school`-scope roles only** and leaves
  the membership active, so Guardian portal access remains.
  Today's behaviour suspends the membership, which would silently end it.
- **Guardian off-boarding** never touches `school`-scope roles or a
  membership that holds one.
- **Membership suspension** is the "this person is out of the School
  entirely" tool. It ends both identities.

### 9.5 Development data
- Pre-POR.1 activated Guardians hold no role.
- POR.1 grants the role on activation only. Whether existing development
  Guardians get it is a seed/demo matter, never a production migration.

### 9.6 `/app/school-setup` (mandatory POR.1 correction)
`SchoolSetupController@index` gets a capability check. The five flags are
School administration state, so the index is gated by `school.profile.view`,
the read capability its sibling setup pages already use
(`SchoolSetupController.php:63`). A Guardian gets 403.

## 10. Permitted surfaces (each read-only unless stated)
### 10.1 POR.1 — Communications inbox (`portal.communications.view`)
- **Contents:**
  - deliveries addressed **to the Guardian persona**: the audience
    snapshot's `guardian_id`, or recipient rows created through that
    persona's link;
  - unread;
  - announcement detail;
  - attachments.
- **Not included:** a dual-role User's staff mail, which stays in the staff
  Hub.
- **Read state:** opening an announcement may mark **that recipient's own**
  delivery read. This is recipient-owned state, not a change to School
  content. POR.1 stays a read-only content slice.
- **Not in POR.1:** reply, compose, forward, export.
- **Attachments:** downloaded only through `authorizeRead` (participant or
  recipient), audited `communication_attachment.downloaded` (existing).

### 10.2 POR.2 — Attendance (`portal.attendance.view` + scope + MFA)
- **Shows:** per in-scope Student, by date range, status only
  (`present|absent|late|excused`; no reasons exist).
- **Excludes:** class rosters, other Students, teacher identity beyond the
  register's display name (decided in POR.2).
- **Needs:** a new Attendance per-Student read seam.

### 10.3 POR.3 — Fees (`portal.fees.view` + scope + MFA)
- **Statement:** per in-scope Student, through a new Guardian-safe entry
  point beside the staff-bound `StudentFeeStatementReadService`.
- **Receipts** *(as built, §26.5: no receipt is ever shown; only the
  amounts applied to the authorised Student, with the receipt number as a
  reference)*: shown only when **every** allocated Student is in scope;
  otherwise only that Student's allocation line is shown (sibling isolation;
  `PaymentReceiptReadService` exposes `studentIds` and `manualReference`
  today).
- **No payment initiation.**

### 10.4 POR.4 — Replies and conversations (`portal.communications.reply`)
- Under the School conversation policy and the existing participant
  authorization.
- Creating content is a different legal question from reading it (POR-L1 Q5).
- **Built (§27):** existing-thread reading and text replies only; Guardian
  initiation deferred.

## 11. Prohibited and deferred surfaces
- marks, results, report cards, transcripts (E39–E42, E35–E37 unchanged);
- Student accounts and Student self-service (§16);
- payment initiation and online payment (E28, ADR 0057);
- exports and PDFs (each needs its own authorisation);
- `/api/v1` portal access and bearer-token portal access;
- native or mobile portal access;
- cross-School aggregate views;
- sibling data not independently in scope;
- staff capabilities for Guardians, and Guardians in arbitrary School roles;
- role-name authorization;
- unrestricted Student search;
- unrestricted historical access;
- Guardian-to-Guardian visibility.

## 12. MFA
- **"MFA required" means:** an enrolled, confirmed factor **and** current
  session assurance bound to that factor. This is the existing
  `mfa` / `mfa-page` middleware (`RequireMfa`; `MfaChallengeService::
  hasValidAssurance`; `MFA_ASSURANCE_WINDOW_MINUTES`, default 60; ADR 0063
  §44 factor binding).
  - Without a factor: a refusal pointing to enrolment.
  - With a stale window: step-up.
- **Per surface:**
  - **Attendance (POR.2), Fees (POR.3):** MFA required on every route,
    including list and detail. Each request re-checks the window, so a window
    that lapses between list and detail triggers step-up.
  - **Communications inbox (POR.1):** not required by this contract. POR-L1
    Q22–Q23 may impose it for production.
  - **Conversations and replies (POR.4, §27.8):** MFA required on every
    route, list and thread included.
- **Fresh re-verification** (`FreshMfaRequirement`, a code entered now) is
  reserved for consequential actions. No read in POR.1–POR.3 needs it.
  No parallel MFA framework.
- **Bearer tokens:** excluded. They carry no MFA (ADR 0049), so no portal
  capability is ever honoured through `/api/*` (§17).

## 13. Audit
### 13.1 Minimum envelope (Student-scoped reads)
`school_audit_events` through `AuditRecorder`:
- actor User, School, timestamp, request id (existing columns);
- event type `<surface>.guardian.<action>`;
- metadata:
  - `guardianId`;
  - `accountLinkId`;
  - `studentId` where the read is Student-scoped;
  - `surface`;
  - bounded parameters such as a date range or academic year;
  - counts.

**Never** Student content: no statuses, amounts, message text, names.

### 13.2 Per surface

| Surface | Event | Notes |
|---|---|---|
| Inbox list / unread | none per list | Low sensitivity. Read state is recorded on open |
| Announcement open | none (existing `read_at`) | Recipient-owned state |
| Attachment download | `communication_attachment.downloaded` (existing) | Unchanged |
| Attendance view | `attendance.guardian.viewed` (POR.2) | Includes `studentId`. This deliberately differs from `TeacherAttendanceReadAudit`, which omits Student ids because teacher reads are roster reads; a Guardian read is about one child |
| Fee statement / receipt | `fee_statement.guardian_viewed`, `payment_receipt.guardian_viewed` (POR.3) | Same fields as `fee_statement.viewed`, plus the Guardian fields |

*POR.5 correction (2026-10-09):* the events as built are named
`fee_statement.guardian.viewed` and `payment_allocation.guardian.viewed` (no
receipt is shown). Attachment downloads now also carry `surface` and
`guardianId`. The full, current inventory, including the conversation and
lifecycle events, is §28.10.

### 13.3 Classification and retention of the audit
- Portal audit rows identify a Student and a Guardian. That is Sensitive
  personal data; no content makes it Highly Sensitive.
- They live in `school_audit_events`: append-only, retention category
  `audit` (D1). No new retention category.
- E21 still governs the period.

### 13.4 Visibility
- Portal audit is staff-reviewable only, under the existing
  `school.audit.view` capability.
- No Guardian sees another Guardian's portal activity (POR-L1 Q12 may
  require more).

## 14. Data classification (see `DATA-CLASSIFICATION.md`)

| Data | Tier |
|---|---|
| Communications content (announcements, messages, attachments) | **Inherits its business content**; at least Sensitive when addressed to or naming a person. A message about a child's health or finances is Highly Sensitive |
| Delivery metadata (recipient, read state) | Sensitive |
| Student attendance | Sensitive (unchanged) |
| Fee statements, receipts, concessions | Highly Sensitive (unchanged) |
| Guardian identity, link and relationship data | Sensitive (Guardian data, unchanged) |
| Portal authorization grants and audit events | Sensitive |

Children's data stays Highly Sensitive whenever combined as
`DATA-CLASSIFICATION.md` says. Nothing ships to real Schools before POR-L1.

## 15. Retention and legal gates
- **Retention:** *(POR.4 added only the column
  `communication_messages.idempotency_key`, §27.5; no new category.)* no new tables in POR.1–POR.3 beyond the planned grant rows,
  which sit in `membership_role_assignments` and its existing history. No
  periods are invented. E21 governs.
- **Legal gates:** see the gate map in §18.1.

## 16. Student-account boundary
Student accounts and Student-facing surfaces are **not part of POR.1–POR.3**
and are **blocked for design and development**: `AUTHORIZATION.md`'s
"age-appropriate capability sets" is a legal-review design question.

A later contract, authorised separately and only after POR-L1 Q16–Q21, must
cover:
- provisioning, authentication, email/contact and recovery;
- MFA;
- age thresholds and age-appropriate capabilities;
- self-service, and Guardian visibility of Student activity;
- the age-18 transition and adult-Student control;
- School transfer and suspension.

No Student-account infrastructure is prepared in POR.0–POR.3.

## 17. Web, API and mobile boundary
- **POR.1–POR.4 are web only:** Inertia pages, session authentication,
  `mfa` / `mfa-page` per §12.
- **Why:**
  - session MFA assurance exists;
  - bearer tokens carry none (ADR 0049; the teacher Attendance API was made
    development-only for this reason, ADR 0063 §43);
  - RES.4 and TCH set the session-only precedent.
- **Not included:**
  - **No `/api/v1` portal route.** A guard test pins that no API route
    checks a `portal.*` key.
  - **Personal access tokens:** a Guardian may still mint one, but it
    reaches nothing portal-related. Whether to hide or refuse token
    issuance for Guardian-only memberships is decided in POR.1. *(POR.5,
    §28.7: not decided. A Guardian may still mint a token, which reaches no
    portal surface and carries no capability; open, not a blocker.)*
  - **Mobile:** `apps/mobile` is a Phase 0A skeleton and imposes nothing.
- API and mobile are later, separately gated work.

## 18. Production refusal strategy
### 18.1 Gate map (current; no legal status changed)

| Surface | Design | Development | Production |
|---|---|---|---|
| Guardian Communications inbox | Allowed | After POR.1 authorisation | Gated: POR-L1 (E46), E21 |
| Guardian Attendance | Allowed | After authorisation; fail-closed predicate | Gated: POR-L1, E21 |
| Guardian fees and receipts | Allowed | After authorisation | Gated: POR-L1, E21, E30–E32 |
| Guardian replies | Allowed | Separately sliced (POR.4) | Gated: POR-L1, Communications production conditions |

*POR.5 (2026-10-09):* every Guardian row above is now **built for
development** (POR.1–POR.4) and refused in code in production. The current
gate matrix is §28.12.
| Student accounts and surfaces | **Blocked** | Blocked | Blocked |
| Marks, results, report cards, transcripts | **Blocked** (E39–E42) | Blocked | Blocked (also E35–E37) |
| Online fee payment | Out of scope | Blocked (E28) | Blocked |

POR-L1 never authorises anything that E39–E42 govern.

### 18.2 Availability gate
POR follows the StudentMark pattern (ADR 0068 §27,
`StudentMarkAvailability`):
- a code-level `PortalAvailability`, **not configurable**, allows only
  `local` / `testing`;
- every portal route gets the `portal-development-only` middleware, and every
  portal Application entry point re-asserts it;
- the refusal is one fixed 403 `PORTAL_UNAVAILABLE`, naming no School,
  Student or environment;
- lifting it is a reviewed code change made only after POR-L1 is recorded and
  the production gates are met.

So "implemented" cannot silently become "production enabled". An
architecture guard pins that the gate is present on every route and entry
point.

## 19. Implementation slices (planned at POR.0; POR.1–POR.4 built for development, POR.5 closure, §24–§28)

| Slice | Content |
|---|---|
| **POR.1** | Guardian actor foundation + read-only Communications inbox (see the list below) |
| **POR.2** | GuardianStudentScope + Attendance per-Student read seam + "My child's attendance" + `attendance.guardian.viewed` + MFA |
| **POR.3** | Guardian fee statement and receipt entry point + sibling isolation + Guardian audit + MFA (E30–E32 production gates unchanged) |
| **POR.4** | Guardian replies and conversations (`portal.communications.reply`) under the School conversation policy |
| **POR.5** | Closure audit and handoff |
| Later, separately gated | Student accounts/portal; API; mobile; marks/results/report cards/transcripts (E39–E42) |

POR.1 contains:
- ActingGuardian;
- the `guardian` scope and system role (ADR 0045 and rule 25 amendment);
- `portal.communications.view`;
- Guardian off-boarding (§9.2) and the staff/Guardian lifecycle split
  (ADR 0059 amendment);
- staff-detection scope filtering (§8.2);
- `/app/school-setup` gating (§9.6);
- `PortalAvailability` (§18.2);
- the web portal shell and navigation;
- the own-delivery inbox, unread and announcement views, attachments;
- allow, deny, cross-School and RLS tests;
- architecture guards.

**Regression cadence:** POR.1 is a major cross-domain integration (Identity,
authorization, Communications, membership lifecycle, database authorization
constraints), so **CLAUDE.md rule 82's "sooner after any major cross-domain
integration" applies: the canonical full regression runs on the final POR.1
tree before publication** (owner direction for POR.1, 2026-10-08, superseding
the 5/5-only wording recorded here at POR.0).

## 20. Alternatives considered

| # | Alternative | Disposition |
|---|---|---|
| R1 | AccountLink as direct authorization | **Rejected.** It is identity and reachability only (ADR 0039, 5D.1); it doesn't cascade (§3.1) |
| R2 | `portal.*` in the `school` namespace, separated by convention | **Rejected.** Rule 25 requires database enforcement; a dedicated `guardian` scope gives it (§8.2) |
| R3 | Staff capabilities (`communications.view`, `finance.*`, `attendance.view`) granted to Guardians | **Rejected.** School-wide data, not relationship-scoped (`FINANCE.md:594-599`) |
| R4 | Role-name checks | **Rejected** (rule 24) |
| R5 | Guardians holding arbitrary School roles | **Rejected.** Breaks the staff/portal separation |
| R6 | A separate portal User table | **Rejected.** ADR 0039:557-559; reuse Users, memberships and recovery |
| R7 | Cross-School aggregate in v1 | **Deferred.** One School per session (§7) |
| R8 | API-first | **Rejected for v1.** Bearer tokens carry no MFA |
| R9 | Mobile-first | **Rejected for v1.** Only a skeleton exists |
| R10 | Attendance first | **Deferred to POR.2.** No per-Student read seam yet |
| R11 | Fees first | **Deferred to POR.3.** Highly Sensitive, a staff-bound seam, sibling risk |
| R12 | Student accounts first | **Blocked** (§16) |
| R13 | Relationship deletion as the only off-boarding tool | **Rejected.** It destroys safeguarding data and can't express School-level or account-level loss (§9.3) |
| R14 | User deletion as revocation | **Rejected.** It destroys audit and history; credential reset and suspension cover incidents |
| R15 | Caching Guardian identity or Student scope in the session | **Rejected.** Revocation must act on the next request |
| R16 | All linked parents authorized automatically | **Rejected pending POR-L1.** Fail-closed `is_legal_guardian` |
| R17 | Reusing teacher ownership semantics unchanged | **Rejected.** Teacher ownership is dated and assignment-based; Guardian authority is current and relationship-based. The *composition* pattern (capability + actor + ownership) is reused |

## 21. Adversarial review (POR.0)

| Scenario | Rule |
|---|---|
| Guardian A puts Guardian B's Student id in a URL | Not in A's scope, so the identical 404 (§6.3) |
| Siblings, one relationship revoked | That Student leaves the scope on the next request; the other stays (§9.2) |
| Guardian in two Schools | Separate ActingGuardian and scope per School; switch required (§7) |
| Membership lost while signed in | The next request fails `RequireSchoolContext` / ActingGuardian |
| Link revoked mid-session | ActingGuardian fails on the next request; capability cache flushed; live check regardless (§8.2) |
| Relationship deleted mid-session | Scope recomputed per request (§6.2) |
| Guardian persona inactive | No ActingGuardian (§5.2) |
| Guardian is also staff | Disjoint capabilities; Guardian inbox filtered to the persona (§5.3, §10.1) |
| Staff roles removed, portal should remain | Staff off-boarding revokes `school`-scope roles only (§9.4) |
| Portal role removed, staff should remain | Guardian off-boarding never touches staff roles or a staff membership (§9.4) |
| School switched with stale route ids | Resolved in the new School's scope, so 404 (§7) |
| Student transfers School | Status leaves `active` in the old School, so out of scope. A new School needs its own relationship and link |
| Student becomes inactive | Out of scope (§6.2) |
| Student turns 18 | **Deferred to POR-L1 Q16–Q17.** No automatic change before the answer, and no Student-surface work |
| Announcement received before revocation, opened after | Authority is checked at open time; a revoked Guardian can't open it. Delivery history is kept |
| Receipt covers several Students | Shown whole only if all are in scope; otherwise only the in-scope line (§10.3) |
| Attachment id guessed | `authorizeRead` (participant or recipient) plus portal capability and ActingGuardian; otherwise refusal (§10.1) |
| Guardian opens `/app/school-setup` | 403 after the POR.1 correction (§9.6). **Blocker before POR.1 publication** |
| Guardian uses an API token | No `/api/*` route checks `portal.*`, so nothing reachable (§17) |
| MFA lapses between list and detail | Every MFA route re-checks, so step-up (§12) |
| Production gate missing or misconfigured | `PortalAvailability` is code, not config, and re-asserted in services; a guard test pins it (§18.2) |
| Legal determination later expires or is withdrawn | Re-review triggers (POR-L1 Q31–Q32) feed back into §18; the gate stays closed until re-cleared |

**Weaknesses found and recorded:**
1. **Guardian-only memberships can't be suspended** → mandatory POR.1
   off-boarding.
2. **Staff detection treats any grant as staff** → POR.1 scope filter.
3. **Staff off-boarding would end portal access for dual-role people** →
   ADR 0059 amendment in POR.1.
4. **`/app/school-setup` is ungated** → POR.1 correction.
5. **No court-restriction field** → POR-L1 Q9–Q10.
6. **Receipts can name siblings** → POR.3 isolation.

None blocks this contract.

## 22. Ownership and dependency directions
- **Identity:** ActingGuardian, the Guardian lifecycle service (off-boarding,
  role grant/revoke), activation. Reads Guardians through a read seam.
- **Guardians:** GuardianStudentScope and relationship semantics. Reads
  Students' status through Students' read seam.
- **Authorization infrastructure:** `CapabilityResolver`, roles, scopes and
  database triggers.
- **Communications, Attendance, Payments/Fees:** own their data and read
  seams. They consume ActingGuardian and GuardianStudentScope, never the
  reverse.
- **POR:** **not a data-owning domain.** The portal shell (routes, Inertia
  pages under `App/Portal`, `PortalAvailability`) composes the owners'
  seams.
- **Dependency rules (rule 4):** no module depends back on a consumer. The
  Guardian lifecycle service in Identity may read Guardians; Guardians never
  depends on Identity's portal code.

## 23. External decisions and re-review triggers
- **POR-L1 (E46):**
  - answers the questions in
    `docs/security/POR-L1-GUARDIAN-STUDENT-PORTAL-REVIEW-REQUEST.md`;
  - is recorded verbatim in a future `POR-L1-…-DETERMINATION.md`;
  - updates E46 by a dated, reviewed change;
  - is filled in by no engineer.
- **Re-review triggers for this ADR:**
  - any POR-L1 answer;
  - a new surface;
  - a Student-facing surface;
  - API or mobile access;
  - any marks or results proposal (E42);
  - a predicate change;
  - a jurisdiction change.
- **Not affected:**
  - E16 (valid through 2026-10-28, expires 2026-10-29; production qualification, outside POR);
  - the TCH-L1 historical-date clarification (teacher Attendance; separate,
    `docs/security/TCH-L1-HISTORICAL-DATE-CLARIFICATION-REQUEST.md`).

## 24. POR.1 — as built (2026-10-08; development only)

### 24.1 Scope and authority
- **Fourth scope:** migration `2026_12_12_090000_add_guardian_authorization_scope`,
  amending ADR 0045 and rule 25:
  - `roles_scope_check` admits `guardian`;
  - `capabilities_guardian_namespace_check`: `portal.*` keys live only in the
    `guardian` namespace;
  - `membership_role_assignments` accepts `school` and `guardian` roles, and a
    new `guardian` grant needs an active Guardian link on the same membership;
  - `trg_sgal_portal_grant_guard`: an active Guardian link cannot end (or move
    membership or persona) while its membership still holds an active
    `guardian` grant;
  - two new revocation reasons, `staff_offboarded` and `guardian_link_revoked`.
- **Rollback:** `down()` refuses while any `guardian` grant or new-reason row
  exists. Otherwise it restores the three-scope rules exactly; rollback proof
  passed.
- **Capability and role:**
  - `portal.communications.view` (namespace `guardian`) only;
  - the closed system role `guardian` (scope `guardian`, not
    runtime-assignable) carries only it;
  - no Attendance or Fees key is reserved.
- **Grant writer:** `GuardianPortalRoleGrants` (Identity) is the only writer of
  `guardian` grants:
  - idempotent `grant()`, called only by `GuardianAccountActivationService`
    when the Guardian's active link sits on that membership;
  - `revokeAll()`, called before any Guardian link ends.
- **Staff code:** `StaffRoleCatalog` grants `school`-scope roles only, so the
  Guardian role is never staff-assignable.

### 24.2 Identity and scope
- **`ActingGuardianResolver` (Identity):** User → active membership in the
  current active School → exactly one active Guardian link → active persona →
  ≥ 1 eligible relationship. Fresh on every call; no cache, session or
  TenantCache.
- **`GuardianStudentScope` (Guardians):**
  - POR.1 uses only `isActiveGuardian()` and `hasEligibleStudent()`;
  - the predicate is `is_legal_guardian = true` to an `active` Student — an
    engineering fail-closed default pending POR-L1, not a legal conclusion;
  - live, School-scoped, never cached.

### 24.3 Lifecycle as built
- **One Student lost** (relationship deleted, or the legal-guardian flag
  cleared): out of scope on the next check. Other eligible Students keep the
  portal.
- **Last eligible relationship lost:** ActingGuardian fails on the next
  request. The grant stays recorded but grants nothing (tested); Guardians
  never calls Identity.
- **Link revoked** (`AccountLinkService::unlinkGuardian`, `guardians.manage`):
  the School access lock, then the `guardian` grant is revoked
  (`guardian_link_revoked`), then the link. Capability cache forgotten inside
  the transaction and after commit.
- **Guardian off-boarding** (`GuardianOffboardingService`,
  `POST /app/guardians/{guardian}/portal-offboard`):
  - needs `guardians.manage` + `school.members.manage` + a fresh MFA code;
  - never the actor's own membership;
  - revokes the grant, then the link, then any **pending Guardian invitation**
    (so it cannot re-create them), then suspends the membership only when it
    holds no active staff grant;
  - audit `guardian.portal_offboarded`.
- **Staff off-boarding of a dual staff + Guardian membership:** staff roles
  only (`staff_offboarded`); membership stays active; reactivation re-grants
  staff roles (ADR 0059 amendment).
  - "Dual" means a **live, activated Guardian**: an active `guardian` grant,
    which only invitation acceptance creates, AND a resolving ActingGuardian.
  - A bare account link an administrator attached (for example to their own
    membership) never shields a staff membership from suspension. This came
    from the POR.1 security review and is tested.
- **Membership suspension:** denies both identities. `CapabilityResolver` and
  `RequireSchoolContext` already require an active membership.

### 24.4 Account security (what each mechanism does)

| Mechanism | Effect |
|---|---|
| Session invalidation / forced sign-out | `credential_version` bump (`CredentialChangeService`: recovery, `platform:user-password-reset`); every existing session is signed out (`EnforceCredentialVersion`). The person can sign in again with the new credential |
| Credential reset or recovery | as above. Not a suspension |
| Membership suspension | one School; both identities in it denied |
| Guardian off-boarding | one School; portal and link end; membership suspended only if no staff role |
| Staff off-boarding | one School; staff roles end; Guardian identity kept |
| Durable account-wide disable | **none for an administrator.** `users.is_disabled` exists, and every authorization path honours it, but the only application writer is E21.4 User minimisation (an erasure step, not a suspension tool). Complete account suspension is therefore per School (off-boarding or suspension in each School) plus a credential reset. A durable account-wide disable is a separate platform feature, not POR scope |

### 24.5 Production block
- `PortalAvailability` (code, `local`/`testing` only; no config, env or
  request input) behind the `portal-development-only` middleware (fixed 403
  `PORTAL_UNAVAILABLE`), and re-asserted first in every
  `GuardianAnnouncementReadService` method. Tested with the middleware
  bypassed.
- Dashboard navigation shows the portal only when the block, the capability
  and a live ActingGuardian all hold.

### 24.6 Surfaces
- **Routes:** `GET /app/portal/communications` (`?unread=1`), `GET
  …/announcements/{announcement}` and `GET
  …/announcements/{announcement}/attachments/{attachment}/download`.
  - all session web routes (Inertia), UUID-constrained;
  - `portal-development-only` first, then
    `capability:portal.communications.view`;
  - then ActingGuardian in the controller.
- **Visibility** (`GuardianAnnouncementReadService`, Communications):
  published announcements whose audience snapshot names this Guardian persona
  in this School. Not a dual-role User's staff mail; anything else is the same
  404.
- **Read state:** opening marks only that User's own in-app delivery read
  (existing `AnnouncementService::markRead`).
- **Attachments:** only those of a visible announcement; audited
  `communication_attachment.downloaded` with `surface = guardian_portal` and
  `guardianId`.
- **Other changes:**
  - `/app/school-setup` now needs `school.profile.view` (§9.6);
  - the dashboard "School setup" link follows it.
- **Not built:** replies, compose, conversations (POR.4), Student surfaces,
  API, mobile.

### 24.7 Race and lock review
- **The School access lock:** `SchoolAccessLock` (the existing per-School
  `staff-access:` advisory key) is taken first by:
  - staff access changes;
  - Guardian activation;
  - Guardian unlink;
  - Guardian off-boarding.

  So activation vs off-boarding, staff vs Guardian off-boarding, and grant vs
  revoke serialize before any row lock. Row order after it is School FOR
  SHARE (where taken) → link → membership → grants. No path takes them in
  another order.
- **Reads:** inbox reads take no locks. An inbox request racing an off-boarding
  or a relationship removal either sees the state before the commit (a
  read-only answer) or after it (denied). The next request is always denied.
- **Capability cache:** forgotten inside the transaction and after commit. The
  live ActingGuardian check bounds authority regardless of the cache.
- **School switch:** every request re-validates School context. A stale route
  id resolves in the new School, giving the same 404.
- **Guardian unlink vs the S5 lock order:** the Guardian↔Student relationship
  path (Student first, then grants and relationships) touches no link,
  membership or grant row, so the orders don't intersect.

### 24.8 Staff attachment download now needs `communications.view`
- **What was found:** the POR.1 security review (MEDIUM) found that the staff
  Hub's `GET /app/communications/attachments/{attachment}/download` checked
  no capability, only creator, recipient or participant. So once staff
  off-boarding could leave a dual person's membership active, they could keep
  downloading staff thread and announcement attachments by id.
- **The fix:** the route now calls
  `authorizeCapability('communications.view')`, like every other Hub page
  (including the announcement page itself). Every existing authorization
  rule is unchanged after that.
- **Who loses access:** a role-less member who was a resolved recipient (for
  example of a School-wide announcement) — they could never open the
  announcement itself.
- **Guardians:** they read their own attachments through the portal route.
- **Tests:** the existing recipient test now covers both a refusal and an
  allow; a dual-persona regression test was added.

### 24.9 Legal
**POR-L1 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.** No production clearance
is inferred. E39–E42, E35–E37, E21, E28 and E30–E32 are unchanged.

## 25. POR.2 — GuardianStudentScope and linked-Student Attendance, as built (2026-10-09; development only)

### 25.1 POR.1 regression evidence, stated precisely
POR.1's full regression ran on candidate **tree** `9a1412be…`. The published
**commit** `a288292` has exactly that tree (`git rev-parse a288292^{tree}`
gives `9a1412be43e2879d7013b8c524c228a002fb2af1`). The tested and published
contents are byte-identical; the commit adds only the commit object.

### 25.2 Capability
- `portal.attendance.view` is in the `guardian` namespace and on the closed
  `guardian` role only. The scope and namespace triggers keep it off every
  staff role (raw-SQL tested).
- Existing Guardians receive it through the role's capability set. No grant
  row is added or duplicated.

### 25.3 GuardianStudentScope, the per-Student authority
- **One predicate source**, `eligibleStudentIdsQuery()`, live and School-scoped.
  A Student is reachable only when all of these hold:
  - a `student_guardian_relationships` row for the ActingGuardian's persona;
  - `is_legal_guardian = true`;
  - the Student's status is `active`;
  - the Guardian persona's status is `active`;
  - all rows are in this School (explicit `school_id` joins, plus RLS and the
    composite keys).

  `is_legal_guardian` is an **engineering fail-closed default pending POR-L1**,
  not a legal conclusion. Withdrawn, transferred or inactive Students are out
  of scope, so there is no historical access until POR-L1 answers.
- **The full rule for one request:**
  - session authentication and the current School;
  - `portal-development-only`;
  - `capability:portal.attendance.view`;
  - `mfa-page`;
  - ActingGuardian (active membership, one active Guardian link, active
    persona, ≥ 1 eligible relationship);
  - the requested Student in GuardianStudentScope;
  - PortalAvailability, re-asserted in the service.
- **Identity is not Student authority (tested):**
  - the `guardian` role is not "every Student in the School";
  - the link is not "every Student of the account";
  - a membership (even a principal's) is not "every Student" — a principal who
    is a Guardian reaches only their own child through the portal.
- **Student choices** come only from `eligibleStudents()`, the Guardian's own
  scope: id and display name. There is no search, roster, autocomplete or
  sibling outside the scope. One eligible Student redirects straight to them.

### 25.4 History window (deliberately narrow)
- **Only the School's active academic year.** `academic_years` allows one
  `active` year per School (database-enforced), and every attendance record
  stores its `academic_year_id`; that is the window.
- **Within it:**
  - never after the School-local today (`schools.timezone`); every bound,
    `from` and `to`, is a calendar day in the School's own timezone (a
    security-review correction: mixing UTC once hid "today" east of UTC);
  - at most **62 days** per request;
  - default: the **last 30 days**;
  - `from`/`to` are optional and clamped to the year start, the year end and
    today;
  - an over-long range is clamped from its end.
- **Nothing else:**
  - no earlier academic year;
  - no relationship start date inferred from `created_at` (no repository
    contract makes it an authorization-effective date);
  - a School with no active year shows nothing.
- This is a **development bound, not a legal access period**. POR-L1 Q2/Q15
  decide production.

### 25.5 Attendance read seam (Attendance-owned)
- **Where:** `App\Domain\Attendance\Application\Portal\GuardianAttendanceReadService`.
  It never reuses or bypasses a staff Attendance service.
- **One query:** `attendance_records` ⋈ `attendance_sessions` ⋈
  `student_enrollments`, each joined on `id` and `school_id`, filtered by
  - `se.student_id = :student`;
  - `se.student_id IN (GuardianStudentScope predicate)`;
  - `ar.academic_year_id = active year`;
  - the date window.

  Authorization and data read are one statement under one snapshot.
- **Returned:** `date`, `periodStart`, `periodEnd`, `status`
  (present/absent/late/excused). Ordered by date descending, then period start,
  then record id (deterministic).
- **Excluded:**
  - teacher;
  - submitter;
  - subject, section and enrollment;
  - the correction flag (`corrected_at`) and history;
  - session and record ids;
  - any classmate or sibling row.

  Attendance has no reason or remark fields to exclude.
- **Empty:** the same page shape with `records: []`.

### 25.6 Routes and UI (web/session only)
- `GET /app/portal/attendance`: the Guardian's own eligible Students; exactly
  one redirects to that Student.
- `GET /app/portal/attendance/students/{student}`: UUID-constrained, with
  optional `from`/`to` (`Y-m-d`; anything else is one fixed validation error
  that never echoes the input).
- Every route: `portal-development-only` FIRST, then
  `capability:portal.attendance.view`, then `mfa-page`.
- **UI:** read-only pages, no edit, export or PDF. The dashboard "Attendance"
  link shows only when the gate, the capability and a live ActingGuardian hold.

### 25.7 MFA
`mfa-page` means an enrolled, confirmed factor (otherwise 403
`mfa_required_not_enrolled`) **and** current session assurance within
`MFA_ASSURANCE_WINDOW_MINUTES` (default 60), bound to that factor. A stale
window or a reset factor gives 401 `mfa_step_up_required`. Every request
re-checks it. The inbox stays MFA-free by contract.

### 25.8 Audit
- One `attendance.guardian.viewed` per successful read: actor User, School,
  `guardianId`, `accountLinkId`, `studentId`, `academicYearId`, `from`, `to`,
  `recordCount`, `surface = guardian_portal`.
- Never a status or any Attendance content. Every field is server-derived, so
  request input cannot forge any of them.
- The chooser (names of one's own children) is not audited, like the inbox
  list.
- **Denied reads write nothing:** the existing convention is that only
  successful protected reads are audited, and an inaccessible Student's data
  is never touched.
- The event lives in `school_audit_events`, category `audit` (D1); E21 governs
  the period.

### 25.9 Consistency and races (no new locks)
Reads take no locks. A read racing any of the following sees either the
before or the after state of the committed transaction, and the next request
is always denied:
- a relationship revoked or `is_legal_guardian` cleared;
- a Student deactivated;
- the Guardian link revoked or the membership suspended;
- the role grant revoked.

The data query re-applies the scope predicate, so a revocation committed
between the scope check and the read already excludes the rows in the same
request. Other cases:
- **School switch:** a stale URL resolves in the new School's scope, so 404.
- **MFA expiry:** the next request steps up.
- **Capability cache:** bounded by the live ActingGuardian and scope checks.

No new lock order is introduced.

### 25.10 Legal
**POR-L1 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.** Its questions (Q2
period, Q6–Q9 who qualifies, Q13–Q15 ending access and history, Q22–Q23 MFA)
already cover this slice, so the draft is unchanged. *(POR.5 correction:
POR.2 did narrowly annotate Q2, with the pre-relationship-dates question.)* No production clearance
is inferred.

## 26. POR.3 — Guardian fee statement and payments applied to a Student, as built (2026-10-09; development only)

### 26.1 Capability and authority
- `portal.fees.view` is in the `guardian` namespace and on the closed
  `guardian` role only (raw-SQL tested: no staff role can hold it). Existing
  Guardians receive it through the role, with no new grant row.
- **Every read requires:**
  - session authentication and the current School;
  - `portal-development-only`;
  - `capability:portal.fees.view`;
  - `mfa-page` (enrolled factor plus current, factor-bound assurance);
  - a fresh ActingGuardian;
  - the requested Student in **GuardianStudentScope** (the POR.2 predicate,
    unchanged: legal guardian, active Student, active Guardian, same School);
  - PortalAvailability, re-asserted in every service method.
- A Finance administrator who is also a parent still sees only their own
  child through the portal (tested). Staff authority never widens it.

### 26.2 Read seam (Payments-owned; the staff services are unchanged)
- `App\Domain\Payments\Application\Portal\GuardianFeeReadService` is a
  separate entry point. The staff `StudentFeeStatementReadService` and
  `PaymentReceiptReadService` are neither reused nor changed (a guard pins
  this).
- **Charge facts:** come through Fees' new
  `ChargeService::statementLinesForStudentWithin()`, which embeds the scope
  predicate in the charge query, so authorization and read are one
  statement.
- **Balances:** from the authoritative `ChargeStateReader`:
  - outstanding = amount − allocations − live adjustments;
  - nothing owed once a charge is cancelled.

  There is no second calculation. Totals are sums of the shown lines.

### 26.3 Statement projection
- **Per charge:**
  - description, fee head, billing period, due date;
  - `currentYear` (whether it belongs to the active academic year);
  - status (`outstanding` / `settled` / `cancelled`);
  - amount, adjustments (live total), paid total, outstanding;
  - for each payment applied **to that charge**: `paymentId` (for the detail
    page), settled date, method, the School receipt **number**, and the
    **amount applied to that charge** only.
- **Totals:** charged (excluding cancelled charges), adjusted, paid,
  outstanding.
- **Never shown:**
  - charge, fee-head and academic-year ids;
  - adjustment categories (concession, scholarship, waiver) and concession
    ids;
  - assessment time;
  - late-fee link ids;
  - a Payment's total, source or provider, manual reference, settlement
    account, journal or recorder;
  - any other Student's charge, allocation or id;
  - any family or household total.

### 26.4 History boundary
- **Shown:**
  - every charge of the School's **active academic year**;
  - any charge of another year (earlier, or already assessed for a later
    year) that is **still outstanding**.
- **Not shown:** settled or cancelled charges of other years.
- **So:** the Guardian's outstanding total equals the Student's
  authoritative all-years outstanding (tested against the staff statement),
  and nothing owed is hidden.
- With no active year, only charges still owed are shown.
- Financial periods whose detail has expired (E21.3A2) are handled by the
  same `ChargeStateReader`.
- This is a development bound, not a legal access period (POR-L1 Q3, Q4,
  Q15).

### 26.5 Payments: "applied to this Student", never a whole receipt
- **The problem:** a Payment may pay several Students' charges (siblings),
  and its receipt (`PaymentReceipt`, ADR 0062 §17) documents the **whole
  Payment**.
- **Guardians are never shown that document or the Payment's total.** The
  detail page `GET
  /app/portal/fees/students/{student}/payments/{payment}` is **"Payment
  applied to {Student}"**. It shows:
  - the settled date, method and the School receipt number (labelled as a
    reference: "this is not the receipt itself");
  - only that Payment's allocations to **this Student's visible charges**;
  - their sum, `appliedTotal`.
- **Not found:** a Payment with no such allocation (a sibling's, another
  School's, unknown, or one allocated only to settled other-year charges)
  is the same 404.
- **Example:** one 940.00 cash Payment pays 300.00 of Student A's tuition and
  640.00 of sibling B's fee. A Guardian authorised only for A sees "300.00
  applied to this fee", and "Applied to A: 300.00" on the detail page. They
  never see 940.00, 640.00, B's name, id, fee or count, or the other
  allocation (tested; a mutation reporting the payment total is caught).
- **Why the receipt number is safe:** it is shared-payment metadata that
  discloses no sibling, no amount and no count. It resolves nothing in the
  portal; it is only a reference for asking the School.
- **Guardian of both siblings:** they see each child separately, with no
  combined view.

### 26.6 Reversals, refunds, corrections
- Posted Payments are immutable, with no refund, void or reversal (rule 91,
  ADR 0031).
- What the domain does have is shown truthfully through the charge state:
  - a **cancelled charge** shows `cancelled` and owes nothing;
  - a **cancelled adjustment** no longer counts in the live adjustment
    total.
- The Guardian has no action of any kind.

### 26.7 Audit
- `fee_statement.guardian.viewed`: `guardianId`, `accountLinkId`,
  `studentId`, `academicYearId`, `lineCount`, `surface`.
- `payment_allocation.guardian.viewed`: `guardianId`, `accountLinkId`,
  `studentId`, `paymentId`, `allocationCount`, `surface`.
- Never an amount, description or sibling id.
- One event per successful read. Denied reads write nothing.
- Category `audit` (D1); E21 governs the period.

### 26.8 Consistency
- No new locks.
- **Revocations:** a read racing any of these sees the before or the after
  state, and the next request is denied:
  - relationship, link or membership revocation;
  - Student deactivation;
  - role revocation.
- **Financial writes:** an allocation, cancellation or adjustment is
  committed atomically by the Finance services, so a read sees each
  transaction whole or not at all.
- **Other cases:**
  - stale URLs after a School switch: 404;
  - MFA expiry: step-up;
  - stale capability cache: bounded by the live checks.

### 26.8a Security review (POR.3)
- **One MEDIUM finding, fixed and tested:** payment dates were shown one day
  early in Schools east of UTC. `settled_at` is School-local midnight stored
  in UTC; the Guardian views now convert to the School's timezone, like the
  staff screens.
- **LOW suggestions taken:**
  - the service re-checks `portal.fees.view` itself, as defence in depth;
  - the payment page says it lists only what was applied to the fees shown
    for this child.
- **Noted:** the 1000-charge cap per statement read, shared with the staff
  statement, is theoretical.

### 26.9 Legal
**POR-L1 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.** Q3 and Q4 were
narrowly annotated with the development behaviour (other-year dues; the
applied amount instead of the whole receipt). E21, E28 and E30–E32 are
unchanged. No fee, late fee or disclosure is declared lawful.

## 27. POR.4 — Guardian conversations and replies, as built (2026-10-09; development only)

### 27.1 Capability
- `portal.communications.reply` (namespace `guardian`) is on the closed
  `guardian` role only. The scope triggers keep it off every staff role
  (raw-SQL tested). Existing Guardian grants receive it through the role,
  with no new grant rows.
- A reply needs **both** `portal.communications.view` and
  `portal.communications.reply`; neither substitutes for the other. The
  route checks both, and the service re-checks both, fresh, under its locks.

### 27.2 Participant authority (Communications' own model, unchanged)
- Conversation participation is `communication_thread_participants`, keyed
  by `(thread_id, user_id)`, with Phase 5D.1 provenance (`participant_kind`,
  `guardian_id`, `student_id`). A Guardian joins only when staff with
  `communications.conversations.guardians` create a thread through
  `ConversationParticipantAuthorizationService` under the School conversation
  policy.
- **A thread is a Guardian's in the portal** (`GuardianConversationService::visible()`)
  only when all of the following hold:
  - this User's own row joined **as this Guardian persona**
    (`participant_kind = guardian`, this `guardian_id`) and has not left. A
    dual-role User's staff participation (`membership`) never counts;
  - **no other Guardian persona** takes part, whether or not they have left.
    Guardian-to-Guardian visibility (§11) is withheld pending POR-L1 Q11–Q12;
    such a thread stays in the staff Hub only;
  - **every Student participant is in the Guardian's live
    GuardianStudentScope**. A Student-involving thread is Student-scoped, so
    it fails closed when that Student leaves scope.
- On top, every request needs PortalAvailability, the capability and this
  User's live ActingGuardian (§24.2). Losing the link, the membership, the
  persona or the last eligible relationship ends the whole portal.
- **History after a relationship ends:** Communications threads carry no
  subject-Student reference; Phase 5D.1 §22 keeps participation history
  untouched and ties access to participation plus an active membership. A
  Guardian-only thread (no Student participant) therefore stays readable
  while the Guardian remains an active Guardian of any eligible child; a
  Student-involving thread follows that Student's live scope. Recorded in
  POR-L1 Q15; not a legal conclusion.

### 27.3 Initiation: deferred
- The repository has **no Guardian-initiated conversation contract.** Phase
  5D.1 defines staff-initiated Guardian participation only and lists
  Guardian-to-Guardian chat as out of scope. It names no recipient set,
  Student context or School policy for a Guardian starting a thread.
- **POR.4 therefore builds existing-thread reading and replying only.** No
  compose route, no `createThread` call (architecture-guarded), no staff
  directory or search.
- **The decision needed:** whom a Guardian may start a conversation with
  (class teacher, subject teacher, office), whether a Student context is
  required, the School policy toggle, and POR-L1 Q5's answer on creating
  content.

### 27.4 Read model
- **List** (`/app/portal/conversations`): visible threads, newest activity
  first, at most 50. Each shows the subject, open or closed, the other current
  participants' display names, a 120-character preview, and unread state
  from the existing `ConversationReadModel` (no portal-only unread model).
- **Thread:** messages newest page first (30 per page, shown oldest-first).
  Each shows the sender's display name ("You" for own), text, time and
  attachment name, type and size.
- **Not exposed:** User, membership, link or Guardian ids of anyone;
  roles or capabilities; priority; delivery or provider data; other
  participants' read state.
- **Read state:** opening a thread moves only this participant's own
  `last_read_at` (`CommunicationThreadService::markRead`).
- **Attachments:** only those already sent with a message of a visible
  thread, never another participant's pending upload. Audited
  `communication_attachment.downloaded` with `surface = guardian_portal` and
  `guardianId`.
- Anything inaccessible (unknown, another Guardian's, staff-only, another
  School's, out-of-scope Student, a pending or foreign attachment) is the
  same 404.

### 27.5 Reply write path and idempotency
- **Writer:** `GuardianConversationService::reply()` → the one authoritative
  writer, `CommunicationMessageService::send()` (recipients, in-app
  deliveries, `last_activity_at`, `communication.message.created`, the
  outbox event). The portal never inserts a message itself.
- **Content:** plain text, trimmed, 1–10,000 characters (the staff Hub's own
  limit), no NUL, valid UTF-8; priority is always `normal`; no attachment
  ids; forged fields are ignored. Stored and rendered as text (no `v-html`).
  Validation messages are fixed and never echo the input.
- **Idempotency (rules 29–33):**
  - the thread page issues a fresh UUID key with every render (the
    Finance/Fees web-form convention); a resubmission of that form reuses it;
  - the key is claimed on the message itself: migration
    `2026_12_13_090000_add_idempotency_key_to_communication_messages` adds
    `communication_messages.idempotency_key`, the partial unique index
    `communication_messages_sender_idempotency_unique (school_id,
    sender_user_id, idempotency_key)` and the check
    `communication_messages_idempotency_thread_check` (keys only on
    conversation messages);
  - **scope:** School + sender. Two Schools, or two Users, reusing one literal
    key never collide;
  - **replay:** same User + same key + same thread + identical text returns
    the original message; any other reuse (another thread, changed text) is
    refused ("This reply form has expired"). Nothing is written;
  - **concurrency:** a transaction-scoped advisory lock on (School, User,
    key) serializes same-key requests, so the second sees the first's
    message. The unique index stays the authoritative claim; a violation is
    re-run once through the full checks and answers the committed message;
  - authority is re-evaluated before any replay (rule 32);
  - the staff Hub keeps `idempotency_key` NULL (unchanged).
- **Rollback:** `down()` drops the index, the check and the column; only
  de-duplication metadata is lost. Rollback and re-apply proved identical.

### 27.6 Locks and races
- **Reply transaction order:**
  1. School FOR SHARE (`SchoolOperationalGuard`, rule 86);
  2. the Guardian's active account link FOR SHARE, then its membership FOR
     SHARE (`ActingGuardianResolver::resolveLocked()`, Identity-owned) —
     the same order Guardian unlink and off-boarding take them FOR UPDATE,
     after the School access lock, which a reply never takes;
  3. the reply-key advisory lock;
  4. the thread FOR NO KEY UPDATE (the lock its `last_activity_at` update
     takes anyway, so two replies never upgrade a shared lock into a
     deadlock);
  5. the Guardian's own participant row FOR SHARE.
- **Effect:** an off-boarding, unlink, membership suspension, participant
  removal or closure either committed before the reply (which then refuses)
  or waits until the reply commits. *(POR.5, §28.6: off-boarding, removal
  and closure are proven in real processes; unlink and suspension take the
  same rows in the same order, shown by analysis, not by a race test.)* Proven in two real OS processes with an
  observed lock wait (`GuardianConversationConcurrencyTest`: duplicate
  submission, off-boarding both orders, participant removal, closure).
- **Not locked:** a Guardian↔Student relationship change or persona
  deactivation (before/after semantics; the next request is denied).
  Capability revocation happens only with the link (§24.3), so it serializes
  through the link lock; the service re-reads capabilities with the cache
  forgotten.
- **School switch:** each request resolves the current School; a stale thread
  id is the same 404.

### 27.7 Abuse control
- The staff Hub reply has no throttle. The portal reply gets one, keyed by
  User only (rule 61): `throttle:guardian-portal-reply`, 10 per minute. It
  can't be bypassed by changing the form key or the School (tested). No new
  moderation or content-scanning was invented.

### 27.8 MFA
- Every conversation route (list, thread, download, reply) needs `mfa-page`:
  an enrolled factor plus current, factor-bound assurance (60 minutes), as
  for Attendance and Fees.
- **Why stricter than the POR.1 inbox:** conversation content is private and
  may be Highly Sensitive (§14), and the reply is the portal's first write.
- No fresh per-reply re-verification (`FreshMfaRequirement` stays for
  consequential administrative actions). POR-L1 Q22–Q23 may change this.

### 27.9 External delivery
- A conversation message creates **in-app deliveries only**
  (`CommunicationMessageService` → `createInAppDelivery`; the in-app driver
  sends nothing outside).
- `communication.message_created.v1` is outboxed, but no webhook, automation
  or notification consumer handles it. No email, SMS, push or provider
  processing is introduced. E17/E18 are untouched.

### 27.10 Audit, sender attribution, edit/delete, retention
- **Audit:** `communication.guardian.replied` records `guardianId`,
  `accountLinkId`, `threadId`, `messageId`, `surface = guardian_portal` and,
  only for a Student-involving thread, `studentIds`. It never records the
  text. The writer's own `communication.message.created` is kept. A replay
  writes nothing.
- **Sender:** `communication_messages.sender_user_id` is the User, as for
  every message. The Guardian capacity is on the sender's participant row
  (`participant_kind = guardian`, `guardian_id`) and in the append-only
  audit. No staff or employee field is used, and a later link change
  rewrites nothing.
- **Edit, delete, recall, moderation:** none.
- **Retention:** Guardian replies are ordinary `communication_messages` rows
  under the existing Communications retention (E21.2C). No new category or
  period. E21 still governs.

### 27.11 Security review (POR.4)
- **HIGH / MEDIUM:** none. The review was independent and read-only. It
  traced the change against the staff Hub, unlink, off-boarding, staff-access
  and invitation code. These held: visibility, capability separation, lock
  order (no cycle with off-boarding, unlink, staff suspension or staff
  sends), idempotency scope and replay order, in-app-only delivery, the
  minimized projection (no `v-html`), the attachment binding, the User-keyed
  throttle, and ignored forged fields.
- **LOW, accepted:** a Guardian↔Student relationship end or a persona
  deactivation is not locked against an in-flight reply. A reply racing it
  may commit just after it (before/after semantics, §27.6), and the next
  request is denied. Unlink, off-boarding, suspension, removal and closure
  are fully serialized.
- **LOW, taken:** the reply key is lower-cased before it is used, so the
  advisory lock and the stored uuid always agree. The unique index already
  resolved case variants to one message.

### 27.12 Legal
**POR-L1 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.** Q5 (replies;
initiation deferred), Q12 (multi-Guardian threads withheld) and Q15 (thread
history after a relationship ends) were narrowly annotated with the
development behaviour. Production stays refused in code
(`PortalAvailability`). E21, E28, E30–E32, E35–E37 and E39–E42 are
unchanged.

## 28. POR.5 — closure and readiness audit (2026-10-09)

### 28.1 Verdict
**POR DEVELOPMENT — CLOSED / PRODUCTION GATED.**
- POR.0–POR.4 are built and verified for development, with two executable
  corrections made by this audit (§28.4).
- Production stays refused in code (`PortalAvailability`).
- **POR-L1 / E46 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.**

### 28.2 Method
- Read-only first, from baseline `393d299`, the POR.4 canonical regression
  checkpoint.
- Four independent read-only reviews covered:
  - authorization, routes, MFA and the production gate;
  - the data surfaces and sibling isolation;
  - database defences, tenancy and lock order;
  - documentation and legal wording.
- Their load-bearing claims were verified, the focused suites re-run, and a
  DDEV demo walk-through done (§28.13).

### 28.3 Slice status
| Slice | State |
|---|---|
| POR.0 | Contract + POR-L1 draft published (3b6ae0e) |
| POR.1 | Guardian scope, role, ActingGuardian, off-boarding, inbox — built (a288292) |
| POR.2 | GuardianStudentScope + Attendance — built (534bca9) |
| POR.3 | Fee statement + payments applied — built (ee0fc4e) |
| POR.4 | Conversations + idempotent replies — built (393d299) |
| POR.5 | This audit |

### 28.4 Executable corrections (closure blockers, fixed)
1. **MEDIUM — staff Hub thread writes checked participation only.**
   - **Routes:** `POST|DELETE /app/communications/{thread}/attachments…` and
     `POST /app/communications/{thread}/archive|unarchive` needed only an
     active participant row.
   - **Impact:** a Guardian-only member is a participant of every thread they
     joined as the Guardian. So they could upload or remove a pending
     attachment, or change their archive state, through the staff Hub, with
     no capability, no MFA and no production gate. That contradicts §27's
     "no upload" (it is the §24.8 class, applied to writes).
   - **Fix:** uploads and removals now need `communications.reply` (a thread
     attachment is only ever sent with a staff Hub reply); archive and
     unarchive need `communications.view`, like every Hub page.
   - **Test:** `a_guardian_participant_cannot_write_through_the_staff_hub_thread_routes`
     (refused for the Guardian, kept for staff).
2. **LOW — rule 6 consistency.**
   - **Gap:** the Attendance and inbox read services did not re-check their
     capability in-service, and the inbox's self-check did not compare the
     School. Fees' and Attendance's `students()` and the inbox's `inbox()`
     took no actor.
   - **Fix:** every public portal read method now takes the User and re-checks
     self, School and capability (`assertSelf`). The inbox's unread lookup
     also filters `school_id` explicitly.
   - **Test:** `every_read_service_rechecks_its_capability_and_school_without_the_route`.
- **Also:** the inbox's empty state no longer claims "The School has not
  sent you any messages yet" (see §28.7 on School-wide announcements). And
  `trg_sgal_portal_grant_guard`'s refusal to move a link to another persona
  gained a raw-SQL pin.

### 28.5 Route inventory (`/app/portal`, web/session only)
| Method | URI | Capability | MFA | Student scope | Writes / downloads |
|---|---|---|---|---|---|
| GET | `/app/portal/communications` | `portal.communications.view` | — | — | — |
| GET | `…/communications/announcements/{id}` | same | — | — | own read state |
| GET | `…/announcements/{id}/attachments/{id}/download` | same | — | — | download (audited) |
| GET | `/app/portal/attendance`, `…/students/{id}` | `portal.attendance.view` | yes | yes | read audited |
| GET | `/app/portal/fees`, `…/students/{id}`, `…/students/{id}/payments/{id}` | `portal.fees.view` | yes | yes | reads audited |
| GET | `/app/portal/conversations`, `…/{thread}` | `portal.communications.view` | yes | Student participants | own read cursor |
| POST | `…/conversations/{thread}/replies` | `.view` + `portal.communications.reply` | yes | Student participants | **write** (throttled, idempotent, audited) |
| GET | `…/conversations/{thread}/attachments/{id}/download` | `portal.communications.view` | yes | Student participants | download (audited) |
- **Every route:** `auth`, `school-context`, `portal-development-only`
  (first), the capability, then a fresh ActingGuardian in the controller.
- **Elsewhere:** no `/api` route checks a `portal.*` key. The only staff-side
  POR route is `POST /app/guardians/{guardian}/portal-offboard`, which needs
  `guardians.manage`, `school.members.manage` and a fresh MFA code.

### 28.6 Integrity findings (verified in code)
- **Actor:** `ActingGuardianResolver` resolves fresh, never cached:
  active membership → exactly one active Guardian link → active persona →
  ≥ 1 eligible relationship. Ambiguity fails closed.
  - One School per session: the demo confirmed nothing resolves until a
    School is selected.
  - Writes use `resolveLocked()`.
- **Capabilities:**
  - four `portal.*` keys in namespace `guardian`, carried only by the closed
    `guardian` role, which carries nothing else;
  - the database refuses the crossings (raw-SQL tested);
  - no role-name checks;
  - "staff" means a `school`-scope grant everywhere it is counted.
- **Lifecycle:** one Student lost, last relationship lost, unlink,
  off-boarding, staff off-boarding of a dual persona, and membership
  suspension all behave as §9.2 says, with the exception in §28.7.
  - Only `AccountLinkService` and `GuardianOffboardingService` end a link,
    and the database refuses any other order.
- **PortalAvailability:** the first statement of all 12 public methods of
  the four portal services; no configuration, environment or request bypass.
- **MFA:**
  - inbox: none (contract);
  - Attendance, Fees, conversation reads and replies: enrolled factor plus
    current, factor-bound assurance.
  - No lower-assurance alternate route: staff Hub and `/api` paths need
    `school`-scope keys a Guardian cannot hold.
- **Finance:** a shared Payment is shown only as the amount applied to this
  Student. No sibling ids, names, allocations or counts. Totals come from
  `ChargeStateReader`; dates are in the School's timezone.
- **Conversations:** the §27 rules hold.
  - Threads with another Guardian are withheld.
  - Student-participant threads follow the live scope.
  - Guardian-only threads stay visible while ActingGuardian resolves (§27.2,
    POR-L1 Q15).
  - No external side effect: `communication.message_created.v1` has no
    consumer, and the webhook registry lists only `platform.webhook_test.v1`.
- **Tenancy:** every portal query runs under `TenantContext::withSchool`
  with an explicit `school_id`, on the runtime connection only (no admin or
  retention connection). No cross-School path was found.
- **Lock order:** no pair of paths takes overlapping rows in opposite order.
  - Link, membership and grant writers all take the School access lock first.
  - Reply takes link → membership FOR SHARE and never takes the School access
    lock.
  - Staff suspension never locks the link.
  - Student-first relationship paths share no rows with reply or
    off-boarding.
  - Real-process proof covers reply vs duplicate, off-boarding (both orders),
    participant removal and closure. Unlink, suspension and activation vs
    off-boarding are shown by analysis only.
- **POR.1 corrections intact:** the staff Hub attachment download needs
  `communications.view`; `/app/school-setup` needs `school.profile.view`.

### 28.7 Known limitations and documented debt (not blockers)
- **School-wide (membership-audience) announcements do not reach the
  Guardian inbox.**
  - **Cause:** the inbox shows only announcements whose audience snapshot
    names the Guardian persona (§10.1).
  - **Effect:** a School-wide notice creates an in-app delivery for a
    Guardian's membership that no Guardian surface shows, and delivery
    counts include it.
  - **Workaround:** to reach Guardians, staff use a Guardian audience.
  - **Status:** deferred; an owner/product decision. The §10.1 branch
    "recipient rows created through that persona's link" needs no separate
    code: every link-created recipient row has a matching Guardian snapshot
    row.
- **Reinstatement of an off-boarded Guardian-only membership:** no
  application reactivation path (§9.2 note). Fails closed. Deferred.
- **Sender-participant database gap (pre-existing Communications debt).**
  - **The gap:** no database rule ties `communication_messages.sender_user_id`
    to an active participant.
  - **Why it is contained:**
    - both application writers enforce it: `CommunicationMessageService::send()`,
      and announcements (`thread_id` NULL by CHECK);
    - the Guardian path holds the participant FOR SHARE;
    - a bypass needs raw SQL by code running as the application in the same
      School (RLS checks `school_id`).
  - **Status:** defence-in-depth debt for a future Communications slice, not
    POR.
- **Trigger write-skew:** the guardian-grant INSERT check and the link-end
  trigger read without row locks. So two concurrent *raw* writers could pair
  an active grant with a revoked link. Every application writer holds the
  School access lock, which closes it in practice. Documented, not changed.
- **Personal access tokens for Guardian-only members:** issuance is not
  refused (§17); a token reaches no portal surface. Open.
- **Throttle quota:** `throttle:guardian-portal-reply` runs before the
  portal gate and capability (framework priority), so a refused request
  still uses the User's quota. No security impact.

### 28.8 Deferred POR scope
| Item | Needs |
|---|---|
| Guardian-started conversations | owner/product decision (recipients, Student context, School policy) + POR-L1 Q5 |
| Guardian reply uploads | an upload contract (validation, scanning) + owner decision |
| Multi-Guardian conversations in the portal | POR-L1 Q11–Q12 |
| History after a relationship ends | POR-L1 Q15 |
| School-wide announcements in the Guardian inbox | owner/product decision |
| Off-boarded Guardian-only reinstatement | owner decision + a lifecycle contract |
| Student accounts and Student self-service | POR-L1 Q16–Q21 + its own contract (§16) |
| API / bearer and mobile portal | its own contract (§17; ADR 0049 MFA) |
| Exports and PDFs | separate authorisation (§11) |
| Cross-School Guardian aggregate | separate contract (§7, §11) |
| Marks, results, report cards, transcripts | E39–E42 (+ E35–E37); prohibited here |

### 28.9 Capability inventory
| Key | Scope / namespace | Carrier | Surfaces | Staff may hold? | Database enforcement |
|---|---|---|---|---|---|
| `portal.communications.view` | guardian | closed `guardian` role | inbox, conversations | no | namespace check + `trg_role_capabilities_scope`; guardian grant needs an active link |
| `portal.attendance.view` | guardian | same | Attendance | no | same |
| `portal.fees.view` | guardian | same | Fees | no | same |
| `portal.communications.reply` | guardian | same | replies (with `.view`) | no | same |
- The `guardian` role can hold no staff key.
- Application enforcement is the route `capability:` middleware plus the
  in-service re-check (§28.4).

### 28.10 Audit-event inventory
| Event | Metadata (never content) |
|---|---|
| `attendance.guardian.viewed` | guardianId, accountLinkId, studentId, academicYearId, from, to, recordCount, surface |
| `fee_statement.guardian.viewed` | guardianId, accountLinkId, studentId, academicYearId, lineCount, surface |
| `payment_allocation.guardian.viewed` | guardianId, accountLinkId, studentId, paymentId, allocationCount, surface |
| `communication_attachment.downloaded` (`surface = guardian_portal`) | announcementId or threadId, guardianId |
| `communication.guardian.replied` | guardianId, accountLinkId, threadId, messageId, surface, studentIds only for a Student-involving thread |
| `guardian.portal_role_granted` / `_revoked` | schoolMembershipId (+ reason) |
| `guardian.portal_offboarded` | guardianId, schoolMembershipId, revokedPortalGrants, membershipSuspended, staffIdentityRetained |
| `guardian.account_unlinked` | guardianId, schoolMembershipId, studentId null |
- The actor, School, timestamp and request id are in the envelope.
- Not audited: inbox list, announcement open, conversation list and thread
  view (§13.2; POR-L1 Q28).
- Classification: Sensitive (`DATA-CLASSIFICATION.md`). Retention category
  `audit`; the period is E21, and none is set here.

### 28.11 Database defences relied on
- the four-scope `roles_scope_check`;
- `capabilities_guardian_namespace_check`;
- `trg_role_capabilities_scope`;
- the membership-role-assignment scope function (a guardian grant needs an
  active link);
- `trg_sgal_portal_grant_guard` (status, membership and persona);
- the history guard;
- `communication_messages_sender_idempotency_unique`;
- `communication_messages_idempotency_thread_check`;
- composite same-School foreign keys;
- forced RLS.

Each is pinned by raw-SQL tests (`GuardianScopeDatabaseInvariantsTest`,
`GuardianConversationPortalTest`). Application-only by design: the
GuardianStudentScope predicate, participant-only and open-only replies, and
"suspend only without a staff grant".

### 28.12 Production-gate matrix
| Surface | Development | Production blockers |
|---|---|---|
| Guardian announcement inbox | built (POR.1) | POR-L1 (E46), E21 |
| Guardian Attendance | built (POR.2) | POR-L1, E21 |
| Guardian fee statement + payments applied | built (POR.3) | POR-L1, E21, E30–E32 |
| Guardian conversations + replies | built (POR.4) | POR-L1, E21 |
| Student accounts and surfaces | not developed (blocked) | POR-L1 Q16–Q21 + own contract |
| Marks, results, report cards, transcripts | prohibited | E39–E42, E35–E37 |
| Online payment | out of scope | E28 |

All production use is also refused in code until `PortalAvailability` is
lifted by a reviewed change. Release qualification (E16 and others) is a
separate gate.

### 28.13 Demo review (rule 82)
`ddev demo-reset` on the published code, then an HTTP walk-through.

**`guardian01`:**
1. Sign-in works, but no School is resolved until one is selected.
2. The portal navigation appears; every staff page is 403.
3. The inbox opens without MFA. It is empty: the demo's announcements are
   School-wide, per §28.7.
4. Attendance, Fees and Conversations return "MFA required" until a factor
   is enrolled, then step-up, then open. Single-child indexes redirect to
   that child.

**The School Admin and the Guardian together:**
1. The School Admin starts a Guardian conversation from the staff Hub.
2. The Guardian reads it and replies.
3. A resubmission of the same form is one message.
4. Changed text with the same key is refused.
5. The staff Hub shows the reply attributed to the Guardian.

### 28.14 Regression
- **Checkpoint:** the canonical checkpoint stays `393d299`.
- **This audit's code change:** narrow. Two controller capability gates and
  in-service re-checks on read paths; no schema, lock or shared-writer
  change. Focused and broad suites covered it.
- **No canonical re-run:** the checkpoint isn't materially invalidated.
- **Counter:** 1/5 (one executable unit since the checkpoint).

### 28.15 Legal
**POR-L1 / E46 — DRAFT REQUEST / NOT SENT / NOT ANSWERED.**
- The draft's description of the build was brought up to date, and Q11,
  Q22, Q23 and Q28 were narrowly annotated. Nothing was answered or sent.
- E21, E28, E30–E32, E33, E35–E37 and E39–E42 are unchanged.
- The TCH-L1 historical-date clarification stays a separate draft, not
  sent.
