# ORG.11 — Staging readiness & controlled promotion record (2026-10-26)

Status: **readiness record** (ADR 0076 §80). No staging infrastructure was
created, no image was pushed or promoted, no deployment was performed.

## 1. Baseline

- `origin/main` = local `main` = `ca4769aa` (ORG.10-R1) at start; clean tree;
  one worktree; `integration/e16-r2` at `fb29aff5` (untouched); stash
  `phase-5d3-preflight` (untouched).
- CI: `CI gate` green on `ca4769aa` (9,585 cases, 9,584 passed, 1 ESI-12
  skip); `main-protection` ruleset active; publication only through
  `lycenza-ci publication-check --push` (ADR 0072).

## 2. Existing staging / pre-production work (repository truth)

| Item | Status |
|---|---|
| ADR 0074 (PPR programme) | PPR.0 contract, PPR.1 environment/data contract, PPR.2 provisioning plan, PPR.2D decisions published |
| `STAGING-ENVIRONMENT.md` | contract implemented in code: `APP_ENV=staging` first-class, hardened like production, synthetic data only, TLS email capture, VERIFIED digests only |
| `STAGING-INFRASTRUCTURE-ARCHITECTURE.md` | AWS, `ap-south-1`, ECS on EC2 (Fargate cannot set no-new-privileges), RDS PostgreSQL 16, ElastiCache (TLS + AUTH), S3, Secrets Manager (recommended) |
| `STAGING-PROVISIONING-RUNBOOK.md` | R-00a … R-20 and D-01 … D-17, **one rule-16 approval per step** |
| `STAGING-EVIDENCE-PLAN.md` | E05/E08/E09 checks, configuration inventory |
| `infrastructure/terraform/` | **contract README only; no Terraform code** (R-00b/R-01 need a reviewed Terraform change) |
| Release tooling (`infrastructure/release/`) | `qualify`, `verify-artifact`, evidence schema; registry/signing custody not configured (OD-PPR-5) |
| Hardening already published | F-D1 Redis TLS + AUTH, F-D1-2 hardened Redis guard, P2-F2 lookup-key guard |
| Prior status | R-00a approved 2026-10-14 then BLOCKED (no AWS session); owner paused staging on 2026-10-17 (local-first); ORG.11 reopens readiness |

## 3. Infrastructure — prerequisite (STOP)

- **Credentials:** checked only through the standard AWS chain — `aws
  configure list` all unset; `sts get-caller-identity` → `NoCredentials`;
  no `AWS_*` environment; Terraform not installed. No search for hidden
  credentials was made.
- **Staging environment:** **does not exist** (no account, network,
  database, bucket, cache, queue, secret namespace, mail capture, domain or
  certificate).
- **Consequence:** ORG.11 §7.5/§8.5/§104.1–104.4 — **INFRASTRUCTURE
  PREREQUISITE**. Nothing was provisioned; no account structure was created.

**What the owner must provide, in runbook order:**
1. R-00a: Organization status (none → separate R-00a0 approval), a
   short-lived operator session (Identity Center / `aws login`) or console
   execution, the member-account root mailbox (never recorded), the trail
   destination, the permission-set scope, `$LYCENZA_OPERATOR_EVIDENCE_ROOT`.
2. R-00b and the reviewed R-01 Terraform change (pinned Terraform toolchain).
3. Open decisions: OD-PPR-4 (secret store), OD-PPR-5 (registry + signing
   custody — required for any deployable artifact), OD-PPR-8 (staging
   domains), OD-PPR-6/-7 (observability, alert routing), OD-PPR-22 (per-role
   secret narrowing).
4. Each R-step's own rule-16 approval at the time.

## 4. Release artifact and supply chain

**Canonical path:** the *Release qualification* workflow (dispatch on
protected `main`; regression, lockfile integrity, language audits, source
secret scan, fresh-builder OCI builds, `verify-images.sh`, SBOM, fresh Grype
scan, provenance, signed evidence bundle, `verify-artifact`). Never pushes,
publishes or promotes.

| Run | SHA | Outcome |
|---|---|---|
| 40303377143 | `ca4769aa` | failed at install (STG-02): the workflow had never completed |
| 40306825538 | `5f2e21cd` | install fixed; failed at the regression's Compose pull of `minio/minio` (STG-05) |
| **40310279869** | **`135de265`** | **whole pipeline ran**: complete regression 9,585 tests (9,584 passed + ESI-12 skip, 367,236 assertions), source secret scan 0, language audits 2 non-blocking, both images built (no cache), `verify-images` all checks passed, SBOM + fresh scan, provenance (no SLSA level claimed), bundle signed with an ephemeral NON-PRODUCTION key |

**Verdicts (run 40310279869, NON-PRODUCTION signing):**
- **`ai-gateway`** `sha256:718d2c7f…0783` — **VERIFIED** (0 blocking, 55 excepted).
- **`app`** `sha256:1d32d00a…5c63` — **FAIL** (`vulnerability_policy_blocking_findings`), 5 blocking, 60 excepted:
  - **Critical** CVE-2026-109618 in the repository-built PHP **8.3.35** (`php-cli`, `php-fpm`) — fixed in **8.3.36** (2026-10-22);
  - High-with-fix CVE-2026-109619 in PHP 8.3.35 — fixed in 8.3.36;
  - High-with-fix CVE-2026-92392 in the repository-built **curl 8.22.0** — fixed in **8.23.0** (2026-10-20).
- **nginx cleared:** the fresh build installed Debian `1.26.3-3+deb13u10`; the three nginx Criticals (and the High) that held E16-R2 no longer match. The owner's E16 monitoring condition ("Debian fixes trixie") is met; `integration/e16-r2` was not touched.
- **Remediation (not done in ORG.11):** the frozen emergency rule allows the narrow same-major correction — PHP 8.3.35 → 8.3.36 (base `php:8.3.36-fpm-trixie` digest, source tarball + signature pins) and curl 8.22.0 → 8.23.0, through the 0O.6D custom-runtime procedure (`CUSTOM-PHP-RUNTIME.md`), then a fresh qualification. Recommended as its own supply-chain slice, as every earlier remediation.
- **Exceptions:** OWNER-0O-E16-2026-10-16 valid through 2026-11-12 (hard stop 2026-11-13; publication refused from then on without a successor decision).
- **Provenance chain proven:** `main` `135de265` → CI gate (run 40308195487) → qualification run 40310279869 (same commit, same-run regression) → digests above → `verify-artifact`. No registry/signing custody exists (OD-PPR-5), so nothing is PUBLISHED or PROMOTED.
- **Language audits (non-blocking):** `league/flysystem` ≤3.35.2 low (CVE-2026-102601) and one further non-blocking advisory, reported only.

## 5. Findings register

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| **STG-01** | STG-BLOCKER (environment) | No staging environment and no authorized cloud session | INFRASTRUCTURE PREREQUISITE (owner) |
| **STG-02** | STG-MAJOR → **FIXED** | The *Release qualification* workflow had never completed: `composer install` ran scripts before `.env` existed, so `package:discover` booted with `APP_ENV` unset (= `production`) and the boot guard refused (also a rule 87 gap: `--no-scripts` missing) | Fixed in `5f2e21cd`; `SupplyChainGuardTest` now requires `--no-scripts --no-plugins` on every workflow `composer install` |
| **STG-05** | STG-MAJOR → **FIXED** | The qualification regression pulled `minio/minio` (public images withdrawn 2026-09) and lacked CI's env-file/package-discovery/key preparation | Fixed in `135de265` (versitygw override + CI-equivalent preparation; pinned by `SupplyChainGuardTest`) |
| **STG-06** | STG-BLOCKER (artifact) | App image **not VERIFIED**: PHP 8.3.35 Critical + High (fix 8.3.36) and curl 8.22.0 High (fix 8.23.0) | Narrow same-major remediation slice + fresh qualification (owner authorization) |
| **STG-03** | STG-OBSERVATION → **RESOLVED** | Debian trixie-security now ships nginx `1.26.3-3+deb13u10`; the tracker marks CVE-2026-15176/-93479/-97205/-97629 fixed in trixie. The app image installs nginx unpinned from Debian, so a fresh build takes the fixed package (E16 monitoring trigger: "rebuild from main with Debian nginx, rescan, verify, abandon R2") | See §4 |
| **STG-04** | STG-OBSERVATION (local only) | At 09:00–09:02 UTC every DDEV container on the host was recreated by a host-level event (not by ORG.11). The WSL docker-ce DDEV `db` came back on a volume last shut down 2026-09-29 (248/346 migrations), while `https://lycenza.ddev.site` keeps being served by the Docker Desktop engine with the current ORG.10 data. CLI (`ddev exec`) and web now reach different databases. No repository or remote impact; the owner should choose which engine is canonical before the next local UAT. | Reported; not changed (shared host, other projects) |
| ORG10-D01 | UAT-MINOR (accepted) | Directory "Current" may list a not-yet-started hire (status-based, §69) | Carried to the staging handoff |

## 6. Gates

- **Staging promotion gate: FAIL** — no environment (STG-01), no registry or
  signing custody (OD-PPR-5), no secrets namespace, no database, storage,
  queue or mail capture to verify; the app image is not VERIFIED (STG-06).
- **Production: NO-GO** (unchanged): E17–E20, E21, E30–E34, E36–E47, HRX
  gates, fee-reminder email purpose, M-1, OD-8.4, OD-8.9, E16 successor
  (decision valid through 2026-11-12, hard stop 2026-11-13), production
  infrastructure.

## 7. Readiness verified without a staging environment

- **Staging-mode configuration** (`APP_ENV=staging` hardened like production):
  `tests/Feature/Configuration` + `tests/Unit/Configuration` (EnvironmentPolicy,
  ProductionConfigurationGuard, StagingParity, HardenedEnvironmentBoot),
  operator verification commands, raw RLS isolation: **268 passed**.
- **Image runtime** (`verify-images.sh` inside qualification run 40310279869):
  all checks passed on the fresh images (local only, nothing deployed).
- **Complete regression in the qualification run:** 9,585 tests, 9,584 passed,
  1 ESI-12 skip.
- **Not verifiable without staging:** DNS/TLS, generated links, sessions/cookies
  under the staging host, managed PostgreSQL roles/RLS (`verify-database`),
  migrations from empty on the target, backup/restore, rollback drill, queue and
  scheduler, cache/session isolation, object storage, mail capture, monitoring,
  every staging smoke and the staging UAT register (§88).

## 8. Staging handoff (to carry into the first promotion)

- **Known issues:** ORG10-D01 (UAT-MINOR, accepted), ORG10 observations,
  ORG.9D-1R1/ORG.10-R1 LOWs (unescaped ILIKE, own Guardian mail in the staff
  Hub, GET read markers, unread-badge cost, 60 s inactive-Campus consumer window,
  Automation owner diagnostic code), F-D1-3 (readiness ~10 s on a hanging Redis).
- **Personas:** the ORG.10 harness (`docs/uat/org-10/harness/`) is DDEV-only
  (its fixture is demo-guarded); staging personas come from
  `platform:bootstrap-root`, `platform:provision-school-admin-account` and the
  UI (R-18), never from the demo seeder.
- **Runbooks:** `STAGING-PROVISIONING-RUNBOOK.md` (R-steps and teardown),
  `MAINTENANCE-WINDOW-RELEASE.md` (release and the one rollback contract: no
  `down()`, forward fixes, PITR only as an approved recovery),
  `RELEASE-QUALIFICATION.md`.
