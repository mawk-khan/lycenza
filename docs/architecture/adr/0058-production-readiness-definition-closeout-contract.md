# ADR 0058: Production Readiness Definition & Closeout Contract

- Status: Accepted (definition-of-done contract; documentation only)
- Date: 2026-09-28 (Phase 0O.12)
- Resolves: **O1**, "Phase 0O definition of done per scope item"
  (`docs/architecture/PHASE-0O-READINESS.md` §8), **as a definition**. O1 is
  **not satisfied** by this ADR (§9).
- Supersedes: the proposed per-item definition of done in
  `PHASE-0O-READINESS.md` §9 (the original readiness matrix, written against
  the pre-0O.1 baseline).
- Collects the "contribution to O1" sections of:
  - ADR 0049 §20 (S1: API and browser hardening);
  - ADR 0050 §19–§20 (S3: infrastructure, secrets, recovery);
  - ADR 0051 §20 and §22 (S2: observability);
  - ADR 0052 §5–§6 (O16: supply chain);
  - ADR 0053 §15 and §17 (O5: service auth);
  - ADR 0054 §14 and §17 (O9: custom domains);
  - ADR 0055 §23–§24 (O13: email);
  - ADR 0056 §20–§21 (O14: account recovery);
  - ADR 0057 §10 (O2/O15: integrations and payments).
- Amends, by note (no rewrite): ADR 0049 (stale status line), ADR 0050
  (the AI Gateway is conditional), ADR 0055 (the provider adapter is
  mandatory for O1), ADR 0056 §17 item 6 (promoted to an O1 blocker).

> **Current status (pointer, 2026-09-29).** This ADR is append-only. The
> §6 register is authoritative for each row, and the dated notes at the end
> supersede body text written earlier. Body sections that are now out of
> date:
> - §4.14 and §9: E24 is REPOSITORY_COMPLETE (Phase 0O.12B);
> - §9 and §11: the blocker list and repository-work count;
> - §4.6, §4.8 and §11: `c4b1b6c`; the latest qualified commit is
>   `e52c4c4`.
>
> The provider is selected (ADR 0060). Since 2026-09-29, §9's "Phase 0M:
> BLOCKED" row is superseded for Phase Zero by ADR 0061. Read the latest
> dated note last.

## 1. Context (audited 2026-09-28, `origin/main` `8512701`)

- **Repository.** Phases 0O.1 to 0O.11A are complete (`PHASE-0O-READINESS.md`
  §13–§40). The last executable qualification is `c4b1b6c`:
  - 6,445 tests, 0 failures, only the deliberate ESI-12 skip;
  - application `sha256:b7638488850a1dc511408850726b67c0b8be826d007a2b3c3dc79368a1eff804`,
    **VERIFIED**;
  - AI Gateway `sha256:f46ef81166502864eeea0136a3cc3607edfcaa89d8a47a4d48a7898365f73905`,
    **VERIFIED**;
  - PUBLISHED = NONE, PROMOTED = NONE.
- **Decisions.** O2–O16 are resolved (ADRs 0049–0057). O1 was the only open
  decision. The readiness audit said there was "no written definition of
  done; section 9 proposes one per item, and the owner must confirm it"
  (`PHASE-0O-READINESS.md` §1).
- **§9 is stale.** Its rows describe the pre-0O.1 state. Examples: "143
  unthrottled `/api` routes", "no token issuance", "no production image, no
  IaC, no runbook, no backup".
- **Stale owner-value wording.** Some places still call V1–V5 "owner values
  still required", although they were approved and frozen on 2026-09-25
  (ADR 0049 implementation amendment):
  - ADR 0049's Status line;
  - `PHASE-0O-READINESS.md` §8 rows O7 and O11, and §15 (historical).

**Findings of this audit (each verified directly):**

1. **`main` is not protected on GitHub.** The public repository
   `mawk-khan/lycenza` reports, through the GitHub REST API on 2026-09-28:
   - `GET /repos/…/branches/main`: `"protected": false`,
     `"protection": {"enabled": false, …}`;
   - `GET /repos/…/rulesets`: `[]`;
   - `GET /repos/…/rules/branches/main`: `[]`.

   ADR 0052 §3.15 requires "a commit reachable from protected `main`". The
   repository's check proves something narrower:
   - `release-qualification.yml` tests `GITHUB_REF == refs/heads/main` and
     `git merge-base --is-ancestor`;
   - `verify-artifact`'s `source_lineage` tests ancestry of `origin/main`.

   Both prove the commit is **on** `main`, never that `main` is
   **protected**. No document claims protection is configured. The phrase
   "protected main" was a label, not a fact.
2. **No production path provisions staff or School-admin login Users.** It
   is worse than ADR 0056 §17 item 6 recorded: a **fresh production install
   cannot create or activate its first School.**
   - Only two production-reachable code paths create a `User`:
     - `platform:bootstrap-root`, which creates the first root only and is
       refused once any active root exists
       (`PlatformRootProvisioningService::bootstrapFirstRoot`);
     - Guardian invitation acceptance (`GuardianAccountActivationService::accept`),
       which works only for an active School.
   - `platform:provision-root` and platform role governance act only on
     existing accounts.
   - Creating a School requires an **existing** account that is not the
     acting operator as its bootstrap administrator
     (`SchoolLifecycleAuthority::bootstrapTarget`: "No account has exactly
     that email or identifier.", `self_nomination`).
   - Activation refuses a School with no qualifying administrator
     (`SchoolLifecycleService`, `admin_missing`).
   - On a fresh install the only User is root, so no School can be created.
   - No route or command grants a School membership or role after
     activation, except Guardian activation (a membership with no role).
   - HR `Employee` never creates a User. `employees.user_id` is an optional
     link to an existing member (`EmployeeService::assertUserLinkageIsSafe`).
3. **The email repository work is provider-neutral, not the chosen-provider
   adapter.**
   - `EmailProviderResolver::PROVIDERS = ['none', 'fake', 'smtp']`.
   - `EmailEventAdapterResolver::ADAPTERS = ['none', 'fake']`. Its comment
     says: "No vendor adapter exists until a vendor is selected."
   - With the `none` event adapter the provider-event route answers 404.
   - ADR 0055 §23 requires "a verified provider webhook", so O13's readiness
     evidence needs a provider-specific adapter that does not exist yet.
4. **`TRUSTED_PROXIES` may be empty in production.** Empty means "trust
   nobody". The production guard refuses only trust-all
   (`trusted_proxies_unsafe`). Behind a TLS-terminating edge, HTTPS
   detection, HSTS and client-IP rate limiting therefore need real edge
   addresses. The boot check cannot prove that; only deployment evidence
   can.
5. **Retention is unresolved beyond email.** `[LEGAL REVIEW REQUIRED]` still
   stands for:
   - `MAIL_RETENTION_DAYS` (ADR 0055 §21);
   - webhook-delivery retention (`config/webhooks.php`, `INTEGRATIONS.md`);
   - Document retention (`DOCUMENTS.md`);
   - retention per category, including audit ledgers (ADR 0042);
   - retention per category in general (`DATA-CLASSIFICATION.md`,
     "Retention").

   `PHASE-0O-READINESS.md` §7 lists retention as a production-gated concern.
6. **No real restore drill exists.** `RESTORE-DRILL-RECORD.md` says
   "_None yet._", and ADR 0050 §20 makes one successful drill part of
   Phase 0O closure.

## 2. Decision — what "Phase 0O complete" means

**Phase 0O COMPLETE means:** the repository and the production-readiness
evidence required by the accepted Phase 0O contracts are complete enough to
**authorize a separate production deployment decision**.

**It does not mean:**
- production is live;
- real School data has been connected;
- every optional feature is enabled;
- Phase 0M is unblocked.

Go-live stays a separate, explicit rule-16 authorization (§8).

## 3. Evidence categories and statuses

Every O1 item belongs to exactly one category:

| Category | Meaning |
|---|---|
| **Repository** | Code, tests, guards, runbooks and qualification in this repository |
| **Provider tail** | Bounded repository work that cannot start until a real provider is selected |
| **Deployment** | An authorized operator's evidence from a real, isolated, production-equivalent environment **without real School data** |
| **Legal** | A qualified legal/compliance decision recorded in the repository |
| **Governance** | Controls outside the code (source-branch protection, signing custody, approvals) |
| **Conditional** | An optional subsystem: mandatory evidence only if it is enabled |

Every register row (§6) has exactly one status:

| Status | Meaning |
|---|---|
| `REPOSITORY_COMPLETE` | The repository part is done and qualified |
| `DECISION_REQUIRED` | An owner decision or a contract must come first |
| `PROVIDER_REQUIRED` | A real provider or service must be selected first |
| `DEPLOYMENT_REQUIRED` | Operator evidence from a real environment is outstanding |
| `LEGAL_REVIEW_REQUIRED` | A qualified legal/compliance decision is outstanding |
| `GOVERNANCE_REQUIRED` | A governance control is not yet active in reality |
| `CONDITIONAL_DISABLED` | Optional, disabled in its documented fail-closed mode |
| `EVIDENCE_COMPLETE` | The required evidence exists and is recorded |

No other wording ("mostly done", "partially ready") describes an O1 item.

## 4. Definition of done per scope item

### 4.1 Repository foundations (Phases 0O.1–0O.11A)

**REPOSITORY_COMPLETE:**
- S1 API/browser hardening;
- production configuration guards;
- the production images and process contract;
- database bootstrap and verification;
- Redis-loss reconciliation;
- storage verification;
- backup/restore tooling;
- observability instrumentation;
- supply-chain qualification;
- service authentication;
- custom domains;
- the provider-neutral email substrate;
- account recovery;
- the integration scope contract;
- manual/offline payment recording.

This does **not** claim provider-specific tail code (§4.11) or the
provisioning path (§4.14; since completed as E24, Phase 0O.12B).
(Cross-references corrected 2026-09-29; they read §4.8 and §4.10.)

### 4.2 S1 — API and browser hardening (ADR 0049)

**Repository (REPOSITORY_COMPLETE):**
- every production `/api/v1` route throttled;
- finite API credentials and scopes;
- the partner production catalog fail-closed
  (`PartnerScopeRegistry::PRODUCTION` stays empty, ADR 0057);
- exact CORS;
- the browser security-header and HSTS contract;
- the trusted-proxy implementation (`TrustedProxyList`, never `*`);
- no production partner route without explicit approved scope.

**Owner values (resolved, frozen 2026-09-25):**

| Value | Setting |
|---|---|
| V1 | human token default **30 days** |
| V2 | human token maximum **90 days** |
| V3 | partner default **90 days** |
| V4 | partner maximum **365 days** |
| V5 | HSTS `max-age` **31,536,000** |

They are no longer "owner values still required" anywhere.

**Deployment (DEPLOYMENT_REQUIRED).** The real edge/proxy addresses are
configured in `TRUSTED_PROXIES`. The following are demonstrated through the
real edge:
- HTTPS detection;
- the HSTS header on HTTPS responses;
- the real client IP reaching the rate limiters.

### 4.3 S2 — Observability (ADR 0051)

The repository portion is **REPOSITORY_COMPLETE**. Local metrics, log
tests and fixture evidence files **never** close S2.

**Deployment (DEPLOYMENT_REQUIRED):**
- telemetry reaches a real external backend; no vendor is named here;
- structured, sanitized logs are retained **≥ 30 days**;
- operational metrics are retained **≥ 90 days**;
- the required OBS alert rules are loaded and evaluated (the
  `platform:alerts-export` render with its operator values);
- alert routing reaches the intended operator channel, proven by a test
  notification;
- backup freshness feeds the backend (`OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE`,
  OBS-20–22);
- the restore-drill overdue alert (OBS-23) is active;
- collector/backend health is monitored (OBS-26).

### 4.4 S3 — Infrastructure, secrets and recovery (ADR 0050)

The repository portion is **REPOSITORY_COMPLETE** (0O.4A).

**Deployment (DEPLOYMENT_REQUIRED).** The ADR 0050 model is demonstrated in a
real, isolated, production-equivalent environment **without real School
data**. No customer traffic is needed.
- **Processes:**
  - one TLS edge;
  - a stateless web process;
  - workers for `default`, `integrations` and `notifications`;
  - **exactly one** scheduler.
- **Data stores:**
  - PostgreSQL 16 with TLS (`DB_SSLMODE` ≥ `require`);
  - Redis with authentication;
  - private S3-compatible object storage with versioning, encryption at
    rest and a public-access block.
- **Secrets:**
  - a managed external secret store and injection (PROVIDER_REQUIRED: no
    vendor is selected);
  - least secret exposure per process;
  - `DB_ADMIN_*` absent from every long-running role.
- **Database roles:** the fixed `school_os_app` runtime role with its
  bootstrap grants (`platform:verify-database`: 0 failed).
- **Environment:** explicit trusted proxies; environment separation (one
  bucket and one database per environment).
- **Backups:**
  - an independent object-storage backup copy;
  - PostgreSQL PITR meeting **RPO ≤ 15 min**;
  - object-storage backup meeting **RPO ≤ 24 h**;
  - recovery objectives **RTO ≤ 4 h** for PostgreSQL and **≤ 8 h** for
    objects.
- **Procedures:**
  - the Redis-loss recovery and reconciliation path exercised
    (`platform:recover-queued-work`);
  - one maintenance-window release performed
    (`MAINTENANCE-WINDOW-RELEASE.md`).

### 4.5 Real restore drill — HARD BLOCKER

O1 cannot close until **one authorized drill** succeeds in a **real,
isolated, non-production environment** using the real backup mechanisms.
A local or simulated run never counts (`RESTORE-DRILL-RECORD.md`).

**Required evidence:**
- PostgreSQL restored from PITR or a snapshot;
- object data restored or reconciled from the independent copy or
  versioning;
- the application booted against the restored environment;
- `platform:verify-restore` and `platform:verify-database` passed (RLS,
  database roles, security checks);
- a Document/object sample validated;
- the achieved recovery point measured;
- the RPO/RTO outcome recorded;
- `RESTORE-DRILL-RECORD.md` appended, with evidence only and never payload
  data;
- the deployment evidence file's `restore_drill` set, so OBS-23 clears.

### 4.6 O16 — source governance (new finding, GOVERNANCE_REQUIRED)

**Before any production publication or promotion:**
- `main` is protected by an actual GitHub branch protection rule or
  repository ruleset consistent with ADR 0052;
- force-pushes and deletion of `main` are prevented;
- changes reach `main` only through the project's controlled integration
  path;
- release qualification runs from that protected source boundary.

This ADR adds no GitHub policy beyond what ADR 0052 requires. It does not
choose required reviewers, status checks or merge methods. ADR 0052 §3.15's
protected CI environment for signing/publish jobs is part of §4.7.

"`main` is protected" must be **true in reality**, shown by the GitHub API or
settings evidence, not by workflow copy.

**Fresh qualification.** `c4b1b6c` was qualified while `main` was
unprotected. A **fresh release qualification after protection is active** is
required before the first production promotion.

### 4.7 O16 — registry, signing and promotion (ADR 0052)

The repository controls are **REPOSITORY_COMPLETE**.

**Deployment and governance evidence:**
- an authorized OCI registry (PROVIDER_REQUIRED) with registry permissions
  applied;
- a real production signing identity or key, with custody
  (GOVERNANCE_REQUIRED; `artifact-policy.json` signing custody is
  `unconfigured`);
- the artifact signature and attestations actually produced;
- the **same VERIFIED digest** stored in the registry, never a rebuild per
  environment;
- aggregate verification (`verify-artifact`) enforced **before** promotion;
- an explicit promotion event;
- the first promoted artifact's evidence retained
  (`retained-releases.json`, today empty);
- a rollback candidate and an evidence-retention policy;
- production deployment accepts a **PROMOTED digest only**.

Pushed ≠ promoted.

### 4.8 Vulnerability-exception clock

The `c4b1b6c` qualification relies on owner-approved exceptions
(`OWNER-0O6E-2026-09-26`) that **expire 2026-10-10 and 2026-10-26**.
`c4b1b6c` is **not** an indefinitely promotable artifact. At the actual
qualification and promotion date, every required exception must still be
valid. Otherwise one of these must hold:
- updated packages remove the finding; or
- a fresh, explicit owner/security decision exists.

There is **no automatic renewal**. If the O1 evidence program passes
either expiry date, requalification under the then-current scan database
and policy is mandatory.

### 4.9 O5 — AI Gateway (CONDITIONAL_DISABLED)

**AI Gateway integration is not required for O1.** ADR 0053 made the
production default "not configured" (`AI_GATEWAY_BASE_URL` has no default).
Unset, it boots, reports Degraded `not_configured`, and readiness is
unaffected (rule 56).

**In that mode none of these is needed:**
- real service signing keys;
- a rotation or revocation drill;
- a private Gateway TLS deployment.

**Before any production environment enables the Gateway**, all of ADR 0053
§15 is mandatory:
- real keys through managed custody;
- the correct ring on every replica;
- a routine rotation drill;
- an emergency revocation drill;
- the private TLS network, including TLS in front of the Gateway.

This does **not** unblock Phase 0M.

### 4.10 O9 — custom domains

**Production enablement is CONDITIONAL_DISABLED.**
`CUSTOM_DOMAINS_ENABLED=false` is a complete, safe mode, and production v1
may launch with it. If custom domains are enabled in production, all of
ADR 0054 §14 is mandatory there **before** enablement.

**A non-production exercise is mandatory (DEPLOYMENT_REQUIRED).** O14's
drill must sign out sessions on two hosts, the platform host and a custom
School domain (ADR 0056 §20). So O1 still needs one real non-production O9
exercise proving:
- the edge target;
- real ownership verification of a non-production domain;
- certificate issuance;
- HTTP → HTTPS;
- private-key custody outside the application;
- the routing/TLS probe;
- drift monitoring;
- one revoke/re-add drill.

### 4.11 O13 — email (mandatory for v1)

Email is required for v1, and O14 depends on it. O1 cannot close with
`MAIL_PROVIDER=none` or with fake or local delivery.

**Provider and legal evidence:**
- an actual transactional email provider selected (PROVIDER_REQUIRED);
- a legal/processor review of that provider (LEGAL_REVIEW_REQUIRED).

**Deployment evidence (DEPLOYMENT_REQUIRED):**
- the real sending domain, with provider domain verification;
- SPF;
- aligned DKIM;
- DMARC **≥ `p=quarantine`** at readiness, reached only after **≥ 14
  days** of 100 % aligned DKIM pass for real traffic (ADR 0055 §17);
- managed credential custody;
- a delivery test;
- hard-bounce handling, complaint handling and suppression demonstrated;
- a credential rotation;
- deliverability monitoring;
- authenticated provider events through the provider webhook.

**Provider code tail (mandatory, bounded).** This amends ADR 0055's "SMTP
baseline used knowingly (no events)" option: that option does **not**
satisfy O1. Once a provider is selected, a separate checkpoint:
- implements the **smallest** provider-specific event-authentication and
  normalization adapter for that provider (no generic provider
  authentication scheme is ever invented);
- adds an HTTPS sending adapter **only if** the provider requires it or
  clearly benefits from it. Hardened SMTP may stay the submission adapter
  if the provider's supported architecture satisfies ADR 0055;
- runs focused tests, the full canonical regression and a full O16
  requalification.

This ADR selects no provider.

### 4.12 Retention — decision A

**`MAIL_RETENTION_DAYS` stays `[LEGAL REVIEW REQUIRED]`.** The same holds for:
- webhook-delivery retention;
- Document retention;
- audit retention and retention per category, repository-wide.

**Owner decision: option A.** These legal retention decisions are
**mandatory before Phase 0O closeout**, not deferred to go-live.

**Reason.** Phase 0O is "External Surface and Production Readiness". The
repository already records retention as a production-gated concern
(`PHASE-0O-READINESS.md` §7). ADR 0050 §8 forbids lifecycle expiry of
current or noncurrent object versions while retention is unresolved.

So O1 cannot close until a qualified legal/compliance review records the
production retention policy for the categories **actually used in v1**.
Engineering never invents the periods. Implementing the recorded periods,
where the repository has a setting or a prune path, is part of closeout.

### 4.13 O14 — account recovery

Self-service recovery must be production-ready for password accounts.

**Required (DEPLOYMENT_REQUIRED):**
- O13 critical email ready (§4.11);
- `ACCOUNT_RECOVERY_ENABLED=true` set deliberately in the production-
  equivalent validation environment, on a real canonical platform origin;
- drills:
  - a successful recovery;
  - an expired link;
  - replay and concurrency;
  - a reset that signs out sessions on **two hosts** (platform and a custom
    School domain, §4.10);
  - MFA still required afterwards;
- monitoring and alerts active (OBS-39–41);
- the operator runbook exercised: `platform:user-password-reset` and the
  lost-MFA escalation (`platform.users.mfa.reset`).

MFA is never weakened.

### 4.14 Staff / School-admin account provisioning — O1 BLOCKER

**Owner decision.** A production v1 that real Schools can operate **must**
have a supported way to provision ordinary staff and School-admin login
Users. The audit (§1, finding 2) shows none exists, and that a fresh install
cannot create its first School. This **is an O1 blocker**
(DECISION_REQUIRED).

It is not solved here. A narrowly scoped checkpoint is required before
final O1 closeout, contract first. Provisional title: **Phase 0O.12A —
Staff / School-Admin Account Provisioning** (contract, then foundation).

**That contract recovers the smallest correct path from the Identity/HR
boundaries**, for example:
- operator-assisted account provisioning, which also closes the first-School
  bootstrap gap;
- School-admin staff invitation and provisioning.

**Fixed now:**
- Employee is not User. HR's employment lifecycle never creates
  authentication accounts automatically.
- Guardian activation is never a workaround for staff accounts.
- The ADR 0047 bootstrap rules still hold. Its exception (platform-side
  membership writes only while `provisioning`) is not widened by
  implication.
- MFA and ADR 0056's credential rules apply to every new account path.

### 4.15 Manual payment correction — recorded debt, not an O1 blocker

The owner has frozen this. Posted manual Payments are immutable, and v1 has
no correction action (ADR 0031 implementation amendment, ADR 0057
implementation note). The append-only payment-correction contract remains a
Finance follow-up. It is not reopened here.

## 5. O1 definition of done

**O1 is SATISFIED, and Phase 0O is COMPLETE, only when every mandatory
item below holds:**

1. **Repository and qualification.** Every required Phase 0O repository
   component is implemented, including the provisioning path (item 10) and
   the email provider tail (item 7). The current full regression and O16
   qualification are green on the final closeout commit.
2. **Source governance.** `main` is genuinely protected under the release
   trust contract (§4.6).
3. **Environment.** A real production-equivalent environment demonstrates
   ADR 0050: infrastructure, secrets, database roles, Redis, S3 policy,
   processes and backup (§4.4). The S1 edge evidence is part of it (§4.2).
4. **Restore drill.** One real, isolated restore drill has succeeded and is
   recorded (§4.5).
5. **Observability.** A real observability backend, retention and alert
   routing are active (§4.3).
6. **Retention.** The required legal retention decisions for v1 data are
   recorded (§4.12).
7. **Email.** A real production email provider is integrated, authenticated
   and drilled, with SPF/DKIM/DMARC and monitoring (§4.11).
8. **Account recovery.** O14 recovery has been drilled end to end,
   preserving MFA and revoking sessions as contracted (§4.13). This includes
   the non-production O9 exercise (§4.10).
9. **Registry and promotion.** A real, authorized registry, signing and
   promotion path satisfies O16 (§4.7).
10. **Provisioning.** A supported production staff/School-admin account
    provisioning path exists (§4.14).
11. **Optional subsystems.** Any optional subsystem enabled in production
    has its own deployment evidence (§4.9, §4.10). Disabled optional
    subsystems remain in their documented fail-closed mode.
12. **Vulnerability exceptions.** Outstanding exceptions are valid at
    qualification and promotion time, or replaced by fixes or new explicit
    decisions (§4.8).
13. **Evidence hygiene.** All evidence is recorded without secrets,
    credentials, hostnames or connection strings, and without School data.

**Then:** O1 = **RESOLVED / SATISFIED** and Phase 0O = **COMPLETE**.
Customer production go-live remains a **separate authorization** (§8).

## 6. O1 evidence register (authoritative)

**Owners:**
- **Owner:** the project owner.
- **Operator:** an authorized operator acting under rule 16.
- **Engineering:** repository work through the normal integration path.
- **Legal:** qualified legal/compliance review.
- **Security:** the owner's security decision-maker.

**Evidence locations:** readiness sections are `PHASE-0O-READINESS.md`;
runbooks are `docs/operations/`. Evidence is **referenced**, never copied
with secrets.

| ID | Control / evidence | Source | Category | Mandatory? | Status | Required evidence | Evidence location | Owner | Blocks O1 |
|---|---|---|---|---|---|---|---|---|---|
| E01 | Phase 0O repository foundations (0O.1–0O.11A) | ADRs 0049–0057 | Repository | Mandatory | REPOSITORY_COMPLETE | Implemented, tested, qualified | Readiness §13–§40 | Engineering | No |
| E02 | Final regression + O16 qualification of the closeout commit | ADR 0052 §3.15; §5 item 1 | Repository | Mandatory | GOVERNANCE_REQUIRED | Fresh qualification of the closeout commit on protected `main`, after: E03 (protection active in reality); E18 (the selected provider's code merged); every other mandatory executable repository change; and E16 (every vulnerability exception valid on the qualification date, or replaced by fixes or a fresh decision). Both images VERIFIED. (Corrected 2026-09-29; read "after E03, E17, E24".) | Future readiness section; `RELEASE-QUALIFICATION.md` | Engineering + Operator | Yes |
| E03 | `main` protected (branch rule or ruleset) | ADR 0052 §3.15; §4.6 | Governance | Mandatory | GOVERNANCE_REQUIRED | GitHub API/settings show protection; force-push and deletion refused | GitHub settings (not in repo); dated note in readiness | Owner | Yes |
| E04 | S1 repository hardening, V1–V5 frozen | ADR 0049 §20 + amendment | Repository | Mandatory | REPOSITORY_COMPLETE | Guard tests; throttled `/api/v1`; empty partner catalog | Readiness §16 | Engineering | No |
| E05 | S1 edge: `TRUSTED_PROXIES`, HTTPS detection, HSTS, client-IP limiting | ADR 0049 §12; ADR 0050 §2 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Real edge addresses configured; the three behaviours demonstrated | Operator evidence record | Operator | Yes |
| E06 | S2 repository instrumentation and alerts | ADR 0051 §20 items 1–9 | Repository | Mandatory | REPOSITORY_COMPLETE | Metrics, logs, OBS rules, runbooks | Readiness §20 | Engineering | No |
| E07 | S2 real backend, retention ≥ 30 d logs / ≥ 90 d metrics, rules loaded, routing, OBS-20–23, OBS-26 | ADR 0051 §20 items 10–12, §22 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Backend receiving telemetry; retention settings; test notification delivered; evidence file feeding OBS-20–23 | `TELEMETRY-COLLECTION.md`; operator record | Operator | Yes |
| E08 | Managed external secret store and per-process injection | ADR 0050 §4 | Deployment (provider) | Mandatory | PROVIDER_REQUIRED | Store selected; per-process secret groups; `DB_ADMIN_*` only in release/console | `PRODUCTION-IMAGES-AND-PROCESSES.md`; operator record | Owner + Operator | Yes |
| E09 | ADR 0050 production-equivalent environment (edge, web, 3 workers, 1 scheduler, PG 16 TLS, Redis auth, private S3, runtime role, env separation) | ADR 0050 §2–§8, §20 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | `platform:verify-database` and `platform:verify-storage` 0 failed; process manifest applied | Operator record | Operator | Yes |
| E10 | Backup policy active: PITR (RPO ≤ 15 min), independent object copy (RPO ≤ 24 h) | ADR 0050 §9 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Policy configured; freshness in the evidence file | `BACKUP-AND-RESTORE.md`; evidence file | Operator | Yes |
| E11 | Real restore drill (RTO ≤ 4 h PG / ≤ 8 h objects) | ADR 0050 §20; §4.5 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Drill per §4.5; PASS | `RESTORE-DRILL-RECORD.md` | Operator | Yes |
| E12 | Redis-loss reconciliation and maintenance-window release exercised | ADR 0050 §10, §14 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | One reconciliation run and one window release in the environment | `REDIS-LOSS-RECOVERY.md`; `MAINTENANCE-WINDOW-RELEASE.md` | Operator | Yes |
| E13 | Authorized OCI registry + permissions | ADR 0052 §5 | Deployment (provider) | Mandatory | PROVIDER_REQUIRED | Registry selected and authorized; permissions applied | Operator record | Owner + Operator | Yes |
| E14 | Production signing identity/key custody | ADR 0052 §3, §5 | Governance | Mandatory | GOVERNANCE_REQUIRED | Custody configured; `artifact-policy.json` signing identity set by a reviewed change | `artifact-policy.json` | Owner + Security | Yes |
| E15 | Signed, stored, verified, explicitly promoted digest; first promotion evidence retained; promoted-only deployment; rollback retention | ADR 0052 §3.14, §3.17, §3.19 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Same VERIFIED digest in registry; promotion event; `retained-releases.json` entry | `retained-releases.json`; `RELEASE-QUALIFICATION.md` | Operator | Yes |
| E16 | Vulnerability exceptions valid at qualification/promotion | ADR 0052; `OWNER-0O6E-2026-09-26` (superseded 2026-09-29 by `OWNER-0O-E16-2026-09-29`) | Governance | Mandatory | DECISION_REQUIRED | Valid on the date, or fixed, or a new explicit decision. **Current (2026-09-29):** `OWNER-0O-E16-2026-09-29`, fresh scan, 97 records valid **through 2026-10-28** (expire 2026-10-29). A fix, or a further fresh decision, is still needed for the E02/E15 dates | `vulnerability-exceptions.json`; `0O-E16-2026-09-29-owner-security-decision.md` | Owner + Security | Yes |
| E17 | Email provider selected + legal/processor review | ADR 0055 §23; §4.11 | Provider / Legal | Mandatory | LEGAL_REVIEW_REQUIRED | Selection decision (made: Twilio SendGrid, ADR 0060); processor review recorded | ADR 0060 (§20 legal amendment, future) | Owner + Legal | Yes |
| E18 | Email provider code tail (event auth/normalization; HTTPS sending only if needed) | §4.11 | Provider tail (repository) | Mandatory | LEGAL_REVIEW_REQUIRED | Adapter per ADR 0060 §22, focused tests, sandbox gates (§21), full regression, O16 requalification | Future Phase 0O.13A | Engineering | Yes |
| E19 | Sending domain, SPF, aligned DKIM, DMARC ≥ quarantine after ≥ 14 d aligned | ADR 0055 §17 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | DNS evidence; aggregate-report summary; `MAIL_SENDING_VERIFIED` attestation | `EMAIL-DELIVERABILITY.md` checklist | Operator | Yes |
| E20 | Email drills: delivery, hard bounce, complaint, suppression, rotation, monitoring, authenticated events | ADR 0055 §18, §23 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Drill record per checklist | `EMAIL-DELIVERABILITY.md` | Operator | Yes |
| E21 | Retention decisions for v1 categories (mail, webhook deliveries, Documents, audit, others used in v1) | §4.12; ADR 0042; `DATA-CLASSIFICATION.md` | Legal | Mandatory | LEGAL_REVIEW_REQUIRED | Qualified decision recorded; settings implemented where they exist | Future legal record + ADR amendment | Legal + Owner | Yes |
| E22 | O9 non-production exercise (edge, ownership, certificate, HTTPS redirect, key custody, probe, drift, revoke/re-add) | ADR 0054 §14; §4.10 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Exercise record | `CUSTOM-DOMAINS.md` | Operator | Yes |
| E23 | O14 drills (success, expiry, replay/concurrency, two-host sign-out, MFA kept), monitoring, operator runbook | ADR 0056 §20 | Deployment | Mandatory | DEPLOYMENT_REQUIRED | Drill record; OBS-39–41 active | `ACCOUNT-RECOVERY.md` | Operator | Yes |
| E24 | Staff/School-admin provisioning path (including first-School bootstrap; owner-amended to the full staff-account lifecycle incl. off-boarding) | §4.14; ADR 0056 §17 item 6; ADR 0047 D13; ADR 0059 | Repository | Mandatory | REPOSITORY_COMPLETE | Implemented, tested, qualified (`e52c4c4`) | Readiness §42–§44 | Engineering | No |
| E25 | Manual/offline payment recording | ADR 0057 §3; ADR 0031 amendment | Repository | Mandatory | REPOSITORY_COMPLETE | Implemented, qualified (`c4b1b6c`) | Readiness §39–§40 | Engineering | No |
| E26 | AI Gateway in production | ADR 0053 §15; §4.9 | Conditional | Conditional | CONDITIONAL_DISABLED | If enabled: all ADR 0053 §15 evidence before enablement | `SERVICE-KEY-ROTATION.md` | Owner + Operator | No (Yes if enabled) |
| E27 | Custom domains in production | ADR 0054 §14; §4.10 | Conditional | Conditional | CONDITIONAL_DISABLED | If enabled: all ADR 0054 §14 evidence there before enablement | `CUSTOM-DOMAINS.md` | Owner + Operator | No (Yes if enabled) |
| E28 | Deferred integrations (gateway, SMS/WhatsApp/push, government/board, accounting, partner scopes, SSO) | ADR 0057 | Conditional | Conditional | CONDITIONAL_DISABLED | Stay fail-closed; any enablement needs its own ADR | ADR 0057 §9 | Owner | No |
| E29 | Evidence hygiene (no secrets, credentials, hostnames, School data) | §5 item 13; rule 12 | Governance | Mandatory | GOVERNANCE_REQUIRED | Reviewed at each evidence record | Every record above | Operator + Owner | Yes |
| E30 | Fee receipt statutory form / GST (ADR 0062 decision J): whether a prescribed receipt or tax invoice, GSTIN, HSN/SAC, taxable value or tax lines are required, and how any fee is treated | ADR 0062 §17.5; FEE.4 note | Legal | Mandatory | LEGAL_REVIEW_REQUIRED (development authorised — FEE.4 ships a payment acknowledgement only) | Qualified answer recorded; the receipt form changed only if the answer requires it | Future legal record + ADR 0062 amendment | Legal + Owner | Yes |
| E31 | Fee regulation: limits on late fees and in-year fee changes (owner product decision H recorded 2026-09-30; the legal question stays open) | ADR 0062 §16, §27 | Legal | Mandatory | LEGAL_REVIEW_REQUIRED (development authorised for FEE.5; H decided 2026-09-30) | Qualified answer recorded before late fees are enabled in production | Future legal record + ADR 0062 amendment | Legal + Owner | Yes |
| E32 | RTE / statutory free-seat obligations for fee assessment and concessions | ADR 0062 §14.2, §27 | Legal | Mandatory | LEGAL_REVIEW_REQUIRED (development authorised; no RTE label or rule exists) | Qualified answer recorded; any required fee treatment decided by the owner | Future legal record + ADR 0062 amendment | Legal + Owner | Yes |
| E33 | TCH-L1 — teacher Attendance: whether widening access to identifiable Student attendance from administrative actors to assigned teachers needs an updated children's-data/privacy assessment, processing record or equivalent production approval | ADR 0063 §26, §39 | Legal | Mandatory | LEGAL_REVIEW_REQUIRED (development authorised — TCH.4 built and development-closed) | Qualified determination recorded; the `attendance.teacher` surface stays out of production use until then | Future `docs/security/TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md` + ADR 0063 amendment | Legal + Owner | Yes |

A row moves to `EVIDENCE_COMPLETE` only by a dated, reviewed repository
change that names its evidence location. The move is appended to
`PHASE-0O-READINESS.md`; this table is never silently edited.

## 7. Non-blocking debt register

Recorded, **not** O1 blockers, and **not** fixed opportunistically:

| Debt | Source | Why it does not block O1 |
|---|---|---|
| Email webhook reads a chunked body before its own 256 KiB check | Readiness §39 | Edge/PHP body caps still apply |
| `platform.webhook_test.v1` subscribable in production (inert) | ADR 0057 §5.2, §13 | Inert test event; environment-gate it in a later webhook change |
| Partner-write `api_client` idempotency actor | ADR 0057 §13 | Partner writes are disabled; no production scope exists |
| AI context-token debt (ADR 0023: no `kid`/rotation); unknown AI agent/tool answers 500 | ADR 0053 §12 and amendment; `docs/ai/AI-PLATFORM.md` | AI is disabled (§4.9); Phase 0M blocked |
| Self-service lost-MFA recovery | ADR 0056 §12, §17 item 5 | Operator `platform.users.mfa.reset` path exists |
| Signed-in password-change UI | ADR 0056 §17 item 3 | Recovery and the operator reset exist; a future flow must use `CredentialChangeService` |
| Manual-payment correction | §4.15 | Owner froze immutable v1 records |
| Other subledger journal entries reversible through the generic action | Readiness §39 | Existing ledger behaviour; Finance follow-up |
| LMS interoperability | ADR 0057 | Cancelled |
| Person-counting Analytics | `ANALYTICS.md`; ADR 0048 | Its own gate stays fail-closed (no cohort default) |
| Malware scanning | ADR 0011; `DOCUMENTS.md` | Documented deferral remains accepted. Any feature that depends on scanning (e.g. LMS Submission uploads) stays blocked by its own gate |
| Qualification host needs Docker's classic image store | Readiness §40 | Tooling limitation, not an artifact risk; a tooling follow-up |

## 8. Boundary — Phase 0O closeout versus go-live

**Phase 0O closeout does not itself authorize:**
- a live production deployment;
- real School data;
- a DNS cutover;
- customer onboarding;
- live external communications beyond approved drill recipients.

Each needs a separate, explicit rule-16 authorization after the O1 evidence
is complete. Every drill above runs in a **non-production** environment
without real School data.

## 9. Status after this ADR

| Item | Status |
|---|---|
| **O1** | **RESOLVED AS DEFINITION OF DONE — NOT SATISFIED** |
| **Phase 0O** | **CLOSEOUT BLOCKED — DEPLOYMENT / LEGAL / GOVERNANCE / PROVISIONING EVIDENCE OUTSTANDING** |
| **Phase 0M** | **BLOCKED — LEGAL/COMPLIANCE/PRODUCT/SECURITY DECISIONS REQUIRED** |
| O2–O16 | Resolved (unchanged) |

**Mandatory blockers:** E02, E03, E05, E07–E24 and E29. **Conditional:**
E26–E28.

**Recommended order (not started):**
1. Repository first: the narrow 0O.12A staff/School-admin provisioning
   contract, then its implementation.
2. In parallel, outside the repository: the owner begins the provider and
   infrastructure decisions for the evidence program: secret store,
   registry, signing custody, email provider, observability backend and the
   non-production environment. `main` protection (E03) can be activated at
   any time.

## 10. Alternatives considered

1. **Close O1 on repository completion alone.** Rejected. ADRs 0050–0056
   each say their deployment evidence is part of closure. A green
   repository proves nothing about backups, alert routing or email
   deliverability.
2. **Retention as a go-live blocker only (option B).** Rejected by the
   owner (§4.12). Phase 0O owns production readiness, and object lifecycle
   is already frozen on it.
3. **Require the AI Gateway and production custom domains for O1.**
   Rejected. Both have complete, tested disabled modes, and ADR 0053 makes
   the Gateway optional. Only the O9 non-production exercise is kept,
   because O14 needs it.
4. **Treat staff provisioning as post-launch debt (as ADR 0056 §17 did).**
   Rejected. A fresh install cannot create its first School, so no real
   School could be operated.
5. **Accept "commit on `main`" as the protected-main check.** Rejected.
   ADR 0052's trust argument assumes `main` cannot be rewritten or pushed
   to uncontrolled.
6. **Keep the SMTP-without-events baseline for O1.** Rejected. Bounce,
   complaint and suppression evidence needs authenticated provider events.

## 11. Consequences

- O1 has a written, verifiable definition, and the §9 matrix is superseded.
- Phase 0O cannot be declared complete by repository work alone: 22
  mandatory register rows are open. Only E02, E18 and E24 involve
  repository work; the rest need provider, deployment, legal or governance
  evidence.
- Two repository checkpoints remain before closeout:
  - staff/School-admin provisioning (0O.12A);
  - the email provider tail, after provider selection.

  Each needs its own regression and O16 qualification.
- The `c4b1b6c` digests are evidence of readiness progress. They are not
  the release to promote: a fresh qualification on protected `main` is
  required.
- No code, configuration, workflow, GitHub setting or infrastructure is
  changed by this ADR.

## Note — Phase 0O.12A (ADR 0059, 2026-09-28)

**E24's decision is made.** ADR 0059 contracts two flows:
- the platform-assisted bootstrap account, from the console, displayed
  once;
- School staff invitations, using `school.members.manage` +
  `school.roles.manage`, fresh MFA and critical email.

E24 **still blocks O1** until **Phase 0O.12B** is implemented and
qualified (ADR 0059 §23).

**New finding: staff off-boarding is DECISION REQUIRED.** No School-side
action suspends a staff membership or revokes a School role. The owner
decides whether it joins 0O.12B or becomes its own row. It is **not** added
to the register silently.

## Note — Phase 0O.12B (owner decision and implementation, 2026-09-28)

**E24 is amended by the owner. It now covers the complete production
staff-account lifecycle:**
- first-School bootstrap;
- staff provisioning;
- School-role assignment;
- staff off-boarding (membership suspension);
- role revocation with history.

Off-boarding gets no separate register row. The ADR 0059 §22 "DECISION
REQUIRED" finding is resolved by this amendment and by the ADR 0059
owner amendment.

**Status.** E24 is implemented in Phase 0O.12B. It becomes
**REPOSITORY_COMPLETE** when that phase's full regression and O16
qualification of both images are recorded (`PHASE-0O-READINESS.md` §43).

**E02 still stands.** The final O1 qualification must run on protected
`main` after every mandatory executable tail, including the email provider
tail. The 0O.12B qualification does not satisfy E02 by itself.

## Note — Phase 0O.12B qualification: E24 REPOSITORY_COMPLETE (2026-09-29)

**E24 moves from DECISION_REQUIRED to REPOSITORY_COMPLETE.** The evidence
is `PHASE-0O-READINESS.md` §44: the O16 qualification of `main` `e52c4c4`
(run `local-20260929T002558Z-08871c71`). That run's same-run complete
regression passed (6,500 tests, 0 failures, only the ESI-12 skip), and
both images reached **VERIFIED**. PUBLISHED = NONE, PROMOTED = NONE.

The repository now provides:
- first-School bootstrap account provisioning (ADR 0059 Flow A);
- ordinary staff invitations (Flow B);
- credential activation;
- School membership and role creation;
- role-grant history (revoke, never delete);
- staff off-boarding by membership suspension;
- explicit reactivation;
- the concurrency-safe last-qualifying-administrator invariant;
- tenant/RLS enforcement;
- the fresh-install proof and real-PostgreSQL concurrency proof.

**Nothing else changes:**
- **O1** stays **RESOLVED AS DEFINITION OF DONE — NOT SATISFIED**.
- **E02** is still open. The `e52c4c4` digests are progress evidence, not
  the closeout qualification. E02 needs a fresh qualification on protected
  `main` after E03, E17 and the E18 provider tail.
- **Mandatory blockers now:** E02, E03, E05, E07–E23 and E29 (21 rows).
  Only E02 and E18 still involve repository work.
- **Conditional (unchanged):** E26–E28.

## Note — Phase 0O.13: provider selected, E02 corrected (2026-09-29)

**E17.** Twilio SendGrid is selected under ADR 0060: HTTPS Mail Send API,
Signed Event Webhook, provider name `sendgrid`. Postmark was rejected;
Amazon SES is the documented fallback. The processor/legal review is **not**
done, so:
- **E17:** PROVIDER_REQUIRED → **LEGAL_REVIEW_REQUIRED**;
- **E18:** PROVIDER_REQUIRED → **LEGAL_REVIEW_REQUIRED**. It is blocked by
  E17's review. Its scope is frozen in ADR 0060 §22 as Phase 0O.13A, and it
  does not start until ADR 0060 records the legal outcome.

**E02 corrected.** "After E03, E17, E24" was stale: E24 is complete, and a
provider *selection* puts no code into the release. The row now requires:
- E03;
- E18;
- every other mandatory executable repository change;
- E16 valid on the qualification date.

E02 is **not** complete.

**§4.1 cross-references** now point to §4.11 and §4.14.

**Timing.** ADR 0055 §17's 14-day aligned-DKIM observation places E02
after both exception expiries (the last passing dates are 2026-10-09 and
2026-10-25). E16 therefore needs a fresh scan and either a refresh or a
fresh owner/security decision before E02 (ADR 0060 §24). Nothing is renewed.

**E03** can and should be completed now. It is outside the repository and
not configured by this change.

**Unchanged:**
- **O1:** RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.
- **Phase 0O:** CLOSEOUT BLOCKED.
- **Mandatory blockers:** E02, E03, E05, E07–E23 and E29.

## Note — Phase Zero scope closure (ADR 0061, 2026-09-29)

The owner deferred Phase 0H's remaining scope and Phase 0M's real providers
and agents to post-v1.

**Current status.** §9's "Phase 0M: BLOCKED — LEGAL/COMPLIANCE/PRODUCT/
SECURITY DECISIONS REQUIRED" stays true for its date. The current status is
**Phase 0M: CLOSED FOR PHASE ZERO — REAL PROVIDERS / REAL AGENTS DEFERRED
POST-v1**. The AI provider gate itself is not cleared.

**Nothing in this ADR's register changes:**
- **E26** (AI Gateway in production) stays CONDITIONAL_DISABLED.
- **O1** is unchanged: RESOLVED AS DEFINITION OF DONE — NOT SATISFIED.

**Phase 0O is the only active Phase Zero closeout area.** No 0H or 0M item
blocks O1, and closing 0O never reopens either.

**E16.** The fresh scan of 2026-09-29 (`PHASE-0O-READINESS.md` §46) found no
available fix for the 12 excepted advisories. Every one of the 97 records
needs a fresh owner/security decision before 2026-10-10.

## Note — E16 exception replacement (2026-09-29)

**Decision.** Owner/security approved `OWNER-0O-E16-2026-09-29`. It is a
**new** decision after the 2026-09-29 fresh scan: the same 12 advisories and
97 records, the same classifications and runtime-hardening conditions, and
one common expiry **2026-10-29** (last passing day 2026-10-28).
- **Records.** It replaces the `OWNER-0O6E-2026-09-26` records, whose
  approval is removed from the active file. It is not an extension of them.
- **Scan change.** A newer scanner database added only one Low (CVE-2026-97399,
  glibc, no fix). Owner/security confirmed it is within scope.

**E16 register status.** It stays **DECISION_REQUIRED** in this register's
closed vocabulary. The current decision is valid now (row annotation), but
E16 needs validity **on the final E02/E15 dates**, and those will fall after
2026-10-28. It is not permanently complete.

**Qualification.** The O16 qualification of the resulting `main` commit is
recorded in `PHASE-0O-READINESS.md` (§47 and the follow-up).

**Qualified (2026-09-29).** `main` `be69b55` was qualified under
`OWNER-0O-E16-2026-09-29` in run `local-20260929T130419Z-6a83ecf8`:
- **Images:** both VERIFIED with `exception_conditions_proven` and 0
  blocking (application `sha256:39c376fa…b35ae`, 48 excepted; Gateway
  `sha256:931a70cb…ab275b`, 49 excepted).
- **Regression:** 6,500 tests, only the ESI-12 skip.
- **Details:** `PHASE-0O-READINESS.md` §48.

E16 stays DECISION_REQUIRED for the E02/E15 dates. The current decision is
valid through 2026-10-28.

## Note — product legal gates carried to production readiness (FEE.4, 2026-09-30)

- **The rule.** The owner authorised product development to continue
  through unresolved legal-review items and to resolve them in the final
  application production-readiness review. **This is not a legal
  determination.** Each such item keeps its substantive question open and
  claims no compliance. Only the conservative behaviour its ADR already
  approves is built.
- **Status.** Each item is marked **LEGAL_REVIEW_REQUIRED (development
  authorised)** — the ADR 0062 wording is "DEVELOPMENT AUTHORISED — PROD
  LEGAL SIGN-OFF REQUIRED". Each stays a mandatory production blocker in
  §6.
- **New register rows:**
  - **E30** — ADR 0062 J, fee receipt statutory form / GST. FEE.4 built a
    payment acknowledgement only: never a tax invoice, no tax fields.
  - **E31** — fee regulation of late fees and in-year changes. The
    owner's product decision H was recorded later the same day (ADR 0062);
    the legal question stays open and E31 stays a production blocker.
  - **E32** — RTE / statutory free seats.
- **Retention.** Stays **E21**: no FEE table has a purge.

## Note — TCH legal gate carried to production readiness (2026-10-01)

TCH (ADR 0063) is development-closed (TCH.6, `ff867e6`). The post-closure
production-readiness audit (ADR 0063 §39) carries TCH's legal items into
this register under the FEE.4 note's rule. **This is not a legal
determination.**

- **New row E33 — TCH-L1** (ADR 0063 §26). Teacher Attendance is built.
  The open question is whether widening access to identifiable Student
  attendance, from administrators to assigned teachers, needs an updated
  children's-data/privacy assessment, processing record or equivalent
  production approval. It is a mandatory production blocker for the
  `attendance.teacher` surface. It does not block development.
- **Gate enforcement.** E33 is enforced by **process, not runtime
  configuration.** No feature flag or setting disables teacher Attendance.
  The one production `teacher` role bundles `attendance.teacher` with the
  other three owned-scope capabilities. Until E33 is answered, granting that
  role in production would also enable teacher Attendance, and no other
  system role carries the other three. ADR 0063 §39.3 records the decision
  this leaves to the owner. **Decided 2026-10-01 (ADR 0063 §40):** no
  production `teacher` role grants while E33 is OPEN, and no role split or
  Attendance gate. E33's resolution is what permits that role in
  production, subject to E21 and the rest of O1.
- **E21 is unchanged.** "Others used in v1" in E21 includes TCH's
  authority-bearing history:
  - link history (audit only);
  - `teaching_assignments`;
  - LMS owner and Section-audience rows;
  - the Attendance and Curriculum Delivery history teachers now write;
  - the audit ledgers.

  None has a purge. ADR 0063 §39.5 lists the exact questions.

## Note — E21.1 retention decision request issued (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.** E21.1 (docs only) audited every v1
data category and issued the decision request
`docs/security/E21-RETENTION-DECISION-REQUEST.md`:
- §4: the inventory;
- §6: the open decisions E21-D0 to E21-D13, with neutral options;
- §9: the determination template. The answer is recorded as
  `docs/security/E21-RETENTION-DETERMINATION.md`.

No period, purge or legal basis was set.

- **Closure semantics, as this row already states.** E21 closes on:
  1. a qualified decision recorded per v1 category; and
  2. the periods implemented where a setting or prune path exists
     (`MAIL_RETENTION_DAYS`, `WEBHOOKS_DELIVERY_RETENTION_DAYS`).

  Whether a decided period that has **no** mechanism yet must be built
  before O1 is open decision **E21-D0**.
- **No immediate retention-safety defect.** The only scheduled deletions are
  ADR-recorded technical lifetimes. The legally gated prunes delete nothing
  while unset.
- **Engineering prerequisite before setting `MAIL_RETENTION_DAYS`.** Three
  latent `platform:email-prune` defects (request §5.2, L1–L3) must be fixed
  first:
  - identity-level messages are skipped;
  - provider references are orphaned;
  - a suppression-referenced event blocks the bulk delete.

  This makes E21 a decision **plus** a small code tail, not a decision
  alone.
- **Independence.** E21 and E33 (TCH-L1) stay separate rows. E30 is the
  receipt form, not retention.

## Note — E21 policy adopted for implementation (E21.2, 2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED` and still blocks O1.** Its state is now
three separate things:
1. **Owner policy: DECIDED, provisionally.**
   `docs/security/E21-RETENTION-DETERMINATION.md` records a project-adopted
   period, trigger and action for each of E21-D0–D13. It is not a legal
   opinion and claims no statutory period.
2. **Engineering: IN PROGRESS.**
   - E21.2A (mail L1–L4 and periods, webhooks, outbox, failed jobs, the
     School hold seam) implements the existing settings and prune paths
     this row requires.
   - E21.2B–F implement the remaining finite periods (D0: finite periods
     must be enforceable before technical closeout).
   - E21.2G is the closure audit.
3. **Final legal/compliance ratification: PENDING.** The final
   pre-production review may amend the policy, and any amendment is
   implemented before O1 clears (determination §7).

E21 moves only by a dated, reviewed change after items 2 and 3 are done.
E33 (TCH-L1) is unaffected and still blocks production `teacher` grants.

## Note — E21.2A operational retention implemented (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.** E21.2A implemented the settings and
prune paths that this row requires implementing (project-adopted periods,
pending ratification):
- **Mail:** `platform:email-prune`, with E21.1 L1–L3 fixed and L4 tested.
- **Webhooks:** delivered 30 / failed 90, as separate fail-closed
  settings.
- **Outbox:** the new `platform:outbox-prune`, 30 days.
- **Failed jobs:** the new `platform:failed-jobs-prune`, 30 days.
- **Holds:** the School hold seam `RETENTION_HOLD_SCHOOL_IDS`.

Production evidence still needs the values set in the real environment
(E09). Remaining engineering is E21.2B–F (released-suppression expiry moved
to E21.2B), then E21.2G, then final ratification.

## Note — E21.2B audit, authority history and released suppressions (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.** E21.2B implemented the project-adopted
periods for:
- **D1 audit:** 7 years;
- **D2 released suppressions:** 1 year;
- **D6 authority history:** 7 years.

It uses a narrow privileged path: `SECURITY DEFINER` functions with fixed
predicates, a database age floor and a tenant tie, executable by the runtime
role, which still holds no DELETE on any protected ledger
(`DatabaseRoleVerifier`, `retention_functions_narrow`). LMS owner/audience
stays with its parent resource. Remaining: E21.2C–F, then E21.2G and final
ratification.

## Note — E21.2C Communications and Documents retention (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.** E21.2C implemented the project-adopted
periods for:
- **D3 Communications:** content 3 years after the end of the Academic Year
  in which it was sent (ambiguous year fails closed), delivery telemetry
  1 year after the terminal state;
- **D5 Documents:** inherited retention as a closed deferral (no Document is
  purge-eligible until E21.2D/E21.2E decide its owner), and proven orphan
  objects after 30 days.

Append-only policy decisions use one more narrow retention function. The
runtime role still holds no DELETE on any protected ledger. Remaining:
E21.2D–F, then E21.2G and final ratification.

## Note — E21.2D Student and academic retention (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.** E21.2D implemented D7:
- **Operational Student history** (attendance records, rollover items,
  Guardian relationships): 7 years after final exit.
- **The core academic record** (identity, placements, subject enrollments,
  Student Documents): 25 years after final exit.

The final exit comes from dated Enrollment departures plus the `inactive`
Student status; anything ambiguous is kept. Any row in another table that
references the Student blocks the purge, which never cascades. That
includes Finance, which awaits E21.2E. No database privilege was added.
Categories D7 does not govern are recorded for E21.2G: School academic
content, attendance sessions, Guardian personal data, processing
authorizations and Admissions. Remaining: E21.2E–F, then E21.2G and final
ratification.

## Note — E21.2E Finance and HR retention (2026-10-01)

**E21 stays `LEGAL_REVIEW_REQUIRED`.**

**D9 is implemented:**
- ancillary HR sub-records go 2 years after final separation;
- employment and payroll evidence, with the Employee, goes 8 years after
  it;
- the trigger is terminal EmploymentRecords (rehire restarts the clock);
  anything ambiguous is kept;
- any row in another table that references the Employee blocks the
  purge: payroll results, teaching history, LMS ownership. Nothing is
  cascaded, and a User is never unlinked.

**D8 is audited, but expiry is BLOCKED.** No financial evidence is deleted:
- every balance is derived from all postings;
- there is no financial-year close or carried-forward opening balance;
- expiring evidence first needs a financial-year close design (an ADR),
  recorded for E21.2G.

No database privilege was added. Remaining: E21.2F, then E21.2G (including
the D8 prerequisite) and final ratification.
