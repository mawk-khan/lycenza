# Owner security decision OWNER-0O-E16-2026-10-16 — renewal of the reviewed no-fix HIGH findings

**Reference:** `OWNER-0O-E16-2026-10-16`

**Decided:** 2026-10-16, by owner/security: the project owner acting as the
security decision-maker, as for `OWNER-0O-E16-2026-10-11`.
- **Decision E16-S1:** renew the 16 reviewed advisories (115 records) for
  28 days.
- **The same instruction also decided:**
  - **no exception** for the three Critical nginx advisories;
  - remediation route **E16-R2-a** (nginx.org stable 1.30.6), with R2-b
    and R2-c **rejected**.

No external DPO, legal or security-counsel approval is claimed.

**Recorded and activated:** Phase 0O / E16 successor, 2026-10-16.

**Machine-readable form:** `approvals.OWNER-0O-E16-2026-10-16` in
[`infrastructure/release/vulnerability-exceptions.json`](../../../infrastructure/release/vulnerability-exceptions.json).
Every active record names this reference in `approved_by`.

**This is a new decision.** It follows the fresh-scan successor review
[`E16-2026-10-16-SUCCESSOR-REVIEW.md`](E16-2026-10-16-SUCCESSOR-REVIEW.md)
and is **not** an extension of `OWNER-0O-E16-2026-10-11`:
- **That decision**
  ([`0O-E16-2026-10-11-owner-security-decision.md`](0O-E16-2026-10-11-owner-security-decision.md))
  stays historical truth for its own dates.
- **This decision supersedes it for current qualification.** Its approval is
  removed from the active `approvals` block.

## 1. Evidence reviewed

- **Reviewed source:** `78b68d6d004972adfec75e5000d55455a762fac8`, which
  was `main` at the review.
- **Fresh scan:** run `local-20261016T095744Z-53be01e2`, with the canonical
  stages `init`, `inputs`, `source-scan`, `audit`, `build` (fresh builder,
  no cache), `verify-images` (134/134) and `scan`.
- **Tools:** Syft 1.33.0 and Grype 0.100.0. The database was built
  2026-10-16T06:29:51Z.
- **Reviewed artifacts:**
  - application `sha256:344c9fc1ab165064fe5522b3c24ddc83ef3491609c66cce8adcff2f2577d8087`;
  - Gateway `sha256:db4d88303c8e3787c5039975a26f9857af35ad501c1ed84b35c01b3127d59b17`.
- **Finding the renewal covers.** Every one of the 115 records still matches
  a finding:
  - the same image, advisory, package, exact version and severity (`high`);
  - Grype reports `wont-fix` or `not-fixed` for each;
  - refreshed Debian 13 apt metadata offers no newer version of any of the
    packages.
- **Evidence re-verified.** The per-package evidence of
  `OWNER-0O-E16-2026-10-11` §2.1–§2.2 was re-checked on the fresh images
  with `readelf` and the dpkg file lists. It was **not** copied forward. The
  results are in the successor review §3.

## 2. Decisions

| # | Advisory | Family | Decision | Approved to | Condition |
|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | util-linux | accepted_risk / not_affected per package (unchanged) | 2026-11-13 | runtime hardening |
| 2 | CVE-2026-78408 | util-linux (`nsenter`) | unchanged | 2026-11-13 | — |
| 3 | CVE-2026-78409 | util-linux (libmount) | unchanged | 2026-11-13 | runtime hardening |
| 4 | CVE-2026-78410 | util-linux (fstab bind) | unchanged | 2026-11-13 | runtime hardening |
| 5 | CVE-2026-19499 | glibc (`strfmon`) | unchanged | 2026-11-13 | — |
| 6 | CVE-2026-5435 | glibc (`ns_printrr*`) | unchanged | 2026-11-13 | — |
| 7 | CVE-2026-54369 | acl | unchanged | 2026-11-13 | runtime hardening |
| 8 | CVE-2026-54370 | acl | unchanged | 2026-11-13 | runtime hardening |
| 9 | CVE-2026-82560 | perl (`Pod::Text`) | not_affected | 2026-11-13 | — |
| 10 | CVE-2026-9538 | perl (`Archive::Tar`) | not_affected | 2026-11-13 | — |
| 11 | CVE-2025-69720 | ncurses (`infocmp`) | unchanged | 2026-11-13 | — |
| 12 | CVE-2026-85091 | zlib | false_positive | 2026-11-13 | — |
| 13 | CVE-2026-107778 | krb5 | accepted_risk / not_affected per package (unchanged) | 2026-11-13 | — |
| 14 | CVE-2026-107161 | Cyrus SASL | not_affected | 2026-11-13 | — |
| 15 | CVE-2026-102010 | libstdc++ | accepted_risk / not_affected per package (unchanged) | 2026-11-13 | — |
| 16 | CVE-2026-95619 | libstdc++ | accepted_risk / not_affected per package (unchanged) | 2026-11-13 | — |

**No other advisory is approved.** The severity is the scanner's
(**High**) for every record.

**Not excepted:** the Critical nginx advisories CVE-2026-15176,
CVE-2026-93479 and CVE-2026-97205.
- E16-P1: a Critical is never exception-eligible.
- They are remediated under E16-R2-a.
- The related High, CVE-2026-97629, is remediated by the same upgrade and is
  not excepted.

### 2.1 Records

| | Records | Application | Gateway | accepted_risk | not_affected | false_positive |
|---|---|---|---|---|---|---|
| Previous decision (`OWNER-0O-E16-2026-10-11`) | 115 | 60 | 55 | 30 | 83 | 2 |
| Renewed (re-verified, unchanged classification) | 115 | 60 | 55 | 30 | 83 | 2 |
| Retired | 0 | | | | | |
| New | 0 | | | | | |
| **Active under this decision** | **115** | **60** | **55** | **30** | **83** | **2** |

**What changed in each record:** only `approved_by`, `created` and
`expires`. Every other field (advisory, image, package, version, status,
reason, compensating control and conditions) is byte-identical to the
previous record.

**What changed in the approval:**
- `max_days` is 28 for every advisory (the decided window);
- `decided` and `record` are this decision's;
- `reviewed_digests` are the §1 digests.

## 3. Conditions and compensating controls

These are unchanged from `OWNER-0O-E16-2026-10-11` §2.2–§3.

**Controls**
- Every role runs non-root, with all capabilities dropped and
  `no-new-privileges`.
- The runtime-hardening conditions (#1, #3, #4, #7, #8) are enforced
  through `runtime-security.json`, `verify-images.sh` (per artifact) and
  `verify-artifact`.
- Each package is either `not_affected` (the vulnerable code is absent),
  `false_positive` (zlib), or `accepted_risk` only where the vulnerable code
  is present but never reached by any role.

## 4. Window

- **Created:** 2026-10-16.
- **Expires:** **2026-11-13** for every record, one common date 28 days
  after creation. This is shorter than the 30-day policy maximum and is the
  decided window.
- **Last passing day:** **2026-11-12**. The evaluator treats
  `expires <= today` as expired, and one expired record invalidates the
  whole file.
- **Hard stop:** from 2026-11-13, the CI `Supply chain` job and
  `publication-check` refuse every candidate.
- **No automatic renewal.** No exception may extend beyond 2026-11-13 under
  this approval.

## 5. Successor digests

A successor artifact may use this decision only when **all** of these hold:
1. it was built from a `main` commit that carries these records and the
   runtime security contract;
2. its scan, with a fresh database within the scanner-age policy, has
   **exactly** this residual High set (the same 16 advisories and the same
   image, package and version pairs, severity `high`, no fix), **and** no
   Critical and no other blocking finding;
3. its signed `verify-images.json` proves the runtime hardening;
4. `verify-artifact` returns **VERIFIED**.

**Consequence until E16-R2 lands.** The application image still carries
the three Critical nginx findings, so it cannot meet condition 2. The
Gateway image can.

## 6. Re-review triggers (owner-required)

A record is re-reviewed immediately, and coverage ends automatically where
the evaluator enforces it, when any of these changes:
- the installed package version;
- the vulnerability severity;
- fix availability (an exception never covers a fixed High: it blocks as
  `high_fix_available`);
- a Debian security release, or a Debian point release affecting the
  package;
- exposure or reachability (a new role; a role running `ss`, APT, SASL
  authentication or a GSS-API acceptor; `libsasl2-modules` installed; a new
  C++ consumer of `libstdc++`);
- a compensating control (privileged mode, added capabilities, root, or a
  failing runtime contract, which also withdraws the conditional records);
- the base image.

**Cadence:** weekly review, as proposed. **Do not wait** for 2026-11-13 when
an upstream or Debian fix appears earlier.

## 7. Not approved

- Any advisory not listed in §2, any Critical, any High with a fix.
- Any package not listed for its image, and any changed package version.
- Publication, promotion, registry push, production signing or deployment:
  **PUBLISHED = NONE, PROMOTED = NONE**.
- Final E02 (ADR 0058) is not satisfied by this decision. It resolves E16
  only, and only through 2026-11-12.

## 8. Status note — E16-R2 held (2026-10-16)

The §2 statement that the nginx Criticals are "remediated under E16-R2-a"
records the route decided on 2026-10-16. It is **not** an outcome.

**What happened.** The R2-a candidate (`fb29aff5`, nginx.org stable
`1.30.6-1~trixie`) was built and verified:
- the image works, and its hardening is intact;
- the signing chain is sound;
- nginx.org's 1.30.6 changelog fixes all four advisories.

**Why it is held.** Grype still matches CVE-2026-15176, -93479 and -97205
(Critical) and CVE-2026-97629 (High) against it. Debian's tracker records
no fixed version for trixie (sid is fixed in 1.30.4-8), so the Debian 13
feed flags every `nginx` deb version.

**Owner decision (2026-10-16): HOLD / DO NOT PUBLISH.** Upstream-fixed remediation has been demonstrated in an unpublished candidate, but Lycenza's authoritative vulnerability scanner continues to classify the app image as blocked. E16-R2 therefore remains held pending a scanner-recognised/Debian-qualified remediation path.

**Monitoring.** Debian trixie nginx is checked weekly, and immediately after
a Debian security or point release or an nginx metadata change. Each check
records:
- the Debian version;
- the tracker's fixed-version state;
- the Grype database;
- whether the three Criticals still match.

When Debian ships a qualifying trixie fix, the image is rebuilt from `main`
with Debian's fixed nginx, and R2-a is abandoned.
