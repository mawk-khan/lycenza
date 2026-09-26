# syntax=docker/dockerfile:1
#
# Phase 0O.4A (ADR 0050 section 12): the PRODUCTION AI Gateway image.
# NullProvider only -- no provider SDK, hostname or credential exists in
# this codebase, and REAL_PROVIDERS_ENABLED stays unset (Phase 0M blocked).
# The Gateway refuses to start without SERVICE_TOKEN, or with the public
# development token outside local/testing (Phase 0O.1); ENVIRONMENT
# defaults to production. Runtime dependencies only, non-root.
#
# Build (context = services/ai):
#   docker build -f infrastructure/docker/production/ai.Dockerfile \
#     -t lycenza-ai-gateway:<version> services/ai
#
# Phase 0O.6A (ADR 0052 sections 3.2, 3.5): the base is pinned BY DIGEST
# (Python 3.12.14, pip 25.0.1 from that digest; pip is never upgraded) and
# dependencies come from the fully resolved, hash-locked requirements.lock:
# every file hash-checked, no dependency resolution, wheels only (no source
# build hook runs). Tests\Feature\Configuration\SupplyChainGuardTest
# guards both.

ARG PYTHON_IMAGE=python:3.12.14-slim-bookworm@sha256:a116514e19457bcb7af7efe9c3dd0b9b71e85b317694e7882a1c52aa15a78134

FROM ${PYTHON_IMAGE} AS deps
RUN python -m venv /opt/venv
COPY requirements.lock /tmp/requirements.lock
RUN /opt/venv/bin/pip install --no-cache-dir --require-hashes --no-deps --only-binary=:all: -r /tmp/requirements.lock

FROM ${PYTHON_IMAGE} AS runtime
RUN useradd --system --uid 10001 --home-dir /srv/ai --shell /usr/sbin/nologin gateway
COPY --from=deps /opt/venv /opt/venv
WORKDIR /srv/ai
COPY --chown=gateway:gateway app ./app
ENV PATH="/opt/venv/bin:${PATH}" \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    ENVIRONMENT=production
USER gateway
EXPOSE 8100
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8100/health/live', timeout=3).status == 200 else 1)"
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100", "--no-server-header"]
