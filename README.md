# School OS

An AI-native School Operating System for India — evolving toward a
complete School ERP / SIS / CRM / LMS / Finance / HR / Communication /
Compliance / Automation / AI Workforce platform for individual schools
and multi-school groups.

**Status: Phase 0A — Architectural Foundation.** No business module
(SIS, Admissions, Fees, Academics, Attendance, HR, Transport, ...) is
implemented yet. This checkpoint establishes the repository structure,
architecture decisions, and a local development environment that later
phases build on. See `docs/roadmap/MASTER-ROADMAP.md` for what comes
next.

## Start here

- `CLAUDE.md` — mandatory engineering rules for anyone (human or AI)
  working in this repository.
- `docs/architecture/ARCHITECTURE.md` — system overview.
- `docs/architecture/DOMAIN-MAP.md` — module ownership and dependency
  direction.
- `docs/architecture/adr/` — every architecture decision, with context,
  rationale, alternatives considered, and consequences.

## Repository layout

| Path | What |
|---|---|
| `apps/platform` | Laravel 13 + Inertia + Vue 3 + TypeScript — the authoritative ERP (ADR 0002, 0005) |
| `apps/mobile` | Flutter mobile client (ADR 0007) |
| `services/ai` | Python/FastAPI AI Gateway (ADR 0008, 0013, 0014) |
| `packages/contracts` | OpenAPI spec + domain-event JSON Schemas (source of truth, ADR 0009, 0010) |
| `packages/shared-types` | TypeScript types generated from `packages/contracts` |
| `infrastructure/docker` | Local dev Dockerfiles |
| `infrastructure/terraform` | Empty by design — no cloud resources in Phase 0A |
| `docs/` | Architecture, AI, security, roadmap documentation |

## Local development

Requires Docker, PHP 8.3+, Node 22+, Python 3.12+.

```bash
# Backend infrastructure
docker compose up -d postgres redis minio

# Laravel (apps/platform)
cd apps/platform
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build
php artisan serve   # http://localhost:8000

# AI Gateway (services/ai), in another shell
cd services/ai
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
uvicorn app.main:app --reload --port 8100
```

See `CLAUDE.md` for the full command reference, including quality gates
(Pint, Larastan, vue-tsc, ruff, mypy, pytest) and the full
`docker compose` service list.

## What's proven so far

Phase 0A's code is intentionally small — a handful of "tiny primitives"
proving the architecture works end to end, not business features:

- `GET /` — Laravel → Inertia → Vue 3 → TypeScript, rendering a system
  status page.
- `GET /api/v1/system/status` — the versioned API envelope, request-id
  propagation, and consistent JSON error format.
- `services/ai`'s `/v1/complete` — an authenticated AI Gateway call
  routed through a provider-independent interface, recorded to an audit
  ledger.
- `services/ai`'s tool registry — capability-gated tool invocation
  (an agent without the required capability is denied, every time).
- `packages/contracts` → `packages/shared-types` — an OpenAPI contract
  generating real, type-checked TypeScript bindings.

See each area's tests for the specifics, and
`docs/architecture/ARCHITECTURE.md` for how these fit the larger
design.
