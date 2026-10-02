# Phase 5D.3 — Final Integration / Reconciliation Report

Guardian / Student Account Invitation & Communication Activation Foundation.
Local integration only — nothing pushed, nothing deployed.

## 1. Source branch / SHA

`feature/phase-5d-account-activation` @ `f0f9104b484b9ac152919c2bd6b233ac819f08ac`
(worktree `/home/wajidkhan/sites/lycenza-phase-5d-account-activation`).

## 2. Feature commit

`feat(identity): add guardian account invitation activation` — single commit,
merge-base with `main` at `83d6da47826b258a064985d307ada1e6bc65af4a`.

## 3. Starting main

Local `main` (worktree `/home/wajidkhan/sites/lycenza-main-merge`) at gate start:
`ed87ca83afd7969024bcddffc95b506e5eb9127d` — already ahead of the feature
branch's assumed baseline (`83d6da4`) via two independently-integrated,
unrelated phases merged after 5D.2: Finance ledger foundation (0G.0–0G.2) and
Admissions foundation (1D.0–1D.6). `83d6da4` confirmed an ancestor of both
current `main` and the feature tip — accepted, already-reconciled history,
not a reconciliation blocker.

## 4. origin/main

`ed87ca83afd7969024bcddffc95b506e5eb9127d` — identical to local `main`
throughout (0 ahead / 0 behind, confirmed after `git fetch origin`).

## 5. Merge-base

`83d6da47826b258a064985d307ada1e6bc65af4a` (main ↔ feature).

## 6. Integration branch / worktree

`integration/phase-5d-account-activation`, created from local `main`
(`ed87ca8`) at `/home/wajidkhan/sites/lycenza-phase-5d3-integration`. Feature
merged in with `--no-ff` → `168cf1b1fc504f8549fa5fb47d383b8ab20b790b`.

## 7. Conflicts

None. `git merge-tree --write-tree` against current `main` completed cleanly
(exit 0, single resulting tree, no conflict markers) before the real merge
was attempted. The actual `git merge --no-ff` auto-merged `routes/web.php`
with no manual conflict resolution required.

## 8. Identity/Communication ownership boundary

Confirmed directly from source: `AccountInvitationService` and
`GuardianAccountActivationService` live under `App\Domain\Identity\*` and are
the sole write paths for invitation issuance/resend/revoke and for
User+SchoolMembership+AccountLink provisioning. Neither depends on
`AnnouncementService`, `CommunicationDelivery`, `CommunicationRecipient`, or
`ConversationParticipantAuthorizationService`. The invitation email is sent
directly via `Mail::to()`, entirely outside the Communication Hub's
delivery/audience/policy machinery — never recorded as a
`CommunicationAnnouncement`/`CommunicationDelivery`/`CommunicationRecipient`.
Communications contains no account-provisioning logic; it only ever consumes
the `AccountLink` this module produces.

## 9. Guardian activation architecture

Invitation issuance resolves the destination email through the existing
`GuardianEmailAddressResolver` (canonical `GuardianContact` architecture) —
no guessed or separately-copied recipient identity. Activation is a single
`DB::transaction()` in `GuardianAccountActivationService::accept()`:
row-locks the invitation, re-validates usability and destination-email
match, resolves-or-creates the `User`, resolves-or-creates the
`SchoolMembership`, links-or-reuses the `AccountLink`, marks the invitation
accepted — one atomic unit.

## 10. Student deferment

No synthetic Student account role or provisioning path exists.
`identity_account_invitations.student_id` exists for schema symmetry with
`communication_domain_preferences`/`_consent_events`'s established
Guardian/Student pairing shape only — no application code in this checkpoint
ever sets it. `Students/Show.vue` adds an honest note: *"Inviting a
brand-new account (as is available for Guardians) is not available for
Students yet — only linking an account that already exists is supported."*
No fake "Invite Student" control.

## 11. Invitation schema

Table `identity_account_invitations` (migration
`2026_09_02_090000_create_identity_account_invitations_table`):

- `id` (uuid, PK), `school_id` (FK `schools`, cascade delete)
- `guardian_id`, `student_id` (uuid, nullable) — composite FKs
  `(guardian_id, school_id)` → `guardians(id, school_id)` and
  `(student_id, school_id)` → `students(id, school_id)`, both restrict-on-delete
- `token_hash` (char 64, unique) — SHA-256 of the plaintext token
- `destination_email_hash` (char 64) — SHA-256 of the normalized destination
  email at issuance time
- `status` (string, default `pending`) — CHECK constraint restricts to
  `pending`/`accepted`/`revoked`
- `expires_at`, `accepted_at`, `revoked_at` (timestamps)
- `invited_by_user_id` (FK `users`), `revoked_by_user_id` (nullable FK `users`)
- CHECK `num_nonnulls(guardian_id, student_id) = 1`
- Partial unique indexes `giai_one_pending_per_guardian` /
  `giai_one_pending_per_student` on `(school_id, guardian_id|student_id)
  WHERE status = 'pending'` — the database-level "no unlimited concurrent
  valid invitations" guarantee
- `TenantRls::enable('identity_account_invitations')` — RLS **forced**
  (`relforcerowsecurity = t`, confirmed by rollback/reapply verification)
- `down()` disables RLS then drops the table — clean, reversible

## 12. Token security

`Str::random(64)` plaintext token, embedded once in the invitation
email/URL, never persisted anywhere. Only its SHA-256 (`token_hash`) is
stored. Public resolution (`resolveUsableInvitation`) scopes strictly to
`school_id` + `token_hash`, returns `null` on any mismatch. No token value
appears in logs, audit metadata, or any response body anywhere in the
reviewed source. Rate-limited via a dedicated `guardian-invitation-accept`
limiter (20/min, IP-keyed) on both the GET and POST routes. No redirect
parameters exist on this flow (nothing to make an open redirect from).

## 13. Lifecycle

Exactly three physical states: `pending` / `accepted` / `revoked`.
`expired` is deliberately a derived read (`isPending() && expires_at->isPast()`),
never a fourth physical status — avoids a second source of truth requiring a
background job to flip it. `isUsable()` is the single predicate consulted by
both the acceptance controller and the admin read-model, so "usable" cannot
drift between the two call sites.

## 14. Expiry / reissue / revoke

Default expiry: `config('identity.guardian_invitation_expiry_days')`
(env `GUARDIAN_INVITATION_EXPIRY_DAYS`, default 7). `resend()` revokes the
current pending row and creates the new one in the **same transaction**,
so the partial unique index is never transiently violated and no unlimited
concurrent tokens can accumulate; a `UniqueConstraintViolationException` on
that index is also caught and translated to
`GuardianAlreadyHasPendingInvitationException` as a second line of defense.
`revoke()` sets `status='revoked'`, `revoked_at`, `revoked_by_user_id`.
Historical (accepted/revoked) rows are never deleted or overwritten.

## 15. Contact-drift behavior

`destination_email_hash` is captured at issuance and independently
re-derived from the Guardian's **current** contact at both read time
(`resolveUsableInvitation`) and, again, inside the locked write transaction
at `accept()`. If the Guardian's email has changed since issuance, the
re-derived hash no longer matches the stored one and `accept()` throws
`InvitationNotUsableException` — the invitation does not silently activate
against a new destination. This was exercised by
`AccountInvitationServiceTest`/`GuardianAccountActivationServiceTest` in the
42 new tests (0 failures).

## 16. User reuse

`accept()` looks up `User::query()->where('email', $email)->first()` before
creating one. If found, the existing-user branch requires the caller to
already be authenticated as that exact user
(`ExistingAccountConfirmationRequiredException` otherwise) — prevents
account takeover via a bare invitation link. If not found, a new `User` is
created with `email_verified_at` set explicitly post-create (deliberately
not added to the mass-assignment `fillable` list for this one caller).

## 17. Membership reuse

`SchoolMembership` is looked up by `(user_id, school_id)` before creating —
an existing membership for this User+School is reused rather than
duplicated. Regression-verified: AccountLink suite (45 tests, 124
assertions, 0 failures) — no change to existing "one active link"/dual-role
semantics.

## 18. Cross-School User behavior

A User matched by email in another School is reused **as the same User
identity**, but a fresh `SchoolMembership` scoped to the *current* School is
resolved/created — never an `AccountLink` against a foreign School's
membership. `AccountLinkService::linkGuardian()` (pre-existing, unmodified
by this feature) enforces the School-scoped link itself.

## 19. Staff/Guardian dual role

Unmodified by this feature — `accept()` reuses whatever `SchoolMembership`
already exists for the User+School pair (staff or otherwise) and only adds
the Guardian `AccountLink` on top. AccountLink regression suite confirms
existing dual-role semantics unchanged.

## 20. AccountLink timing

Issuing (or resending) an invitation alone never creates an `AccountLink` —
`AccountInvitationService` never touches `AccountLinkService`'s write path.
The link is created for the first time only inside `accept()`'s transaction,
after real proof of mailbox control (new user) or existing authentication
match (existing user).

## 21. Transactional activation

Confirmed atomic: one `DB::transaction()` wraps invitation row-lock,
user resolve/create, membership resolve/create, `linkOrReuse()`, and the
invitation's `accepted` status write. Any exception before the final
`->save()` rolls back the entire unit — no partial User/membership/link/
invitation state possible.

## 22. IN_APP activation

No Communications-layer mutation exists in this feature's diff (confirmed
via `git diff --name-status`, and via the full Communications suite regression:
405 tests, 855 assertions, 0 failures — zero change from Phase 5D behavior).
Guardian IN_APP reachability is a pure downstream consequence of the
`AccountLink` existing, exactly as already implemented in Phase 5B.

## 23. Conversation activation

5D.1 regression suite: 70 tests, 184 assertions, 0 failures. No new
conversation-participation special-case logic was added — eligibility
continues to flow through the existing `ConversationParticipantAuthorizationService`
chain (capability + policy + AccountLink + active membership), unchanged.

## 24. External EMAIL independence

`AccountInvitationService`/`GuardianAccountActivationService` never read or
write `communication_domain_preferences`/`_consent_events`. Guardian EMAIL
delivery continues to depend only on a valid `GuardianContact` + channel
policy + Phase 5D.2 preference/consent state — never on account activation.
5D.2 regression suite: 35 tests, 81 assertions, 0 failures, confirms
preference/consent state is untouched by activation.

## 25. Preference/consent preservation

Same evidence as §24 — no code path in this feature touches either table,
and the regression suite exercising both is unchanged (0 failures).

## 26. Authorization

`GuardianAccountInvitationController` requires **both**
`guardians.manage` and `school.members.manage` (`authorizeBoth()`) for
invite/resend/revoke — never a role-name check. The public acceptance
routes are intentionally the only unauthenticated pair in this checkpoint;
`{school}` is a plain, non-secret route-bound segment used solely to
establish `TenantContext` before the RLS-protected lookup runs, never
itself treated as authorization (the actual secret is the 64-byte token,
compared only via its hash). Capability catalog inventory (67 total, 0
duplicates) confirms both capabilities are real, pre-existing entries — not
invented for this feature, and `communications.manage` gained no new
authority.

## 27. Public-token privacy

Every failure path (wrong token, expired, revoked, already accepted, or
destination-email drift) returns the identical generic "invalid or expired"
response — no enumeration oracle. Lookup is scoped to
`school_id + token_hash` only; RLS remains enforced for the tenant-scoped
`identity_account_invitations` table throughout (this is not a global RLS
bypass — see §11's forced-RLS confirmation). Rate limiting: 20/min, IP-keyed
(`guardian-invitation-accept` limiter), applied to both GET and POST.

## 28. Audit

`AuditRecorder` events confirmed for: `guardian.account_invited`,
`guardian.account_invitation_revoked` (reason `reissued` or `revoked`),
`guardian.account_activated` (new user) / `guardian.account_linked_existing`
(existing user). Metadata is limited to `guardianId`,
`schoolMembershipId`, and (for revocation) `reason` — no token, no token
hash, no password, no GuardianContact plaintext, no phone number appears in
any audit call site reviewed.

## 29. RLS

`identity_account_invitations` uses `TenantRls::enable()` — same mechanism
as every other tenant-owned table, RLS forced (confirmed via
`relforcerowsecurity = t` after the targeted rollback/reapply). Dedicated
`GuardianAccountInvitationsRlsIsolationTest` is one of the 42 new tests
(0 failures). Full `tests/Feature/Postgres` suite: 307 tests, 540
assertions, 0 failures.

## 30. Migrations

One new migration in this feature. Fresh-from-zero on the isolated test
database: **115 total migrations**, 0 pending, 0 errors (112 published as of
Phase 5D.2 + this feature's 1 + 2 more legitimately merged into `main` via
Finance/Admissions since the 5D.2 baseline — not a discrepancy). Targeted
rollback/reapply of only `identity_account_invitations`: table removed
cleanly on rollback, reapplied cleanly, RLS/constraints/indexes/composite
FKs restored exactly as originally migrated. No other migration touched.

## 31. New Phase 5D.3 tests

**42 tests, 165 assertions, 0 failures** — exact match to the feature
branch's reported numbers. Confirmed across the 5 expected files
(`AccountInvitationServiceTest`, `GuardianAccountActivationServiceTest`,
`GuardianAccountInvitationHubTest`, `InvitationAcceptanceHubTest`,
`GuardianAccountInvitationsRlsIsolationTest`).

## 32. Authentication regression

`tests/Feature/Auth/LoginTest.php` (the only authentication test file in
this repository): 6 tests, 22 assertions, 0 failures.

## 33. Communications regression

405 tests, 855 assertions, 0 failures across the full Communications suite.
Phase 5D.3 makes no Communications implementation changes, and the
regression run confirms zero behavioral drift.

## 34. Full regression

- AccountLink: 45 tests / 124 assertions / 0 failures
- Phase 5D.1 (conversations): 70 tests / 184 assertions / 0 failures
- Phase 5D.2 (preference/consent): 35 tests / 81 assertions / 0 failures
- GuardianContact: 25 tests / 48 assertions / 0 failures
- Authentication: 6 tests / 22 assertions / 0 failures
- RLS (`tests/Feature/Postgres`): 307 tests / 540 assertions / 0 failures

## 35. Quality gates

Pint: PASS (1072 files). PHPStan: 0 errors across 560 files — **no baseline
file exists in this repository at all** (not merely "no new baseline
entries"). Prettier: PASS. ESLint: 0 errors (2 pre-existing warnings in
`Pagination.vue`, a file untouched by this feature). `vue-tsc`: PASS.
`npm run build`: PASS (confirmed twice).

## 36. Infrastructure safety

The repository's `docker-compose.yml` hardcodes `name: school-os` — every
worktree that runs `docker compose up` without an explicit project-name
override shares that single Compose project. Mid-verification, the shared
`school-os` project's containers were recreated by a concurrent session
working in a different worktree, crashing this verification's in-progress
containers (not caused by this integration effort). Remediated by giving
this worktree its own isolated Compose project (`-p school-os-phase5d3`,
ports 63432/63379/63900-63901 via a local, untracked
`docker-compose.override.yml`) — no other worktree's containers, volumes,
or in-progress work were touched or reused. Stable and healthy for the
remainder of the verification. No `docker compose down -v` was ever run;
Redis was never flushed.

## 37. Full application suite

First attempt hit a PHP memory-limit fatal in `artisan test`'s child
process (the child doesn't inherit `-d` CLI flags) — fixed with a
persistent 512M `php.ini` memory limit. Second attempt surfaced 5 failures,
all in `DocumentReadMinioIntegrationTest` — root-caused to the fresh
isolated MinIO volume never having had its `school-os-local` bucket
created (a Documents-module environment gap, unrelated to this feature).
Bucket created, full suite rerun clean:

**2796 tests, 9662 assertions, 0 failures, 0 errors, 0 skips.**

One clean run performed (not two, for time — see §38, deferred).

## 38. Deferred

- A second consecutive clean full-suite run (time-boxed out; the one run
  performed was clean with 0 failures/errors).
- Recovery of the `feature/phase-1e-student-lifecycle` session's own WIP
  found misplaced in the `main` worktree during pre-flight (62 files,
  Admissions-module removal + shared-file edits) — safely `git stash`'d in
  `lycenza-main-merge` (still present there, undropped) and additionally
  `git stash apply --index`'d into that session's own worktree
  (`lycenza-phase-1e-student-lifecycle`) so the work is recoverable in
  both places. Not committed anywhere — left for that session's owner to
  resolve.
- Everything explicitly out of scope per the original brief: production
  email provider work, Student account-role design, Guardian/Student
  portal work, any next Phase 5 checkpoint.

## 39. Publication readiness

Local integration only. `origin/main` was not touched. Recommend the
Phase 5D.3 Remote Publication Gate as the next explicit, separately
authorized step — not executed here.

## 40. Next recommendation

**PHASE 5D.3 REMOTE PUBLICATION GATE** (not executed — requires separate,
explicit authorization per root CLAUDE.md rule 16).

## Later amendment — E21.3B retention (2026-10-02)

Ended `identity_account_invitations` (accepted, revoked, or expired while
pending) are deleted `PORTAL_INVITATION_RETENTION_DAYS` (adopted 7) after
that canonical end by `platform:portal-invitations-prune`; a usable
invitation is never touched, and the secret is unusable from the moment it
ended. See `docs/security/E21-RETENTION-DETERMINATION.md` §5.6.
