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
