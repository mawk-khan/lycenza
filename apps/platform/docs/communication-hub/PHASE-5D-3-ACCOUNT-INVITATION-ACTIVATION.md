# Phase 5D.3 — Guardian / Student Account Invitation & Communication Activation Foundation

## 1. Ownership and scope

Account provisioning and activation are owned by the Identity domain.
Communication Hub consumes the resulting AccountLink and
SchoolMembership but does not create or activate accounts during
communication delivery.

This checkpoint builds the first real account-provisioning flow in
this application: an admin invites a Guardian's domain identity to
establish an authenticated School OS account. Nothing in
`App\Domain\Communications\*` was changed to create or activate
identities; every new write path for `User`/`SchoolMembership`/
`StudentGuardianAccountLink` lives under `App\Domain\Identity\*`.

## 2. Mandatory identity audit (brief §6)

| # | Question | Answer |
|---|---|---|
| 1 | Existing invitation model/service? | None. Only docblock mentions in `AccountLinkService`. |
| 2 | Existing one-time token pattern? | None in application code. Laravel Sanctum's `personal_access_tokens` hashing convention (SHA-256, already a dependency via `HasApiTokens`) is mirrored for the new `token_hash` column. |
| 3 | Can a SchoolMembership exist before activation? | Yes — `school_memberships.status` already models `invited\|active\|suspended`. This checkpoint's design does not use the `invited` intermediate state (see §5 below) but confirms the column supports it. |
| 4 | Can a User exist without active login credentials? | Structurally yes (no `MustVerifyEmail` enforcement, no forced-first-login flow), but no code path created one before this checkpoint. |
| 5 | Is email verification already modeled? | `users.email_verified_at` column exists; nothing sets it anywhere in application code before this checkpoint. `config/auth.php`'s `passwords` broker and `password_reset_tokens` table exist but are wired to no controller. |
| 6 | What roles currently exist? | `school_admin`, `principal`, teacher/staff roles, and platform roles — no `guardian` or `student` role. |
| 7 | Is there a Guardian role? | No — Guardian is a domain identity (`guardians` table) with an optional `StudentGuardianAccountLink` to a `SchoolMembership`; the linked membership carries whatever ordinary school role (if any) the person independently has. |
| 8 | Is there a Student role? | No — same shape as Guardian, and no Student self-service portal or role exists. |
| 9 | Can AccountLink be created administratively? | Yes — `GuardianAccountLinkController`/`StudentAccountLinkController` (Phase 5B.2) already link an *existing* `SchoolMembership`. This checkpoint adds the complementary "there is no existing account yet" path. |
| 10 | Precedent for linking an existing User instead of duplicating? | Yes — `users.email` is globally unique, so an exact match is treated as the same person; this checkpoint's activation service reuses that exact rule. |
| 11 | How does duplicate email identity behave? | The database rejects it (`users_email_unique`) — no code path before this checkpoint ever needed to reason about reuse because none created Users. |
| 12 | Multi-school User behavior? | `SchoolMembership` already models one User with many memberships across Schools (Phase 5B.2 test coverage: `a_multi_school_users_membership_in_school_b_does_not_satisfy_a_school_a_link`). This checkpoint's activation reuses the same User row across Schools and creates a School-specific `SchoolMembership` per School. |

## 3. Guardian/Student account readiness

Guardian accounts are fully supported by this checkpoint. Student
account provisioning is **structurally deferred** — no Student role or
self-service concept exists in this codebase, so no invitation flow is
offered for Students (brief §7's explicit "Guardian-complete /
Student-deferred" outcome). The `identity_account_invitations` table
carries a nullable `student_id` column for schema symmetry with every
other Guardian/Student pairing in this codebase (`communication_domain_preferences`,
`communication_thread_participants`), but no application code sets it.
`Students/Show.vue` states this honestly rather than exposing a
non-functional "Invite" control.

## 4. Chosen invitation architecture

- **No signed routes.** The RLS/tenant-context chicken-and-egg problem
  (a public route needs `TenantContext::withSchool()` set before an
  RLS-protected lookup by token is possible) is solved with a plain
  `/invitations/{school}/{token}` route: `{school}` is a non-secret
  route-bound `School` (Schools carry no RLS), used only to open tenant
  context; the actual secret is the 64-byte random `{token}` path
  segment, compared via its SHA-256 hash. This is simpler than Laravel
  signed routes (which would add tamper-evidence the token's own
  entropy already provides) and has a real precedent in this
  codebase's URL-based-context pattern (`school_domains`).
- **No User/SchoolMembership at issue time.** `AccountInvitationService::invite()`
  creates only the invitation row. `User`/`SchoolMembership`/
  `StudentGuardianAccountLink` are created for the first time inside
  `GuardianAccountActivationService::accept()`, atomically, only once
  a real person has proven control of the mailbox (new-account branch)
  or is already authenticated as the matching existing account
  (existing-account branch). An invitation that is never accepted
  leaves no orphaned account behind.
- **"Expired" is derived, never a 4th physical status.** `status` holds
  only `pending`/`accepted`/`revoked`; `GuardianAccountInvitation::isExpired()`
  computes `expires_at < now()` on read. No background job flips
  anything.

## 5. Schema

`identity_account_invitations` (migration
`2026_09_02_090000_create_identity_account_invitations_table.php`):

- `id`, `school_id` (RLS via `TenantRls::enable()`)
- `guardian_id` / `student_id` (nullable, `num_nonnulls(...) = 1` CHECK,
  composite FK against `(id, school_id)` on `guardians`/`students`)
- `token_hash` (SHA-256, unique) — the plaintext token is never
  persisted anywhere
- `destination_email_hash` (SHA-256) — never the plaintext address
- `status` (`pending|accepted|revoked`, CHECK constraint)
- `expires_at`, `accepted_at`, `revoked_at`
- `invited_by_user_id`, `revoked_by_user_id`
- Partial unique indexes `giai_one_pending_per_guardian`/`_student` —
  at most one usable pending invitation per Guardian/Student, the
  database-level guarantee behind `resend()`'s revoke-then-reissue
  pattern.

## 6. Token security

- 64 bytes of `Str::random()` entropy, generated with
  `AccountInvitationService::createAndSend()`.
- Stored only as a SHA-256 hash (`token_hash`), mirroring Sanctum's
  own convention.
- Never logged: `AuditRecorder` metadata for every invitation event
  carries only `guardianId`, never the token or email.
- Single-use by construction: acceptance flips `status` to `accepted`
  inside the same transaction that creates the User/Membership/Link;
  a second acceptance attempt fails `isUsable()` and returns the
  generic "no longer valid" error.

## 7. Destination-email integrity (contact-drift)

`destination_email_hash` is compared against the Guardian's
**current** resolved email (via `GuardianEmailAddressResolver`, the
same sole source of truth Communication Hub email delivery uses) both
at the read-only `resolveUsableInvitation()` check and again, under a
row lock, inside `accept()`'s transaction. If the Guardian's contact
changed after the invitation was issued, the token is treated as no
longer valid — closing a window where an invitation could otherwise
silently bind to a different mailbox than the one that received it.
Threat model: without this check, an admin correcting a mistyped
Guardian email after issuing an invitation would leave the *old*
mailbox holder able to activate the account.

## 8. Lifecycle: issue / resend / revoke

- `invite()` — denied with an honest error if the Guardian has no
  active email contact (`GuardianHasNoEmailContactException`), already
  has an active account link (`GuardianAlreadyHasAccountLinkException`),
  or already has a pending invitation (`GuardianAlreadyHasPendingInvitationException`,
  backed by the partial unique index, not just an application check).
- `resend()` — revokes the current pending invitation and issues a
  fresh one in the same transaction, so the partial unique index is
  never transiently violated.
- `revoke()` — marks the current pending invitation revoked; a no-op
  if none exists.
- Expiry is configurable via `GUARDIAN_INVITATION_EXPIRY_DAYS`
  (`config/identity.php`, default 7 days).

## 9. GuardianContact integration

`AccountInvitationService`/`GuardianAccountActivationService` both use
`GuardianEmailAddressResolver` exclusively — never `User.email`, never
a manually copied value. No email contact means no invitation is ever
issued.

## 10. Existing-User reuse

`users.email` is globally unique, so an exact match to the Guardian's
resolved email is treated as definitively the same person. If that
User already exists, `accept()` requires the caller to be
authenticated (`Auth::user()`) as that exact User before proceeding —
the invitation link itself (mere email receipt) is never sufficient to
set a *new* password on an already-established account, since that
would be a strictly weaker guarantee than the existing credential.
`InvitationAcceptanceController`/`Invitations/Accept.vue` present a
"log in, then return here and confirm" UX rather than modifying
`LoginController` or building intended-URL redirect infrastructure.

## 11. Same-School membership reuse

If the resolved User already has a `SchoolMembership` in the target
School (e.g. a staff member invited as a Guardian for their own
child), that membership is reused as-is — its `status` is never
changed by activation, so a `suspended` membership stays suspended.

## 12. Cross-School User behavior

If the resolved User has memberships in other Schools but none yet in
the invited School, a new `SchoolMembership` (`status = active`) is
created for this School only. The User row itself, and every
membership elsewhere, is untouched.

## 13. Staff/Guardian dual-role

Covered by `a_staff_member_invited_as_a_guardian_reuses_their_staff_membership_and_keeps_their_role`
(`GuardianAccountActivationServiceTest`): activating as a Guardian
never removes or alters the person's existing staff role capabilities.

## 14. AccountLink timing

An issued invitation does not itself make a Guardian reachable by
IN_APP. Authenticated reachability begins only after successful
activation and a valid AccountLink. `AccountLinkService::linkGuardian()`
is called for the first time inside `accept()`'s transaction, after
the User/Membership are resolved — never at issue time.

## 15. Transactional activation

`GuardianAccountActivationService::accept()` re-verifies token
usability and destination-email match under a row lock, then performs
User (if new) creation, SchoolMembership resolution/creation, the
AccountLink, and the invitation's `accepted` status flip inside one
`DB::transaction()`. A `PersonaAlreadyLinkedException` (e.g. an admin
manually linked the Guardian via the existing "link existing account"
UI while the invitation was still pending) is caught and treated
idempotently, reusing the existing link rather than erroring — the
brief's "duplicate activation" case.

## 16. IN_APP / conversation activation behavior

After activation, the existing Phase 5B/5D machinery shows IN_APP
reachability and private-conversation eligibility automatically —
`AnnouncementService::publish()` and `CommunicationThreadService::createThread()`
both resolve reachability purely from the real `StudentGuardianAccountLink`
`AccountLinkService` already creates; no "activate communications"
flag exists or is needed. Proven end-to-end in
`InvitationAcceptanceHubTest::after_activation_the_guardian_is_reachable_in_app_and_eligible_for_private_conversations_with_no_further_setup`.

## 17. External Guardian EMAIL independence

Guardian external EMAIL remains independent from account activation
and continues to use GuardianContact plus domain preference/consent.
Nothing in this checkpoint touches `CommunicationDomainPreferenceService`/
`CommunicationConsentService`/`GuardianEmailAddressResolver`'s delivery
path; a Guardian with no account at all continues to receive Optional/
Required EMAIL exactly as Phase 5D.2 established.

## 18. Preference/consent preservation

A domain preference or consent event recorded for a Guardian *before*
activation is never touched by activation — proven by
`a_domain_preference_recorded_before_activation_survives_activation_unchanged`.

## 19. Admin UI

`Guardians/Show.vue` gained an "Invite a new School OS account"
section (shown only when no account link exists): Invite / Resend /
Revoke actions, current status (`pending`/`expired`/`revoked`/
`accepted` — derived, never a raw internal flag), and an honest
"add an email contact first" / "no permission" state. No
token/security data is ever sent to the browser — `AccountInvitationService::currentPendingInvitation()`
exposes only `status` and `expiresAt`. `Students/Show.vue` states
plainly that inviting a new account is not available, only linking an
existing one (already-existing Phase 5B.2 UI, unchanged).

## 20. Authorization

Every invitation admin action (`GuardianAccountInvitationController`)
requires **both** `guardians.manage` (this is a Guardian-facing admin
operation) **and** `school.members.manage` (provisioning a brand-new
`SchoolMembership` is squarely that capability's existing purpose) —
reusing two existing capabilities rather than inventing a new one. No
role-name check anywhere. This means `principal` (which has
`guardians.manage` but only `school.members.view`) cannot invite an
account by default, while `school_admin` can — a deliberately
conservative default for account creation, covered by
`a_principal_cannot_invite_a_guardian_account_by_default`.

## 21. Separation from Communications authorization

`communications.manage` is never checked or required anywhere in the
invitation/activation code path — it remains exclusively the gate for
Communication Hub preference/consent administration
(`GuardianCommunicationPreferenceController`), unchanged from Phase
5D.2.

## 22. Audit events

`guardian.account_invited`, `guardian.account_invitation_revoked`,
`guardian.account_activated`, `guardian.account_linked_existing` — all
via `AuditRecorder::school()`, metadata limited to `guardianId`/
`schoolMembershipId`. Verified never to contain the plaintext token,
email, or password
(`invitation_events_are_audited_without_the_plaintext_token_or_email`,
`activation_events_are_audited_without_the_password_or_email`).

## 23. RLS

`identity_account_invitations` uses `TenantRls::enable()`/`disable()`
exactly like every other tenant-owned table.
`GuardianAccountInvitationsRlsIsolationTest` proves: RLS is enabled and
forced; no-context sees zero rows; School A cannot SELECT/UPDATE
School B's row; a row cannot be inserted claiming a different
`school_id` than the active tenant context; a cross-School Guardian
reference is rejected by the composite FK at insert time; the partial
unique index rejects a second pending invitation per Guardian.

## 24. Rate limiting / public-token privacy

The public `/invitations/{school}/{token}` GET and POST routes are
IP-keyed via a new `guardian-invitation-accept` limiter (20/minute,
`RateLimiterServiceProvider`) — defense-in-depth, since the 64-byte
token is already infeasible to guess. Every failure path (wrong token,
expired, revoked, already accepted, contact-drift, cross-School URL
mismatch) returns the identical generic "This invitation link is no
longer valid" response — no enumeration oracle. Proven by
`a_wrong_token_for_a_real_school_returns_the_same_generic_invalid_state`
and `a_token_issued_for_one_school_does_not_resolve_under_a_different_schools_url`.
Admin invite/resend/revoke routes carry no additional throttle beyond
session auth + capability + CSRF, matching every other admin Inertia
mutation in this codebase (`school-api-mutations` is keyed off a
`{school}` route parameter this `{guardian}`-scoped route group does
not have).

## 25. Security review (brief §84)

| Item | Result |
|---|---|
| Token entropy | 64 bytes, `Illuminate\Support\Str::random()` |
| Storage | SHA-256 hash only, never plaintext |
| Expiry | Configurable, derived (not physically stored) status |
| Single-use enforcement | `status` flip inside the accept transaction |
| CSRF | Standard Laravel `web` group session CSRF (routes live outside `guest`/`auth` but inside the default `web` group) |
| Rate limiting | `guardian-invitation-accept` (20/min, IP-keyed) |
| Enumeration | Identical generic error for every failure reason |
| Open redirect | None — acceptance always redirects to the fixed `/app` |
| Activation URL handling | Plain route params, no query-string trust decisions |
| User reuse | Global email uniqueness, existing-account branch requires prior authentication |
| Duplicate identity | Database `users_email_unique` + this checkpoint's reuse logic |
| Cross-School linking | Composite FK + explicit `school_id` re-verification, never trusts the URL's `{school}` for authorization |
| Credential exposure | Password only ever appears in the POST body over the request the user submits; never logged, never audited |
| Audit leakage | Verified via dedicated tests (§22 above) |

## 26. Performance

No admin list endpoint was extended to show per-row invitation status
in this checkpoint (only the single-Guardian `Show` page), so no N+1
was introduced — `GuardianController::index()` is unchanged.

## 27. Migrations

One new migration, `2026_09_02_090000_create_identity_account_invitations_table.php`.
Proven: incremental migrate (via `platform:test-db-reset --force`,
112 total migrations including this one), targeted `migrate:rollback
--step=1` + reapply (clean, no errors), fresh-from-zero (the same
reset command runs `migrate:fresh` semantics via `pgsql_admin`). No
historical migration was edited.

## 28. Tests

5 new test files, 49 new test methods, 165 new assertions:

- `tests/Feature/StudentGuardianIdentity/AccountInvitationServiceTest.php` — 8 tests
- `tests/Feature/StudentGuardianIdentity/GuardianAccountActivationServiceTest.php` — 12 tests
- `tests/Feature/App/GuardianAccountInvitationHubTest.php` — 6 tests
- `tests/Feature/Identity/InvitationAcceptanceHubTest.php` — 9 tests
- `tests/Feature/Postgres/GuardianAccountInvitationsRlsIsolationTest.php` — 7 tests

Targeted regression (StudentGuardianIdentity, Identity, Postgres, App,
Communications, Auth): **1165 tests, 3775 assertions, 0 failures.**

Full application regression: **2603 tests, 9000 assertions, 0
failures, 0 errors, 0 skips** (published Phase 5D.2 baseline was 2561
tests / 8835 assertions — this checkpoint adds 42 tests / 165
assertions net, after accounting for the new files above having tests
that touch shared fixtures already counted).

## 29. Quality gates

Pint: 1035 files, 0 style issues (after auto-fix). PHPStan
(`--memory-limit=1024M`, full `app/`): 540 files, 0 errors, no new
baseline entries. Prettier: all matched files clean. ESLint: 0 errors
(2 pre-existing warnings in an unrelated file, `Pagination.vue`).
`npm run build`: succeeds.

## 30. Safety

No destructive operation ran against anything but `school_os_test`,
verified via `TestDatabaseGuard` on every invocation. No historical
migration edited. No shared Docker lifecycle command run (containers
were already healthy). No `.env`/`docker-compose.override.yml`/local
infra file staged.

## 31. Out of scope (unchanged from brief §86)

Guardian/Student full self-service portal, a Student account role,
social login, MFA, SMS/WhatsApp invitations, production email provider
selection, a public Guardian preference portal, Communication
broadcast changes, real-time chat, provider webhooks.

## 32. Deferred items

- Student account invitation (no Student role exists to invite into).
- A background/UI listing of an invitation's full history (only the
  current pending invitation is surfaced today — past
  revoked/accepted rows remain in the append-visible table for audit
  purposes but have no dedicated read-model yet).
- A "resend cooldown" beyond the natural revoke-then-reissue
  transaction (no explicit minimum interval between resends is
  enforced beyond normal admin capability gating).

## 33. Independence statements (verbatim, brief §85)

Account provisioning and activation are owned by the Identity domain.
Communication Hub consumes the resulting AccountLink and
SchoolMembership but does not create or activate accounts during
communication delivery.

Guardian external EMAIL remains independent from account activation
and continues to use GuardianContact plus domain preference/consent.

An issued invitation does not itself make a Guardian reachable by
IN_APP. Authenticated reachability begins only after successful
activation and a valid AccountLink.
