# infrastructure/terraform

Empty by design in Phase 0A.

Per the Phase 0A stop gates, this checkpoint does not provision cloud
resources, configure production secrets, or connect to any real
environment. When a future phase introduces actual infrastructure
(a Postgres instance, object storage, container hosting, DNS, etc.),
it belongs here, split by environment (`environments/staging`,
`environments/production`) with remote state and no hand-edited
resources.

Phase 0O.4 (ADR 0050, `docs/architecture/adr/0050-production-infrastructure-secrets-recovery-contract.md`):
this directory stays provider-neutral and resource-free until a hosting
provider is chosen -- generic provider-neutral modules would be
decoration. When it is populated: never applied by tests or CI, `plan`
separate from a deploy-gated `apply`, remote/encrypted/access-controlled/
locked state (never committed, Highly Sensitive), no secret in outputs,
one `environments/<env>` per environment.

## Input interface (Phase 0O.4A, ADR 0050 §13)

Still no resources. When a provider is chosen, an environment's IaC must
supply exactly these inputs to the application (documented in
`docs/operations/PRODUCTION-IMAGES-AND-PROCESSES.md`), and nothing here
may contain their secret values:

| Input | Consumed as |
|---|---|
| Public hostname and TLS certificate (at the proxy) | `APP_URL`; TLS terminates at the proxy |
| Proxy address ranges | `TRUSTED_PROXIES` (explicit IPs/CIDRs, never `*`) |
| PostgreSQL 16 endpoint with TLS, database name, migration role | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_SSLMODE`, `DB_ADMIN_USERNAME` (release/console only); bootstrap per `docs/operations/DATABASE-BOOTSTRAP.md` |
| Redis endpoint with authentication and in-transit TLS | `REDIS_URL=tls://<endpoint>:6379` (+ secret `REDIS_PASSWORD`, and `REDIS_USERNAME` for an ACL user); the guard refuses plaintext or unverified TLS (ADR 0050 F-D1-2 amendment) |
| Private, versioned, encrypted bucket per environment (+ independent copy) | `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT` (HTTPS or empty), `DOCUMENTS_DISK=s3`, `COMMUNICATION_ATTACHMENTS_DISK=s3` |
| Secret-store references | the `secret_groups` of `apps/platform/deploy/processes.json`, injected per process |
| Backup policy references | PITR (RPO ≤ 15 min, 35 days), object copy (RPO ≤ 24 h) — `docs/operations/BACKUP-AND-RESTORE.md` |
| Process definitions | `apps/platform/deploy/processes.json` (one scheduler, three workers, web, private AI Gateway) |

## Decided at PPR.2D (2026-10-14, ADR 0074 §28)

**OD-PPR-25 = Terraform; OD-PPR-3 = AWS; OD-PPR-24 = `ap-south-1`.** Still
no resource is defined here.

**The first IaC change** is its own reviewed slice: the staging network for
runbook R-01, under `environments/staging`.

**Contract** (applies to every change in this directory):

| Topic | Rule |
|---|---|
| Versions | HashiCorp Terraform CLI pinned exactly (`required_version`); the AWS provider pinned exactly; `.terraform.lock.hcl` (with hashes) committed; upgrades are reviewed changes to releases at least 14 days old. OpenTofu is a drop-in alternative if the owner prefers MPL over Terraform's BSL 1.1 |
| State | remote S3 backend **per environment account** (staging state in the staging account): versioning, SSE-KMS, Block Public Access, TLS-only policy, S3 native locking, access for the Terraform role only. Classified **Highly Sensitive**. **Never committed**: `.gitignore` here refuses state, plans, `.terraform/` and `*.tfvars` |
| Separation | staging and production never share state, account, key or role |
| Secrets | Terraform creates secret **containers** and access policies only. Values are written out of band by an operator (runbook R-12), never in `.tf`, variables, plans, state outputs or logs. Sensitive outputs are not emitted |
| Flow | 1 reviewed change on protected `main` → 2 `terraform plan -out` (operator) → 3 rule-16 approval of that plan's hash → 4 `apply` of exactly that plan → 5 verification → 6 `EVID-…` entry with the plan hash |
| CI | never holds cloud credentials, never plans or applies (ADR 0052). Offline `fmt -check`/`validate` may be added later |
| Credentials | operators through IAM Identity Center on short-lived credentials, a dedicated Terraform-apply permission set; no long-lived access keys; root user unused |

**A committed `.tf` file or plan authorises nothing.**
