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
uvicorn app.main:app --reload --port 8100
```

## Tests

```bash
pytest
```
