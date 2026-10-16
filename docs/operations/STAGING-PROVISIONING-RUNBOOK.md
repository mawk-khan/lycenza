# Staging provisioning runbook (PPR.2 plan, ADR 0074 §27)

**Status: PLAN ONLY. NO STEP HAS BEEN PERFORMED.**

> No resource in this plan may be created or changed without a separate
> rule-16 owner approval for that specific operator action.

**Approval rules:**
- **One step per approval.** Each step below is one approvable unit; an
  approval for one step never covers another.
- **The approval names:** the step id (`R-nn` or `D-nn`), the target
  environment role ("staging"), the operator reference (`OP-nnn`) and the
  expected evidence id.

**Evidence** is recorded per
[OPERATOR-EVIDENCE-RECORD.md](OPERATOR-EVIDENCE-RECORD.md) and
[STAGING-EVIDENCE-PLAN.md](STAGING-EVIDENCE-PLAN.md):
- raw evidence goes under `$LYCENZA_OPERATOR_EVIDENCE_ROOT/ppr/<EVID id>/`;
- only E29-safe entries go into the repository.

**Architecture:** [STAGING-INFRASTRUCTURE-ARCHITECTURE.md](STAGING-INFRASTRUCTURE-ARCHITECTURE.md).

**Decisions:**
- **Frozen at PPR.2D** (ADR 0074 §28): OD-PPR-3 = AWS, OD-PPR-24 =
  `ap-south-1`, OD-PPR-25 = Terraform.
- **Still needed before the steps that use them:**
  - OD-PPR-4 (secret store) before R-11;
  - OD-PPR-8 (domains) before R-03;
  - OD-PPR-5 (registry/signing, PPR.3) before R-14.

**Every step** uses staging-only values, synthetic data and no production
credential. **Downtime:** none applies, because nothing exists yet. "Rollback"
means deleting what the step created (staging is disposable).

## Step index

| Step | Action | Needs | Evidence id (row token) | Blocked until |
|---|---|---|---|---|
| R-00a | AWS Organization / staging member account in `ap-south-1`, organisation CloudTrail, IAM Identity Center operator access, root MFA | PPR.2D decisions | `EVID-E09-<YYYY>-n` | — |
| R-00b | Terraform state backend in the staging account (S3 versioned + SSE-KMS + Block Public Access + TLS-only; S3 native locking; access for the Terraform role only) | R-00a | `EVID-E09-…` | — |
| R-01 | Private network and zones, deny-by-default rules | OD-PPR-3, -24, R-00a, R-00b, a **reviewed R-01 Terraform change** | `EVID-E09-<YYYY>-n` | — |
| R-02 | Operator management path (bastion/VPN/console access) | R-01 | `EVID-E09-…` | — |
| R-03 | Edge / load balancer (443, 80→redirect), private target group | R-01, OD-PPR-8 | `EVID-E05-…` | — |
| R-04 | Staging TLS certificate at the edge | R-03, R-05a DNS | `EVID-E05-…` | — |
| R-05a | DNS record for the staging platform host | OD-PPR-8 | `EVID-E05-…` | separate DNS approval (rule 16) |
| R-05 | PostgreSQL 16 instance (private, encrypted at rest, UTC) | R-01 | `EVID-E09-…` | — |
| R-06 | PostgreSQL TLS (server cert; client `verify-full`/`require`) | R-05 | `EVID-E09-…` | — |
| R-07 | Database roles + bootstrap grants (`production-bootstrap.sql`) | R-06, R-11 | `EVID-E09-…` | — |
| R-08 | Redis-compatible service (private, auth, `noeviction`) | R-01 | `EVID-E09-…` | — |
| R-09 | Object-storage bucket (staging) | R-01 | `EVID-E09-…` | — |
| R-10 | Bucket policy: public-access block, encryption, versioning, lifecycle, least-privilege identity | R-09 | `EVID-E09-…` | — |
| R-11 | Secret-store staging namespace + per-process access policies | OD-PPR-4 | `EVID-E08-…` | — |
| R-12 | Generate and store staging secrets, per group | R-11 | `EVID-E08-…` | — |
| R-13 | TLS email-capture service (no relay; SMTP egress denied) | R-01, R-12 | `EVID-PPR2-…` | — |
| R-14 | Release task: migrations + catalogue seed | R-07, R-12 | `EVID-E09-…` | **PPR.3** (VERIFIED digest) |
| R-15 | Web role behind the edge | R-03, R-14 | `EVID-E09-…` | **PPR.3** |
| R-16 | Three worker roles | R-14 | `EVID-E09-…` | **PPR.3** |
| R-17 | One scheduler (singleton) | R-14 | `EVID-E09-…` | **PPR.3** |
| R-18 | Synthetic data bootstrap (root, School, administrator) | R-15 | `EVID-PPR2-…` | **PPR.3** |
| R-19 | Monitoring hooks: private reachability of `:9102`, log collection point | R-15 | `EVID-PPR2-…` | **PPR.3**; backend is PPR.4 |
| R-20 | Verification suite (E05, E08, E09 checks) | R-15–R-19 | `EVID-E05/E08/E09-…` | **PPR.3** |

Backups (PITR, independent object copy) are **PPR.5** steps, not listed here;
R-05 and R-09 only choose services that support them.

## Steps

**Frozen choices (PPR.2D, ADR 0074 §28):**
- **Platform:** AWS, `ap-south-1`.
- **IaC:** Terraform.
- **The six-step flow for every Terraform step:**
  1. reviewed IaC change on protected `main`;
  2. `terraform plan -out`;
  3. rule-16 approval of that plan's hash;
  4. apply that plan;
  5. verify;
  6. an `EVID` entry with the plan hash.
- **What authorises:** a committed `.tf` or plan authorises nothing, and
  CI never plans or applies.

### R-00a — Staging AWS account and operator access
- **Pre:** PPR.2D.
- **Approve:** this step only.
- **Do:**
  - use the owner's AWS Organization, or create one, and create a dedicated
    **staging** member account;
  - enable an organisation CloudTrail trail;
  - enable IAM Identity Center with `OP-nnn` operator users and permission
    sets (Terraform-apply, read-only verify, secret-writer) on short-lived
    credentials;
  - protect the root user with MFA and leave it unused.
- **Not done here:** no VPC or any other resource.
- **Verify:** CloudTrail logging is on; an operator sign-in is recorded;
  no IAM access keys exist.
- **Undo:** close the member account (D-00a).
- **Secrets:** operator credentials (operator-only).
- **Evidence:** `EVID-E09-…` (the account id is **never** recorded).

### R-00b — Terraform state backend
- **Pre:** R-00a.
- **Approve:** this step only.
- **Do:** create the staging state bucket:
  - versioning;
  - SSE-KMS with a dedicated key;
  - Block Public Access;
  - a TLS-only bucket policy;
  - access for the Terraform role only;
  - S3 native state locking.

  The bucket is created once by the operator; Terraform cannot store its
  own state before it exists.
- **Verify:** policy read-back; a `terraform init` against it from an
  empty configuration succeeds.
- **Undo:** D-00b (only after everything else is destroyed).
- **Secrets:** none.
- **Evidence:** `EVID-E09-…`.

**Format.** Each step lists:
- **Pre:** prerequisites;
- **Approve:** the approval it needs;
- **Do:** the action;
- **Verify:** the check that proves it;
- **Undo:** the rollback/delete action (see §Deprovision);
- **Secrets:** the secrets involved;
- **Evidence:** the evidence id it produces.

### R-01 — Private network and zones
- **Pre:** R-00a, R-00b, and a reviewed R-01 Terraform change on protected
  `main` (the first IaC change).
- **Approve:** create the staging network only.
- **Do:** create the staging network with public, application and data
  zones (architecture §1). Default-deny ingress and egress, then add only
  the listed rules. The application zone gets no internet egress except
  allow-listed later needs.
- **Verify:** a rule listing equals architecture §1, and no data-zone port
  is reachable from outside (port scan from an external vantage, recorded
  as counts).
- **Undo:** D-01.
- **Secrets:** none.
- **Evidence:** `EVID-E09-<YYYY>-n`.

### R-02 — Operator management path
- **Pre:** R-01.
- **Approve:** create the management path and named operator access.
- **Do:** create an authenticated, audited access path (bastion, VPN or
  provider console) for named operators (`OP-nnn`). It is never a public
  database or Redis port.
- **Verify:** access works for an authorised operator and is refused
  without credentials, and the access log records both.
- **Undo:** D-02.
- **Secrets:** operator credentials (operator-only, never in a process).
- **Evidence:** `EVID-E09-…`.

### R-03 — Edge
- **Pre:** R-01, OD-PPR-8.
- **Approve:** create the edge only.
- **Do:** create the TLS-terminating edge/load balancer:
  - 443 public; 80 redirects to 443 only;
  - target the private web port 8080;
  - health check `GET /api/health/ready`;
  - set and overwrite `X-Forwarded-For/-Host/-Port/-Proto`.
- **Record** the edge's private source range for `TRUSTED_PROXIES`.
- **Verify:** listener and rule listing; 80 → 301 to HTTPS.
- **Undo:** D-03.
- **Secrets:** none.
- **Evidence:** `EVID-E05-…`.

### R-05a — DNS record (separate DNS approval)
- **Pre:** OD-PPR-8 and the domain's DNS access.
- **Approve:** the single DNS record.
- **Do:** create one record for the staging platform host pointing at the
  edge. **Nothing for production.**
- **Verify:** it resolves to the edge (the record value is not written into
  the repository entry).
- **Undo:** D-05a.
- **Secrets:** the DNS operator credential (operator-only).
- **Evidence:** `EVID-E05-…`.

### R-04 — Edge TLS certificate
- **Pre:** R-03, R-05a.
- **Approve:** issue and install the staging certificate.
- **Do:** issue a certificate for the staging host and install it at the
  edge, with renewal owned by the edge/provider. The private key never
  leaves the edge.
- **Verify:** the TLS handshake shows a valid chain and expiry (recorded as
  days remaining).
- **Undo:** D-04.
- **Secrets:** the certificate private key (edge-only).
- **Evidence:** `EVID-E05-…`.

### R-05 — PostgreSQL 16
- **Pre:** R-01.
- **Approve:** create the database instance only.
- **Do:** create a PostgreSQL 16 single primary in the data zone:
  - encryption at rest on;
  - `timezone=UTC`;
  - no public endpoint;
  - PITR-capable storage selected, though not configured here (PPR.5).
- **Verify:** the version is 16, there is no public address, and the
  instance is reachable from the application zone only.
- **Undo:** D-05.
- **Secrets:** the provider's initial superuser credential (operator-only;
  stored at once in the secret store under an operator-only path).
- **Evidence:** `EVID-E09-…`.

### R-06 — PostgreSQL TLS
- **Pre:** R-05.
- **Approve:** enforce TLS.
- **Do:** require TLS for every network connection and obtain the CA bundle
  needed for `DB_SSLMODE=verify-full` (or use `require` if the provider CA
  cannot be distributed).
- **Verify:**
  - a non-TLS connection attempt is refused;
  - a TLS connection succeeds (`pg_stat_ssl.ssl = true`).
- **Undo:** reverting TLS is not an option. Delete via D-05 if needed.
- **Secrets:** none new.
- **Evidence:** `EVID-E09-…`.

### R-07 — Database roles and bootstrap
- **Pre:** R-06, R-11 (passwords go straight into the store).
- **Approve:** create the migration, runtime and retention roles and run
  the bootstrap.
- **Do,** per `DATABASE-BOOTSTRAP.md`:
  - create the migration role (its own name, not `school_os`);
  - create `school_os_app` with the exact attributes;
  - create `school_os_retention`;
  - run `infrastructure/postgres/production-bootstrap.sql` (default
    privileges FOR ROLE the migration role);
  - store each password in its group (R-12).
- **Verify:** the bootstrap script's own verification (attributes, no
  owner membership).
- **Undo:** D-07.
- **Secrets:** `database_admin`, `app_runtime` (`DB_PASSWORD`),
  `database_retention`.
- **Evidence:** `EVID-E09-…`.

### R-08 — Redis-compatible service
- **Pre:** R-01. Finding F-D1 is **cleared** (2026-10-16, ADR 0074 §30): `predis` connects with verified TLS and auth, and the readiness probe follows the same connection. That proof was local, not of this service.
- **Approve:** create the cache/queue service only.
- **Do:** create a private Redis-protocol service:
  - authentication required;
  - `maxmemory-policy noeviction`;
  - persistence optional.
- **Verify:**
  - an unauthenticated `PING` is refused;
  - an authenticated `PING` succeeds from the application zone;
  - there is no public address;
  - in-transit TLS is on, and the service certificate verifies against the
    image's CA bundle under the endpoint name (a `tls://` `REDIS_URL` with no
    `ssl[cafile]`).
- **Undo:** D-08.
- **Secrets:** `REDIS_PASSWORD` (`app_runtime`).
- **Evidence:** `EVID-E09-…`.

### R-09 — Object-storage bucket
- **Pre:** R-01.
- **Approve:** create the staging bucket only.
- **Do:** create one staging bucket. Its name is not `school-os-local` or
  `school-os-test` and not shared with any other environment.
- **Verify:** the bucket exists and has no public policy.
- **Undo:** D-09.
- **Secrets:** none.
- **Evidence:** `EVID-E09-…`.

### R-10 — Bucket protection and identity
- **Pre:** R-09.
- **Approve:** apply the bucket policies and create its identity.
- **Do:**
  - public-access block on;
  - encryption at rest;
  - versioning on;
  - lifecycle: never expire current objects; noncurrent versions ≤ 35 days;
  - a least-privilege identity (get/put/delete/list on this bucket only).
- **Verify:** `platform:verify-storage` from a console task (once R-14 is
  possible), or the provider's policy read-back now.
- **Undo:** D-10.
- **Secrets:** the bucket identity keys (`app_runtime` `AWS_*`) or none
  (credential chain).
- **Evidence:** `EVID-E09-…`.

### R-11 — Secret-store staging namespace
- **Pre:** OD-PPR-4.
- **Approve:** create the staging namespace and access policies.
- **Do:** create a staging namespace (separate from any production one).
  Create one read identity per process class:
  - **web/workers:** `app_runtime`;
  - **scheduler:** `app_runtime` + `database_retention`;
  - **release:** `app_runtime` + `database_admin`;
  - **console:** all three;
  - **operators:** write.

  Turn on access auditing.
- **Verify:**
  - each identity reads only its groups;
  - a web/worker identity is **denied** `database_admin`.
- **Undo:** D-11.
- **Secrets:** none yet.
- **Evidence:** `EVID-E08-…`.

### R-12 — Staging secrets
- **Pre:** R-11 (and R-07 or R-08 for the values they create).
- **Approve:** generate and store the staging secrets.
- **Do:** generate staging-only values for every secret marked "staging" in
  architecture §7.1 and store each in its group.
- **Never set** any secret marked "not set" (SendGrid, provider events,
  Gateway, domain probe).
- **Verify:** the store listing shows names only (Confidential); the values
  are never printed.
- **Undo:** D-12.
- **Secrets:** all `app_runtime`, `database_admin` and `database_retention`
  values.
- **Evidence:** `EVID-E08-…`.

### R-13 — TLS email capture
- **Pre:** R-01, R-12 (its SMTP credential).
- **Approve:** deploy the capture service.
- **Do:**
  - deploy Mailpit as an ECS service in a private subnet behind an
    **internal NLB TLS listener with a public ACM certificate** for a name
    in the staging domain (F-D2); the application uses
    `MAIL_SMTP_TLS=implicit` and the NLB's port, with SMTP auth;
  - **no relay or forwarding configuration**;
  - its UI only through the management path;
  - message retention ≤ 7 days.
- **Verify:**
  - a STARTTLS session with auth succeeds;
  - plaintext is refused;
  - the relay settings are empty;
  - SMTP egress from the application zone is denied.
- **Undo:** D-13.
- **Secrets:** the capture TLS key (service-only), `MAIL_PASSWORD`
  (`app_runtime`).
- **Evidence:** `EVID-PPR2-…`.

### R-14 — Release task (blocked until PPR.3)
- **Pre:** a VERIFIED, pullable application digest (PPR.3); R-07; R-12.
- **Approve:** run the release task.
- **Do:**
  - run the `release` role
    (`console migrate --database=pgsql_admin --force`) with
    `APP_ENV=staging`;
  - then `console db:seed --force`.
- **Verify:**
  - the guard boots;
  - migrations complete;
  - `console platform:verify-database` reports 0 failed.
- **Undo:** D-14 (drop and recreate via D-05/R-05 — staging has no
  migration rollback, per the one rollback contract).
- **Secrets:** `app_runtime`, `database_admin`.
- **Evidence:** `EVID-E09-…`.

### R-15 — Web role (blocked until PPR.3)
- **Pre:** R-03, R-14.
- **Approve:** start the web role.
- **Do:**
  - run `web` from the VERIFIED digest with the runtime security contract;
  - inject `app_runtime`;
  - set the staging configuration (STAGING-ENVIRONMENT; the configuration
    inventory in STAGING-EVIDENCE-PLAN §4);
  - register it with the edge.
- **Verify:**
  - `/api/health/ready` 200 through the edge;
  - the guard booted (no refusal);
  - the role runs non-root with no capabilities.
- **Undo:** D-15.
- **Secrets:** `app_runtime`.
- **Evidence:** `EVID-E09-…`.

### R-16 — Three workers (blocked until PPR.3)
- **Pre:** R-14.
- **Approve:** start the three worker roles.
- **Do:** run `worker default`, `worker integrations` and
  `worker notifications`, one each, with restart-always.
- **Verify:**
  - each process is up;
  - a worker canary per queue succeeds (`platform:dispatch-worker-canaries`
    heartbeats).
- **Undo:** D-16.
- **Secrets:** `app_runtime`.
- **Evidence:** `EVID-E09-…`.

### R-17 — Scheduler (blocked until PPR.3)
- **Pre:** R-14.
- **Approve:** start the single scheduler.
- **Do:** run `scheduler` as a single-replica, restart-always service.
- **Verify:**
  - task heartbeats advance;
  - the orchestrator definition shows exactly one replica;
  - no second scheduler exists (OBS-07 quiet once PPR.4 exists; until then
    the run-rate check from the metrics).
- **Undo:** D-17.
- **Secrets:** `app_runtime`, `database_retention`.
- **Evidence:** `EVID-E09-…`.

### R-18 — Synthetic data bootstrap (blocked until PPR.3)
- **Pre:** R-15.
- **Approve:** bootstrap the synthetic root, School and administrator.
- **Do:**
  - `console platform:bootstrap-root` (synthetic identity, then MFA);
  - create a synthetic School in the platform UI;
  - `console platform:provision-school-admin-account` (operator-controlled
    mailbox);
  - activate the account through the captured email.
- **Verify:** sign-in works and the email appears **only** in the capture
  UI.
- **Undo:** D-14 (rebuild).
- **Secrets:** synthetic account passwords (operator-held, never recorded).
- **Evidence:** `EVID-PPR2-…`.

### R-19 — Monitoring hooks (blocked until PPR.3)
- **Pre:** R-15.
- **Approve:** expose the metrics port privately and set the log
  collection point.
- **Do:**
  - make `:9102` reachable only from the future collector's network;
  - point container stderr to the platform's log collection.
- **No backend or alerting** — that is PPR.4.
- **Verify:** a bearer-authenticated scrape succeeds from the collector
  network, and the request is refused from elsewhere and without the token.
- **Undo:** D-15.
- **Secrets:** `METRICS_SCRAPE_TOKEN`.
- **Evidence:** `EVID-PPR2-…`.

### R-20 — Verification suite (blocked until PPR.3)
- **Pre:** R-15–R-19.
- **Approve:** run the read-only verification.
- **Do:** run STAGING-EVIDENCE-PLAN §1–§3 (E05, E08, E09 checks).
- **Verify:** each check's closed result.
- **Undo:** none (read-only).
- **Secrets:** none new.
- **Evidence:** the `EVID-E05/E08/E09-…` entries.

## Deprovision (staging is disposable)

**Each step also needs its own approval.** Destroying staging data is an
operator action recorded as evidence; it is **never** confused with
production tenant erasure, which stays NOT AUTHORISED.

**Order:** reverse of creation.

| Step | Removes | Notes |
|---|---|---|
| D-17 | scheduler | stop first, so no task runs during teardown |
| D-16 | the three workers | |
| D-15 | web role, edge registration, `:9102` exposure | |
| D-14 | staging application data | drop the database (or the instance, D-05); purge the capture service |
| D-13 | email-capture service and its key | |
| D-12 | staging secret values | delete or destroy every value in the staging namespace |
| D-11 | staging namespace and access identities | |
| D-10 | bucket identity and policies | |
| D-09 | the bucket, **including every object version** | empty all versions before deletion; record counts only |
| D-08 | Redis service | |
| D-07 | database roles | when keeping the instance for a rebuild |
| D-05 | PostgreSQL instance and its automated backups | backups follow the provider's retention unless explicitly deleted (owner decision at the time) |
| D-04 | the edge certificate | |
| D-05a | the staging DNS record | separate DNS approval |
| D-03 | the edge | |
| D-02 | the management path and operator access | |
| D-01 | the network | |
| D-00b | the Terraform state bucket | after every other resource is destroyed |
| D-00a | the staging member account | last; closing it ends all billing for staging |
