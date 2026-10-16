# ADR 0074: Pre-Production / Staging Readiness Programme Contract (PPR.0)

- Status: **Accepted — PPR.0 CONTRACT PUBLISHED (2026-10-14,
  documentation only). PPR.1 STAGING / ENVIRONMENT CONTRACT — IMPLEMENTED
  (2026-10-14, §26; OD-PPR-1, OD-PPR-2 and OD-PPR-9 decided). PPR.2 —
  PROVISIONING PLAN COMPLETE / OPERATOR ACTION NOT STARTED (2026-10-14,
  §27). PPR.2D — PROVIDER, REGION AND IaC FROZEN (2026-10-14, §28:
  OD-PPR-3 = AWS, OD-PPR-24 = ap-south-1 Mumbai, OD-PPR-25 = Terraform).
  No staging infrastructure exists. OD-PPR-4 to OD-PPR-8, OD-PPR-10 to
  OD-PPR-23 remain open (§22, §27.6, §28).**
- Date: 2026-10-14
- Programme: **PPR — Pre-Production / Staging Readiness**
  (`MASTER-ROADMAP.md`, post-foundation programme 9).
- Baseline:
  - `origin/main` = `edfe9e67014af55f83670734b0ad9a54c0b30e50` (SWC.5).
  - It equals local `main` and `HEAD`.
  - The tree is clean, with one worktree and the historical stash untouched.
  - Ruleset `main-protection` 24909660 is active with no bypass actor, and
    the drift check is `ok`.
  - The exact-SHA `CI gate` (app 15368) and the post-publication `main` CI
    run 38627381631 are green.
- Revalidated:
  - SWC is **CLOSED / DEVELOPMENT COMPLETE** (ADR 0073 §20).
  - O1 is **RESOLVED AS DEFINITION OF DONE — NOT SATISFIED** (ADR 0058 §9).
  - Phase 0O stays open, and Phase 0M stays BLOCKED.
- Governing sources (read in full for this contract):
  - ADRs: 0058 (register E01–E47 and every note), 0050, 0051, 0052, 0053
    (rotation), 0055, 0060 (§21, §27–§31), 0063 §40–§44, 0067 §22/§31,
    0071, 0072 and 0073 §13/§20;
  - `PHASE-0O-READINESS.md`, `MASTER-ROADMAP.md`;
  - every runbook in `docs/operations/`;
  - `docs/security/` (E16 decisions, the E21 determination and closure
    audit, TCH-L1, the PAY-L1/POR-L1/SR-L1/E17 requests);
  - the code guards cited below.
- Amends nothing. No ADR 0058 row changes status. This ADR defines the
  programme that produces the outstanding O1 evidence, and the decisions
  that must come first.

**Classification used throughout:**

| Label | Meaning |
|---|---|
| **REQUIRED** | Required by an accepted contract (ADR 0058 register, ADRs 0050–0060, 0072) |
| **DECISION** | An owner (or legal) decision is needed before the work can be defined |
| **RECOMMENDED** | Not required by the repository; recommended, for owner adoption |
| **RISK** | An unplanned risk with no contract, registered here so it is not lost |

Nothing is REQUIRED merely because it is common industry practice.

## 1. The question PPR answers

> What exact evidence, decisions, infrastructure and operator actions are
> required to move from development-complete at `edfe9e67` to a
> production-qualified School System ERP?

**Answer in one line:** the ADR 0058 §5 definition of done (13 items), and
the 22 mandatory register rows still open, plus the decisions in §22.

**Scope.** PPR produces evidence for O1, within the register (§3) and
the RISK items the owner adopts (§12–§18). It is not a general hardening
programme.

**O1 closure is not go-live.** It authorises a separate production
deployment decision only (ADR 0058 §2, §8). Go-live, real School data, DNS
cutover and customer onboarding each stay a separate rule-16 authorisation.

## 2. Exact remaining O1 conditions (ADR 0058 §5)

| §5 item | Condition | Register rows | State at `edfe9e67` |
|---|---|---|---|
| 1 | Repository complete + full regression + O16 qualification on the closeout commit | E02, E18 | Repository complete except the email production enablement (E18 refused in code by design); E02 not started |
| 2 | `main` genuinely protected | E03 | **EVIDENCE_COMPLETE** (ADR 0072 §30). Production-stage PR + ≥ 1 independent approval + CODEOWNERS are still required **before E02** (ADR 0072 §16–§17) |
| 3 | Production-equivalent environment (ADR 0050) incl. S1 edge | E05, E08, E09, E10, E12 | Nothing exists |
| 4 | One real, isolated restore drill | E11 | **HARD BLOCKER**: `RESTORE-DRILL-RECORD.md` "No drill has been performed" |
| 5 | Real observability backend, retention, routing | E07 | No backend; no routing |
| 6 | Legal retention decisions for v1 data | E21 | Engineering complete; **qualified ratification pending**; E21-L1 §8 blank |
| 7 | Real email provider integrated, authenticated, drilled | E17–E20 | SendGrid built, dormant, refused in production; E19 clock not started |
| 8 | Account recovery drilled end to end, incl. the O9 exercise | E22, E23 | Not started; depends on 3 and 7 |
| 9 | Registry, signing, promotion | E13, E14, E15 | No registry; signing custody `unconfigured`; `retained-releases.json` empty |
| 10 | Staff/School-admin provisioning | E24 | **REPOSITORY_COMPLETE** |
| 11 | Enabled optional subsystems evidenced | E26–E28 | All CONDITIONAL_DISABLED; v1 launches with them off unless the owner enables one (§22 OD-PPR-17) |
| 12 | Vulnerability exceptions valid at qualification and promotion | E16 | Current through **2026-11-09** (§8) |
| 13 | Evidence hygiene | E29 | Rules exist; no record yet (§19) |

**Feature legal rows that also block O1:** E30, E31, E32, E33 and E34
("Blocks O1: Yes"). E45 and E47 are "Owner to decide". E35–E44 and E46 are
post-v1 and do not block O1.

## 3. Register classification (E01–E47)

**Columns** (Y = blocks, — = does not, C = conditional):
- **Start:** blocks starting PPR.
- **Env:** blocks creating the environment.
- **Stg:** blocks staging use.
- **UAT:** blocks a UAT.
- **ProdQ:** blocks production qualification (O1).
- **Launch:** blocks go-live.
- **Feat:** blocks only one feature.
- **post-v1.**

**"UAT"** here means School staff using staging. Synthetic data only
(§6); real School data is never a UAT input.

| Row | Status | Start | Env | Stg | UAT | ProdQ | Launch | Feat | post-v1 | Evidence / decision |
|---|---|---|---|---|---|---|---|---|---|---|
| E01 | REPOSITORY_COMPLETE | — | — | — | — | — | — | — | — | done |
| E02 | GOVERNANCE_REQUIRED | — | — | — | — | Y | Y | — | — | Fresh O16 qualification on the protected closeout commit after E03, E18 and E16; production-stage PR/approval/CODEOWNERS active first |
| E03 | EVIDENCE_COMPLETE | — | — | — | — | — | — | — | — | done (ADR 0072 §30) |
| E04 | REPOSITORY_COMPLETE | — | — | — | — | — | — | — | — | done |
| E05 | DEPLOYMENT_REQUIRED | — | — | Y¹ | Y | Y | Y | — | — | Real edge, `TRUSTED_PROXIES`, HTTPS detection, HSTS, client-IP limiting — operator |
| E06 | REPOSITORY_COMPLETE | — | — | — | — | — | — | — | — | done |
| E07 | DEPLOYMENT_REQUIRED | — | — | — | Y² | Y | Y | — | — | Backend, retention ≥ 30 d logs / ≥ 90 d metrics, rules loaded, test notification, OBS-20–23/26 — operator; backend vendor (OD-PPR-6), response owner (OD-PPR-7) |
| E08 | PROVIDER_REQUIRED | — | Y | Y | Y | Y | Y | — | — | Secret store selected (OD-PPR-4); per-process injection — owner + operator |
| E09 | DEPLOYMENT_REQUIRED | — | (is it) | Y | Y | Y | Y | — | — | ADR 0050 environment; `platform:verify-database`/`verify-storage` 0 failed — operator; hosting decision (OD-PPR-3) |
| E10 | DEPLOYMENT_REQUIRED | — | — | — | Y² | Y | Y | — | — | PITR + independent object copy active; freshness in the evidence file — operator |
| E11 | DEPLOYMENT_REQUIRED | — | — | — | — | Y | Y | — | — | **Hard blocker**: one real restore drill per ADR 0058 §4.5 — operator |
| E12 | DEPLOYMENT_REQUIRED | — | — | — | — | Y | Y | — | — | One Redis-loss reconciliation + one maintenance-window release — operator |
| E13 | PROVIDER_REQUIRED | — | — | Y³ | Y | Y | Y | — | — | Registry selected and authorised (OD-PPR-5) — owner + operator |
| E14 | GOVERNANCE_REQUIRED | — | — | Y³ | Y | Y | Y | — | — | Signing identity/key custody; `artifact-policy.json` set by a reviewed change (OD-PPR-5) — owner + security |
| E15 | DEPLOYMENT_REQUIRED | — | — | — | — | Y | Y | — | — | Signed, stored, VERIFIED, explicitly promoted digest; `retained-releases.json` entry; promoted-only deployment — operator |
| E16 | DECISION_REQUIRED (current to 2026-11-09) | — | — | — | — | Y | Y | — | — | Valid on every qualification/promotion date (§8); **time-bound** |
| E17 | LEGAL_REVIEW_REQUIRED (deferred) | — | — | Y⁴ | Y⁴ | Y | Y | — | — | Provider/processor determination; must precede any external delivery with real personal data |
| E18 | LEGAL_REVIEW_REQUIRED (dev implemented) | — | — | — | — | Y | Y | — | — | Production enablement only after E17, E08, E19, E20 (ADR 0060 §31.7) |
| E19 | DEPLOYMENT_REQUIRED (clock not started) | — | — | — | — | Y | Y | — | — | ≥ 14 qualifying days of aligned DKIM; **waiting window** |
| E20 | DEPLOYMENT_REQUIRED (deferred) | — | — | — | — | Y | Y | — | — | Email drills on a deployed environment with a live event feed |
| E21 | LEGAL_REVIEW_REQUIRED | — | — | — | — | Y | Y | — | — | Qualified ratification of D0–D13 + E21-L1 §8; production retention configuration (`E21-CLOSURE-AUDIT.md` §9) |
| E22 | DEPLOYMENT_REQUIRED | — | — | — | — | Y | Y | — | — | One non-production custom-domain exercise (ADR 0054 §14) — operator; non-production domain (OD-PPR-8) |
| E23 | DEPLOYMENT_REQUIRED | — | — | — | — | Y | Y | — | — | Account-recovery drills incl. two-host sign-out; needs E19/E20 (critical email) and E22 |
| E24 | REPOSITORY_COMPLETE | — | — | — | — | — | — | — | — | done |
| E25 | REPOSITORY_COMPLETE | — | — | — | — | — | — | — | — | done |
| E26 | CONDITIONAL_DISABLED | — | — | — | — | C | C | Y | — | AI Gateway: all ADR 0053 §15 evidence only if enabled |
| E27 | CONDITIONAL_DISABLED | — | — | — | — | C | C | Y | — | Production custom domains: ADR 0054 §14 only if enabled |
| E28 | CONDITIONAL_DISABLED | — | — | — | — | — | — | Y | Y | Deferred integrations stay fail-closed |
| E29 | GOVERNANCE_REQUIRED | — | — | — | — | Y | Y | — | — | Applied at every evidence record (§19); evidence store (OD-PPR-9) |
| E30 | LEGAL_REVIEW_REQUIRED | — | — | — | — | Y | Y | Y | — | Receipt form/GST answer — see §17 (payments cannot be "switched off") |
| E31 | LEGAL_REVIEW_REQUIRED | — | — | — | — | Y | Y | Y | — | Late fees — §17 |
| E32 | LEGAL_REVIEW_REQUIRED | — | — | — | — | Y | Y | Y | — | RTE free seats — §17 (no feature to disable) |
| E33 | DETERMINED — APPROVED WITH CONDITIONS | — | — | — | — | Y⁵ | Y⁵ | Y | — | Production-candidate re-verification (§18) |
| E34 | LEGAL_REVIEW_REQUIRED | — | — | — | — | Y | Y | Y | — | Library fines — §17 |
| E35–E44 | RES legal rows | — | — | — | — | — | — | Y | Y | Marks/results stay refused in code (StudentMark availability) |
| E45 | LEGAL_REVIEW_REQUIRED (request not sent) | — | — | — | — | owner | owner | Y | — | §18; statutory payroll not runtime-reachable (§21 F-1) |
| E46 | LEGAL_REVIEW_REQUIRED (request not sent) | — | — | — | — | — | — | Y | Y | Guardian portal refused in code outside local/testing |
| E47 | QUESTION DRAFTED | — | — | — | — | owner | owner | Y | — | §18 |

**Notes:**
1. Staging must sit behind a real edge, or the production guard refuses
   boot (`trusted_proxies_unsafe`) once OD-PPR-1 gives staging guard
   parity (§4).
2. UAT may proceed without E07/E10. RECOMMENDED: UAT only after basic
   monitoring and backups exist, so UAT findings and data loss are visible.
3. Under OD-PPR-1 (recommended), staging deploys only VERIFIED or
   promoted digests. That makes a registry and signing custody
   prerequisites for staging.
4. Only if staging delivers email externally or holds real personal data.
   The §6 policy forbids the latter, and §7 keeps the default a capture
   sink.
5. Legal question answered; production use waits for the §18
   re-verification.

**Nothing blocks starting PPR.** PPR.1 is decisions plus one bounded code
change (§4.4).

## 4. Staging / environment contract

### 4.1 Finding — `staging` is an undefined third state in code

Code knows exactly three environment names.

**Keyed on exactly `production`:**
- the boot guard: `AppServiceProvider.php:64`, `isProduction()` →
  `ProductionConfigurationGuard`, about 60 violation codes;
- the SendGrid refusal: `SendGridConfiguration.php:34`;
- the SendGrid event adapter: `EmailEventAdapterResolver.php:28`;
- the email reserved-domain and `MAIL_SENDING_VERIFIED` gates:
  `EmailProviderResolver.php:74-87`;
- HSTS: `ApplySecurityHeaders.php:74`;
- forced HTTPS URLs: `ClassifyRequestHost.php:96,108`;
- the `platform:verify-database` TLS failure:
  `DatabaseRoleVerifier.php:288-290`;
- the JSON log default: `config/observability.php:110`.

**Keyed on `local`/`testing` (development features):**
- `PortalAvailability`;
- StudentMark availability;
- `DevOnlySchoolHeaderResolver`;
- the teacher Attendance bearer API;
- domain fakes;
- the fake email and notification providers;
- the demo/internal routes;
- the demo seeder (`DemoEnvironmentGuard` refuses anything but DDEV
  `local`).

**Strict outside `local`/`testing`:**
- plaintext SMTP is refused (`SmtpEmailProvider.php:59`);
- service-key age is enforced.

**So with `APP_ENV=staging`** every development feature is off, as in
production, **but the production boot guard, HSTS, forced HTTPS and the
email production refusals do not run.** A staging deployment today would
silently lack the hardening that E05/E09/E23 evidence is meant to prove.

### 4.2 Decision required — OD-PPR-1 (environment model)

| Option | What it means | Assessment |
|---|---|---|
| **A** | Staging runs `APP_ENV=production` | Exact production code path, with no code change. But SendGrid and its event adapter are **refused** there, so the E20 drills (app-driven delivery and authenticated events) cannot run until the refusal is removed. ADR 0060 §31.7 orders removal **after** E20 (items 16 and 19), so A forces a contradiction. It also blurs which deployment is production. |
| **B (recommended)** | `staging` becomes a first-class value. Production hardening is ON and production-only authorisations are OFF: `ProductionConfigurationGuard` runs for `production` **and** `staging`, with a closed, enumerated staging-difference list; every `local`/`testing` feature stays off | Proves the same hardening, keeps "production" unambiguous, and allows E19/E20 in staging under ADR 0060's existing non-production rules (restricted key, allow-listed recipients). Needs one bounded code change (§4.4). |
| C | Leave `APP_ENV=staging` undefined | **Rejected.** Hardening silently off; evidence produced there would not prove production behaviour. |

**Name.** The production-equivalent environment without real School data
(ADR 0058 §3 "Deployment") **is** staging. One name covers staging,
pre-production and the qualification environment. ADR 0050 §16 made staging
"recommended, not required". Under OD-PPR-1 B, the owner makes it the
environment where PPR's deployment evidence is produced.

**Production itself** is still needed later for go-live and E33
re-verification (ADR 0063 §44.4 names `APP_ENV=production`). That is outside
PPR (§1).

### 4.3 The staging contract (applies once OD-PPR-1 is decided)

| Aspect | Staging |
|---|---|
| `APP_ENV` | `staging` (B) or `production` (A) — never anything else |
| Production guard | Runs in full (B: with only the §4.4 differences) |
| Development features (`local`/`testing` keyed) | Off — unchanged code |
| Real School data | **Prohibited** (§6) |
| Infrastructure | ADR 0050 model at production shape: edge, web, three workers, one scheduler, PostgreSQL 16 TLS, Redis auth, private versioned encrypted S3, runtime role (E09); its own database, Redis, bucket and secrets — shares nothing with production, DDEV or CI (ADR 0050 §16) |
| Images | Only VERIFIED digests, deployed by digest, never rebuilt (ADR 0052); promotion to production is a separate event |
| Email | Capture sink by default (§7); external delivery only inside the email slice |
| AI Gateway, custom domains in production, deferred integrations | Disabled (E26–E28) — except the one non-production custom-domain exercise (E22) |
| Credentials | Staging-only, from the same secret-store mechanism as production (OD-PPR-4), never shared with production |
| Operator actions | Rule-16 authorised per action (provisioning, DNS, provider accounts, drills, credential creation); evidence per §19 |

### 4.4 Bounded code change required by B (PPR.1, not PPR.0)

**Change only:**
- the boot guard runs for `staging` too;
- a closed, test-pinned staging-difference list. Candidates, each to be
  justified in PPR.1:
  - SendGrid and its event adapter permitted in their existing
    non-production mode (restricted key, mandatory
    `MAIL_SENDGRID_TEST_RECIPIENT_DOMAINS`);
  - `MAIL_SENDING_VERIFIED` not required before E19 completes;
- HSTS, forced HTTPS and the database-TLS verifier treat `staging` as
  `production`;
- a guard test pins `staging` membership in every production-keyed check.

**Not in this change:**
- any production refusal (§17);
- any `local`/`testing` relaxation.

**Defect class:** CLAUDE.md rule 15 (a new ADR amendment records it).

**Also in PPR.1:**
- **Staging email capture.** The capture sink (§7) must speak TLS:
  plaintext SMTP is refused outside `local`/`testing`.
- **Migration-rollback contradiction.** Reconcile it (§10.3).

## 5. `APP_ENV` recommendation

**Values:**
- `production` — production only.
- `staging` — the qualification environment (OD-PPR-1 B).
- `local`, `testing` — unchanged.

**Never** any other value: under B, the guard refuses an unknown
`APP_ENV`. That is a candidate check in §4.4, because an unset value already
defaults to `production` (`config/app.php:29`).

**Public status endpoint.** `/api/v1/system/status` publicly returns
`config('app.env')` (`SystemStatusController.php:25`). This is not a
contract breach, but PPR's security review (§13) decides whether to keep
disclosing it.

## 6. Staging data policy (default — stricter rule applies; OD-PPR-2 may only tighten it, or relax it with legal approval)

| Data | Rule |
|---|---|
| Real School data (any Student, Guardian, staff, admissions, finance record) | **Prohibited** (ADR 0058 §3, §8; rule 16) |
| Production-derived data, including anonymised or pseudonymised | **Prohibited** unless a qualified legal determination and an owner decision allow it. The repository has no anonymisation contract, and none is assumed. |
| Synthetic data | **Required.** Fictional people, generated by an approved method (OD-PPR-2: UI/API entry by testers, or a new staging seeder; the DDEV demo seeder refuses non-DDEV environments by design) |
| Email addresses | Operator-controlled test domains only for anything that may deliver; non-delivering reserved names (`example.test`) elsewhere. Never a real person's address (ADR 0060 OD-1) |
| Phone numbers | Fictional; no SMS, WhatsApp or push exists (E28) |
| Documents / uploads | Synthetic files only; never a real ID, certificate or photograph |
| Finance | Synthetic amounts; no real bank account, card or payment-provider credential (no gateway exists, ADR 0057) |
| Statutory identifiers (PAN, UAN, ESI numbers) | Synthetic, structurally valid only |
| Retention | Disposable. A reset or rebuild is allowed at any time, and backups of staging carry staging data only. On decommission the database, bucket (all versions), Redis and secrets are destroyed and recorded (E29) |
| Access | Named operators and testers only; no customer access. UAT participants see synthetic data only |

## 7. Email in staging

**Default: capture, never deliver** (preserves OD-EMAIL-DEV).
- Staging email goes to a non-delivery capture sink (Mailpit or
  equivalent) **over TLS**.
- ADR 0060 §31.4 allows a capture-sink staging without E17.

**External delivery from staging is allowed only inside the email PPE
slice (§16), after the §31.4 triggers, and only:**
- to allow-listed operator mailboxes;
- with synthetic content;
- with the restricted non-production key.

**Real personal data never reaches an external processor before E17**
(ADR 0060 §31; ADR 0058 note 2026-10-14).

**E19:**
- **Reopens:** only when all eight ADR 0060 §31.4 triggers hold.
- **Clock start:** a clock-admission record, timestamped when the last of
  A1–A14 became true. It never starts from account creation or DNS
  publication and is never backdated (E19 record §2.2, §5).
- **Day 0 never counts.**
- **Success:** 14 consecutive qualifying UTC days. Late reports count
  within 72 h.

**SendGrid sandbox mode never qualifies DKIM/DMARC.** It never delivers and
emits no events (ADR 0060 §28.3; E19 record §6.1). E19 and E20 need
delivery mode.

**Unchanged:** Mailpit stays canonical for development until §16
explicitly advances.

## 8. E16 — the first time-bound obligation

**Calendar.**
- `OWNER-0O-E16-2026-10-11` (115 High records) is valid **through
  2026-11-09** and expires 2026-11-10.
- From **2026-11-10** the `supply-chain` lane's
  `qualify audit --fail-on-blocking` fails. No candidate can then have a
  green `CI gate`, and `publication-check` refuses: **no publication of
  anything, docs included.**
- The successor cycle should begin **around 2026-10-30.**

**Successor process** (precedent: E16-R1, D1, P1 on 2026-10-11):
1. **Fresh scan.** Run `infrastructure/release/qualify all` on the current
   `main`, or the qualification workflow, with the current scan database.
   Evidence stays outside the repository.
   - Sync the Gateway `.venv` from `requirements.lock --require-hashes`
     first.
   - Docker's classic image store is required.
2. **Remediation** (a separate authorised slice) if any Critical or any
   fixable High is found. A Critical is never exception-eligible (E16-P1).
   Remediation updates bases and locks only.
3. **Owner/security decision** for the residual no-fix set:
   - a new `OWNER-0O-E16-<date>` record in
     `docs/security/release-remediation/`;
   - `vulnerability-exceptions.json` records, each re-verified with
     exploitability evidence;
   - never by editing old records' dates, never a fabricated approval.
4. **Publication** through the protected path (ADR 0072): an integration
   branch, an exact-SHA `CI gate` (supply-chain lane green under the new
   decision), then `publication-check --push`.
5. **Terminal proof:**
   - the post-publication `main` CI is green;
   - `qualify verify` reports both images **VERIFIED** on the published
     SHA;
   - a readiness section and an ADR 0058 note are written.

**Recurrence.** E16 is not a one-off. Each decision lasts roughly four
weeks, and E16 must be valid on the **E15 (first promotion)** and **E02
(closeout)** dates (ADR 0058 §4.8). PPR schedules a cycle before each
expiry and aligns one with E02 (§23).

**PPR.0 does not execute it.** On 2026-10-14 it is not due, and starting it
early buys nothing: the exceptions would expire on the same cadence.

## 9. Infrastructure, secrets and the environment (E05, E08, E09)

### 9.1 Decisions before any build

| Decision | Status | Source |
|---|---|---|
| OD-PPR-3: hosting/orchestration platform | DECISION (no vendor in repo, ADR 0050 §1) | E09 |
| OD-PPR-4: managed secret store | DECISION (E08 PROVIDER_REQUIRED, "no vendor is selected", ADR 0050 §4) | E08 |
| OD-PPR-5: OCI registry and signing custody (KMS/offline key or keyless identity) | DECISION (E13, E14) | ADR 0052 §3, §5 |
| OD-PPR-8: non-production domain(s): platform host, a custom School domain for E22, the sending domain for E19 | DECISION | E05, E19, E22 |

### 9.2 Secret-store requirements (E08)

**Inventory** (`deploy/processes.json`; ADR 0050 §4; ADR 0055 §7):
- **`app_runtime`:**
  - `APP_KEY`, `APP_PREVIOUS_KEYS`, `METRICS_SCRAPE_TOKEN`;
  - `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_ACCESS_KEY_ID`,
    `AWS_SECRET_ACCESS_KEY`;
  - `MAIL_PASSWORD`, `MAIL_SENDGRID_API_KEY`;
  - `MAIL_PROVIDER_EVENT_SECRET` and its previous value (public keys, kept
    in the store for uniform custody);
  - `MAIL_SUPPRESSION_HMAC_KEY` and its previous value;
  - `AI_GATEWAY_CONTEXT_SIGNING_KEY`, `AI_GATEWAY_SERVICE_SIGNING_KEY`;
  - `CONTACT_LOOKUP_HMAC_KEY`, `STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY`;
  - `DOMAIN_PROBE_KEY`.
- **`database_admin`:** release and operator console only.
- **`database_retention`:** scheduler and operator console.
- **`gateway`:** only if E26 is enabled.
- **Operator-only, never in a runtime process:**
  - the provider operator credential;
  - the E19 T2 keys;
  - the registry and signing credentials.

**Requirements (REQUIRED):**
- per-process groups exactly as `processes.json`;
- `DB_ADMIN_*` absent from every long-running role;
- never in git, images, the database, logs, audit, errors or the frontend;
- operator access limited to named operators, with access recorded (E29).

**Staging.** RECOMMENDED: the same mechanism as production, in a separate
instance or namespace with separate values. E08 is then demonstrated where
the evidence is produced, and production differs only in data.

**E08 evidence:**
- store selected;
- per-process injection proven;
- `DB_ADMIN_*` only in release/console;
- a guard boot in staging with secrets from the store.

### 9.3 Environment build order (configure → prove)

1. **Configure:** network and edge (E05), PostgreSQL 16 TLS, Redis auth,
   private versioned encrypted bucket, secret store (E08).
2. **Bootstrap:** `DATABASE-BOOTSTRAP.md` on the admin connection.
3. **Configure:** processes per manifest (E09); one scheduler, proven
   single.
4. **Prove:**
   - `platform:verify-database` and `platform:verify-storage` 0 failed;
   - guard boot passes;
   - edge behaviours (HTTPS detection, HSTS, real client IP reaching the
     limiters).

## 10. Release, deployment and the maintenance window (E12–E15)

### 10.1 Required (ADR 0052, ADR 0058 §4.7)

**Infrastructure:**
- an authorised registry with permissions applied (E13);
- signing custody configured, with `artifact-policy.json` signing identity
  set by a reviewed change (E14). Today it is `"custody": "unconfigured"`.

**First promotion (E15):**
- the same VERIFIED digest is signed, stored and attested;
- `verify-artifact` runs before promotion, followed by an explicit
  promotion event;
- the first promotion's evidence is retained in `retained-releases.json`
  (empty today);
- deployment accepts promoted digests only;
- a rollback candidate is retained.

### 10.2 Exercises against real infrastructure (staging)

| Exercise | Type | Evidence |
|---|---|---|
| Deploy a VERIFIED digest by digest | prove | digest, verify result |
| Refuse a non-VERIFIED / unsigned digest | exercise under controlled failure | refusal recorded |
| Maintenance-window release with migrations (E12) | prove | `MAINTENANCE-WINDOW-RELEASE.md` steps 0–12 |
| Failed deployment during the window → restore to the step-5 checkpoint | exercise under controlled failure | recovery point, duration |
| Rollback to the previous promoted digest (no migration) | prove | digest, verification |
| Worker/scheduler continuity: drain, pause, single scheduler after restart | prove | OBS-05/07/08 quiet; queue drained |

### 10.3 Finding — migration rollback is documented two ways

- **`MAINTENANCE-WINDOW-RELEASE.md`:** "no general `down()` rollback in
  production — restore to the step-5 checkpoint".
- **`PRODUCTION-RELEASE.md`:** a deliberate
  `migrate:rollback --database=pgsql_admin`.

Two retention-fence migrations refuse `down()`. PPR.1 reconciles the two
into one statement, without changing CLAUDE.md rule 10 (every migration
still has a considered `down()`).

**Recommended reconciliation:** production recovery after a migration
failure is the PITR checkpoint, and `down()` is a development/test
guarantee.

## 11. Observability (E07)

### 11.1 Required (ADR 0051 §20, ADR 0058 §4.3)

- **Backend:** a real external backend receiving metrics (Prometheus text,
  port 9102, bearer `METRICS_SCRAPE_TOKEN`) and JSON logs (stderr). Vendor:
  OD-PPR-6.
- **Retention:** logs ≥ 30 d and metrics ≥ 90 d. E21-D13 additionally
  names 180 d for a log class; reconcile this in the E21 configuration.
- **Rules:** the `platform:alerts-export` rules are loaded and evaluated,
  with operator values set. Nine rules are **disabled until an operator
  value is set**: OBS-16, 18, 25, 33, 36–40, plus the C-tiers of OBS-10/24.
- **Routing:** proven by a test notification to "the intended operator
  channel".
- **Evidence file:** `OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE` mounted
  (OBS-20–23 fire while it is absent).
- **OBS-26:** scrape job named `lycenza-metrics`.

### 11.2 Gaps registered

| Gap | Class | Proposal |
|---|---|---|
| No HTTP latency alert. `lycenza_http_request_duration_seconds` exists, dashboard only | RECOMMENDED (OD-PPR-7) | Add one p95/p99 latency rule per surface, with operator thresholds |
| No HTTP 5xx-rate alert. `lycenza_http_requests_total{status_class="5xx"}` exists | RECOMMENDED (OD-PPR-7) | Add a 5xx-ratio rule; operator threshold |
| No named response owner, on-call or channel. SEV-1/2/3 response times exist; "routing is a deployment decision" | REQUIRED for E07 routing evidence (a channel must exist); ownership is DECISION | OD-PPR-7: who receives SEV-1/2/3, hours of cover, escalation |
| Object-storage failures weakly covered (OBS-24 tier disabled; dependency failures not alerted) | RECOMMENDED | Set `ALERT_STORAGE_FAILURES_PER_15M` |
| OBS-06 has no `absent()` for a task that never succeeded | RISK (small) | Engineering fix in a PPR slice |
| Documentation drift: "26 alerts" in ADR 0051/OBSERVABILITY.md vs 41 in code; evidence-file timestamps accepted up to now+24 h vs "not in the future" | Doc/code drift | Reconciled in the observability slice |

**Minimum pre-production contract.** Every row needs a named responder
(OD-PPR-7):

| Area | Covered by |
|---|---|
| Health | OBS-01–03 |
| HTTP failures | **new 5xx rule** |
| Latency | **new latency rule** |
| Queues | OBS-08–10 |
| Scheduler | OBS-05–07 |
| Database and Redis | OBS-02/03 |
| Storage | OBS-24 with a threshold |
| Email | OBS-31–38 with thresholds |
| Backup | OBS-20–22 |
| Restore drill | OBS-23 |
| Collector | OBS-26 |

**No dashboards beyond `dashboards.md`.**

## 12. Backup and recovery (E10, E11, E12)

### 12.1 States to distinguish

| State | Meaning |
|---|---|
| **Configured** | PITR/WAL archiving and base backups, encrypted, separate credentials, deletion protection, 35-day retention; bucket versioning; an independent object copy under separate administrative control |
| **Exists** | Backups observed; freshness written to the evidence file (OBS-20–22 quiet) |
| **Intact** | A backup is restorable (proved only by E11) |
| **Restored** | Data brought back in an isolated target |
| **Verified** | `platform:verify-restore` (with a Document sample) and `platform:verify-database` pass |
| **Recovery-time evidence** | Achieved RPO and RTO, measured against ≤ 15 min / ≤ 4 h (PostgreSQL) and ≤ 24 h / ≤ 8 h (objects) |

"A backup counts only once a restore has succeeded"
(`BACKUP-AND-RESTORE.md`).

### 12.2 The first restore drill (E11 — hard blocker)

**Environment.** A **separate, isolated** restore target. It is not staging
itself and never production. It is built from staging's real backups (PITR
or snapshot, plus the independent object copy or versioning), with
synthetic data only.

**Evidence** (ADR 0058 §4.5; `RESTORE-DRILL-RECORD.md` template):
- PostgreSQL restored;
- objects restored or reconciled;
- the application booted;
- `verify-restore` and `verify-database` passed (RLS, roles);
- a Document sample validated;
- the achieved recovery point measured;
- RPO/RTO recorded;
- a record `DRILL-YYYY-QN-n` appended, with evidence only;
- the evidence file's `restore_drill` set, so OBS-23 clears.

**Preconditions:** E10 has run long enough to have PITR and a base backup,
plus E07 (evidence file and alerts). The drill is an operator rule-16
action.

**Cadence.** Quarterly after the first drill (ADR 0050). OBS-23 warns
after 92 days.

### 12.3 E12

- **Redis-loss exercise:** flush Redis in staging, then
  `platform:recover-queued-work`. Reconciliation sweeps recover, users
  are signed out, and OBS-11–19 return to normal.
- **Maintenance-window release:** one release in the window (§10.2).

Both are recorded as operator evidence.

## 13. Security finalisation

| Item | Class | Requirement |
|---|---|---|
| E02 / O16 | REQUIRED | Production-stage PR rule + **≥ 1 independent approval** + CODEOWNERS active (ADR 0072 §16–§17), then a fresh qualification of the closeout commit. ADR 0072 records that **no second reviewer identity exists**, so this needs an owner resourcing decision (OD-PPR-16) |
| E21 | REQUIRED (legal) | Qualified ratification, E21-L1 §8, production retention configuration |
| E22, E23 | REQUIRED | §15 |
| E29 | REQUIRED | §19 |
| Production credentials | REQUIRED | Created only in the store (E08), per process; email credentials only at PPE step "production credential" |
| Production role grants | REQUIRED process | Staff roles are granted only through Staff accounts (SR). The `teacher` role stays ungranted until E33 re-verification (§18). E47 personas follow OD-PPR-14 |
| Final security review | REQUIRED only inside email PPE (ADR 0060 §31.7 item 20); otherwise RECOMMENDED | OD-PPR-15: one final pre-O1 security review across the deployment |
| Penetration test | **Not repository-required** | OD-PPR-15 decides; not mandatory unless adopted |
| `/api/v1/system/status` discloses `app.env` | RISK (low) | Decide in the security slice |

**Key rotation:**

| Secret | Current state | Decision |
|---|---|---|
| `APP_KEY` | `APP_PREVIOUS_KEYS` supported; **no procedure** | OD-PPR-10: rotation procedure (re-encryption or decrypt-with-previous window, session invalidation, rate-limit fingerprint reset, MFA secrets, webhook secrets, guardian contacts, statutory identifiers, email content) |
| AI context signing key | Single key, no `kid`; **no procedure** | Required before E26 enablement only. With the Gateway disabled, record a compromise-only replacement procedure (RECOMMENDED) |
| Contact / statutory lookup HMAC | Version column, **no rotation workflow** | OD-PPR-10: accept compromise-only re-keying with a documented re-hash, or build a workflow |
| Service Ed25519 keys | Runbook exists | Only if E26 enabled |
| Email suppression HMAC, event secret, SendGrid keys | Runbooks exist | Exercised in E20 |
| Webhook endpoint secrets | Built, with an overlap window | Partner-facing |
| DB / Redis / S3 credentials | **No runbook** | RECOMMENDED: rotation runbook in the security slice |

## 14. Incident and personal-data-breach response (RISK → slice)

**Gap.** No general incident plan, breach procedure, on-call or escalation
policy exists. What exists: `SUPPLY-CHAIN-INCIDENTS.md`, emergency key
revocation, the compromised-mail-credential steps and SEV definitions.

**PPR slice (§23, PPR.9) delivers an operator plan:**
- classification and severity (reusing SEV-1/2/3);
- containment;
- evidence preservation (records outside the repository, E29);
- credential and key rotation (§13);
- tenant communication (who tells a School, and how);
- **personal-data-breach escalation to the owner's privacy/legal function**;
- recovery;
- post-incident review.

**Notification deadlines and authority reporting** come only from a
qualified legal answer (OD-PPR-11). No deadline is written without one.

## 15. Tenant support (RISK → slice)

### 15.1 Existing mechanisms (preserved, not widened)

| Need | Mechanism |
|---|---|
| Lost password | `platform:user-password-reset` (console, audited) |
| Lost MFA | `platform.users.mfa.reset` (root only; audited) |
| Self-service recovery | ADR 0056 (E23) |
| Staff access and off-boarding | The School's own administrators (Staff accounts) |
| School suspension, freeze, close, reopen | `SchoolLifecycleService` (`platform.schools.manage`, fresh MFA) |
| Failed jobs | `platform:failed-jobs`, `FAILED-JOBS.md` |
| Erasure cases | `platform:erasure-case-*` |
| Platform presence | Elevation: 30 min, MFA, reasoned, audited. **No School route accepts elevated context** (rule 83), so platform support cannot see School data. |

### 15.2 The tenant-support runbook (PPR.9)

**It covers:**
- intake and identity verification of the requester;
- tenant lockout (no active School administrator);
- MFA and account restoration;
- data-correction escalation (the School corrects its own data; the
  platform never edits tenant rows);
- cross-tenant incidents (§14);
- failed jobs;
- provider failure;
- suspension and freeze;
- escalation ownership.

**Open decision OD-PPR-12:** whether any support operation needs a
`school-context:elevated` route. **The default is none.** Each such route
needs its own ADR and a narrow `platform.*` capability (ADR 0044 §8). No
hidden access path is introduced.

## 16. Email pre-production (E17–E20)

**The sequence** is ADR 0060 §31.7, mapped to evidence. Every step is
gated on the previous one, except where marked parallel.
1. **Provider decision re-confirmed.** OD-PPR-13; SendGrid is the selection
   on record.
2. **E17 evidence refreshed and the review request sent.** Parallel and
   early: legal turnaround is the unknown.
3. **Privacy/legal determination recorded verbatim.** E17.
4. **Account opened.** Operator, rule 16, under OD-1.
5. **Region and residency.** EU subuser or global; **a change means
   re-admission.**
6. **DPA and sub-processors** accepted as determined.
7. **Secret storage.** E08 for production credentials; non-production E19
   keys may use the operator store (E19 record §4).
8. **DNS and domain authentication** for the sending domain (OD-PPR-8).
9. **DKIM** (new selector, RSA ≥ 2048), then **SPF**, then **DMARC**
   (never weakened).
10. **E19 admission record.** A1–A14 hold simultaneously; Day 0 recorded.
11. **≥ 14 qualifying days, Day 1–Day 14**, plus the 72 h late-report
    margin. **This is the waiting window.**
12. **§21 sandbox request-contract gates and live webhook qualification**
    in staging. Delivery mode, allow-listed operator mailboxes.
13. **E20 drills:** delivery, soft and hard bounce, complaint, duplicate and
    replayed webhook, provider retry, credential and webhook-key rotation,
    monitoring.
14. **The async-bounce DPO answer.**
15. **Production credential** created in E08.
16. **Final security review** (ADR 0060 §31.7 item 20).
17. **Deliberate removal of the production refusal** by a reviewed ADR
    amendment and code change. Then regression and O16 (feeds E02).

**Constraint.** Mailpit and the capture sink stay the default everywhere
until step 12 runs in staging.

## 17. E30–E34 — can a gate be met by keeping the feature out of production?

**The architectural answer.** O1 requires each row's evidence. "Feature
unused" is not legal clearance.

**Two ways a row can be closed:**
- **(a)** a qualified answer, the normal path;
- **(b)** an **owner decision amending ADR 0058** to make the row
  CONDITIONAL. That means "blocks O1 only if the feature is enabled in
  production", backed by a **code-level production refusal**, not a
  process promise.

Option (b) needs:
- a reviewed code change (a refusal keyed like `PortalAvailability`);
- tests;
- the amendment naming the feature, the refusal and the re-enablement
  condition.

Capability non-assignment alone does **not** qualify. Every gating
capability below is held by seeded production roles.

| Row | Feature | Code today | Production guard | Capability suppression? | Can (b) apply? | What an owner decision must state |
|---|---|---|---|---|---|---|
| E30 | Payment receipt, acknowledgement only | `ReceiptIssuer` issues a receipt **automatically on every settled payment** (`SettledPaymentRecorder.php:197`) | None | No: `finance.payments.record` (school_admin, accountant, cashier) | **Not practically.** Disabling receipts means disabling payment recording | Either a qualified answer that an acknowledgement-only receipt is acceptable for v1 Schools, or the receipt form the answer requires |
| E31 | Late-fee rules and runs | `LateFeeRuleService`, `LateFeeRunService` | None | No: `finance.fee_structures.manage`, `finance.fee_assessments.run` (school_admin, accountant) | Yes | (a), or (b) with a late-fee production refusal, and late fees excluded from v1 |
| E32 | RTE / free-seat obligations | **No RTE feature exists**; concessions are generic | None | n/a | **No.** There is nothing to switch off; the question is whether fee assessment is lawful for RTE Schools | (a), or an owner launch-scope decision stating which Schools v1 serves, recorded with legal input |
| E33 | Teacher Attendance | Built; nine controls PASS | Process only (`teacher` role ungranted) | No: the `teacher` role is seeded | n/a: determined | §18 |
| E34 | Library overdue fines | Assessed at check-in only if a fine policy is **active**; `school_admin` publishes the policy | None | No: `library.fines.manage` (school_admin) | Yes | (a), or (b) with a fine-policy production refusal |
| OPF fee intent (E21/E30–E32) | Transport, Hostel, admission fee selections | Built | None | No | Follows E30–E32 | Covered by those rows |

**This is explicit decision point OD-PPR-14a.** ADR 0073 §16 X3 deferred it
here, and PPR.0 does not decide it.

## 18. E33, E45, E46, E47

**E33 — production re-verification.** The legal question is answered, so
none of this reopens development.

*Controls.* The nine controls (TCH-L1 determination §9) are re-verified on
the **production candidate** (ADR 0063 §43.5, §44.4):
- `APP_ENV=production`;
- `TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED` unset (boot refuses it);
- `/app/my-attendance` only, with `mfa-page` on every route;
- teachers enrolled in MFA;
- audit of every owned read and write;
- verified ActingEmployee and TeachingAssignment ownership;
- School scope;
- revocation ending future authority;
- no authority beyond Attendance.

*Also required:*
- an `hr_officer`-capable School creating EmploymentRecords (ADR 0058
  SR.0 note);
- E21;
- the platform checklist;
- a rule-16 go-live authorisation.

*Gaps.* No dedicated checklist document exists; PPR.10 writes one. The
evidence is produced at the production candidate, which is a go-live
activity. Staging can rehearse it.

| Row | Feature | Blocks O1? | Code refusal? | Role suppression? | Owner decision required |
|---|---|---|---|---|---|
| E45 | ESI disability ceiling (statutory payroll) | "Owner to decide" | The branch is not implemented; `payroll.statutory.*` is held by **no** production role, is legal-gated and cannot be granted at runtime | Yes, by non-seeding (no code refusal) | OD-PPR-14b: is statutory payroll in v1? If not, record E45 as not blocking O1. If yes, the PAY-L1 request must be sent and answered. See finding F-1 (§21) |
| E46 | Guardian/Student portal | No (post-v1) | **Yes**: `PortalAvailability` (`local`/`testing` only), enforced in middleware, services and navigation | — | None for O1. Note: Guardian activation is not portal-gated and leads to no surface in production (harmless) |
| E47 | Staff personas reaching Highly Sensitive data | "Owner to decide" | None; roles seeded and grantable | No | OD-PPR-14c: is a DPO answer needed before production use of the new personas? If yes, send `SR-L1-…-DPO-QUESTION.md` early |

## 19. Evidence architecture (E29)

**The repository holds references, never payloads.**
- **Format:** `OPERATOR-EVIDENCE-RECORD.md` `EVID-<row>-<YYYY>-<n>`.
- **Each entry carries:** UTC timestamps, the environment **role** (never a
  hostname), command result codes, digests, references and an E29 hygiene
  line.
- **Row status:** a row moves to `EVIDENCE_COMPLETE` only by a reviewed
  repository change appended to `PHASE-0O-READINESS.md`.

**Excluded always:**
- secrets, tokens, credentials, connection strings;
- hostnames, IP addresses, URLs, email addresses;
- School data, payloads, raw scan reports.

**Operator identity.** Recorded as a **role**, plus a pseudonymous operator
reference agreed in OD-PPR-9, never a personal email.

**Full evidence** (logs, provider screenshots, CI artifacts, DMARC
reports):
- it lives in an **operator evidence store outside the repository**. Its
  location, access control and retention are **undefined today**
  (OD-PPR-9);
- CI artifacts expire after 90 days and must be copied there first
  (`RELEASE-QUALIFICATION.md`).

**No fake entries.** An `EVID-` entry describes only an action that
happened. **No `EVID-` entry exists today.**

## 20. Performance, accessibility, onboarding, DSAR

**Performance and scale (RISK → OD-PPR-18).** No repository requirement,
SLO or load test exists. Before production the owner supplies the
envelope:
- Schools per deployment;
- Students and employees per School;
- concurrent staff users at peak;
- portal users (post-v1);
- peak attendance and timetable activity (start of day);
- fee assessment, payment and statement volume (term start);
- report/export volume;
- queue throughput (notifications);
- document/storage growth.

PPR then proposes one of:
- (i) query-budget qualification only (the existing pattern);
- (ii) **recommended:** a load exercise on staging against owner-set
  latency/throughput targets for the top staff workflows, plus a stress
  point;
- (iii) defer with an explicit risk acceptance.

No numeric SLO is set by engineering.

**Accessibility (OD-PPR-19).** Options:
1. the SWC baseline only;
2. **recommended:** the SWC baseline plus a targeted audit of the top staff
   workflows;
3. a formal WCAG level target;
4. a post-v1 remediation programme.

The roughly 800 pre-SWC lint findings are **not** launch blockers unless
the owner adopts option 3.

**Onboarding (OD-PPR-20).** Student, Guardian and admissions import and
opening fee balances do not exist (OD-SWC-4/5). Employee import exists.

The owner classifies each as one of:
- required for the first customer;
- customer-specific implementation;
- operator procedure, e.g. manual entry through the UI;
- post-v1.

**Opening balances are new Finance domain scope.** The current model has no
opening-balance record, and payments record money received only. They are
not a PPR engineering task without a Finance contract.

**DSAR and tenant export (OD-PPR-21, legal + owner).**

*Exists today:*
- erasure cases (E21-D10, operator console);
- read-only tenant closure status (E21-D11).

*Does not exist:*
- access, correction or portability tooling;
- tenant export;
- School purge. Tenant destruction stays **NOT AUTHORISED**.

*Decisions:*
- does v1 need DSAR tooling, or is an operator-assisted procedure
  acceptable?
- tenant export at contract end: format, authorisation, who receives it;
- how export relates to retention and destruction (E21-D11 preconditions);
- how backups inside the 35-day window are treated.

No export code is written in PPR.0.

## 21. Findings recorded by this audit (reported, not fixed)

| # | Finding | Class | Disposition |
|---|---|---|---|
| F-1 | `StatutoryPayrollCalculationService::calculateForRun()` and `StatutoryPayrollPostingService` have **no runtime caller**: no route and no command, only tests. Statutory payroll can be configured and its exports viewed, but its calculation and posting can never be triggered in a deployment | Product gap inside an already-gated feature (E45; no production role holds `payroll.statutory.*`) | Owner decision OD-PPR-14b (statutory payroll in v1?). If yes, a Payroll slice outside PPR wires it. Not fixed in PPR.0 |
| F-2 | `APP_ENV=staging` disables the production guard, HSTS, forced HTTPS and the email refusals | Contract gap | §4, OD-PPR-1, PPR.1 code |
| F-3 | Plaintext SMTP is refused outside `local`/`testing`, so a staging Mailpit needs TLS | Configuration constraint | §7, PPR.1 |
| F-4 | Migration-rollback procedure documented two ways | Doc conflict | §10.3, PPR.1 |
| F-5 | ADR 0051/OBSERVABILITY.md say 26 alerts, code has 41; evidence-file timestamps accepted to now+24 h against "not in the future"; several runbooks carry no alert-specific section | Doc/code drift | §11, observability slice |
| F-6 | No HTTP latency or 5xx-rate alert | Coverage gap | §11, OD-PPR-7 |
| F-7 | `payroll.runs.view`-only holder has no run-page path (SWC.5 §20.3) | Post-v1 UX note | unchanged |
| F-8 | `/api/v1/system/status` publicly returns `app.env` | Low-risk disclosure | §13 |

## 22. Owner and legal decisions (all open)

*(2026-10-14, PPR.1: OD-PPR-1 (option B), OD-PPR-2 and OD-PPR-9 are decided -- §26.1. The rest stay open. PPR.2 adds OD-PPR-22 to OD-PPR-25 -- §27.6.)*

| ID | Decision | Needed by |
|---|---|---|
| OD-PPR-1 | Environment model: `staging` first-class (B, recommended) vs `APP_ENV=production` (A) | PPR.1 |
| OD-PPR-2 | Staging data policy confirmation (§6) and synthetic-data method | PPR.1 |
| OD-PPR-3 | Hosting/orchestration platform | PPR.2 |
| OD-PPR-4 | Managed secret store (E08); same mechanism for staging | PPR.2 |
| OD-PPR-5 | Registry (E13) and signing custody (E14) | PPR.3 |
| OD-PPR-6 | Observability backend (E07) | PPR.4 |
| OD-PPR-7 | Alert routing channel, responders, hours of cover; adopt latency/5xx rules | PPR.4 |
| OD-PPR-8 | Non-production domains: platform host, custom-domain test, sending domain | PPR.2 / PPR.6 |
| OD-PPR-9 | Operator evidence store: location, access, retention; operator reference scheme | PPR.1 |
| OD-PPR-10 | Key-rotation procedures: `APP_KEY`, lookup HMACs, infrastructure credentials | PPR.9 |
| OD-PPR-11 | Incident/breach legal framework (notification duties) — legal | PPR.9 |
| OD-PPR-12 | Any elevated support route (default none) | PPR.9 |
| OD-PPR-13 | Email provider re-confirmation and E17 send | PPR.6 (start early) |
| OD-PPR-14a | E30–E34: answer each, or make it CONDITIONAL with a code refusal (§17) | PPR.10 (start legal early) |
| OD-PPR-14b | Statutory payroll in v1? (E45, F-1) | PPR.10 |
| OD-PPR-14c | E47: DPO answer before production personas? | PPR.10 |
| OD-PPR-15 | Final security review scope; penetration test yes/no | PPR.9 |
| OD-PPR-16 | Production-stage review: a second reviewer identity for ≥ 1 independent approval + CODEOWNERS (ADR 0072 §16–§17) | before PPR.11 |
| OD-PPR-17 | Optional subsystems in v1 production (AI Gateway, custom domains) — default off | PPR.11 |
| OD-PPR-18 | Performance/scale envelope and qualification depth | PPR.8 |
| OD-PPR-19 | Accessibility target | PPR.9 |
| OD-PPR-20 | Onboarding: import and opening balances | before first customer (go-live track) |
| OD-PPR-21 | DSAR tooling and tenant export | PPR.10 |

**Legal requests that should go out early.** They are the longest external
dependencies:
- E17 (refresh and send);
- E21 ratification + E21-L1;
- E30, E31, E32, E34;
- PAY-L1 (if statutory payroll is in v1);
- SR-L1 (if needed);
- the breach-response framework.

## 23. PPR slices, dependencies and critical path

**Numbering differs from the suggested outline:**
- release and registry precede observability, because staging deploys
  VERIFIED digests;
- email is split so the legal request starts at once;
- custom-domain and recovery drills get their own slice, because they
  depend on email.

| Slice | Contract | Prerequisites | Evidence / output | Operator? | Real infra? |
|---|---|---|---|---|---|
| **PPR.0** | This contract | — | ADR 0074 | No | No |
| **PPR-E16** (parallel track) | E16 successor cycles (§8) | — | New decision before each expiry; VERIFIED images | Owner + security | No |
| **PPR.1** | Environment & data contract freeze + `staging` semantics code (§4.4) + migration-rollback reconciliation + evidence-store decision | OD-PPR-1, -2, -9 | ADR amendment; code + tests; docs | No | No |
| **PPR.2** | Infrastructure & secrets: E08, E09, E05 | PPR.1; OD-PPR-3, -4, -8 | `verify-database`/`verify-storage` 0 failed; guard boot; edge behaviours; EVID records | **Yes** | **Yes** |
| **PPR.3** | Registry, signing, first promotion, deploy/rollback exercises: E13, E14, E15, E12 (window part) | PPR.2; OD-PPR-5; E16 valid | Promoted digest; `retained-releases.json`; exercise records | **Yes** | **Yes** |
| **PPR.4** | Observability: E07 + adopted alert additions + routing ownership | PPR.2; OD-PPR-6, -7 | Backend, retention, rules, test notification, evidence file | **Yes** (+ small code if alerts adopted) | **Yes** |
| **PPR.5** | Backup & recovery: E10, E11 (first drill), E12 (Redis loss) | PPR.2, PPR.4 (evidence file); backups aged | `DRILL-…` record; OBS-20–23 quiet | **Yes** | **Yes** (+ isolated restore target) |
| **PPR.6** | Email PPE: E17 → E19 → E20 (§16) | E17 answer; PPR.2 (E08, staging); OD-PPR-8, -13 | E17 determination; E19 14-day record; E20 drill record; refusal removal | **Yes** | **Yes** |
| **PPR.7** | Custom-domain + account-recovery drills: E22, E23 | PPR.2; PPR.6 (critical email) | Exercise and drill records; OBS-39–41 | **Yes** | **Yes** |
| **PPR.8** | Performance/scale qualification | PPR.2–PPR.4; OD-PPR-18 | Envelope; load-exercise record | **Yes** | **Yes** |
| **PPR.9** | Security, incident & support readiness: key rotation, incident/breach plan, support runbook, security review | OD-PPR-10, -11, -12, -15, -19 | Runbooks; review record; rotation exercise | Partly | Partly |
| **PPR.10** | Legal & feature-gate closure: E21 ratification + config, E30–E34, E33 checklist, E45/E47, DSAR/export | Legal answers; OD-PPR-14a/b/c, -21 | Determinations; ADR amendments; any code refusals; retention config | No | No (config applied in PPR.2+) |
| **PPR.11** | Final production qualification: E02 + O16, production-stage review controls, E16 valid, O1 determination | Everything above; OD-PPR-16, -17 | Fresh qualification on protected closeout commit; O1 decision record | Owner + security | Registry |

**Dependency graph:**

```
PPR.0 ─► PPR.1 ─► PPR.2 ─┬─► PPR.3 ─┐
                         ├─► PPR.4 ─► PPR.5 ─┤
                         ├─► PPR.6 (needs E17) ─► PPR.7 ─┤
                         └─► PPR.8 ──────────────────────┤
PPR.9 (from PPR.1, parallel) ────────────────────────────┤
PPR.10 (legal, from PPR.0, parallel) ────────────────────┤
PPR-E16 (every expiry) ──────────────────────────────────┴─► PPR.11
```

**Parallel tracks:**
- **May run in parallel:**
  - E16 cycles;
  - legal requests (E17, E21, E30–E34, PAY-L1, SR-L1, breach framework);
  - the secret-store, hosting, registry and backend decisions;
  - runbook drafting (PPR.9: incident, support, rotation);
  - the accessibility and onboarding decisions;
  - PPR.3 / PPR.4 / PPR.8 once PPR.2 exists.
- **Not in parallel:**
  - anything producing evidence before the environment it describes is
    configured;
  - E11 before E10 has produced backups;
  - E19 before the domain, DNS and E17-compatible provider setup;
  - E20 before E19 admission;
  - E23 before critical email and E22;
  - E02 last.

**Critical path** (longest chain; no calendar promises):

```
OD-PPR-1/3/4/8 decisions ─► PPR.1 code ─► PPR.2 environment
  ─► [E17 legal answer — external, unknown duration, start NOW]
  ─► provider account/region/DNS ─► E19 admission
  ─► Day 0 + 14 qualifying days + 72 h late-report margin (≈ 17–18 days minimum, restarts on any failed day)
  ─► E20 drills ─► E23 recovery drills ─► production refusal removal (code, regression, O16)
  ─► production-stage review controls ─► E16 valid ─► E02 fresh qualification ─► O1 determination
```

**Waiting windows and external dependencies:**
- **E17 and the other legal answers:** turnaround unknown. Send early.
- **E19:** ≥ 17–18 days minimum, and a restart resets it.
- **E10 → E11:** backups need time to accumulate PITR history.
- **E16:** roughly four-week cadence; must be valid on the E15 and E02
  dates.
- **A second reviewer identity (OD-PPR-16):** a resourcing lead time.
- **Infrastructure procurement and accounts:** owner-dependent.

**What must begin early:**
1. Send the E17 and other legal requests.
2. Make OD-PPR-1/3/4/8.
3. Plan the E16 cycle around 2026-10-30.
4. Resolve the second-reviewer question.

## 24. Boundary

**PPR.0 performs none of these:**
- deploy, provision or create accounts;
- change DNS;
- create credentials;
- send email;
- use real data;
- run drills or load tests;
- change production guards or role grants.

**Each operator action in later slices** is a separate rule-16
authorisation.

**Status after PPR.0:**
- PPR: **PPR.0 CONTRACT PUBLISHED / IMPLEMENTATION NOT STARTED.**
- The ERP is **not production-ready.**
- Phase 0O stays open (O1 not satisfied); Phase 0M stays BLOCKED.

## 25. Alternatives considered

1. **Run PPR as one "hardening" bucket.** Rejected: there is no completion
   criterion. Every slice here maps to register rows or adopted decisions.
2. **Deploy straight to production and qualify there.** Rejected. ADR 0058
   §3 and §8 require non-production deployment evidence without real
   School data before any production authorisation.
3. **Treat every O1 blocker as equivalent.** Rejected (§3). Legal rows
   are on a separate track, and post-v1 rows do not block O1.
4. **Count disabled features as cleared.** Rejected (§17). It needs an ADR
   0058 amendment and a code refusal, decided by the owner.
5. **Adopt industry defaults (pentest, WCAG AA, fixed SLOs) as
   requirements.** Rejected. They are registered as decisions, not
   invented as requirements.

## 26. PPR.1 — Staging / Pre-Production Environment Contract — IMPLEMENTED (2026-10-14)

**Baseline:**
- `origin/main` = `9e202ed1` (PPR.0), with green exact-SHA and `main`
  `CI gate`;
- ruleset 24909660 active, no bypass actor, drift `ok`;
- clean tree, one worktree, historical stash untouched.

**Scope.** Application semantics, tests and documentation only. No
infrastructure, provider account, DNS, credential, email or deployment.

### 26.1 Owner decisions recorded (frozen at PPR.1 authorisation)

| ID | Decision |
|---|---|
| **OD-PPR-1** | **Option B approved.** `staging` is an officially recognised environment. Allowed values: `production`, `staging`, `local`, `testing`; unknown values fail closed at startup. Staging is production-equivalent for security and hardening unless a difference is explicitly listed and test-pinned, and is never equivalent to local/development. |
| **OD-PPR-2** | **Staging uses synthetic data only.** Prohibited: real School data, copies of production databases, pseudonymised production databases, anonymised production-derived datasets (unless a later explicit legal determination authorises them), real Student/Guardian/employee/statutory identifiers, real finance records, production documents or uploads. Synthetic data comes only from project-controlled fixtures or seeding, the application's bootstrap/setup workflows, or ordinary UI/API use with synthetic values. Manual SQL is never the standard method, and no legacy import is built for it. Staging is disposable and may be rebuilt. |
| **OD-PPR-9** | **Raw operator evidence lives outside Git** under the operator-configured `$LYCENZA_OPERATOR_EVIDENCE_ROOT/ppr/`: directories `0700` and sensitive files `0600`, or the platform equivalent; never a path written into application code. **The repository holds only E29-safe entries:** id, timestamp, operator reference, role, action/result code, safe command reference, hashes, minimised outcome, repository SHA. **Never committed:** credentials, keys, passwords, prohibited hostnames, raw secret-bearing responses, private keys. **Operators** appear as an opaque reference (`OP-001`) plus a role, never a personal email. **Naming** stays exactly E29's `EVID-<row>-<YYYY>-<n>`; a PPR action with no register row uses its slice id (`EVID-PPR2-2026-1`). **No fabricated entries.** |

### 26.2 The environment model (OD-PPR-1)

**One classifier.** `App\Support\Configuration\EnvironmentPolicy` is the
only classifier:

| Constant / method | Members | Use |
|---|---|---|
| `SUPPORTED` | `production`, `staging`, `local`, `testing` | anything else refuses to boot |
| `HARDENED` / `isHardened()` | `production`, `staging` | every security hardening |
| `DEVELOPMENT` / `isDevelopment()` | `local`, `testing` | development conveniences (each still behind its own switch) |
| `isProduction()` / `isStaging()` | one value each | **only** the listed `STAGING_DIFFERENCES` sites |

**Unknown values.** `AppServiceProvider::register()` calls
`EnvironmentPolicy::assertSupported()` first, before the test-database
guard and anything else:
- it refuses `stage`, `preprod`, `prod2`, `Production`, an empty value, and
  so on;
- `UnsupportedEnvironmentException` says the value is unsupported and lists
  the supported set;
- it **never echoes the value**: the message gives only "empty" or the
  character count, and the call carries no argument, so no stack frame
  prints it;
- debug is forced off.

**An unset `APP_ENV`** is still `production` (`config/app.php`), the
strictest value.

**Build step.** The production image's build-time `package:discover` used
an ad-hoc `APP_ENV=build`; it now runs as `local` in that throwaway,
secret-less build stage. Its output is environment-independent, and the
runtime stage stays `APP_ENV=production`.

### 26.3 Production-guard audit (finding F-2)

**Every environment branch in `app/`, `config/`, `routes/` and
`bootstrap/` was classified:**

| Location | Before | Production | Staging before PPR.1 | Required in staging | Action |
|---|---|---|---|---|---|
| `AppServiceProvider` boot guard | `isProduction()` | ~60 checks refuse boot | **none ran** | all | `isHardened()`; guard told its environment |
| `ApplySecurityHeaders` HSTS | `isProduction()` | HSTS on secure responses | **no HSTS** | HSTS | `isHardened()` |
| `ClassifyRequestHost` forced HTTPS (School and platform hosts) | `isProduction()` | forced `https` root | **not forced** | forced | `isHardened()` |
| `DatabaseRoleVerifier` TLS check | `isProduction() \|\| $ssl` | FAIL unencrypted | EVIDENCE only | FAIL | `isHardened()` |
| `config/observability.php` log format | `APP_ENV === 'production'` | JSON default | `line` default | JSON | `HARDENED` |
| `SendGridConfiguration` | `environment('production')` | refused | **allowed** | refused until PPR.6 | `isHardened()` |
| `EmailEventAdapterResolver` sendgrid | `environment('production')` | refused | **allowed** | refused until PPR.6 | `isHardened()` |
| `EmailProviderResolver` reserved domain + `MAIL_SENDING_VERIFIED` | `environment('production')` | both enforced | **neither** | reserved domain always; attestation unless declared capture | `isHardened()`; difference `mail_sending_verified_not_required_for_capture` |
| `SmtpEmailProvider` `tls=none` | `local`/`testing` only | refused | refused | refused | unchanged (development-keyed) |
| `ServiceAuthKeys` key age | not `local`/`testing` | enforced | enforced | enforced | unchanged |
| `PortalAvailability`, StudentMark/teacher marks, `DevOnlySchoolHeaderResolver`, teacher bearer API, domain fakes and development hosts, fake email/event/notification providers, demo/internal routes, `LocalCatalogueFixtures`, SSRF loopback override, partner probe scope, metric-catalog strictness, `SendFakeEmailEvent`, `VerifyServiceAuth` dev keys, `DatabaseSeeder` dev branch | `local`/`testing` | off | off | off | unchanged (already fail closed) |
| `DemoEnvironmentGuard` | `local` + DDEV | refused | refused | refused | unchanged |
| `TestDatabaseGuard`, `platform:test-db-reset`, env diagnostic | `testing` | n/a | n/a | n/a | unchanged |
| `ApplySecurityHeaders` Vite hot-reload CSP | `local` | n/a | n/a | n/a | unchanged |
| `SystemStatusController` | returned `app.env` | disclosed | disclosed | not disclosed | field removed (§26.6) |
| `StructuredJsonFormatter` `environment` log field | value | logged | logged | logged (internal logs only) | unchanged |

**No intentional production-only hardening remains.** The only production
vs staging distinctions are the two listed differences (§26.4).

**Guard:** `EnvironmentArchitectureGuardTest`:
- no code outside `EnvironmentPolicy` tests `production`/`staging`
  literally;
- every framework `environment(...)` check, and every environment-list
  constant, names development values only;
- `isProduction()`/`isStaging()` appear only at the two
  `STAGING_DIFFERENCES` sites;
- the F-2 controls ask the policy.

**Proof.** A mutation check (restoring `isProduction()` in the HSTS
middleware) fails the guard and the staging HSTS parity test.

### 26.4 Staging vs production — the complete difference list

`EnvironmentPolicy::STAGING_DIFFERENCES` is pinned by
`EnvironmentPolicyTest`:

| Key | Staging | Production |
|---|---|---|
| `mail_staging_capture` | `MAIL_STAGING_DELIVERY=capture` declares the SMTP target a non-delivering capture sink. Undeclared staging SMTP is refused (`mail_staging_delivery_undeclared`). Only with the declaration may the host be a Mailpit-style service. TLS (`required`/`implicit`), credentials, the bounded timeout and the loopback refusal still apply. Values outside `capture` are refused (`mail_staging_delivery_invalid`) | The declaration is refused (`mail_staging_delivery_outside_staging`) |
| `mail_sending_verified_not_required_for_capture` | A declared capture sink needs no `MAIL_SENDING_VERIFIED`; the reserved-domain gate still applies | The attestation is required |

**Contract-level differences** (not code): synthetic data only, a
disposable environment, synthetic/test recipients, and qualification
tooling only under explicit PPR operator actions.

**Everything else is identical:** HTTPS/HSTS, database TLS, the
configuration checks, authorization, tenancy, workers and scheduler,
storage security, debug off, the secret architecture and migration
behaviour. The operator view is `docs/operations/STAGING-ENVIRONMENT.md`.

**Email** (ADR 0060 §31 preserved):
- **Default:** capture. SendGrid stays dormant and refused in staging,
  exactly as in production. A plaintext Mailpit is impossible outside
  development, so the staging sink is a TLS-capable Mailpit-equivalent
  service.
- **External delivery from staging:** opens only in PPR.6, under the frozen
  E17–E20 contract, by a reviewed change extending the `MAIL_STAGING_DELIVERY`
  value set.
- **Production:** the SendGrid refusal is unchanged.

**Refusal wording.** It reads "unsafe production configuration" in staging
too. Staging is production-equivalent, and the existing tests and runbooks
match on that wording.

### 26.5 Development features in staging

All stay unavailable in staging, as in production:
- `StagingParityTest`: the Guardian portal, StudentMark, teacher marks, the
  partner probe and the fake email provider; service-key age is enforced;
- `HardenedEnvironmentBootTest`: domain fakes and the teacher bearer API
  refuse boot.

**Also off in staging:**
- **Dev dependencies:** none ship (`composer install --no-dev`); there is
  no debugbar or Telescope at all.
- **The demo seeder:** refuses non-DDEV environments.
- **Demo/internal routes:** are not registered.
- **Debug pages:** never render. `APP_DEBUG=true` refuses boot, and every
  refusal forces debug off.

**DDEV/local and PHPUnit are unchanged and proven to boot.**

### 26.6 F-8 — the public status endpoint

**Change.** `GET /api/v1/system/status` now answers `data.status` only. The
development-era `phase` ("0A - Architectural Foundation") and `environment`
fields are removed.

**Compatibility:**
- **Consumers:** none in the repository (mobile, Gateway, frontend: none
  call it); the OpenAPI contract and `packages/shared-types` are updated.
- **Envelope:** `meta.apiVersion` and `requestId` are unchanged.
- **Health:** `/api/health/live` and `/api/health/ready` are unchanged.
- **Operators:** environment identity stays available through the
  platform-authorised `platform:operations-status` and
  `GET /api/internal/operations/status`.

**Test:** `SystemStatusTest` proves the anonymous body contains no
`production`, `staging`, `local`, `testing`, `environment` or `phase`, in
each environment.

### 26.7 F-4 — one rollback contract

`MAINTENANCE-WINDOW-RELEASE.md` and `PRODUCTION-RELEASE.md` now state one
contract, for every hardened environment:

1. **Application rollback** redeploys the previous promoted digest with the
   database preserved, and **never runs a migration `down()`**. It is
   allowed only when the earlier image is compatible with the current
   schema; this is decided before migrating.
2. **Database correction** uses a reviewed **forward corrective
   migration**, or, for a true recovery event, an approved PITR restore to
   the release checkpoint.
3. **`down()`** stays required by rule 10 as a development/test guarantee,
   never the routine staging/production path.

**No tooling assumed automatic rollback** (none found). The contract is
pinned by `ReleaseRollbackContractDocumentTest`.

### 26.8 F-5 — alert count

**The catalogue has 41 rules, OBS-01–OBS-41.** OBS-27–OBS-41 were added
after 0O.5A's 26.

**Corrected:**
- `OBSERVABILITY.md`;
- the `platform:alerts-export` description (text only);
- the `AlertCatalogTest` docblock;
- ADR 0051's implementation record, as a dated note.

**No alert behaviour changed.** The latency and 5xx additions stay PPR.4
(OD-PPR-7).

### 26.9 Security review

| Concern | Result |
|---|---|
| Environment spoofing | `APP_ENV` is deployment configuration, not request input. Nothing reads an environment from a request. |
| Defaults / fallback | Unset → `production` (strictest); empty or unknown → refused |
| Missing `APP_ENV` | Boots as `production` and runs the full guard (fail closed) |
| Secret leakage in startup errors | The unsupported-environment refusal never echoes the value; the guard refusal names codes only; both force debug off. Proven in subprocesses, web probe included |
| Debug mode | Refused in every hardened environment |
| Staging provider escape | SendGrid and its events refused in staging; capture must be declared; TLS and credentials still required; the reserved sending domain still enforced |
| HTTP/TLS hardening | HSTS, forced HTTPS and database-TLS verification now hold in staging |
| Public environment disclosure | Removed (F-8) |
| Production weakened? | **No.** Production adds one refusal (`mail_staging_delivery_outside_staging`); every existing production check is unchanged |

### 26.10 Tests

**New:**
- `EnvironmentPolicyTest` (3, unit);
- `HardenedEnvironmentBootTest` (5, subprocess);
- `StagingParityTest` (7);
- `EnvironmentArchitectureGuardTest` (4);
- `ReleaseRollbackContractDocumentTest` (1, repository-documents);
- a staging test in `ProductionConfigurationGuardTest`.

**Changed:**
- `SystemStatusTest` (F-8);
- `ProductionBootSmokeTest`, whose baseline is now the shared
  `BootsHardenedEnvironment` trait, identical values.

**Counts and the canonical sharded regression:** recorded in the PPR.1
commit.

### 26.11 Status

**PPR.1 COMPLETE / PPR.2 NEXT.** The environment semantics are implemented
and tested. **No staging infrastructure exists.**

**PPR.2 needs owner decisions:**
- OD-PPR-3 (hosting/orchestration);
- OD-PPR-4 (secret store);
- OD-PPR-8 (non-production domains).

It then needs operator authorisation (rule 16) for each provisioning action
(§9).

**E16 calendar (parallel track PPR-E16, unchanged):**
- valid through **2026-11-09**;
- publication blocked from **2026-11-10**;
- successor cycle begins around **2026-10-30**.

## 27. PPR.2 — Infrastructure & Secrets Provisioning Plan — PLAN COMPLETE / OPERATOR ACTION NOT STARTED (2026-10-14)

**Baseline:**
- `origin/main` = `37e0b319` (PPR.1), with green exact-SHA and `main`
  `CI gate`;
- ruleset 24909660 active, no bypass actor, drift `ok`;
- clean tree, one worktree, historical stash untouched.

### 27.1 Owner boundary

> **PPR.2 planning is authorised. Infrastructure provisioning is not.**
> No resource in this plan may be created or changed without a separate
> rule-16 owner approval for that specific operator action.

**Approvals are per action.** Each later instruction names:
- the runbook step (`R-nn`/`D-nn`);
- the target role "staging";
- the operator reference;
- the expected evidence id.

No approval implies another. **None was given in PPR.2, and nothing was
created:** no account, network, database, Redis, bucket, secret, DNS
record, certificate, credential or process.

### 27.2 Exact contracts planned (ADR 0058 §6, unchanged)

| Row | Control (verbatim) | Required evidence (verbatim) | Status |
|---|---|---|---|
| E05 | "S1 edge: `TRUSTED_PROXIES`, HTTPS detection, HSTS, client-IP limiting" | "Real edge addresses configured; the three behaviours demonstrated" | DEPLOYMENT_REQUIRED |
| E08 | "Managed external secret store and per-process injection" | "Store selected; per-process secret groups; `DB_ADMIN_*` only in release/console" | PROVIDER_REQUIRED |
| E09 | "ADR 0050 production-equivalent environment (edge, web, 3 workers, 1 scheduler, PG 16 TLS, Redis auth, private S3, runtime role, env separation)" | "`platform:verify-database` and `platform:verify-storage` 0 failed; process manifest applied" | DEPLOYMENT_REQUIRED |

**No row changes status.**

### 27.3 What the plan contains

**Three new operator documents**, vendor-neutral and authoritative for the
future work:

| Document | Contents |
|---|---|
| `docs/operations/STAGING-INFRASTRUCTURE-ARCHITECTURE.md` | zones and deny-by-default network rules; edge and HTTPS (TLS at the edge, application HSTS, trusted proxies); PostgreSQL 16 TLS and the three database roles; credential-to-process map; Redis (`noeviction`, auth, logical databases); private, versioned, encrypted bucket; secret-store requirements S1–S7 and the full secret inventory per role; the process topology from `processes.json` (web, `worker-default`/`-integrations`/`-notifications`, one scheduler, release and console one-shots; Gateway not deployed); TLS email capture; synthetic-data bootstrap; the PPR.3 artifact dependency; HA, scale and cost drivers; monitoring and backup hooks |
| `docs/operations/STAGING-PROVISIONING-RUNBOOK.md` | atomic steps R-01 to R-20, each with prerequisites, approval, action, verification, undo, secrets and evidence id; deprovision steps D-01 to D-17 |
| `docs/operations/STAGING-EVIDENCE-PLAN.md` | the E05, E08 and E09 checks and their PASS criteria (REQUIRED vs RECOMMENDED), and the complete staging configuration inventory (public/secret, environment-specific, same/different from production) |

**Workers.** The three workers are exactly the manifest's: one per queue
the code dispatches to.

| Worker | Carries |
|---|---|
| `default` | outbox dispatch and reconciliation, automation |
| `integrations` | webhooks |
| `notifications` | email, Communications |

There is **no conflict** with `processes.json`.

**Scheduler.** It runs 30 tasks and is a single replica. Missed runs are
detected by the heartbeat alerts OBS-05, OBS-06 and OBS-07.

**HA is not required for staging.** Security, configuration and topology
shape are identical to production. Capacity and HA are not, and PPR.5 and
PPR.8 can still be meaningful at that size.

### 27.4 What waits for PPR.3

**Blocked until PPR.3:** the application roles (R-14 to R-20). Staging runs
only a VERIFIED digest by digest (ADR 0052): no Git-checkout deployment, no
host build. The registry (E13) and signing custody (E14) are PPR.3.

**Not blocked** (each still needs its own approval):
- network (R-01) and management path (R-02);
- edge and DNS (R-03 to R-05a);
- PostgreSQL (R-05 to R-07);
- Redis (R-08);
- bucket (R-09, R-10);
- secret store (R-11, R-12);
- email capture (R-13).

### 27.5 Findings (reported, not changed — PPR.2 changes no code)

| # | Finding | Disposition |
|---|---|---|
| P2-F1 | `processes.json` gives web, all three workers and the scheduler the same `app_runtime` group, and `ProductionConfigurationGuard` requires several of its secrets in every hardened process (`APP_KEY`, the metrics token, the AI context key, the mail secrets when email is on). Narrowing within `app_runtime` per role (e.g. a future SendGrid key only for `worker-notifications`) needs a code change: a process-role-aware guard and a split group. | E08's required "per-process secret groups" is met by the existing manifest. Finer narrowing is **OD-PPR-22** (RECOMMENDED before PPR.6 enables any provider key) |
| P2-F2 | The contact and statutory-identifier lookup HMAC keys are not boot-checked. A missing key fails closed at first use (`ContactLookupKeyNotConfiguredException`), not at boot. | The staging configuration inventory requires them. A boot check is a candidate for a later engineering slice (no code in PPR.2) |
| P2-F3 | Redis TLS is not required by the hardened contract (private network + authentication, ADR 0050). `predis` supports a `tls://` `REDIS_URL`, but this repository has never proven it. | RECOMMENDED where offered, but only after a verification step; not part of E09 |

### 27.6 Decisions before real provisioning

| ID | Decision | Blocks |
|---|---|---|
| OD-PPR-3 | Hosting/orchestration platform | R-01 onward |
| OD-PPR-4 | Secret store (and that staging uses the same mechanism) | R-11, R-12 |
| OD-PPR-5 | Registry and signing custody (PPR.3) | R-14 to R-20 |
| OD-PPR-6 | Observability backend (PPR.4) | backend only; R-19 hooks need no vendor |
| OD-PPR-8 | Domains: staging platform host, custom-domain test, sending domain | R-03 to R-05a |
| **OD-PPR-22** (new) | Finer per-role secret narrowing (P2-F1): keep the manifest groups for staging, or schedule the guard/manifest change | before PPR.6 |
| **OD-PPR-23** (new) | A synthetic fixture generator, if manual entry proves too slow (default: none) | none now |
| **OD-PPR-24** (new) | Region / data location for staging (and later production) | R-01 |
| **OD-PPR-25** (new) | IaC: Terraform in a future (not created) `environments/staging` directory under `infrastructure/terraform` (ADR 0050 §13: state remote/encrypted/locked, apply deploy-gated) vs recorded console actions | R-01 onward |
| — | Backup destination (PPR.5) and email-capture implementation (a Mailpit-equivalent with STARTTLS is the plan; the product choice follows OD-PPR-3) | R-13; PPR.5 |

**IaC.** No IaC exists beyond the provider-neutral input interface
(`infrastructure/terraform/README.md`). None was added: provider-neutral
modules would be decoration (ADR 0050 §13).

### 27.7 Verification and status

**Change.** Documentation only: three new operations documents, the
evidence-record cross-links, the operations index, the roadmap, readiness
§65 and this section. **Application code changed: zero.**

**Verification:**
- the repository-documents guards;
- whitespace;
- gitleaks;
- the CI tooling tests;
- the docs-lane exact-SHA `CI gate`.

**Status: PPR.2 — PROVISIONING PLAN COMPLETE / OPERATOR ACTION NOT
STARTED.**
- E05, E08 and E09 are **not** complete.
- The next possible action is the first separately approved operator step,
  once OD-PPR-3, OD-PPR-24 and OD-PPR-25 are decided: **R-01, create the
  staging private network.**

**E16 calendar (PPR-E16, parallel):**
- valid through **2026-11-09**;
- publication blocked from **2026-11-10**;
- successor cycle around **2026-10-30**.

## 28. PPR.2D — Hosting, Region & IaC Decision — FROZEN (2026-10-14)

**Baseline:**
- `origin/main` = `b22693ad` (PPR.2 plan), with green exact-SHA and `main`
  `CI gate`;
- ruleset 24909660 active, no bypass actor, drift `ok`;
- clean tree, one worktree, historical stash untouched.

**Scope.** A decision checkpoint only. Nothing was created: no cloud
account, network, database, Redis, bucket, secret store, registry, DNS
record, certificate, credential or process.

**How the decisions were made.** The owner instructed this checkpoint to
resolve OD-PPR-3, OD-PPR-24 and OD-PPR-25. They were selected by evaluation
against the published contracts and current provider documentation.

**What they bind:**
- **They bind** the planning documents.
- **They do not authorise** any resource.
- **R-00a,** the first operator action (§28.10), needs its own rule-16
  approval. That approval is the owner's natural point to confirm or
  override these choices before anything is created.

### 28.1 Requirements the provider must meet

These are taken from the published contracts, not from industry habit:

| # | Requirement | Source |
|---|---|---|
| H1 | A public TLS edge/load balancer with explicit proxy addresses | ADR 0050 §2; E05 |
| H2 | Private networking with deny-by-default rules | STAGING-INFRASTRUCTURE-ARCHITECTURE §1 |
| H3 | Managed **PostgreSQL 16**, private, TLS, encrypted at rest | ADR 0050 §7; E09 |
| H4 | PITR with RPO ≤ 15 min and a **35-day** operational backup window; restore into a separate target | ADR 0050 §9, §11; E10, E11 |
| H5 | An authenticated, private Redis-protocol service | ADR 0050 §6; E09 |
| H6 | Object storage with the **S3 API**: the application uses Laravel's `s3` disk, and `platform:verify-storage` reads versioning, encryption and the public-access block through the S3 API. Private, versioned, encrypted, with an independent copy | ADR 0050 §8; ADR 0011 |
| H7 | A managed secret store injecting per-process groups before boot | E08; architecture §7 |
| H8 | Immutable OCI images run by digest; long-running web, three workers and **one** singleton scheduler; one-shot release/console tasks; the runtime security contract (non-root, cap-drop ALL, no-new-privileges) | ADR 0050 §3, §12; ADR 0052; `runtime-security.json` |
| H9 | Monitoring hooks: private metrics scrape and stderr log collection | ADR 0051 |
| H10 | A private TLS email-capture service whose certificate the **unchanged** image trusts (peer verification on, no custom CA option: `SmtpEmailProvider`) | PPR.1 §26.4 |
| H11 | Environment separation; mature IaC support | ADR 0050 §13, §16 |

### 28.2 Providers evaluated

**Shortlist:** AWS, Google Cloud, Microsoft Azure, DigitalOcean.

| | AWS | Google Cloud | Azure | DigitalOcean |
|---|---|---|---|---|
| PostgreSQL 16 (H3) | RDS for PostgreSQL 16 ✓ | Cloud SQL ✓ | Flexible Server ✓ | Managed PostgreSQL ✓ |
| Backup window (H4) | automated backups **0–35 days**, PITR within the window ✓ (RDS user guide) | 1–365 retained backups ✓; PITR log retention not stated on the backups page | ✓ (not decisive) | **7 days** of daily backups/PITR ✗ against the 35-day window |
| Redis (H5) | ElastiCache (Valkey/Redis OSS); AUTH/RBAC requires in-transit TLS (see F-D1) | Memorystore ✓ | Azure Cache/Managed Redis ✓ | Managed Caching ✓ |
| Object storage, S3 API (H6) | **S3 native** ✓ | XML API "interoperable with **some** tools" (partial); S3 bucket-configuration reads not documented ⚠ | Blob Storage has **no native S3 API**; needs a gateway ✗ | Spaces (S3-compatible); versioning and public-access block parity unverified |
| Secret store (H7) | Secrets Manager, per-container injection in ECS task definitions ✓ | Secret Manager ✓ | Key Vault ✓ | none managed (external store needed) ✗ |
| Containers (H8) | **ECS with EC2 capacity** ✓: `dockerSecurityOptions: ["no-new-privileges"]`, `linuxParameters.capabilities.drop: ["ALL"]`, non-root `user`, never `privileged`; services, one-shot tasks, a single-replica service. **ECS on Fargate ✗**: AWS lists `dockerSecurityOptions` as not valid on Fargate, so no-new-privileges cannot be set (F-D4) | GKE ✓ (`allowPrivilegeEscalation: false`); Cloud Run cannot express it ⚠ | AKS ✓; Container Apps ⚠ | DOKS ✓; App Platform ⚠ |
| Registry/signing (OD-PPR-5) | ECR (OCI artifacts, cosign-compatible) — compatible, not decided | Artifact Registry — compatible | ACR — compatible | DOCR |
| Region in India | ap-south-1 Mumbai, ap-south-2 Hyderabad (ECS ✓, ElastiCache ✓, Secrets Manager endpoint ✓ — AWS docs) | asia-south1, asia-south2 | Central India, South India | BLR1 |
| IaC (H11) | Terraform AWS provider: mature ✓ | ✓ | ✓ | ✓ |
| Audit | CloudTrail ✓ | Cloud Audit Logs ✓ | Activity Log ✓ | limited |
| Operational complexity | moderate | moderate | moderate | low |
| Lock-in | moderate (IaC per provider; the application is portable: S3 API, PostgreSQL, Redis, OCI images) | moderate | higher (no S3 API) | low |
| Staging cost profile (relative, no figures) | medium | medium | medium | lowest, but fails H4/H7 |

### 28.3 OD-PPR-3 — hosting provider: **AWS**

**Why AWS.** It is the only shortlisted provider meeting H1–H11 **natively,
without weakening a contract or adding a component**:
- **Object storage:** S3 itself (H6), so `platform:verify-storage` works
  as designed.
- **Backups:** RDS's 35-day backup window and PITR match ADR 0050 exactly
  (H4).
- **Secrets:** per-container secret injection from the secret store maps
  onto `processes.json` groups (H7).
- **Containers:** ECS with EC2 capacity expresses every field of the
  runtime security contract (H8). Fargate does not (F-D4).

**Why not the others:**
- **Google Cloud:** close, but its S3-API storage is only partially
  interoperable (H6).
- **Azure:** no native S3 API (H6).
- **DigitalOcean:** a 7-day backup window and no managed secret store (H4,
  H7).

None was rejected on price.

**Tradeoffs:**
- AWS-specific IaC and operations knowledge.
- EC2 capacity to patch instead of Fargate (F-D4).
- ElastiCache authentication requires TLS (F-D1).
- Moderate cost even at small sizes.
- The application itself stays portable.

**Staging → production.**
- **Intended model:** AWS is the **intended production provider** too
  (same model: TLS behaviour, private networking, secret injection,
  database engine and backups, artifacts), so staging evidence is
  relevant to production.
- **Separation:** separate AWS accounts per environment (§28.6).
- **If production later moved elsewhere,** everything provider-specific
  would need requalification:
  - E05, E08, E09, E10, E11 and E12;
  - the email-capture design;
  - the IaC.

### 28.4 OD-PPR-24 — region: **AWS ap-south-1 (Asia Pacific, Mumbai)**

**Criteria:**
- **Users:** the expected users are Indian Schools. The product implements
  Indian statutory payroll, GST/RTE questions and DPDP-oriented reviews, so
  latency is lowest in India.
- **Services:** every needed service is available (ECS, ElastiCache and
  Secrets Manager confirmed in AWS's regional documentation; RDS, EC2 and
  S3 are core regional services).
- **Recovery:** a second Indian region (ap-south-2 Hyderabad) exists for a
  later cross-region copy if PPR.5 wants one. ADR 0050 §9 does not require
  cross-region.
- **Production:** compatible with an Indian production region.
- **Email:** independent of the email provider (SendGrid has no India
  region and is a separate E17 question).

**Alternatives:**
- **ap-south-2 (Hyderabad):** newer, and a reasonable secondary.
- **ap-southeast-1 (Singapore):** outside India. It would create a
  cross-border question while the localisation question is unresolved.

**Placement.** One region holds everything:
- runtime, PostgreSQL, Redis, storage and the secret store;
- backups, in the same region with separate administrative control
  (ADR 0050 §9).

No service has to live elsewhere.

**Legal caveat.** No repository decision mandates India residency:
- data residency is recorded as an open organisation decision
  (`PHASE-0O-READINESS.md` §3, category C);
- India data-localisation is noted as an open exposure (ADR 0037).

Choosing ap-south-1 is an **operational choice, not legal approval**. It
avoids creating a cross-border transfer while that question is open, but
a later legal or privacy determination may still require re-evaluating the
production region or the processors.

### 28.5 OD-PPR-25 — provisioning method: **Terraform**

**Options:**
- **(A) Terraform:** reproducible, reviewable plans, drift visibility,
  deterministic teardown (D-steps), staging/production parity, and
  evidence linked to a plan hash.
- **(B) Recorded console actions:** less tooling up front, but weak
  reproducibility, manual drift, error-prone rebuilds, and a staging →
  production gap.

**Chosen: Terraform (A).**
- **It matches the repository:** ADR 0050 §13 already defines Terraform's
  place (`infrastructure/terraform`, per-environment directories, remote
  locked state, deploy-gated apply).
- **It suits PPR's needs:** atomic, evidence-linked steps and repeatable
  teardown.

**State and security contract** (to be implemented in the first IaC
change, not now):
- **Versions:**
  - HashiCorp Terraform CLI pinned to an exact version (`required_version`);
  - the AWS provider pinned exactly, with the `.terraform.lock.hcl`
    (hashes) committed;
  - upgrades are reviewed changes, and only to releases at least 14 days
    old (the ADR 0052 supply-chain posture).
- **Licence.** Terraform is BSL 1.1. Internal use is permitted, and
  OpenTofu is a drop-in alternative if the owner prefers MPL.
- **State backend:**
  - an S3 bucket per environment account: versioning, SSE-KMS, public
    access blocked, TLS-only policy;
  - S3 native state locking;
  - access only for the Terraform operator role;
  - classified **Highly Sensitive** (ADR 0050 §18).
- **Never committed:** state, plans, `.terraform/` or `*.tfvars` with
  values. A `.gitignore` in `infrastructure/terraform` enforces this from
  now on.
- **Separation:** staging and production state are separate (separate
  accounts, buckets and keys).
- **Secrets:**
  - Terraform creates secret **containers** and access policies only;
  - values are written out of band by an operator (runbook R-12), never in
    `.tf`, variables, state outputs or plans;
  - sensitive outputs are not emitted.
- **Flow:**
  1. a reviewed IaC change through the protected `main` path;
  2. `terraform plan -out` by the operator;
  3. owner/operator rule-16 approval of that plan (its hash);
  4. `apply` of exactly that plan;
  5. verification;
  6. an `EVID-…` entry carrying the plan hash.

  CI never holds cloud credentials (ADR 0052) and never plans or applies.
  CI may later run offline `fmt -check`/`validate` only. **A committed
  `.tf` file or plan authorises nothing.**

### 28.6 Cloud-credential policy (no credential created now)

- **Accounts:** an AWS Organization with separate accounts. The staging
  account is created in R-00a; production, backup and restore accounts
  come later, as decided in PPR.5.
- **Root user:** MFA, unused for routine work.
- **Operators:** sign in through IAM Identity Center as `OP-nnn` with
  short-lived credentials. Permission sets are separate for:
  - Terraform apply;
  - read-only verification;
  - secret writing.

  No long-lived access keys.
- **Audit:** CloudTrail is on from account creation.
- **Application identity:** ECS **task roles** (least privilege per role).
  `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` stay **unset**, so the
  credential chain is used (the guard allows both-absent).
- **Staging and production** never share an identity, role or key.

### 28.7 Implications for the open decisions (not decided here)

| Decision | Implication |
|---|---|
| **OD-PPR-4** (secret store) | AWS Secrets Manager is the natural fit: per-container injection in ECS, KMS encryption, CloudTrail audit and rotation support satisfy S1–S7. An external store remains possible. **Still open**; this selection narrows it. |
| **OD-PPR-5** (registry/signing, PPR.3) | ECR (OCI artifacts, immutable tags, cosign-compatible signatures) and AWS KMS for signing custody are compatible. GHCR and keyless signing remain possible. **Still open.** |
| **OD-PPR-6** (observability, PPR.4) | Options: CloudWatch Logs (stderr from ECS), Amazon Managed Service for Prometheus or a scraping collector for `:9102`, or an external backend. **Still open.** |
| **PPR.5** (backups) | RDS automated backups (35 days) + PITR; snapshot copy or AWS Backup into a separate account/vault; S3 versioning + replication to a separate bucket/account; restore into a separate VPC/account (E11 isolated target). |
| **OD-PPR-22** (narrower secrets) | **Stays OPEN.** ECS can inject per container, but the boot guard still requires several `app_runtime` secrets in every process (P2-F1). Narrowing needs the application change. |
| **OD-PPR-23** (fixture generator) | **Stays OPEN / DEFERRED** (use the bootstrap and UI workflows first). |

### 28.8 Findings from the decision

| # | Finding | Disposition |
|---|---|---|
| F-D1 | ElastiCache enables AUTH/RBAC **only with in-transit TLS**. Authenticated Redis (required by the guard) on AWS therefore means TLS, and the application's `predis` TLS path is unproven (P2-F3). | **Prerequisite of R-08 (not R-01):** a small engineering verification proving `predis` with TLS + AUTH against a TLS Redis (and with ElastiCache's publicly trusted certificate) before R-08 is approved. If it fails, the fix is an application change in its own slice. |
| F-D2 | The SMTP adapter verifies the peer with the image's system CA store and has no custom-CA option. A Mailpit with a private-CA certificate would fail. | **Email-capture design:** an **internal Network Load Balancer with a TLS listener and a public ACM certificate** for a name in the staging domain. The application uses `MAIL_SMTP_TLS=implicit` (supported). The NLB forwards to Mailpit in the private subnet; no relay; SMTP egress denied. No application change. |
| F-D4 | **ECS on Fargate cannot set no-new-privileges.** AWS's "task definition differences for Fargate" page lists `dockerSecurityOptions` (and `privileged`) as not valid on Fargate. `runtime-security.json` requires `no_new_privileges: true` and says "an unsupported field blocks deployment"; five approved vulnerability exceptions are conditional on that contract. | **Compute is ECS with an EC2 capacity provider**: ECS-optimised Amazon Linux AMI, small instances, patched through AMI refresh. The task definitions set `dockerSecurityOptions: ["no-new-privileges"]`, drop ALL capabilities, a non-root user and never `privileged`. **E09-3 evidence must show `NoNewPrivs: 1` and zero capabilities from inside each running role** (the `verify-images.sh` checks, on the real platform). The API reference states the ECS agent must be registered as security-option capable (`ECS_SELINUX_CAPABLE`/`ECS_APPARMOR_CAPABLE`) before security options apply; the container-instance configuration sets this, and the measurement proves it. EKS was considered and is heavier to operate; it remains an alternative. |
| F-D3 | Choosing Terraform and AWS adds two operator prerequisites before R-01: the staging account and operator identities (R-00a), and the Terraform state backend (R-00b). R-01 itself then needs a reviewed Terraform change for the network. | Runbook amended (R-00a, R-00b). The R-01 IaC is the first repository IaC change, made in its own reviewed slice. |

### 28.9 Cost profile (relative; no bill promised)

**Drivers, from the largest staging cost down:**
- RDS PostgreSQL, the smallest burstable class plus storage and 35-day
  backups;
- ElastiCache, the smallest node;
- EC2 capacity for the ECS tasks (web, three workers, scheduler, Mailpit): one or two small instances;
- the public ALB and the internal NLB;
- NAT/egress (minimised by VPC endpoints for S3, ECR and Secrets
  Manager, the default-deny egress);
- S3, including versions;
- Secrets Manager, per secret;
- ECR;
- CloudWatch/monitoring;
- backup storage.

**Scale-down levers:**
- single-AZ for staging;
- the smallest classes;
- one web task;
- stopping the EC2 capacity between qualification runs.

None of these changes the security semantics.

### 28.10 Readiness

**Decisions: YES.** OD-PPR-3, OD-PPR-24 and OD-PPR-25 are frozen. No other
*decision* blocks R-01.

**Before R-01's network can exist, three non-decision prerequisites remain**
(F-D3):
1. **R-00a:** create the staging AWS account in an AWS Organization, enable
   CloudTrail, set up IAM Identity Center operator access. **Rule-16
   approval.**
2. **R-00b:** create the Terraform state backend in the staging account.
   **Rule-16 approval.**
3. **The R-01 Terraform change:** a reviewed repository slice. It creates
   nothing until its plan is approved.

**The first possible operator action is R-00a.** The prompt the owner may
give, if and when they choose (it is not executed by this checkpoint):

> "Rule-16 approval: PPR.2 runbook step **R-00a** — create the AWS
> Organization (or use the owner's existing one) and a dedicated
> **staging** member account in **ap-south-1**, enable an organisation
> CloudTrail trail, enable IAM Identity Center with operator **OP-001**
> (role: Infrastructure Operator) on short-lived credentials, root user
> MFA-protected and unused. No VPC, no database, no other resource.
> Evidence: `EVID-E09-2026-1` (E29-safe; account id never recorded)."

**Status: PPR.2 — PLAN COMPLETE / PROVIDER-REGION-IaC FROZEN /
PROVISIONING NOT STARTED.**
- E05, E08 and E09 are not complete.
- E16: valid through **2026-11-09**; successor cycle around
  **2026-10-30**.

## 29. Post-V11 PPR re-entry reconciliation (2026-10-16, record only)

**Authority.** The owner's post-V11 transition brief. Baseline: `main` =
`53e29ca6`. V11 is reconciled (ADR 0075 §21–§22), with the product-side
staging handoff YES. **Nothing was provisioned, no credential was created
or requested, and no product code changed.**

### 29.1 Checkpoint map

| Slice | State | Evidence |
|---|---|---|
| PPR.0 | Complete | §1–§25 |
| PPR.1 | Complete | §26, `37e0b319` |
| PPR.2 | Plan complete; **operator action not started** | §27 |
| PPR.2D | Frozen (AWS, ap-south-1, ECS on EC2, Terraform) | §28 |
| PPR.2 **R-00a** | Approved by the owner 2026-10-14, **BLOCKED**, no resource created; re-verified here (§29.2) | — |
| PPR.2 R-00b, R-01 to R-13 | Not started (each needs R-00a and its own approval) | — |
| PPR.2 R-14 to R-20 | Blocked until PPR.3 (VERIFIED digest) | §27 |
| PPR.3 | Not started (needs PPR.2, OD-PPR-5, a VERIFIED app image) | — |
| PPR.4, PPR.5, PPR.6, PPR.7, PPR.8 | Not started (need PPR.2) | — |
| PPR.9 | Not started (parallel; needs OD-PPR-10/-11/-12/-15/-19) | — |
| PPR.10 | Not started (legal answers outstanding) | — |
| PPR.11 | Last | — |
| PPR-E16 | Gate current: OWNER-0O-E16-2026-10-16 (E16-S1, `c638121e`), valid through **2026-11-12**, hard stop **2026-11-13**. **App image NOT VERIFIED**: three Critical and one High Debian-nginx findings with no trixie fix. **E16-R2 HELD** (candidate `fb29aff5` on `integration/e16-r2`, unpublished) | `docs/security/release-remediation/` |

**Date drift.** §26–§28 and the roadmap row say E16 is valid through
2026-11-09 with publication blocked from 2026-11-10. E16-S1 superseded
those dates (2026-11-12 and 2026-11-13). The earlier text is kept as
written.

### 29.2 R-00a re-verification (once)

**Still BLOCKED.**
- **What changed since 2026-10-14:** an AWS CLI is now installed
  (`aws-cli/2.37.4`).
- **What is still missing:**
  - no profile, credentials or region is configured (`aws configure list`
    shows all four entries unset);
  - `aws sts get-caller-identity` fails with `NoCredentials`;
  - Terraform is not installed;
  - no `AWS_*` variables and no `LYCENZA_OPERATOR_EVIDENCE_ROOT` are set.
- **How it was checked:** through the standard credential chain only. No
  credential search, no key request, nothing installed, no account touched.
- **Consequence:** the AWS Organization state (precondition §1) still
  cannot be determined.

**What the owner must provide to unblock R-00a.** Nothing below may be
improvised:
1. **An authorised short-lived session** in this environment, or the owner
   performing R-00a in the console. A short-lived session means IAM
   Identity Center or `aws login`, never long-lived access keys.
2. **The Organization status:**
   - if one exists, it must be all-features mode and owner-controlled;
   - if none exists, creating one needs a separate **R-00a0** approval.
3. **A member-account root mailbox.** It is never recorded.
4. **The CloudTrail log destination,** if no organisation trail exists.
5. **The Identity Center permission-set scope**
   (Terraform-apply, read-only verify, secret-writer).
6. **An operator evidence root outside Git** (OD-PPR-9 store).

**Reconciliation item.** The runbook's R-00a text ("protect the root user
with MFA") must be reconciled with the root-access posture the owner
approves for R-00a. This is done when the R-00a evidence is recorded, not
now.

### 29.3 Work that does not need AWS

| Item | Nature | Blocks | Ready? |
|---|---|---|---|
| **F-D1 / P2-F3:** prove `predis` with TLS + AUTH (and a publicly trusted certificate) | Engineering verification on a local TLS Redis; an application change only if it fails | R-08 | **Yes**, no decision needed |
| Reviewed R-00b / R-01 Terraform change | First IaC slice (F-D3); needs a pinned Terraform toolchain decision | R-01 | After the tooling decision; cannot be planned against a real account |
| P2-F2: boot check for the lookup HMAC keys | Small code slice | Staging configuration confidence | Yes |
| OD-PPR-22: per-role secret narrowing | Guard + manifest change | PPR.6 | Owner decision first |
| PPR.9 runbooks (key rotation, incident/breach, tenant support) | Documentation | PPR.11 | OD-PPR-10/-11/-12/-15 |
| Legal requests (E17, E21 ratification, E30–E34, PAY-L1, breach framework) | Owner sends | PPR.6, PPR.10 | Drafts exist; sending is the owner's action |
| E16-R2 monitoring | Weekly Debian trixie nginx / Grype check | PPR.3 (VERIFIED app image) | Cadence; last check 2026-10-16 |

### 29.4 Prerequisites before the first staging deployment (R-14)

1. R-00a, R-00b and R-01 to R-13 approved and evidenced:
   - E09 platform;
   - E08 secrets, including both lookup HMAC keys and the
     `MAIL_STAGING_DELIVERY=capture` sink;
   - E05 database / storage verifiers, 0 failed.
2. F-D1 proven before R-08.
3. OD-PPR-8 non-production domains, DNS and public TLS certificates.
4. PPR.3:
   - OD-PPR-5 registry and signing custody;
   - **VERIFIED app and Gateway digests** built from a published `main`
     SHA;
   - E16 valid on that date. The app image is blocked today by the nginx
     Criticals.
5. Synthetic data only (OD-PPR-2). No real Student or customer data, and no
   benchmark data.
6. Automated database backups and point-in-time recovery enabled at R-05,
   so the E10 history accrues before the E11 drill.

### 29.5 Recommendation

**The next PPR checkpoint is the F-D1 Redis TLS + AUTH verification.** It
is the highest-value AWS-independent item that needs no owner decision:
- it is a named prerequisite on the staging path (R-08);
- it is the only PPR.2D data-tier assumption still unproven;
- if it fails, the application change it needs should land before
  infrastructure exists, not after.

It is not started automatically. R-00a stays blocked until the owner
provides authorised access (§29.2).

## 30. F-D1 — Redis TLS + authentication verification (2026-10-16)

**Authority.** The owner approved F-D1 (2026-10-16). It is a local
protocol and client proof. **No AWS access was used and no cloud resource
was created.** R-08 is **not** complete. F-D1 clears only its stated
prerequisite.

### 30.1 Redis architecture (as found)

- **Client:** Predis 3.6.0 (`REDIS_CLIENT=predis`, set in the production
  image). No phpredis is compiled in; Horizon is not installed; the AI
  Gateway uses no Redis.
- **Two connections, one set of credentials.** Both `default` and `cache`
  read `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME` and
  `REDIS_PASSWORD`. They differ only in the logical database:

| Connection | Database | Used by |
|---|---|---|
| `default` | `REDIS_DB` | queues; cache locks (`lock_connection`, including `TenantLock` and scheduler overlap locks); Redis sessions; the metrics hash |
| `cache` | `REDIS_CACHE_DB` | the cache store, and with it the rate limiter, the session handoff store and the AI replay store |

- **Readiness:** probes `default`.

### 30.2 Configuration contract

No new variable and no code path were needed. Laravel's
`ConfigurationUrlParser` maps `tls://` and `rediss://` to Predis's `tls`
scheme and carries `?ssl[...]` into the stream context. Predis enables
crypto with PHP's defaults, so peer and hostname verification stay on.

**Contract:**
- `REDIS_URL=tls://<endpoint>:6379`, with no credentials in the URL;
- `REDIS_PASSWORD` always;
- `REDIS_USERNAME` only for an ACL user;
- `?ssl[cafile]=` only for a private CA.

Recorded in `docs/operations/STAGING-INFRASTRUCTURE-ARCHITECTURE.md` §5,
`STAGING-EVIDENCE-PLAN.md`, the R-08 runbook step and `.env.example`.

### 30.3 Finding F-D1-1 (FIXED): readiness ignored the connection configuration

**Defect.** `OperationalStatusService::redis()` built its own Predis client
with a hard-coded `scheme => 'tcp'`, reading only host, port and password.
Against a TLS-only Redis it reported `unhealthy`, which would fail E09-5 and
keep every role out of service. Against a plaintext Redis wrongly
configured as `tls://` it reported `healthy`: a silent downgrade.

**Fix.** The probe now resolves `default` through Laravel's own
`RedisManager`, overriding only its short timeouts. This is the same
principle as 0O.4A's `sslmode` fix for PostgreSQL.

**Test.** `Tests\Feature\Observability\RedisReadinessConnectionTest` covers:
- the URL being followed;
- no TLS downgrade;
- the probe's own timeout;
- the `tls`/`rediss` parameter contract.

Two of its tests fail on the previous code.

### 30.4 Live proof

**Evidence id:** `EVID-PPR2-2026-1`.
- **Raw evidence:** outside Git under the operator evidence root
  `ppr/EVID-PPR2-2026-1/`. It holds the `before-fix` and `after-fix`
  runs, the harness, certificate subjects (never keys), server
  configuration notes and a leak check.
- **Role:** Engineering, authorised by Owner 2026-10-16.

**Setup:**
- **Runtime:** the production application image runtime (PHP 8.3.35,
  Debian OpenSSL) with the repository code mounted.
- **Network:** a disposable `--internal` network, isolated from everything
  else.
- **Servers:**
  - **Redis 7.4.11** (the digest pinned in `verify-images.sh`) and
    **Valkey 8.1.9**;
  - `port 0` (no plaintext listener), `tls-port 6379`, no client
    certificates;
  - a default-user password and an ACL user.
- **Certificates:** a generated test CA, a server certificate with
  endpoint SANs, an unknown CA, and an expired certificate signed by the
  trusted CA. Passwords and keys were generated per run and deleted.

**Before the fix:** at `b2bea79c`, everything below passed except
readiness, which reported `unhealthy` on a valid TLS connection (4 of 4
positive cases).

**After the fix:** 36 of 36 checks passed.

- **TLS:** TLS 1.3 (`TLS_AES_256_GCM_SHA384`) on both connections, both
  servers and both auth modes.
- **Consumers:**
  - cache put/get/forget, and TTL expiry (server TTL 2 s, then gone);
  - a lock held and a second acquire refused;
  - rate-limiter hits;
  - Redis session write and read;
  - the metrics hash;
  - readiness `healthy` in about 0.02 s.
- **Queue:**
  - a job dispatched over TLS;
  - a `lycenza console queue:work` worker (the deployment entrypoint) ran
    it;
  - a worker with a wrong password, or presented an unknown CA, consumed
    nothing and logged only the exception class.
- **Refused:**
  - unknown CA;
  - system trust only (private CA not supplied);
  - wrong hostname (SAN mismatch);
  - expired certificate;
  - wrong, missing or wrong-user credentials;
  - plaintext to the TLS port;
  - TLS to a plaintext server.

  Readiness reported `unhealthy` in each of those cases.
- **Leak check:** no password or private-key material in any captured
  output. Predis errors name only `tls://host:port`.

### 30.5 Findings reported, not fixed (outside F-D1)

| ID | Finding | Recommendation |
|---|---|---|
| F-D1-2 (**RESOLVED §31**) | `ProductionConfigurationGuard` requires a Redis password but neither requires TLS nor refuses `ssl[verify_peer]=0` / `verify_peer_name=0` in a hardened environment. ADR 0050 §6 does not require Redis TLS, but AWS needs it for auth | A small guard slice before R-14, so that a disabled-verification Redis configuration refuses to boot. Whether to require TLS outright is an owner decision (it amends ADR 0050 §6) |
| F-D1-3 | When Redis **hangs** rather than refuses (for example TLS to a plaintext endpoint, or a blackholed address), readiness takes about 10 s. The probe itself stops at its 0.5 s budget; the delay is the failure counter written through the metrics store to the same Redis, with Laravel's default 10 s connection timeout. This predates F-D1 | PPR.4: bound the connection timeout explicitly (a `timeout` on the Redis connections) or keep the readiness metric off the failing dependency, and align it with the load-balancer health-check timeout |

### 30.6 Not proven here (R-08 / E09 on the real service)

- ElastiCache's actual certificate chain verified by the image CA bundle.
- Cluster mode, replicas, failover, Multi-AZ, and IAM authentication.
- The guard-hardened `APP_ENV=staging` boot against the real endpoint,
  including scheduler and web roles under load.

**Status.** F-D1 is **PASS**: the Predis TLS + auth assumption is proven,
with one wiring defect fixed. R-00a stays blocked (§29.2).

## 31. F-D1-2 — Hardened Redis TLS configuration guard (2026-10-16)

**Authority.** The owner approved F-D1-2 and decided the contract: **a
hardened environment's Redis is authenticated TLS with certificate and
hostname verification on.** Local development is unchanged.
- **Recorded as:** the ADR 0050 implementation amendment of 2026-10-16.
  The owner cited "§6"; the Redis rules there are §2, §4 and §15.
- **Resolves:** finding F-D1-2 (§30.5).

**Guard.** `ProductionConfigurationGuard` now checks every configured Redis
connection on its effective Predis parameters:
- `redis_tls_required`;
- `redis_tls_verification_disabled`: `verify_peer`/`verify_peer_name`
  false by PHP truthiness, or `allow_self_signed` true;
- `redis_configuration_unsupported`: another client, a cluster set,
  `options.parameters`, or an `ssl` option outside `cafile`, `capath`,
  `peer_name` and the flags.

What stays the same:
- `redis_password_missing` is unchanged;
- a private CA (`cafile`/`capath`) remains supported;
- refusals print codes only.

**Parity.** `HardenedRedisTransportParityTest` checks the guard's reading
against the real RedisManager → Predis parameters for 12 connection
shapes. They include URL driver versus explicit scheme, a scheme on the
host, and upper-case `TLS://`.

**Release tooling:**
- **`verify-images.sh`** runs a TLS-only throwaway Redis with its own CA,
  passed to the app as `REDIS_URL=tls://…?ssl[cafile]=`. It adds checks
  that:
  - Redis answers over verified TLS;
  - Redis has no plaintext listener;
  - the built image refuses a plaintext URL and a URL with hostname
    verification off;
  - no password is printed.

  The existing web, replay-store and readiness checks now run over TLS.
- **`verify-production-bootstrap.sh`** uses the same TLS Redis.

**Readiness** is not redesigned (F-D1 already resolves it like the
application). **F-D1-3** stays assigned to PPR.4.

**Status.** F-D1-2 is **complete**. R-08 is still not done, and R-00a
stays blocked (§29.2).
