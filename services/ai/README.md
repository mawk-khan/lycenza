# services/ai — AI Gateway (FastAPI)

Provider-independent AI platform boundary for School OS. See
`docs/ai/AI-PLATFORM.md` and `docs/ai/AI-SECURITY.md` for the full design.

Phase 0A ships only the skeleton needed to prove the boundary works:

- `app/gateway/router.py` — Model Provider Registry + Router
- `app/providers/` — provider-independent `ModelProvider` interface, plus an
  offline `NullProvider` used for local dev/tests (no real model calls, no
  API keys required)
- `app/agents/registry.py` — Agent Registry (capability sets per agent)
- `app/tools/registry.py` — Tool Registry with capability-gated invocation
- `app/audit/ledger.py` — AI Audit Log (in-process stub)
- `app/core/security.py` — service-to-service auth for the Laravel <-> AI
  Gateway boundary

No real LLM provider, RAG/embedding pipeline, or customer-facing agent is
implemented yet — that is out of scope for Phase 0A.

## Local development

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements-dev.txt
cp .env.example .env        # ENVIRONMENT=local, SERVICE_TOKEN=dev-local-only-token
uvicorn app.main:app --reload --port 8100
```

## Configuration (fail-closed, Phase 0O.1)

| Variable | Default | Rule |
|---|---|---|
| `ENVIRONMENT` | `production` | Only exactly `local` or `testing` (any case) counts as local |
| `SERVICE_TOKEN` | none | Required in every environment; the public `dev-local-only-token` is refused outside local/testing |
| `ERP_CONTRACT_BASE_URL` | `http://platform:8000/api/internal/ai` | Laravel's internal AI contract base |
| `REAL_PROVIDERS_ENABLED` | off | Only the exact word `true` enables it; Phase 0M gate — never set it |

The process refuses to start (`app.core.startup`) and `GET /health/ready`
returns **503** when the token rule is broken; `/health/live` never checks
configuration. Refusals name a code, never a value. Production process and
release contract: `docs/architecture/PRODUCTION-RELEASE.md`.

## Tests

```bash
ruff check . && ruff format --check . && mypy app && pytest -q
```

`tests/conftest.py` runs the suite as `ENVIRONMENT=testing` with the
development token.
