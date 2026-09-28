# ADR 0056: Account Recovery Contract

- Status: Accepted; **implemented in the repository by Phase 0O.10A**
  (§24). Deployment evidence (§20) is outstanding.
- Date: 2026-09-28 (Phase 0O.10)
- Resolves: **O14** (`docs/architecture/PHASE-0O-READINESS.md` §8)
- Consumes:
  - ADR 0055, which activates the reserved `account_recovery` and
    `security_notice` purposes;
  - ADR 0054, whose canonical platform origin and Host boundary this uses.
- Related: ADR 0037 (MFA), ADR 0044 (elevation), ADR 0046 (root),
  ADR 0047 (School lifecycle), ADR 0049 (API tokens), ADR 0050 (secrets),
  ADR 0051 (observability).

## 1. Context — what exists today (audited 2026-09-28, `origin/main` `180cae8`)

### 1.1 Human and non-human principals

| Principal | Model | Local password | Email | MFA | Session | Personal access tokens |
|---|---|---|---|---|---|---|
| School staff, School admins | `users` | yes (`password`, `hashed` cast) | yes | optional TOTP + recovery codes (ADR 0037); required by `mfa`-gated pages | web session guard | yes (`/app/account/api-tokens`, Sanctum `personal_access_tokens`, ADR 0049) |
| Guardians | `users`, created only by invitation acceptance (`GuardianAccountActivationService`) | yes, set at activation | yes (the invitation's resolved contact, normalized) | same | same | same |
| Group users | `users` with a `group_role_assignments` grant | yes | yes | same (Group pages need MFA) | same | same |
| Platform users (`platform_auditor`) | `users` with a runtime `platform_role_assignments` grant | yes | yes | required for audit review | same | same |
| Root (`platform_super_admin`) | `users` with an out-of-band grant (`platform:bootstrap-root` / `platform:provision-root`, ADR 0046) | yes | yes | reset only by another root (`platform.users.mfa.reset`) | same | same |
| AI Gateway | Ed25519 service identity (ADR 0053) | — | — | — | — | — |
| Partner API clients | `api_clients` / `api_client_credentials` (ADR 0049) | — | — | — | — | own credentials, never a User |

There is **no SSO or federated identity**. Every human is one `users` row,
so a School membership, a Group grant or a platform grant is an
authorization attached to that one identity, never a separate credential.

### 1.2 Facts the contract depends on

- **Email uniqueness.** `users.email` has a plain `UNIQUE` constraint
  (case-sensitive).
  - Both application paths that create a User store the address
    lowercased and trimmed: `PlatformRootProvisioningService::bootstrapFirstRoot()`
    uses `strtolower(trim())`, and guardian activation uses the
    `EmailNormalizer` output of the resolved contact.
  - No application path changes a User's email.
  - The authoritative lookups (`PlatformRoleGovernanceService`,
    `SchoolLifecycleAuthority`, `SchoolGroupGovernanceService`) query
    `strtolower($identifier)`.
  - So **a normalized email identifies at most one User**, and the §4.4
    stop condition does not apply. 0O.10A turns the convention into a
    database guarantee (§4.4).
- **Login** (`LoginController::store()`) calls `Auth::attempt()` with the
  email exactly as typed, so a mixed-case entry never matches the stored
  lowercase value. This is a finding (§17), fixed in 0O.10A with the same
  normalization.
- **No password recovery or change path exists.** Passwords are written
  only at guardian activation and root bootstrap.
  - There is no authenticated password-change route, no email-change route
    and no reset route.
  - Laravel's stock broker is dormant: `config/auth.php` `passwords.users`,
    the `password_reset_tokens` table from the default migration, and
    `User`'s inherited `CanResetPassword`. No route, notification or caller
    uses it. `route:list` has no forgot/reset route. No Fortify, Breeze,
    Jetstream, Socialite or Passport.
- **Password policy.** `Password::defaults()` is used by invitation
  acceptance and root bootstrap. It has no custom definition, so it is
  Laravel's default: at least 8 characters.
- **Sessions.**
  - Sessions are host-only cookies. Production uses the `redis` session
    driver (ADR 0050), which cannot enumerate a user's sessions.
  - `CrossHostHandoff` (ADR 0054) creates a session on another host from a
    one-time ticket.
  - **There is no global per-user session revocation.** Nothing like
    `AuthenticateSession` or a credential version exists; disabling a user
    is enforced by the per-request capability/membership checks.
- **Remember-me.** Login always passes `remember: false`, so no remember
  cookie is ever issued. The `remember_token` column exists and is unused.
- **MFA** (ADR 0037).
  - TOTP factors and hashed single-use recovery codes, owned by the User
    (no School).
  - An MFA-enrolled User completes login only after `/login/mfa`.
  - Lost MFA is handled by an administrative reset: `platform.users.mfa.reset`,
    root-only (ADR 0046), which audits, revokes factors, consumes recovery
    codes and ends elevations (`MfaAdminResetService`).
- **Elevation.** `SchoolElevationService::finishActiveFor(user, reason)`
  exists (ADR 0044). The closed `ElevationEndReason` set has no
  credential-reset reason yet.
- **Email.**
  - The ADR 0055 layer reserves `account_recovery` and `security_notice`.
    The database refuses both today (`email_messages_purpose_check`), and
    `email_messages.school_id` is `NOT NULL`, since v1 had no School-less
    mail.
  - `EmailProviderResolver::criticalEmailAvailable()` is the readiness
    signal (false while `MAIL_PROVIDER=none`, the sending domain is
    missing, and in production while the sending domain is not reserved or
    `MAIL_SENDING_VERIFIED` is false).
- **Hosts.**
  - `SchoolHostSurface` admits only `login`, `login/mfa`, `logout`, `app/…`,
    `invitations/…` and `session/handoff` on a School host.
  - `CanonicalOrigin::platformUrl()` builds platform links from `APP_URL`,
    never the Host.
- **Logs.** `LogSanitizer` redacts `token`-, `secret`- and
  `password`-shaped keys and `?ticket=`. The production access log records
  paths without query strings (0O.8A).

## 2. Decision summary

**Self-service password recovery is an identity-level flow on the canonical
platform host.**

- **Eligible users:** active, non-root human Users with a local password
  and an authoritative email.
- **Request:** enumeration-resistant — the browser always gets one generic
  response.
- **Issuance:** asynchronous. The request path does the same bounded work
  for every input; a queued, encrypted job decides and issues.
- **Credential:**
  - a 256-bit random secret addressed by a random selector;
  - only SHA-256 of the secret is stored;
  - valid for **30 minutes** and **single-use**;
  - at most **3 active per User**;
  - invalidated by any credential-security change;
  - a GET never consumes it.
- **Transport:** the secret travels in the URL **fragment** and the POST
  body only, so it never reaches the server on a GET, a log or a Referer.
- **Reset:** one transaction. It bumps a per-User `credential_version`,
  which ends every session on every host. It also revokes human personal
  access tokens, ends elevations, cycles the remember token and consumes
  every outstanding request.
- **What it never touches:** MFA. The browser is never logged in; it goes
  to the platform login, which still requires MFA.
- **Delivery:** only through the ADR 0055 critical email substrate; the
  feature is refused while critical email is unavailable. Root recovery
  stays operator/console-only.

## 3. Scope boundaries (owner decisions, frozen)

- **Recovery belongs to the human identity, not a School.**
  - A request, credential, email or reset never carries or grants a School
    id, membership, capability, Group authority, platform authority,
    elevation or `TenantContext`.
  - A reset selects no School.
  - One School's lifecycle (suspension, archival) neither enables nor
    blocks recovery of a multi-School User.
- **Recovery resets the PASSWORD ONLY.** It never disables MFA, resets an
  authenticator, regenerates recovery codes or satisfies an MFA challenge.
- **No automatic login.** Afterwards the browser is sent to the canonical
  platform `/login`.
- **Explicitly prohibited:** security questions and any knowledge-based
  authentication (date of birth, student details, School name, phone
  fragments), an email-only MFA bypass, helpdesk disclosure of a password,
  School-specific password credentials, and custom-domain ownership as
  identity proof.

## 4. Eligibility (closed rule)

### 4.1 Rule

A recovery credential is issued, and later honoured, only for a `users` row
that satisfies **all** of:
1. `is_disabled = false`;
2. a non-empty local password hash (every current User; future SSO-only
   Users would fail this);
3. an email equal to the normalized requested address;
4. **no active `platform_super_admin` grant** (§4.2).

The rule is evaluated at issuance and **again at reset** (§11).

### 4.2 Root is excluded; other privileged humans are eligible

- **Root.** The root/bootstrap identity gets **no public email-only
  recovery path**. ADR 0046 already makes root's lifecycle out-of-band.
  - A root that has lost its password is recovered by the operator
    console: 0O.10A adds `platform:user-password-reset {user}` on the admin
    connection (§14).
  - A root that has lost MFA uses another root's
    `platform.users.mfa.reset`, or the ADR 0046 provisioning path when every
    root is lost.
  - The browser still gets the generic response.
- **Platform auditors, Group users and School admins are eligible.** They
  are ordinary humans holding revocable grants, and recovery preserves
  their MFA (§12). Pages that need MFA still need it, and the reset
  revokes every session, token and elevation (§11). Excluding them would
  force every lost password through root, which does not scale and grants
  root nothing it should need.

### 4.3 Invited, pending and not-yet-activated identities

- **Not a User yet.** A Guardian with a pending invitation has no User
  until acceptance, so a request for that address is "unknown": the
  generic response, and no credential.
- **Recovery never touches the invitation lifecycle.** It never accepts an
  invitation, activates or creates a membership, replaces an invitation,
  or reveals one.
- **Activated User with other pending invitations.** Identity recovery
  proceeds normally, and the invitations stay as they are. Accepting one
  still requires the existing signed-in confirmation.

### 4.4 Email uniqueness (verified; hardened in 0O.10A)

- **Lookup.** Recovery normalizes exactly like `EmailNormalizer` (trim,
  lowercase, validate) and looks up `users.email` by equality.
- **Hardening in 0O.10A:**
  - add `CHECK (email = lower(btrim(email)))` on `users`;
  - its migration first audits the table and **refuses** to proceed,
    naming counts only, if any row violates it or two rows collide
    case-insensitively — never guessing a merge;
  - normalize the login email the same way (§17 finding).
- **If a later identity model** lets one address name several humans, this
  ADR must be amended before recovery may run. There is never a "reset
  every matching account" flow.

## 5. Request (enumeration resistance)

### 5.1 Endpoint and response

- **Routes.** `GET /account-recovery` (form) and `POST /account-recovery`
  (request) on the **platform host only**.
- **Validation.** A malformed email fails ordinary validation, which says
  nothing about any account.
- **Response.** Every well-formed request gets the **same** response:
  status, page, flash text and headers are identical. The text is: "If an
  eligible account exists for that address, we have sent password reset
  instructions. The link expires in 30 minutes."
- **Identical cases.** The response is the same for:
  - an unknown email;
  - an eligible User;
  - a disabled User;
  - root;
  - a pending invitation;
  - a suppressed address;
  - the per-identity limit reached;
  - the active-request limit reached;
  - critical email unavailable;
  - recovery disabled but the route reachable (the feature flag still
    serves the generic page).

### 5.2 Uniform request-path work; asynchronous decision

The request handler does, for every well-formed input:
1. normalize the email;
2. compute the throttle fingerprint (§5.3);
3. consult and hit the limiters;
4. dispatch **one** `IssueAccountRecoveryJob` carrying only the normalized
   email;
5. render the generic page.

The job is `ShouldBeEncrypted`, so the address is encrypted in the queue
and `failed_jobs`. It runs on queue `notifications` with `$tries = 1` and
`$timeout = 30`.

- **No account lookup, token creation or email write on the request path.**
  The response time does not depend on the account. There are no fixed
  sleeps.
- **The job decides:** eligibility (§4), the active-request limit (§7.3),
  critical-email availability (§9.2). Only then does it create the request
  row and the email (§9).
- **Residual:** queue load is shared and not account-dependent; the job's
  own duration is invisible to the requester. This is recorded as the
  honest limit — a determined observer of mail delivery timing (a mailbox
  the attacker controls) learns nothing about other accounts.

### 5.3 Rate limits

| Limiter | Key | Limit | When exceeded |
|---|---|---|---|
| `account-recovery-ip` | source IP | 10 per 15 min | generic 429 (route throttle) |
| `account-recovery-global` | constant | 1,000 per hour | generic 429 |
| per identity | fingerprint | 3 per hour and 10 per day | **the generic success response**; no job dispatched |

- **Fingerprint.** `HMAC-SHA256(k, normalized email)`, with
  `k = HKDF-SHA256(APP_KEY, info = "lycenza/account-recovery-throttle/v1")`.
  - It needs no new secret.
  - Rotating `APP_KEY` only resets the counters (bounded, harmless).
  - The plaintext email is never a cache or metric key.
- **No School id** in any limiter.
- **Existing convention.** These follow the named limiters in
  `RateLimiterServiceProvider` (route throttles for IP/global) and
  application-layer keyed limiters (per identity, like
  `GuardianInvitationSendLimiter` and `DomainCheckLimiter`).

## 6. Credential

### 6.1 Format

- **Selector.** 16 random bytes, unpadded base64url (22 characters),
  unique. It is non-secret; it only locates the row.
- **Secret.** 32 bytes from `random_bytes()` (CSPRNG), unpadded base64url
  (43 characters): 256 bits.
- **Link.** `https://<APP_URL host>/account-recovery/{selector}#{secret}`
  (§8.1).
- **Never used as the credential:** a JWT, signed user data, the password
  hash, the email, the user id, a timestamp or a database id.

### 6.2 Storage

`account_recovery_requests` is an identity-level table:

| Column | Notes |
|---|---|
| `id` | UUIDv7 |
| `selector` | unique |
| `user_id` | FK `users`, cascade on delete |
| `secret_hash` | `char(64)`, hex SHA-256 of the secret. With 256 bits of random entropy, plain SHA-256 is sufficient; no pepper or slow hash. |
| `credential_version` | snapshot of `users.credential_version` (§11.2) |
| `email_hash` | SHA-256 of the normalized address at issuance |
| `created_at`, `expires_at` | `expires_at = created_at + 30 min` |
| `consumed_at` | |
| `invalidated_at`, `invalidation_reason` | closed reason: `superseded_by_reset`, `credential_changed`, `email_changed`, `account_ineligible`, `operator` |
| `email_message_id` | nullable |

- **Checks:** at most one of consumed/invalidated; `expires_at` within
  30 minutes of `created_at`.
- **Never stored:** the plaintext secret, a URL, or the request Host.
- **Access.**
  - No `school_id` and no RLS: this is identity/security infrastructure
    like `users` and `user_mfa_recovery_codes`.
  - Only `App\Domain\Identity\Application\AccountRecovery\*` reads or
    writes it (architecture test).
  - No HTTP endpoint lists it. Operator visibility is the metadata-only
    command of §14.
  - The runtime role may not DELETE, except through the prune command's
    narrow path (§13).

### 6.3 Validity

A credential is valid only when **all** of these hold, compared in
constant time (`hash_equals` on the SHA-256):
- the secret hash matches;
- `now < expires_at`;
- not consumed and not invalidated;
- the User is still eligible (§4.1);
- `users.credential_version` equals the snapshot;
- the SHA-256 of the User's current email equals `email_hash`.

## 7. Lifetime, single use, concurrency of requests

### 7.1 Lifetime

The credential lives **30 minutes**, and delivery delay never extends it.
The `account_recovery` email is created with `expires_at` equal to the
request's `expires_at`. ADR 0055 cancels and purges a message not
submitted by then, so no one receives an already-expired link.

### 7.2 Single use

A credential changes a password **exactly once**. Consumption happens in
the same transaction as the password write (§11.1). After a successful
reset, every other active request of that User is invalidated
(`superseded_by_reset`).

### 7.3 Several active requests

- **New requests don't invalidate old ones.** A new anonymous request
  **never** invalidates a still-valid one, so an attacker who knows an
  address cannot keep destroying the owner's link.
- **Cap.** At most **3 unexpired, unused, uninvalidated requests per User**.
  At the cap, the job issues nothing; the browser already got the generic
  response.
- **Per-identity limits.** 3 per hour and 10 per day (§5.3) bound the total.

## 8. Link, pages and browser safety

### 8.1 The secret never reaches the server except in the reset POST

- **Chosen mechanism** (this replaces an optional clean-URL exchange): the
  secret is the URL **fragment**. Browsers never send fragments in
  requests or `Referer` headers, so:
  - a link-scanner or mail-client prefetch of the URL transmits only the
    selector;
  - access logs, proxies and the application never see the secret on a
    GET;
  - the `?ticket=`-style log redaction is not even needed.
- **The reset page script:**
  1. reads `location.hash`;
  2. removes it with `history.replaceState`, so it doesn't stay in history
     or bookmarks;
  3. keeps the secret in memory only;
  4. sends it once, in the POST body, with the new password.
- **Without JavaScript** the page states that JavaScript is required. The
  application is already an Inertia SPA.
- **No server-side browser recovery session** is created, so there is no
  second handle to steal or expire.

### 8.2 GET never consumes

- **`GET /account-recovery/{selector}`** renders the form. It makes no
  state change: no consumption, no invalidation, no counter.
- **Selector check.** It may show "this link is invalid or has expired"
  when the selector is unknown or no longer active — a selector is
  unguessable, and this is UX only. Knowing that a selector is active
  reveals nothing about an email or account.

### 8.3 Reset POST

- **Route.** `POST /account-recovery/{selector}` with body `secret`,
  `password`, `password_confirmation`.
- **CSRF.** Normal web-group CSRF; the route is **not** added to any CSRF
  exception.
- **Throttle** `account-recovery-reset`: 20 per 15 minutes per IP and
  5 per 15 minutes per selector. Keys never include the secret.
- **Every failure gets one response** ("This password reset link is
  invalid or has expired. Request a new one."). The cases are: unknown
  selector, wrong secret, consumed, invalidated, expired, version or email
  changed, ineligible or disabled. There is no token oracle.
- **Password policy.** A new password that fails the policy gets the
  ordinary validation error. That only happens after the credential
  proved valid, so it reveals nothing to an attacker without the secret.

### 8.4 Headers, CSP and forms

Every recovery response (form, request result, reset page, POST result)
carries:
- `Cache-Control: no-store` (the existing `private-no-store` middleware);
- `Referrer-Policy: no-referrer` (stricter than the global
  `strict-origin-when-cross-origin` set by `ApplySecurityHeaders`);
- the existing strict CSP, with no third-party asset, analytics or
  tracking.

Password fields use standard `autocomplete="new-password"`, with the email
as the `username` hint on the request page. Password managers are never
blocked.

### 8.5 No open redirect

The endpoints accept no `return`, `redirect`, `continue` or `next`
parameter. After a successful reset the response is a redirect to the
canonical platform `/login`, with a one-time flash ("Your password was
changed. Sign in with your new password."). Any future "return to my
School" must be a separately validated server-side intent — not in v1.

## 9. Origin, Host and email

### 9.1 Canonical platform origin only

- **Recovery routes are platform-host routes.** They are not in
  `SchoolHostSurface`, so on a School custom host they answer **404**
  (ADR 0054). They are never `/api/v1`, internal, partner or
  service-auth routes. No Host, custom domain or API School id affects
  them, and they never establish `TenantContext`.
- **The School host's login page** shows "Forgot password?" when recovery
  is enabled, as an absolute link to `CanonicalOrigin::platformUrl('account-recovery')`.
- **Recovery links** come from `platformUrl()`, never the request Host, a
  School domain, a provider redirect or user input.
- **Unchanged:** Sanctum stateful domains, CORS and the CSP. There is no
  cross-origin recovery API.

### 9.2 Critical email prerequisite

- **Feature flag.** `ACCOUNT_RECOVERY_ENABLED`, default **false**.
- **When the job may issue.** Only when the feature is enabled **and**
  `EmailProviderResolver::criticalEmailAvailable()` is true.
- **Otherwise it issues nothing.** Under `MAIL_PROVIDER=none`, a missing
  or unverified sending domain, or recovery disabled, the job creates no
  credential anyone could receive and records the metric outcome
  `email_unavailable` or `disabled`. The browser response is unchanged.
- **Degraded provider.** A provider that is configured but temporarily
  failing is normal. The credential and the durable email are created,
  and ADR 0055 retries until the message's expiry, which is the
  credential's.

### 9.3 The `account_recovery` message (activating the ADR 0055 reservation)

- **Class and source.** Purpose `account_recovery`, kind **critical**,
  source type `account_recovery_request`, source id = the request id.
- **Expiry.** `expires_at` = the request's `expires_at`.
- **Still wanted?** Only while the request is still valid (§6.3, minus the
  secret). An invalidated or consumed request's unsent email is cancelled
  (`source_withdrawn`).
- **Content** (identity-level):
  - "A password reset was requested for your Lycenza account.";
  - the expiry time;
  - the one link;
  - "If you did not request this, ignore this email — your password has
    not changed."
- **Never in the email:** a School, membership, role, platform privilege
  or MFA state, a password or a question.
- **Handling.**
  - The sealed content holds a Highly Sensitive link until purge (as
    invitations do).
  - It respects suppression. A suppressed recipient simply gets no email:
    the browser never learns it, and nothing auto-releases a suppression.
  - Provider `submitted` or `delivered` states never mean the User saw or
    used the link.
- **ADR 0055 schema change (0O.10A).**
  - `email_messages.school_id` becomes nullable — **NULL if and only if**
    the purpose is `account_recovery` or `security_notice`.
  - `email_provider_references.school_id` becomes nullable.
  - The purpose, kind and source checks are extended.
  - Identity-level rows are visible to the runtime role **only inside an
    explicit platform-email scope**. This is a new
    `TenantRls::enableWithPlatformScope()` helper: the ordinary School
    policy, OR `school_id IS NULL AND app.platform_email_scope = 'on'`.
    It is set by a `PlatformEmailScope::run()` wrapper that is mutually
    exclusive with a School `TenantContext`, and is **never** hand-written
    SQL (rule 18; ADR 0021/0022 amended).
  - A School context — or no context at all — sees no identity-level row
    (RLS tests required).
  - School-less messages use a separate `platform` budget bucket and skip
    `SchoolOperationalGuard`, because a School's lifecycle is not identity
    authority.

## 10. Reset transaction

### 10.1 Order (one transaction; deadlock-free)

1. Look up the request by selector, without a lock. Unknown → generic
   failure.
2. `SELECT … FOR UPDATE` the **User** first (the same lock order for every
   credential writer), then the request row.
3. Verify §6.3: hash, expiry, state, eligibility, version, email.
4. Validate the new password against `Password::defaults()` and
   `confirmed`, the same rule as invitation acceptance and root bootstrap.
   No second rule and no external breach service (not in the
   architecture).
5. Write `password` with `Hash::make` (the framework hasher).
6. Increment `users.credential_version`, and cycle `remember_token` to a
   new random value.
7. Consume this request, and invalidate every other active request of the
   User (`superseded_by_reset`).
8. Delete every human Sanctum `personal_access_tokens` row of the User.
   Partner `api_clients` credentials, service Ed25519 keys and email
   provider credentials are separate principals and are **never** touched.
9. End active elevations: `SchoolElevationService::finishActiveFor(user,
   ElevationEndReason::CredentialReset)` (a new closed case).
10. Platform audit `auth.password_recovered` (§15).
11. After commit, queue the `security_notice` (§11.4).

No email or provider call runs inside the transaction. No password, secret
or URL enters a job, log, audit record or exception.

### 10.2 Concurrency (0O.10A proves each with real processes, never sleeps)

- **Same credential submitted twice.** The User lock serializes the two;
  the second sees `consumed_at` → generic failure.
- **Two different valid credentials of one User.** The first wins; the
  second sees the version changed (and its row `superseded_by_reset`) →
  generic failure. Exactly one password change happens.
- **Reset versus disable, email change, or another password write.**
  Every credential writer locks the User and bumps the version, so
  whichever commits first makes the other's check fail.
- **Reset versus personal-access-token use.** A request authenticated by
  a token deleted in the committed reset fails on its next lookup. An
  already-running request may finish — the documented residual of
  database-token revocation.

## 11. Global session revocation

### 11.1 Credential version

- **Column.** `users.credential_version` (bigint, default 1, never
  decreasing — trigger-enforced).
- **Who bumps it.** Only `CredentialSecurity::bump($user, reason)`, under
  the User row lock. The callers are:
  - a recovery reset;
  - an operator password reset (§14);
  - any future authenticated password change or email change;
  - disabling a User.
- **What a bump does.** It also invalidates every active recovery request
  (`credential_changed`, `email_changed` or `account_ineligible`), so
  every credential flow agrees.

### 11.2 Sessions on every host

- **Stamping.** At every point a session becomes fully authenticated, the
  session stores `credential_version`. Those points are:
  - password-only login;
  - `/login/mfa` completion;
  - invitation acceptance sign-in;
  - `CrossHostHandoff` redemption (`SessionHandoffController`). The ticket
    carries the version at issue and redemption refuses a mismatch.
- **Checking.** A web-group middleware, `EnforceCredentialVersion`,
  compares it on **every authenticated request** on every host. On a
  mismatch, or a missing value, it logs out, invalidates the session and
  answers like `SessionEndedResponder`.
- **Result.** A reset therefore ends every browser session of the User on
  the platform host and on every School custom host at its next request,
  whatever store holds it. Nothing needs to know the cookies.
- **Deploy cost.** A missing value counts as a mismatch, so deploying
  0O.10A signs everyone out once. Lazily stamping old sessions would let
  a session that stays idle across a reset survive it.
- **Other assurance.** MFA assurance and password-confirmation timestamps
  live in the session, so they die with it.

### 11.3 Remember-me

No remember cookie is issued today (`remember: false`), and 0O.10A keeps
it so. The reset still cycles `remember_token`, and a test proves a
pre-reset remember cookie, if one ever existed, cannot re-authenticate.

### 11.4 Post-reset security notice

After commit, a `security_notice` (critical, identity-level) goes to the
User's email. It says: "Your Lycenza password was changed at <time>. If
this was not you, contact your School or Lycenza support immediately."
It contains no link or token and no School data, and it respects
suppression. Activating `security_notice` is limited to this producer.

## 12. MFA

- **Untouched by recovery:** factors, secrets, recovery codes, enrollment
  state and MFA assurance. The next login of an MFA-enrolled User still
  needs `/login/mfa`. Tests must prove recovery never turns an MFA account
  into a password-only one.
- **Lost MFA is not O14.**
  - The existing procedure stands: another root's `platform.users.mfa.reset`
    (audited; ends elevations), ADR 0037/0046.
  - A **self-service** lost-MFA path is recorded as separate
    security/product debt (§17).
  - No email-only MFA bypass is ever added.

## 13. Cleanup and retention

- **Recovery rows** hold only hashes, ids and timestamps. They are
  technical credential data: a daily `account-recovery-prune` deletes rows
  **24 hours** after they expire, are consumed or are invalidated.
- **This is not the security audit.** The audit (`platform_audit_events`,
  append-only, ADR 0017) is separate; this ADR sets no audit retention
  period.
- **This ADR invents no legal retention period.** The O13
  `MAIL_RETENTION_DAYS` remains **[LEGAL REVIEW REQUIRED]** and is not
  resolved here.

## 14. Operator boundary

- **May see.** A metadata-only console command,
  `platform:account-recovery-status {user}`: request ids, timestamps,
  state, closed reason, and whether the email was queued, submitted or
  suppressed. It is platform-audited as a review.
- **Never shown:** the secret, the hash, a URL or a password.
- **Operator password reset.** `platform:user-password-reset {user}`
  (admin connection, interactive hidden prompt, `Password::defaults()`)
  performs the full §10 effects with `ElevationEndReason::CredentialReset`
  and the platform audit `auth.password_reset_by_operator`.
  - It is the root recovery path, and the high-assurance path for anyone
    else.
  - The operator never learns an existing password; there is no disclosure
    anywhere.
  - It has no HTTP route.

## 15. Logging, audit, metrics, alerts, status

- **Logs.** Closed outcome codes, route, and the request id or selector
  where useful. Never email, secret, password, School, membership or MFA
  state.
  - `LogSanitizer` gains `secret`/`selector`-adjacent recovery keys, the
    `password_confirmation` field and a `/account-recovery/…#…` pattern
    (belt and braces; fragments are never sent).
- **Audit.**
  - Anonymous requests are **not** audited (no flooding). They go to
    metrics and security logs only.
  - A successful reset is audited `auth.password_recovered` (actor = the
    User; metadata: request id, revoked personal-access-token count,
    elevations ended).
  - An operator reset is audited `auth.password_reset_by_operator`, and a
    status review likewise.
  - Never a token, password or URL.
- **Metrics** (closed labels; never email, user, School, selector, IP or
  token):

  | Metric | Labels |
  |---|---|
  | `lycenza_account_recovery_requests_total` | `outcome` ∈ `accepted`, `rate_limited_ip`, `rate_limited_identity` |
  | `lycenza_account_recovery_issuance_total` | `outcome` ∈ `issued`, `unknown`, `ineligible`, `active_limit`, `email_unavailable`, `disabled` |
  | `lycenza_account_recovery_resets_total` | `outcome` ∈ `succeeded`, `invalid`, `policy_rejected`, `rate_limited` |
  | `lycenza_account_recovery_enabled` | gauge |

- **Alerts** (conceptual; OBS ids are assigned in 0O.10A after OBS-38):

  | Condition | Severity |
  |---|---|
  | Request spike | SEV-3, operator value |
  | Invalid-reset spike | SEV-3, operator value |
  | Recovery enabled but critical email unavailable | SEV-2 |

  Recovery-email backlog is already OBS-31 (critical class).
- **Operations Status.** An `account_recovery` component: `disabled`,
  `unavailable` (enabled but critical email unavailable), or healthy.
  Degraded at worst; never readiness.

## 16. Production configuration

- **Default.** `ACCOUNT_RECOVERY_ENABLED=false` is a complete mode: no
  "Forgot password?" link, the routes serve the generic page, nothing is
  issued.
- **When enabled, the production guard refuses:**
  - `account_recovery_email_disabled` (`MAIL_PROVIDER=none`, or no sending
    domain);
  - `platform_url_invalid` (already enforced).
- **Structurally enforced (architecture tests):**
  - the credential-version middleware is registered;
  - no stock broker path exists.
- **Runtime.** An unverified or unreserved sending domain issues nothing
  (§9.2) and shows as `unavailable`.
- **No new secret.** The throttle key is derived (§5.3), and the token
  hash needs none.
- **Legacy disposition (0O.10A).** There will be ONE recovery mechanism:
  - drop `password_reset_tokens`, with a `down()` that recreates it;
  - remove `auth.passwords`;
  - override `User::sendPasswordResetNotification()` to throw;
  - add an architecture test forbidding `Password::broker`,
    `sendResetLink`, `Password::reset` and stock `/forgot-password` or
    `/reset-password` routes.

## 17. Findings recorded for 0O.10A (outside the recovery flow itself)

1. **Login is case-sensitive** against lowercase-stored addresses. Fix:
   normalize at login, plus the §4.4 database check.
2. **A failed login writes the raw email and IP** into platform audit
   metadata (`auth.login_failed`). This is operator-only, but it is the
   only place an attempted address is stored. 0O.10A should replace it
   with a fingerprint (the §5.3 derivation). This is not an O14 blocker.
3. **No authenticated password change exists.** Signed-in users can only
   change a password through recovery. A future change flow must call
   `CredentialSecurity::bump()`.
4. **There is no global session revocation** until §11 lands. Today a
   disabled User's existing session is contained only by the per-request
   capability and membership checks.
5. **Self-service lost-MFA recovery** does not exist, and is separate
   security/product debt. The root-only administrative reset is the
   procedure.
6. **No production path creates staff/School-admin Users** besides root
   bootstrap and guardian activation (demo seeding only). This is an
   onboarding gap, not a recovery one, and is recorded for the roadmap.

## 18. Local, DDEV and tests

- **DDEV** uses the ADR 0055 Mailpit path and the fake event feed; no real
  email.
- **Review list:**
  - an unknown email and an eligible email get the same page;
  - the Mailpit recovery message arrives with a fragment link;
  - a valid reset, an expired reset and a used reset;
  - MFA is still required at the next login;
  - a second browser session (platform host and a fake custom host) is
    signed out;
  - root gets the generic page and no email.
- **Tests** generate secrets at run time. No working token is committed,
  and none is a fixture.
- **0O.10A test matrix:**
  - enumeration: identical responses across the §5.1 cases; no job for a
    rate-limited identity;
  - timing: no account-dependent work on the request path (architecture
    test);
  - tokens: format and entropy, hash-only storage, expiry, single use, the
    3-active cap;
  - invalidation on version, email, disable and root grant;
  - a GET does not consume; the fragment never reaches the server;
  - CSRF, no-store/no-referrer headers, the School-host 404, the policy
    reused;
  - the transaction effects: sessions on two hosts revoked (the handoff
    included), personal access tokens revoked while partner/service
    credentials are not, elevation ended, remember token cycled, MFA
    untouched, the security notice queued after commit;
  - the email expiry equal to the credential's, suppression and outage
    behaviour, the `none` mode, the production guard;
  - real-process concurrency for every §10.2 case;
  - RLS tests for identity-level email rows;
  - the legacy broker removed.

## 19. Definition of done (repository, 0O.10A)

1. The eligibility rule is explicit and enforced twice.
2. The request is enumeration-resistant (uniform path, async issuance).
3. 256-bit hashed credentials are addressed by a selector.
4. Credentials are single-use and expire in 30 minutes, with at most 3
   active.
5. A GET or prefetch never consumes; the secret never sits in a server URL.
6. The ADR 0055 critical substrate (`account_recovery`) is used with the
   identity-level RLS scope.
7. It runs on the platform host only; custom domains carry no authority.
8. `Password::defaults()` is reused.
9. `credential_version` revokes all sessions on all hosts.
10. The remember token is cycled and human personal access tokens are
    revoked; other principals are untouched.
11. MFA is preserved.
12. Rate limits and real concurrency are enforced.
13. Logs, audit and metrics carry no secret or enumerating data.
14. The production guard and feature flag are in place, and the legacy
    broker is removed.
15. The complete regression and O16 requalification pass.

## 20. Deployment evidence (rule 16)

- Production critical email is enabled under ADR 0055: sending domain,
  provider and DMARC at `p=quarantine` or stricter.
- `ACCOUNT_RECOVERY_ENABLED=true` is set deliberately, on a real canonical
  platform origin.
- A non-production drill covers:
  - a successful recovery;
  - an expired link;
  - replay and concurrency;
  - a reset proven to sign out sessions on two hosts (platform and a
    custom School domain);
  - MFA still required afterwards.
- Monitoring and alerts are active.
- The operator runbook (`platform:user-password-reset`, lost-MFA
  escalation) has been exercised.

## 21. O1 contribution

O1 stays open. Phase 0O cannot close while password accounts have no
secure recovery, or while recovery could:
- enumerate accounts;
- bypass MFA;
- leave stolen sessions alive;
- take authority from a School Host;
- send links through an unready email channel.

0O.10A plus §20 removes each of these.

## 22. Alternatives considered

1. **Laravel's stock broker.** Rejected:
   - its tokens are unscoped;
   - the table is keyed by email;
   - a new request replaces the old token, so an attacker can keep
     destroying the owner's link;
   - it has no version binding, cross-host revocation, async issuance or
     critical-email integration.
2. **Signed URLs or JWTs.** Rejected: user data would be the credential,
   and revocation would still need state.
3. **Consuming on GET.** Rejected: scanners and prefetchers.
4. **A clean-URL exchange into a server-side recovery session.** Rejected
   in favour of the fragment: equal protection with less state.
5. **Invalidating older tokens on each new request.** Rejected (account
   denial).
6. **Auto-login after reset.** Rejected (owner decision; MFA stays the
   gate).
7. **Recovery on School custom domains.** Rejected (owner decision): one
   School's hostname never becomes authoritative over a multi-School
   identity.
8. **Email-based MFA recovery, security questions, helpdesk KBA.** Rejected
   (owner decision).
9. **Per-session revocation by deleting sessions from the store.**
   Rejected: Redis sessions cannot be enumerated per User, and cookies are
   host-only. The credential version is store-agnostic.

## 23. Consequences

- O14 is **resolved as a contract**; 0O.10A implements it. Still open:
  **O1, O2, O15**. O2 and O15 (external and payment integration scope) are
  best reviewed together next.
- ADR 0055 gains identity-level email: nullable `school_id`, a
  platform-email RLS scope, and the `account_recovery` and
  `security_notice` producers.
- ADR 0054's School surface is unchanged: recovery is platform-host only.
- ADR 0021/0022 gain one sanctioned `TenantRls` helper mode (§9.3).
- Deploying 0O.10A signs every user out once (§11.2).

## 24. Implementation (Phase 0O.10A, 2026-09-28)

The contract is implemented as written, with the deviations and
clarifications below. Deployment evidence (§20) remains **OUTSTANDING**:
`ACCOUNT_RECOVERY_ENABLED` stays `false` in every deployed environment.

### 24.1 Where it lives

| Concern | Implementation |
|---|---|
| Canonical email (§4.4) | `EmailNormalizer::canonical()` (trim + lowercase; no provider tricks); `User` email mutator; `users_email_canonical_check`, whose migration audits first and refuses (counts only) on non-canonical rows or case collisions |
| Eligibility (§4) | `AccountRecoveryEligibility` — at issuance, at reset and in the email source's `isStillWanted()` |
| Request (§5) | `AccountRecoveryController::store` (normalize, per-address limiter, one encrypted `IssueAccountRecoveryJob` on `notifications`); `AccountRecoveryIssuer` decides off the request path |
| Limits (§5.3) | route limiter `account-recovery-request` (IP 10/15 min, global 1,000/h → 429); `AccountRecoveryIdentityLimiter` (3/h, 10/day, silent) keyed by `IdentityFingerprint` (HMAC, HKDF from `APP_KEY`, info `lycenza/account-recovery-rate-limit/v1`); `account-recovery-reset` (IP 20 and selector 5 per 15 min) |
| Credential (§6–7) | `RecoveryCredential` (16-byte selector, 32-byte secret, base64url, SHA-256); `account_recovery_requests` with database checks (30-minute bound, consumed XOR invalidated, immutable identity, ended stays ended) |
| Reset (§8, §10) | `AccountRecoveryResetService` (User lock, then request lock); `CredentialChangeService` is the one password writer (password, version, remember token, human PATs, elevation `credential_reset`) |
| Revocation (§11) | `users.credential_version` + `trg_users_credential_version_guard` (never decreases; bumps on password, email, disable) + `trg_users_invalidate_account_recovery` (reasons `credential_changed`, `email_changed`, `account_ineligible`); `CredentialSession` stamps at login, MFA completion, invitation acceptance and handoff redemption; `EnforceCredentialVersion` (web group, before School resolution) |
| Identity email (§9.3) | `OutboundEmailGateway::queueForIdentity()`; `PlatformEmailScope` (`app.platform_email_scope`); `TenantRls::enableWithPlatformScope()`; `AllowsIdentityLevelRows`; `email_messages_identity_level_check`; a `platform` fairness bucket |
| Operator (§14) | `platform:user-password-reset`, `platform:account-recovery-status`, `platform:account-recovery-prune` |
| Observability (§15) | metrics below; OBS-39/40/41; Operations Status `account_recovery`; runbook `docs/operations/ACCOUNT-RECOVERY.md` |
| Legacy (§16) | `password_reset_tokens` dropped (`down()` recreates it); `auth.defaults.passwords = null` (Laravel re-merges its framework `passwords.users` entry, so the null default is what makes `Password::broker()` fail closed); `sendPasswordResetNotification()` throws; architecture-tested |

### 24.2 Deviations and clarifications

1. **Metric outcomes.** `requests_total` counts `accepted`,
   `rate_limited_identity` and `dispatch_failed`; `resets_total` counts
   `succeeded`, `invalid` and `policy_rejected`. The IP, global and reset
   throttles answer 429 in route middleware, before the controller, and
   appear in `lycenza_http_requests_total{status_class="4xx"}` rather than
   as `rate_limited_ip` / `rate_limited` outcomes.
2. **Prune cadence.** `account-recovery-prune` runs **hourly** (the §13
   24-hour bound is unchanged). Its heartbeat uses the daily staleness
   threshold, which is lenient for an hourly task.
3. **Operator reset connection.** `platform:user-password-reset` runs on the
   ordinary runtime connection from the operator console, not
   `pgsql_admin`. The runtime role may already write `users.password`, and
   the database triggers apply to every role. It has no HTTP route and is
   interactive only (hidden prompts, confirmation).
4. **Root grant after issuance.** Granting root (only possible out of band)
   does not mark open requests invalidated. The reset re-checks eligibility
   and answers `invalid`, and the email source cancels the undelivered
   message.
5. **Operations Status `disabled`** is reported as Degraded with reason
   `disabled` (the same convention as the email component's disabled mode).
6. **Log sanitizer.** The recovery keys are `recovery_token`,
   `recovery_selector`, `recovery_link`, `recovery_url`, `reset_link` and
   `reset_url` (plus the existing `password` and `secret` words), and two
   value patterns (a `/account-recovery/<selector>#…` link and any
   43-character `#fragment`). A bare `selector` key is deliberately not
   redacted, because a DKIM selector is public configuration.
7. **§17.3.** The central password writer is
   `CredentialChangeService::setPassword()` (the contract's
   `CredentialSecurity::bump()`). A future signed-in password change must
   call it.
8. **Existing sessions at deploy.** A session with no stamp is signed out
   (§11.2 by design), so the deploy of 0O.10A signs every browser out once.

### 24.3 Findings (§17) — disposition

| # | Finding | Disposition |
|---|---|---|
| 1 | Case-sensitive login | **Fixed** (canonical form at login; database check) |
| 2 | Raw email and IP in `auth.login_failed` | **Fixed** (keyed `identity_fingerprint`; the IP stays in the audit envelope's own column, not metadata) |
| 3 | No signed-in password change | **Debt** (must use `CredentialChangeService`) |
| 4 | No global session revocation | **Fixed** (`credential_version`) |
| 5 | No self-service lost-MFA recovery | **Debt** (unchanged procedure: `platform.users.mfa.reset`) |
| 6 | No production staff/School-admin provisioning | **Debt** (onboarding, roadmap) |
