# E19 — SendGrid domain authentication and 14-day aligned-DKIM observation record

**Classification: Confidential.** Operator entries for this row follow
[OPERATOR-EVIDENCE-RECORD](OPERATOR-EVIDENCE-RECORD.md) (`EVID-E19-<YYYY>-<n>`)
and its E29 rules. §5 and §8 cite those entries by id.

- **Register row:** ADR 0058 **E19** (Mandatory, `DEPLOYMENT_REQUIRED`).
- **Governing contract:** ADR 0055 §17; ADR 0060 §9, §10, §23, §27.3, §27.4;
  ADR 0060 §29 (this record's freeze).
- **Authority:** owner decision **OD-1** (ADR 0060 §27.3). It authorises a
  strictly non-production SendGrid account and the E19 observation only. It
  is **not** legal approval and authorises **no production mail**.
- **Opened:** 2026-10-13, on `origin/main` `9c0e147e` (E18 published; main
  `CI gate` green).

**Status: E19 — CONTRACT FROZEN / CLOCK NOT STARTED.** No SendGrid account,
DNS record, credential or observation message exists as of 2026-10-13. Every
operational step below is an operator action under rule 16. It is recorded
here only when it has actually happened.

## 1. Evidence hygiene (E29) — how this record names things

ADR 0058 §5 item 13 keeps secrets, credentials, **hostnames**, connection
strings and School data out of all evidence. This record therefore uses
placeholders, never real values:

| Placeholder | Meaning |
|---|---|
| `<MAIL_SENDING_DOMAIN>` | The Lycenza sending subdomain (ADR 0060 §9, conceptually `notify.<platform domain>`) |
| `<ORG_DOMAIN>` | Its organizational (registrable) domain |
| `<RP_LABEL>` | The custom return-path label (ADR 0060 §9) |
| `<SG_TARGET>` | A SendGrid-assigned CNAME target |
| `<RUA_MAILBOX>` | The operator-controlled DMARC aggregate-report mailbox |

- **Recorded here:** the DKIM selector label, record types, relative names,
  TTLs, key size, timestamps, result codes and counts.
- **Recipients** are named "operator-controlled mailbox A/B", never by
  address.
- **Kept outside the repository**, in the operator's evidence store: real
  hostnames, CNAME targets, the SendGrid user/subuser id, API-key ids,
  recipient addresses and raw DMARC XML.
- **Never anywhere:** API keys, passwords or the operator login.

## 2. The frozen clock contract

### 2.1 What is measured
At least **14 consecutive qualifying UTC days** of **100 % aligned DKIM
pass** in DMARC aggregate reports, at the observation policy (§2.5). Only
then is DMARC moved to `p=quarantine` and `MAIL_SENDING_VERIFIED` set (ADR
0060 §23 step 8; ADR 0055 §17).

### 2.2 Admission — when the clock may start
The clock starts only at a **clock-admission record** (§5). That record
names the exact UTC timestamp at which the **last** prerequisite of §5
became true. It never starts from account creation or DNS publication, and
it is never backdated.

### 2.3 Day numbering
- **Day 0** is the partial UTC day containing the admission timestamp. It
  is observed and recorded, but it **never counts**: its reports may cover
  pre-admission traffic.
- **Day 1** is the first complete UTC day after admission.
- Days are UTC calendar days, because the major reporters' aggregate
  `date_range` windows are UTC days.

### 2.4 The E19 stream and what qualifies
- **In-stream row:** a DMARC aggregate record row whose header From is
  `<MAIL_SENDING_DOMAIN>` and whose source is SendGrid or whose DKIM
  `auth_results` name the E19 selector.
- **Out-of-stream row:** any other row for `<MAIL_SENDING_DOMAIN>`, for
  example spoofing or an unauthorised sender. It is **recorded and
  reviewed, never deleted**. It does not count for or against the clock,
  because the subdomain has no other legitimate sender. A legitimate
  out-of-stream sender, if one is ever found, stops the clock for review.
- **Aligned DKIM pass:** `policy_evaluated/dkim = pass` **and** an
  `auth_results/dkim` entry with `d=<MAIL_SENDING_DOMAIN>`, the E19
  selector and `result = pass`.
- **A day qualifies** only when all of these hold:
  1. at least one qualifying observation message was sent that day (§6
     send log);
  2. at least one aggregate report with a ≤ 24 h `date_range` inside that
     day contains at least one in-stream row;
  3. **every** in-stream row attributed to that day is an aligned DKIM
     pass;
  4. no restart condition (§7) occurred that day;
  5. the §5 prerequisites still held for the whole day.
- **Longer reports:** a report whose `date_range` exceeds 24 h never
  qualifies a day by itself. Any in-stream failure in it still counts as a
  failure on every day it covers.
- **Missing evidence:** a day with no qualifying report is **not
  qualifying**. It breaks the consecutive run (§7.1). Late reports may
  complete a day only if they arrive within 72 h of the day's end.

### 2.5 Observation policy
`_dmarc.<MAIL_SENDING_DOMAIN>` at `p=none`, `adkim=r`, `aspf=r`, `pct=100`,
`rua=mailto:<RUA_MAILBOX>`, per ADR 0060 §9 / §23 step 5. **This is subject
to conflict C3 (§3):** E19 never weakens an effective policy that is already
stronger.

### 2.6 The result
E19's DKIM clock is satisfied only when the daily log (§8) has 14
consecutive qualifying days, Day 1 to Day 14, with no restart between them.
Fewer days is **CLOCK RUNNING / NOT YET COMPLETE**.

## 3. Conflicts found in the contract audit, and the interpretation used

| # | Conflict | Interpretation (safest compatible) |
|---|---|---|
| C1 | ADR 0055 §17 says "real traffic"; OD-1 forbids real or production traffic. | ADR 0060 §23 and §27.4, the later and more specific contract, define the clock over non-production observation traffic to operator-controlled recipients. Followed as written. Production traffic is never used to satisfy E19. |
| C2 | The E19 instruction asks for hostnames and values to be recorded; E29 forbids hostnames in evidence. | Placeholders here (§1); real values in the operator store. |
| C3 | ADR 0060 §23 step 5 says publish `_dmarc` at `p=none`; E19 must never weaken DMARC. If `<ORG_DOMAIN>`'s record already gives the subdomain `quarantine`/`reject` (through `sp=`, or `p=` with no `sp=`), a subdomain `p=none` record would weaken it. | **Audit first** (§4 step 6). If the effective subdomain policy is already ≥ `quarantine`, do **not** publish `p=none`. Publish `_dmarc.<MAIL_SENDING_DOMAIN>` at the **same** effective policy with `rua`, and observe there. Aggregate reports still show DKIM pass rates. The later `p=quarantine` step is then already met. Record that this happened. |
| C4 | ADR 0060 §23 step 7 names two sending paths (the hardened `smtp` adapter via SendGrid's relay, or the E18 adapter). Neither has a way to send recurring synthetic mail: there is no test-send command, and the purpose catalogue is closed at five (ADR 0060 §28.5). | **Open owner decision OD-E19-T (§6).** No code or purpose is added in this record. |
| C5 | ADR 0060 §9 says SendGrid rotates keys "when necessary" behind the CNAMEs, so a provider key change can happen without any Lycenza action. | It is not a restart by itself. It triggers a 2048-bit re-check that day, and any failure it causes is a failure (§7). |

## 4. Operator procedure (rule 16 — each step is an operator action)

Record each step's UTC completion time in §5 as it happens.

1. **Account (OD-1).**
   - Create the SendGrid account, or a subuser on an existing one, clearly
     labelled non-production.
   - Accepting SendGrid's terms is a commercial act the owner accepted
     under OD-1. It is **not** the E17 DPO determination.
   - Record: operator role, the plan tier, and that the label is
     non-production.
   - Load no contact list and no production recipient.
2. **Region (§9).** Record `global` or `eu`. This is not a residency
   decision.
3. **Credentials (ADR 0060 §8).**
   - Create a **restricted** API key with **Mail Send only** (Custom
     Access; no other scope).
   - Use a **separate** operator login or key for settings, domain and
     suppression work. It never reaches a runtime process.
   - Store both in the operator's secret store. Neither is ever in git, a
     screenshot, a log or this record.
   - Prove the restriction: list the Mail-Send key's scopes with the
     operator credential (`GET /v3/api_keys/{id}`), and record the count
     and that it is exactly `mail.send`.
   - Create no production key.
4. **Tracking off (ADR 0060 §10).**
   - Disable click, open, subscription and Google Analytics tracking with
     the operator credential (`/v3/tracking_settings/{click,open,
     subscription,google_analytics}`).
   - Record each `GET`'s `enabled` value (all `false`).
   - Confirm no Marketing Campaigns use, and no link branding.
   - Categories and `custom_args` are never sent. E18 sends neither (ADR
     0060 §28.5), and §6 forbids them in any observation path.
5. **Domain authentication (ADR 0060 §9).**
   - Domain `<MAIL_SENDING_DOMAIN>`, **automated security on**.
   - A **custom return-path label** `<RP_LABEL>`.
   - A **new custom DKIM selector**. It must not be `s1` or `s2`, and must
     not be a selector already published anywhere under `<ORG_DOMAIN>`.
     Check before choosing one.
   - Publish the CNAMEs SendGrid issues. Do not edit or remove any existing
     record of `<ORG_DOMAIN>`.
   - Record per record: purpose, type, relative name, TTL, time published.
6. **SPF and DMARC audit, before any DMARC change.**
   - Read `<ORG_DOMAIN>`'s SPF and DMARC, and any existing
     `_dmarc.<MAIL_SENDING_DOMAIN>`.
   - **SPF:** do **not** change `<ORG_DOMAIN>`'s SPF. SPF for SendGrid
     mail evaluates on the return path, which is the CNAME to SendGrid, so
     no `include:` and no second SPF TXT record is needed anywhere.
   - **DMARC:** apply §2.5 and conflict C3.
   - If `<RUA_MAILBOX>` is outside `<MAIL_SENDING_DOMAIN>`'s organizational
     domain, the receiving domain must publish the RFC 7489 §7.1
     external-destination record, or reporters will drop reports.
7. **Verification.**
   - Click "Verify" in SendGrid and record the result per record.
   - Run `platform:mail-verify-domain --json` from a non-production host
     configured with `MAIL_SENDING_DOMAIN`, `MAIL_RETURN_PATH_DOMAIN` and
     `MAIL_DKIM_SELECTOR`. Record the result codes only.
   - The command follows CNAMEs and returns `weak_key` below 2048 bits.
     **ADR 0060 §9: a key below 2048 bits stops E19.**
   - At `p=none` it exits non-zero, because DMARC is below `quarantine`.
     This is expected; record `spf`, `dkim` and `dmarc` individually.
   - Confirm externally with an independent resolver (`dig TXT`, `dig
     CNAME`) and record the time each record was first externally
     observable.
8. **Observation traffic (§6)** and the report pipeline. Then write the
   admission record (§5).

## 5. Clock-admission record

**Not admitted.** Fill each row with the UTC time it became true, plus a
safe evidence reference. The admission timestamp is the **latest** of these
times, and only when all rows hold **at the same time**.

| # | Prerequisite | Became true (UTC) | Evidence (safe form) |
|---|---|---|---|
| A1 | Non-production SendGrid account/subuser exists, labelled non-production (OD-1) | — | — |
| A2 | Region recorded (§9) | — | — |
| A3 | Restricted Mail-Send-only key exists; separate operator credential; no production key | — | — |
| A4 | Click, open, subscription and GA tracking all `false` (API `GET`s) | — | — |
| A5 | No categories or `custom_args` in the observation path (§6) | — | — |
| A6 | Domain authentication verified by SendGrid (automated security, custom return path) | — | — |
| A7 | New custom DKIM selector published (not `s1`/`s2`, not reused) | — | — |
| A8 | DKIM resolves externally; RSA ≥ 2048 bits (`platform:mail-verify-domain` `dkim=pass`) | — | — |
| A9 | SPF valid on the return path (`spf=pass`); `<ORG_DOMAIN>` SPF unchanged | — | — |
| A10 | DMARC active for `<MAIL_SENDING_DOMAIN>` per §2.5 / C3, with `rua` | — | — |
| A11 | At least one aggregate report has arrived at `<RUA_MAILBOX>` with an in-stream row | — | — |
| A12 | Evidence process running: the daily send (§6) and report parsing to §8 | — | — |
| A13 | No production mail sent; production refusal intact (§10) | — | — |
| A14 | No real School, Student, Guardian or staff personal data used | — | — |

- **Admission timestamp:** — (not admitted)
- **Day 1:** — (not started)

## 6. Observation traffic

### 6.1 Minimum design
- **Recipients:** at least **two** operator-controlled mailboxes, at
  **two different major mailbox providers** whose DMARC aggregate reporting
  is established (for example a Google-hosted and a Microsoft-hosted
  mailbox).
  - Preferred: mailboxes on a test domain the operator controls and that
    those providers host. `MAIL_SENDGRID_TEST_RECIPIENT_DOMAINS` can then
    name that domain alone.
- **Volume:** at least **2 messages per mailbox per UTC day**, spread
  across the day. That is enough for each reporter to produce an in-stream
  row every day, and small enough to stay a test stream.
- **Content:** one fixed synthetic template: no name, no School, no link,
  no attachment, and no one-time link.
  - Never Student, Guardian, employee, admissions, marks, attendance, fees,
    a real announcement or a production link.
- **Mode:** SendGrid **delivery** mode. Sandbox mode never delivers and
  produces no DKIM or DMARC evidence (ADR 0060 §28.3), so it cannot start
  or feed the clock.
- **Why delivery is permitted:** OD-1 explicitly scopes "§23's
  non-production observation traffic" to operator-controlled recipients,
  and DKIM alignment can only be observed on delivered mail.
- **Send log:** date, UTC time, recipient provider (never the address),
  SendGrid message-id prefix and the `202`. Kept by the operator; §8
  records daily counts only.

### 6.2 Open decision OD-E19-T — which sender (owner)
Conflict C4: neither §23 path can send recurring synthetic mail today
without either a staffed workflow or new code. The options:

| Option | What | Contract effect |
|---|---|---|
| **T1** | A non-production deployment of the current image with the E18 adapter in delivery mode; a synthetic demo School whose synthetic Guardians are the test mailboxes; an operator sends one fixed `school_communication` per slot | Inside §23 as written. Needs a non-production environment that stays up ≥ 15 days, and a person or automation per send |
| **T2** | An operator script calls SendGrid Mail Send directly with the restricted key, the fixed template, fixed recipients, and `tracking_settings` all `false` | DKIM signing is provider-side and identical (§23: "the same whichever Lycenza adapter submits"). It is not a named §23 path, so it needs a dated ADR 0060 note before use |
| **T3** | A new console command or email purpose for observation mail | Changes the closed purpose catalogue (ADR 0060 §28.5); needs its own ADR. Not recommended (rule 2) |

**Recommendation:** T2. It handles no application data at all, needs no
environment to stay up, and its tracking and recipient constraints are
fixed in one reviewed script kept with the operator's evidence. T1 is the
fallback if the owner wants the Lycenza adapter in the evidence path.
**Not decided in this record.**

## 7. Restart, pause and no-effect conditions

### 7.1 Rules
- **Restart (failure):** the consecutive count returns to 0. The next
  qualifying day is the new Day 1, provided §5 still holds. A short dated
  re-confirmation note in §8 says so.
- **Restart (re-admission):** the configuration under measurement changed.
  A new admission record (§5, all rows) is required, and Day 1 is counted
  from it.
- **Pause:** evidence is incomplete for a reason other than the
  configuration. The affected days do not qualify, so the run breaks
  exactly as for a failure. "Pause" only names the cause; it never carries
  a count across a gap.
- **No effect:** the run continues. The change is still recorded in §8.

Nothing is ever deleted or excluded from §8 to preserve the run.

### 7.2 The conditions

| Condition | Effect |
|---|---|
| An in-stream row that is not an aligned DKIM pass | **Restart (failure)** |
| A day with no qualifying report or no observation send | **Pause** (run breaks) |
| DKIM selector change, or Lycenza-initiated key rotation | **Restart (re-admission)** |
| SendGrid provider-side key rotation behind the unchanged CNAME (C5) | **No effect**, if the same-day 2048-bit re-check passes; otherwise restart |
| Account, subuser or region migration (including global → EU) | **Restart (re-admission)** |
| `<MAIL_SENDING_DOMAIN>` or `d=` change | **Restart (re-admission)** |
| A change of any DKIM or return-path CNAME target | **Restart (re-admission)** |
| Domain authentication re-created or re-configured in SendGrid | **Restart (re-admission)** |
| SendGrid domain verification lost (even if later restored) | **Restart (re-admission)** |
| Material DMARC change during the run: `p`, `sp`, `adkim` or `pct` | **Restart (re-admission)** |
| A DMARC `rua` change | **Pause** until reports arrive at the new destination |
| Tracking, categories or `custom_args` enabled at any time | **Restart (re-admission)** (A4/A5 lost) |
| Any production mail, or any real personal data | **Stop.** E19 is suspended and reported to the owner; this is outside OD-1 |
| A legitimate out-of-stream sender discovered | **Pause** for review |
| Switching the observation sender between T1 and T2 | **No effect** on DKIM (§23); recorded |
| E18 code changes | **No effect** on DKIM; recorded if deployed to the T1 sender |
| The planned move to `p=quarantine` **after** Day 14 | The intended outcome, not a change during the run |

## 8. Daily observation log

**No observation day exists.** Rows are appended only after a day has
ended and its reports have been reviewed. A future day is never written in
advance.

| Day | UTC date | Window | Selector | In-stream rows | DKIM auth | DKIM aligned | SPF (aligned) | DMARC | Messages sent | Out-of-stream rows | Failures | Changes | Qualifies |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|

## 9. Region and the EU residual risk

- **Region used:** — (not yet chosen; record at A2).
- **Recommended for E19, pending E17:** `global`.
  - E17 has not answered Q-C (residency), and there is no India region
    (ADR 0060 §27.2).
  - The EU option needs an upper-tier plan, an EU subuser and a dedicated
    EU IP (ADR 0060 §20).
  - Choosing EU now would anticipate a legal answer not yet given.
- **Residual risk (stated, not resolved):** if the E17 determination
  requires the EU SendGrid region and E19 was performed in the
  global/default region, counsel and architecture must decide whether the
  evidence remains acceptable. Unless the governing contract expressly
  preserves it, a materially different provider-region configuration is
  assumed to require requalification. ADR 0060 §27.4 already says domain
  authentication would be redone on an EU subuser with its own selector,
  and the clock would count only from that configuration.
- **A global-region clock is therefore not declared reusable.** §7.2
  treats a region migration as a re-admission restart.

## 10. Production boundary (unchanged by E19)

- **The production refusal stays in code** and is pinned by tests:
  - the boot guard (`mail_provider_sendgrid_not_authorised`,
    `mail_events_sendgrid_not_authorised`), in
    `ProductionConfigurationGuardTest`;
  - both resolvers and the adapter, in `SendGridEmailProviderTest` and
    `SendGridEmailEventWebhookTest`.
- **What E19 changes:** DNS and a provider account only. It changes no
  application code or configuration, so it cannot weaken those guards, and
  no new guard is needed.
- **Never in E19:** a production credential, and production traffic.
- **What E19 success does not do:**
  - authorise production;
  - close E17 (`LEGAL_REVIEW_REQUIRED`) or E20 (the drills);
  - satisfy E08 (secret store) or E02/O16 (qualification), which stay
    independent.
- **`MAIL_SENDING_VERIFIED`** is set only in the environment it describes,
  after §2.6 and the move to `p=quarantine`. A non-production attestation
  is not a production one.

## 11. Preserved for the DPO (E18 asynchronous bounces, unchanged)

ADR 0060 §28.4's behaviour stands until explicitly authorised. The
question is carried forward verbatim:

> May Lycenza transiently use the recipient address present in an unlinked
> asynchronous SendGrid bounce solely to locate and suppress the
> corresponding local recipient, without persisting that address or adding
> a new provider correlation identifier?

E19 neither answers nor depends on it. §21 gate 10, whether SendGrid lists
asynchronously bounced addresses, can be observed with the same account,
but it is E18/E20 evidence, not E19.
