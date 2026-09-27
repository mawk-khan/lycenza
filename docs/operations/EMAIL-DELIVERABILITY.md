# Runbook: production email & deliverability (ADR 0055) — evidence checklist

**Status: contract only.**
- The repository implementation is Phase 0O.9A, and it has **not
  started**.
- None of the commands named below exists yet.
- None of the evidence below exists: no provider account, API key,
  sending domain, DNS record, webhook secret or production send (rule 16).

Until 0O.9A and this evidence are both complete, **no production workflow
may rely on email**. That includes the Guardian account invitation and any
future account recovery (O14).

## What operators will configure (0O.9A)

| Setting | Meaning |
|---|---|
| `MAIL_PROVIDER` | The one adapter, or `none` (an explicit disabled mode). `fake` is local/testing only. |
| `MAIL_SENDING_DOMAIN` | The Lycenza-controlled sending domain, recommended as a dedicated subdomain of the platform domain. It is never a School domain. |
| `MAIL_FROM_NAME` | The platform display name ("Lycenza"). School mail shows `"<School> via <MAIL_FROM_NAME>"`. |
| Provider credential | Highly Sensitive, an `app_runtime` secret, available to web and workers only. |
| Provider-event webhook secret ring | Highly Sensitive; 1–2 entries, for rotation overlap. |
| `MAIL_SUPPRESSION_HMAC_KEY` | Highly Sensitive; a dedicated key, never `APP_KEY` or a lookup key. |
| `MAIL_SENDING_VERIFIED` | Operator attestation that the evidence below exists. Without it nothing is submitted. |
| Per-School and global budgets | `MAIL_SCHOOL_PER_MINUTE`, `MAIL_SCHOOL_PER_DAY`, `MAIL_SCHOOL_MAX_IN_FLIGHT`, `MAIL_GLOBAL_PER_MINUTE`. |

The provider's open and click tracking stays **disabled**.

## Deployment evidence checklist (ADR 0055 §17, §18, §23)

1. A provider account is selected under the ADR 0055 §5 requirements, and
   one adapter is configured. Legal/processor review is complete.
2. The sending domain is configured, and the provider has verified it.
3. **SPF:** exactly one record on the return-path subdomain, authorizing
   only the provider.
4. **DKIM:** the provider signs with an aligned `d=` domain at 2048 bits or
   more, and the selector verifies.
5. **DMARC:** `p=none` with aggregate reports during verification. Then
   `p=quarantine` after at least 14 days of 100 % aligned DKIM pass, which
   is the **minimum for readiness closeout**. The target is `p=reject`.
6. `platform:mail-verify-domain` (read-only) reports SPF, DKIM and DMARC
   passing.
7. Credentials and the webhook secret ring are in the managed secret
   store, and none appears in git, images, logs or the database.
8. The provider webhook is configured, and a signed test event is
   accepted and deduplicated.
9. Deliverability monitoring (the email metrics and alerts) is active.

## Non-production drill (ADR 0055 §18)

The drill uses provider sandbox addresses or fixtures only, never real
third parties. It demonstrates:
- a delivered message;
- a soft bounce, where the message stays deferred and the provider
  retries;
- a hard bounce, which causes suppression, so the next send to the
  address is `suppressed`;
- a complaint, which causes suppression with the right scope;
- a duplicated webhook (no second transition) and a replayed stale one
  (refused);
- the provider retrying an event after a 5xx;
- credential rotation (below).

Record the date, environment, message ids (never addresses) and outcomes.

## Credential rotation (ADR 0055 §19)

**Routine rotation:**
1. Create the new provider credential and webhook secret.
2. Add the secret to the ring.
3. Deploy web and workers.
4. Confirm successful submissions and verified events.
5. Remove the old credential and secret, then redeploy.

**On compromise:**
1. Revoke the credential at the provider immediately.
2. Install the replacement.
3. Restart web and workers.
4. Prove the old credential fails.
5. Review provider and application logs for the exposure window.

## Suppression release

`platform:mail-suppression-release` is platform-audited. Use it only after
confirming the mailbox problem is fixed. Never release suppression to
"get a critical email through". Critical mail respects suppression by
design.
