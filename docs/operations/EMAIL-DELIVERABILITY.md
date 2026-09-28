# Runbook: production email & deliverability (ADR 0055, OBS-31–38)

**Status.**
- **Repository implementation COMPLETE (Phase 0O.9A).**
- **Deployment evidence OUTSTANDING.** There is no provider account,
  credential, sending domain, DNS record, webhook exposure or production
  send yet (rule 16). The provider-neutral hardened SMTP adapter exists;
  a vendor's HTTPS API and event adapter is written when a vendor is
  selected.
- **O1 (ADR 0058):** email is mandatory for v1. O1 cannot close with
  `MAIL_PROVIDER=none` or fake delivery (evidence rows E17–E20).

Every business email goes through the durable email layer
(`App\Support\Email\OutboundEmailGateway`):
- a sealed, encrypted `email_messages` row;
- submission by `SubmitEmailMessageJob` on the `notifications` queue, after
  the business transaction commits;
- bounded retries, authenticated provider events and a global suppression
  list.

"Queued" and "submitted" never mean "delivered".

## What operators configure

| Setting | Meaning |
|---|---|
| `MAIL_PROVIDER` | `none` (default; email explicitly disabled — messages wait, nothing claims to be sent), `smtp` (hardened baseline), `fake` (local/testing only; production refuses it). |
| `MAIL_SENDING_DOMAIN` | The Lycenza-controlled sending domain (recommended: a dedicated subdomain of the platform domain). It must be reserved — under the platform's registrable domain or a `DOMAIN_RESERVED_SUFFIXES` entry — or nothing is submitted (`sending_domain_not_reserved`). Never a School's web domain. |
| `MAIL_FROM_ADDRESS` | Must be exactly `notifications@<MAIL_SENDING_DOMAIN>` (the closed catalog). |
| `MAIL_FROM_NAME` | The platform display name; School mail shows `"<School> via <name>"`. |
| `MAIL_MAILER` | Laravel's framework mailer, which business email never uses. Must not be `log`/`array` while email is enabled, and never `failover`/`roundrobin`/`sendmail`. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | The SMTP relay. The password is Highly Sensitive (`app_runtime`). |
| `MAIL_SMTP_TLS` | `required` (STARTTLS, never downgraded) or `implicit`. `none` only for Mailpit in local/testing. |
| `MAIL_SMTP_TIMEOUT_SECONDS` | 1–5 (connect and each response). |
| `MAIL_SUPPRESSION_HMAC_KEY` / `_KEY_ID` | Highly Sensitive; a dedicated key of at least 32 characters, plus a stable id. |
| `MAIL_SUPPRESSION_HMAC_PREVIOUS_KEY` / `_PREVIOUS_KEY_ID` | Only during a rotation (below). |
| `MAIL_PROVIDER_EVENTS`, `MAIL_PROVIDER_EVENT_SECRET` / `_PREVIOUS_SECRET` | The provider-event adapter and its 1–2 secret ring. `none` until a vendor adapter exists (the route answers 404). |
| `MAIL_SENDING_VERIFIED` | The operator's attestation that the evidence below exists. In production nothing is submitted while it is false (`sending_not_verified`). It records evidence; it proves nothing. |
| `MAIL_RETURN_PATH_DOMAIN`, `MAIL_DKIM_SELECTOR` | Where `platform:mail-verify-domain` looks. |
| `MAIL_PROVIDER_OPEN_TRACKING` / `_CLICK_TRACKING` | Must stay `false`; production refuses tracking. |
| `MAIL_SCHOOL_PER_MINUTE`, `MAIL_SCHOOL_PER_DAY`, `MAIL_SCHOOL_CRITICAL_PER_MINUTE`, `MAIL_SCHOOL_CRITICAL_PER_DAY`, `MAIL_SCHOOL_MAX_IN_FLIGHT`, `MAIL_GLOBAL_PER_MINUTE`, `MAIL_GLOBAL_CRITICAL_PER_MINUTE`, `MAIL_GLOBAL_MAX_IN_FLIGHT` | Fairness budgets. Over budget defers, never fails. |
| `MAIL_RETENTION_DAYS` | **[LEGAL REVIEW REQUIRED]** — unset deletes nothing. |

`ProductionConfigurationGuard` refuses to boot on any unsafe value and
prints codes only.

## Operator commands (console only)

- **`platform:mail-status`** — the `email` component: provider mode, event
  adapter, attestation, backlog by purpose, suppression counts by key-ring
  position, retention. Never a secret or an address.
- **`platform:mail-verify-domain`** — read-only DNS evidence:
  - SPF on the return path (exactly one record, `-all`/`~all`, aligned);
  - the DKIM selector key (RSA ≥ 2048 bits or Ed25519, `d=` = the sending
    domain);
  - the effective DMARC policy, and whether the domain is reserved.

  It exits non-zero until DNS is ready. It **cannot** prove the provider's
  own domain verification or the 14-day DMARC history.
- **`platform:mail-retry {school} {message}`** — makes one *waiting* message
  due now, restoring its attempt budget once. It resurrects nothing and
  every claim check still runs. Platform-audited.
- **`platform:mail-suppression-release --reason=…`** — releases an address
  (hidden prompt, never logged or audited). Platform-audited.
- **`platform:mail-suppression-rekey`** — after a key rotation, re-keys the
  suppressions the repository can reconstruct.
- **`platform:email-messages-redispatch`** (scheduler, every minute) —
  re-dispatches due or abandoned messages, expires outlived ones (suspended
  Schools included, so content is purged on time) and re-queues unapplied
  events.
- **`platform:email-prune`** (scheduler, daily) — applies
  `MAIL_RETENTION_DAYS`; with it unset, nothing is deleted.

## Deployment evidence checklist (ADR 0055 §17, §18, §23)

1. A provider is selected under the ADR 0055 §5 requirements, and the legal
   and processor review is complete. Its adapter is written (API and event
   feed), or the SMTP baseline is used knowingly (no events).
   **For O1 (ADR 0058 §4.11), the no-events baseline is not enough:** the
   provider-specific event adapter is a mandatory repository tail. Its
   sending adapter stays SMTP unless the provider needs HTTPS.
2. The sending domain is configured and reserved; the provider has verified
   it.
3. SPF, DKIM and DMARC pass `platform:mail-verify-domain`.
   - DMARC starts at `p=none` with aggregate reports.
   - Move to `p=quarantine` after at least **14 days of 100 % aligned DKIM
     pass**. This is the **minimum for readiness**.
   - The target is `p=reject`.
4. Credentials, the suppression key ring and the event secret ring are in
   the managed secret store; nothing is in git, images, logs or the
   database.
5. `MAIL_SENDING_VERIFIED=true` is set only once 1–4 hold.
6. The provider webhook is configured and a signed test event is accepted
   and deduplicated.
7. Monitoring is active (OBS-31..38 routed), and operator values are set
   for OBS-33, 36, 37 and 38.
8. The drill below passes.

## Non-production drill

Use provider sandbox addresses or fixtures only, never real third parties.
Walk through:
- one delivered message;
- a soft bounce (the message stays `deferred`);
- a hard bounce, which suppresses the address for all mail (the next send is
  `suppressed`);
- a complaint, which suppresses standard mail, or all mail when the
  complaint was about critical mail;
- a duplicated webhook (no second change) and a stale one (401);
- the provider retrying an event after a 5xx;
- a credential rotation.

Record dates, the environment, message ids (never addresses) and outcomes.
DDEV rehearses the same flow with Mailpit and the fake event feed
(`docs/development/DDEV-DEMO-REVIEW.md`).

## Rotation

- **Provider credential.** Create the new credential at the provider; deploy
  it to web and workers; confirm submissions succeed; revoke the old one.
- **Compromised provider credential.** Revoke at once, install the
  replacement, restart web and workers, prove the old one fails, and review
  the exposure window.
- **Event secret.** Set the new secret as `MAIL_PROVIDER_EVENT_SECRET` and
  the old as `_PREVIOUS_SECRET`; deploy; switch the provider; confirm
  verified events; remove the previous secret.
- **Suppression HMAC key.**
  1. Move the current key and id to `_PREVIOUS_KEY`/`_PREVIOUS_KEY_ID`.
  2. Set a new key with a **new** id and deploy.
  3. Run `platform:mail-suppression-rekey`.
  4. Keep the previous key until `platform:mail-status` shows no suppression
     left under it. Rows that cannot be re-keyed are re-keyed when the
     address is next seen, or released.
  5. Never drop the previous key while rows depend on it: a replaced key
     with no ring would silently un-suppress every address.

## OBS-31 / OBS-32 — email waiting too long (SEV-2 critical, SEV-3 standard)

1. Run `platform:mail-status`. The reason names the cause:
   - `disabled` — `MAIL_PROVIDER=none`: invitations wait by design. Enable
     email, or stop issuing invitations.
   - `sending_not_verified`, `sending_domain_missing`,
     `sending_domain_not_reserved` — configuration.
   - `provider_auth_failure` — see OBS-34.
   - `backlog` — workers or the provider are slow.
2. Check the `notifications` worker (OBS-08/09) and the
   `email-messages-redispatch` heartbeat.
3. Once the provider is back, waiting messages resume on their own. Use
   `platform:mail-retry` for an urgent one.

## OBS-34 — provider refusing credentials or TLS (SEV-2)

Every submission pauses for 15 minutes after an authentication or TLS
refusal. Messages wait durably; nothing is lost and nothing retries in a
loop.
- Fix the credential (rotation above) or the relay's TLS.
- The pause ends by itself.

## OBS-33 / OBS-36 / OBS-37 — failure ratio, hard bounces, complaints

- Check recent attempt failure codes (never addresses), then provider status
  and list quality: which School, which purpose.
- Complaints threaten the shared sending reputation: review what was sent.
- **Suppression release is never the fix for a spike.**

## OBS-35 — no provider events while mail is submitted (SEV-3)

The event feed is stale:
- check the provider's webhook configuration and the endpoint's rejects
  (OBS-38);
- check the event secret ring.

## OBS-38 — webhook authentication failures (SEV-3)

Usually a rotated or mis-set event secret, or internet noise. The endpoint
never processes an unauthenticated body.

## Account recovery depends on this layer (ADR 0056)

Self-service password recovery (Phase 0O.10A) sends `account_recovery`
critical email only while `EmailProviderResolver::criticalEmailAvailable()`
is true.
- Its messages expire with the 30-minute credential.
- They respect suppression: a suppressed address simply receives nothing,
  and the browser never learns it.
- A post-reset `security_notice` follows.
- Both are identity-level (no School). They use a separate `platform`
  budget bucket and a sanctioned platform-email RLS scope.
- Operating recovery (enablement, OBS-39..41, the operator reset) is in
  [ACCOUNT-RECOVERY](ACCOUNT-RECOVERY.md). `platform:mail-retry` and
  `platform:mail-fake-event` take the School argument `platform` for
  identity-level mail; the redispatch sweep covers it automatically.

## Provider-side suppression

A release here does not clear the provider's own suppression list. Clear
that in the provider console as a separate, deliberate step.
