# Owner security decision OWNER-0O-E16-2026-09-29 — residual HIGH findings after a fresh scan

**Reference:** `OWNER-0O-E16-2026-09-29`
**Decided:** 2026-09-29, by owner/security (the project owner acting as the
security decision-maker), after the E16 fresh-scan audit.
**Recorded and activated:** Phase 0O / E16, 2026-09-29.
**Machine-readable form:** `approvals.OWNER-0O-E16-2026-09-29` in
[`infrastructure/release/vulnerability-exceptions.json`](../../../infrastructure/release/vulnerability-exceptions.json).
Every active record names this reference in `approved_by`.

**This is a new decision.**
- It follows a fresh scan and is **not** an extension or renewal of
  `OWNER-0O6E-2026-09-26`.
- That decision
  ([`0O.6E-owner-security-decision.md`](0O.6E-owner-security-decision.md))
  stays historical truth for its dates. Its records were active from
  2026-09-26 until this replacement, and past qualifications (for example
  `c4b1b6c`, `e52c4c4`) cite it.
- Its approval is removed from the active `approvals` block, so no new
  record can be created under it.

## 1. Evidence reviewed

- **Qualified executable source:**
  `e52c4c4c0be88f9b3cbba2684fce78c667727218`
  (run `local-20260929T002558Z-08871c71`, `PHASE-0O-READINESS.md` §44).
- **Reviewed artifacts (qualified manifests):**
  - application `sha256:d03a3e4dd3400f89ea4ed98f94cfe27ecd2b937000dae001e47adfcd80159729`
  - Gateway `sha256:db5ae74d5a227fd1432d288cab525e3b8a427995207242672e5386de3524e502`
- **Fresh scan:** 2026-09-29, with `qualify rescan` of those artifacts'
  SBOMs. Pinned Grype v0.100.0 used the latest database available at scan
  time, built 2026-09-28 06:42Z (within the 24 h scanner-age policy).
- **Residual set:**
  - 12 advisories and **97** High findings (48 application + 49 Gateway),
    each matching a record exactly (image, advisory, package, version,
    severity);
  - **0 Critical**;
  - **0 High with an available fix**.
- **Language audits:** Composer, npm and pip-audit found 0 advisories.
- **Fix availability (Debian):**
  - no newer version of any affected package exists in `trixie`,
    `trixie-updates`, `trixie-security` or `trixie-proposed-updates`, and
    the pinned base has 0 upgradable packages;
  - the security tracker marks the trixie entries `<no-dsa>` (minor issue)
    or `<postponed>`, or unfixed in every release;
  - no newer `debian:13-slim` or `python:3.14-slim-trixie` digest exists;
  - `sid` is not a legitimate source for trixie production images.
- **Conclusion:** no legitimate refresh removes any finding. **Nothing is
  remediable today.**
- **Recheck at activation (2026-09-29).** A newer Grype database was
  published (built 2026-09-29 06:32Z) and was re-run over the same SBOMs:
  - the governed High/Critical set is **identical** (the same 12 advisories,
    97 findings, versions, severities and fix states);
  - the only change is one new **Low** advisory, **CVE-2026-97399** (glibc,
    `libc6` and `libc-bin` 2.41-12+deb13u4, no fix), in both images;
  - owner/security confirmed that this Low-only change is within this
    decision. Low findings are recorded and never blocking (the
    policy evaluator), and no exception covers them.

## 2. Decisions

| # | Advisory | Family | Decision | Approved to | Condition |
|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | util-linux (mount helper exit status / post-mount hooks) | accepted_risk | 2026-10-29 | runtime hardening |
| 2 | CVE-2026-78408 | util-linux (nsenter `--join-cgroup`) | accepted_risk | 2026-10-29 | — |
| 3 | CVE-2026-78409 | util-linux (libmount `X-mount.subdir`) | accepted_risk | 2026-10-29 | runtime hardening |
| 4 | CVE-2026-78410 | util-linux (restricted fstab bind mounts) | accepted_risk | 2026-10-29 | runtime hardening |
| 5 | CVE-2026-19499 | glibc (`strfmon`/`strfmon_l`) | accepted_risk | 2026-10-29 | — |
| 6 | CVE-2026-5435 | glibc (`ns_printrr*`/`fp_nquery`) | accepted_risk | 2026-10-29 | — |
| 7 | CVE-2026-54369 | acl (libacl pathname functions, symlink traversal) | accepted_risk | 2026-10-29 | runtime hardening |
| 8 | CVE-2026-54370 | acl (TOCTOU privilege escalation) | accepted_risk | 2026-10-29 | runtime hardening |
| 9 | CVE-2026-82560 | perl (Pod::Text) | not_affected | 2026-10-29 | — |
| 10 | CVE-2026-9538 | perl (Archive::Tar) | not_affected | 2026-10-29 | — |
| 11 | CVE-2025-69720 | ncurses (`infocmp`) | accepted_risk | 2026-10-29 | — |
| 12 | CVE-2026-85091 | zlib (`gz_vacate`) | false_positive | 2026-10-29 | — |

**No other advisory is approved.**

### Exact per-package status (unchanged from the fresh audit)

| Advisory | `accepted_risk` | `not_affected` / `false_positive` |
|---|---|---|
| CVE-2026-76642, CVE-2026-78409, CVE-2026-78410 | `mount`, `libmount1` | `util-linux`, `bsdutils`, `login`, `libblkid1`, `liblastlog2-2`, `libsmartcols1`, `libuuid1` (not_affected) |
| CVE-2026-78408 | `util-linux` (ships `nsenter`) | `mount`, `libmount1`, `bsdutils`, `login`, `libblkid1`, `liblastlog2-2`, `libsmartcols1`, `libuuid1` (not_affected) |
| CVE-2026-19499 | `libc6` | `libc-bin` (not_affected) |
| CVE-2026-5435 | `libc6` | `libc-bin` (not_affected) |
| CVE-2026-54369, CVE-2026-54370 | `libacl1` | the acl tool packages are not installed |
| CVE-2026-82560, CVE-2026-9538 | — | `perl-base` (not_affected: the vulnerable modules are absent) |
| CVE-2025-69720 | `ncurses-bin` (ships `infocmp`) | `libtinfo6`, `ncurses-base`, and in the Gateway `libncursesw6` (not_affected) |
| CVE-2026-85091 | — | `zlib1g` (false_positive: `gz_vacate` is absent from zlib 1.3.1) |

The same statuses apply to both images, where each package is present.

**CVE-2026-5435 differs between the images:**
- **Application:** `libresolv` (part of `libc6`) is loaded indirectly
  through PHP's DNS functions. No ELF object imports
  `ns_printrr*`/`fp_nquery`, and nothing calls them.
- **Gateway:** `libresolv` is not loaded at all.
- **Both:** `accepted_risk` on `libc6`, because the code is present.

**Exact package versions** (Debian 13, verified against the fresh scan):
- util-linux family: `2.41.5-0+deb13u1`; `bsdutils`: `1:2.41.5-0+deb13u1`;
  `login`: `1:4.16.0-2+really2.41.5-0+deb13u1`;
- `libc6` / `libc-bin`: `2.41-12+deb13u4`;
- `libacl1`: `2.3.2-2+b1`;
- `perl-base`: `5.40.1-6+deb13u1`;
- ncurses packages: `6.5+20250216-2`;
- `zlib1g`: `1:1.3.dfsg+really1.3.1-1+b1`.

**Records:** 97 in total (48 application, 49 Gateway, which also has
`libncursesw6`):
- 24 `accepted_risk`;
- 71 `not_affected`;
- 2 `false_positive`.

## 3. Conditions: runtime hardening (#1, #3, #4, #7, #8)

These approvals depend on the production containers **not** being able to
mount filesystems or escalate privilege. Required for **both** images, in
every deployment:
1. **Not privileged:** `privileged` = false.
2. **Capabilities:** **all dropped**, none added.
3. **No new privileges:** `no-new-privileges` = true.
4. **User:** the existing non-root user (`www-data`/uid 33 for the app,
   `gateway`/uid 10001 for the Gateway).
5. **Setuid:** `mount`/`umount` carry **no setuid bit** (Debian
   `dpkg-statoverride`).
6. **fstab:** no user-mountable `/etc/fstab` entry (`user`, `users`,
   `owner`, `group`, `bind`, `X-mount.*`).
7. **Root processes:** no persistent privileged (root) process in any role:
   web, PHP-FPM, nginx, the workers, the scheduler, or the Gateway.

**How it is enforced:**
- **Contract:** encoded in
  [`infrastructure/release/runtime-security.json`](../../../infrastructure/release/runtime-security.json).
- **Proof:** `infrastructure/docker/production/verify-images.sh` checks
  every process of every role from `/proc/<pid>/status`.
- **Gate:** `verify-artifact` applies a `runtime-hardening` record only when
  the signed `verify-images.json` for **that artifact** shows every
  contract-required check passed. Otherwise the exception is withdrawn and
  verification FAILs.
- **Deployment:** evidence that a real platform applies the contract is
  still outstanding. Deploying without it breaks this approval's condition.

## 4. Window

- **Created:** 2026-09-29.
- **Expires:** **2026-10-29** for every record (one common date, 30 days:
  the policy maximum for High).
- **Last passing day:** 2026-10-28. The evaluator treats
  `expires <= today` as expired, and **one expired record invalidates the
  whole exception file**, so every verification FAILs from 2026-10-29
  unless a new decision replaces the records first.
- **No automatic renewal.**

## 5. Reviewed digests and successor digests

- **Reviewed:** the qualified `e52c4c4` manifests in §1.
- **Successor rule.** Every build gets new digests (build time and commit
  are image labels). A successor artifact may use this decision only when
  **all** of these hold:
  1. it was built from a `main` commit that carries this decision's
     records and the runtime security contract;
  2. its scan, with a fresh database within the scanner-age policy, has
     **exactly** this residual set: the same 12 advisories, the same
     (image, package, version) pairs, severity `high`, and no fix
     available;
  3. its signed `verify-images.json` proves the runtime hardening;
  4. `verify-artifact` returns **VERIFIED**.

## 6. Automatic invalidation

Coverage of a finding **ends automatically**, and it blocks and returns for
a new review, when:
- a fix becomes available (the evaluator blocks it as `high_fix_available`;
  an exception never covers a fixed High);
- a package version changes (the exact record no longer matches);
- a severity changes;
- the residual set changes in any way (a new advisory, package or
  version);
- a new, ungoverned High appears, or any Critical;
- a Debian 13 point or security release changes an affected package;
- the runtime-hardening conditions fail or `verify-images` fails (the
  conditional records are withdrawn);
- a successor artifact does not reproduce the reviewed residual set.

Medium, Low and Negligible findings are recorded, never blocking, and never
covered by an exception. They do not change the residual set defined in §1.

**No manual override exists.** No "still approved" statement, and no edit
of dates, versions or statuses outside a new recorded decision, can bypass
these rules.

## 7. Not approved

- Any advisory not listed in §2, any Critical, any High with a fix.
- Any package not listed in §2 for its image.
- Publication, promotion, registry push, production signing or deployment:
  **PUBLISHED = NONE, PROMOTED = NONE**.
- Final E02 (ADR 0058) is not satisfied by this decision. The final
  qualification needs every exception valid **on its own date**.

## 8. Activation and qualified successor (2026-09-29)

**Activation.** The records went live on `main` at
`be69b55cef66e98d18bd728851b9a104c52a0580`.

**Qualification.** Run `local-20260929T130419Z-6a83ecf8`, with a fresh
Grype database built 2026-09-29 06:32Z. It is recorded in
`PHASE-0O-READINESS.md` §48, and it meets all four §5 successor
conditions:
1. **Source.** Built from the `main` commit that carries these records and
   the runtime security contract.
2. **Exact residual set.** The scanned High/Critical set equals the 97
   records exactly: 0 Critical, 0 High with a fix. The only difference from
   the decision scan is the in-scope Low CVE-2026-97399 (§1).
3. **Runtime hardening proven.** The signed `verify-images.json` shows
   133/133 checks, and both images report `exception_conditions_proven`.
4. **VERIFIED:**

   | Image | Manifest digest | Result |
   |---|---|---|
   | Application | `sha256:39c376fa7eb3e3961ee5d93e95d11d25424e5e8a3de740b23f8d2c3e5b0b35ae` | 0 blocking, 48 excepted |
   | Gateway | `sha256:931a70cb71873c5af4a3d088adef8d66b408b385cf2e112cf11fb765f8ab275b` | 0 blocking, 49 excepted |

**PUBLISHED = NONE, PROMOTED = NONE.**
