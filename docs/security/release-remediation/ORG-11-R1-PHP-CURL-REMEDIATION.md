# ORG.11-R1 — PHP 8.3.36 / curl 8.23.0 runtime remediation (2026-10-26)

Status: **remediation record** (ADR 0076 §81). Closes ORG.11 STG-06 when the
qualification below reports `app = VERIFIED`.

## Blocking findings (qualification run 40310279869, `135de265`)

| Advisory | Package | Severity (policy) | Fixed in |
|---|---|---|---|
| CVE-2026-109618 | repository-built PHP 8.3.35 (`php-cli`, `php-fpm`) | Critical | 8.3.36 (2026-10-22) |
| CVE-2026-109619 | repository-built PHP 8.3.35 | High with fix (treated as Critical by the owner brief) | 8.3.36 |
| CVE-2026-92392 | repository-built curl 8.22.0 (`libcurl.so.4`) | High with fix | 8.23.0 (2026-10-20) |

## Change (frozen emergency rule: narrow, same-major)

- **PHP 8.3.35 → 8.3.36** (same 8.3 line). Source
  `https://www.php.net/distributions/php-8.3.36.tar.xz`, SHA-256
  `dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd` (equal to
  php.net's release metadata); detached signature `VALIDSIG
  AFD8691FDAEDF03BDF6E460563F15A9B715376CA` (one of the three pinned PHP 8.3
  release managers). Build-only toolchain image
  `php:8.3.36-fpm-trixie@sha256:56e2cf0558ddfbe36e90030f763b72be2f763bcd9075890e9c68420e0dfa827e`
  (was `php:8.3.35-fpm-trixie@sha256:e0623b71…`).
- **curl 8.22.0 → 8.23.0** through the existing verified source path (Phase
  0O.6D architecture: Debian's libcurl is purged; no new download path). Source
  `https://curl.se/download/curl-8.23.0.tar.xz`, SHA-256
  `8dadd7d2536a314f982ad05a651b713f426bf7d02cc2c76cffb9796f2218e6c5`; detached
  signature `VALIDSIG 27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2` (pinned).
- **Unchanged:** runtime base `debian:13.7-slim@sha256:a29215f6…`, libxml2
  2.15.4, Node 22.23.3, Composer 2.10.2, `composer.lock`, `package-lock.json`,
  `services/ai/requirements.lock`, the PHP configure flags and extension set
  (`php-modules.expected`), the scanner policy and every exception.
- **Pins updated together:** `app.Dockerfile`, `verify-images.sh`,
  `runtime-checks/php-native-smoke.php`, `CustomPhpRuntimeGuardTest`,
  `test_sbom_provenance_archive.py`, `CUSTOM-PHP-RUNTIME.md`,
  `PRODUCTION-IMAGES-AND-PROCESSES.md`, `infrastructure/release/README.md`.

## Qualification (run 40327776824, `66be83a6`)

Published through the protected flow: candidate `66be83a6` → `CI gate` run
40325590541 green (4 shards = canonical inventory: 9,585 cases, 9,584 passed
+ ESI-12 skip, 365,639 assertions) → `publication-check --push` (fast-forward
of `main` from `779e009c`). *Release qualification* was then dispatched on
protected `main`:

| Evidence | Result |
|---|---|
| Same-run complete regression | 9,585 tests, 1 skip (ESI-12), 366,384 assertions, exit 0 |
| Source secret scan / image secret + history scans | 0 / 0 / 0 findings |
| Language audits | non-blocking only: `league/flysystem` ≤3.35.2 (Low), `postcss-selector-parser` (Moderate, npm, build-time) |
| `verify-images.sh` | 143 PASS, 0 FAIL — "PHP is the repository-built 8.3.36", "libcurl 8.23.0 and libxml2 2.15.4 are the loaded runtime versions" |
| **`app`** `sha256:7d2591e62d45baa8f2905f724194d72e63563caea54e1f250e98492c9f110868` | **VERIFIED** (16/16 checks PASS; 0 blocking, 60 excepted; 0 Critical) |
| **`ai-gateway`** `sha256:ef524d9be34e3a5b14c3f5fa9f2393a4f5dfbcc4d48e0fbc87a5e1f1ab166aed` | **VERIFIED** (0 blocking, 55 excepted) |
| CVE-2026-109618 / CVE-2026-109619 / CVE-2026-92392 | **absent** from the fresh scan of both images |
| Signing | ephemeral NON-PRODUCTION key (qualification only; nothing pushed, published or promoted) |

**App package diff (SBOM, run 40310279869 → 40327776824):** 216 → 216
packages; only `php-cli`, `php-fpm` and the bundled extensions (`bcmath`,
`gd`, `opcache`, `pcntl`, `pdo_pgsql`, `pgsql`, `sodium`) 8.3.35 → 8.3.36 and
`curl` 8.22.0 → 8.23.0 changed. Scanner matches 213 → 208 (the 2 Critical
and 3 High above removed; nothing added). The `ai-gateway` package set is
identical (117 → 117); only its digest changed (fresh no-cache build).

Scanner policy, thresholds and `vulnerability-exceptions.json` were not
changed; none of the three advisories was excepted. **STG-06 is CLOSED.**
