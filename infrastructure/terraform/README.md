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
| Redis endpoint with authentication | `REDIS_HOST`, `REDIS_PORT` (+ secret `REDIS_PASSWORD`) |
| Private, versioned, encrypted bucket per environment (+ independent copy) | `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT` (HTTPS or empty), `DOCUMENTS_DISK=s3`, `COMMUNICATION_ATTACHMENTS_DISK=s3` |
| Secret-store references | the `secret_groups` of `apps/platform/deploy/processes.json`, injected per process |
| Backup policy references | PITR (RPO ≤ 15 min, 35 days), object copy (RPO ≤ 24 h) — `docs/operations/BACKUP-AND-RESTORE.md` |
| Process definitions | `apps/platform/deploy/processes.json` (one scheduler, three workers, web, private AI Gateway) |

