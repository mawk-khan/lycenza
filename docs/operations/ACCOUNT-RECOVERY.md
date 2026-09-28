# Runbook: account recovery (ADR 0056, OBS-39–41)

**Status.**
- **Repository implementation COMPLETE (Phase 0O.10A).**
- **Deployment evidence OUTSTANDING.** Self-service recovery depends on
  production critical email (ADR 0055), which has no provider, sending
  domain or DNS yet. `ACCOUNT_RECOVERY_ENABLED` stays `false` in every
  deployed environment until the checklist below holds (rule 16).

## What it is

- One identity-level flow on the **platform host only**:
  `https://<APP_URL>/account-recovery`. A custom School host answers 404; an
  unknown host answers 421 (ADR 0054).
- A person submits an email address. The response is always the same
  generic message — whether the account exists, is disabled, is root, has
  no password, is over its limits, or email is unavailable.
- An eligible account (not disabled, not root, a local password, an email
  address) receives a link
  `https://<APP_URL>/account-recovery/<selector>#<secret>`:
  - a 16-byte selector and a 32-byte secret (base64url); only SHA-256
    hashes are stored in `account_recovery_requests`;
  - the secret lives in the URL **fragment**, which browsers never send to
    a server, and the page removes it from the address bar at once;
  - valid 30 minutes, single use; at most 3 open requests per account, and
    a new request never invalidates older ones;
  - opening the link (GET) consumes nothing, so mail scanners are harmless.
- A successful reset, in one transaction:
  - sets the password (`Password::defaults()`);
  - bumps `users.credential_version`, which signs out every session on
    every host (platform and custom School hosts);
  - cycles the remember token; revokes the account's human personal access
    tokens (partner API credentials and service identities are untouched);
  - ends an active platform elevation (`credential_reset`);
  - invalidates every other open recovery request.

  MFA is **not** changed: the next sign-in still needs the second factor.
  There is no automatic sign-in. A `security_notice` email follows.
- **Root** (`platform_super_admin`) never has email recovery. Its only path
  is `platform:user-password-reset` on the operator console.

## Configuration

| Setting | Meaning |
|---|---|
| `ACCOUNT_RECOVERY_ENABLED` | `false` by default: the routes still answer (generic), the login page shows no link, nothing is issued. Production refuses `true` unless critical email is configured (`account_recovery_email_disabled`). |
| `ALERT_ACCOUNT_RECOVERY_REQUESTS_PER_HOUR` | OBS-39 operator baseline (unset disables the tier, explicitly). |
| `ALERT_ACCOUNT_RECOVERY_INVALID_RESETS_PER_HOUR` | OBS-40 operator baseline. |

The fixed limits (`config/account_recovery.php`) are:
- requests: 10 per IP per 15 minutes and 1,000 per hour globally (429);
- per email: 3 per hour and 10 per day, keyed by an HMAC fingerprint
  derived from `APP_KEY` (over-limit answers the same generic success);
- reset submissions: 20 per IP and 5 per selector per 15 minutes.

## Operator commands (console only)

- **`platform:user-password-reset {email-or-id}`** — interactive only. It
  takes the new password twice through hidden prompts and asks for
  confirmation. It has the same effects as a self-service reset. Audited as
  `auth.password_reset_by_operator`, and a security notice is sent. Use it
  for root, and for anyone whose identity was verified out of band.
- **`platform:account-recovery-status {email-or-id}`** — shows, without
  secrets:
  - eligibility;
  - open and recent requests (created, expires, consumed or invalidated,
    reason);
  - the credential version.

  Audited as `auth.account_recovery_status_viewed`.
- **`platform:account-recovery-prune`** (scheduler, hourly) — deletes
  requests that ended or expired more than 24 hours ago.

**Lost MFA** is not recovery. It stays the audited
`platform.users.mfa.reset` operator action (ADR 0037/0046). There is no
self-service MFA recovery.

## Deployment evidence checklist

1. `docs/operations/EMAIL-DELIVERABILITY.md`'s checklist holds, and
   `platform:mail-status` shows critical email available.
2. The Operations Status `account_recovery` component reads `disabled`,
   then — after enabling — healthy (not `unavailable`).
3. A drill in a non-production environment with sandbox addresses:
   - request → mail → reset;
   - the old session is signed out on the platform host and on a custom
     School host;
   - the security notice arrives;
   - an unknown, a disabled and a root address each show the same generic
     page and receive nothing;
   - a used, expired and tampered link each show "invalid or expired".
4. OBS-39..41 are routed, and the OBS-39/40 operator values are set.
5. Only then is `ACCOUNT_RECOVERY_ENABLED=true` deployed, with explicit
   authorization.

## OBS-41 — enabled but critical email unavailable (SEV-2)

Every request is answered generically, but no email can be sent: people
cannot recover.
1. Run `platform:mail-status`: the reason names the cause
   (`email_disabled`, `sending_not_verified`, `sending_domain_not_reserved`,
   provider auth — see EMAIL-DELIVERABILITY.md).
2. Fix email, or set `ACCOUNT_RECOVERY_ENABLED=false` until it is fixed.
3. Urgent cases use `platform:user-password-reset` after out-of-band
   identity verification.

## OBS-39 — request spike (SEV-3)

Usually enumeration or a bot. The limits already cap the effect. Every
answer is generic, and at most 3 emails per address per hour go out.
- Check the request outcome mix (`lycenza_account_recovery_requests_total`
  by outcome) and the edge logs for the sources.
- Block abusive sources at the edge if needed. Never loosen the limits.

## OBS-40 — invalid reset submissions (SEV-3)

Guessing or replaying links. A secret has 256 bits and the selector limit
stops brute force, so the risk is noise, not compromise.
- Look for an old link forwarded or pasted somewhere public: which
  accounts (via `platform:account-recovery-status`) received links recently?
- If an account is suspected compromised, run `platform:user-password-reset`
  (every session ends) and review its audit trail.

## Suspected account takeover through recovery

1. Run `platform:account-recovery-status` for the account: were requests
   issued or consumed?
2. Review the account's `auth.password_recovered` and sign-in audit
   events.
3. Reset through the operator command after out-of-band verification; this
   ends every session, token and elevation.
4. If the mailbox itself was compromised, the owner must secure it first
   (recovery trusts the account's email address, ADR 0056 section 4).

## Privacy

- Logs, audit, metrics and the backlog never carry an email address, a
  selector, a secret, a link or a password.
- The request counters are keyed by an HMAC fingerprint, never the address.
- Failed sign-in audits record a fingerprint, never the typed address.
