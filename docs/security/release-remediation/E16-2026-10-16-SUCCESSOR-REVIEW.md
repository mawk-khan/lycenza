# E16 successor review — 2026-10-16

**Status: DECIDED 2026-10-16.**
- **E16-S1:** renewal approved as proposed, as `OWNER-0O-E16-2026-10-16`,
  expiring 2026-11-13. See
  [`0O-E16-2026-10-16-owner-security-decision.md`](0O-E16-2026-10-16-owner-security-decision.md).
- **nginx:** route **E16-R2-a** approved; R2-b and R2-c rejected.

The text below is the review as it stood before the decision.

**Status before the decision: DECISION REQUIRED (owner).**

This is a review and a proposal. **No exception was created, renewed or
changed.** `infrastructure/release/vulnerability-exceptions.json` is
untouched. Its 115 records of `OWNER-0O-E16-2026-10-11` remain the only
active approval, and they expire on **2026-11-10**.

**Nothing below is approved until the owner decides (§8).**

## 1. Baseline

**Repository**
- `origin/main` = local `main` = HEAD = `78b68d6d004972adfec75e5000d55455a762fac8`
  (UAT-C4).
- The tree was clean, with one worktree. The historical stash is
  untouched.
- The latest `main` CI was green: run 38883707779.

**Current decision**
- `OWNER-0O-E16-2026-10-11` ([record](0O-E16-2026-10-11-owner-security-decision.md)):
  115 records, 16 advisories, all `high`, all created 2026-10-11 and
  expiring 2026-11-10.
- E16-P1 (a Critical is never exception-eligible) is in force.

**Policy and tools**
- `artifact-policy.json` and the exception semantics are unchanged:
  - Critical always blocks;
  - High blocks when a fix exists, and otherwise needs an exception;
  - High exceptions last at most 30 days;
  - unrated language advisories count as High.
- Tools: Syft 1.33.0 and Grype 0.100.0. The Grype database was built
  2026-10-16T06:29:51Z (3.7 h old at scan time).

## 2. Fresh scan

**Run.** `local-20261016T095744Z-53be01e2`, started 2026-10-16T09:57:44Z
on protected `main` (`on_protected_main_at_start: true`). It ran the
canonical stages `init`, `inputs`, `source-scan`, `audit`, `build` (fresh
builder, no cache), `verify-images` and `scan`.

**Artifacts**

| Image | Digest | SBOM sha256 | Grype report sha256 |
|---|---|---|---|
| app | `sha256:344c9fc1ab165064fe5522b3c24ddc83ef3491609c66cce8adcff2f2577d8087` | `eb58ae26…5d9e9d` | `786c0bf7…ab456e` |
| ai-gateway | `sha256:db4d88303c8e3787c5039975a26f9857af35ad501c1ed84b35c01b3127d59b17` | `a3b3f548…2e57` | `349baea1…f54a` |

**Results**

| Check | Result |
|---|---|
| Lockfiles (composer, npm, Python) | in sync |
| Source secret scan | 0 |
| Image secret scans | 0 |
| `verify-images.sh` | 134/134, exit 0 |
| Language audits | 3 advisories, **0 blocking**: laravel/framework Low (CVE-2026-102279), league/flysystem Low (CVE-2026-102601), postcss-selector-parser Moderate — the same three as at D1 |

**Grype matches:** 376 (app 215, Gateway 161).
- Critical: app 6.
- High: app 62, Gateway 55.
- The remaining 253 are Medium, Low or Negligible (recorded, never
  blocking).

**Evaluator** (the repository's own `evaluate()` against the current
exception file):
- verdict **FAIL**;
- 115 excepted, 0 unused records;
- **8 blocking**, all in the app image:
  - 6 Critical: 3 advisories × the `nginx` and `nginx-common` packages;
  - 2 High-requires-exception: 1 advisory × those two packages.

**Evidence** (outside the repository):
- the scan: `~/lycenza-release-evidence/e16-2026-10-16/scan/`;
- the evaluation, the effective `nginx -T` and the re-verification script
  and output: `~/lycenza-release-evidence/e16-2026-10-16/review/`.

## 3. Reconciliation of the 115 existing records

All 16 advisories are still present, unchanged:
- **Matches:** the same image, package, exact version and severity; every
  record still matches a finding (0 unused).
- **Fixes:** Grype reports `wont-fix` for 15 advisories and `not-fixed` for zlib (#12).
- **Debian:** the apt metadata of the built app image was refreshed against
  trixie, trixie-updates and trixie-security. The only upgradable package
  is `liblzma5` (deb13u1 → deb13u2). It has no Grype match and is not a
  governed finding.
- **Package inputs:** the base images and installed package sets are
  identical to E16-R1. The `FROM` and `apt-get install` lines of both
  Dockerfiles are unchanged since `ab8e1ae4`.

**The per-package evidence was re-verified on the fresh images** with
`readelf` and the dpkg file lists. It was not copied forward:

| # | Advisory | Family | Records | Re-verified evidence (2026-10-16) | Class |
|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | util-linux | 18 | `mount`/`umount` carry no setuid bit; libmount is used only by util-linux tools | D (no fix) |
| 2 | CVE-2026-78408 | util-linux (`nsenter`) | 18 | `nsenter` is present; the entrypoint never invokes it; non-root, no capabilities | D |
| 3 | CVE-2026-78409 | util-linux (libmount) | 18 | as #1 | D |
| 4 | CVE-2026-78410 | util-linux (fstab bind) | 18 | as #1 | D |
| 5 | CVE-2026-19499 | glibc `strfmon` | 4 | 0 ELF objects (of 717 / 754) import `strfmon`/`strfmon_l` | D |
| 6 | CVE-2026-5435 | glibc `ns_printrr*` | 4 | 0 ELF objects import `ns_printrr`/`ns_printrrf`/`fp_nquery` | D |
| 7 | CVE-2026-54369 | acl | 2 | acl tools absent; libacl is used only by cp/mv/install/tar/sed/useradd/usermod, which the `lycenza` entrypoint never invokes | D |
| 8 | CVE-2026-54370 | acl | 2 | as #7 | D |
| 9 | CVE-2026-82560 | perl `Pod::Text` | 2 | `Pod/Text.pm` absent | D |
| 10 | CVE-2026-9538 | perl `Archive::Tar` | 2 | `Archive/Tar.pm` absent | D |
| 11 | CVE-2025-69720 | ncurses `infocmp` | 7 | `infocmp` is owned only by `ncurses-bin` | D |
| 12 | CVE-2026-85091 | zlib | 2 | version unchanged | D |
| 13 | CVE-2026-107778 | krb5 (app) | 4 | `gss_accept_sec_context` is imported only by libtirpc, whose only consumer is `/usr/bin/ss`; libpq initiates only | D |
| 14 | CVE-2026-107161 | Cyrus SASL (app) | 2 | `libsasl2-modules` is not installed; no `libdigestmd5` exists | D |
| 15 | CVE-2026-102010 | libstdc++ | 6 | `libstdc++.so.6` is linked only by APT, in both images | D |
| 16 | CVE-2026-95619 | libstdc++ | 6 | as #15 | D |

**Totals:** 115 records (60 app + 55 Gateway).

**Classes:** none is A (gone), B (fix available), E (materially changed) or
F (duplicate). All 16 are **D — no fix available**, with the mitigation
evidence re-verified.

## 4. New findings: Debian `nginx` 1.26.3-3+deb13u9 (app image)

| Advisory | Scanner severity | Upstream (nginx.org) | Debian fix | Trigger condition in our config (`nginx -T`) |
|---|---|---|---|---|
| CVE-2026-15176 | **Critical** | fixed in 1.30.6 / 1.31.7 | none (`not-fixed`) | `alias` + `rewrite` + `$request_filename` — **absent** (no `alias`, no `rewrite`) |
| CVE-2026-93479 | **Critical** | fixed in 1.30.6 / 1.31.7; upstream range 1.30.0–1.30.5 and 1.31.2–1.31.6 (1.26.x not listed) | none | a regex `map` whose **capture** is referenced in `root` or the log format before the map output — we have a regex `map` with a named capture, but the log format references only the map **output** |
| CVE-2026-97205 | **Critical** | fixed in 1.30.6 / 1.31.7 | none | AIO + SSI — **absent** (no `aio`, no `ssi`) |
| CVE-2026-97629 | High | fixed in 1.30.6 / 1.31.7 | none | `ssl_session_cache shared:` without ticket keys — **absent** (no TLS in the container; plain `listen 8080`) |

**Notes**
- **Sources.** Upstream data is from the nginx.org security advisories
  page, read 2026-10-16. The Debian candidate was read from the refreshed
  apt metadata: `1.26.3-3+deb13u9` is the newest in trixie-security.
- **Severity.** The repository's mapping takes the scanner's severity
  (Critical). Upstream rates these Medium. This review does **not**
  reinterpret severity.
- **Reachability.** The configuration evidence shows the documented
  trigger conditions are not present. That **does not** make a Critical
  exception-eligible:
  - **E16-P1:** a Critical is never exception-eligible, in any status,
    including `false_positive` or `not_affected`.
- **Effect.** The app image cannot be **VERIFIED** until nginx is
  remediated, or until the advisory data changes.
- **What it does not affect.** Code publication: the CI gate does not scan
  images. Nor the Gateway image.

## 5. Successor decision register

| ID | Advisory | Component | Version | Severity | Fix? | Existing exception? | Proposed outcome | Reason | Expiry / trigger |
|---|---|---|---|---|---|---|---|---|---|
| 1–4 | CVE-2026-76642, -78408, -78409, -78410 | util-linux (both) | 2.41.5-0+deb13u1 (and epoch variants) | High | no | yes (72) | **RENEW EXCEPTION** | §3; runtime hardening re-proven (134/134) | D+28; re-review on any version, fix or severity change |
| 5–6 | CVE-2026-19499, -5435 | glibc (both) | 2.41-12+deb13u4 | High | no | yes (8) | **RENEW EXCEPTION** | §3, no importer | D+28; same triggers |
| 7–8 | CVE-2026-54369, -54370 | acl (both) | 2.3.2-2+b1 | High | no | yes (4) | **RENEW EXCEPTION** | §3 | D+28; same triggers |
| 9–10 | CVE-2026-82560, -9538 | perl (both) | 5.40.1-6+deb13u1 | High | no | yes (4) | **RENEW EXCEPTION** (`not_affected`) | modules absent | D+28; same triggers |
| 11 | CVE-2025-69720 | ncurses (both) | 6.5+20250216-2 | High | no | yes (7) | **RENEW EXCEPTION** | §3 | D+28; same triggers |
| 12 | CVE-2026-85091 | zlib (both) | 1:1.3.dfsg+really1.3.1-1+b1 | High | no | yes (2) | **RENEW EXCEPTION** (`false_positive`) | version unchanged | D+28; same triggers |
| 13 | CVE-2026-107778 | krb5 (app) | 1.21.3-5+deb13u1 | High | no | yes (4) | **RENEW EXCEPTION** | §3, no acceptor path | D+28; same triggers |
| 14 | CVE-2026-107161 | Cyrus SASL (app) | 2.1.28+dfsg1-9 | High | no | yes (2) | **RENEW EXCEPTION** (`not_affected`) | plugin absent | D+28; same triggers |
| 15–16 | CVE-2026-102010, -95619 | libstdc++ (both) | 14.2.0-19 | High | no | yes (12) | **RENEW EXCEPTION** | APT-only linkage | D+28; same triggers |
| 17 | CVE-2026-15176 | nginx (app) | 1.26.3-3+deb13u9 | Critical | upstream only | no | **REJECT EXCEPTION / BLOCK** | E16-P1 | remediate (§6) |
| 18 | CVE-2026-93479 | nginx (app) | 1.26.3-3+deb13u9 | Critical | upstream only | no | **REJECT EXCEPTION / BLOCK** | E16-P1 | remediate (§6) |
| 19 | CVE-2026-97205 | nginx (app) | 1.26.3-3+deb13u9 | Critical | upstream only | no | **REJECT EXCEPTION / BLOCK** | E16-P1 | remediate (§6) |
| 20 | CVE-2026-97629 | nginx (app) | 1.26.3-3+deb13u9 | High | upstream only | no | **REMEDIATE NOW** (with 17–19) | the same upgrade fixes it; no exception proposed | remediate (§6) |

**D** = the owner's decision date. The proposed window is **28 days**:
shorter than the 30-day policy maximum, aligned to a 4-week cadence and to
Debian point and security releases.

**Totals**

| Measure | Count |
|---|---|
| Findings scanned | 376 image matches + 3 language advisories |
| High/Critical findings | 123 |
| Previous exceptions | 115 (16 advisories) |
| Exceptions no longer needed | 0 |
| Proposed renewed exceptions | 115 (16 advisories, unchanged per-package statuses) |
| Blockers | 3 Critical advisories (6 findings) |
| Remediation slices required | 1 (E16-R2, nginx) |
| Unresolved owner decisions | 2 (§8) |

## 6. Remediation slice E16-R2 (proposed, not started)

**Scope.** Remove the 3 Critical and 1 High nginx findings from the app
image. Debian has no fixed package. Upstream fixed all four in **1.30.6**
(stable) and 1.31.7 (mainline). The choice of route is an owner decision:

- **R2-a — nginx.org stable packages (recommended).**
  - **What:** install `nginx` 1.30.6 (or newer stable) from the official
    nginx.org Debian trixie repository.
    - The signing key is pinned by its exact fingerprint in the
      Dockerfile.
    - The package version is pinned.
    - Debian `nginx` and `nginx-common` are removed.
  - **Lockfiles:** unchanged. **The image changes:** a new apt source, a
    new nginx binary, and possibly paths and modules.
  - **Supply chain:** this adds a third-party apt repository. It needs an
    ADR 0052 amendment naming the source, key and pinning rule.
  - **Testing:**
    - `verify-images.sh` (with the nginx config, runtime contract and
      health checks);
    - a fresh scan (0 Critical, 0 fixable High);
    - the full exact-SHA regression;
    - independent publication.
  - **Risk:** low to medium. The config is small: two servers, FastCGI, no
    TLS, no SSI, no AIO.
- **R2-b — wait for a Debian security update** (outcome **NEEDS EXTERNAL
  VENDOR/UPSTREAM INFORMATION**).
  - Debian usually backports nginx fixes, but the date is unknown.
  - Until then, **no app image can be VERIFIED**. Code publication is
    unaffected.
- **R2-c — build nginx from the signed upstream source tarball.** Not
  recommended: larger build and maintenance surface.

**Precedent:** E16-R1 — a targeted change, then the full regression, then
independent publication.

## 7. Publication hard stop (verified from code)

| Mechanism | Behaviour at expiry | Evidence |
|---|---|---|
| The exception file | `validate_exceptions()` treats `expires <= today` as **expired, and the whole file invalid** | `infrastructure/release/lycenza_release/exceptions.py` |
| CI `Supply chain` job | `qualify audit --fail-on-blocking` loads the file with today's UTC date. An invalid file fails the job, so the `CI gate` is red for every candidate | `qualify.py` (`load_exceptions(policy, utc_now().date())`); `.github/workflows/ci.yml` (`supply-chain`, required by `gate`) |
| `publication-check --push` | Refuses when `now.date() >= earliest expiry`, or when the gate run completed on or after it | `infrastructure/ci/lycenza_ci/publication.py` (`e16_last_valid_day` = expiry − 1 day) |
| Release qualification | Fails the exceptions check on an invalid file | `verify-artifact` |
| Local publish gate | Does not read exceptions; not a control | `.claude/skills/publish-gate/run.sh` |

**Dates (UTC)**
- **Last valid day:** 2026-11-09.
- **Hard stop:** from **2026-11-10**, every candidate is unpublishable until a
  new decision is committed and a new `CI gate` passes on it.

**Separate from the hard stop.** The nginx Criticals block **VERIFIED app
images now**. That is independent of the exception expiry.

## 8. Owner decisions required

1. **Renew the 16 no-fix advisories (115 records)** as a new decision.
   - **Proposed reference:** `OWNER-0O-E16-<decision-date>`.
   - **Window:** decision date to decision date + 28 days.
   - **Statuses and conditions:** unchanged per package. The runtime
     hardening condition stays on #1, #3, #4, #7 and #8.
   - **Approval binding:** the approval would cite the reviewed digests
     above.
   - **Deadline:** approve **on or before 2026-11-09**, with the commit and
     a green `CI gate` by then. Target: 2026-10-30.
   - **Answer:** approve, approve with changes, or reject.
2. **Choose the nginx route:** R2-a (nginx.org stable 1.30.6, with an ADR
   0052 amendment), R2-b (wait for Debian), or R2-c.
   - The Criticals cannot be excepted under any choice.

No approval is claimed here. The approving authority is the project owner
acting as the security decision-maker, as for the previous E16 decisions.

## 9. Testing done for this review

- **Scan:** the fresh qualification-stage scan (§2).
- **Evaluation:** the repository evaluator over the fresh reports (§2).
- **Evidence:** re-verified on the fresh images (§3).
- **Release-tooling and CI-tooling unit tests:** see the completion report.
- **Diff:** documentation only.

## 10. Separation

This review does not touch:
- ESI-12;
- E21, E30–E35 and E37–E47;
- V11 analytics privacy;
- UAT-C5;
- PPR;
- the product.
