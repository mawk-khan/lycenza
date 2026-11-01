# E16 successor review — 2026-11-01 (E16-S2, with remediation E16-R3)

**Status: DECIDED 2026-11-01.**
- **E16-S2:** approved as proposed, as `OWNER-0O-E16-2026-11-01`,
  expiring 2026-11-29. See
  [`0O-E16-2026-11-01-owner-security-decision.md`](0O-E16-2026-11-01-owner-security-decision.md).
- **E16-R3:** the Gateway Critical CVE-2026-115557 is remediated (CPython
  3.14.8 → 3.14.10). It is never excepted.
- **Out of scope, deferred by the owner:** `symfony/mime` Medium
  CVE-2026-45070, to be fixed in its own slice after E16.

**Naming.** The brief called this checkpoint "E16-R3". The repository
convention is:
- **`E16-S<n>`** for a successor decision (E16-S1 was 2026-10-16), so this
  one is **E16-S2**;
- **`E16-R<n>`** for a remediation slice (E16-R1 on 2026-10-11, E16-R2 held
  and then retired), so the CPython fix is **E16-R3**.

No parallel naming scheme was introduced.

## 1. Baseline

**Repository**
- `origin/main` = local `main` = HEAD = `bbc3cb24e25bfd4f1134f22b6fcaca4b9beead15`
  (SIS 11.10).
- The tree was clean, with one worktree. The historical stash
  (`phase-5d3-preflight`) is untouched.
- The latest `main` CI was green: run 41287196778 (`main` push) and the
  exact-SHA `CI gate` run 41285786475.
- `ddev env-check`: PASS.

**Current decision before this review**
- `OWNER-0O-E16-2026-10-16` (E16-S1): 115 records, 16 advisories, all
  `high`, created 2026-10-16, expiring **2026-11-13** (last valid day
  2026-11-12).
- E16-P1 is in force: a Critical is never exception-eligible.

**Historical branch.** `integration/e16-r2` = `fb29aff5` is **unmerged**
(151 commits behind and 1 ahead of `main`).
- **Status:** the owner held it on 2026-10-16, and ORG.11-R1 retired it on
  2026-10-26 (Debian's fixed nginx scans clean).
- **Retention:** every later record calls it "untouched" fallback
  evidence. No contract asks for its removal.
- **This review:** not read, reused, rebased or deleted. The successor was
  developed on a fresh branch, `integration/e16-s2`.

## 2. Fresh scan

**Run.** The *Release qualification* workflow on protected `main`, run
**41287488442**, started 2026-11-01T08:08:26Z (`on_protected_main_at_start:
true`).
- **Stages:** inputs, the same-run complete regression (passed, 4,229 s),
  source scan, audits, a fresh-builder build, `verify-images`, scan,
  provenance, sign and verify.
- **Evidence** (outside the repository):
  `~/lycenza-release-evidence/e16-2026-11-01/ci/`.

| Check | Result |
|---|---|
| Lockfiles (composer, npm, Python) | in sync |
| Same-run complete regression | passed, exit 0 |
| Source secret scan (gitleaks) | 0 |
| Image secret and history scans | 0 / 0 (both images) |
| `verify-images.sh` | 144 checks, exit 0 |
| Grype | 0.100.0, database built 2026-11-01T06:20:12Z (3.2 h old) |
| **app** `sha256:3c93bf0f…b4e3` | FAIL: 2 blocking (new CVE-2026-84243), 60 excepted, 0 Critical |
| **ai-gateway** `sha256:770fa783…6459` | FAIL: 1 Critical (CVE-2026-115557) + 2 blocking (CVE-2026-84243), 55 excepted |

**Unused records:** 0 in either image. Every one of the 115 records still
matches a finding.

## 3. Exception inventory and re-verification

**The per-package evidence was re-verified on freshly built images** with
`readelf` and the dpkg file lists: `bbc3cb24` (both images) and the E16-R3
Gateway. It was not copied forward.
- **Script and output:**
  `~/lycenza-release-evidence/e16-2026-11-01/review/` (`reverify.sh`,
  `reverify.txt`, `reverify-r3.txt`).
- **The R3 Gateway output** is identical to the `bbc3cb24` Gateway output.
- **Installed versions** are unchanged from E16-S1: util-linux
  `2.41.5-0+deb13u1`, glibc `2.41-12+deb13u4`, acl `2.3.2-2+b1`, perl
  `5.40.1-6+deb13u1`, ncurses `6.5+20250216-2`, zlib
  `1:1.3.dfsg+really1.3.1-1+b1`, krb5 `1.21.3-5+deb13u1`, SASL
  `2.1.28+dfsg1-9`, libstdc++ `14.2.0-19`.

| # | Advisory | Scanner / gate | Package(s) | Original reason | Still present? | Still justified? | Can close now? | Successor action |
|---|---|---|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | Grype → `evaluate()` | util-linux family (18) | no setuid mount/umount; libmount used only by util-linux tools | yes, `wont-fix` | yes: 0 setuid, runtime hardening re-proven (144/144) | no (no fix) | **CONTINUED** (renewed unchanged) |
| 2 | CVE-2026-78408 | same | util-linux family (18) | `nsenter` never invoked; no capabilities | yes | yes | no | **CONTINUED** |
| 3 | CVE-2026-78409 | same | util-linux family (18) | as #1 | yes | yes | no | **CONTINUED** |
| 4 | CVE-2026-78410 | same | util-linux family (18) | as #1 | yes | yes | no | **CONTINUED** |
| 5 | CVE-2026-19499 | same | libc6, libc-bin (4) | 0 importers of `strfmon` | yes | yes: 0 importers (717 / 754 ELF objects) | no | **CONTINUED** |
| 6 | CVE-2026-5435 | same | libc6, libc-bin (4) | 0 importers of `ns_printrr*` | yes | yes: 0 importers | no | **CONTINUED** |
| 7 | CVE-2026-54369 | same | libacl1 (2) | acl tools absent; consumers never invoked | yes | yes: getfacl/setfacl absent | no | **CONTINUED** |
| 8 | CVE-2026-54370 | same | libacl1 (2) | as #7 | yes | yes | no | **CONTINUED** |
| 9 | CVE-2026-82560 | same | perl-base (2) | `Pod/Text.pm` absent | yes | yes: absent | no | **CONTINUED** |
| 10 | CVE-2026-9538 | same | perl-base (2) | `Archive/Tar.pm` absent | yes | yes: absent | no | **CONTINUED** |
| 11 | CVE-2025-69720 | same | ncurses (7) | `infocmp` only in ncurses-bin, never run | yes | yes | no | **CONTINUED** |
| 12 | CVE-2026-85091 | same | zlib1g (2) | false positive for this version | yes, `not-fixed` | yes: version unchanged | no | **CONTINUED** |
| 13 | CVE-2026-107778 | same | krb5 (4, app) | no GSS acceptor path; libpq initiates only | yes | yes: only libtirpc imports `gss_accept_sec_context`, only `ss` uses libtirpc | no | **CONTINUED** |
| 14 | CVE-2026-107161 | same | SASL (2, app) | DIGEST-MD5 plugin not installed | yes | yes: `libsasl2-modules` absent | no | **CONTINUED** |
| 15 | CVE-2026-102010 | same | libstdc++ family (6) | linked only by APT | yes | yes: only APT links `libstdc++.so.6` | no | **CONTINUED** |
| 16 | CVE-2026-95619 | same | libstdc++ family (6) | as #15 | yes | yes | no | **CONTINUED** |

**Totals:** 115 records (60 app + 55 Gateway).
- **CLOSED:** 0. **REPLACED:** 0. **INVALIDATED:** 0. **CONTINUED:** 16
  advisories / 115 records.
- **Class (brief §6):** every advisory is **C** (still present, understood,
  temporary continuation). None is A (gone), B (fixable) or D (materially
  changed).

**Why none can close.** Grype reports `wont-fix`/`not-fixed`, Debian 13
offers no newer version of any of these packages, and every record is
still matched. Removing a record would make its finding block.

## 4. New findings since E16-S1

| Advisory | Image | Package | Severity | Fix? | Outcome |
|---|---|---|---|---|---|
| **CVE-2026-115557** | ai-gateway | CPython 3.14.8 | **Critical** | yes: 3.14.10 (python.org commits; `python:3.14.10-slim-trixie` published 2026-10-28) | **REMEDIATED, E16-R3** (never excepted, E16-P1) |
| **CVE-2026-84243** | both | glibc `libc6`, `libc-bin` `2.41-12+deb13u4` | High | **no**: Debian unfixed in bookworm, trixie, forky and sid (bug 1152640); upstream GLIBC-SA-2026-0025 | **NEW EXCEPTION** (owner-approved; evidence below) |

### 4.1 E16-R3 — CPython 3.14.10

**The advisory.** CPython's `ssl` module adds a denylist of known
compromised certificates, which are rejected whatever the `SSLContext`
policy.

**The fix.** The same three pins as E16-R1 move to the multi-arch index
digest of `python:3.14.10-slim-trixie`
(`sha256:3cabfd7d7aaf394e6040e79fa1eec6ca1c52b74ed9188650472695d9a9326999`):
- `ai.Dockerfile` (`PYTHON_IMAGE`);
- `verify-images.sh` (TLS smoke server);
- `artifact-policy.json` (`tools.python`).

No lockfile or dependency changes.

**Verification of the remediated Gateway.**
- **Build:** from the candidate commit with the canonical fresh builder.
- **Scan:** pinned Syft 1.33.0 and Grype 0.100.0, on the **same** database
  as the reviewed scan.
- **Result:** Python 3.14.10, **0 Critical**, the same 57 Highs, and the
  same Debian package set.
- **Evaluated against the successor file:** **PASS** (57 excepted, 0
  unused).
- `verify-images.sh` for the new Gateway runs in the candidate's exact-SHA
  `CI gate` (production images lane).

**Local limitation.** The local `qualify build` stops at
`loaded_image_mismatch`. That is a check of the loaded image's ID, and
Docker Desktop's containerd image store reports manifest IDs. The OCI
archive, the build identity, was written first and is what was scanned.
The CI qualification has no such limitation.

### 4.2 CVE-2026-84243 — glibc gettext `LANGUAGE` traversal

The evidence is in the decision record (§2.2). In short:
- the vulnerable code is in `libc.so.6` (`libc6`), and no `libc-bin`
  program imports gettext;
- there are **no `.mo` catalogs** in either image;
- **no `LANGUAGE`** is configured;
- no request or user input reaches the process environment;
- nothing runs setuid, and every role is non-root with `no-new-privileges`.

The notable consumers (`libpq`, `libpython3.14`) run in processes whose
environment is fixed by the deployment.

**Proposed and approved classification:**
- `libc6`: `accepted_risk`;
- `libc-bin`: `not_affected`.

That is the same shape as glibc records #5 and #6.

## 5. Successor exception scope

| Measure | Count |
|---|---|
| Advisories | 17 (16 renewed + 1 new) |
| Records | **119** = 115 renewed + 4 new (app 62, Gateway 57) |
| Statuses | accepted_risk 32, not_affected 85, false_positive 2 |
| Conditions | runtime hardening on #1, #3, #4, #7 and #8 (unchanged) |
| Severity | `high` only; no Critical, no wildcard, no version range |
| Window | created 2026-11-01, expires **2026-11-29** (28 days, under the 30-day policy maximum) |

**What changed:**
- **Renewed records:** only `approved_by`, `created` and `expires`, proven
  field by field against the previous file.
- **The approval:** `record`, `decided` and `reviewed_digests` are the new
  decision's, and CVE-2026-84243 was added.

**Scope was not broadened.** Each record names one exact image, advisory,
package and version. The scope cannot be reduced any further without
removing a record whose finding is still present.

**Evaluator on the reviewed scans with the successor file**, as of
2026-11-01 and as of 2026-11-28:

| Scan | Verdict | Excepted | Unused |
|---|---|---|---|
| app (`3c93bf0f`) | **PASS** | 62 | 0 |
| ai-gateway E16-R3 build | **PASS** | 57 | 0 |
| ai-gateway (`770fa783`, pre-R3) | FAIL: only the Critical, which correctly stays blocked | 57 | 0 |

## 6. Current risk reassessment

**Surfaces added since E16-S1:**
- reporting and governed CSV export (SIS 11.1–11.4);
- Student and Applicant Highly Sensitive documents (SIS.EXP-3, SIS 11.5,
  11.H1);
- certificates (dompdf, SIS.EXP-4);
- Student communities (SIS 11.7–11.8);
- Attendance reporting (SIS 11.10);
- the new capabilities and routes that came with them.

**Effect on the old rationales: none is invalidated.** Every exception
concerns an OS package component that **no role reaches**, and the new
features add no such path:
- no new setuid binary;
- no new process role;
- no subprocess or `exec` of `ss`, APT, `infocmp`, `nsenter`, the acl tools
  or a SASL/GSS acceptor;
- no new C++ consumer;
- no `.mo` catalog;
- no code that writes the environment.

The runtime contract (`verify-images.sh`, 144 checks) passed on the fresh
images.

**Export (SIS 11.4).**
- **Dependencies:** composer, npm and pip audits cover the export path's
  libraries. No advisory is reported against them.
- **Formula injection:** this is application logic (`CsvCell::safe()`),
  guarded by `StudentReportExportTest`. No dependency scanner covers it, and
  none is invented here.
- **Downloads:** streamed with no file written, so no download path exists.

**Documents (SIS.EXP-3, 11.5, 11.H1).**
- **Libraries in the composer audit:** `league/flysystem` 3.35.2 and its
  S3 adapter, `aws/aws-sdk-php` 3.393.4, `league/mime-type-detection`
  1.17.0, `symfony/mime` 7.4.16, `dompdf/dompdf` 3.1.6 (with `php-svg-lib`,
  `php-font-lib`, `masterminds/html5`, `sabberworm/php-css-parser`) and
  `guzzlehttp/*`.
- **Findings, both non-blocking (Low and Medium never block):**
  - **`league/flysystem` CVE-2026-102601 (Low):** control characters in
    `WhitespacePathNormalizer`. Already recorded at ORG.11-R1. Tenant file
    paths are server-built through `TenantStoragePath`, never from a
    user-supplied file name. A patch release (3.35.3) exists. The
    recommendation is to take it with the `symfony/mime` slice.
  - **`symfony/mime` CVE-2026-45070 (Medium):** email header injection via
    non-token characters. Email headers are server-controlled (From is
    `notifications@MAIL_SENDING_DOMAIN` with a sanitized name; no Reply-To,
    Cc or Bcc; fixed subjects). **Owner decision:** fix in its own slice
    after E16; a clean patch update to 7.4.22 is available.
- **OS packages:** no Debian package on the document path (PDF, image, MIME
  or TLS libraries) carries an excepted or blocking finding.

**Assessment.** No old rationale is weakened. The new glibc advisory is the
only new no-fix High.

## 7. Dependency, secret and other gates (bbc3cb24)

| Gate | Result |
|---|---|
| **Frontend (npm audit)** | 16 Moderate, 0 High/Critical. All are in the **ESLint dev toolchain** (`minimatch` GHSA-g3hv-75wg-m249 via eslint and typescript-eslint; `postcss-selector-parser` GHSA-rj75-hqrm-r3gf via eslint-plugin-vue). None is in the built assets. npm's "fix" is a semver-major downgrade, so no usable fix. Non-blocking; no exception applies |
| **Backend (composer audit)** | `league/flysystem` 3.35.2 Low CVE-2026-102601 (transitive: required by `laravel/framework` and the S3/local adapters; 3.35.3 and 3.36.0 exist); `symfony/mime` 7.4.16 Medium CVE-2026-45070 (transitive: `laravel/framework`, `symfony/mailer`; fix 7.4.22). Both non-blocking (Low and Medium never block); no exception applies. **Recommended:** carry both patch updates in the owner-deferred post-E16 dependency slice |
| **Gateway (pip-audit)** | 26 locked dependencies, 0 advisories |
| **Secrets** | gitleaks 8.28.0: 0 in the source tree (qualification) and 0 in either image filesystem or history. The full-history `Secrets scan` CI job is green on `bbc3cb24`. No allowlist was added |
| **Supply chain** | lockfiles in sync (`composer validate --strict`, `npm ci --dry-run`, `pip-compile` byte-for-byte); every tool and base image pinned by digest |
| **Runtime hardening** | `verify-images.sh` 144/144 |

## 8. Hard-stop enforcement (verified from code)

| Mechanism | Behaviour at expiry | Evidence |
|---|---|---|
| The exception file | `validate_exceptions()` treats `expires <= today` as **expired, and the whole file invalid**. It also refuses a record created in the future, a window over the approval's `max_days` or the policy maximum, wildcards, ranges, duplicates, an unapproved reference, a missing decision record, malformed dates and any Critical | `infrastructure/release/lycenza_release/exceptions.py` |
| CI `Supply chain` job | `qualify audit --fail-on-blocking` loads the file with today's UTC date. An invalid file fails the job, so the `CI gate` is red for every candidate | `qualify.py`; `.github/workflows/ci.yml` |
| `publication-check --push` | Refuses when `now.date() >= earliest expiry`, or when the gate run completed on or after it | `infrastructure/ci/lycenza_ci/publication.py` |
| Release qualification | Fails the exceptions check on an invalid file | `verify-artifact` |

**Deterministic proof (fixed dates, no real clock):**
- **The live file, through `validate_exceptions()`:**
  - 2026-10-31: invalid (created in the future);
  - 2026-11-01, **2026-11-13** and 2026-11-28: 119 valid;
  - 2026-11-29 and 2026-12-01: invalid (expired).
- **Pinned in tests:**
  - `test_the_activated_records_expire_and_then_fail_every_verification`;
  - `test_the_repository_decision_boundary_is_exclusive`
    (`publication-check`: the last valid day passes, the expiry day refuses);
  - the fixture cases `expired`, `too-long-high`, `bad-date`,
    `wildcard-id`, `wildcard-package`, `version-range`, `unapproved`,
    `empty-approval`, `future-created` and `duplicate` (no open-ended
    waiver).

**Dates:**

| | Date |
|---|---|
| Previous last valid day | 2026-11-12 |
| Previous hard stop | 2026-11-13 |
| **New expiry** | **2026-11-29** |
| **New last valid day** | **2026-11-28** |
| **New hard stop** | **2026-11-29** |
| Next successor review should start | about 2026-11-20 |

## 9. Separation

This review does not touch:
- staging, AWS, Terraform or production;
- the product;
- any SIS, HR, Finance, Transport or Inventory feature.

The `symfony/mime` fix is a separate, owner-deferred slice.

## 10. Post-publication qualification (recorded 2026-11-01, with E16-R4)

After `af1ca5fa` (E16-R3 + E16-S2) reached `main`, *Release qualification*
run **41296953625** qualified it:
- **app** `sha256:a94907f19d54b8f70538d9dac8de8a52d039e06b83f4d1d014324183274ccc4f`:
  **VERIFIED**, 62 excepted, 0 blocking;
- **ai-gateway** `sha256:e748dfdae3498f1340eff86e330443164c2d19f7af4a29f2f0705e2fc372cc03`:
  **VERIFIED**, 57 excepted, 0 blocking, 0 Critical;
- same-run regression passed; `verify-images.sh` exit 0; gitleaks 0 in the
  source and in each image.

This is the E16-S2 publication's own evidence. It was written down in the
later E16-R4 change ([record](E16-R4-DEPENDENCY-REMEDIATION-2026-11-01.md)
§8), because recording it at the time would have needed a further
publication.
