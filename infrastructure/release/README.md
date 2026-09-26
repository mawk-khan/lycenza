# Release qualification and artifact verification (Phase 0O.6A, ADR 0052)

This directory is the repository side of O16: it builds the two production
images as immutable OCI archives, produces and checks their supply-chain
evidence, and decides **VERIFIED** or **FAIL** for each exact digest. It never
pushes, publishes, promotes or deploys anything.

> **NO PRODUCTION REGISTRY IS CONFIGURED.**
> **NO REAL SIGNING IDENTITY/KEY IS CONFIGURED.**
> **NO PRODUCTION IMAGE HAS BEEN PUSHED.**
> **NO PRODUCTION IMAGE HAS BEEN PROMOTED.**

Everything here uses the Python standard library only (no dependency of its
own). Each security tool runs from a digest-pinned container image named in
`artifact-policy.json`.

## Files

| File | Purpose |
|---|---|
| `artifact-policy.json` (+ `schema/`) | The one policy manifest: images, required evidence, vulnerability thresholds, scanner-database age, exception limits, signing custody (unconfigured), pinned tool digests, reachable states. `verify-artifact` reads only this. |
| `vulnerability-exceptions.json` (+ `schema/`) | Time-bounded, exact-match vulnerability exceptions. **Empty.** |
| `retained-releases.json` (+ `schema/`) | Releases whose retained SBOMs the scheduled re-scan covers. **Empty** (nothing promoted). |
| `gitleaks-image.toml`, `../../.gitleaks.toml` | Image-filesystem and source secret-scan configuration: gitleaks' default rules with an **exact-match** allowlist (one path + exact value per entry). |
| `image-scan-allowlist.json` | Exact-match allowlist (image + code + path + file sha256) for the filesystem canary/shape scan. |
| `requirements-tools.in` / `.lock` | pip-tools and pip-audit, hash-locked; installed wheels-only into a throwaway venv inside the pinned Python image, never into an application image. |
| `qualify` | The qualification entry point (stages below). |
| `verify-artifact` | The aggregate verifier: one PASS/FAIL with a per-check table of bounded codes. |
| `regression-gates` | Every release test gate in order (Pint, Larastan, vue-tsc, ESLint, Prettier, build, Gateway ruff/format/mypy/pytest, release-tooling tests, the complete ERP regression). |
| `lycenza_release/` | The implementation. `evaluate.py` is the only place vulnerability thresholds are applied. |
| `tests/` | `python3 -B -m unittest discover -s tests` (from this directory). |

## States (ADR 0052 §3.14)

| State | Reached by | Meaning |
|---|---|---|
| **BUILT** | `qualify build` | An OCI archive with a manifest digest exists. |
| **VERIFIED** | `verify-artifact` PASS | Every repository gate passed for that digest; the evidence bundle is signed. |
| PUBLISHED | *deploy-gated* | The same digest stored in an authorized registry with its signature/attestations. |
| PROMOTED | *deploy-gated* | Explicitly approved for production. Production deploys PROMOTED digests only. |

The repository reaches **VERIFIED at most**; the tooling refuses to record
PUBLISHED or PROMOTED, and `verify-artifact` fails any evidence that claims
them.

## Qualifying a release

Only a commit on protected `main` qualifies (the lineage check fails
anything else). In CI: run the **Release qualification** workflow
(`.github/workflows/release-qualification.yml`, `workflow_dispatch` on
`main`). Locally (a clean checkout of the commit; dependencies installed from
their locks):

```bash
SAFE_TEST_ISOLATED=1 infrastructure/release/qualify all \
  --out /path/outside/the/repo/evidence --artifacts /path/outside/the/repo/artifacts \
  -- infrastructure/release/regression-gates
```

`all` runs, in order, against ONE evidence directory whose `run.json` fixes
the commit and run id (every stage refuses a different HEAD or a dirty
tree):

1. `init` — commit, run id, builder id, protected-main lineage at start.
2. `inputs` — lockfile integrity: `composer validate --strict`,
   `npm ci --dry-run` (fails when package.json and the lock disagree),
   `pip-compile` reproduces `services/ai/requirements.lock` byte for byte.
3. `regression` — the same-run complete regression (`-- <command>`),
   recorded as `tests/complete-regression.json`.
4. `source-scan` — gitleaks over the committed tree (`git archive`).
5. `audit` — `composer audit`, `npm audit`, `pip-audit`; reports stored
   unmodified; **nothing is ever auto-fixed**.
6. `build` — a fresh `docker-container` builder (pinned BuildKit), no cache,
   the build context exported from the commit (never the working tree), OCI
   labels (`source`, `revision`, `version`, `created`) from trusted values,
   output to an OCI archive (the identity) plus a docker archive loaded
   locally for the image checks; the loaded image id must equal the
   archive's config digest. → **BUILT**
7. `verify-images` — `infrastructure/docker/production/verify-images.sh`
   against exactly those images.
8. `scan` — Syft SPDX 2.3 SBOM from the OCI archive; a fresh Grype database;
   Grype over the SBOM; gitleaks over the exported image filesystem; the
   config/history/env/label and filesystem canary scan.
9. `provenance` — an in-toto Statement v1 / SLSA Provenance v1 predicate per
   image (no SLSA level claimed).
10. `sign` — `bundle.json` (every evidence file's sha256 and each image's
    digest/SBOM/provenance/scan digests), signed with an **ephemeral,
    per-run, NON-PRODUCTION** cosign key generated in a private temporary
    directory and destroyed afterwards; only its public half is kept.
11. `verify` — `verify-artifact` for each image, recording
    `state/<image>.json` (VERIFIED or FAIL).

Timings of each stage are written to `state/timings.json`.

## verify-artifact

```bash
infrastructure/release/verify-artifact --image app --digest sha256:… \
  --evidence EVIDENCE [--archive ARTIFACTS/app.oci.tar] \
  [--test-public-key EVIDENCE/signing/ephemeral-test.pub] [--rescan-report fresh-grype.json]
```

Checks (fail closed, bounded codes, no secret or finding detail printed —
details go to `--record`): policy; exceptions; evidence complete and free of
forbidden files (`.env`, keys, archives); **signature** (through the custody
`Verifier` — with no `--test-public-key` and no configured custody it fails
`signing_identity_not_configured`); bundle integrity (every file hash, no
unlisted file); digest = bundle = build record (= the archive, whose every
blob is re-hashed); no deploy-gated state claimed; **source on protected
main**; tests, build and evidence from the same run and commit, regression
passed; provenance (subject, source, parameters, base-image digests against
the Dockerfile at that commit, run id, SBOM/scan byproduct digests, test run,
no SLSA level, no secret); SBOM (SPDX 2.3, Syft pinned version, describes
this digest, required ecosystems present); scanner pinned and its database
≤ 24 h old at scan time; the vulnerability policy; an optional fresh
re-scan; secret and history scans clean.

Rollback re-verification runs the same command against the retained
evidence of the earlier digest (optionally with `--rescan-report` from a
fresh scan). A newly found Critical in an old digest fails with
`rescan_blocking_findings_require_judgement`: a human decision recorded as an
exception, never an automatic pass — and never an automatic rollback.

## Vulnerability policy (evaluate.py)

- CRITICAL blocks. HIGH blocks when a fix is available. HIGH without a fix
  blocks unless a valid exception matches. MEDIUM, LOW, NEGLIGIBLE and
  UNKNOWN are recorded, never blocking here.
- Language advisories without a severity (pip-audit reports none; some
  Composer advisories) are treated as **HIGH** and as **fixable** — so they
  block until resolved or excepted.
- An exception matches only on the exact image, advisory id (or alias),
  package, version **and** reported severity. Versions are exact only (ranges
  are refused: version ordering differs between dpkg, PEP 440 and SemVer).
  Critical/High exceptions last at most 30 days, others at most 90; no
  wildcards; an expired, future-dated, duplicate, unapproved or malformed
  entry invalidates the whole file (FAIL). The tooling validates the record;
  **it never decides whether a risk is acceptable** — `approved_by` references
  the human security approval.

## Signing custody

`artifact-policy.json` → `signing.custody` is `unconfigured`. A deployment
chooses one of:

- `external-key`: `public_key_ref` names a cosign-resolvable key reference
  (a KMS URI such as `awskms://…`/`gcpkms://…`/`azurekms://…`/`hashivault://…`,
  or the public half of an offline key). No KMS SDK is added — cosign
  resolves the reference.
- `keyless`: `certificate_identity` and `certificate_oidc_issuer` (for
  GitHub Actions, the release workflow's identity and
  `https://token.actions.githubusercontent.com`); signing then needs
  `id-token: write` on a protected environment — added only when that path
  is authorized.

Verification goes through the same `Verifier.verify()` for the ephemeral test
key and for either production custody.

## build-type-oci-image-v1

The provenance `buildType`. Parameters: the source repository, ref and
commit; the production Dockerfile, its context directory (exported from the
commit), the `runtime` target and platform `linux/amd64`. Internal
parameters: the pinned BuildKit image, the OCI-archive exporter and "no
cache". Resolved dependencies: the source commit and every digest-pinned
base image of the Dockerfile.

## Current release status (Phase 0O.6B)

Both production images still **FAIL** the vulnerability policy
(`docs/security/release-remediation/`): after Phase 0O.6C the Gateway (CPython
3.14.7) has 0 CRITICAL and 0 HIGH with a fix — only HIGH-without-fix findings
awaiting a human exception decision (inactive decision pack) — and the
application awaits an ABI decision for patched libcurl/libxml2. No digest is
VERIFIED; the exception file stays empty; proposed records live beside the
remediation records, never here. The pinned tool Python (lock freshness,
pip-audit) is the Gateway's interpreter, CPython 3.14.7.

## Known limits (recorded, not claimed away)

- Composer's GitHub dists carry no `shasum`: archive integrity rests on TLS
  and the locked commit reference; `composer audit`, the SBOM and the image
  scan are the compensating controls.
- Debian packages are not individually pinned (base digests + the
  final-image SBOM are the control); byte-for-byte reproducibility is not
  claimed.
- The bundled frontend assets carry no package metadata in the runtime
  image, so the SBOM lists no npm packages for them; `npm audit` over the
  lock covers their build inputs.
- With no custody configured, the evidence signature proves integrity
  against a per-run key kept next to the evidence — it is **not** a
  provenance guarantee and never valid for promotion.
