# ADR 0060: Transactional Email Provider Selection & Integration Contract

- Status: Accepted as the **engineering selection** (Phase 0O.13,
  documentation only). **Processor/legal review OUTSTANDING.** ADR 0058 row
  E17 moves to `LEGAL_REVIEW_REQUIRED`, and E18 must not start until that
  review is recorded (§22).
- Date: 2026-09-29
- Baseline: `origin/main` `4daa955` (executable checkpoint `e52c4c4`)
- Resolves: the provider-selection half of ADR 0058 **E17**; freezes the
  **E18** implementation contract.
- Amends, by note (no rewrite): ADR 0055 §11.2 (per-adapter event bounds,
  §12 below). ADR 0055 is otherwise applied, not changed.
- Related: ADR 0050 (secrets), ADR 0052 (O16), ADR 0055 (email contract),
  ADR 0056 (recovery), ADR 0058 (O1).

Nothing here creates an account, credential, DNS record or webhook, and no
email is sent (rule 16).

## 1. Context — the seams the provider must fit (audited at `4daa955`)

- **Sending boundary.** `EmailProviderAdapter` (`name()`,
  `supportsIdempotencyKey()`, `submit()`). `EmailProviderResolver::PROVIDERS`
  is `none`, `fake`, `smtp`; `config/email.php` defaults to `none`.
- **SMTP provider id.** `SmtpEmailProvider` returns Symfony's
  `SentMessage::getMessageId()`. Symfony takes the id from a final
  `250 [host] Ok[:] [queued as |id=]<id>` reply. Otherwise it keeps
  Lycenza's own RFC `Message-ID`, which is not a provider id.
- **Event boundary.** `EmailEventAdapter` has `provider()`,
  `authenticate(Request)` and `normalize(array)`. `EmailEventAdapterResolver`
  allows `none` and `fake` only (`fake` in local/testing only). The route
  `POST /api/integrations/email-provider/events` answers 404 while the
  adapter is `none`.
- **Controller.** It checks body bound → `authenticate()` →
  `Content-Type: application/json` (else 415) → JSON depth 32 → `normalize()`
  → event count → ingest → 202.
  - Bounds: `max_body_bytes` 262,144 and `max_events` 100 (global).
  - Timestamp tolerance: 300 s.
  - Secret ring: `MAIL_PROVIDER_EVENT_SECRET` + `_PREVIOUS_SECRET`, each at
    least 32 characters (production guard).
- **Linkage.** An event finds its message **only** through
  `email_provider_references` by (`provider`, `provider_message_id`)
  (`EmailEventApplier`). The event adapter's `provider()` must equal the
  sending adapter's `name()`. The event's message id must equal the id
  recorded at acceptance.
- **Event storage.**
  - `email_events.event_key` is `varchar(128)`, unique with `provider`.
  - `provider_message_id` is `varchar(255)`.
  - Bounce sub-classes are closed: `mailbox_unknown`, `mailbox_full`,
    `domain_invalid`, `policy_rejected`, `content_rejected`,
    `provider_suppressed`, `other`.
  - `Rejected` + `provider_suppressed` suppresses all mail.
  - `BouncePermanent` suppresses all mail.
  - `Complaint` suppresses standard or all mail (by message kind).

## 2. Method and evidence standard

- **Sources.** The candidates were researched on 2026-09-29 against the
  providers' **official** documentation, legal pages and trust pages only.
  Third-party claims were discarded.
- **NOT VERIFIED items.** Anything the official pages did not state is
  recorded as NOT VERIFIED. When it matters to the design, it becomes a
  **sandbox verification gate** of E18 (§21).
- **Criteria.** No candidate was chosen on popularity or price. Cost is
  secondary (§6).
- **Jurisdiction.** Lycenza's legal entity and jurisdiction are **not**
  inferred here. Where residency or transfer matters, it is recorded as an
  owner/legal dependency (§20).

## 3. Candidates and hard requirements

Evaluated: **Amazon SES (v2)**, **Postmark**, **Twilio SendGrid**,
**Mailgun**. No other provider was added: none was needed to find a
candidate that passes.

| Hard requirement | Amazon SES | Postmark | Twilio SendGrid | Mailgun |
|---|---|---|---|---|
| A. HTTPS API and/or SMTP with TLS; send-scoped credential; verified domain | Pass (SigV4 API; SMTP TLS required on every port; IAM `ses:SendEmail`/`SendRawEmail` with identity and From conditions) | Pass (server/stream tokens; SMTP TLS "available", not required) | Pass (restricted key with Mail Send only; SMTP 587 TLS / 465 SSL) | Pass (Domain Sending Keys limited to `/messages` for one domain) |
| B. 2048-bit DKIM; custom return path; DMARC alignment | Pass (Easy DKIM default RSA 2048; custom MAIL FROM) | **FAIL**: "Postmark uses 1024-bit DKIM keys" | Pass (automated security creates **new** keys at 2048 bit; custom return-path label) | Pass (`dkim_key_size` 2048; `mailfrom_host`) |
| C. Delivered, deferred, transient/permanent bounce, complaint, rejection; message id; event id | Pass (SNS `MessageId` reused on retry; `feedbackId` for bounce/complaint) | Partial (no deferred webhook; Delivery has no event id) | Pass (`sg_event_id` documented "for deduplication"; `X-Message-Id`) | Pass, weaker (event `id` "unique within a day") |
| D. Cryptographically authenticated webhook, not IP-only; replay material; rotation | Pass with caveat (SNS RSA signature; the `SigningCertURL` is in the request body) | **FAIL**: "Postmark does not currently support HMAC webhook signature verification" (basic auth / IP allowlist only) | Pass (ECDSA/SHA-256 over timestamp + raw body; public key from configuration) | Pass, weaker (HMAC-SHA256 over timestamp + token only — **the body is not signed**) |
| E. Provider suppression visible and operable | Pass | Pass | Pass | Pass |
| F. Rotation; documented retries; tracking can be fully off | Pass (2 IAM keys; SNS retries ≤ 1 h max; tracking only with a config-set destination) | Pass | Pass (≤ 100 keys; 24 h retries; tracking off per account and per message) | Pass (8 h retries; tracking off by default) |
| G. DPA, subprocessors, security documentation | Pass | Pass (US-only hosting; message content retained ≥ 7 days, cannot be disabled; no own SOC audit) | Pass | Pass |

**Rejected: Postmark.** It fails B (1024-bit DKIM) and D (no signed
webhooks). ADR 0055 §11.2 would tolerate a shared secret "where that is the
vendor's only mechanism". The Phase 0O.13 hard requirement is a
cryptographic signature, and 2048-bit DKIM is an ADR 0055 §5 requirement in
any case.

Sources (official, 2026-09-29):
- SES: `docs.aws.amazon.com/ses/latest/dg/` (`smtp-connect`,
  `control-user-access`, `send-email-authentication-dkim-easy`, `mail-from`,
  `event-publishing-retrieving-sns-contents`,
  `sending-email-suppression-list`, `faqs-metrics`);
  `docs.aws.amazon.com/sns/latest/dg/` (`sns-verify-signature-of-message*`,
  `SendMessageToHttp.prepare`, `sns-message-delivery-retries`).
- Postmark: `postmarkapp.com/support/article/1091-how-do-i-set-up-dkim-for-postmark`,
  `postmarkapp.com/developer/webhooks/webhooks-overview`,
  `postmarkapp.com/eu-privacy`.
- SendGrid:
  - `twilio.com/docs/sendgrid/api-reference/mail-send/mail-send`;
  - `…/glossary/x-message-id`, `…/glossary/message-id`;
  - `…/for-developers/tracking-events/event`;
  - `…/tracking-events/getting-started-event-webhook-security-features`;
  - `…/tracking-events/twilio-sendgrid-event-webhook-overview`;
  - `…/tracking-events/getting-started-event-webhook`;
  - `…/ui/account-and-settings/api-keys`;
  - `…/how-to-set-up-domain-authentication`, `…/dkim-records`;
  - `…/migrating-to-2048-bit-domainkeys-identified-mail-dkim`;
  - `…/ui/sending-email/index-suppressions`, `…/ui/sending-email/dmarc`;
  - `…/api-reference/settings-tracking/*`, `…/data-residency`;
  - `twilio.com/en-us/blog/insights/2048-bit-dkim-keys`;
  - `twilio.com/en-us/legal/data-protection-addendum`, `…/legal/sub-processors`.
- Mailgun: `documentation.mailgun.com/docs/mailgun/user-manual/webhooks/`
  (`securing-webhooks`, `webhook-retries`, `webhook-payloads`),
  `…/events/event-structure`, `…/domains/dkim_security`,
  `…/tracking-messages/tracking-messages`, `mailgun.com/security`, the Sinch
  DPA and sub-processor pages.

## 4. Comparison of the passing candidates

| Criterion | SendGrid | Mailgun | SES |
|---|---|---|---|
| Fit to ADR 0055 §11.2 ("HMAC or asymmetric", "keys only from configuration") | **Best**: asymmetric; operator-configured public key; timestamp and **body** signed | HMAC key from configuration, but **payload not signed** (body integrity rests on TLS only); replay defence needs a token cache | RSA, but the certificate location is **inside the untrusted body**. Compliance needs a pinned, pre-provisioned certificate that SNS may rotate |
| Dedupe identity | `sg_event_id`, documented for deduplication | `id` unique only "within a day" | SNS `MessageId` (stable on retry) |
| Provider retry window | **24 h** | 8 h | 3 retries by default, ≤ 1 h maximum |
| Bounce / complaint fidelity | `deferred`, `bounce`/`blocked`, `dropped` with reason, `spamreport` | `temporary_fail`, `permanent_fail`, `complained` | `DeliveryDelay`, Bounce Permanent/Transient/Undetermined, Complaint |
| Fit to the existing controller | JSON array, `application/json`; **batches up to ~768 KB exceed today's 256 KiB / 100-event bound (§12)** | One JSON event per POST | SNS posts `text/plain` (controller change) plus a subscription-confirmation handshake |
| Domain controls | CNAME-delegated DKIM (provider rotates) and return path | CNAME-delegated DKIM (120-day rotation) | CNAME Easy DKIM; custom MAIL FROM |
| Implementation size | One HTTPS sender plus one event adapter; no new package (Laravel HTTP client, PHP `openssl_verify`) | Similar | Largest: SNS signature and certificate handling, handshake, content-type change, IAM |
| Residency options | Global; EU via an EU subuser (upper-tier plan) | US or EU region | Any AWS Region |
| Provider content retention | Does "not retain the contents of emails" (metadata kept) | NOT VERIFIED | NOT VERIFIED |

**Cost** was not a deciding factor. The plan tier matters only through EU
residency (§20).

## 5. Decision — Twilio SendGrid

**Twilio SendGrid is the selected transactional email provider for
production v1.** It is recorded in code as provider name **`sendgrid`**.

The deciding reasons, in contract order:
1. **Webhook security.** Its ECDSA signature over timestamp + raw body,
   verified against an operator-configured public key, is the closest fit
   to ADR 0055 §11.2. No caller-supplied key location exists.
2. **Deduplication.** A documented, unique per-event id.
3. **Retry window.** The longest documented webhook retry window (24 h).
   This makes a key rotation or short outage lossless without extra
   machinery.
4. **Domain authentication.** Provider-hosted, CNAME-delegated DKIM
   (2048-bit for new keys) and a custom return path, with no Lycenza-held
   private key.
5. **Least-privilege sending.** A restricted Mail-Send-only key.
6. **Content retention.** It documents that it does not retain message
   content.

**Fallback.** If the legal review (§20) rejects SendGrid, for example over
a residency requirement it cannot meet, the documented fallback is
**Amazon SES**. SES needs a new ADR 0060 amendment covering SNS
certificate pinning and the handshake. It is never a silent swap.

## 6. Sending transport — SendGrid HTTPS v3 Mail Send API

**Decision: a new HTTPS API sending adapter.** The existing SMTP adapter is
**not** the production transport for SendGrid.

The API brings material, contract-required benefits:
- **Linkage.** The `202` response carries `X-Message-Id`, which SendGrid
  documents as the value to "track events that the Event Webhook posts".
  The SMTP reply format is **NOT VERIFIED**, and §1 requires exact linkage.
- **Tracking enforced per message.** Every request sets
  `tracking_settings` (click, open, subscription and Google Analytics all
  `enable: false`). The account default for transactional tracking is NOT
  VERIFIED, and SMTP cannot enforce this per message as reliably.
- **Structured failure classification.** HTTP statuses map directly onto
  ADR 0055 §10.

Only **one** sending path is written; there is no SMTP/API symmetry. The
hardened `smtp` adapter stays in the repository unchanged. Before E18 it may
be used only for non-production observation traffic (§23).

**Request shape (E18):**
- `POST https://<host>/v3/mail/send`, where `<host>` is from a **closed
  set**: `api.sendgrid.com` (global) or `api.eu.sendgrid.com` (EU subuser).
  It is chosen by configuration, never a free URL.
- `Authorization: Bearer <key>`.
- Timeouts: connect ≤ 5 s, request ≤ 15 s.
- Body:
  - exactly one personalization with one `to` recipient;
  - the catalog `from` (address + sanitized display name);
  - `subject`;
  - `content` (text/plain first, optional HTML);
  - `headers` (`Message-ID`, `Auto-Submitted` on critical classes);
  - Communications attachments (base64) when present;
  - `tracking_settings` all disabled.
- **Never set:** `mail_settings.bypass_*`, `sandbox_mode` in production,
  `custom_args`, `categories`, a School id, `reply_to`, `cc` or `bcc`.
- **Classification (ADR 0055 §10):**
  - `202` → accepted;
  - `429` / `5xx` / timeout / TLS or network error → transient;
  - `401` / `403` → authentication failure (transient + `ProviderAuthPause`
    + OBS-34);
  - `400` / `413` / other `4xx` → permanent.
- **No new package.** Laravel's HTTP client and PHP OpenSSL are enough
  (rule 2).

## 7. Idempotency

The SendGrid Mail Send API documents **no idempotency key**.
`supportsIdempotencyKey()` returns `false`, and the `lycenza-email-<id>` key
is not sent. ADR 0055 §9.3's **at-least-once** submission stands: a crash
after `202` but before recording the acceptance can send a duplicate.
Exactly-once is never claimed.

## 8. Credentials

- **Sending.** One **restricted API key** with **Mail Send** only (Custom
  Access). No other scope: no suppression, webhook, stats or settings
  access.
  - Highly Sensitive, `app_runtime`, from the managed secret store (E08).
  - Injected **only** into the worker role that runs the `notifications`
    queue.
  - Never in git, images, the database, logs, audit or errors.
  - Proposed setting `MAIL_SENDGRID_API_KEY`; region
    `MAIL_SENDGRID_REGION` ∈ `global`, `eu`. E18 fixes the final names.
- **Event verification.** `MAIL_PROVIDER_EVENT_SECRET` /
  `_PREVIOUS_SECRET` hold SendGrid's **public** verification key(s)
  (base64 DER). They are injected only into the web role.
  - They are not secret in themselves, but stay in the managed store for
    uniform custody.
  - The guard must require each entry to parse as an EC public key (§21).
- **Operator credentials.** Suppression inspection, webhook and domain
  management use a **separate operator login or key**. It never reaches
  any runtime process.
- **Rotation (overlap).** SendGrid allows up to 100 keys at once:
  1. create a new restricted key;
  2. store it;
  3. deploy;
  4. observe accepted submissions;
  5. delete the old key;
  6. prove the old key fails.
- **Emergency revocation.**
  1. Delete the key at SendGrid (it "rejects any subsequent API calls" at
     once).
  2. Install the replacement.
  3. Restart the workers.
  4. Confirm OBS-34 clears.
  5. Review logs for the exposure window (ADR 0055 §19).

## 9. Sending domain, DKIM and return path

- **Domain.** `MAIL_SENDING_DOMAIN` is a **Lycenza-controlled** subdomain of
  the platform's registrable domain, conceptually `notify.<platform
  domain>`, set in configuration. It is never a School web (O9) domain, and
  the reserved-domain guard already enforces that.
- **From:** `notifications@<MAIL_SENDING_DOMAIN>` (ADR 0055 §8.2).
- **Provider verification:** SendGrid **domain authentication** of
  `MAIL_SENDING_DOMAIN` with **automated security** (CNAME delegation).
- **DKIM:**
  - Provider-hosted; Lycenza never holds the private key.
  - Use a **new custom DKIM selector**, never a reused default `s1`/`s2`
    key. SendGrid states that newly created automated-security keys are
    2048-bit, while reused `s1` keys and manual-security keys can stay
    1024-bit.
  - SendGrid rotates keys "when necessary" behind the CNAMEs.
  - `d=` is `MAIL_SENDING_DOMAIN`, strictly aligned with From.
  - **E19 gate:** the published key's modulus must be 2048-bit, or stop.
- **Return path / MAIL FROM:** automated security's return-path subdomain
  with a **custom label** (for example `bounce.<MAIL_SENDING_DOMAIN>`),
  CNAME to SendGrid.
  - SPF evaluates there and aligns in **relaxed** mode.
  - No School or customer return path ever exists.
- **DMARC:** `_dmarc.<MAIL_SENDING_DOMAIN>`, operator-managed.
  - Staged `p=none` → `p=quarantine` → `p=reject` (ADR 0055 §17).
  - `adkim`/`aspf` relaxed is acceptable.
  - The organizational domain's own policy must not contradict it: check
    `sp=` before relying on the subdomain record.
- **Not configured:** SendGrid link branding (tracking is off), and a
  dedicated IP (optional; only the EU subuser needs one).

## 10. Tracking — off

- **Account-wide** (operator, before any send): click tracking, open
  tracking, subscription tracking and Google Analytics all **disabled**
  (`/v3/tracking_settings/*`). These settings are E19 evidence.
- **Per message** (E18, every request): `tracking_settings` with all four
  `enable: false` (plus `click_tracking.enable_text: false`).
- **Never used:** Marketing Campaigns (which forces open tracking).
- **Guard.** `MAIL_PROVIDER_OPEN_TRACKING` and
  `MAIL_PROVIDER_CLICK_TRACKING` (`config/email.php`, `tracking.*`; ADR
  0055 §13 calls them `MAIL_PROVIDER_TRACKING`) must stay `false`.
- **Rejection rule.** If the E18 sandbox gate (§21) shows rewritten links
  or a pixel despite these settings, SendGrid is **rejected** and the SES
  fallback is taken.

## 11. Webhook authentication, replay and timestamp

- **Scheme:** SendGrid Signed Event Webhook.
  - Headers `X-Twilio-Email-Event-Webhook-Signature` (base64 ECDSA
    signature) and `X-Twilio-Email-Event-Webhook-Timestamp`.
  - The signed input is **the timestamp string concatenated with the raw
    request body bytes**; verified with SHA-256 (`openssl_verify`).
- **Trust anchor.**
  - The verification public key is shown to the operator in the SendGrid
    console/API once signing is enabled.
  - The operator copies it over an authenticated session into the secret
    store.
  - It is **never fetched at runtime**, and no URL, `jku` or key id from
    the request is honoured.
- **Order** (the controller's existing order):
  1. body bound;
  2. both headers present;
  3. timestamp is an integer within **±300 s** of now;
  4. the signature verifies under **any** ring key (at most 2).
  - Only then: Content-Type, JSON parse and normalization.
- **Failure.** Any failure → the existing uniform empty **401**, metric
  `unauthenticated`, OBS-38. Nothing is parsed or stored.
- **Replay.** A replay inside the 300 s window re-delivers identical events.
  They are deduplicated by event key (§14), with no second transition.
- **Not used:** OAuth 2.0 webhook authentication (not needed alongside
  signing). No IP allowlisting ("IPs often change"; never a control).

## 12. Event bounds — amendment to ADR 0055 §11.2

SendGrid posts events as one JSON array, "within approximately 30 seconds or
when the batch size reaches 768 kilobytes". Today's global bounds (256 KiB,
100 events) would reject legitimate batches. Those would be retried for
24 h and then **lost**.

**Amendment.** The bounds become **per adapter**, declared by the adapter:
- **`sendgrid`:** body ≤ **1 MiB** (1,048,576 bytes), ≤ **5,000** events per
  request, JSON depth ≤ 32.
- **All other adapters:** the existing 256 KiB and 100 events.
- **Unchanged:** the rate limit (600/min per source) and authentication
  before parsing.

**E18 must prove** that a maximum-size signed batch is authenticated,
deduplicated and queued within the request timeout. Edge and PHP request
caps must allow 1 MiB (E05/E09 deployment configuration).

**One bad event never rejects the batch.** Rejecting the batch would lose
every event in it. An array element without `sg_event_id` or
`sg_message_id`, or with an unknown `event`, is normalized as `Ignored`
(fingerprint key, §14) and counted. Only a non-array body is `400`.

## 13. Event normalization map

| SendGrid event (`event`) | Lycenza `EmailEventType` | Bounce sub-class / notes |
|---|---|---|
| `processed` | `Ignored` | Acceptance is already recorded synchronously from `202` |
| `deferred` | `Deferred` | Temporary; SendGrid keeps retrying |
| `delivered` | `Delivered` | Transport evidence only (ADR 0055 §9.2) |
| `bounce`, `type` = `bounce` (or absent) | `BouncePermanent` | From `bounce_classification` (field NOT VERIFIED, gate §21): Invalid Address → `mailbox_unknown`; Mailbox Unavailable → `mailbox_full`; Content → `content_rejected`; Reputation / Frequency or Volume Too High → `policy_rejected`; anything else or absent → `other`. Suppresses all mail, consistent with SendGrid adding it to its bounce list |
| `bounce`, `type` = `blocked` | `Rejected` | `policy_rejected`. **Ambiguous event:** the receiving server refused this message (often reputation or policy). The address is not proven invalid, and SendGrid does not suppress blocks. So: terminal `failed` (`provider_rejected`) and **no** Lycenza suppression. Not `BounceTransient`, because SendGrid does not retry a block |
| `dropped`, reason = recipient on a SendGrid suppression list (bounced, spam-reporting, invalid or unsubscribed address) | `Rejected` | `provider_suppressed` → Lycenza suppression, scope `all` (ADR 0055 §11.1) |
| `dropped`, any other reason | `Rejected` | `other` |
| `spamreport` | `Complaint` | Scope by message kind (ADR 0055 §12.2) |
| `open`, `click` | `Ignored` | Must never occur (tracking off). E18 logs a closed code `tracking_event_received` as an operator signal |
| `unsubscribe`, `group_unsubscribe`, `group_resubscribe` | `Ignored` | Subscription tracking is off; v1 has no `List-Unsubscribe`; never a preference change |
| account status change, any unknown value | `Ignored` | — |

No new core event type is needed. The exact `dropped` reason strings come
from sandbox fixtures (§21).

- **`occurred_at`:** the event's `timestamp` (Unix seconds).
- **Vendor strings stay in the adapter** (ADR 0055 §11.1).

## 14. Event identity (deduplication)

- **Source:** SendGrid's `sg_event_id`, documented as unique and "for
  deduplication".
- **Key:** `event_key = "sg:" + lowercase hex sha256(sg_event_id)`. It is 67
  characters, fits the existing `varchar(128)` (SendGrid says the id "can
  exceed 100 characters") and needs no migration. It is deterministic, so
  a retried or duplicated event maps to the same key and the unique
  (`provider`, `event_key`) index makes it a no-op.
- **Fallback, only for a malformed element without `sg_event_id`:** ADR
  0055 §11.3's fingerprint (`NormalizedEmailEvent::fingerprint`). Its type
  is always `Ignored`.
- **Never:** a request timestamp alone, a delivery timestamp alone, or a
  local random UUID.

## 15. Provider message id and linkage

- **At submission.** The `X-Message-Id` response header of `202` is the
  provider message id. It goes into `SubmissionResult::accepted($id)`, then
  `email_messages.provider_message_id` and `email_provider_references`
  (`provider = 'sendgrid'`).
  - A `202` **without** the header is still accepted; resending would
    duplicate the email. It is recorded with no provider id and a closed log
    code `provider_message_id_missing`, and it can then receive no events.
- **In events.** `sg_message_id` has the form `<X-Message-Id>.<filter
  suffix>` (for example `XBg2anf2TqCy6WXKQFhieQ.filter0905p1…`). The adapter
  uses the part **before the first `.`** as the provider message id.
  - This prefix rule matches SendGrid's own example and its
    `X-Message-Id` guidance, but is **not stated as a rule** — sandbox gate
    §21.
- **Chain:** submission attempt → `202` + `X-Message-Id` → stored
  reference (`sendgrid`, id) → event `sg_message_id` prefix → the same
  reference → the stored `school_id` (never the payload).
- **Naming.** Both adapters use the name `sendgrid`.
- **Not the logical id.** The provider id is never Lycenza's logical id
  (the UUIDv7) or its RFC `Message-ID`. Whether SendGrid preserves Lycenza's
  `Message-ID` header is NOT VERIFIED, and linkage never depends on it.

## 16. Webhook retries and ordering

- **Retries.** SendGrid retries any non-2xx "at increasing intervals for up
  to 24 hours after the event occurs".
  - Lycenza answers `202` once events are deduplicated and queued, and 401,
    413, 415 or 400 otherwise.
  - A 401 during a key rotation is safe: the batch retries until the new
    key is deployed.
- **Duplicates.** SendGrid documents that duplicates are possible. They are
  handled by §14.
- **Ordering.** Not guaranteed or documented. ADR 0055 §9.2's monotonic
  graph makes late and out-of-order events safe. Provider ordering is never
  required.

## 17. Suppression — two independent lists

- **At SendGrid:**
  - hard bounces, spam reports, invalid addresses and unsubscribes are
    suppressed automatically, and later sends to them are `dropped`;
  - blocks are **not** suppressed.
- **Lycenza's `email_suppressions` stays the authority** for Lycenza's
  decisions (ADR 0055 §12.3; alternative 5 rejected reliance on provider
  suppression). It is written from normalized events only:
  - `BouncePermanent` → `hard_bounce`/`all`;
  - `Complaint` → `complaint`;
  - `dropped` for a suppressed address → `provider_suppressed`/`all`.
  - Provider lists are never imported or synchronized into Lycenza's
    database.
- **Operator inspection.** SendGrid's lists are inspected in the SendGrid
  console or API with the **operator** credential (§8). The runtime key
  cannot read them.
- **Release needs both sides.**
  1. `platform:mail-suppression-release` (platform-audited).
  2. The operator **also** removes the address from the matching SendGrid
     list. Otherwise the next send is `dropped` and re-suppressed.

  The E18 runbook records both steps.
- **Never** a `bypass_*` mail setting, including for critical mail (ADR
  0055 §12.4).

## 18. Webhook key rotation

- **Overlap model.** The Lycenza ring holds current + previous public keys.
  A request verifies under either.
- **Preferred: two webhooks.** Used when the SendGrid plan allows a second
  Event Webhook.
  1. Create a second webhook to the same URL, with signing enabled.
  2. Install its public key as current, the old one as previous, and
     deploy.
  3. Both webhooks now deliver; duplicates collapse by `sg_event_id`.
  4. Delete the old webhook.
  5. Drop the previous key.
- **Otherwise: regenerate in place.**
  - Regenerating the key pair (disable/enable signing) makes deliveries
    fail 401 until the new public key is installed. The 24 h retry window
    makes this lossless if the new key is deployed promptly.
  - E20 must demonstrate the chosen procedure loses no event.
- **Trust.** The trust anchor is always operator-installed configuration,
  never a URL.

## 19. Data sent to SendGrid and classification

| Data | Sent? | Class (DATA-CLASSIFICATION / ADR 0055 §21) |
|---|---|---|
| Recipient address (one) | Yes | Sensitive (personal data) |
| From address (`notifications@…`) and display name (`"<School> via <platform>"`) | Yes | The School name reaches the processor; Internal |
| Subject, text body, optional HTML body | Yes | The class of the business content. Invitation, activation and recovery links are **Highly Sensitive** until used or expired |
| Communications attachments (private storage) | Yes, when a School communication has them; never on critical classes | The class of the attachment (often Sensitive) |
| Headers `Message-ID` (UUIDv7 at the sending domain), `Date`, `MIME-Version`, `Auto-Submitted` | Yes | Internal |
| Restricted API key (authentication) | Yes (to SendGrid only) | Highly Sensitive |
| School id, user ids, tenant data, `custom_args`, categories, tracking identifiers | **No** | — |

- **Generated and kept by SendGrid:** delivery metadata and events. SendGrid
  states it does not retain email contents, but it keeps metadata.
  Retention of that metadata is NOT VERIFIED and is for legal review (§20,
  E21).
- **Unavoidable in-transit exposure.** Critical links in the body reach the
  processor over TLS. The short link lifetimes (ADR 0055 §9.4, ADR 0056,
  ADR 0059) bound the exposure. Legal review must accept this.

## 20. Processor, legal and residency — review OUTSTANDING

**What SendGrid documents:**
- a **DPA** (updated 2026-04-09; SendGrid content deleted from backups one
  year after termination);
- a **sub-processor list** (updated September 2026);
- **EU data residency** only through an EU subuser on an upper-tier plan,
  with a dedicated EU IP. Some features are unavailable, and data may leave
  the EU for support or troubleshooting;
- **no retention of email contents**;
- a Twilio Trust Center (SOC 2 Type II / ISO 27001 documents). Whether they
  scope SendGrid specifically is NOT VERIFIED.

**Engineering makes no legal decision.** The qualified legal/compliance
review must decide:
1. processor acceptability and execution of the DPA;
2. the sub-processor posture;
3. international transfer and residency for Lycenza's actual jurisdiction
   and customers, which are **not inferred here**. That includes whether
   global, EU or another region is required. If a region SendGrid cannot
   offer is required, the selection falls back to SES (§5).
4. provider-side retention of metadata, against Lycenza's own E21 retention
   decision.

**E17 is complete only when** this review is recorded, as a dated
amendment to this ADR, naming the reviewer role and outcome. Until then E17
is `LEGAL_REVIEW_REQUIRED` and **E18 does not start**.

## 21. Sandbox verification gates (carried into E18)

These are NOT VERIFIED on official pages. Each must be proven in the
non-production SendGrid account, with fixtures committed without secrets or
addresses, **before** E18 merges. A failed gate stops E18 for an amendment;
it is never coded around.

1. **Timestamps on retries.** Each (re)delivery is freshly signed with a
   current timestamp. If a retry reuses a stale timestamp, the 300 s
   tolerance would reject valid retries.
2. **Curve.** The signature curve is P-256 and verifies with
   `openssl_verify(..., OPENSSL_ALGO_SHA256)`.
3. **Linkage.** `sg_message_id`'s prefix equals the `X-Message-Id` from
   `202`.
4. **Event id length.** The maximum `sg_event_id` length is observed. It is
   hashed anyway.
5. **Tracking.** With the §10 settings, no link is rewritten and no pixel
   is added (text and HTML).
6. **Payload fields.** The `dropped` `reason` strings and the
   `bounce_classification` values match §13.
7. **Batches.** The maximum observed batch size stays within §12.
8. **`Message-ID`.** Whether Lycenza's header is preserved. This is
   informational only.
9. **Attachments.** The provider message-size limit is at least the
   `email_max_total_size_mb` cap (ADR 0055 §13).

## 22. E18 — exact implementation scope

**Checkpoint: Phase 0O.13A — SendGrid Transactional Email Adapter.** It
starts only after §20's legal approval is recorded.

1. **`SendGridEmailProvider implements EmailProviderAdapter`:**
   - `name()` returns `sendgrid`;
   - `supportsIdempotencyKey()` returns `false`;
   - the HTTPS `submit` of §6 (closed host set, timeouts, classification,
     tracking disabled, `X-Message-Id`).
2. **Sending resolver.** `EmailProviderResolver`: add `sendgrid` to
   `PROVIDERS` with its match arm.
3. **`SendGridEmailEventAdapter implements EmailEventAdapter`:**
   - `provider()` returns `sendgrid`;
   - `authenticate(Request)` per §11;
   - `normalize()` per §13, §14 and §15.
4. **Event resolver.** `EmailEventAdapterResolver`: add `sendgrid` to
   `ADAPTERS` with its match arm.
5. **Per-adapter event bounds (§12):** the adapter declares them; the
   controller reads them.
6. **Configuration and production guard:**
   - `MAIL_PROVIDER=sendgrid` requires `MAIL_PROVIDER_EVENTS=sendgrid`, and
     vice versa;
   - the API key is present and not a placeholder;
   - the region is `global` or `eu`;
   - every event-ring entry parses as an EC public key;
   - tracking is `false`;
   - no sandbox mode in production.
7. **Signature and replay tests:** valid, wrong key, previous-ring key,
   stale and future timestamps, a missing header, a tampered body.
8. **Fixtures for every §13 event,** including the ambiguous `blocked` and
   the `dropped` reasons.
9. **Duplicate and out-of-order tests** (late `delivered` after `bounced`,
   the same `sg_event_id` twice, a replayed batch), plus a maximum-size
   batch.
10. **Secret redaction.** The API key, verification keys and raw payloads
    never reach logs, errors or audit (`LogSanitizer` names added).
11. **Linkage tests** proving the §15 chain and School resolution from the
    stored row only.
12. **Documentation.** The E18 runbook section in
    `EMAIL-DELIVERABILITY.md`:
    - key and webhook rotation;
    - the two-sided suppression release;
    - the account-wide tracking settings;
    - the domain-authentication steps.
13. **Full canonical regression** (`SAFE_TEST_ISOLATED=1 bin/safe-test
    --reset-db`).
14. **Full O16 qualification** of both images.
15. The §21 sandbox gates passed and recorded.

**Out of scope:**
- no SMTP/API symmetry;
- no multi-provider routing or failover;
- no provider suppression synchronization;
- no tracking;
- no List-Unsubscribe;
- no change to the durable email model or state graph.

## 23. E19 — start the observation clock early

**What starts the clock.** The 14-day aligned-DKIM clock (ADR 0055 §17)
dominates the email critical path. It can run **in parallel with E18**,
because DKIM signing is the same whichever Lycenza adapter submits. It
starts only on the owner's rule-16 authorization.

**Account first.** Opening the SendGrid account accepts its terms, a
commercial and legal act. The owner decides whether that waits for §20.

**Sequence:**
1. Create the non-production SendGrid account or subuser, with the plan
   and region per §20.
2. Create a Mail-Send-only key and a separate operator credential, stored
   in the managed store.
3. Turn off all tracking account-wide (§10).
4. Authenticate the domain: `MAIL_SENDING_DOMAIN`, automated security, a
   custom return-path label and a new custom DKIM selector.
5. Publish the CNAMEs and `_dmarc` at `p=none`, with aggregate reports to
   an operator-controlled mailbox.
6. Confirm SendGrid verification and `platform:mail-verify-domain`. Check
   the DKIM key is 2048-bit.
7. Begin observation traffic:
   - **non-production only**, to **operator-controlled recipients** at
     major mailbox providers;
   - no School data, sent regularly;
   - from either a non-production deployment of the current VERIFIED
     image using the existing hardened `smtp` adapter against SendGrid's
     relay (TLS; the restricted key), before E18, or the E18 adapter once
     merged.
8. Collect aggregate reports. At **≥ 14 consecutive days of 100 % aligned
   DKIM pass**, move to `p=quarantine`, and only then set
   `MAIL_SENDING_VERIFIED`.

Evidence is recorded per E29 (dates, selectors, pass rates; never
addresses or keys). **No O1 readiness is claimed before the observation
contract is met.**

## 24. E16 interaction (carried forward)

- **Expiry dates.** The current exception records expire **2026-10-10**
  (54 records) and **2026-10-26** (43). `exceptions.py` fails a record on
  its expiry date itself, so the last passing dates are 2026-10-09 and
  2026-10-25.
- **Why they cannot cover E02.** The E19 clock alone puts the final E02
  qualification after both dates.
- **Required before E02.** A fresh scan, then either:
  - a reviewed base-image or dependency refresh that removes the findings;
    or
  - a fresh, explicit owner/security decision on the new scan.
- **No renewal.** Nothing here renews or extends any record.
- **Unless both expiries are covered first,** the E18 qualification
  (0O.13A) will itself need that fresh decision or a refresh.

## 25. Alternatives considered

1. **Amazon SES.** Passes, and is the documented fallback. It was not
   selected because:
   - ADR 0055 §11.2 compliance needs a pinned SNS certificate (the
     certificate URL is in the untrusted body);
   - SNS posts `text/plain` and needs a subscription handshake (controller
     changes);
   - the default retries are short.

   It is preferred if legal requires a region only AWS offers.
2. **Mailgun.** Passes, not selected:
   - the webhook HMAC does not cover the body;
   - event ids are unique only within a day;
   - the retry window is 8 h.
3. **Postmark.** Rejected: 1024-bit DKIM and no webhook signatures.
4. **SMTP transport to SendGrid with an event adapter only.** Rejected for
   production: the SMTP id format is unverified (linkage), and tracking
   cannot be enforced per message. Kept only as a pre-E18 observation path.
5. **Two transports or multi-provider failover.** Rejected (ADR 0055 §6;
   rule 2).

## 26. Consequences and status

- **E17:** `LEGAL_REVIEW_REQUIRED`. The provider is selected; the
  processor/legal review is outstanding.
- **E18:** blocked on E17. Its scope is frozen (§22), and it starts only
  after the legal amendment.
- **O1:** RESOLVED AS DEFINITION OF DONE — **NOT SATISFIED**.
- **Phase 0O:** CLOSEOUT BLOCKED — deployment, legal, governance and
  provider evidence outstanding.
- **Phase 0M:** BLOCKED.
- **Code.** None in this checkpoint. The executable checkpoint stays
  `e52c4c4`.

**Status correction (2026-09-29, ADR 0061).** §26's "Phase 0M: BLOCKED"
was true on its date. The current status is **CLOSED FOR PHASE ZERO — REAL
PROVIDERS / REAL AGENTS DEFERRED POST-v1**. Nothing else in this ADR
changes.
