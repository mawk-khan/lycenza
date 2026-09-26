# Custom PHP runtime — ownership and update procedure (Phase 0O.6D)

**Lycenza maintains its own PHP runtime.** Since Phase 0O.6D the production
application image does not ship the prebuilt binary from the official PHP
Docker image. It ships PHP compiled by this repository
(`infrastructure/docker/production/app.Dockerfile`, stages `sources` →
`php-build` → `runtime`).

The reason: Debian 13 ships libcurl 8.14.1 and libxml2 2.9.14 with unfixed
CRITICAL/HIGH advisories. The fixed upstream releases are curl 8.22.0 and
libxml2 2.15.4. libxml2 2.15.4 has a new ABI (`libxml2.so.16`) that the
official PHP binary cannot load
(`docs/security/release-remediation/0O.6C-PATCHED-LIBRARIES-PYTHON314.md`).

The official PHP Docker image project will **not** update these components
for us. It still ships Debian's libcurl/libxml2, and its image is now only a
build-time toolchain here.

## Current pins

| Component | Version | Source | SHA-256 | Signature |
|---|---|---|---|---|
| PHP | 8.3.35 | `https://www.php.net/distributions/php-8.3.35.tar.xz` | `ff4630fbbbd94359134b7d3c223db59329905bdc4f5a9ef93d257b48e358619a` | detached `.asc`; `VALIDSIG` must be one of the PHP 8.3 release managers `1198C0117593497A5EC5C199286AF1F9897469DC`, `C28D937575603EB4ABB725861C0779DC5C0A9DE4`, `AFD8691FDAEDF03BDF6E460563F15A9B715376CA` (keys from php.net's published keyring) |
| curl / libcurl | 8.22.0 (`libcurl.so.4`) | `https://curl.se/download/curl-8.22.0.tar.xz` | `f7ef3ae8a22e521f289803fe93543eb64c329b58aa73a9e224dfd915a2a5f4f7` | detached `.asc`; `VALIDSIG` must be `27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2` (Daniel Stenberg) |
| libxml2 | 2.15.4 (`libxml2.so.16`) | `https://download.gnome.org/sources/libxml2/2.15/libxml2-2.15.4.tar.xz` | `98087fd181d9070724f3fbc65c7377db03038eb92bd882374daff44940138821` | none published by GNOME. The pinned SHA-256, equal to GNOME's `.sha256sum`, is the integrity mechanism. |

Build-only images, all digest-pinned in the Dockerfile:
- `php:8.3.35-fpm-trixie`: the official image, used for its recipe environment, `PHPIZE_DEPS` and `docker-php-*` scripts;
- `node:22.23.3-bookworm-slim` and `composer:2.10.2`.

Runtime base: `debian:13.7-slim`, the official PHP image's own base.

**Build choices**
- **PHP:** the official docker-library configure flags. PEAR is omitted: it is dev tooling, and its install downloads an unverified phar.
- **curl:** OpenSSL (Debian's `libssl3t64`, the system TLS stack), HTTP/2 (nghttp2), zlib, IDN (libidn2), the Public Suffix List (libpsl), and the Debian CA bundle. Protocols are **HTTP and HTTPS only**. There is no curl CLI.
- **libxml2:** the default configuration without Python bindings.
- **Loader:** the patched libraries live in `/usr/local/lib`, registered through `/etc/ld.so.conf.d/00-lycenza-native.conf` and `ldconfig`.
- **SBOM identity:** each library carries a standard ELF `.note.package` (FDO packaging metadata) so Syft can identify it.

## When a rebuild is due

Review and rebuild when any of these happens:
- a PHP 8.3 security release;
- a curl security advisory (`https://curl.se/docs/vuln.json`);
- a libxml2 security release (GNOME `NEWS` / NVD);
- a digest update of any base image (security refresh of `debian:13.x-slim` or of the official build image);
- a CRITICAL/HIGH finding from the scheduled SBOM re-scan against any of these components.

Grype identifies the source-built libraries only through NVD CPE data. An
advisory recorded only in a distribution's tracker will not appear, so
checking curl's `vuln.json` and libxml2's release notes is part of every
review.

## Update procedure

1. **Select** the exact upstream release. Never a branch, a snapshot or a moving tag.
2. **Verify the source.** Download it, compute its SHA-256, and check the detached signature against the pinned fingerprints (PHP, curl). For libxml2, compare against GNOME's published `.sha256sum`.
3. **Update the pins.** In the `sources` stage, change `LYCENZA_<NAME>_VERSION`, `_URL` and `_SHA256`, plus the fingerprints if a release manager changes, which needs a separate review of the new key. Then update the hard-coded version checks in `php-build`, the ELF note versions, `verify-images.sh`, `CustomPhpRuntimeGuardTest`, and this runbook's table. The guard fails until all of them agree.
4. **Build** the production image (`verify-images.sh --build`). The build itself fails on a checksum or signature mismatch, on a library version mismatch, or on an unresolved library.
5. **Verify extensions and linkage.** `verify-images.sh` checks:
   - the exact module set (`php-modules.expected`);
   - that `php` and `php-fpm` resolve libcurl/libxml2 from `/usr/local/lib`;
   - that no Debian copy of either library is present;
   - the ELF notes;
   - that no headers or source remain;
   - the native smoke: the webhook HTTP client stack over real TLS, the AWS SDK against MinIO, and every XML API, repeated, with crash signatures refused.

   An ABI change (a new soname) needs a new review, like Phase 0O.6C's Step 6 stop.
6. **Run the full tests:** the complete regression (`SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test --reset-db`, then `safe-test`), the quality gates, and the release-tooling tests.
7. **SBOM and scan.** Check that the SBOM lists `php-cli`/`php-fpm`, `curl` and `libxml2` at the new versions, and that Grype, with a fresh database, reports no CRITICAL and no HIGH-with-fix for them. Approved exceptions (`OWNER-0O6E-2026-09-26`) cover only their exact Debian packages and versions: any new residual finding blocks and needs its own decision. The rebuilt image must still pass the runtime security contract checks (`runtime-security.json`) for the conditional ones to apply.
8. **Qualify the release** (`infrastructure/release/qualify all …`) on the published commit. The provenance records the three source archives (URL + SHA-256) and every base-image digest.

A PHP *minor* upgrade (8.4) is a separate decision, not a routine rebuild.
