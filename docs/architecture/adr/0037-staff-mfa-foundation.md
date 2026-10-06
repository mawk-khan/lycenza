# ADR 0037: Staff MFA Foundation

- Status: Accepted
- Date: 2026-09-04

## Context

Phase 0H.4D's StudentMark engineering-readiness audit identified staff
multi-factor authentication as a mandatory platform prerequisite for
any future Highly Sensitive capability (StudentMark chief among them),
independent of StudentMark's own unresolved children's-data legal
gate. This ADR is that prerequisite: generic identity/session security
infrastructure, with zero coupling to Examinations, marks, or any
other consumer module. It closes the architecture gate from the prior
Phase 0H.4D-P1 readiness review and records what was actually built,
including two real bugs found and fixed during implementation.

Before this checkpoint, `LoginController`'s own docblock read *"No
custom cryptography, no OIDC/SSO/MFA yet"* and `docs/security/
AUTHORIZATION.md` had zero mentions of MFA despite that same docblock
claiming a documented future path existed there.

## Decision

### Factor strategy: TOTP only, v1

RFC 6238 TOTP via `pragmarx/google2fa` (pure PHP, no external network
dependency, verified compatible with this repo's `php: ^8.3` /
`laravel/framework: ^13.17`) plus `bacon/bacon-qr-code` for
enrollment. SMS and email OTP were deliberately rejected: both
introduce a third-party delivery provider (cost, an external
processor, the same India-data-localization exposure already open for
StudentMark's own production gate), and email OTP specifically
weakens the "second factor" property when email is also a plausible
future password-reset channel. WebAuthn/passkey is the natural next
factor type — the schema's `type` column already accommodates it
without a migration — but is out of scope for v1.

### One active factor per User, v1, database-enforced

`user_mfa_factors` carries a partial unique index,
`user_mfa_factors_one_active_per_user` (`ON user_mfa_factors (user_id)
WHERE status = 'active'`), the exact same pattern
`academic_years_one_active_per_school` already established for "only
one active X per Y." This is the real guarantee, not a
controller-level check-then-insert — proven by
`Tests\Feature\Auth\Mfa\MfaFactorConcurrencyTest`, two genuine OS
processes racing to activate two different pending factors for the
same User.

### Recovery codes: hashed, single-use, atomic consumption

`user_mfa_recovery_codes.code_hash` uses Laravel's `hashed` cast (same
treatment as `users.password`) — verify-only, never needs to
round-trip to plaintext, unlike `user_mfa_factors.secret_encrypted`
(Laravel's `encrypted` cast, the same reversible-secret pattern as
`webhook_endpoints.secret_encrypted`). Ten codes are issued per
enrollment confirmation or regeneration; issuing a new set marks every
previously-unused code `consumed_at = now()` in the same transaction.
Consumption is an atomic conditional `UPDATE ... WHERE consumed_at IS
NULL`, never check-then-update — proven by
`Tests\Feature\Auth\Mfa\MfaRecoveryCodeConcurrencyTest`, two genuine OS
processes racing to consume the same code.

### Session-scoped assurance, not a database fact

`session('mfa_verified_at')` records when a User last completed an MFA
challenge. `App\Support\Auth\Mfa\MfaChallengeService::hasValidAssurance()`
checks it against `config('mfa.assurance_window_minutes')` (default
60 — deliberately shorter than `config('session.lifetime')`'s 120
minutes, so a stale-but-technically-live session cannot coast
indefinitely on one earlier check for a Highly Sensitive operation).
**Bug found and fixed during implementation, then corrected again at
closure:** the initial comparison used
`now()->diffInMinutes($verifiedAt) <= $window` — Carbon 3 returns a
*signed* difference by default (unlike Carbon 2's implicit
absolute-value behavior), so an expired, in-the-past timestamp
satisfied `<=` and was wrongly treated as still valid. The first fix
wrapped the comparison in `abs()`, which over-corrected: `abs()` also
makes a *future* stored timestamp within the window pass, which is
never legitimate here (both `mfa_verified_at` and
`password_confirmed_at` are written only server-side via a plain
`now()` at the moment of establishment — a future value can only mean
tampered/corrupted session state or clock skew). The closure
correction replaces `abs()` with `App\Support\Auth\AssuranceFreshness`,
a single shared helper (used by both
`MfaChallengeService::hasValidAssurance()` and
`PasswordConfirmationService::require()`) that compares raw Unix
timestamps rather than a Carbon diff method, so the sign of "age" is
unambiguous by construction: missing → invalid; malformed/unparseable
→ invalid (caught, never an uncontrolled parse exception); future (age
< 0) → invalid; age beyond the configured window → invalid; a genuine
past timestamp within the window → valid. Covered by dedicated tests
for every one of those five states, including the boundary exactly
inside and exactly outside the window.

### Two-stage login, single session-regeneration point preserved

A User with no active factor keeps today's exact single-stage login,
proven byte-for-byte unchanged by
`Tests\Feature\Auth\PasswordOnlyLoginUnchangedTest`. A User with an
active factor: `Auth::attempt()` still validates credentials, but
`LoginController::store()` immediately `Auth::logout()`s again and
stores `session('mfa_pending_user_id')` rather than letting that
temporary login stand — `$request->user()` is null again until
`MfaChallengeController::store()`'s stage 2 (a valid TOTP or recovery
code) calls `Auth::login()` and `session()->regenerate()`, the same
single point stage-1-only logins already regenerate at.
`auth.login_succeeded` fires exactly once, at whichever point full
assurance is actually established — never at the password-only
midpoint for an MFA-enrolled User.

### Opt-in per-route enforcement, never global; MFA never substitutes for capability

`App\Http\Middleware\RequireMfa` (alias `mfa`) is attached explicitly,
alongside `capability:`, never replacing it:

```
->middleware(['auth', 'school-membership', 'capability:X', 'mfa'])
```

No active factor → `403 mfa_required_not_enrolled`. Active factor,
missing/expired assurance → `401 mfa_step_up_required`. Both are
returned as direct JSON via `App\Support\Auth\RendersAuthJsonErrors`,
not thrown up to `bootstrap/app.php`'s global exception envelope —
that envelope is deliberately scoped to `/api/*` only, and every route
`mfa` protects is a JS-driven request to a Highly Sensitive action, not
a full page load. `Tests\Feature\Auth\Mfa\MfaMiddlewareTest` proves all
three outcomes plus composition: valid MFA assurance never substitutes
for a missing capability (the capability gate denies first, before
`mfa` even runs). `Tests\Feature\Auth\Mfa\MfaMiddlewareArchitectureGuardTest`
asserts the middleware and every MFA foundation source file contain
zero reference to Examinations/marks/StudentMark or any SMS/email
provider.

### User-global ownership, no `school_id`, platform-scoped administrative reset

`user_mfa_factors`/`user_mfa_recovery_codes` carry no `school_id` and
no RLS — MFA is User identity data, and a User can hold
`SchoolMembership` rows in many Schools (`SchoolMembership`'s own
"central/platform data, do NOT add school_id-based RLS" docblock is
the precedent). `Tests\Feature\Auth\Mfa\MfaMultiSchoolIdentityTest`
proves one User has exactly one MFA state regardless of which School
is active. Administrative reset is consequently **not** a School-admin
action — a School admin resetting a User's MFA would carry
cross-School blast radius on an object it doesn't own. A new
platform-scoped capability, `platform.users.mfa.reset`, granted to
`platform_super_admin`, gates `App\Http\Controllers\App\Account\MfaAdminController::reset()`,
following the exact `authorizeCapability(..., platform: true)` pattern
`Api\Internal\OperationsController` already established for
`platform.operations.view`. This was **fully implementable** with
existing platform-capability infrastructure — no restructuring was
needed. `Tests\Feature\Auth\Mfa\MfaAdminResetTest` proves a
`platform_super_admin` can reset, and that a School admin (even one
holding `school.settings.manage`/etc. in their own School) cannot.

### Fresh password re-confirmation, generic and narrow

`App\Support\Auth\PasswordConfirmationService` is a new, narrow seam —
no equivalent existed anywhere in this codebase before this
checkpoint. It gates: beginning enrollment, disabling MFA, and
regenerating recovery codes. It is deliberately not a password-reset
system and is not mixed with forgotten-password recovery (this
codebase has no password-reset flow at all — that remains explicitly
out of scope here). Self-service disable additionally requires a
currently-valid TOTP or recovery code, proving continued factor
possession — a stolen session plus password alone is never sufficient
(`Tests\Feature\Auth\Mfa\MfaFactorServiceTest`).

### Audit: closed vocabulary, platform-level, never the secret

`App\Support\Auth\Mfa\MfaAuditActions` defines a closed set —
`auth.mfa_enrollment_started`, `auth.mfa_enrolled`, `auth.mfa_disabled`,
`auth.mfa_recovery_codes_regenerated`, `auth.mfa_reset_by_admin`,
`auth.mfa_challenge_failed`, `auth.mfa_challenge_succeeded` — all
written via `AuditRecorder::platform()`, the same `auth.*` family
`LoginController` already uses (MFA is User/identity-level, not
School-scoped). None ever carry the TOTP secret, a submitted code, a
recovery-code plaintext or hash, or the otpauth URI, proven by
`Tests\Feature\Auth\Mfa\MfaAuditPayloadSecurityTest` against real
issued values, not synthetic placeholders.

### TOTP verification window and replay behavior (actual, not aspirational; corrected)

Clock-skew tolerance is `config('mfa.totp_window')`, default 1 —
google2fa's adjacent 30-second time-steps either side of "now," not an
unbounded window.

**Replay counter vs. human timestamp — corrected model.** google2fa's
`getTimestamp()`/`verifyKeyNewer()` operate on a 30-second TOTP
**period counter** (`time() / 30`), never a Unix epoch timestamp,
despite the library's own parameter being named `$oldTimestamp` — an
earlier revision of this ADR (and the code it described) incorrectly
stated that google2fa "returns the actual epoch time-step timestamp."
It does not. `user_mfa_factors` therefore carries two distinct
columns, never conflated:

- **`last_used_totp_step`** (nullable `bigint`) — the google2fa period
  counter accepted at the last successful verification. This is the
  *only* value ever passed as `verifyKeyNewer()`'s `$oldTimestamp`
  argument or read back from it. Never a datetime. Null until the
  first successful verification.
- **`last_used_at`** (nullable `timestamp`) — a genuine wall-clock
  Carbon timestamp of when a TOTP factor last verified successfully,
  written via a plain `now()`, for humans/audit/observability. Never
  fed back into google2fa.

**Atomic replay claim.** `MfaChallengeService::verifyTotp()` performs
the read of `last_used_totp_step`, the `verifyKeyNewer()` check, and
the write of the newly accepted step inside one `SELECT ... FOR
UPDATE` transaction on the factor row. A prior version of this
verification read-then-wrote without a lock, which the closure audit
proved allows two genuinely concurrent requests submitting the
identical valid code to both read the same stale floor and both
succeed — defeating "reject a code from the same or an earlier
time-step than the last one this factor successfully verified"
entirely under concurrency, not just at the margins. With the row
lock, a second concurrent request for the same TOTP step blocks until
the first commits, then re-reads the now-advanced floor and is
correctly rejected. `verifyKeyNewer()` is always called with an
explicit floor (`0` when the factor has never verified) rather than
falling back to plain `verifyKey()` with no floor — google2fa's
`findValidOTP()` returns the boolean `true`, not the matched step, on
success only when no floor is supplied, and casting that boolean to
`(int)` is what previously corrupted the counter's predecessor
(`last_used_at`, doubling as the counter) to a meaningless value on
enrollment confirmation, leaving the enrollment code itself replayable
at the very next login. Enrollment confirmation
(`MfaEnrollmentService::confirm()`) now establishes the replay floor
identically, from the real accepted step, in the same transaction that
activates the factor.

**Concurrent-identical-TOTP invariant**, proven by a committed
real-process test (two genuinely separate OS processes, not
sequential simulation): for one factor and one valid TOTP period, at
most one concurrent request may successfully claim that step — exactly
one `ACCEPTED`, exactly one `REJECTED`, enforced by the database row
lock, not by application-level comparison alone.

This is still **not** RFC-level replay resistance against an attacker
who captures a code and replays it in a *later*, not-yet-used
time-step before the legitimate user does — no TOTP library prevents
that without an additional out-of-band signal, and this implementation
makes no such claim.

### Rate limiting: one named limiter per risk profile

Following `RateLimiterServiceProvider`'s established convention (never
one generic limiter reused across risk profiles): `mfa-challenge`
(login-stage code guessing, keyed off `session('mfa_pending_user_id')`
since the request isn't authenticated yet), `mfa-enrollment-confirm`,
`mfa-recovery-code`, `mfa-password-confirmation` (all three
actor-keyed). None reuse the existing `login` limiter — a distinct
attack surface gets its own bound.

## Consequences

- Any future Highly Sensitive route composes `['capability:X, 'mfa']`
  and inherits all of the above for free — no Examinations-specific
  (or any consumer-specific) MFA code is ever required.
- `docs/security/AUTHORIZATION.md` and `docs/security/DATA-CLASSIFICATION.md`
  now have a real "documented future path" and an explicit
  authentication-secrets example respectively, closing the gap
  `LoginController`'s docblock had been pointing at.
- WebAuthn/passkey support, per-action step-up beyond the
  assurance-window model, and any teacher/class-scoped enforcement
  question remain explicitly deferred, not designed here.
  *Note (RES.0B, 2026-10-06):* since then, ADR 0049 added a fresh MFA
  re-verification (`FreshMfaRequirement`) for consequential actions, and
  ADR 0063 (TCH) added teacher/class-scoped ownership. ADR 0068 §9.2 uses
  both for StudentMark: `mfa` on every marks route, fresh re-verification
  for the lock and correction approval; teacher marks entry is RES.4.
