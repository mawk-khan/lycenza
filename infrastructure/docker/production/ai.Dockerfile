# syntax=docker/dockerfile:1
#
# Phase 0O.4A (ADR 0050 section 12): the PRODUCTION AI Gateway image.
# NullProvider only -- no provider SDK, hostname or credential exists in
# this codebase, and REAL_PROVIDERS_ENABLED stays unset (Phase 0M blocked).
# The Gateway refuses to start without its ADR 0053 service keys
# (SERVICE_SIGNING_KEY, PLATFORM_VERIFICATION_KEYS), with the committed
# development keys or a plaintext Laravel URL outside local/testing, or with
# the retired SERVICE_TOKEN set (Phase 0O.1/0O.7A); ENVIRONMENT defaults to
# production. Runtime dependencies only, non-root.
#
# Build (context = services/ai):
#   docker build -f infrastructure/docker/production/ai.Dockerfile \
#     -t lycenza-ai-gateway:<version> services/ai
#
# Phase 0O.6A (ADR 0052 sections 3.2, 3.5): the base is pinned BY DIGEST
# (Python 3.14.7 on Debian 13 "trixie" since Phase 0O.6C -- owner decision
# R12: CVE-2026-82049 is fixed only in CPython 3.14; pip from that digest is
# never upgraded) and
# dependencies come from the fully resolved, hash-locked requirements.lock:
# every file hash-checked, no dependency resolution, wheels only (no source
# build hook runs). Tests\Feature\Configuration\SupplyChainGuardTest
# guards both.

ARG PYTHON_IMAGE=python:3.14.7-slim-trixie@sha256:51dafde81dbdb6ebde285137a295cf18a47ca95234fe388a343719cb97305b3d

FROM ${PYTHON_IMAGE} AS deps
RUN python -m venv /opt/venv
COPY requirements.lock /tmp/requirements.lock
RUN /opt/venv/bin/pip install --no-cache-dir --require-hashes --no-deps --only-binary=:all: -r /tmp/requirements.lock

FROM ${PYTHON_IMAGE} AS runtime
RUN useradd --system --uid 10001 --home-dir /srv/ai --shell /usr/sbin/nologin gateway \
 # Phase 0O.6F (runtime security contract): nothing mounts filesystems, so
 # mount/umount lose their setuid bit via Debian's dpkg-statoverride.
 && dpkg-statoverride --update --add root root 0755 /usr/bin/mount \
 && dpkg-statoverride --update --add root root 0755 /usr/bin/umount
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
