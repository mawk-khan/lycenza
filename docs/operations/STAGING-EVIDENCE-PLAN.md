# Staging evidence plan — E05, E08, E09 (PPR.2, ADR 0074 §27)

**Status: DEFINITIONS ONLY. NO EVIDENCE ENTRY EXISTS.**
- An entry is written only after the action it describes actually happens
  (OPERATOR-EVIDENCE-RECORD.md, E29).
- No row changes status because of this document.

**Entry format.** `EVID-<row>-<YYYY>-<n>` in the OPERATOR-EVIDENCE-RECORD
format, with these fields:
- operator reference (`OP-nnn`) and role;
- UTC timestamps;
- target "staging" (role only, never a hostname);
- safe resource class (e.g. "managed PostgreSQL 16 instance");
- result code;
- the verification command or tool;
- minimised output: counts and closed codes;
- the repository SHA and the configuration version (secret-store version
  ids, never values);
- the raw-evidence store reference and SHA-256;
- the reviewer, where the hygiene check needs one.

## 1. E05 — S1 edge

**ADR 0058 row E05, authoritative:**
- *Control:* "S1 edge: `TRUSTED_PROXIES`, HTTPS detection, HSTS, client-IP
  limiting".
- *Required evidence:* "Real edge addresses configured; the three
  behaviours demonstrated".
- *Sources:* ADR 0049 §12, ADR 0050 §2.

**Required evidence** (each check passes through the real edge, and none
needs a code change):

| # | Check | Method (read-only) | PASS when |
|---|---|---|---|
| E05-1 | Real edge addresses configured | compare the edge's private source range with the `TRUSTED_PROXIES` value (record "match: yes" and the CIDR count, never the ranges) | exact, no trust-all; guard booted |
| E05-2 | HTTPS detection | through the edge: an unauthenticated `GET /app` returns a redirect whose `Location` is `https://…`; `Set-Cookie` carries `Secure` | both true |
| E05-3 | HSTS | `GET /api/health/live` over HTTPS: `Strict-Transport-Security: max-age=31536000`; port 80 answers only a redirect | header present; no content on 80 |
| E05-4 | Client-IP limiting | from vantage A, exceed the login throttle until 429; vantage B is not throttled; vantage A sending a forged `X-Forwarded-For` is still throttled (the edge overwrites it) | all three |
| E05-5 | Spoofing refused | a direct request to the web port from outside the trusted range is impossible (network rule) or its forwarded headers are ignored | either holds |

**E05 complete** only when E05-1 to E05-4 pass and are recorded
(EVIDENCE_COMPLETE needs a reviewed repository change, ADR 0058 §6).
E05-5 is RECOMMENDED corroboration.

## 2. E08 — managed secret store

**ADR 0058 row E08, authoritative:**
- *Control:* "Managed external secret store and per-process injection".
- *Required evidence:* "Store selected; per-process secret groups;
  `DB_ADMIN_*` only in release/console".
- *Source:* ADR 0050 §4. *Status:* PROVIDER_REQUIRED.

| # | Evidence | Class | PASS when |
|---|---|---|---|
| E08-1 | Store selected | REQUIRED | owner decision OD-PPR-4 recorded in ADR 0074 |
| E08-2 | Per-process groups injected | REQUIRED | each role receives exactly its `processes.json` groups (store policy read-back, names only); the guard boots in staging with values from the store |
| E08-3 | `DB_ADMIN_*` only release/console | REQUIRED | a web, worker or scheduler identity is **denied** reading `database_admin` (negative test); the release task can read it |
| E08-4 | Namespace separation | REQUIRED by ADR 0050 §16 | no staging identity can read a production path (if production exists; otherwise the policy shows no cross-namespace grant) |
| E08-5 | No secret in Git, image or logs | REQUIRED by ADR 0050 §4 | gitleaks clean on the repository; `verify-images.sh` / qualification secret scans clean for the deployed digest; a sampled staging log window contains no stored value (search by fingerprint of each value, record "0 matches") |
| E08-6 | Rotation | RECOMMENDED (ADR 0050 §5) | one staging secret (e.g. the capture SMTP password or `REDIS_PASSWORD`) rotated in the store and picked up by a restart, no image rebuild |
| E08-7 | Operator access control | RECOMMENDED | the write policy names only operator identities; access audit enabled; one audit record observed |
| E08-8 | Recovery | RECOMMENDED | documented procedure for loss of the store or a leaked value (revoke, rotate, restart), linked from the entry |

**E08 is not complete because a store exists.** It needs E08-1 to E08-5.

## 3. E09 — production-equivalent environment

**ADR 0058 row E09, authoritative:**
- *Control:* "ADR 0050 production-equivalent environment (edge, web, 3
  workers, 1 scheduler, PG 16 TLS, Redis auth, private S3, runtime role, env
  separation)".
- *Required evidence:* "`platform:verify-database` and
  `platform:verify-storage` 0 failed; process manifest applied".

| # | Evidence | Method | PASS when |
|---|---|---|---|
| E09-1 | Database | `console platform:verify-database` | 0 failed (includes runtime role attributes and RLS, `runtime_connection_encrypted` = PASS, which since PPR.1 FAILs unencrypted in staging) |
| E09-2 | Storage | `console platform:verify-storage` | 0 failed; independent copy recorded as operator evidence later (PPR.5) |
| E09-3 | Process manifest applied | orchestrator definition read-back vs `processes.json` | web ≥ 1; `worker-default`, `worker-integrations`, `worker-notifications` exactly 1 each; `scheduler` exactly 1 (single replica); release/console as one-shot tasks; no Gateway; runtime security contract on every container |
| E09-4 | Workers and scheduler alive | worker canaries and scheduler task heartbeats in the metrics | every queue heartbeat fresh; minute tasks fresh |
| E09-5 | Redis authenticated | unauthenticated PING refused; readiness OK | both |
| E09-6 | PostgreSQL 16 TLS | version check; non-TLS connection refused | 16.x; refused |
| E09-7 | Private stores | no public address on PostgreSQL/Redis; bucket public-access block | true |
| E09-8 | Environment separation | own database, Redis, bucket, secret namespace; `APP_URL` not local (guard `environment_not_separated` passes); nothing shared with DDEV/CI/production | true |
| E09-9 | PPR.1 contract | `APP_ENV=staging`; the guard booted (hardened); `MAIL_STAGING_DELIVERY=capture`; SendGrid unset; synthetic-data attestation by the operator | all true |
| E09-10 | No external email | a test invitation appears in the capture service and nowhere else; SMTP egress denied | both (RECOMMENDED corroboration of the PPR.1 contract) |

**E09 complete** when E09-1 to E09-9 pass. E09-10 is RECOMMENDED.

**Not part of E09:**
- backups (E10);
- the restore drill (E11);
- Redis loss and the maintenance window (E12).

## 4. Staging configuration inventory

**Classes:**
- **P** = public/non-secret;
- **S** = secret (secret store; value never in a document);
- **E** = environment-specific value;
- **=** = same as production;
- **Δ** = intentionally different from production (each Δ is listed in
  STAGING-ENVIRONMENT §3).

| Setting | Class | Staging value / rule |
|---|---|---|
| `APP_ENV` | P Δ | **`staging`** |
| `APP_DEBUG` | P = | `false` |
| `APP_URL` | P E | `https://<staging platform host>` (OD-PPR-8) |
| `APP_KEY`, `APP_PREVIOUS_KEYS` | S E | staging-generated |
| `SESSION_SECURE_COOKIE` | P = | `true` |
| `SESSION_DOMAIN` | P = | empty (host-only cookies) |
| `TRUSTED_PROXIES` | P E | the edge's private range |
| `CORS_ALLOWED_ORIGINS` | P = | empty |
| `LOG_FORMAT` / `LOG_LEVEL` / `LOG_CHANNEL` | P = | `json` / `info` / `stderr` (image defaults) |
| `METRICS_SCRAPE_TOKEN` | S E | staging-generated |
| `OBSERVABILITY_DEPLOYMENT_EVIDENCE_FILE` | P E | reserved mount (PPR.4/PPR.5) |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` | P E | `pgsql` / staging endpoint / 5432 / staging database |
| `DB_SSLMODE` | P = | `verify-full` (or `require`) |
| `DB_USERNAME` | P = | `school_os_app` |
| `DB_PASSWORD` | S E | staging |
| `DB_ADMIN_USERNAME`, `DB_ADMIN_PASSWORD` | S E | staging migration role (release/console only) |
| `DB_RETENTION_USERNAME`, `DB_RETENTION_PASSWORD` | S E | staging retention role (scheduler/console only) |
| `DB_CONNECT_TIMEOUT` | P = | 5 |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_CLIENT` | P E | staging endpoint / 6379 / `predis` |
| `REDIS_PASSWORD` | S E | staging |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER` | P = | `redis` |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | P = | `cache` / `database` (image defaults) |
| `SESSION_HANDOFF_STORE` | P = | `redis` |
| `DOCUMENTS_DISK`, `COMMUNICATION_ATTACHMENTS_DISK` | P = | `s3` |
| `AWS_BUCKET` / `AWS_DEFAULT_REGION` / `AWS_ENDPOINT` | P E | staging bucket / region / https or empty |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | S E | staging bucket identity or unset (credential chain) |
| `MAIL_PROVIDER` | P Δ | `smtp` (to the capture sink) |
| `MAIL_STAGING_DELIVERY` | P Δ | **`capture`** |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_SMTP_TLS` | P E | capture service / its STARTTLS port / `required` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | S E | the capture credential |
| `MAIL_SENDING_DOMAIN`, `MAIL_FROM_ADDRESS` | P E | staging sending domain (reserved: under the platform host) and `notifications@` it |
| `MAIL_SENDING_VERIFIED` | P Δ | unset (capture needs none) |
| `MAIL_SUPPRESSION_HMAC_KEY` (+ id, previous) | S E | staging |
| `MAIL_PROVIDER_EVENTS` | P = | `none` |
| `MAIL_SENDGRID_*`, `MAIL_PROVIDER_EVENT_SECRET` | — | **unset** (PPR.6) |
| `ACCOUNT_RECOVERY_ENABLED` | P E | `false` until PPR.7 (E23) decides otherwise |
| `CUSTOM_DOMAINS_ENABLED`, `DOMAIN_FAKES_ENABLED`, `DOMAIN_ALLOW_DEVELOPMENT_HOSTS` | P = | `false`, `false`, `false` (custom domains wait for PPR.7) |
| `DOMAIN_PROBE_KEY` | — | unset |
| `TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED` | P = | `false` |
| `AI_GATEWAY_BASE_URL` | P = | empty (E26 disabled) |
| `AI_GATEWAY_CONTEXT_SIGNING_KEY` | S E | staging-generated (the guard requires it) |
| `AI_GATEWAY_SERVICE_SIGNING_KEY`, `AI_GATEWAY_SERVICE_TOKEN` | — | unset |
| `CONTACT_LOOKUP_HMAC_KEY`, `STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY` (+ versions) | S E | staging-generated (P2-F2) |
| Retention settings (`E21-RETENTION-DETERMINATION.md` §6) | P E | as project-adopted; unset = fail-closed (E21 ratification pending) |

**No external production provider is configured in staging.**
