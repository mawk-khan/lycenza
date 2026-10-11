# E16-R1 — Supply-chain vulnerability remediation (2026-10-11)

**Status: REMEDIATION COMPLETE / FRESH OWNER DECISION REQUIRED.**

The post-remediation scan has **0 Critical and 0 High with a fix**. Four
**new** High findings have no fix (18 matches). They are not covered by any
exception and need a fresh owner/security decision, which this slice does
not make. Until that decision exists, no image is VERIFIED.

The slice changed no exception record. `vulnerability-exceptions.json` is
byte-identical (sha256
`4f811d3e8fd52d1277126a0a277e0b07a74e30da2c4d3061f4bff232173f7efb`), and
its 97 records of `OWNER-0O-E16-2026-09-29` still expire on 2026-10-29.

**PUBLISHED = NONE, PROMOTED = NONE.**

## 1. Why this slice exists

The E16 fresh scan of `ef575bf` (2026-10-11, run
`local-20261011T070805Z-dd2d0259`) ended **BLOCKED**. ADR 0052's evaluator
never lets an exception cover a Critical, or a High that has a fix. The
historical facts of that scan stand as recorded. This document adds to
them; it does not replace them.

| Fact | `ef575bf` (E16 scan, before) |
|---|---|
| Grype / database | 0.100.0, database built 2026-10-11T06:30:42Z |
| Image matches | app 208, Gateway 207 |
| Critical | **2 advisories**, 2 matches: CVE-2026-19445 (CPython 3.14.7, Gateway) and GHSA-pqg4-j6r4-53mv (npm `shell-quote` 1.9.0) |
| High with a fix | **12 advisories**, 22 matches (image and language combined) |
| High without a fix, unexcepted | 4 advisories, 18 matches |
| Excepted (97 records) | 97 matched, 0 unused |
| Language audits | 12 advisories, 7 blocking |

Evidence (outside the repository, left unchanged):
`~/lycenza-release-evidence/e16-2026-10-11/run2/evidence`.

## 2. What was remediated

Each item is the smallest change that removes the finding. Fixes come from
parent upgrades. There are no forced overrides, no hand-edited lock entries
and no downgraded tooling.

| # | Finding (severity) | Where | Old | New | How |
|---|---|---|---|---|---|
| 1 | CVE-2026-19445 (**Critical**), CVE-2026-19553 (High) | Gateway CPython | 3.14.7 | **3.14.8** | Base `python:3.14.8-slim-trixie@sha256:a2b82f3c48559aa0a8446d9af49826b6e2b2016f4cd2afabfe6013ec53729170`. The pinned release-tool Python (`artifact-policy.json`) and the `verify-images.sh` TLS helper follow it. |
| 2 | CVE-2026-54873, -72897, -84782, -84784 (High ×4, 12 matches) | Gateway `libssl3t64`, `openssl`, `openssl-provider-legacy` | 3.5.7-1~deb13u2 | **3.5.7-1~deb13u3** | Carried by the 3.14.8 base layer. |
| 3 | CVE-2026-103111 (High, 2 matches) | `libpcre2-8-0`, both images | 10.46-1~deb13u2 | **10.46-1~deb13u3** | Gateway: the 3.14.8 base. App: runtime base refreshed to `debian:13.7-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f` (was `a99cfc51…`). The runtime stage installs packages but never upgrades the base. |
| 4 | GHSA-3q6v-r5mr-hxv8 (High; also the Medium GHSA-97jj-33gv-5xf9) | Composer `league/commonmark` | 2.10.0 | **2.10.3** | `composer update league/commonmark --no-scripts --no-plugins`. The only Composer change. |
| 5 | GHSA-pqg4-j6r4-53mv (**Critical**) | npm `shell-quote` (via `concurrently`) | 1.9.0 | **1.12.0** | `concurrently` 10.0.5 → 10.0.6. |
| 6 | GHSA-g2v6-rqmx-r4w6 (High) | npm `@vue/server-renderer` | 3.5.41 | **3.5.43** | `vue` 3.5.41 → 3.5.43 (with its `@vue/*` set). |
| 7 | GHSA-68fv-2mgg-jv7q (High) | npm `source-map-js` | 1.2.1 | **1.2.2** | In-range lock update. |
| 8 | GHSA-qhr7-859c-m2p7, GHSA-6j4f-fj2g-mc7p (High) | npm `brace-expansion` | 5.0.9 | **5.0.12** | In-range lock update. |
| 9 | GHSA-vfj7-8cjw-p6xm (High) | npm `braces` ≤3.0.3 | 3.0.3 | **removed** | No patched `braces` exists. npm's only "fix" was a downgrade of `@vue/eslint-config-typescript` to 14.0.1. See below. |

Other lock changes came along with these parents: `@babel/parser` 7.29.8 →
7.29.9, `nanoid` 3.3.18 → 3.3.20, `postcss` 8.5.26 → 8.5.29. Nothing else
moved.

**Item 9 in detail.** `braces` reached the tree only through
`@vue/eslint-config-typescript` → `fast-glob` → `micromatch`. That
wrapper's `createConfig()` uses `fast-glob` to scan the whole project for
type-aware rules. This repository enables none, so the scan's result was
never used.

`apps/platform/eslint.config.js` now writes out the same configuration
directly. It uses the two packages the wrapper wrapped, both already
installed at the same versions: `typescript-eslint` 8.67.0 and
`vue-eslint-parser` 10.4.1. Equivalence was checked as follows:
- `eslint --print-config` output is byte-identical before and after, for a
  `.vue` file and a `.ts` file;
- the lint result is identical: 241 files, 0 errors, the same 3 `v-html`
  warnings.

The `fast-glob` chain (17 lock entries) is gone. No lint or security tool
was downgraded.

**Not changed (non-blocking, out of scope).** Three language advisories
remain, none of them blocking:
- Composer `laravel/framework`, PKSA-d5tc-s1qs-h781 (Low);
- Composer `league/flysystem`, PKSA-w9tt-7782-78jx (Low);
- npm `postcss-selector-parser`, GHSA-rj75-hqrm-r3gf (Moderate).

Fixing them would mean a framework refresh or unrelated upgrades, which
this slice excludes. pip-audit: 0. The Gateway's `requirements.lock`
inputs did not change, so the lock was not regenerated.

## 3. Post-remediation scan

Remediation commit **`ab8e1ae487de1b9fb286b05aad5f7f0dc02a7e6e`**,
canonical stages `init`, `inputs`, `source-scan`, `audit`, `build`,
`verify-images` and `scan`.

- **Run:** `local-20261011T083057Z-af070f55`, started 2026-10-11T08:30:57Z.
  It ran on the local commit before push, so `on_protected_main_at_start`
  is recorded as `false`. That value is recorded, not enforced.
- **Builds:** fresh builder, no cache.
- **Evidence:** a new directory,
  `~/lycenza-release-evidence/e16-2026-10-11/remediation/evidence`.
  `run2` was not touched.

| Image | Manifest digest | Config digest | Created (UTC) |
|---|---|---|---|
| Application | `sha256:f29ef25567c58f6215a8a2770af52a6535f1981c00e12030ed3a13e3b91651df` | `sha256:d67352c8875017de4da73cef867824528965ab57159587e0b5268632bc687a3e` | 2026-10-11T08:44:14Z |
| AI Gateway | `sha256:8886e2fc750e661180e5683aa199b57d102d485fca7d2976e8c42b43298e1536` | `sha256:eb29910c1b6ea286138b1342c62a3d60b35f69cc4832f06f74c9fbd7d69accf2` | 2026-10-11T08:45:14Z |

- **SBOMs** (Syft 1.33.0, SPDX, new):
  - app `6485afece0c18b472fc2afb259e6f0e553d789a7f3b3e924f2f2cb9f8b92f37a`
    (created 08:50:31Z);
  - Gateway `65e64d6ab6978e36965ed3b4312be1c804a94b8d21cf48bd8963ca1a1634faae`
    (created 08:51:02Z).
- **Grype:** 0.100.0. The database was refreshed per run (built
  2026-10-11T06:30:42Z, about 2 h 20 min before the scan, within the 24 h
  policy).
- **Reports:**
  - app `grype.json` `f7f5d0b59187dd10b52ad2fe70b101b1edf729300abb17b16df1d8c2319669d8`;
  - Gateway `grype.json` `37851e65d3fecdc3d6d3a956d1c45f66398f78d86e4bbf507a181131dbc93f44`.
- **Confirmed versions in the built images:**
  - from the SBOM, and inside the container:
    - Gateway `python -c 'import sys'` reports 3.14.8, `ssl` reports
      OpenSSL 3.5.7;
    - `dpkg-query`: `libssl3t64`/`openssl`/`openssl-provider-legacy`
      3.5.7-1~deb13u3 and `libpcre2-8-0` 10.46-1~deb13u3, in both images;
  - in the app's `vendor/composer/installed.json`: `league/commonmark`
    2.10.3.
- **`verify-images.sh`:** 133/133 PASS.
- **Secret scans:** 0 findings in source and in each image.

Reconciliation used the repository's own evaluator
(`lycenza_release.evaluate`, exceptions loaded for 2026-10-11):

| | Before (`ef575bf`) | After (`ab8e1ae`) |
|---|---|---|
| Image matches (app / Gateway) | 208 / 207 | 205 / 161 |
| **Critical** | 2 advisories, 2 matches (1 image, 1 language) | **0** |
| **High with a fix** | 12 advisories, 22 matches | **0** |
| High without a fix, excepted | 97 matches (all 97 records used) | 97 matches (all 97 records used, 0 unused) |
| High without a fix, **unexcepted** | 4 advisories, 18 matches | 4 advisories, 18 matches (same set) |
| Language audits | 12 advisories, 7 blocking | 3 advisories, **0 blocking** |
| Evaluator verdict | FAIL | FAIL — only `high_requires_exception` |

## 4. Residual set awaiting a fresh owner decision (evidence only)

These are the same four advisories the E16 scan found. All are in Debian 13
packages, with a Debian fix state of `wont-fix` or `not-fixed`. The
exploitability evidence below was **re-checked on the remediated images**
(dynamic-link scan of every file, symbol imports and package lists). It was
not copied from the earlier scan.

It is evidence for the owner's decision. **It is not a risk acceptance.**

| Advisory | Packages (version) | Images | Matches | Re-verified evidence |
|---|---|---|---|---|
| CVE-2026-107778 (krb5 `make_cred_list()` NULL dereference; a **GSS-API acceptor** is crashed by an authenticated client) | `libgssapi-krb5-2`, `libk5crypto3`, `libkrb5-3`, `libkrb5support0` (1.21.3-5+deb13u1) | app | 4 | Pulled in by `libpq5` (17.11-0+deb13u1) for PHP `pgsql`/`pdo_pgsql`. libpq imports only `gss_init_sec_context`, the initiator. It never accepts a context. The only importer of `gss_accept_sec_context` is `libtirpc`, and its only consumer is `/usr/bin/ss` (iproute2), a diagnostic tool that no process role starts. Not present in the Gateway. |
| CVE-2026-107161 (Cyrus SASL **DIGEST-MD5 plugin** heap overflow, client side) | `libsasl2-2`, `libsasl2-modules-db` (2.1.28+dfsg1-9) | app | 2 | Linked by `libpq` and `libldap`. `libsasl2-modules`, which ships DIGEST-MD5, is **not installed**. The plugin directory holds only `libsasldb`, and no file in it names DIGEST-MD5. Not present in the Gateway. |
| CVE-2026-102010 (libstdc++ `erase_if` on a priority queue, use-after-free) | `gcc-14-base`, `libgcc-s1`, `libstdc++6` (14.2.0-19) | both | 6 | In both images, `libstdc++.so.6` is linked only by APT (`apt*`, `libapt-pkg`, `libapt-private`). That is base-image Essential tooling which no process role runs. Every process runs non-root with no capabilities and `no-new-privileges`, and those runtime checks pass. No PHP extension, Python wheel or service binary links it. |
| CVE-2026-95619 (libstdc++ aligned `operator new` integer overflow) | the same three packages (14.2.0-19) | both | 6 | The same as CVE-2026-102010. |

Unchanged: the 97 records of `OWNER-0O-E16-2026-09-29` still match exactly,
and none is unused. They are valid through 2026-10-28 and expire on
2026-10-29. Renewing them is part of the same future decision.

## 5. Gates for this slice

- `qualify inputs`: lock integrity in sync.
- `qualify audit`: 0 blocking.
  - The CI `supply-chain` job runs this stage, so it is green on this tree.
  - It will fail from 2026-10-29 unless a fresh decision replaces the
    expiring file.
- Release tooling tests: 85 OK.
- `tests/Feature/Configuration`: 45 tests OK.
- Gateway: ruff, ruff format and mypy clean; pytest 153 passed.
- npm: `type-check`, `lint`, `format:check` and `build` pass. `npm audit`:
  0 critical, 0 high, 1 moderate.
- The canonical full regression and the publish gate run on the published
  tree. Their results are recorded in `PHASE-0O-READINESS.md` §49 and the
  slice report.

## 6. What this slice does not do

- It records no owner decision and adds, edits or renews no exception.
- It does not mark E16 closed or the supply-chain gate current.
- It does not make an image VERIFIED. `verify-artifact` would fail today on
  the 18 unexcepted no-fix matches.
- It publishes nothing to a registry, signs with no production identity and
  deploys nothing.
