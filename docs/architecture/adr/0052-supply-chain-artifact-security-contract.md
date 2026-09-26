# ADR 0052: Supply Chain & Artifact Security Contract (Phase 0O.6)

- Status: Accepted. Repository side implemented by Phase 0O.6A (see the
  amendment at the end); nothing has been pushed, published or promoted, and
  no registry, signing key or keyless identity is configured.
- Date: 2026-09-26
- Resolves: Phase 0O decision **O16** (dependency audit, artifact
  integrity, image pinning, production promotion policy)
  (`docs/architecture/PHASE-0O-READINESS.md` §8).
- Builds on: ADR 0016 (secrets), ADR 0050 (production images, process
  manifest, secret injection, maintenance-window release), ADR 0051
  (observability; no high-cardinality metrics).

## 1. Context

Phase 0O.4A produced two production images (application, AI Gateway) that
are built and verified **locally only** (`verify-images.sh`, 57 checks).
ADR 0050 and ADR 0051 both stop at the same boundary: *no production image
may be pushed or promoted before O16 is resolved.* This ADR fixes how a
releasable artifact is identified, built, inventoried, scanned, attested,
signed, verified and promoted — without choosing a registry vendor,
creating a signing key, or pushing anything.

## 2. Current state (verified 2026-09-26 at `5fff35d`)

| Area | Finding |
|---|---|
| Production base images | `app.Dockerfile`: `php:8.3-fpm-bookworm` (build + runtime), `node:22-bookworm-slim`, `composer:2`; `ai.Dockerfile`: `python:3.12-slim-bookworm` (build + runtime). **All tag-only** (build `ARG`s); `composer:2` floats across a whole major version. No `:latest`. Local Dockerfiles (`platform.Dockerfile`, `ai.Dockerfile`) are dev-only and tag-based. |
| OS packages | `apt-get install` (unversioned) of `libpq-dev libpng-dev libzip-dev unzip` (build) and `nginx libpq5 libpng16-16 libzip4 ca-certificates` (runtime) from the base image's Debian bookworm repositories — versions float with the repository at build time (runtime today: nginx 1.22.1). |
| Toolchain in images | PHP 8.3.33, Composer from `composer:2` (build stage only; not in the runtime image), Node 22 / npm 10.9.9 (build stage only), Python 3.12 / pip 25.0.1 (from the base image). |
| PHP dependencies | `composer.lock` committed and authoritative; build runs `composer install --no-dev --no-scripts --no-autoloader` then `dump-autoload --no-scripts` and an explicit `package:discover`. 89 production packages, **all distributed from GitHub archives with an empty `dist.shasum`** — Composer cannot verify archive integrity; it relies on TLS and the locked commit reference. No custom repositories (Packagist only). No Composer plugin in the production set; `allow-plugins` lists `pestphp/pest-plugin` and `php-http/discovery`, **neither present in the lock** (stale). |
| npm dependencies | `package-lock.json` committed; all 283 entries carry `sha512` integrity; single source `registry.npmjs.org`; `.npmrc` sets `ignore-scripts=true` and `audit=true`. Only `fsevents` (macOS-only, optional) declares an install script. **The image's assets stage copies only `package.json`/`package-lock.json` before `npm ci`, so the repository `.npmrc` is not in effect there.** |
| Python dependencies | `requirements.txt` pins the **5 top-level** packages with `==`; the final image resolves **23** packages — 18 transitive versions (e.g. `anyio`, `certifi`, `idna`, `starlette`, `uvloop`) float at build time; **no hashes**; `pip install -r` (sdists allowed). No custom index. |
| Build contexts | `apps/platform/.dockerignore` and `services/ai/.dockerignore` exclude `.env*`, keys, `.git`, `vendor`, `node_modules`, `storage`, tests, caches, demo seeders; DDEV state is outside both contexts (repo root). |
| CI (`.github/workflows/ci.yml`) | Triggers `push` to `main` and `pull_request` (**no `pull_request_target`**); workflow-level `permissions: contents: read`, no job widens it; secrets used: only the built-in `GITHUB_TOKEN` (gitleaks); runners `ubuntu-latest`; `actions/cache` for Composer (keyed on `composer.lock`), `setup-node`/`setup-python` caches; **no image build, no artifact upload, no registry credential**. |
| Action references | `actions/checkout@v4` (×5), `gitleaks/gitleaks-action@v2`, `shivammathur/setup-php@v2`, `actions/cache@v4`, `actions/setup-node@v4` (×2), `actions/setup-python@v5`, `subosito/flutter-action@v2` — **all version tags; none pinned to a commit SHA**; no local actions. |
| Secret scanning today | gitleaks on source (default rules, no repository config); `verify-images.sh` greps the image for the known local credentials and development tokens; **no generic image-filesystem secret scan, no `docker history`/label/environment check**. |
| Dependency audits | none in CI (no `composer audit`, `npm audit`, `pip-audit`); no image vulnerability scan; no SBOM; no provenance; no signing. |

## 3. Decision — O16

### 3.1 Artifact identity and promotion (owner decision)

- A production artifact is an **immutable OCI image identified by its
  manifest digest** (`sha256:…`). Tags are human-readable aliases only.
- **Build once, verify, promote the same digest.** One artifact per image
  per release is built from one exact commit and receives environment
  configuration and secrets only at runtime (ADR 0050). No rebuild for
  staging, production or any other environment; no package install, bundle
  change or mutation after verification.
- v1 builds a **single platform (`linux/amd64`)**; the identity is the image
  manifest digest. A future multi-platform build uses the index digest.
- 0O.6A builds release images to an **OCI image layout/archive**
  (`docker buildx build --output type=oci`) so the manifest digest is known
  before any registry exists, is the input of every scan, and is preserved
  by a later digest-preserving copy to a registry. A publish step that
  re-encodes layers (changing the digest) is not permitted.

### 3.2 Base-image and toolchain pinning (owner decision)

- Every `FROM`/base-image `ARG` in `infrastructure/docker/production/*` is
  pinned **by digest** (`image:tag@sha256:…`); the tag stays as a readable
  comment/alias. `composer:2` becomes an exact Composer version tag plus
  digest. No `:latest` anywhere in a production artifact's inputs.
- The toolchain is fixed **through those digests**: PHP, Composer, Node,
  npm, Python and pip versions are whatever the pinned digests contain,
  recorded in the SBOM and provenance. No separate `pip install --upgrade
  pip` or `npm install -g npm` in release builds.
- **OS packages:** base digest pinning is the v1 control; Debian package
  versions are **not** individually pinned (freezing every transitive
  package would make security updates unmaintainable). Drift is bounded to
  the moment of an explicit build, and the **final-image SBOM is the record
  of what was installed**. A snapshot repository may be introduced later if
  byte-reproducibility becomes a requirement.
- Local/DDEV Dockerfiles, `docker-compose.yml` services and CI service
  containers are not production artifacts; they stay tag-based (no
  `:latest`), outside the release policy.
- Security tooling (scanner, SBOM generator, signer) is run from **pinned
  container digests or checksum-verified releases**, versions recorded in
  the evidence. Release runners use a pinned runner image label (e.g.
  `ubuntu-24.04`), not `ubuntu-latest`.

### 3.3 Base-image update policy

Pinned does not mean frozen: a digest update is an ordinary reviewed change
that re-runs full qualification. Updates are prompted by the scheduled
re-scan (§3.7), by a Critical/High finding with a fix available, or at
least every **30 days**. Deployment never pulls a mutable base.

### 3.4 CI action pinning (owner decision)

Every third-party action used by the release and security workflows is
pinned to a **full commit SHA** with a trailing `# vX.Y.Z` comment. 0O.6A
pins **all** actions in the repository (the ordinary CI shares the same
trust surface). Update automation (Dependabot/Renovate) may propose SHA
bumps later, but security-sensitive action changes are never auto-merged.

### 3.5 Deterministic dependency inputs (owner decision)

- **PHP:** `composer.lock` authoritative; release/CI builds use `composer
  install` only (never `update`), CI checks `composer validate --strict`
  and that the lock is in sync with `composer.json`. Production install adds
  `--no-plugins` (no production plugin exists; stale `allow-plugins` entries
  are removed). Known limit: GitHub-archive dists have no `shasum`, so
  archive integrity rests on TLS and the locked commit reference; `composer
  audit`, the SBOM and the image scan are the compensating controls. This is
  recorded, not claimed as hash verification.
- **Frontend:** `package-lock.json` authoritative; `npm ci` only (never
  `npm install`/`update`/`audit fix` in CI or builds); npm verifies every
  `sha512` integrity. The assets stage copies `.npmrc` **before** `npm ci`
  so `ignore-scripts=true` applies (verified safe: the only install script is
  the macOS-only optional `fsevents`); the build's own `npm run build` is
  explicit.
- **Python — hash-verified install (decision):** 0O.6A adds a fully resolved
  lock, `services/ai/requirements.lock` (every transitive package, exact
  version and `--hash=sha256:` for each distribution), generated from the
  existing top-level `requirements.txt` with `pip-compile
  --generate-hashes` (pip-tools, a pinned dev tool). The image installs with
  `pip install --require-hashes --no-deps --only-binary=:all: -r
  requirements.lock` — wheels only (no source build hooks, no compiler in
  any image stage), every file hash-checked. CI fails if the lock is stale.
  Versions are not changed by this ADR.
- **Lockfile integrity gate:** CI fails when `composer install`, `npm ci` or
  the Python lock check would need a lockfile change.

### 3.6 SBOM (owner decision)

- Canonical format: **SPDX 2.3 JSON**.
- Generated from the **final built image** (the OCI archive of §3.1), not
  from lockfiles: OS packages, PHP/Composer packages (from
  `vendor/composer/installed.json`), the built frontend assets where
  discoverable, and the Gateway's Python packages. Generator: **Syft**
  (pinned). The SBOM's own `sha256` is recorded in the provenance.

### 3.7 Vulnerability policy (owner decision)

- Scanned: the final application and Gateway images (OS + language
  packages) with **Grype** (pinned) against the image/SBOM; plus
  language-native advisories in CI — `composer audit` (lock), `npm audit`
  (lock, never `audit fix`), `pip-audit` (the resolved Python lock). All
  report; none edits dependencies.
- Release gate: **CRITICAL blocks**; **HIGH blocks when a fixed version is
  available**; HIGH without a fix needs an approved, time-bounded exception;
  MEDIUM/LOW are recorded and tracked, not automatically blocking (a
  specific finding may still be escalated by security judgement).
- **Scanner database freshness:** a release scan's database must be at most
  **24 hours** old (Grype exposes the database build time); the scanner and
  database versions/timestamps are part of the evidence. Build
  reproducibility and scanner-database freshness are different things: the
  same digest can gain findings tomorrow.
- **Scheduled re-scan:** 0O.6A adds a scheduled, credential-free workflow
  that re-scans the retained SBOMs of the current and rollback-candidate
  releases with a fresh database and reports; it never rebuilds or
  redeploys.

### 3.8 Vulnerability exceptions (owner decision)

Repository file `infrastructure/release/vulnerability-exceptions.json`,
JSON-Schema-validated; each entry:

| Field | Rule |
|---|---|
| `id` | the advisory (CVE-…, GHSA-…); **no wildcards** |
| `status` | `accepted_risk` \| `not_affected` \| `false_positive` |
| `image` | `app` \| `ai-gateway` |
| `package` | exact package name, and `version` or a version constraint |
| `severity` | as reported |
| `reason`, `compensating_control` | required text (no exploit payloads) |
| `approved_by` | a reference to the human/security approval record (not validated as a person) |
| `created`, `expires` | ISO dates; **Critical/High: at most 30 days**; any status: at most 90 days; none indefinite |

The evaluator reads the **unmodified** scanner report and applies
exceptions itself; the original finding stays in the evidence with its
exception id. An expired, malformed, wildcard or unmatched-but-required
entry fails validation. Tooling validates the record; **it does not decide
whether the risk is acceptable** — approval is a human security decision
(no UI in v1).

### 3.9 Secret scanning and image history (owner decision)

- **Source/committed changes:** gitleaks with a repository config
  (`.gitleaks.toml`) whose allowlist names only the unavoidable fake
  test/demo values by exact string/path — **never a production secret**.
- **Final image filesystem and generated artifacts:** a secret scan of the
  image (Trivy secret scanner or equivalent pinned tool) plus the existing
  `verify-images.sh` canaries (`Demo1234!`, local passwords, development
  tokens, `lyc_pat_`/`lyc_pk_` prefixes, private-key headers, `.env`).
- **History, environment and labels:** `docker history --no-trunc`, the
  image config `Env`, `Labels` and build `ARG`s are checked for the same
  canaries and for any secret-shaped variable (`*PASSWORD*`, `*SECRET*`,
  `*TOKEN*`, `*KEY*` with a value). Build-time secrets, if ever needed
  (e.g. private packages), use BuildKit `--mount=type=secret` only — never
  `ARG`/`ENV`.
- Reinforces ADR 0050: an image contains no `.env`, runtime secret, database
  or Redis password, storage secret, signing key, scrape token, API secret
  or service credential — proven by scanning the artifact, independent of
  Dockerfile intent.

### 3.10 Provenance (owner decision)

An **in-toto Statement v1** with a **SLSA Provenance v1** predicate
(`https://slsa.dev/provenance/v1`) per image:

- `subject`: image name and `sha256` manifest digest;
- `buildDefinition`: repository-defined `buildType`; external parameters —
  source repository URL, ref, commit SHA, Dockerfile path, build target,
  platform; resolved dependencies — the source commit and every pinned
  base-image digest;
- `runDetails`: builder id (the CI workflow identity and run id), invocation
  id, `startedOn`/`finishedOn`; byproducts — the SBOM `sha256`, the scan
  report `sha256`, the test-run id.

No environment dump, no secret or secret reference, no user data. SLSA
structures are used **without claiming a SLSA level**; no level is asserted
until every requirement is demonstrably met.

### 3.11 Signing and verification (owner decision)

- Model: **Sigstore/cosign-compatible**, OCI-native signatures and
  attestations. The contract supports either custody model — a key managed
  outside the repository (KMS/HSM or an offline key) or keyless OIDC
  identity — selected at deployment; the policy manifest names the expected
  public key *or* certificate identity and issuer.
- Before a registry exists, 0O.6A signs a **release evidence bundle** (digest,
  SBOM digest, provenance digest, scan-result digest) with `cosign
  sign-blob`/`verify-blob`; after the deploy-gated first push, the same
  identity signs the registry digest and attaches the SBOM and provenance as
  attestations (`cosign attest`).
- Repository tests use an **ephemeral, generated-per-run test key pair**
  that is never committed; no real key, KMS or external keyless service is
  used by this ADR or by 0O.6A.
- **Verification before promotion and before deployment** (fail closed):
  1. the digest to deploy equals the qualified digest;
  2. a valid signature from the expected identity;
  3. provenance present, valid, signed, subject = digest, source commit on
     protected `main`;
  4. SBOM present, digest matches the provenance byproduct;
  5. vulnerability policy passes with only valid, unexpired exceptions;
  6. secret/image-history policy passed.

  Missing, malformed, unsigned, mismatched, expired or failing → **FAIL**. No
  warn-and-continue; a tag that "looks right" is never enough.

### 3.12 Artifact policy manifest

One machine-readable policy, `infrastructure/release/artifact-policy.json`
(0O.6A), describing per image: name, Dockerfile, context, target, platform;
required evidence (SBOM format, provenance predicate, signature, scan,
secret scan); vulnerability thresholds; scanner-database maximum age;
allowed exception file; signing identity reference (empty until deployment
chooses custody). The aggregate verifier reads only this policy.

### 3.13 Aggregate verification command

One entry point for CI and operators, `infrastructure/release/verify-artifact`
(0O.6A; repository-level, independent of the application runtime), taking an
image reference/digest and an evidence directory and returning one PASS/FAIL
with a per-check table. It never pushes, promotes or edits anything.

### 3.14 Artifact state model (frozen)

| State | Meaning |
|---|---|
| **BUILT** | an image digest exists (OCI archive) |
| **VERIFIED** | every repository gate passed for that digest; evidence bundle signed |
| **PUBLISHED** | the same digest stored in an authorized registry with its signature/attestations (deploy-gated) |
| **PROMOTED** | explicitly approved for production deployment (deploy-gated) |

Production deployment accepts a **PROMOTED digest only**. Pushed ≠ approved.
States may later be encoded as registry tags, attestations or external
metadata — no vendor assumed.

### 3.15 Release qualification and trust boundary

- **Releasable source:** a commit reachable from protected `main` (checked
  by the workflow). Pull-request builds — including forks — may build and
  scan images but are **never release artifacts** and never receive signing
  or registry authority. No `pull_request_target` with an untrusted
  checkout.
- **Release gates, all green for the exact commit:** the canonical
  **complete ERP regression** (the 5k+ suite with real PostgreSQL, Redis
  and MinIO), static analysis, frontend type/lint/format/build, AI Gateway
  tests and static checks, lockfile integrity, language audits, production
  image verification, SBOM, vulnerability scan, secret/history scan,
  provenance, signature creation **and** verification, architecture/security
  guards.
- **Linkage:** tests, build and evidence come from **one workflow run on one
  checked-out commit**; the evidence records that commit, the test run id
  and the digest, and verification fails if any differs. Simplest rule, no
  exceptions: any new commit — including docs-only — re-qualifies.
- **Credentials:** release jobs get `contents: read` plus only what a step
  needs (`id-token: write` for keyless signing, registry scope for publish)
  — added only when that registry/deploy path is authorized. No long-lived
  production registry credential in repository secrets; short-lived
  credentials when the chosen platform supports them. Signing and publish
  jobs run behind a protected CI environment with required reviewers.
- **Caches:** optimizations, never evidence. Release builds run with a fresh
  builder and no remote/actions cache; lockfile and digest verification run
  regardless of cache contents. PR caches cannot write the default-branch
  cache scope.
- **Uploaded CI artifacts:** only release evidence (SBOM, provenance, scan
  reports, verification record, signatures) under its classification; never
  environment dumps, `.env`, databases or credential-bearing logs.
- **PR CI subset:** lockfile integrity, language audits, secret scan, image
  build + `verify-images.sh` where affordable, architecture guards — no
  release credentials.
- Local/DDEV development requires none of this.

### 3.16 OCI labels and timestamps

Release images carry `org.opencontainers.image.source`, `.revision`
(commit SHA), `.version` (release id), `.created` (build time) — generated
by the pipeline from trusted values only (never branch-provided text, user
data or secrets). Byte-for-byte reproducibility is **not** claimed in v1
(timestamps, apt state and toolchain metadata prevent it); the guarantee is
**traceable, pinned inputs and an immutable output digest**.

### 3.17 Rollback, retention, registry

- Rollback redeploys a previously VERIFIED/PROMOTED digest — never a rebuild
  of an old commit — and re-runs verification (signature and evidence; a
  newly discovered Critical in an old image needs operator/security
  judgement).
- Retain, at minimum, the current release, the approved rollback candidates
  and their evidence (SBOM, provenance, scan, signatures, verification
  record) for the life of the deployed artifact **plus the incident-response
  window**; the exact period is deployment policy (no legal basis exists to
  invent one). Release evidence is operational/security evidence — never
  written to `school_audit_events` or Platform audit metadata.
- **Registry (no vendor chosen) must offer:** private push, authenticated
  pull, immutable digest storage, deletion controls, OCI signatures/
  attestations (or compatible external storage), audit of push/promotion.
- **No production image push or promotion is authorized** until 0O.6A's
  repository enforcement is complete; even then the first push is
  deploy-gated and operator-authorized.

### 3.18 Dependency confusion, install scripts, network

- No private Composer, npm or Python packages exist; single public sources
  (Packagist/GitHub dists, `registry.npmjs.org`, PyPI). **No private-package
  confusion boundary exists today**; if private packages are introduced,
  explicit per-namespace source pinning becomes mandatory.
- Install-time code: Composer runs no plugins and no package scripts in the
  production build (`--no-scripts`, `--no-plugins`); npm lifecycle scripts
  are disabled via `.npmrc` (build scripts explicit); Python installs wheels
  only. Laravel's own `package:discover` runs explicitly.
- Builds need network access to resolve locked dependencies; **later stages
  fetch nothing** (the runtime stages only copy); the build is not claimed to
  be hermetic.

### 3.19 Release order (amends ADR 0050 §14 runbook)

1. build and qualify the candidate (BUILT → VERIFIED);
2. verify supply-chain evidence (the aggregate verifier);
3. explicit promotion authorization (→ PROMOTED; deploy-gated);
4. **only then** begin the maintenance-window release;
5. deploy the exact promoted digest;
6. post-deployment verification.

No maintenance window is opened only to discover an unsigned or failing
image.

### 3.20 Supply-chain incidents (runbook requirements for 0O.6A)

Concise runbooks for: a compromised dependency; a compromised CI action; a
leaked signing key (key custody: revoke/rotate and re-sign current releases;
keyless: identity-policy compromise and certificate-identity review); a
malicious or incorrect artifact (stop promotion, redeploy last good
digest); a Critical CVE after deployment (scheduled re-scan alert, exception
or rebuild decision). **No automatic production rollback.**

## 4. Classification

| Material | Tier (DATA-CLASSIFICATION.md) |
|---|---|
| SBOM | **Confidential** (package inventory; no credentials) |
| Provenance | **Confidential** (operational metadata; no secrets) |
| Vulnerability scan reports | **Confidential** (attack-surface information); never shown to School users |
| Vulnerability exceptions | **Confidential** security-operational records; no exploit payloads |
| Private signing keys | **Highly Sensitive**; never in the repository |
| Public verification material | Internal (Public only if the chosen keyless model publishes it by design) |
| Release verification record | Confidential |

Supply-chain details never become runtime metrics (no CVE id as a metric
label, ADR 0051 §10).

## 5. O16 definition of done

**Repository portion (0O.6A):** (1) production base images pinned by
digest; (2) all workflow actions pinned to SHAs; (3) deterministic language
installs (Composer/npm lock gates, Python hash-verified lock); (4) final-image
SBOM; (5) image and language vulnerability scanning; (6) image
secret/history scanning; (7) provenance; (8) signing/attestation tooling;
(9) the aggregate verifier and policy manifest; (10) exception validation;
(11) the artifact state/promotion contract; (12) release/runbook
integration; (13) deterministic tests and guards.

**Deployment evidence (operator, deploy-gated):** an authorized registry
configured; signing identity/key custody configured; a release artifact
actually signed; verification enforced before promotion; registry
permissions applied; the first promoted image's evidence retained. O16 is not
operationally complete until these exist.

## 6. Contribution to O1

Phase 0O cannot close with production artifacts whose source cannot be
traced, whose dependencies are unknown, whose signatures are unverified,
whose Critical vulnerabilities are ungoverned, or whose promotion is mutable
or uncontrolled. O1 remains open.

## 7. Proposed Phase 0O.6A — Supply Chain & Artifact Security Foundation (not implemented)

Repository-only, **no registry push, no real key, no promotion**:
digest-pinned production bases; SHA-pinned actions (with version comments);
Composer/npm lock gates, `--no-plugins`, `.npmrc` in the assets stage, stale
`allow-plugins` removed; Python hash-verified lock (`requirements.lock`,
wheels only); OCI-archive release builds with OCI labels; Syft SPDX SBOM;
Grype scan with database-age check; `composer audit`/`npm audit`/`pip-audit`;
gitleaks config and image secret/history/label/env scan; SLSA v1 provenance
statement; cosign sign/verify of the evidence bundle with an ephemeral test
key; `artifact-policy.json`, `vulnerability-exceptions.json` + schema +
validator; the aggregate `verify-artifact` entry point with deterministic
tests (missing/malformed/expired/unsigned/mismatched/Critical → FAIL); a
trusted release-qualification workflow (protected-`main` check, complete
regression, evidence linkage, no credentials) and a scheduled SBOM re-scan;
release runbook and incident runbooks.

## 8. Boundaries

- No registry vendor, no signing key or KMS, no keyless external signing, no
  push, no promotion in this ADR or in 0O.6A.
- Still open after this ADR: **O1, O2, O5, O9, O13, O14, O15**.
- Phase 0M stays BLOCKED.

## Alternatives considered

- **Tag-based bases with frequent rebuilds.** Rejected: the owner requires
  digests; tags move silently.
- **CycloneDX as canonical SBOM.** Not chosen: no repository tooling
  favours it; SPDX JSON is the owner's preferred open format and Syft emits
  both.
- **Trivy for SBOM + scan + secrets in one tool.** Viable; Syft/Grype chosen
  for the SBOM-then-scan split (the retained SBOM is re-scannable without
  the image) and Trivy only as an optional secret scanner. Either family is
  open source; the policy manifest, not the tool, is the contract.
- **Pin every Debian package version.** Rejected for v1: unmaintainable
  security updates; digest + final-image SBOM is the control.
- **Rebuild per environment.** Rejected by owner decision (digest drift,
  untested artifact).
- **Claim a SLSA level / reproducible builds.** Rejected until demonstrably
  true.

## Consequences

- 0O.6A has a concrete, testable scope, including real fixes found by this
  audit (unpinned bases, floating Python transitive dependencies, `.npmrc`
  not applied in the image build, stale Composer `allow-plugins`, tag-pinned
  actions, no image history check).
- The first registry push, key custody and promotion remain operator
  decisions, now with a verifiable gate in front of them.

## References

ADR 0016, ADR 0050, ADR 0051; `infrastructure/docker/production/*`;
`apps/platform/.dockerignore`, `services/ai/.dockerignore`;
`.github/workflows/ci.yml`; `apps/platform/composer.json`/`.lock`,
`package-lock.json`, `.npmrc`; `services/ai/requirements*.txt`;
`docs/operations/MAINTENANCE-WINDOW-RELEASE.md`,
`docs/architecture/PRODUCTION-RELEASE.md`.

## Amendment — Phase 0O.6A implementation (2026-09-26)

Implemented as specified, in `infrastructure/release/` (Python standard
library only; every tool a digest-pinned container image named in
`artifact-policy.json`), `.github/workflows/{ci,release-qualification,sbom-rescan}.yml`,
the production Dockerfiles, `services/ai/requirements.lock`, `.gitleaks.toml`,
`Tests\Feature\Configuration\SupplyChainGuardTest` and 62 release-tooling
tests. **NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS BEEN
PROMOTED.**

Implementation decisions and narrowings (none weakens the contract):

1. **Pinned digests are the last-qualified ones.** `php:8.3.33-fpm-bookworm`,
   `node:22.23.3-bookworm-slim`, `composer:2.10.2` and
   `python:3.12.14-slim-bookworm` are pinned to the exact digests the 0O.4A /
   0O.5A images were verified from (no silent toolchain change). The
   registry has since re-published the `composer:2.10.2` and
   `python:3.12.14-slim-bookworm` tags with newer digests, and
   `php:8.3-fpm-bookworm` has moved to a newer PHP; adopting any of them is
   an ordinary reviewed base update (§3.3).
2. **OCI archive through a dedicated builder.** Docker's default `docker`
   driver cannot export OCI archives, so each qualification creates a
   throwaway `docker-container` builder from a pinned BuildKit image, builds
   with `--no-cache` from a `git archive` of the commit (never the working
   tree) and exports the OCI archive (the identity) plus, from the same
   build, a docker archive loaded locally for `verify-images.sh` and the
   filesystem scans; the loaded image id must equal the archive's config
   digest. OCI labels (`source`, `revision`, `version`, `created`) come from
   the policy, `git` and the clock only.
3. **Grype scans the SBOM**, not the archive: verified identical results on
   both images, and it makes the scheduled re-scan of a retained SBOM the
   same operation. The database is refreshed per run and its build time
   checked (≤ 24 h at scan time).
4. **Image secret scan = gitleaks** (the ADR's "equivalent pinned tool") over
   the exported filesystem, plus a canary/shape scanner (committed
   development values outside the guards that refuse them, application
   credential shapes, `APP_KEY` values, PEM private-key material, `.env`
   files) and the config/history/env/label scan (build arguments leaked into
   `RUN` history, secret-shaped variables set in any layer). Allowlists are
   exact: gitleaks entries name one path and one value **and are scoped to
   the `generic-api-key` rule** — a global gitleaks 8.28 allowlist path
   exempts the whole file even with `condition = "AND"`, which the canary
   proof test demonstrates is avoided; the canary scanner's allowlist also
   pins the file's sha256 (the only entry: GnuTLS's compiled-in self-test
   keys).
5. **Exceptions are exact-version only** — the narrowest form of "version or
   version constraint": ranges are refused because version ordering differs
   between dpkg, PEP 440 and SemVer. An exception must also match the
   reported severity. Any invalid entry invalidates the whole file.
6. **Unrated language advisories** (pip-audit reports no severity; some
   Composer advisories) are treated as HIGH and fixable, i.e. blocking until
   resolved or excepted. UNKNOWN and NEGLIGIBLE scanner severities are
   recorded with MEDIUM/LOW.
7. **Language audits in PR CI report; the release gate blocks.** PR CI runs
   lockfile integrity, the audits (report only — an advisory published after
   merge must not block unrelated work), the release-tooling tests, a full
   image build with `verify-images.sh`, gitleaks over the full history (the
   pinned image, replacing the third-party gitleaks action and its token)
   and the PHP guards. The release workflow's `verify-artifact` applies the
   policy to the same reports.
8. **Lockfile integrity** = `composer validate --strict`, `npm ci --dry-run`
   (npm's own "lock out of sync" refusal) and `pip-compile` reproducing
   `requirements.lock` byte for byte, all in pinned images.
9. **Same-run regression** = `infrastructure/release/regression-gates` (every
   static/frontend/Gateway gate, the release-tooling tests, then the complete
   ERP regression through `bin/safe-test`), recorded with the run id and
   commit that the provenance and verifier check.
10. **Signing.** `cosign sign-blob` of `bundle.json` (every evidence file's
    sha256 plus each image's digest and SBOM/provenance/scan digests) with a
    per-run key generated in a private temporary directory, transparency-log
    upload disabled (no external service), private key destroyed after use.
    `verify-artifact` verifies through a custody `Verifier` that also
    implements `external-key` (a cosign-resolvable reference, no KMS SDK)
    and `keyless` (identity + issuer); with custody `unconfigured` and no
    test key it fails `signing_identity_not_configured`.
11. **Runners** are `ubuntu-24.04` in every workflow, not only release ones.

**Qualification result on the final code (2026-09-26, Grype database built
2026-09-26T06:29Z):** every mechanical check passes for both images (lock
integrity, source and image secret scans, build, `verify-images.sh`, SBOM,
provenance, signature, bundle integrity, digest, linkage), but **both images
FAIL `vulnerability_policy`** and therefore neither is VERIFIED:

- application image: 40 Critical and 112 High findings, 152 blocking (56
  distinct advisories) — all Debian bookworm packages of the PHP base;
  only OpenSSL (`libssl3`/`openssl` 3.0.20 → 3.0.22) has a fix, the rest
  (glibc, perl, curl, libxml2, sqlite, util-linux, ncurses, nginx, …) are
  `not-fixed`/`wont-fix` in Debian;
- AI Gateway image: 10 Critical and 75 High findings, 85 blocking (46
  distinct) — the same unfixed Debian classes, OpenSSL and PCRE2 with fixes,
  CPython 3.12.14 (fix only in a later minor), and **Starlette 0.47.3**
  (several advisories fixed in 0.49.1–1.3.1, which the pinned FastAPI
  0.116.1 does not allow).

No exception was created (an approval is a human security decision) and no
dependency or base was changed to make the gate pass (0O.6A was scoped to
lock the existing versions, with no upgrade and no auto-fix). Reaching VERIFIED needs an owner/security
decision: a reviewed base refresh (newer bookworm digests, or a newer Debian
release/minimal base), a FastAPI/Starlette upgrade, and exceptions — or a
base change — for the Debian findings that have no fix.

## Amendment — Phase 0O.6B release vulnerability remediation (2026-09-26)

Artifact remediation under owner decisions R1–R7 with the unchanged 0O.6A
gate (`docs/security/release-remediation/0O.6B-RELEASE-VULNERABILITY-REMEDIATION.md`,
finding matrix and proposed exception records alongside). **Status: BLOCKED —
RELEASE VULNERABILITY; no image VERIFIED, PUBLISHED or PROMOTED.**

- Both production images moved to Debian 13 "trixie" within the same
  official families (`php:8.3.35-fpm-trixie`, `python:3.12.14-slim-trixie`,
  digest-pinned); the application runtime purges the PHP image's extension
  build toolchain, libc headers, curl CLI and xz-utils; the Gateway takes the
  minimal security-only FastAPI 0.133.0 / Starlette 1.3.1 upgrade (+
  `annotated-doc`), nothing else in its graph changed; Python stays 3.12.14
  (the newest 3.12 release).
- Findings (same database): application 40 C / 112 H / 152 blocking →
  **9 C / 65 H / 74**; Gateway 10 C / 75 H (+6 pip-audit) / 85 →
  **0 C / 50 H / 50**, pip-audit clean.
- Remaining blockers: application — 9 genuine CRITICAL advisories in
  `libcurl4t64` (8) and `libxml2` (1), both linked by the official PHP binary,
  Debian `wont-fix`, no newer package in any trixie suite; Gateway —
  CVE-2026-82049 (CPython tarfile, HIGH, fixed only in CPython 3.14; a newer
  minor is outside R3) plus 12 HIGH-without-fix advisories whose proposed
  exception records await human/security approval.
- No evidence-backed false-positive/not-affected determination was
  possible; no exception was written; the policy, evaluator and tooling are
  unchanged.

## Amendment — Phase 0O.6C patched libraries & Python 3.14 (2026-09-26)

Owner decisions R8–R18 (`docs/security/release-remediation/0O.6C-PATCHED-LIBRARIES-PYTHON314.md`).
**Status: BLOCKED — RELEASE VULNERABILITY; nothing VERIFIED, PUBLISHED or
PROMOTED; no exception active.**

- AI Gateway: CPython 3.12.14 → **3.14.7** (`python:3.14.7-slim-trixie`,
  digest-pinned; the policy's pinned tool Python moved with it); pydantic
  2.11.7 → 2.12.0 and pydantic-core 2.33.2 → 2.41.1 (no cp314 wheel for the
  old core), nothing else changed. CVE-2026-82049 no longer matches.
  **Technical remediation complete:** 0 CRITICAL, 0 HIGH with a fix; 12
  HIGH-without-fix advisories (49 matches, Debian Essential packages) await a
  human exception decision (decision pack alongside, inactive).
- Application: the minimum published upstream fixes are curl **8.22.0**
  (clears all 8 libcurl CRITICAL + 10 HIGH advisories; `libcurl.so.4`;
  signature verified) and libxml2 **2.15.4** (the only release fixing the
  libxml2 HIGHs; ABI-breaking soname `libxml2.so.16`, so the official PHP
  binary cannot load it). The ABI-compatible 2.13.9 fixes the CRITICAL only
  and is unmaintained. Per Step 6 the unit stopped before forcing an
  ABI-breaking change: rebuilding PHP 8.3.35 from source against the patched
  libraries (recommended) or another option is an owner decision. The
  application image is unchanged.
- No policy, evaluator, validator or tooling-logic change; no scanner output
  edited; no Essential package removed.

## Amendment — Phase 0O.6D custom PHP runtime (2026-09-26)

Owner decision: the production application runtime is a **repository-built
PHP 8.3.35** (official source, signature-verified; official docker-library
configure flags, no source patch, PEAR omitted) linked against **curl 8.22.0**
(signature-verified; HTTP/HTTPS only, OpenSSL) and **libxml2 2.15.4**
(`libxml2.so.16`; checksum-verified), on `debian:13.7-slim` with no Debian
libcurl/libxml2 (`docs/security/release-remediation/0O.6D-CUSTOM-PHP-RUNTIME.md`,
`docs/operations/CUSTOM-PHP-RUNTIME.md`). **Lycenza maintains this runtime**;
the official PHP image is build-only.

- Application: **0 CRITICAL, 0 HIGH with a fix**; 48 HIGH-without-fix matches
  (12 Debian Essential-package advisories). Gateway unchanged: 0 CRITICAL, 0
  HIGH with a fix, 49 HIGH-without-fix matches. **Status: TECHNICAL
  REMEDIATION COMPLETE — AWAITING HIGH VULNERABILITY EXCEPTION DECISION**; one
  combined inactive decision pack; nothing VERIFIED, PUBLISHED or PROMOTED.
- Tooling: provenance now records the verified source archives (URL +
  sha256) as resolved dependencies; source-built libraries carry standard ELF
  `.note.package` metadata so Syft identifies them. No policy, evaluator or
  verifier change; no exception active; no scanner output edited.
