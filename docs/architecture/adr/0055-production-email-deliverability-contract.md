# ADR 0055: Production Email & Deliverability Contract

- Status: Accepted (contract only; implementation is Phase 0O.9A)
- Date: 2026-09-27 (Phase 0O.9)
- Resolves: **O13** (`docs/architecture/PHASE-0O-READINESS.md` §8)
- Amends:
  - ADR 0054 §13: the web-domain/email-domain boundary it recorded is now
    frozen here (§8.4);
  - Phase 5A.3 (`docs/communication-hub/PHASE-5A-3-EMAIL-DELIVERY.md`): the
    deferred provider events, bounce handling and idempotency key are
    decided here.
- Related: ADR 0026/0027 (outbound webhooks), ADR 0047 (School lifecycle),
  ADR 0050 (secrets), ADR 0051 (observability), ADR 0052 (supply chain),
  ADR 0054 (canonical origins).

## 1. Context — what exists today (audited 2026-09-27, `origin/main` `610349d`)

### 1.1 Outbound email inventory

The application sends email from exactly two places. There are no Laravel
`Notification::toMail()` classes, no password-reset or other authentication
mail, and no security notices by email.

| | Guardian account invitation | Communication Hub email |
|---|---|---|
| Class | `App\Domain\Identity\Mail\GuardianAccountInvitationMail` | `App\Domain\Communications\Application\Channels\CommunicationMail`, via `EmailChannelDriver` |
| Purpose | One-time link to create or link a Guardian's account (access-critical) | Announcements and conversation messages from a School to its families and staff |
| Trigger | `AccountInvitationService::invite()` / `resend()` (School admin; `guardians.manage` + `school.members.manage`) | `AnnouncementService::publish()`, `CommunicationMessageService` |
| Recipient | One Guardian (the resolved primary or oldest active email contact) | One recipient per delivery (`destination_snapshot['email']`, captured at creation) |
| Queued? | **No — `Mail::send()` synchronously, inside `DB::transaction()`** | Yes: `ProcessCommunicationDeliveryJob`, queue `notifications` |
| From | `config('mail.from')` | `config('mail.from.address')`, display name `"<School> via <mail.from.name>"` (never a School domain) |
| Reply-To | None | None |
| Transport | The default mailer (`MAIL_MAILER`) | `COMMUNICATION_EMAIL_MAILER`, else the default |
| Retry | None: an uncaught transport exception rolls the invitation back and fails the admin's request | Up to 3 attempts (30 s, 120 s, 300 s); every transport exception is treated as transient; claim lease plus the `communication-deliveries-redispatch` sweeper |
| School context | Explicit (`TenantContext::withSchool`) | Explicit job argument; `SchoolOperationalGuard` defers a suspended School's delivery |
| Template | `emails/identity/guardian-account-invitation` (text only) | `emails/communications/message` (text only) plus private-storage attachments, capped by `communications.attachments.email_max_total_size_mb` |
| Links | `CanonicalOrigin` (since 0O.8A) | None generated |
| Durable state | Invitation row (`token_hash`, `destination_email_hash`); **no delivery record** | `communication_deliveries` (`accepted`, `sent`, `delivered`, `bounced`, `rejected`, …, with a `provider_message_id` column) and append-only `communication_delivery_attempts`. Email only ever reaches `sent`, meaning the transport accepted the call. |
| Gate | None | `COMMUNICATION_EMAIL_ENABLED` (default **false** everywhere, including DDEV) |

The platform `NotificationDispatcher`'s `email` provider is
`LogEmailProvider`, a **log-only test provider** that sends nothing.

### 1.2 Transport and configuration

- **Default mailer.** `config/mail.php` defaults to **`log`**.
  `.env.example` uses `log`; DDEV uses `smtp` to Mailpit (127.0.0.1:1025);
  phpunit forces `array`.
- **Defaults and fallbacks.**
  - `MAIL_FROM_ADDRESS` defaults to `hello@example.com`.
  - The SMTP mailer has `timeout => null`.
  - A `failover` mailer is defined as `smtp → log`, i.e. a silent sink.
  - The framework's `ses`, `postmark` and `resend` stubs are present but
    unused.
- **Secret handling.** `MAIL_PASSWORD` is already in the `app_runtime`
  secret group.
- **No guard.** `ProductionConfigurationGuard` checks nothing about mail.

### 1.3 Other relevant facts

- **Recipient addresses.**
  - Guardian email contacts are encrypted at rest and normalized and
    lowercased at write time (`GuardianContactService`).
  - `verified_at` exists but **no verification workflow** sets it.
  - `schools.email` is free School profile data.
- **Content.** There is **no HTML sanitizer or rich-text** anywhere
  (Phase 5A.6 audit); every email is text/plain. Attachments are
  private-storage objects, never filesystem paths.
- **Recipient model.** There is no To/Cc/Bcc fan-out: one recipient per
  delivery (`EmailChannelDriver` sends to one `destination_snapshot` address), and no unsubscribe or `List-Unsubscribe`
  exists.
- **Preferences and policy.** Communications has recipient preferences, an
  append-only consent ledger, School channel policies (optional vs
  required), quiet hours with an emergency timing bypass, approvals and
  priorities.
- **Provider ingress.** No inbound provider-event endpoint exists anywhere.
  Payments has the normalized-event, unique-claim ingestion pattern
  (`PaymentProviderEventService`), with authenticity deferred to an
  adapter.
- **Rate limits.** Guardian invitation send and resend are **not
  throttled** (only acceptance is: `guardian-invitation-accept`).
  Communications volume is bounded by approvals and policies, not by a
  submission rate.
- **Observability.** Metrics `lycenza_communication_*` exist, and alerts
  OBS-17/OBS-18 cover overdue deliveries and the failure ratio.

### 1.4 Findings recorded for 0O.9A

1. **The invitation is sent inside the business transaction** (CLAUDE.md
   rule 38).
   - The provider's latency holds the transaction open.
   - A transport error fails the admin action.
   - A commit failure after the send leaves an email carrying a dead token.
   - No delivery record exists.
2. **Production silently sinks mail.** Nothing stops production from
   running on the `log` mailer (the default when unset), with a
   `hello@example.com` From address and an unbounded SMTP timeout, or from
   selecting the `failover → log` mailer.
3. **No deliverability model.** There is no provider event ingestion,
   bounce or complaint handling, suppression, provider message id or
   idempotency key, and Communications never learns whether email arrived.
4. **Invitation send and resend are unthrottled.**

## 2. Decision summary

- **Sender.** Production email is sent only from a **Lycenza-controlled,
  deployment-configured sending domain**. A dedicated subdomain is
  recommended, conceptually `notify.<platform domain>`; the name lives in
  configuration, never in code. From addresses come from a **closed
  mailbox catalog**, and Schools control display branding only.
- **Provider.** There is **one** configured transactional provider at a
  time, behind a Lycenza adapter boundary. No vendor is chosen. The
  requirements are frozen in §5.
- **One durable email layer.** It serves every sender: logical message,
  attempts, events and suppression. Invitations enter it through an outbox
  row written in the business transaction, and Communications hands its
  email deliveries to it. Submission is never delivery.
- **Deliverability.** SPF, DKIM with alignment and a staged DMARC policy are
  deployment-gated. Provider events are authenticated, deduplicated and
  normalized into a closed set. Hard bounces and complaints feed a global
  platform suppression list.
- **Scope.** v1 is **transactional and operational only**. Marketing and
  commercial bulk mail is out of scope.

## 3. Message-purpose catalog (closed)

| Class | Kind | v1 status | Producer |
|---|---|---|---|
| `account_invitation` | **Critical** (access) | Exists | Identity (Guardian invitation) |
| `account_recovery` | **Critical** (access) | **Reserved for O14**: no route, token or template in 0O.9A | — |
| `security_notice` | **Critical** (security) | Reserved: none exists today | — |
| `school_communication` | Standard (operational School → family/staff notice) | Exists | Communications email channel |

Rules:
- **The class is set by the producer in code**, never chosen by a School or
  a request. A new class needs an amendment to this ADR.
- **Kind drives behaviour.** A critical class gets the retry and alerting
  of §10, never shares its suppression exception with standard mail (§12),
  and carries `Auto-Submitted: auto-generated`.
- **Marketing and commercial bulk email is out of scope for v1.** A School
  cannot turn `school_communication` into unrestricted bulk mail: the
  existing audience, approval, preference and consent rules stay
  authoritative, plus the submission budgets of §15.

## 4. Critical-message dependency (O14)

O13 must provide the transport future recovery depends on:
- durable submission that never runs inside a business transaction;
- bounded retries, alerting on provider outage, and suppression awareness;
- canonical links (§13);
- a sealed-content lifetime no longer than the link's lifetime (§9.4).

O14 itself (tokens, routes, rate limits and the generic "if an account
exists…" outcome) is **not** designed here.

## 5. Provider requirements (provider-neutral)

The one configured provider **must** support:
1. authenticated submission over an **HTTPS API** or **SMTP with mandatory
   TLS** (STARTTLS required or implicit TLS; never plaintext and never
   opportunistic downgrade), with credentials scoped to sending;
2. a custom sending domain, DKIM signing for it (2048-bit RSA or a stronger
   supported algorithm), and a **custom envelope/return-path subdomain**
   so SPF can align;
3. a stable provider message identifier returned at submission;
4. delivery, deferral, bounce (hard and soft) and complaint events delivered
   by **authenticated** webhook (signature or secret), retried by the
   provider, with a stable event identifier or enough fields for §11.3's
   fingerprint;
5. provider-side suppression visibility (events for suppressed or dropped
   recipients);
6. **open and click tracking that can be disabled** for the stream or
   account;
7. throughput limits and credential **rotation with overlap** (two valid
   credentials or keys at once);
8. data-processing terms compatible with this ADR's classification; the
   region and processor review are a deployment and legal gate.

**Optional:**
- a submission **idempotency key** (used when available, §9.3);
- separate transactional and bulk streams;
- a sandbox or test mode that generates bounce and complaint fixtures (used
  by the drill, §18, when available).

## 6. Transport boundary and SMTP vs API

- **The boundary.** 0O.9A adds an application-facing
  **`OutboundEmailGateway`**. It is the only code that builds and submits
  production email. It depends on **one** `EmailProviderAdapter`
  (`submit(OutboundEmail): SubmissionResult`) selected by `MAIL_PROVIDER`.
  - It never runs inside a business transaction, and never from a web
    request.
  - Vendor event names, status codes and payloads stay inside the adapter;
    domain code sees only §11's normalized events and §9's states.
- **Adapters in 0O.9A:**
  - `fake`, for local and testing only, recording in memory or cache;
  - a hardened, provider-neutral **`smtp`** adapter: TLS required, peer
    verification on, authenticated, connect ≤ 5 s, command/response ≤ 15 s,
    no failover to `log`.
- **API adapters.** An **HTTPS API** adapter for the chosen vendor is
  **preferred** and is written in the vendor-selection change. That change
  also supplies the vendor's event adapter (§11). No multi-provider
  framework, routing or failover between providers.
- **No framework sinks in production.** Laravel's `log`, `array`, `failover`
  and `roundrobin` mailers and the framework vendor stubs are never a
  production path; the production guard refuses them (§20).

## 7. Credentials and custody (ADR 0050)

- **What is Highly Sensitive:** provider API keys, SMTP credentials, event
  webhook secrets and any provider signing keys. They are `app_runtime`
  secrets from the managed secret store, and only the web and worker roles
  that submit or receive events get them.
- **Where they never appear:** git, images, the database, a School row,
  the frontend, logs, Terraform output, audit metadata or errors. No School
  role can see any provider setting.
- **Rotation.** Event verification secrets form a **ring of 1–2**, so a
  rotation can overlap. Submission credentials rotate with overlap when the
  provider allows it (§19).

## 8. Sending identity

### 8.1 Sending domain

- **Configuration.** `MAIL_SENDING_DOMAIN` is a Lycenza-controlled domain,
  recommended as a dedicated subdomain of the platform's registrable domain,
  so its reputation and DMARC policy are isolated from the corporate
  domain. It must be a canonical hostname (0O.8A `HostnameNormalizer`
  rules).
- **What it is not.** It never equals or sits under any School custom
  domain; `ReservedHosts` already reserves the platform domain's subtree.
- **Where records live.** All DNS records are deployment evidence (§17);
  the application never changes DNS.

### 8.2 From address (closed catalog)

- **Address.** The From address is `<mailbox>@<MAIL_SENDING_DOMAIN>`, and
  the mailbox comes from a **code-owned catalog**.
  - v1 catalog: **`notifications`**, used by every class.
  - A new mailbox (for example `security`) needs an amendment to this ADR
    and the DNS evidence to match.
- **No free-form input.** There is no per-message From and no free-form
  local part.

### 8.3 Display name and Reply-To

- **Display name.**
  - School mail: `"<School display name> via <MAIL_FROM_NAME>"`; platform
    mail: `MAIL_FROM_NAME` (the Communications convention since 5A.3).
  - The School name is sanitized: CR/LF and control characters removed;
    `<`, `>`, `@`, `"`, `,` and `;` removed (so no address-like or
    header-splitting display); whitespace collapsed; at most 64 characters.
    It falls back to `MAIL_FROM_NAME` when nothing usable remains.
- **Reply-To in v1: none from Schools.**
  - No School address is verified: `schools.email` is free profile data,
    and contacts' `verified_at` has no workflow.
  - So Reply-To is omitted (conversations reply in-app), and no request
    value ever reaches a header.
  - A verified School Reply-To is a later amendment. It needs a real
    verification flow, and Reply-To never authenticates a sender.
- **School branding controls:** the display name (through its School name)
  and content within the existing Communications authorization. It never
  controls the authenticated From domain, DKIM identity, envelope sender,
  provider account or webhook credentials.

### 8.4 Web domains are not email domains (O9 boundary)

An ACTIVE custom School web domain (ADR 0054) authorizes **nothing** about
email:
- no `From:` at that domain;
- no DKIM for it;
- no return-path at it.

School-owned sending domains are a **separate future contract**: domain
proof, SPF/DKIM/DMARC evidence, provider provisioning and revocation. They
are not v1.

## 9. Durable model

### 9.1 Source of truth

One platform email layer beneath both producers:

| Table (0O.9A) | Role |
|---|---|
| `email_messages` | **Authoritative transport state** of one logical email to **one** recipient. It holds a UUIDv7 id, `school_id` (nullable only for future platform mail), class, mailbox, recipient (encrypted), `recipient_hash`, sealed content, state, `expires_at`, the RFC 5322 `Message-ID`, provider and provider message id, and the source reference (`source_type`, `source_id`). |
| `email_submission_attempts` | Append-only, one row per real provider call. Unique on (`email_message_id`, `attempt_number`). |
| `email_events` | Normalized provider events. Unique on (`provider`, `event_key`). |
| `email_suppressions` | The global suppression list (§12). |

Rules:
- **RLS.** Tables carrying `school_id` are RLS-protected (rule 18).
  Suppression is platform data (§12.3).
- **Product state stays with its owner.** Invitation state stays with the
  invitation.
- **Communications.** `communication_deliveries` stays the per-recipient
  product record for Communications. Its email status is **projected from**
  `email_messages` by one mapping:
  - handed over → `accepted`;
  - `submitted` → `sent`;
  - `delivered` → `delivered`;
  - `bounced` or `complained` → `bounced`;
  - `suppressed` or rejected → `rejected`;
  - `failed` or `cancelled` → `failed`.

  Communications' own 3-attempt retry no longer applies to email: the email
  layer owns email retries (rule 59: one retry owner).

### 9.2 States (closed; submission ≠ delivery)

**States:** `pending`, `submitting`, `submitted`, `deferred`, `delivered`,
`bounced`, `complained`, `failed`, `suppressed`, `cancelled`.

**Terminal before any provider acceptance:** `failed` (permanent submission
failure or attempts exhausted), `suppressed` (a suppression hit when
claimed) and `cancelled` (source revoked, School or recipient no longer
eligible, or `expires_at` passed).

**After acceptance the state only moves forward:**
- `submitted` → `deferred` / `delivered` / `bounced` / `complained`;
- `deferred` → `delivered` / `bounced`;
- `delivered` → `bounced` (asynchronous bounce) / `complained`;
- a **late `submitted`, `deferred` or `delivered` never moves a message
  backward** from `delivered`, `bounced` or `complained`;
- `complained` is final.

A database trigger enforces this graph, like `school_domains`. A
`delivered` event is transport evidence only: it never accepts an
invitation, activates an account or changes any authorization.

### 9.3 Attempts and idempotency

- **Claiming.** A worker claims a message with a conditional UPDATE to
  `submitting` with a lease (the `DeliverWebhookJob` pattern, rule 48). It
  re-checks suppression, expiry, the source's eligibility and the School
  (`SchoolOperationalGuard`) inside the claim transaction, then submits
  **after** commit.
- **One identity per logical email.** Every attempt reuses the same
  `Message-ID` (`<{id}@{MAIL_SENDING_DOMAIN}>`) and, when the provider
  supports it, **the message id as the idempotency key**.
- **Crash after acceptance.** When the worker dies after the provider
  accepted but before recording it, the lease expires and the message is
  submitted again with the same identity. Without provider idempotency a
  **duplicate is possible**. The contract is **at-least-once submission**
  with deduplication where the provider allows it, never exactly-once.
- **Anti-duplication guards.** The lease is far longer than the submission
  timeout, and a worker never re-claims its own in-flight message.

### 9.4 Sealed content

- **Rendered once.** Content is rendered **once** by the producer and
  stored in `sealed_content`: subject, text body and attachment references,
  under Laravel's `encrypted` cast.
- **Why.** The invitation's plaintext token cannot be recomputed from its
  hash, and every retry must send identical bytes.
- **Purge.** Sealed content is purged once the message reaches `submitted`
  or a pre-submission terminal state, and in any case at `expires_at`. The
  row keeps only metadata.
- **Critical classes:** `expires_at` is never later than the embedded
  link's validity.

### 9.5 Producers

- **Invitations.** The invitation row, its audit record and a `pending`
  `email_messages` row (sealed) are written in **one business
  transaction**. After commit a job submits it; there is no provider I/O in
  the transaction (fixes finding 1).
  - Resend revokes the old invitation and cancels its unsent message.
  - The admin sees "invitation created; email queued", and the delivery
    state appears on the Guardian page.
- **Communications.** `EmailChannelDriver` creates the message (sealed from
  the delivery's destination snapshot) and returns `accepted`. The email
  layer then drives the projection.

## 10. Retries, timeouts and outage

- **Classification.**
  - Transient: connect or read timeout, TLS or network error, HTTP 429 or
    5xx, SMTP 4xx, provider throttling, provider authentication failure.
    Authentication failure also raises an operator alert and is never a
    per-message permanent failure.
  - Permanent: HTTP 4xx other than 408/429, SMTP 5xx recipient or message
    rejection, a sender not verified, content refused.
- **Backoff.** Exponential with ±20 % jitter: 30 s, 2 min, 10 min, 30 min,
  2 h. At most **6 attempts** and never past `expires_at`; then `failed`.
  There is never infinite retry.
- **Operator retry** resets the attempt budget once and re-runs every claim
  check, suppression included. It never bypasses anything.
- **Timeouts.**
  - Provider: connect ≤ 5 s, request ≤ 15 s.
  - Jobs: `$tries = 1`, `$timeout = 30` (< 90 s `retry_after`, rule 58), on
    queue **`notifications`**. No new worker role; existing canaries and
    heartbeats cover it.
  - A sweeper re-dispatches due and expired-lease messages, like
    `communication-deliveries-redispatch`.
- **Provider outage.** Email becomes **degraded**, never global readiness:
  - messages wait durably and nothing is lost;
  - the operation that queued them already reported "queued", never
    "delivered";
  - Operations Status, metrics and alerts show it (§16).
  - Readiness never calls the provider.

## 11. Provider events

### 11.1 Normalized set (closed)

`delivered`, `deferred`, `bounce_transient`, `bounce_permanent`,
`complaint`, `rejected` (the provider refused or dropped the message,
including provider-side suppression), `ignored` (anything else: opens and
clicks, which are disabled anyway, and unknown types).

- **Adapter-only vocabulary.** Vendor strings never leave the adapter.
- **What is stored.** An event stores its normalized type, event key,
  provider message id, `occurred_at`, `received_at`, a closed bounce
  sub-class (for example `mailbox_unknown` or `mailbox_full`) and the
  processing outcome.

### 11.2 Webhook endpoint and authentication

- **Route.** `POST /api/integrations/email-provider/events` on the
  **platform host** only.
  - It is not a School custom-domain route, not `/api/v1`, and has no
    session or cookie.
  - The 0O.8A host boundary answers 404 on School hosts. It resolves no
    School from the Host.
- **Authentication by the adapter**, before any parsing beyond the body
  bound:
  - the provider's signature (HMAC or asymmetric) verified against the
    **configured** secret ring (1–2 entries), with a timestamp tolerance of
    at most 5 minutes when the scheme carries one;
  - or a provider shared secret over TLS where that is the vendor's only
    mechanism;
  - **never** source IP alone;
  - **never** a key fetched from a caller-supplied URL (JWKS or keys only
    from configuration).
- **Unauthenticated requests** get a uniform 401. Nothing is processed and
  the body is not stored.
- **Bounds:**
  - body ≤ 256 KiB;
  - `Content-Type` must be the vendor's declared JSON (or form) type;
  - JSON depth ≤ 32 and ≤ 100 events per request;
  - rate limit `email-provider-events` of 600/min per source;
  - normalized events are applied in a queued job, so the request only
    verifies, deduplicates and enqueues.

### 11.3 Replay, deduplication and ordering

- **Event key.** The provider's stable event id, or, when a vendor has none,
  `sha256(provider | provider_message_id | normalized type | occurred_at |
  recipient_hash)`. The unique index (`provider`, `event_key`) makes a
  duplicate a no-op: no transition, metric or audit twice.
- **Ordering.** Events apply through the monotonic graph of §9.2, so late,
  duplicate and out-of-order events are safe. An event for an unknown
  provider message id is recorded `ignored` (it may belong to another
  environment) and never creates a message.
- **Tenancy.** The School is **always** the stored `email_messages.school_id`
  found by provider message id. A School or tenant value in the payload is
  never trusted.

## 12. Bounce, complaint and suppression

### 12.1 Bounces

- **`bounce_transient`** is informational (`deferred`): the provider owns
  its retries.
- **`bounce_permanent`** moves the message to `bounced` and adds a
  suppression. A permanently invalid address is never retried.

### 12.2 Complaints

A verified complaint moves the message to `complained` and suppresses the
address:
- a complaint about `school_communication` suppresses **standard** classes;
- a complaint about a critical class suppresses **all** classes.

No School queueing more mail overrides this.

### 12.3 Suppression model

- **The table.** `email_suppressions` is a **platform** table (no RLS,
  never readable by Schools). Its fields:
  - `address_hmac`: HMAC-SHA256 of the normalized address under the
    dedicated `MAIL_SUPPRESSION_HMAC_KEY`, never plaintext;
  - `scope`: `all` or `standard`;
  - `reason`: `hard_bounce`, `complaint`, `provider_suppressed` or
    `operator`;
  - `source_event_id`, `created_at`, and `released_at` / `released_by`.
- **Scope.** It is **global per address**: deliverability protects the
  shared sending reputation for every School. Schools see only their own
  delivery rows as `rejected`/`bounced` with a generic reason, never another
  School's data or the suppression list.
- **Release.** Only by an operator command (`platform:mail-suppression-release`,
  platform-audited), for example after a mailbox is confirmed repaired. A
  changed address is simply a different hash.
- **Separate from preferences.** Suppression is **not** a preference.
  Communications preferences and consent stay product rules. Suppression is
  never an unsubscribe, and preferences never bypass suppression.

### 12.4 Critical mail and suppression

Critical classes **respect** suppression of scope `all`. Emergency and
required communications never bypass it either: the 5A.10 emergency bypass
is a timing rule only. An undeliverable address cannot be made deliverable
by skipping the list. O14 must surface a generic, non-disclosing outcome and
never claim a delivery.

## 13. Recipients, headers, links and content

- **Recipients.** One recipient per message, never To/Cc/Bcc lists and never
  BCC fan-out. The address is the application's authoritative normalized
  value (as written by `GuardianContactService` / the User email), with no
  new case-folding rule. It is validated as an RFC address and refused when
  it contains CR/LF.
- **Headers.** Every configurable value (display name, subject) has CR/LF
  and control characters removed and is length-capped (subject ≤ 200).
  School content can never add transport headers. Code sets exactly:
  - `From`, `To`, `Subject`, `Date`, `Message-ID`, `MIME-Version`;
  - `Auto-Submitted` on critical classes;
  - any headers the provider requires, added inside the adapter.
- **Links.** Every School link comes from `CanonicalOrigin` (ADR 0054 §8.9),
  never a request Host, `APP_URL` blindly or a provider redirect.
  - Links carry no open-redirect parameter.
  - Queued mail renders from stored state, since there is no request.
- **Tracking.** Provider **open and click tracking stays disabled**
  (`MAIL_PROVIDER_TRACKING=false`; the guard refuses anything else). No
  pixels and no URL rewriting. Enabling it needs an explicit decision
  covering privacy and canonical links.
- **Content.**
  - Text/plain stays authoritative in v1 (the existing templates).
  - An HTML alternative may be added only from **repository-owned Blade
    templates with escaped interpolation**, and never from School-authored
    HTML (no sanitizer exists; 5A.6).
  - No remote images, logos or tracking.
  - School content stays within the existing Communications authorization.
    The transport adds no template scripting.
- **Attachments.** The existing Communications attachments from private
  storage keep the per-email cap (`email_max_total_size_mb`, ≤ provider
  limit). There are never arbitrary filesystem paths, and critical classes
  carry none.

## 14. Unsubscribe and legal boundary

- **v1 is transactional and operational.** There is no marketing
  subscription model, so no `List-Unsubscribe` is added, and suppression is
  not unsubscribe.
- **Deploy/product gate.** If a deployment's School-wide announcement
  volume makes it a **bulk sender** under mailbox-provider requirements,
  one-click unsubscribe mapped to the existing Communications preferences
  becomes required before scaling. That needs a product and legal decision
  first.
- **Retention and consent law** are not invented here. Where §21's periods
  are legal questions they are marked **[LEGAL REVIEW REQUIRED]**.

## 15. Rate limiting and fairness

- **Invitations.** `guardian-invitation-send` allows 10 per minute per actor
  and 200 per day per School for invite and resend (fixes finding 4).
- **Submission budgets per School**, operator-configurable and applied at
  claim time:
  - defaults `MAIL_SCHOOL_PER_MINUTE=60` and `MAIL_SCHOOL_PER_DAY=5000`;
  - a School over its budget is **deferred** (`next_attempt_at`), never
    failed;
  - critical classes use a separate, smaller bucket so a School's
    announcement burst cannot starve its invitations.
- **Global throughput.** `MAIL_GLOBAL_PER_MINUTE` protects the provider
  quota. It is a capacity setting, not a security policy.
- **Fairness.** Claims order due messages oldest first with **at most N in
  flight per School** (`MAIL_SCHOOL_MAX_IN_FLIGHT`, default 5). There are
  no per-School queues.
- **Fan-out.** Announcements already create one delivery per recipient,
  dispatched in chunks; the email layer submits one message per provider
  call. A provider call never carries a recipient list.

## 16. Observability and operations

**Logs** (event codes, closed outcome codes, ids):
- carried: `email_message_id`, class, attempt number, provider outcome, and
  the normalized event;
- **never** carried: the recipient address as an ordinary field, the body,
  tokens, provider keys, webhook secrets or raw payloads;
- `LogSanitizer` gains the new secret names.

**Metrics** (closed labels only; never School, recipient, domain,
provider message id or template):

| Metric | Type | Labels |
|---|---|---|
| `lycenza_email_messages_total` | counter | `message_class`, `outcome` ∈ `queued`, `submitted`, `delivered`, `deferred`, `bounced`, `complained`, `suppressed`, `failed`, `cancelled` |
| `lycenza_email_submission_attempts_total` | counter | `message_class`, `outcome` ∈ `accepted`, `transient_failure`, `permanent_failure` |
| `lycenza_email_webhook_requests_total` | counter | `outcome` ∈ `accepted`, `unauthenticated`, `too_large`, `malformed`, `duplicate` |
| `lycenza_email_pending_messages` | gauge | `message_class` |
| `lycenza_email_oldest_pending_age_seconds` | gauge | `message_class` |
| `lycenza_email_last_event_timestamp_seconds` | gauge | — |

**Alerts** (conceptual; the OBS ids are assigned in 0O.9A after checking
the catalog, next free after OBS-30):

| Condition | Severity |
|---|---|
| Critical-class oldest pending age above threshold | SEV-2 |
| Standard backlog above threshold | SEV-3 |
| Submission failure ratio high | SEV-3 |
| Provider authentication failures | SEV-2 |
| No provider events while submissions happen (event staleness) | SEV-3 |
| Hard-bounce spike | SEV-3 |
| Complaint spike | SEV-2 (reputation) |
| Webhook authentication failures | SEV-3 |

None is SEV-1, and email never enters readiness (rule 56 spirit).

**Operations Status:** an `email` component showing configured state,
submission health (recent attempt outcomes), event ingestion freshness,
backlog and oldest pending. It is `Degraded` at worst and shows no
credential, recipient or provider detail.

**Operator commands:**
- `platform:mail-verify-domain`: read-only DNS evidence for SPF, DKIM
  selector and DMARC, through the 0O.8A resolver;
- `platform:mail-suppression-release`;
- `platform:mail-retry {message}`: re-runs every check;
- `platform:mail-status`.

## 17. Domain authentication (deploy-gated)

- **SPF.** The sending domain's envelope/return-path subdomain (for example
  `bounce.<sending-domain>`, provider-controlled) publishes **exactly one**
  SPF record authorizing only the configured provider mechanism. No vendor
  `include:` is prescribed here. Evidence: the effective record and an
  evaluation result.
- **DKIM.** The provider signs with `d=` the sending domain (or the
  organizational domain) using a 2048-bit RSA key or a stronger supported
  one. The application never holds the private key when the provider signs;
  if it ever did, that key is Highly Sensitive. Evidence: selector, domain,
  and verification state.
- **Envelope sender.** Provider-controlled, under the sending domain's
  return-path subdomain. VERP or tagged bounce ids are allowed. There is
  never a School or customer return path.
- **Alignment.** DMARC alignment by **DKIM is mandatory** (`d=` aligned with
  the From domain). SPF alignment through the custom return-path subdomain
  is expected. Relaxed alignment (`adkim=r; aspf=r`) is acceptable. School
  branding can never change the aligned domain.
- **DMARC**, staged:
  1. `p=none` with aggregate (`rua`) reporting during controlled
     verification only;
  2. `p=quarantine` once aggregate reports show 100 % aligned DKIM pass for
     real traffic over at least 14 days;
  3. `p=reject` as the target.

  **Production-readiness closeout requires at least `p=quarantine`.**
  `reject` readiness is never claimed without that evidence.

## 18. Deployment readiness and drill

**Sending-domain readiness**, all operator evidence (no School admin can
assert it):
- the sending domain is configured;
- SPF, DKIM and DMARC pass `platform:mail-verify-domain`;
- provider domain verification is complete;
- a test delivery arrives;
- bounce and complaint events arrive and authenticate;
- suppression works.

The production guard needs the operator attestation `MAIL_SENDING_VERIFIED`
before any critical or standard class may submit. Until then submission
stays `pending` and alerts.

**Deliverability drill** (non-production), covering:
- a successful delivery;
- a soft bounce;
- a hard bounce leading to suppression;
- a complaint, using the provider's sandbox or fixtures, never real third
  parties;
- a duplicated and a replayed webhook;
- provider event retry;
- credential rotation or controlled replacement.

## 19. Credential rotation

- **Routine.** Add the new credential (and add the new webhook secret to
  the ring), deploy, observe successful submissions and verified events,
  then retire the old one. Use provider overlap where available.
- **Compromise.** Revoke at the provider immediately, install the
  replacement, restart the web and worker roles (secrets are read at
  start), prove the old credential fails, review logs for the exposure
  window.
- The 0O.9A runbook records each step.

## 20. Production guard (0O.9A)

The guard refuses to boot on any of these:
- the `log`, `array`, `failover`, `roundrobin` or `sendmail` transports, or
  a Mailpit/local host;
- `MAIL_PROVIDER` other than a real adapter, or `fake` outside
  local/testing;
- a missing provider credential;
- a missing or invalid `MAIL_SENDING_DOMAIN`, or one under a School
  domain;
- a From outside the catalog;
- plaintext or opportunistic-only SMTP, or TLS verification off;
- a missing or placeholder webhook secret ring;
- a missing `MAIL_SUPPRESSION_HMAC_KEY`;
- tracking enabled;
- provider debug or sandbox mode in production.

Disabled email (`MAIL_PROVIDER=none`) is an allowed, explicit mode. In it,
critical mail cannot be sent and the product says so. It never silently
sinks.

## 21. Classification, audit and retention

| Item | Class |
|---|---|
| Provider API key or SMTP credential, webhook secret or key, `MAIL_SUPPRESSION_HMAC_KEY`, any application-held DKIM key | Highly Sensitive |
| Recipient address (encrypted in `email_messages`) | Sensitive (personal data) |
| Sealed content | The classification of its business content (often Sensitive; an invitation or recovery link is Highly Sensitive until purged) |
| Provider message id, normalized events, attempts | Internal (operational metadata) |
| Bounce and complaint facts, suppression hashes | Sensitive (personal data) |
| SPF, DKIM and DMARC DNS | Public |
| Raw provider payloads | Not stored by default. A time-boxed incident capture, if ever enabled, is Confidential with a documented short retention. |

**Audit.**
- What is audited: invitation send, resend and revoke (existing),
  Communications send and cancel (existing), and operator suppression
  release and message retry (platform ledger).
- What is **not** audited: routine provider events and automatic
  suppression. They belong to delivery state, logs and metrics.

**Retention.** Message metadata, attempts and events are kept for an
operational window. **[LEGAL REVIEW REQUIRED]** for the final period
(configurable; like `WEBHOOKS_DELIVERY_RETENTION_DAYS`, an unset period
deletes nothing and is reported as unconfigured — no period is invented here). Suppression rows live until released.

## 22. Local, DDEV and tests

- **DDEV** never sends real email:
  - `MAIL_PROVIDER=smtp` to Mailpit is allowed only in local, behind the
    usual double guard;
  - the fake event adapter plus `platform:mail-fake-event` simulates
    delivery, bounce and complaint;
  - Mailpit shows the rendered text and HTML, headers, From, the absent
    Reply-To, canonical links and the recipient.
- **Tests** use the fake adapter and fixtures only; no real provider is
  needed. The 0O.9A matrix covers:
  - submission: success, timeout, transient failure, permanent failure,
    retry exhaustion, the idempotency key, and crash after acceptance
    (lease);
  - provider events: duplicate, out-of-order, late submitted-after-delivered,
    soft and hard bounces, complaint scopes;
  - suppression: hit at claim, critical mail respects it, operator release;
  - lifecycle and links: School suspension, invitation revoke or resend
    cancelling the unsent message, canonical URLs;
  - security: header injection (name, subject), display-name spoofing,
    webhook signatures (valid, wrong, stale, rotated ring), body and depth
    limits, tenancy from the stored row;
  - hygiene: secret redaction, the invitation now outside the transaction,
    the rate limits and the production guard.

## 23. Definition of done

**Repository (0O.9A):**
1. an explicit transport boundary (`OutboundEmailGateway` plus one
   adapter) with no framework sink in production;
2. the platform-controlled From identity and mailbox catalog, sanitized
   display name, and no School Reply-To;
3. a deployment-configured, provider-neutral sending domain;
4. the durable message/attempt/event model and state trigger, with
   Communications projected and invitations moved out of the transaction;
5. bounded retries and idempotency (at-least-once, documented);
6. authenticated, deduplicated, normalized events (for the fake adapter; a
   vendor adapter comes with vendor selection);
7. suppression (global, scoped, operator release);
8. canonical School URLs;
9. correct secrets, logging and classification;
10. health, metrics, alerts and Operations Status;
11. no real provider in local or testing;
12. a production guard;
13. O16 requalification of both images (dependencies or code change).

**Deployment evidence (rule 16):**
- a provider account and one adapter configured;
- the real sending domain;
- SPF, DKIM and DMARC (at least `p=quarantine` at readiness closeout);
- credential custody in the secret store;
- a verified provider webhook;
- a successful non-production delivery drill (§18), with hard-bounce and
  complaint handling and suppression demonstrated;
- a credential-rotation procedure demonstrated;
- deliverability monitoring active.

## 24. O1 contribution

O1 stays open. Phase 0O cannot close while user-critical email could rely
on a local sink, unauthenticated or plaintext SMTP, an unverified sender
identity, no bounce or complaint handling, no suppression, or no provider
health monitoring. 0O.9A plus the deployment evidence removes each of these.

## 25. Alternatives considered

1. **Sending From School custom domains.** Rejected for v1: web ownership is
   not mail authority (§8.4), and it would need per-School DKIM, SPF and
   provider provisioning.
2. **Free-form From or School Reply-To.** Rejected: spoofing and
   unverifiable addresses.
3. **Keeping the synchronous invitation send.** Rejected (finding 1).
4. **A second durable queue only for invitations** (with Communications
   untouched). Rejected: two retry, suppression and event semantics for one
   channel. One email layer serves both, and Communications keeps its
   product record through a projection.
5. **Relying on provider-side suppression only.** Rejected: it is invisible
   to operators and lost on a provider change.
6. **Multi-provider failover.** Rejected (no demonstrated need; rule 2, and
   ADR 0050's provider-neutral stance). The `failover → log` mailer is exactly
   the unsafe form.
7. **Open and click tracking.** Rejected by default (privacy, URL
   rewriting, canonical links).
8. **Per-School queues.** Rejected in favour of claim-time budgets and a
   per-School in-flight cap.

## 26. Consequences

- O13 is **resolved as a contract**; the implementation is Phase 0O.9A.
- The invitation flow changes behaviour in 0O.9A: it is queued, and the
  admin sees the delivery state.
- Communications email can finally report delivered, bounced and
  complained.
- O14 is unblocked on the transport side (§4) but remains open.
- Still open: **O1, O2, O14, O15**.

## Amendment — Phase 0O.9A implementation (2026-09-27)

ADR 0055 is **implemented in the repository**. Nothing here chooses a vendor,
creates a credential, touches DNS, exposes a webhook to a real provider or
sends real email. What the implementation decided, where the contract left
room:

1. **Five tables, not four.**
   - `email_messages` and `email_submission_attempts` are tenant tables
     (forced RLS; attempts append-only).
   - `email_events` and `email_suppressions` are platform tables (no
     School, no address).
   - A fifth, `email_provider_references` (provider, provider message id,
     message id, School id — ids only, listed in
     `DatabaseRoleVerifier::NON_RLS_SCHOOL_TABLES`), is written by the worker
     that records an acceptance.
   - It is how a provider event, which arrives with no School context, finds
     its School from **stored** data without an RLS bypass and without
     trusting the payload.
   - `email_messages.school_id` is `NOT NULL`: v1 has no platform (School-less)
     mail.
2. **The state graph, exactly** (`EmailState`, and the identical database
   function `email_messages_transition_allowed()`, guard-tested
   cell-by-cell):
   - `pending` → `submitting` | `suppressed` | `cancelled` | `failed`
   - `submitting` → `submitted` | `pending` (retry scheduled) | `failed`
   - `submitted` → `deferred` | `delivered` | `bounced` | `complained` | `failed`
   - `deferred` → `delivered` | `bounced` | `complained` | `failed`
   - `delivered` → `bounced` | `complained`
   - Every other change is refused by trigger. Same-state updates (lease,
     deferral) are allowed.
   - `failed` is also reached after acceptance, on a provider `rejected`
     event (`status_code = provider_rejected`).
   - A soft bounce is recorded as `deferred`.
   - Three different notions of "final":
     - submission: only `pending`/`submitting` are ever submitted;
     - content: sealed content exists only in those two states — the
       trigger purges it on any other state, and the sweeper cancels at
       `expires_at`;
     - observation: `submitted`/`deferred`/`delivered` still take events.
3. **Identifiers.**
   - The internal id is a UUIDv7.
   - The RFC Message-ID `<id@sending-domain>` is fixed at creation, or at the
     first claim when the domain was not yet configured, then immutable.
   - The provider idempotency key is `lycenza-email-<id>`.
   - The provider message id is set once on acceptance.
4. **Adapters.**
   - `none`: disabled, the default. Nothing is submitted and messages wait
     with `email_disabled`.
   - `fake`: local/testing only; never resolved elsewhere and refused at
     boot.
   - `smtp`: the hardened baseline — required TLS via STARTTLS
     (`requireTls`) or implicit TLS, peer verification, auth, and the 5 s
     socket timeout that Symfony applies to the connect and to each
     response. Plaintext exists only for Mailpit in local/testing.
   - SMTP carries no delivery events, so SMTP-sent mail stays `submitted`.
   - The event side is `EmailEventAdapter` (authenticate + normalize). Only
     the test/local `FakeEmailEventAdapter` exists. Production event
     ingestion answers 404 until a vendor adapter is written with the vendor
     selection.
5. **Communications mapping.** The driver returns `accepted` (handed over).
   The email layer projects its state onto the delivery:

   | Email state | Delivery status |
   |---|---|
   | `submitted`, `deferred` | `sent` |
   | `delivered` | `delivered` |
   | `bounced`, `complained` | `bounced` |
   | `suppressed` | `rejected` |
   | `failed` with `provider_rejected` | `rejected` |
   | `failed` (other) | `failed` |
   | `cancelled` with `expired` | `expired` |
   | `cancelled` (other) | `cancelled` |

   - A projection never moves a delivery backward.
   - Communications' own retry no longer applies to email.
   - The finished metric counts `sent` once and failure outcomes; not
     `delivered`.
6. **Retries.**
   - Backoff: 30 s, 2 min, 10 min, 30 min, 2 h, ±20 % (injectable random);
     at most 6 counted attempts and never past `expires_at`.
   - An authentication/TLS refusal opens a platform-wide 15-minute pause
     (`ProviderAuthPause`, shared cache). Every other message defers without
     contacting the provider. Auth attempts count toward the six.
   - The claim lease is 120 s, the job timeout 30 s, the queue `retry_after`
     90 s.
   - `platform:mail-retry` restores the budget once, for a waiting message
     only. A finished message's content is gone: the product re-issues it.
7. **Fairness.**
   - Per School and globally, one in-flight slot is reserved for critical
     mail: defaults 5 per School and 50 globally, with standard mail using
     at most cap − 1.
   - Separate critical/standard rate buckets:
     - per School: 60/min and 5,000/day standard, 20/min and 1,000/day
       critical;
     - global: 600/min standard, 120/min critical.
   - Claims serialize on one advisory lock so the counts are exact.
   - Over budget defers, never fails.
8. **Suppression key ring** (amending §12.3).
   - `MAIL_SUPPRESSION_HMAC_KEY` / `_KEY_ID` (current) plus an optional
     `_PREVIOUS_KEY` / `_PREVIOUS_KEY_ID`, with stable ids. Every row records
     its key id.
   - Lookups try every ring key. A match under the previous key is
     re-recorded under the current key on observation.
   - `platform:mail-suppression-rekey` re-keys what it can reconstruct (the
     source message still holds its encrypted recipient) and reports the
     rest. An HMAC cannot be reversed, so those rows stay under the previous
     key: keep that key in the ring until they are released or re-observed.
   - `platform:mail-status` reports rows under a key no longer in the ring
     (never silently dropped).
9. **Guards and deferrals.**
   - `sending_domain_not_reserved`: in production nothing is submitted
     unless the sending domain is under the platform domain or a
     `DOMAIN_RESERVED_SUFFIXES` entry, i.e. no School can ever claim it.
   - `sending_not_verified`: nothing is submitted without the
     `MAIL_SENDING_VERIFIED` attestation. It records evidence; it proves
     nothing (§18).
   - The production guard codes are listed in
     `docs/architecture/PRODUCTION-RELEASE.md`.
   - Laravel re-merges its own default mailers, so the guard refuses a
     failover/roundrobin/sendmail **default** rather than their presence.
10. **Throttle.** `GuardianInvitationSendLimiter` allows 10 per minute per
    administrator and 200 per day per School, for invite and resend
    together. It is evaluated in the service once the School is known
    (rule 61) and answers one generic message.
11. **`NotificationDispatcher`.** The Phase 0C log-only `email` provider and
    the fake SMS/WhatsApp/push providers are registered in local/testing
    only. Elsewhere an `email` notification has no provider and is refused —
    never "sent" to a log.
12. **O14 dependency.**
    - `EmailPurpose::AccountRecovery` exists as a name only: the database
      and the gateway refuse it.
    - `EmailProviderResolver::criticalEmailAvailable()` is the readiness
      signal a future recovery flow must require before it is offered or
      declared production-ready.
13. **Observability.**
    - Six metrics with the `message_class` label.
    - Alerts OBS-31..OBS-38, runbook `docs/operations/EMAIL-DELIVERABILITY.md`:

      | Alert | Severity |
      |---|---|
      | OBS-31 critical backlog | SEV-2 |
      | OBS-32 standard backlog | SEV-3 |
      | OBS-33 failure ratio | SEV-3, operator value |
      | OBS-34 provider auth | SEV-2 |
      | OBS-35 event staleness | SEV-3 |
      | OBS-36 hard-bounce spike | SEV-3, operator value |
      | OBS-37 complaint spike | SEV-2, operator value |
      | OBS-38 webhook auth failures | SEV-3, operator value |

      None is SEV-1.
    - An `email` Operations Status component that is `Degraded` at worst.
    - Scheduled tasks `email-messages-redispatch` (every minute) and
      `email-prune` (daily; nothing is deleted while `MAIL_RETENTION_DAYS`
      is unset — [LEGAL REVIEW REQUIRED]).
14. **Impossible without a chosen provider** (deployment evidence, ADR 0055
    §23):
    - a real provider event feed and its authentication;
    - provider-side suppression visibility;
    - provider idempotency;
    - `delivered`/`bounced`/`complained` for real mail;
    - provider domain verification;
    - the DMARC aggregate history;
    - a delivery/bounce/complaint drill against a real sandbox.

## Consumer note — ADR 0056 (Phase 0O.10, 2026-09-28)

ADR 0056 (account recovery) activates the reserved purposes
`account_recovery` (critical, source `account_recovery_request`) and
`security_notice` (critical; the post-reset notice) in Phase 0O.10A. Both
are identity-level. In 0O.10A:

- **`school_id`.** `email_messages.school_id` becomes nullable — NULL if
  and only if the purpose is one of these two — and so does
  `email_provider_references.school_id`.
- **RLS.** Those rows are visible only inside an explicit platform-email
  scope, a new `TenantRls` helper mode, never hand-written SQL.
- **Budgets.** They use a `platform` budget bucket and skip
  `SchoolOperationalGuard`.
- **Expiry.** A recovery message's `expires_at` equals its credential's
  (30 minutes).

Nothing else in this contract changes.

## Note — Phase 0O.12 (ADR 0058, 2026-09-28)

For O1, the "SMTP baseline used knowingly (no events)" option is **not
sufficient**. Readiness needs authenticated provider events, and today only
the `none` and `fake` event adapters exist. Once a provider is selected, a
bounded checkpoint adds:
- the smallest provider-specific event-authentication and normalization
  adapter;
- an HTTPS sending adapter only if that provider requires it or clearly
  benefits from it.

That checkpoint needs its own tests, full regression and O16
requalification (ADR 0058 §4.11, rows E17–E20).

`MAIL_RETENTION_DAYS` remains **[LEGAL REVIEW REQUIRED]**. The retention
decision is now mandatory before Phase 0O closeout (ADR 0058 §4.12, option
A).
