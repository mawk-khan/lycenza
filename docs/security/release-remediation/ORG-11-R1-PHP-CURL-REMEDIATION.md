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

## Qualification

The fresh *Release qualification* run on the published remediation commit, its
verdicts and the before/after package diff are recorded in the follow-up
documentation commit (this file and `docs/operations/ORG-11-STAGING-READINESS.md`
§4). Scanner policy, thresholds and `vulnerability-exceptions.json` were not
changed; none of the three advisories is excepted.
