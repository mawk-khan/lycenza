# ADR 0059: Staff / School-Admin Account Provisioning Contract

- Status: Accepted (contract only; documentation only, nothing implemented)
- Date: 2026-09-28 (Phase 0O.12A)
- Resolves: the **decision** part of ADR 0058 evidence row **E24**
  ("Staff/School-admin provisioning path, including first-School
  bootstrap"). E24 stays **blocking** until the implementation (§23) is
  built and qualified.
- Amends, by note (no rewrite):
  - ADR 0047 — a bootstrap administrator must have an established
    credential to count as "qualifying" (§7.3);
  - ADR 0056 — the recovery/activation boundary for credential-less Users
    (§13);
  - ADR 0055 — a new critical, School-scoped email purpose (§9.2).
- Related:
  - ADR 0037 (MFA), ADR 0044 (elevation), ADR 0045/0048 (Groups);
  - ADR 0046 (root boundary), ADR 0047 (School lifecycle);
  - ADR 0054 (hosts), ADR 0055 (email), ADR 0056 (recovery);
  - ADR 0058 (O1).

## 1. Context — before-fix audit (2026-09-28, `origin/main` `1f226aa`)

**User creation in production.** Exactly two code paths create a `User`
in a production environment:

| Path | Code | Scope |
|---|---|---|
| A. First root | `platform:bootstrap-root` → `PlatformRootProvisioningService::bootstrapFirstRoot` | Interactive only; `pgsql_admin`; refused once any active root exists |
| B. Guardian activation | `POST /invitations/{school}/{token}` → `GuardianAccountActivationService::accept` | Active School only; Guardian invitation only |

The only other creators are the local/testing seeder
(`DatabaseSeeder`) and the DDEV demo (`DemoDataBuilder`, guarded by
`DemoEnvironmentGuard`). Neither runs in production.

**Paths that act only on existing accounts:**
- `platform:provision-root` grants root to an existing, enabled User. Its
  docblock says "no user is ever created".
- `PlatformRoleGovernanceService::grant` grants a platform role to an
  existing User.
- `platform:user-password-reset` changes an existing password.
- `SchoolBootstrapAdministrationService::establish` gives an existing User a
  membership plus `school_admin`. The target comes from
  `SchoolLifecycleAuthority::bootstrapTarget`, which rejects an unknown
  email ("No account has exactly that email or identifier."), a disabled
  account, and self-nomination.

**HR does not own accounts:**
- `EmployeeService::create` and `EmployeeImportService` never create a User.
- `employees.user_id` is nullable and optional.
  `assertUserLinkageIsSafe` requires the User to **already** hold a
  membership in that School.
- `docs/modules/HR.md` says: "User is not Employee"; account state lives
  on `users`/`school_memberships`.

**Other facts that shape the design:**
- **`users.password` is `NOT NULL`.** No "account without a credential"
  state exists.
- **`school_memberships`.** `status` is `invited|active|suspended` by
  convention only, with no CHECK constraint. `invited` is the column
  default but no code writes it. The table is central discovery data with
  no RLS, by design.
- **Only two School roles exist:** `school_admin` and `principal`, both
  `is_system`. No runtime path writes `roles` or `role_capabilities`, so
  there are no custom roles or grants.
- **Membership capabilities.** `school.members.manage` and
  `school.roles.manage` exist, and only `school_admin` holds them.
  - `school.members.manage` gates Guardian invitations; its docblock calls
    it "the Identity-domain capability that governs creating school members
    generally".
  - `school.roles.manage` gates nothing except the School-activation test.
- **No School-side surface lists members or writes memberships or role
  assignments.** The only writers are the platform bootstrap service and
  Guardian activation.
- **MFA is opt-in per route** (ADR 0037), not forced on School Admins.
  Sensitive actions use fresh re-verification (`FreshMfaRequirement`).
- **The Guardian invitation substrate is Guardian/Student-specific.**
  - `identity_account_invitations` has no purpose column and composite
    foreign keys to `guardians`/`students`.
  - Its token is a single 64-character value in the **URL path**. The
    email purpose `account_invitation` maps 1:1 to the Guardian source.
  - No prune exists for its rows.
- **Account recovery** (ADR 0056) uses the stronger scheme: a selector, a
  256-bit secret in the URL **fragment**, and a SHA-256 hash stored.

### 1.1 The first-School deadlock (exact)

On a fresh production install:

1. `platform:bootstrap-root` creates the **only** User, the root.
2. `SchoolLifecycleService::create` requires `admin`, resolved by
   `bootstrapTarget`. It must be an **existing, enabled** account that is
   **not the acting root** (`self_nomination`).
3. There is no second User:
   - `bootstrap-root` refuses once a root exists;
   - `provision-root` needs an existing account;
   - Guardian activation needs an **active** School, and no School can
     exist yet.
4. So **no School can be created**. Even if one existed,
   `SchoolLifecycleService::activate` refuses without a qualifying
   administrator (`admin_missing`).

The cycle: **a School needs an ordinary User → the only ordinary-User
factory needs an active School → activation needs a School with an ordinary
administrator.** Loosening the lifecycle (a School without an
administrator, or root as its own administrator) is rejected.

## 2. Decision summary

Two distinct, related flows (the owner decision):

| | **A. Platform-assisted bootstrap account** | **B. School staff invitation** |
|---|---|---|
| Purpose | Create the ordinary User who becomes a provisioning School's bootstrap administrator | An active School invites staff into **its own** School |
| Who | An infrastructure operator at the console | A School member holding `school.members.manage` **and** `school.roles.manage` |
| Surface | Console command only; no HTTP creation endpoint | School browser routes |
| Creates | One credential-less User + one identity-level activation credential. **No membership, role or Employee** | One School-owned invitation. The User (if new), membership and roles are created only at **acceptance** |
| School authority | Given only by root through the **existing** ADR 0047 bootstrap path (fresh MFA) | Given by the invitation's roles at acceptance |
| Delivery | Displayed **once** on the operator's terminal; no email | ADR 0055 critical email only |
| When | Before O13 exists, and at any time for a `provisioning` School | Active School, critical email available |

Neither flow creates an Employee, a platform or Group grant, or elevation.
Neither widens ADR 0047 D13 (no ongoing platform membership
administration).

## 3. User versus Employee (unchanged, restated)

- **User** is the authentication identity. **Employee** is the HR person
  record.
- Provisioning a User never creates an Employee. Creating an Employee never
  creates a User.
- `employees.user_id` stays optional and explicit. Linking happens through
  the existing HR rules, which require an existing membership in that
  School (`assertUserLinkageIsSafe`).
- The Employee is never inferred from an email.

## 4. Credential-less Users (new identity state)

A User created by flow A exists **without a usable local credential** until
activation.

**Frozen semantics:**
- It cannot sign in. The attempt fails exactly like a wrong password, with
  no distinct message.
- It is **not** recovery-eligible (ADR 0056, §13).
- It does **not** count as a qualifying School administrator (§7.3).
- Its first password is set **only** by consuming its activation
  credential.
- A random placeholder hash is **forbidden**. A placeholder would look like
  a real password, and recovery would treat it as one: a recovery link
  would then act as an activation link.

**Representation (for the implementation):** one explicit, database-checked
representation. The preferred one is `users.password` nullable, with NULL
meaning "no local credential established". That is the case ADR 0056
already anticipates ("future SSO-only Users would fail this"). A trigger or
CHECK must ensure that only an establishing write moves NULL to a value,
and nothing moves a value back to NULL.

A flow-B **new** User is created at acceptance **with** its password, in one
transaction. It never passes through the credential-less state.

## 5. Flow A — platform-assisted bootstrap account

**Command:** provisional name `platform:provision-school-admin-account`; the
exact name is set in the implementation.

**Security properties (reusing `bootstrap-root`/`provision-root`):**
- console only; there is **no** HTTP route that creates an arbitrary User;
- **interactive only**: `--no-interaction` is refused and there is **no**
  `--force`, because a display-once secret needs a human at the terminal;
- `assertOperatorConnection()`-style operator context. The User row is an
  ordinary identity write; no admin privilege is needed for the row itself;
- inputs:
  - the exact email (canonicalized, §11);
  - an explicit display name;
  - optionally, one exact `provisioning` School (id or slug), recorded
    only as audit context;
- a summary, then **typed confirmation of the exact email** (the
  `provision-root` convention);
- no password is ever typed or chosen by the operator;
- no root or platform role, no Group grant, no membership, no Employee.

**Refusals (the operator is trusted, so messages may be specific):**
- the email belongs to a User with a credential: that User can be named
  directly as the bootstrap target;
- the email belongs to a disabled User: reactivation is not this command's
  job;
- the named School is not `provisioning`.

**Re-issue.** For an existing **credential-less** User, the command issues a
fresh activation credential that supersedes the previous one (§16), after
the same typed confirmation.

**Output: displayed once.**
- The command prints one activation link on the **canonical platform
  origin** (`CanonicalOrigin::platformUrl`), with the secret in the URL
  **fragment**, plus the expiry.
- It is printed only to the interactive terminal, never to a log, file,
  audit row or database.
- Classification: **Highly Sensitive**.
- The runbook requires transfer to the named person over an authenticated
  channel. The link is never pasted into chat, tickets or email to a
  shared mailbox.
- Lifetime: **24 hours** by default, with a database CHECK of at most 72
  hours.

**Authority split.** The console proves infrastructure access (ADR 0046 §2
trust). **School authority** still comes only from root, using
`platform.schools.manage`, confirmation and **fresh MFA**, through the
existing ADR 0047 paths:
- `create`, naming this User as `admin`, for a new School;
- the bootstrap-admin **replace** action, for a School already
  `provisioning`.

The command never writes `school_memberships` or `membership_role_assignments`.
The ADR 0047 invariant stands: the bootstrap service is the only platform
writer.

**Not an emergency path after activation.** Once a School is active, a
console-created User gains nothing without that School's own invitation
(flow B). Losing every School admin stays the future **break-glass**
decision (ADR 0047). It is not provided here.

## 6. Flow B — School staff invitation

**Capabilities.** No new capability is invented. The existing two are
exactly the membership and role-assignment authorities that
`AUTHORIZATION.md` already assigns to the School:
- **Issue:** `school.members.manage` **and** `school.roles.manage`.
- **Resend and revoke:** `school.members.manage`.
- **List:** `school.members.view`.

**Also required for every mutation:**
- **fresh MFA re-verification** (`FreshMfaRequirement`, like API clients
  and School domains). A School admin must enroll MFA before provisioning
  staff;
- `SchoolOperationalGuard`;
- the School's normal `TenantContext`.

**Default grant.** `school_admin` only, unchanged. `principal`, Teacher-,
HR-, Accountant- or Admissions-type roles never gain these through this
ADR. Custom grants do not exist (no runtime `role_capabilities` writer),
so none are possible.

**Refused:**
- **elevation.** No route declares `school-context:elevated`; rule 83 and
  ADR 0044 are unchanged;
- **Group authority.** Rule 84 and ADR 0045 are unchanged. Group-level
  workforce provisioning needs its own contract.

### 6.1 Roles

**Assignable roles:**
- An invitation names **at least one** role.
- Roles come from the closed catalog of `roles` with `scope = 'school'`.
  At ADR 0059 that was `school_admin` and `principal`; since TCH.3 the
  catalog also holds the system `teacher` role (ADR 0063 §31), granted
  through this same path under the no-escalation rule.
- Role ids are resolved server-side from keys. A client-supplied id is
  never trusted.
- Never platform or Group roles, root, or a wildcard. The existing
  `trg_membership_role_assignments_scope` trigger also refuses a
  non-School role.

**No escalation.** The issuer may name only roles whose capability set is a
subset of the issuer's **current** capabilities in that School. *(Amended by
ADR 0071 §6 and §10, SR.0, 2026-10-09, effective from SR.2:
- a capability may also be covered by a class-scoped grant right the
  issuer holds (`school.roles.grant.{hr,hr_sensitive,payroll_sensitive}`);
- the check runs inside the mutation transaction;
- a single-role revoke needs the same coverage;
- the database enforces grantor coverage from SR.1.)* A
`school_admin` may invite another `school_admin`, as `AUTHORIZATION.md`
already expects.

**Effect.** Roles are recorded on the invitation and assigned only at
acceptance.

### 6.2 Invitation lifecycle (School-owned)

- **States:** `pending` → `accepted` | `revoked`. "Expired" is derived,
  matching the Guardian precedent.
- **Binding.** An invitation is bound to **one canonical destination
  email** in **one School**. Only an identity whose canonical email equals
  it can accept.
- **One pending invitation** per `(school_id, destination email)`,
  enforced by a partial unique index.
- **Lifetime:** 7 days by default (the Guardian precedent), with a
  database CHECK of at most 7 days.

### 6.3 Acceptance

Acceptance is served on the School's canonical origin
(`CanonicalOrigin::schoolUrl`) under the existing `invitations/` School-host
surface, on its own route. The secret is in the **fragment**, and a GET
never consumes.

**The POST runs in one transaction, in this order:**
1. `SchoolOperationalGuard` hold (a suspended School makes the invitation
   unusable; it is not revoked);
2. a row lock on the invitation;
3. re-check that the invitation is pending, not expired, and the secret
   matches;
4. re-check the **issuer's** current authority: still an active,
   non-disabled member holding both capabilities, with the roles still
   within their capability set. Otherwise the invitation is no longer
   valid (fail closed);
5. identity resolution by canonical email:
   - **No User exists.** Create the User with the submitted password
     (`Password::defaults()`, confirmed), written through
     `CredentialChangeService`. An insert race is lost to the unique
     canonical-email constraint; the loser rolls back and receives the
     "existing account" response. **One email never creates two Users.**
   - **A User exists.** The request must be **signed in as that User**,
     the Guardian precedent (`ExistingAccountConfirmationRequired`). Its
     password and MFA are never touched.
6. refuse (the generic "no longer valid", §15) if the User:
   - is disabled;
   - holds any unrevoked platform role;
   - already has a membership in this School in any state.
7. create an **`active`** membership and the invited role assignments, then
   mark the invitation accepted;
8. audit (§17).

**No auto-login** for a new User. The page directs them to sign in, as
recovery does ("never auto-logs in"). The first sign-in then follows normal
login and MFA.

### 6.4 Why the membership starts `active`

Following the Guardian precedent, the pending lifecycle lives on the
**invitation row**, and `school_memberships` is created only at acceptance.

Pre-creating an `invited` membership would require pre-creating a User for
every invited email. That would leave credential-less identities for
invitations that are never accepted, and it would bind existing Users
before they consent.

The documented `invited|active|suspended` convention is kept and
**formalized by a CHECK constraint** in the implementation. `invited`
remains reserved and unused. No second membership lifecycle is invented.

## 7. School lifecycle interaction (ADR 0047)

### 7.1 The existing bootstrap path is unchanged

- It runs only while the School is `provisioning`: exact, existing,
  enabled User, never oneself.
- It needs `platform.schools.manage`, confirmation and fresh MFA.
- It closes permanently at first activation.
- D13 is unchanged: no ongoing platform membership administration.

### 7.2 After activation

Ordinary staff provisioning belongs to the School alone (flow B). Platform
authority never adds School members, whether through a console command,
elevation or Group authority.

### 7.3 Amendment: a qualifying administrator must have a credential

`SchoolLifecycleAuthority::qualifyingAdministrators` additionally requires
the User to have an **established local credential**. A credential-less
User (§4) never qualifies. Activation otherwise refuses with a new bounded
outcome code, `admin_not_activated`.

**Why.** An active School whose only administrator can never sign in would
be stranded, because the bootstrap path is closed for good. Each alternative
is worse:
- a re-issue after activation would be platform membership administration;
- a recovery link must never act as activation.

MFA is still **not** required for activation. ADR 0047's "no invented setup
requirements" stands: this is not a setup step, it is the ability to sign
in at all.

### 7.4 School states for flow B

| School status | Issue / resend / revoke | Acceptance |
|---|---|---|
| `provisioning` | Not reachable. No School context exists, so no member can select it | Not applicable |
| `active` | Allowed (with authorization) | Allowed |
| `suspended` | Refused (`SchoolOperationalGuard`) | Refused, "no longer valid"; the invitation stays pending until it expires |

## 8. Exact first-School bootstrap sequence

This ordering was verified against the code. **Create requires the admin
first.**

1. **Root account.** An operator runs `platform:bootstrap-root`
   (interactive). Root signs in on the platform host and enrolls MFA, which
   every lifecycle action needs.
2. **Bootstrap User.** An operator runs the flow-A command for the intended
   administrator's email and name. It creates a credential-less User and
   displays the activation link once.
3. **Transfer.** The link reaches the named person over an authenticated
   channel. They open it on the platform host and set a password
   (`Password::defaults()`). The User now has a credential. There is no
   auto-login.
4. **School creation.** Root creates the School (`/app/platform/schools`,
   fresh MFA), naming that email as `admin`. The School is `provisioning`,
   and the bootstrap service gives the User an `active` membership plus
   `school_admin`.

   Steps 3 and 4 may run in either order. Step 5 needs both.
5. **Activation.** Root activates the School (fresh MFA).
   `qualifyingAdministrators` requires an active membership with
   `school.members.manage` + `school.roles.manage`, a non-disabled User and
   **an established credential** (§7.3).
6. **First sign-in.** The School Admin signs in and selects the School
   (`/app`, never auto-selected).
7. **MFA enrollment.** The School Admin enrolls MFA
   (`/app/account/security`). This is required before any staff
   provisioning, because flow B needs fresh MFA.
8. **Staff.** From then on the School Admin invites staff (flow B), which
   needs O13 critical email. Staff accept, then sign in normally.

A wrong bootstrap target while `provisioning` is fixed with the existing
ADR 0047 replace action, combined with flow A for a new person.

## 9. Email

### 9.1 Before O13 is live

Only flow A works: its link is displayed on the console with no email.
This breaks the circular dependency.
- The first administrator exists before any provider is configured.
- Configuring and testing O13 needs operators and that administrator, not
  staff.
- Production go-live needs O13 anyway (ADR 0058 E17–E20).

Flow B is **not** given a display-once or copy-link fallback in the School
UI. That would put a live credential in a browser for someone other than
its recipient, which weakens the final flow.

### 9.2 Normal operation

**Flow B uses ADR 0055 only:**
- a new, **critical, School-scoped** purpose (provisional name
  `staff_account_invitation`) and its own source type;
- queued through `OutboundEmailGateway::queue()` inside the issuing
  transaction, and submitted after commit;
- `expiresAt` equal to the invitation's expiry.

**Issuance is refused** with a bounded code (`email_unavailable`) while
`criticalEmailAvailable()` is false. A staff invitation that nobody can
receive is never created.

**Local/testing** uses the existing fake provider or Mailpit, as ADR 0055
allows.

## 10. Credential scheme (both flows, own purposes)

- **Selector and secret:** a random selector plus a **256-bit** secret.
  Only the SHA-256 is stored, compared with `hash_equals`.
- **Link:** the secret travels in the URL **fragment**, the ADR 0056
  precedent, never the path. The page captures and strips it before the
  app renders, like `recoveryFragment.ts`.
- **Expiry and use:** time-bounded (§5, §6.2) and **single use**.
- **Consumption:** a GET **never** consumes; only the POST with the
  password does.
- **Password:** always `Password::defaults()` with confirmation, written
  through **`CredentialChangeService`**. It gains an "establish initial
  password" operation that requires the credential-less state, so it
  remains the only password writer outside the root bootstrap command.
- **Secrecy:** no secret, selector, link or password is ever logged,
  audited or put in a metric. `LogSanitizer` redacts them as a backstop.
- **Credential version:** a flow-A credential snapshots
  `credential_version` like recovery does. Any change invalidates it.
- **MFA:** an invitation or activation credential is **never** an MFA
  factor and never records MFA assurance. MFA enrollment is **not**
  embedded in activation.
- **Separate purposes:** activation and recovery credentials are separate
  rows and cannot be used for each other.

## 11. Canonical email

Both flows reuse `EmailNormalizer::canonical()` (trim + lowercase) and the
database's `users_email_canonical_check` plus the unique index. There is no
provider-specific normalization (Gmail dots or `+tags`) and no parallel
normalizer.

## 12. MFA

- ADR 0037 is unchanged: MFA is opt-in per route, with no global or
  role-name rule.
- Flow B's mutations require **fresh MFA**, so an admin without a factor
  gets the existing `mfa_required_not_enrolled` response.
- Flow A's activation does not enroll MFA. Root's own lifecycle actions
  already require fresh MFA.
- Whether School Admins must have MFA globally is **not** decided here.

## 13. Account recovery (ADR 0056)

- Activated staff and admins are ordinary eligible Users: non-root,
  enabled, with a local password. Root stays console-only.
- **Credential-less Users are ineligible.** Eligibility must keep
  excluding them under the §4 representation, so a request for them gets
  the same generic "unknown" treatment.
- A recovery credential never activates an account.
- An activation credential never resets an established password. If a
  credential already exists, activation reports "no longer valid".
- **`platform:user-password-reset` refuses a credential-less User.** Its
  first credential comes only from activation; the operator re-issues the
  activation link instead.
- ADR 0056 §4.3 still holds for flow B: a pending invitation is not a User,
  and recovery never touches invitations.

## 14. Existing, disabled and conflicting states (fail closed)

| State at issue (flow B) | Result shown to the School |
|---|---|
| Email of a member **of this School** (any membership status) | "Already a member of this School" (this School's own knowledge only) |
| A pending invitation for this email in this School | "Already invited — resend or revoke" |
| Anything else: unknown email, another School's member, a Guardian elsewhere, a Group user, a disabled User, a platform operator, root | **The same accepted result**, with no difference in wording, status or timing class |

| State at acceptance (flow B) | Result |
|---|---|
| Disabled User | Generic "no longer valid". A disabled identity is **never** reactivated here; that is existing governance |
| User holding any unrevoked platform role (root, auditor) | Generic "no longer valid", plus a platform audit (`outcome_code` only). Platform operators never become School staff through a School invitation |
| Existing membership in this School (`invited`/`active`/`suspended`) | Generic "no longer valid". A suspended membership is **not** reactivated by an invitation |
| School suspended | "No longer valid" (the invitation stays pending until it expires) |

**Flow A** refuses specifically, for the trusted operator: an existing User
with a credential, a disabled User, a non-`provisioning` School.

## 15. Anti-enumeration

- **Issue.** A School learns only its **own** facts: its members and
  invitations. Every other identity state produces the same accepted result
  (§14). Server-side work for ineligible targets still records the pending
  invitation and queues the email, so the School sees nothing different.
  The recipient, if ineligible, simply cannot accept.
- **Acceptance page.** It offers "Set your password" and "Already have an
  account? Sign in, then return" side by side. Whether an account exists is
  disclosed only after a POST, only to the holder of a valid secret (the
  destination mailbox's controller), and never to the School.
- **Failures.** Invalid, expired, revoked, superseded, wrong-School and
  wrong-identity links all show one "invalid or no longer valid" message.
- **Visibility.** Other memberships, platform roles, Group grants,
  Guardian links and disabled status are never shown to a School or in
  School audit.

## 16. Resend, supersession and concurrency

**Flow B:**
- **Resend** revokes the current pending row (`reason = reissued`) and
  creates a new one in **one** transaction, under a row lock. That keeps
  at most **one** pending invitation per `(school, email)`, backed by the
  partial unique index.
- **Revoke** sets `revoked`. A concurrent acceptance and revoke serialize
  on the row lock; exactly one wins.

**Flow A:**
- **One open activation credential per User** (partial unique index).
- A re-issue invalidates the previous one (`superseded`) in the same
  transaction, under a User row lock.
- Activation takes the User lock first, then the credential lock, in
  recovery's lock order.

**Required PostgreSQL concurrency tests** (real overlapping transactions or
processes; no sleeps as correctness):
- two invitations for the same new email in the same School: one pending
  row;
- two Schools inviting the same new email, both accepted concurrently:
  **one** User; the second is refused as "existing account";
- an existing User discovered while another request creates them (insert
  race);
- activation vs supersession, and activation vs revoke;
- duplicate role assignment (unique `(membership, role)`);
- bootstrap-admin replacement vs activation (the existing School row lock,
  re-proven with a credential-less target).

## 17. Audit

**Rules:**
- ids and bounded codes only;
- **never** an email, name, secret, selector, link, password or MFA data.
  Emails are Sensitive; the School audit metadata rule already forbids
  them.
- the subject is the invitation, the membership or the User id.

**Platform ledger** (`platform_audit_events`):
- `platform.account.provisioned`: actor null, `method: console`,
  `user_id`, optional intended `school_id`;
- `platform.account.activation_reissued`: `user_id`;
- `auth.account_activated`: `user_id`, `method: platform_activation`;
- `staff.account_invitation_refused_protected`: `school_id`,
  `invitation_id`, `outcome_code`. It is recorded only on the platform side
  and never reveals the platform role to the School;
- the existing `platform.school.bootstrap_admin_assigned|replaced` and
  `platform.school.activated`, plus the new denial outcome
  `admin_not_activated`.

**School ledger** (`school_audit_events`):
- `staff.account_invited`: `invitationId`, `roleKeys`;
- `staff.account_invitation_revoked`: `invitationId`, `reason`
  (`revoked` / `reissued`);
- `staff.account_activated` (new User): `invitationId`, `userId`,
  `schoolMembershipId`;
- `staff.account_linked_existing` (existing User): the same fields;
- `school.membership.role_assigned`, one per role: `schoolMembershipId`,
  `roleKey`, `invitationId`.

Expiry is derived, so it has no event. Structured logs carry ids and
outcome codes only.

## 18. Data model and ownership (frozen; exact columns belong to the implementation)

**`staff_account_invitations`** — **Identity-domain, School-owned**
(`BelongsToSchool`, `TenantRls::enable`). Columns:
- `id`, `school_id`;
- the canonical `destination_email`, needed to send and to show the
  pending list. Sensitive, never logged;
- `selector`, `secret_hash`;
- `status`, `expires_at`, `accepted_at`, `revoked_at`, `revocation_reason`;
- `invited_by_user_id`, `accepted_user_id`, `email_message_id`;
- timestamps.

Roles are stored in a School-owned child table: `invitation_id`, `role_id`,
with a composite foreign key and a `scope = 'school'` trigger like the
assignment table's. Composite `(id, school_id)` foreign keys follow rule
70. This is **not** `identity_account_invitations`: its Guardian/Student
semantics and path-token scheme stay as they are.

**`account_activation_credentials`** — **Identity-domain, identity-level**
(no `school_id`, no RLS, like `account_recovery_requests`). Columns:
- `id`, `user_id`, `selector`, `secret_hash`;
- the `credential_version` snapshot;
- `expires_at` (CHECK ≤ 72 h), `consumed_at`, `invalidated_at`,
  `invalidation_reason` (closed catalog);
- `created_via` (`console`);
- timestamps.

An immutability trigger keeps consumed and invalidated rows ended.

**Other schema changes:**
- `users.password` becomes nullable with an establishing-write guard (§4);
- `school_memberships.status` gets a CHECK constraint
  (`invited|active|suspended`);
- no change to `membership_role_assignments` history (debt, §22).

## 19. Rate limiting

- **Issue and resend:** an application limiter per **actor per School** and
  per **School per day**, in the shape of `GuardianInvitationSendLimiter`
  but separate. Plus a user-keyed route limiter for School mutations. IP is
  never the primary key for authenticated actions.
- **Acceptance:** a route limiter per IP plus a per-selector limiter, the
  recovery precedent.
- **Flow A:** no HTTP limiter. Its activation POST uses the acceptance
  limiters.

## 20. Tenancy and RLS

- **Invitations and their role rows** are School-owned and forced-RLS.
  School A cannot list, read, revoke or accept School B's invitations. An
  acceptance POST resolves the invitation only inside that School's
  context, from the route's School and the selector.
- **`school_memberships`** stays central discovery data (no RLS, by
  design). It is written only by the acceptance service, inside the
  School's `TenantContext`.
- **`membership_role_assignments`** stays forced-RLS, written through
  `TenantContext::withSchool`.
- **Users and activation credentials** are identity-level, as `users` and
  `account_recovery_requests` already are.
- A School never supplies a `user_id`. Identity resolution is always
  server-side by canonical email.
- **Required tests:** Eloquent **and** raw-SQL RLS isolation for the new
  School tables (rule 28), and missing context fails closed.

## 21. Classification and retention

**New `DATA-CLASSIFICATION.md` rows:**

| Item | Classification |
|---|---|
| Activation / staff-invitation **secret** | **Highly Sensitive** (ephemeral credential) |
| Staff invitation row (destination email, role ids, status) | **Sensitive** |
| Activation credential metadata | **Sensitive** (security credential metadata) |
| Role and membership metadata | Existing tiers, unchanged |
| Bootstrap evidence (platform audit envelope) | Existing platform-audit tier |

**Technical cleanup, not legal retention:**
- activation credentials are pruned **24 h** after they end (recovery
  precedent);
- staff invitation rows are pruned **7 days** after they are accepted,
  revoked or expired. The School can still see recent outcomes, and the
  audit ledger remains the record.

Audit and legal retention stay with ADR 0058 **E21**. No period is invented
here.

## 22. Rejected and out of scope

**Rejected:**
- open staff signup;
- domain-based auto-join (anyone at `@school-domain` becoming staff);
- self-nomination as School Admin;
- an invitation code not bound to one identity;
- any public "create School" or "join School" flow;
- an operator-chosen or printed permanent password;
- a platform HTTP endpoint that creates Users;
- reusing Guardian invitation rows;
- a copy-link fallback in the School UI;
- elevation or Group authority provisioning staff;
- console provisioning after activation (D13).

**Recorded, not decided here:**
- **Staff off-boarding** is a **new O1 finding: DECISION REQUIRED.** No
  School-side action exists to suspend a staff membership or revoke a
  School role. A School that can add staff but never remove their access
  is a production-readiness gap. The owner decides whether it joins
  0O.12B or becomes its own ADR 0058 row. It is **not** silently added
  here.
- `membership_role_assignments` keeps no revocation history (hard delete).
  Decide it together with off-boarding.
- The lost-last-admin break-glass remains future (ADR 0047).
- Guardian invitation rows have no prune, use a path token, and have no
  classification row. This is existing debt, unchanged.
- School Admin MFA as a global rule (§12).
- Employee-first convenience UI: a later nicety. Its domain operation stays
  "invite User + membership, then explicit linkage".
- Stale text, noted and not fixed here (it is code):
  - `BootstrapPlatformRoot`'s closing message still says "There is no
    password reset yet";
  - `AUTHORIZATION.md`'s system-role list omits `platform_auditor` and
    `group_admin`.

## 23. Implementation checkpoint and E24 definition of done

**One executable checkpoint: Phase 0O.12B — Staff / School-Admin Account
Provisioning Foundation.**

**Why one checkpoint.** The two flows have different credential stores,
but they share the credential scheme, the activation page, the
`CredentialChangeService` extension, the credential-less identity state and
the qualifying-administrator amendment. E24's central proof (the
fresh-install test) spans both. Splitting would ship a first School that
cannot add staff, or staff invitations with no way to create the first
School.

**UI scope:** School → Settings → **Staff accounts**, shown only to holders
of `school.members.view`. It offers:
- invite (email, roles from the closed catalog);
- the pending list;
- resend and revoke;
- activation status.

It never shows a password, token, other Schools, platform or Group roles,
or HR data. An optional link to Employee linkage uses the existing HR rules
only.

**The fresh-install test** (the central E24 proof: a real fresh database,
no demo data):
1. no ordinary User exists;
2. `platform:bootstrap-root`;
3. root MFA;
4. the flow-A command;
5. activation;
6. School creation naming that User;
7. activation is refused while the User is credential-less (a separate
   case) and succeeds after activation;
8. the School Admin signs in, selects the School and enrolls MFA;
9. flow B invites a new staff member (fake email), who accepts and signs
   in;
10. an existing User from another School accepts through signed-in
    confirmation;
11. no Employee is created anywhere.

**E24 is complete only when:**
1. a fresh production install can safely create the first ordinary School
   Admin;
2. an active School Admin can provision ordinary staff accounts;
3. User and Employee remain separate;
4. existing-User multi-School invitation is safe and non-enumerating;
5. canonical email uniqueness is concurrency-safe;
6. invitation credentials are single use, expiring and never logged;
7. memberships and role assignments are correct and tenant-safe;
8. root, platform and Group boundaries are preserved;
9. activation cannot bypass MFA or account policy;
10. the School lifecycle bootstrap sequence works;
11. audit is complete;
12. the fresh-install, authorization (allow and deny), RLS and concurrency
    tests pass;
13. the full regression passes;
14. both production images requalify under O16.

**E24 is not complete in this ADR.**

## 24. Consequences

- The first-School deadlock has a contracted exit that does not loosen
  ADR 0047. It adds one prerequisite: the administrator must be able to
  sign in.
- `school.roles.manage` gains its first real use.
- Identity gains one state (credential-less), two credential stores and
  one email purpose. The ADR 0056 recovery boundary is made explicit.
- One new O1 decision is recorded: staff off-boarding.
- No code, configuration, route, GitHub setting or infrastructure is
  changed by this ADR.

## Owner amendment and implementation note — Phase 0O.12B (2026-09-28)

### Owner decision: off-boarding is part of 0O.12B

This resolves the §22 "staff off-boarding — DECISION REQUIRED" finding. It
lives in ADR 0058 row **E24**, which now covers the complete production
staff-account lifecycle (§E24 note there). There is no separate register
row.

- **Off-boarding.** An ACTIVE School membership becomes SUSPENDED, and
  every active School role grant on it is revoked. The operation never:
  - deletes or disables the User;
  - deletes the membership;
  - changes the password, MFA, `credential_version` or human API tokens;
  - touches another School's membership, platform or Group authority, or
    any Employee record.

  `suspended` is the existing authorization kill switch. Every request
  re-checks an active membership, and the target's capability cache is
  forgotten in the transaction and again after commit.
- **Capabilities.** The existing ones are reused, with no new capability.
  Every mutation also needs a fresh MFA code.

  | Action | Capabilities |
  |---|---|
  | Suspend, reactivate | `school.members.manage` + `school.roles.manage` |
  | Grant or revoke one role | `school.roles.manage` |
  | Resend or revoke an invitation | `school.members.manage` |

- **No self-administration.** Your own membership and your own roles are
  refused (`self_administration`). Another School Admin acts.
- **Last qualifying administrator: a hard invariant.** No suspension or
  revocation may leave an active School with zero qualifying
  administrators, as defined in §7.3 and now in
  `App\Support\Authorization\SchoolAdministrators`. The invariant is
  evaluated after the change, inside the transaction.
- **Role grants keep history** (the `platform_role_assignments` /
  `group_role_assignments` pattern):
  - `membership_role_assignments` gains `revoked_at`, `revoked_by_user_id`
    and a closed `revocation_reason` (`revoked`, `membership_suspended`,
    `reactivation_reset`);
  - one active grant per membership and role (partial unique index);
  - revoked rows are immutable (trigger);
  - a re-grant inserts a new row;
  - the runtime role cannot DELETE;
  - authorization reads active rows only;
  - existing rows became active grants.
- **Reactivation** takes an explicit role selection from the closed
  catalog, within the issuer's own capabilities. Previously revoked roles
  never return: new grant rows are inserted. Any grant still active on a
  suspended membership (possible only in data written before
  off-boarding) is revoked (`reactivation_reset`).
- **Pending invitations** keep using the invitation revoke action.
  Off-boarding applies only to an existing active membership.

### As built

- **Schema (migrations `2026_10_28_090000`–`090500`):**
  - `users.password` is nullable, NULL is the only credential-less form,
    and a credential is never removed (`trg_users_password_never_cleared`);
  - `school_memberships_status_check`;
  - role-grant history, as described above;
  - `account_activation_credentials`;
  - `staff_account_invitations` and `staff_account_invitation_roles`;
  - the `staff_account_invitation` email purpose.
- **Flow A:**
  - the command is `platform:provision-school-admin-account`
    (interactive only, no `--force`, operator-connection check, typed-email
    confirmation, `--hours` 1–72, default 24);
  - activation is `GET/POST /account-activation/{selector}`, on the
    platform host only.
- **Flow B:**
  - Settings → Staff accounts is at `/app/settings/staff`;
  - acceptance is `GET/POST /invitations/{school}/staff/{selector}`.
- **Services** (`App\Domain\Identity\Application\Staff`):
  - `StaffInvitationService`, `StaffInvitationAcceptanceService`;
  - `StaffAccessService` (off-boarding, reactivation, grant and revoke,
    with a transaction-scoped advisory lock per School);
  - `BootstrapAccountProvisioningService`, `AccountActivationService`.

  `CredentialChangeService::establishInitialPassword()` is the initial
  password writer.
- **Limiters:**
  - `staff-account-management`: 20 per minute per User;
  - `staff-invitation-accept` and `account-activation`: 20 per 15 minutes
    per IP and 5 per 15 minutes per selector;
  - the application send limiter: 10 per minute per administrator and 200
    per day per School.
- **Cleanup.** `platform:staff-account-credentials-prune`, hourly,
  removes activation credentials 24 h after they end and invitations 7
  days after.
- **Audit (as named):**
  - School ledger: `staff.account_invited`,
    `staff.account_invitation_revoked`, `staff.account_activated`,
    `staff.account_linked_existing`, `school.membership.role_assigned`,
    `school.membership.role_revoked`, `school.membership.suspended`,
    `school.membership.reactivated`;
  - platform ledger: `platform.account.provisioned`,
    `platform.account.activation_reissued`, `auth.account_activated`,
    `staff.account_invitation_refused_protected`, and the lifecycle
    denial outcome `admin_not_activated`.

  Metadata holds ids, role keys and bounded codes only.
- **ADR 0047 bootstrap replace.** It now also revokes the replaced
  administrator's role grant (`membership_suspended`), kept as history
  instead of left active on a suspended membership. A later
  re-establishment inserts a new grant.

**Proof:**
- the fresh-install scenario (`FreshInstallProvisioningTest`), which drives
  the real operator console as a subprocess;
- real-PostgreSQL overlap races (`StaffAccountConcurrencyTest`,
  `SchoolLifecycleConcurrencyTest`);
- raw-SQL invariants and RLS (`StaffAccountDatabaseInvariantsTest`);
- the service, HTTP, session and PAT tests under
  `tests/Feature/Identity/Staff`;
- the architecture guards (`StaffAccountArchitectureGuardTest`);
- a DDEV smoke and browser review.

E24 is **implemented**. It becomes **REPOSITORY_COMPLETE** once the full
regression and both images' O16 qualification are recorded
(`PHASE-0O-READINESS.md`).

## Implementation and qualification amendment — Phase 0O.12B (2026-09-29)

This amendment records facts only. The decisions above are unchanged.

- **Implementation commit:** `e52c4c4` on `main` (Phase 0O.12B).
- **Flow A is implemented:**
  - `platform:provision-school-admin-account` creates a credential-less
    bootstrap User and shows its activation link once;
  - activation is at `/account-activation/{selector}`;
  - School activation refuses an administrator who has not activated
    (`admin_not_activated`).
- **Flow B is implemented:** School staff invitations and acceptance
  (`/app/settings/staff`, `/invitations/{school}/staff/{selector}`).
- **Off-boarding is implemented** (owner amendment):
  - suspension revokes every School role grant;
  - reactivation is explicit, with newly chosen roles;
  - single-role grant and revoke;
  - no self-administration;
  - no global sign-out;
  - the Employee is untouched.
- **Role grants keep history.** `membership_role_assignments` records the
  revocation (`revoked_at`/by/reason). Revoked rows are immutable, there is
  one active grant per membership and role, and the runtime role cannot
  DELETE.
- **Last-administrator invariant.** It is evaluated after each change,
  under a per-School transaction-scoped advisory lock. Real-PostgreSQL
  races prove it, including two administrators removing each other
  (`StaffAccountConcurrencyTest`).
- **Fresh install.** `FreshInstallProvisioningTest` covers the §23
  scenario through the real operator console, on a database with no demo
  data.
- **Final regression.** The same-run complete regression inside the O16
  qualification: 6,500 tests, 0 failures, only the deliberate ESI-12 skip
  (isolated PostgreSQL, Redis and MinIO).
- **Artifact qualification.** Run `local-20260929T002558Z-08871c71` of
  `e52c4c4`: both images **VERIFIED**, with an ephemeral non-production
  signature; PUBLISHED = NONE, PROMOTED = NONE. The digests are in
  `PHASE-0O-READINESS.md` §44.

§23 items 1–14 are met. **ADR 0058 row E24 is REPOSITORY_COMPLETE.** O1
remains **NOT SATISFIED**.

## Amendment — staff and Guardian identities in one membership (POR.1, 2026-10-08; ADR 0070 §9.4)

A School membership may carry staff roles **and** a Guardian account link,
for example a teacher who is also a parent. The two lifecycles are now
independent.
- **What "staff" means:** a membership that holds, or held, a
  **`school`-scope** grant. A `guardian`-scope grant never makes anyone
  staff. `StaffAccessService` (including staff detection and `revokeAll`),
  `StaffAccountDirectory` and the bootstrap-administrator replacement count,
  list and revoke `school`-scope grants only.
- **Staff off-boarding** of a membership that is also a **live, activated
  Guardian** (an active `guardian` grant and a resolving ActingGuardian, never
  merely an account link):
  - revokes the staff roles only (new revocation reason `staff_offboarded`;
    audit `school.membership.staff_offboarded`);
  - leaves the membership **active**, so the Guardian identity and portal
    remain;
  - can be reversed by **reactivation**, which re-grants the chosen staff
    roles with no status change.
- **Guardian off-boarding** (ADR 0070 §9.2) never touches staff roles or a
  membership that holds one.
- **Membership suspension** still ends both identities. Off-boarding a dual
  person from both, staff then Guardian, suspends the membership.
- Every change takes one per-School access lock first (`SchoolAccessLock`,
  the existing `staff-access:` key), shared with Guardian activation,
  unlinking and off-boarding.

## Amendment — SR.0 Staff Role Catalogue (2026-10-09, ADR 0071)

Documentation only; implementation is SR.1–SR.4.
- **§1 "Only two School roles exist":** historical. Since TCH and HRX the
  School catalogue is `school_admin`, `principal`, `teacher` and
  `staff_self_service`. ADR 0071 adds thirteen fixed operational system
  roles (§4 there).
- **Still true:** no runtime writer of `roles` or `role_capabilities`
  (ADR 0071 §11 makes this database-enforced in SR.1); tenant-custom roles
  remain future.
- **§6.1 no escalation:** extended by class-scoped grant rights (ADR 0071
  §6), re-evaluated inside the transaction. Revoking a single role needs
  coverage. Whole-membership off-boarding stays uncovered as an emergency
  safety mechanism (ADR 0071 §10.3).
- **Invitation issue and acceptance** take the School access lock (ADR 0071
  §14). A refused escalation is audited as
  `school.membership.role_grant_refused` (ADR 0071 §13).
- **Viewing** the role catalogue and assignments uses `school.roles.view`
  (ADR 0071 §10.4).
- **Bootstrap:** the ADR 0047 path keeps working through a narrow,
  database-checked exception (ADR 0071 §11.6(b)).
