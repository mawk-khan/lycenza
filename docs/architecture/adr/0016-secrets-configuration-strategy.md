# ADR 0016: Secrets and Configuration Strategy

- Status: Accepted
- Date: 2026-08-22

## Context

The system has several categories of sensitive configuration: database
credentials, Redis credentials, object-storage keys, the Laravel
`APP_KEY`, the Laravel<->AI Gateway service token (ADR 0013), and,
eventually, real model-provider API keys. None of this may ever be
committed to the repository, and local development must not require
real production secrets to run.

## Decision

- **Configuration is environment-variable-driven** in every component
  (`apps/platform/.env`, `services/ai/.env`), following each
  framework's standard convention (Laravel's `.env` + `config/*.php`,
  FastAPI's `pydantic-settings` in `services/ai/app/core/config.py`).
- **Only `.env.example` files are committed**, with safe, non-secret
  local-dev defaults (`.env.example` files at both `apps/platform/` and
  `services/ai/`) — real `.env` files are gitignored everywhere.
- **No secret has a working default that would function against a real
  external provider.** Local defaults either point at local
  infrastructure (Postgres/Redis/MinIO containers) or are inert
  placeholders (e.g. `services/ai`'s `dev-local-only-token`).
- **Service-to-service authentication** (Laravel <-> AI Gateway) uses a
  shared secret token today (`SERVICE_TOKEN` /
  `AI_GATEWAY_SERVICE_TOKEN`), checked in
  `services/ai/app/core/security.py` — deliberately simple for Phase
  0A; see "Future extraction" below for its evolution path.
- **Where real secrets are deployed (staging/production) is explicitly
  out of scope for Phase 0A** per the stop gates — no production
  secrets are configured in this checkpoint.

## Rationale

- `.env`-based configuration is the idiomatic, well-understood pattern
  for both Laravel and FastAPI, minimizing surprise for engineers
  working across both codebases.
- Committing only `.example` files with inert defaults means a fresh
  clone of this repository is immediately runnable locally
  (`docker compose up`) without anyone needing to source real secrets
  first — reduces onboarding friction and the temptation to commit a
  "just this once" real credential.
- A single shared-secret token for the Laravel<->AI Gateway boundary is
  the simplest mechanism that still enforces "only the platform (or
  another trusted internal caller) may call the AI Gateway" — proven
  by the passing/failing tests in `services/ai/tests/test_health.py`.
  It is intentionally not over-engineered (e.g. full mTLS) before
  there's a deployment topology that needs it.

## Alternatives considered

1. **A dedicated secrets manager (Vault, cloud KMS/secrets service)
   wired up in Phase 0A.** Rejected for this checkpoint: no cloud
   infrastructure exists yet (stop gates), and introducing a secrets
   manager before there's a real deployment target to protect would be
   speculative infrastructure. The `.env`-driven approach is designed to
   be replaced by one later without an application-code rewrite (config
   is already centralized in `config/*.php` / `core/config.py`).
2. **mTLS or signed-JWT service-to-service auth from day one.**
   Rejected as premature complexity for Phase 0A's actual need (a
   single internal caller, no production deployment yet); the shared-
   token check is deliberately the simplest thing that enforces the
   boundary today, with a clear upgrade path noted below.
3. **Committing sanitized "example" secrets that actually work against
   a shared dev/staging environment.** Rejected: blurs the line between
   "safe to commit" and "a working credential," which is exactly the
   mistake this ADR exists to prevent.

## Consequences

- Every new secret-shaped configuration value must be added to the
  relevant `.env.example` with a clearly inert/local-only default, never
  a working credential.
- Root `CLAUDE.md`'s "no secrets committed" rule is the enforcement
  backstop; this ADR is the design decision it implements.
- The shared-token service auth is a known, accepted simplification for
  Phase 0A — not a claim that it's sufficient for a production
  multi-instance deployment without revisiting (see below).

## Future extraction/evolution path

Before production deployment: (1) real secrets move to a proper
secrets manager or the hosting platform's native secret injection,
never `.env` files on disk in production; (2) the Laravel<->AI Gateway
shared token should be revisited — likely replaced or supplemented with
per-request signed tokens or mTLS once there's a real multi-instance
deployment topology to secure. Both are explicitly deferred, not
decided, in this checkpoint.

**Note (Phase 0O.7, 2026-09-27):** item (2), "revisit the Laravel<->AI
Gateway shared token", is decided by **ADR 0053**. The shared token is
replaced by per-request Ed25519 service assertions, with one keypair per
calling service, verification rings, and staged or emergency rotation; mTLS
is not the v1 mechanism. Phase 0O.7A implements it.
