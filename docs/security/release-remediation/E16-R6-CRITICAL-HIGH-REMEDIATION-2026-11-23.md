# E16-R6 — Critical and fixable High remediation from the E16-S3 scan (2026-11-23)

**Status: REMEDIATED, except one fixable High that has no released fix (§5).**
- **Authorization:** the owner's E16-R6 decision of 2026-11-23, "bounded remediation", following the E16-S3 review authorization of the same day.
- **Exceptions:** `vulnerability-exceptions.json` is **not** changed. `OWNER-0O-E16-2026-11-01` stays valid through 2026-11-28. Renewal is E16-S3's separate decision.
- **Scope:** no product, dependency-lock, business-logic or policy change.

## 1. Baseline

- `origin/main` = `df899e1908a94b1877fe022c46ad6a17eca5fb20`; clean tree; one worktree; the historical stash and `integration/e16-r2` untouched.
- Ruleset 24909660 is active with 0 bypass actors.
- **The trigger:** E16-S3's fresh qualification, run **45105136282** on `df899e19`. It failed **only** `vulnerability_policy_blocking_findings`. That run stays recorded as a failure and was not rerun. Evidence is kept outside the repository: `~/lycenza-release-evidence/e16-2026-11-23/`.

## 2. Findings mapped to this change

| Finding (E16-S3 scan, Grype 0.100.0, DB 2026-11-22) | Image | Fix | Change here |
|---|---|---|---|
| **Critical** CVE-2026-115560, CVE-2026-126714, CVE-2026-129289, CVE-2026-129481, CVE-2026-129496 (php-cli, php-fpm 8.3.36) | app | PHP 8.3.37 | PHP 8.3.36 → **8.3.37** |
| High CVE-2026-127960, CVE-2026-128064, CVE-2026-128090, CVE-2026-129291 (PHP 8.3.36) | app | PHP 8.3.37 | same |
| High CVE-2026-19499, CVE-2026-5435 (libc6, libc-bin `2.41-12+deb13u4`; previously excepted, now superseded) | both | `2.41-12+deb13u5` | in-place `--only-upgrade` (§3) |
| High CVE-2026-109518 (apt, libapt-pkg7.0 3.0.3) | both | 3.0.4 | refreshed base digests |
| High CVE-2026-85091 (zlib1g `+b1`; previously excepted, now superseded) | ai-gateway | `1:1.3.dfsg+really1.3.1-1+deb13u1` | in-place `--only-upgrade` |
| High CVE-2026-119170 (python 3.14.10) | ai-gateway | 3.14.11, **not released** | **none** (§5) |

## 3. Change

| File | Change |
|---|---|
| `infrastructure/docker/production/app.Dockerfile` | `PHP_IMAGE` `php:8.3.37-fpm-trixie@sha256:e8d97ebb4e9c47c71993ed9367a96374c2d033facf16ff2e930e4e4a7c06ca6f` (build-only); `LYCENZA_PHP_VERSION/URL/SHA256` for 8.3.37; `RUNTIME_IMAGE` `debian:13.7-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2`; runtime stage: `apt-get install --only-upgrade libc6 libc-bin` |
| `infrastructure/docker/production/ai.Dockerfile` | `PYTHON_IMAGE` `python:3.14.10-slim-trixie@sha256:9aaa474097d094040649f2162a627c0049b408f52138d980916788d80f04802f` (same tag, refreshed digest); runtime stage: `apt-get install --only-upgrade libc6 libc-bin zlib1g`, lists removed |
| `infrastructure/docker/production/verify-images.sh`, `runtime-checks/php-native-smoke.php` | the expected PHP version, 8.3.37 |
| `apps/platform/tests/Feature/Configuration/CustomPhpRuntimeGuardTest.php` | `PHP_VERSION` 8.3.37 |
| `docs/operations/CUSTOM-PHP-RUNTIME.md` | current pins and the E16-R6 entry |
| this record | — |

**Why the glibc and zlib packages are upgraded in place.** Debian 13 published `libc6`/`libc-bin` `2.41-12+deb13u5` and `zlib1g` `1:1.3.dfsg+really1.3.1-1+deb13u1`. Both are the archive candidates as of 2026-11-23. But **no** current `debian:13.7-slim` or `python:3.14.10-slim-trixie` digest contains them. Both refreshed digests still carry glibc `deb13u4`, and the Python base still carries zlib `+b1`, so a digest refresh alone cannot deliver these fixes.
- `--only-upgrade` installs nothing new. The build logs show "2 upgraded, 0 newly installed" for app and "3 upgraded, 0 newly installed" for ai-gateway.
- Packages come from the Debian archive under apt's signed-repository verification, as the app runtime stage's existing `apt-get install` already does.
- Each line carries a comment to drop it once the pinned base contains the fix.

**Unchanged:**
- curl 8.23.0, libxml2 2.15.4, and the signature and SHA-256 enforcement;
- the runtime security contract (non-root, `dpkg-statoverride` on mount/umount);
- the Node and Composer build images;
- the tooling Python pins (`artifact-policy.json` `tools.python`, the verify-images TLS helper), which ship in no image;
- every dependency lock, the vulnerability policy and evaluator, and the exception file.

## 4. Proof (pre-publication)

**Upstream source.**
- `php-8.3.37.tar.xz` SHA-256 `0237af84478b7a7649d3964153f1b8653d622550b846f56d63062de683fb86bb` equals php.net's release metadata (released 2026-11-19).
- The detached signature gives `VALIDSIG C28D937575603EB4ABB725861C0779DC5C0A9DE4`, one of the three pinned PHP 8.3 release managers.
- This was checked independently, and again inside the build's `sources` stage, which ended `php.tar.xz: OK` plus the pinned-fingerprint check under `set -e`.

**Base images.** The digests were resolved from Docker Hub with `docker buildx imagetools inspect`, and their package versions read with `dpkg-query` in throwaway containers.

| Image | glibc | apt | zlib1g |
|---|---|---|---|
| `debian:13.7-slim` old `a29215f6…` | deb13u4 | 3.0.3 | +b1 |
| `debian:13.7-slim` new `a72b4433…` | deb13u4 | **3.0.4** | +b1 |
| `python:3.14.10-slim-trixie` old `3cabfd7d…` | deb13u4 | 3.0.3 | +b1 |
| `python:3.14.10-slim-trixie` new `9aaa4740…` | deb13u4 | **3.0.4** | +b1 |

**Fresh local builds of the candidate** (`qualify build`, fresh builder, no cache; diagnostic only):
- **Packages:**
  - app: `libc6`/`libc-bin` 2.41-12+deb13u5, apt 3.0.4, zlib1g +deb13u1;
  - ai-gateway: `libc6`/`libc-bin` 2.41-12+deb13u5, apt 3.0.4, zlib1g +deb13u1.
- **PHP:** `PHP_VERSION` 8.3.37, curl 8.23.0, libxml2 2.15.4. The module set equals `php-modules.expected`.

**Scan** (pinned Grype 0.100.0, DB 2026-11-23T06:28Z, scored by the repository's `evaluate()` against the current exception file):

| Image | Critical | High with a fix | High without a fix (E16-S3) |
|---|---|---|---|
| app | **0** (was 10) | **0** (was 14) | 33 |
| ai-gateway | 0 | **1**: CVE-2026-119170 (was 8) | 29 |

**Exception records that stop matching (independent review, MEDIUM, disclosed here).** Exception records name an exact version, so the version moves leave these E16-S2 records unmatched. Nothing is weakened by this: each affected finding either is gone or blocks until E16-S3 decides it.
- **CVE-2026-84243** (glibc gettext, `wont-fix`), the **4** records pinned at `2.41-12+deb13u4` (libc6 and libc-bin, both images). The finding is still present at `deb13u5`, so it **now blocks** in both candidate scans. Those 4 entries are counted in the 33 and 29 above. They are the only blocking entries in the candidate that were not blocking in the E16-S3 scan, and they are not new vulnerabilities. E16-S3 has to decide them again at `deb13u5`, including the `libc-bin` gettext rationale that E16-S3 found to be wrong.
- **CVE-2026-19499 and CVE-2026-5435** (8 records): the findings are gone, so the records are unused.
- **CVE-2026-85091** (2 records): the finding is gone, so the records are unused.
- **CVE-2026-107778** (4 app records at krb5 `deb13u1`): already unused in the E16-S3 scan.

No new vulnerability identifier appears in either candidate scan. The match totals fell from 383 to 255 (app) and from 246 to 214 (ai-gateway). This scan is diagnostic: the exact-SHA CI lanes do not run Grype, and the authoritative scan is the post-publication qualification.

**Tests:**
- `CustomPhpRuntimeGuardTest`, `SupplyChainGuardTest` and `ReleaseRollbackContractDocumentTest`: 16 tests, OK (isolated Compose project);
- `infrastructure/release/tests`: 101 OK;
- `infrastructure/ci/tests`: OK.

The complete regression, `verify-images.sh` and the production-image build run in the candidate's exact-SHA `CI gate` (strict lanes for `infrastructure/**`).

## 5. CVE-2026-119170 — no released fix (D hard stop)

- **Advisory:** a regression in hashlib's FIPS algorithm enforcement. It affects only processes using OpenSSL **in FIPS mode**, and only CPython **3.14.10**.
- **Availability:** Grype names 3.14.11 as the fix, but on 2026-11-23 python.org's newest 3.14 release is 3.14.10, with no `3.14.11/` directory, and Docker Hub has no `3.14.11-slim-trixie` tag. The only released fixed line is 3.15.x: a minor-version change that needs a regenerated hash-locked `requirements.lock`, which is outside this scope.
- **Applicability:** the Gateway image has no FIPS provider (no `fips.so`, no `openssl-provider-fips`, no FIPS directive in `openssl.cnf`), so the vulnerable path is not used.
- **Policy is unchanged:** a High with a fix is never exception-eligible, so this finding keeps blocking the Gateway's release qualification until a fixed CPython 3.14 image exists. Nothing is excepted, and E16-R6 is **not** claimed complete for it.
- **Next step:** re-check python.org and Docker Hub for 3.14.11, then the same digest-pinned bump as E16-R3.

## 6. Not done here

- No exception added, renewed or removed.
- No qualification rerun of 45105136282.
- No promotion, registry, signing identity or deployment.
- No DDEV, Jurimatter or real-data contact.

The E16-S3 successor decision, which covers the remaining no-fix Highs and the erroneous `libc-bin` gettext rationale, is separate.
