# E17 — Twilio SendGrid as the transactional email processor: legal / processor review request

> **2026-10-14 — deferred to pre-production (owner decision OD-EMAIL-DEV,
> ADR 0060 §31).** E17 is now **FINAL PROVIDER/PRIVACY REVIEW DEFERRED TO
> PRE-PRODUCTION / DEVELOPMENT AUTHORISED**. This request is kept as
> pre-production material. It is **not sent during development**, and no
> determination is requested now.
> - **Before it is sent:** refresh its provider evidence (reviewed
>   2026-10-13) against then-current Twilio terms, and update its data
>   inventory to the application's actual email flows at that time.
> - **What still waits on the answer:** production external email, real
>   School personal data reaching the processor, and an email
>   production-ready claim.
> - **What no longer waits:** application development, including E18,
>   which OD-E18-DEV already authorised for development (ADR 0060 §28.1).
>   The "What waits on the answer" list below is historical.

**Status at drafting (2026-10-13): REVIEW REQUEST — PUBLISHED IN THE
REPOSITORY, NOT SENT, NOT ANSWERED.** This document asks questions and
supplies evidence. It records no determination, and nothing in it may be
read as one. SendGrid is **not** legally approved, no Data Processing
Addendum is known to be executed, and no Lycenza account exists.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
  (the reviewer role of the TCH-L1 and RES-L0 determinations).
- **From:** the product owner, Lycenza School OS.
- **Register item:** ADR 0058 row **E17** ("Email provider selected +
  legal/processor review"), status `LEGAL_REVIEW_REQUIRED`.
- **Engineering contract:**
  `docs/architecture/adr/0060-transactional-email-provider-selection-integration-contract.md`
  (§19 data sent, §20 the four review questions, §22 the E18 scope);
  `docs/architecture/adr/0055-production-email-deliverability-contract.md`
  (§21 classification, §23 definition of done).
- **What waits on the answer:**
  - **E18** — Phase 0O.13A, the SendGrid adapter. ADR 0060 §20: E18 "does
    not start" until the outcome is recorded;
  - **any production use of SendGrid**, and so the email part of O1
    (ADR 0058 E17–E20).

## 1. What is being asked, in one paragraph

Lycenza has selected Twilio SendGrid, on engineering grounds, to deliver its
transactional email: account invitations, password recovery, security
notices and School announcements. Before any adapter is built or any real
School data reaches SendGrid, Lycenza needs a qualified determination on four
questions (ADR 0060 §20):
- **A.** processor acceptability and the DPA;
- **B.** the sub-processor posture;
- **C.** international transfer and data residency;
- **D.** provider-side retention of message and event metadata, against
  Lycenza's own retention policy (E21).

Two supporting questions follow from them: **Q0**, the governing law, and
**E**, which content categories may be sent. Section 2 gives the
repository's facts, section 3 the provider's published facts, and section 4
the questions.

## 2. Repository facts — what Lycenza would send, generate and keep

Every statement here is taken from the repository at `b42cbbc6`, not from
general knowledge of email services.

### 2.1 The email layer

- **One transport boundary.** All application email goes through
  `App\Support\Email\OutboundEmailGateway`. No business code calls a mail
  framework directly (CLAUDE.md rule 89; ADR 0055).
- **Closed purposes.** There are five, set in code and never by a School or
  a request (`App\Support\Email\EmailPurpose`). No marketing or bulk
  commercial mail exists in v1.
- **One message, one recipient.** There is no Cc or Bcc, no Reply-To, and
  no School return path.
- **Sender identity.** The From address is `notifications@<MAIL_SENDING_DOMAIN>`,
  a Lycenza-controlled subdomain, never a School domain. The display name is
  `"<School name> via <platform name>"`, sanitized (ADR 0055 §8).
- **Tracking is off** for both the account and every message: no open
  pixel, no link rewriting, no subscription or Google Analytics tracking
  (ADR 0060 §10). If the E18 sandbox shows rewritten links or a pixel
  anyway, SendGrid is rejected (ADR 0060 §10).
- **Transport.** E18 will use one HTTPS sending path, the SendGrid v3 Mail
  Send API, with a closed host set: `api.sendgrid.com` (global) or
  `api.eu.sendgrid.com` (EU subuser). The region is chosen by configuration
  (ADR 0060 §6).
- **Delivery evidence comes back** through SendGrid's signed Event Webhook,
  normalized into a closed internal vocabulary (ADR 0060 §11–§16).
- **Today:** no SendGrid code, account, key, DNS record or webhook exists.
  The deployed provider setting defaults to `none`, meaning email is
  disabled.

### 2.2 Which workflows would use SendGrid

These are the only five routes to the email layer in the repository.

| Purpose (`EmailPurpose`) | Producer | Recipient | Content (from the templates) | Risk context |
|---|---|---|---|---|
| `account_invitation` (critical) | `AccountInvitationService` / `GuardianInvitationEmailSource` | A **Guardian** being invited to the School's portal | Guardian's **first name**, School name, a one-time acceptance link with its expiry | Personal data of a parent; the link is Highly Sensitive until used or expired. It reveals that the person is a Guardian at that School |
| `staff_account_invitation` (critical) | `StaffInvitationService` | A **staff member** invited by a School | School name, platform name, a one-time acceptance link (secret in the `#fragment`), expiry. No role is named | Authentication material; reveals staff affiliation with the School |
| `account_recovery` (critical, identity-level, no School) | `AccountRecoveryIssuer` | Any **account holder** who asks to reset a password | A one-time reset link (secret in the `#fragment`) valid for a few minutes. No School, role or MFA detail | Authentication material (Highly Sensitive while valid) |
| `security_notice` (critical, identity-level, no School) | `SecurityNoticeService` | The **account holder** whose password changed | Time of change; "signed out everywhere". No link | Security information |
| `school_communication` (standard) | Communication Hub `EmailChannelDriver`, reached only from **announcements** (`AnnouncementService`) | **Staff, Students and Guardians** in the School's chosen audience: an individual, school-wide, a Student, a Guardian, Guardians of Students, a grade, a section or a subject offering | **Staff-authored free text**: the announcement title is the subject and its body is the email body, with optional **attachments** of up to 8 MB in total. Emergency announcements included | **Unconstrained content.** A School author can write anything, including children's data, fee and payment information, or staff matters. Students may be minors. Guardian and Student email preferences and consent are checked before sending |

**Not routed to email in the repository:**
- fee receipts, statements and reminders;
- admissions decisions;
- payslips and HR notices;
- attendance alerts;
- marks and results;
- Guardian portal replies (in-app only).

None of these has an email producer. Such information can reach SendGrid
**only** if a School author writes it into an announcement or attaches it.
Adding a dedicated email producer for any of them would be a **new data
class** for re-review purposes (§7).

### 2.3 Data items

Key:
- **"Sent"** means Lycenza transmits the item to SendGrid.
- **"Generated"** means SendGrid creates it.
- **"Local"** means what the Lycenza database keeps (tables `email_messages`,
  `email_events`, `email_provider_references`, `email_suppressions`).
- Local retention is the E21-D2 project policy, pending ratification (§2.5).

| Item | Sent by Lycenza | Generated by SendGrid | Stored locally | Local retention | Class (`DATA-CLASSIFICATION.md`, ADR 0055 §21) |
|---|---|---|---|---|---|
| Recipient email address (exactly one) | Yes | Echoed in its event records | Yes, **encrypted** (`recipient_encrypted`) plus a hash | Deleted with the message, 180 days after its terminal state | Sensitive (personal data) |
| Recipient display name | **No.** The API sends only the address (ADR 0060 §6) | — | No | — | — |
| A personal name in the body | Only the Guardian's **first name** in a Guardian invitation, and whatever a School author writes in an announcement | — | Inside sealed content only | Sealed content is purged at submission, or within 72 hours | Sensitive |
| From display name `"<School> via <platform>"` | Yes. The School's name reaches the processor | — | Yes (`from_display_name`) | 180 days | Internal |
| Subject | Yes | — | Yes, **plaintext** (`subject`) | 180 days (E21-D2 names the plaintext subject explicitly) | The class of the business content |
| Text and HTML body | Yes | SendGrid states it does not retain contents, but its deletion page says bodies are "retained temporarily" and random samples up to 7 days (§3.5) | Sealed content, encrypted | Purged at submission or pre-submission terminal state, and at `expires_at` (≤ 72 hours; ≤ link validity for critical mail) | The class of the business content. Links are Highly Sensitive until used or expired |
| Attachments (announcements only, never critical mail) | Yes (base64) | As for the body (§3.5) | Not in the email tables: a reference to the School's private storage object, which belongs to Communications/Documents retention | E21-D3 Communications | The class of the attachment (often Sensitive) |
| `Message-ID` header (`<email_messages.id>@<sending domain>`, a UUIDv7) | Yes | — | Yes (`rfc_message_id`) | 180 days | Internal. An **opaque internal row id** (see §2.4) |
| `Date`, `MIME-Version`, `Auto-Submitted` (critical mail) | Yes | — | — | — | Internal |
| School id, User id, Student id, Guardian id, membership id, `custom_args`, `categories`, template ids, tracking identifiers | **No** (ADR 0060 §6 "never set"; §19) | — | — | — | — |
| Restricted API key (authentication) | Yes, to SendGrid only | — | **Never** in the database, git, logs or images | — | Highly Sensitive |
| Provider message id (`X-Message-Id`) | — | Yes | Yes (`provider_message_id`, `email_provider_references`) | 180 days | Internal |
| Event id (`sg_event_id`) | — | Yes | Only as `sg:` + SHA-256 of it (`event_key`) | 180 days, or longer while a suppression references the event | Internal |
| Event type and time; bounce classification | — | Yes | Yes, normalized to a closed set (`email_events.type`, `bounce_class`, `occurred_at`) | 180 days | Internal; bounce and complaint facts are Sensitive |
| Recipient address, sending IP, SMTP response, `reason` text, `useragent` and any other raw event fields | — | Yes (see §3.7) | **No.** Raw provider payloads are not stored (ADR 0055 §21); the adapter keeps only the normalized fields above | — | — |
| Bounce / complaint / provider-suppressed facts | — | Yes | Yes, as a **fingerprint only** (HMAC of the address, current and previous key ring), never the address (`email_suppressions`) | Active: kept while active. Released: 1 year after release (E21-D2/E21.2B) | Sensitive |
| SendGrid's own suppression lists (bounces, spam reports, invalid addresses, unsubscribes) | — | Yes, kept by SendGrid | **Not imported or synchronized** (ADR 0060 §17) | Removed at SendGrid only by an operator, in a separate step | Sensitive (held by the processor) |
| Open / click data | — | Must not occur (tracking off) | Ignored if received; a closed log code only | — | — |
| Sending-domain DNS records (SPF, DKIM, DMARC) | Published by the operator | DKIM keys hosted by SendGrid | — | — | Public |

### 2.4 Data minimisation review

| Check | Finding | Classification |
|---|---|---|
| Internal database ids | Not sent, with one exception: the RFC `Message-ID` header is `<email_messages.id>@<sending domain>`. That is a UUIDv7 row id, opaque, carrying no School, User or Student id, but encoding its creation time. Every email in transit carries a Message-ID; the receiving mailbox sees it too | **Future hardening** (optional): a random per-message id would remove the timestamp. Not an E17 blocker; for the reviewer's awareness |
| Student ids | Not sent | Minimised by contract |
| Guardian ids | Not sent | Minimised by contract |
| School identifiers | The School **id** is never sent. The School **name** is sent in the From display name and appears in invitation bodies, which is inherent to the purpose | E17 question (Q-E): accept the School name reaching the processor |
| Personal names | No recipient display name. The Guardian's first name appears in the Guardian invitation body | E17 question (Q-E). An **E18 implementation condition** is already fixed: the `to` carries the address only |
| `custom_args` / `categories` | Never set (ADR 0060 §6) | Minimised by contract; **E18 implementation condition** (already in §22's frozen scope via §6) |
| Message bodies | Identity mail is template-fixed and minimal. **Announcements are staff-authored free text, with attachments**, and may contain children's, financial or staff information | **E17 legal/privacy concern** (Q-E). Possible conditions: allowed categories, an author notice, or an attachment restriction. Any product control the answer requires is **future hardening**, not E18 |
| Secrets in links | The recovery and staff-invitation secrets sit in the URL `#fragment`, inside the body SendGrid transmits. Tracking is off, so links are not rewritten | **E17 question** (ADR 0060 §19 "legal review must accept this"). Bounded by short link lifetimes (ADR 0055 §9.4, ADR 0056, ADR 0059) |

### 2.5 Local retention (E21) and what Lycenza cannot force at SendGrid

- **Lycenza's own copy** is governed by **E21-D2**
  (`docs/security/E21-RETENTION-DETERMINATION.md`), a project-adopted
  policy that is **not** legally ratified. E21 stays
  `LEGAL_REVIEW_REQUIRED` and the E21-L1 qualified decision is
  unanswered. The policy:
  - sealed content: purged at submission, or within 72 hours;
  - message metadata, attempts and processed events, including the
    plaintext subject: **180 days** after the terminal state
    (`MAIL_RETENTION_DAYS=180`, `platform:email-prune`);
  - suppressions: kept while active; released ones **1 year** after
    release (`platform:email-suppressions-prune`).
- **What Lycenza cannot force at SendGrid.** Local deletion does not delete
  SendGrid's own records. Lycenza deletes its rows; SendGrid keeps,
  under its own terms (§3.5):
  - event and activity metadata;
  - its suppression lists;
  - data on backups until its own deletion schedule.

  Provider-side retention therefore **survives local deletion** unless the
  DPA, an account setting or an API deletion covers it.
- **Effect on E21.** Processor-side retention is a separate fact from E21's
  local periods. This request asks counsel (Q-D) whether it is acceptable
  and whether it needs a condition, notice or register entry. The answer may
  feed E21's qualified ratification, but **E17 does not reopen E21
  engineering**, and E21's ratification stays independently open.

### 2.6 Account and security posture (for context; nothing here is built)

- **Sending key.** One **restricted API key with Mail Send only**. It is
  held in the production secret store (ADR 0058 **E08**, not yet selected)
  and injected only into the notifications worker. It is never in git,
  images, the database, logs, audit or errors (ADR 0060 §8).
- **Separate credentials** for sandbox and production. A **separate
  operator credential** for suppressions, webhooks and the domain never
  reaches a runtime process.
- **Signed Event Webhook.** ECDSA over timestamp + raw body, verified
  against an operator-installed public key ring (current + previous),
  with ±300 s timestamp tolerance. Nothing is parsed before
  authentication. Events are deduplicated by `sg_event_id` (ADR 0060
  §11, §14).
- **Rotation and emergency revocation.** Both are documented (ADR 0060 §8,
  §18).
- **Redaction.** The API key, verification keys and raw payloads never
  reach logs, errors or audit (an E18 requirement, ADR 0060 §22 item 10).
- **Provider console access** is limited to authorized operators.

## 3. Provider facts — what SendGrid currently documents

All sources were reviewed on **2026-10-13** from Twilio/SendGrid primary
pages. Contractual pages (the DPA, the sub-processor list, the Privacy
Notice, the Security Overview) outrank documentation and support pages,
and those outrank marketing pages. These are **facts as published**, not
legal conclusions. They can change, which is why §7 lists re-review
triggers.

### 3.1 Data Protection Addendum

**Source:** Twilio Data Protection Addendum —
`https://www.twilio.com/en-us/legal/data-protection-addendum`, "Last
Updated: April 9, 2026".

| Fact | Limitation |
|---|---|
| **No signature block.** The DPA "forms part of the agreement(s) between Customer and Twilio covering Customer's use of the Services". Schedule 3 says "By entering into the Agreement, Data Exporter is deemed to have signed" the EU SCCs | Whether accepting the Terms of Service is enough execution for Lycenza is **Q-A.3** |
| **SendGrid is covered.** Schedule 1 §6.2 defines "SendGrid Services" | — |
| **Twilio's roles.** Twilio acts as **processor/sub-processor** for Customer Content and Customer Personal Data (§2). It is an **independent controller** of: Customer Account Data (§3.2); **Communications Usage Data** (§3.3); and Customer Content "to the extent necessary" for listed purposes (§3.4). Verified verbatim on 2026-10-13 | Delivery and event metadata may fall within Twilio's own controllership. **Q-A.1 / Q-D** |
| **SendGrid deletion.** Schedule 1 §6.2: "at Customer's election, delete or return to Customer any Customer Content". Back-ups are deleted "one (1) year after the termination effective date". §9.2 allows retention where law requires it | The general (non-SendGrid) terms differ: 30 days for stored content, 60 days for back-ups |
| **Changes to the DPA** need at least 30 days' written notice (§13.4) | — |
| **Applicable law.** The defined list (Australia, Brazil, Canada, the EEA, Israel, Japan, Mexico, Singapore, Switzerland, the UK, the US, "without limitation") **does not name India or the DPDP Act**. Children's data is not mentioned. The customer is responsible for safeguards for Sensitive Data (Schedule 1) | The last ~4 KB of the page were not reviewed |

### 3.2 Sub-processors

**Sources:** Twilio Sub-processors —
`https://www.twilio.com/en-us/legal/sub-processors`, "Last Updated:
September 2026"; DPA §6.2–§6.3; Security Overview —
`https://www.twilio.com/en-us/legal/security-overview`, April 9, 2026.

| Fact | Limitation |
|---|---|
| **SendGrid email** is routed and transmitted through **AWS**, in the USA and the EU | — |
| **SendGrid hosting and storage** is at **DataBank** (North America), **Lumen** (North America) and **Digital Realty** (North America/EU). The Security Overview says the same | — |
| **Twilio-wide entries:** Twilio Inc. (USA); AWS core hosting (USA, EU, AU); Clickhouse (USA, EU). **Support tools:** Plain (UK) for tickets and Slack (USA) for chat | Support staff locations are not stated |
| **No Indian entity or location** is listed | — |
| **Change notices.** Customers can subscribe to change notifications. The DPA requires notice ≥ 30 days before a change of infrastructure provider and ≥ 10 days for other sub-processors. Customers may object "in writing and based on reasonable grounds relating to data protection"; without an objection, the change is deemed authorized | Who subscribes and monitors for Lycenza is **Q-B.2** |

### 3.3 Data residency

| Source | Date | Fact | Limitation |
|---|---|---|---|
| Data Residency, `https://www.twilio.com/docs/sendgrid/data-residency` | modified 2026-07-17 | EU residency stores and processes "email recipients' personally identifiable information (PII), email content, and event data" in EU data centres | Documentation, not contract |
| FAQ on Data Residency for EU, `https://www.twilio.com/docs/sendgrid/data-residency/faq` | none shown | "Only the EU can use Data Residency." **No India region.** A dedicated EU IP is required; shared-IP customers must upgrade to a Pro or Premier package. EU subusers lack Marketing, Activity, Validation and Geostats | Documentation |
| Same FAQ | — | **Exceptions:** support requests and some settings can involve processing outside the EU; engineering may access data for troubleshooting; "some processing instances, such as retrieval, may leave the region" | Documentation |
| Privacy Notice, `https://www.twilio.com/en-us/legal/privacy` | April 9, 2026 | "our primary processing facilities are in the U.S." The **global** (default) region is the United States | Twilio-wide statement |
| EU subuser API guide (`…/subusers-api/how-to-create-a-eu-regional-subuser-with-the-twilio-sendgrid-api`) | — | An EU subuser sends through `api.eu.sendgrid.com`; requires Pro or above with a dedicated IP | Seen only in a search snippet: **NOT VERIFIED** on the page |

### 3.4 International transfer mechanisms

| Fact | Limitation |
|---|---|
| DPA Schedule 3 lists, in order: the EU-US Data Privacy Framework (with the UK Extension and Swiss-US DPF); Twilio BCRs; the EU SCCs (2021/914); the UK International Data Transfer Addendum; Brazil SCCs; Global CBPR/PRP | — |
| **The BCRs do not cover SendGrid.** Schedule 3 §2.3: "the Twilio BCRs do not serve as a transfer mechanism for the SendGrid Services". Verified verbatim on 2026-10-13 | So SendGrid relies on the DPF and/or the SCCs |
| The Privacy Notice says Twilio Inc. complies with the EU-US DPF, the UK Extension and the Swiss-US DPF | SendGrid is not named. The live DPF list entry is **NOT VERIFIED** (the register did not render) |
| **India:** no Twilio document found addresses DPDP Act §16 or Indian cross-border transfer | Not found is not proof of absence. India-specific service terms were **NOT REVIEWED** |

### 3.5 Retention by SendGrid

| Source | Date | Fact | Limitation |
|---|---|---|---|
| "How to Find the Body or Contents of Emails", `https://support.sendgrid.com/hc/en-us/articles/18961023703963` | undated | "Twilio SendGrid does not retain the contents of emails sent through our platform". Delivery data (addresses, timestamps, status) is kept | Support page |
| "Requesting Account Data Deletion", `https://support.sendgrid.com/hc/en-us/articles/18017175645595-Requesting-Account-Data-Deletion` | undated | Account data is "typically deleted 60 days after closing an account". Customer Content is deleted 30 days after termination. "email message bodies are retained temporarily". Other personal data, including recipient data, is kept "for a maximum of 37 days". **Event data is kept "in pseudonymized form for up to a year".** "random content samples may be held for seven days" | Support page. **It partly conflicts** with "does not retain the contents" and with the DPA's 1-year SendGrid back-up term. **Q-D** |
| Email Activity Feed, `https://www.twilio.com/docs/sendgrid/ui/analytics-and-reporting/email-activity-feed` | 2026-01-22 | The paid "Email Activity history add-on" keeps events for up to 30 days | The default period without the add-on is **NOT VERIFIED** |
| Getting started with the Event Webhook, `https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook` | 2026-07-23 | Retries continue "up to 24 hours after the event occurs" | — |
| Mail Settings, `https://www.twilio.com/docs/sendgrid/ui/account-and-settings/mail` | 2026-07-23 | A configurable **Bounce Purge** deletes bounces after 1–3,650 days | Default suppression retention is **NOT VERIFIED** ("kept until removed" is not stated) |
| Delete a global suppression, `…/suppressions-global-suppressions/delete-a-global-suppression` | 2026-07-17 | `DELETE /v3/asm/suppressions/global/{email}` | — |
| Erase Recipients' Email Data, `…/recipients-data-erasure-api/erase-recipients-email-data` | — | `POST /v3/recipients/erasejob`, up to 5,000 addresses per call, erases "names, email addresses, subject lines, categories, and IP addresses". Does not cascade to subusers | Seen in a search result; page date not checked |
| Security Overview | April 9, 2026 | "Twilio retains security logs for one hundred and eighty (180) days" | Twilio-wide |
| Unverified third-party claim | — | Bodies held up to 72 h for retries, and 6 days for scheduled sends | The Twilio page returned HTTP 403; seen only on third-party mirrors. **NOT VERIFIED; not relied on** |

### 3.6 Security attestations

| Fact | Limitation |
|---|---|
| Security Overview "Covered Services": ISO/IEC 27001, 27017 and 27018 cover "Twilio Services, Segment Services". SOC 2 Type 2 covers "All Services" | **ISO does not list SendGrid** as covered. This answers ADR 0060 §20's open point |
| SendGrid uses "opportunistic TLS v1.1 or higher"; enforced TLS is a customer opt-in | A sending-side setting the reviewer may wish to condition |
| The Trust Center (`https://security.twilio.com/`) offers, on request: the SOC 2 report, a SendGrid penetration-test report, a SendGrid DR test report and a SendGrid privacy/security datasheet | Report dates and SOC 2 scope detail not seen |

### 3.7 Event Webhook fields SendGrid generates

**Source:** Event Webhook reference —
`https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event`,
modified 2026-07-09.

- **Every message event** carries `email` (the recipient address),
  `sg_event_id`, `sg_message_id`, and any custom args echoed back. Lycenza
  sends none of those custom args.
- **`delivered`** carries `ip` (described as the **sending** IP),
  `response` and `tls`. **`deferred`, `bounce`/`blocked`, `dropped`** carry
  `reason`/`status`.
- **`open`/`click`** would carry the **recipient's** IP and user agent.
  They require open/click tracking to be turned on, which Lycenza never
  does. The page states no explicit "no events when disabled" rule, so
  ADR 0060 §21 gate 5 tests it.
- **Asynchronous bounces have no `sg_message_id`.** That is an engineering
  consequence for E18 (ADR 0060 §27), not a legal question.
- Lycenza keeps **none** of the raw fields. It stores only the normalized
  type, time, bounce class, hashed event id and provider message id
  (§2.3).

### 3.8 Children, education and India

- **Privacy Notice.** "Our Services are not directed to or intended to be
  used by children". This concerns Twilio's own account holders, not
  recipients.
- **Not found** in the DPA, Privacy Notice, sub-processor list or Trust
  Center:
  - any education-sector terms;
  - any India-specific SendGrid terms;
  - any DPDP reference.
- **India-specific service terms:** **NOT REVIEWED**.

## 4. Questions for counsel / DPO

Please answer each question separately. Where the answer depends on a fact
in §3 that is marked NOT VERIFIED, please say what evidence would settle it.

**Q0 — Governing law and customer scope.** Which data-protection law(s)
apply to this processing? For example, India's Digital Personal Data
Protection Act 2023 and its Rules, and any other jurisdiction of Lycenza's
customers. Does any customer outside India change the answers below?
ADR 0060 §2 deliberately did not infer this.

**Q-A — Processor acceptability and the DPA.**
1. Is Twilio SendGrid acceptable as Lycenza's processor (and, where a
   School is the data fiduciary or controller, as Lycenza's
   sub-processor) for the purposes in §2.2?
2. Is the Twilio Data Protection Addendum (§3.1) acceptable as it stands?
3. Must anything be executed, signed, countersigned or accepted before
   production use, beyond accepting the Terms of Service? Must it also be
   done before a non-production account is opened (§5, OD-1)?
4. Do Lycenza's own controller/processor documents need updating: the
   School contract or DPA, a sub-processor list for Schools, the privacy
   notice, or the processing register?
5. The DPA makes Twilio an **independent controller** of Communications
   Usage Data, Customer Account Data and some Customer Content (§3.1).
   Is that acceptable for School recipients' delivery metadata, which
   includes Students and Guardians? Does it need disclosure or a
   condition?

**Q-B — Sub-processors.**
1. Is the SendGrid / Twilio sub-processor posture (§3.2) acceptable for
   this use?
2. What review or notification is required when Twilio changes its
   sub-processors? Who monitors the list, and how often?
3. Does any listed sub-processor or location create a production
   restriction?

**Q-C — International transfer and residency.**
1. Which processing region(s) are permitted: global (United States), EU
   (via an EU subuser), or another?
2. Is SendGrid's documented residency posture (§3.3), including its stated
   exceptions, acceptable?
3. Must production use the EU regional option where available?
4. Are the documented transfer mechanisms (§3.4) sufficient for the
   jurisdiction(s) in Q0? Note that the Twilio BCRs expressly do **not**
   cover SendGrid, the DPA's applicable-law list does not name India, and
   no India region exists.
5. If SendGrid cannot meet a required region or transfer condition, must
   production move to the documented fallback, Amazon SES (ADR 0060 §5,
   §25)? A move to SES would itself need a fresh owner decision and an
   ADR 0060 amendment; it is never automatic.

**Q-D — Provider-side retention.**
1. Is SendGrid's retention of message and event metadata, suppression
   lists and backups (§3.5) acceptable? This includes event data kept "in
   pseudonymized form for up to a year", random content samples up to 7
   days, and back-ups for 1 year after termination. Which provider
   statement governs where the support pages and the DPA differ?
2. Is it compatible with Lycenza's E21-D2 policy (§2.5)? Is there a
   maximum period it must not exceed?
3. Must any account-level retention or configuration be changed? For
   example, the Email Activity history add-on must not be purchased, a
   Bounce Purge period must be set, suppression lists must be periodically
   cleared, or the recipient erasure API (§3.5) must be used when Lycenza
   erases a person.
4. Must the event webhook data be further minimised, or any event type
   disabled? Lycenza already stores no raw payload, address or IP from
   events.
5. Can Lycenza's local deletion coexist with unavoidable processor-side
   retention? If so, under what notice, register entry or contractual
   condition?

**Q-E — Content categories (from §2.2 and §2.4).**
1. May each purpose in §2.2 be sent through SendGrid?
2. For staff-authored **announcements** (free text, attachments, Student
   and Guardian audiences, possibly minors): are any categories
   prohibited by email? Examples are children's health or disciplinary
   information, marks, fee arrears and staff HR matters. Is a School
   author notice or an attachment restriction required?
3. Is the transmission of one-time authentication links in the body
   acceptable, given the short link lifetimes and tracking off (ADR 0060
   §19)?
4. Is sending the School's name in the sender display name and in
   invitation bodies acceptable?

**Q-F — Re-review triggers.** Please list the events that require
re-review. Lycenza proposes those in §7.

## 5. Owner decisions (not for counsel)

These are product and provider choices the owner makes. They are recorded
here so counsel can see them. None is a legal determination.

- **OD-1 — Non-production SendGrid account before the E17 outcome:
  AUTHORISED (owner decision, 2026-10-13).** It is the open question of
  ADR 0060 §23; the conditions and effects are recorded in ADR 0060 §27.
  - **Purpose.** Only the §21 sandbox gates and starting the E19
    aligned-DKIM observation clock, so that at least 14 days are not lost
    after E17.
  - **No real data.** No production School data, and no real
    Student, Guardian or staff personal data. Recipients are synthetic or
    operator-controlled test mailboxes only (ADR 0060 §23 step 7).
  - **No production traffic.** No production or customer mail. The domain
    authentication of `MAIL_SENDING_DOMAIN` and non-production observation
    traffic of ADR 0060 §23 are in scope, because that is what the E19
    clock measures.
  - **Credentials.** No production secrets. Isolated non-production
    credentials, deleted or rotated if the provider decision changes.
  - **Labelling.** The account or subuser is clearly labelled
    non-production.
  - **Not implied.** No legal clearance, no E18 start and no production
    enablement.
  - **Commercial act.** Opening the account accepts SendGrid's terms. The
    owner accepts that as a commercial act. The account is opened by an
    authorized operator under rule 16, as a separate action.
- **OD-2 — SES fallback.** Refusal of SendGrid does **not** activate SES
  automatically. It needs a fresh owner decision and an ADR 0060 amendment
  covering SNS certificate pinning and the subscription handshake (ADR 0060
  §5).
- **OD-3 — Plan tier and region purchase** follow the Q-C answer. EU
  residency needs an upper-tier plan and an EU subuser (§3.3).

## 6. Possible outcomes and their effect

| Outcome | E18 development | Non-production (sandbox) use | Production (real School data) |
|---|---|---|---|
| **APPROVED** | May start once ADR 0060 records the determination | Continues under OD-1 | Permitted after the rest of O1 (E19, E20, E08, E09, E02…) |
| **APPROVED WITH CONDITIONS** (e.g. execute the DPA, use a named region, configure or limit retention, update notices or the register, prohibit data classes, periodic sub-processor review) | May start when the conditions affecting development are met or scheduled; each condition is mapped to E18, deployment or product hardening | Continues under OD-1 unless a condition restricts it | Only after every production condition is evidenced |
| **NOT ACCEPTABLE** (SendGrid refused) | Does not start | Stops; sandbox credentials deleted | Not permitted. A fresh owner decision on the SES fallback (OD-2) follows; E17 restarts for the new provider |
| **MORE INFORMATION REQUIRED** | Does not start | Continues under OD-1 | Not permitted; E17 stays `LEGAL_REVIEW_REQUIRED` |

Sandbox permission (OD-1) is never production authorization.

## 7. Proposed re-review triggers

- a material change to the DPA or Terms of Service;
- a sub-processor addition or change affecting SendGrid email;
- a change of processing region or residency option;
- a change to, or invalidation of, a transfer mechanism;
- a material acquisition or restructuring of the provider;
- a **new data class** sent: for example a new email purpose, a fee, admissions,
  HR or marks email producer, `custom_args`, tracking, or a recipient
  display name;
- a change to SendGrid's retention of content or metadata, or any
  purchase of extended activity history;
- a security incident at the provider affecting Lycenza data;
- a change from SendGrid to SES or another provider.

## 8. Requested form of answer

For each of Q0, Q-A to Q-F:
- an outcome: **APPROVED / APPROVED WITH CONDITIONS / NOT ACCEPTABLE / MORE
  INFORMATION REQUIRED**;
- conditions, each marked as a development, sandbox or production
  condition;
- development (E18), sandbox and production permission, stated separately;
- re-review triggers, and any expiry;
- date, name and role.

## 9. Determination template

To be completed by the reviewer, or recorded verbatim from their answer.
Engineering never fills in or infers any field.

```
E17 DETERMINATION — Twilio SendGrid transactional email processor

Determination date:            ____
Reviewer (name, role):         ____
Governing law / scope (Q0):    ____

Q-A processor acceptability:   APPROVED | APPROVED WITH CONDITIONS | NOT ACCEPTABLE | MORE INFORMATION REQUIRED
Q-A DPA outcome:               ____  (executed? by whom, when: ____)
Q-A Lycenza documents to update: ____
Q-B sub-processor outcome:     ____  (monitoring duty: ____)
Q-C permitted region(s):       ____
Q-C transfer mechanism outcome: ____
Q-D provider metadata retention: ____  (maximum / settings / minimisation: ____)
Q-E content categories:        ____  (prohibited classes / author notice / attachments: ____)

Provider selection outcome:    SENDGRID APPROVED | SENDGRID APPROVED WITH CONDITIONS | SENDGRID REFUSED → SES FALLBACK ASSESSMENT
Conditions (each: development | sandbox | production): ____
Development effect (E18):      ____
Sandbox effect (OD-1):         ____
Production effect:             ____
Re-review triggers:            ____
Expiry (if any):               ____
```

## 10. Recording the answer (engineering instructions)

- Record the answer verbatim in a new dated
  `docs/security/E17-SENDGRID-PROCESSOR-DETERMINATION.md`.
- Add a dated **ADR 0060 amendment** that records the outcome and names the
  reviewer role, as §20 requires.
- Update ADR 0058 row E17, and E18 if it is authorized, by a dated,
  reviewed change, with a matching `PHASE-0O-READINESS.md` section.
- An ambiguous answer is never read as an approval. If material ambiguity
  remains, E17 stays open.
- **The event that authorizes E18** is that ADR 0060 amendment, recording an
  APPROVED or APPROVED WITH CONDITIONS outcome whose development conditions
  are met. Nothing else, including OD-1, authorizes it.

## 11. What this does not ask

- **Not asked here.** It does not ask counsel to choose a provider; the
  engineering selection stands (ADR 0060 §5). It does not ask about E21's
  local retention periods (E21-L1), SMS/WhatsApp or other channels (ADR 0057,
  E28), or the Guardian portal (E46).
- **Not changed.** It changes no code, configuration, retention setting or
  gate.
- **Separate decisions.** E08 (the production secret store) is an
  independent owner/provider decision. E17 does not wait for it, but
  production SendGrid credentials must use the E08 mechanism once chosen.
