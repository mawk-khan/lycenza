# Owner security decision OWNER-0O-E16-2026-11-01 — E16-S2 successor: 16 renewed and 1 new no-fix HIGH

**Reference:** `OWNER-0O-E16-2026-11-01`

**Decided:** 2026-11-01, by owner/security: the project owner acting as the
security decision-maker, as for `OWNER-0O-E16-2026-10-16`. The decision was
given in-session on the proposal of the successor review
[`E16-2026-11-01-SUCCESSOR-REVIEW.md`](E16-2026-11-01-SUCCESSOR-REVIEW.md)
("Approve as proposed").
- **Decision E16-S2:**
  - renew the 16 reviewed advisories (115 records) for 28 days, unchanged;
  - add one new advisory, CVE-2026-84243 (glibc gettext; 4 records), for the
    same 28 days.
- **The same instruction also decided:** the non-blocking Medium
  `symfony/mime` CVE-2026-45070 is fixed in its **own slice after E16**,
  not in this checkpoint.

No external DPO, legal or security-counsel approval is claimed.

**Recorded and activated:** Phase 0O / E16 successor, 2026-11-01, together
with remediation **E16-R3** (Gateway CPython 3.14.10 for the Critical
CVE-2026-115557, which is never exception-eligible).

**Machine-readable form:** `approvals.OWNER-0O-E16-2026-11-01` in
[`infrastructure/release/vulnerability-exceptions.json`](../../../infrastructure/release/vulnerability-exceptions.json).
Every active record names this reference in `approved_by`.

**This is a new decision,** not an extension of `OWNER-0O-E16-2026-10-16`:
- **That decision**
  ([`0O-E16-2026-10-16-owner-security-decision.md`](0O-E16-2026-10-16-owner-security-decision.md))
  stays historical truth for its own dates.
- **This decision supersedes it for current qualification.** Its approval is
  removed from the active `approvals` block.

## 1. Evidence reviewed

- **Reviewed source:** `bbc3cb24e25bfd4f1134f22b6fcaca4b9beead15`, which was
  protected `main` at the review.
- **Fresh scan:** the *Release qualification* workflow on protected `main`,
  run **41287488442**: same-run complete regression (passed), lockfile
  integrity, language audits, source secret scan, fresh-builder image
  builds, `verify-images.sh` (144 checks, exit 0), Syft SBOM, Grype scan,
  image secret and history scans, provenance and the signed NON-PRODUCTION
  evidence bundle.
- **Tools:** Syft 1.33.0 and Grype 0.100.0. The database was built
  2026-11-01T06:20:12Z (3.2 h old at scan time).
- **Reviewed artifacts:**
  - application `sha256:3c93bf0fc61dcf31f4358c7324efa72599fb819a261e4cc6ca9535adb1a7b4e3`;
  - Gateway `sha256:770fa783114a709f85c41a205a4b8c56ad80c83cea5ab4294eceb31e21d46459`.
- **Remediated Gateway (E16-R3):** built from the candidate commit with the
  canonical fresh builder and scanned with the pinned Syft and Grype against
  the **same** database. It carries CPython 3.14.10, 0 Critical, and the
  same Debian package set as the reviewed Gateway (successor review §4).
- **Renewed findings.** Every one of the 115 records still matches a finding:
  - the same image, advisory, package, exact version and severity (`high`);
  - Grype reports `wont-fix` (or `not-fixed` for zlib) for each;
  - no record is unused in either image.
- **Evidence re-verified,** not copied forward: the per-package evidence was
  re-checked on freshly built images with `readelf` and the dpkg file lists
  (successor review §3).

## 2. Decisions

| # | Advisory | Family | Decision | Approved to | Condition |
|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | util-linux | accepted_risk / not_affected per package (unchanged) | 2026-11-29 | runtime hardening |
| 2 | CVE-2026-78408 | util-linux (`nsenter`) | unchanged | 2026-11-29 | — |
| 3 | CVE-2026-78409 | util-linux (libmount) | unchanged | 2026-11-29 | runtime hardening |
| 4 | CVE-2026-78410 | util-linux (fstab bind) | unchanged | 2026-11-29 | runtime hardening |
| 5 | CVE-2026-19499 | glibc (`strfmon`) | unchanged | 2026-11-29 | — |
| 6 | CVE-2026-5435 | glibc (`ns_printrr*`) | unchanged | 2026-11-29 | — |
| 7 | CVE-2026-54369 | acl | unchanged | 2026-11-29 | runtime hardening |
| 8 | CVE-2026-54370 | acl | unchanged | 2026-11-29 | runtime hardening |
| 9 | CVE-2026-82560 | perl (`Pod::Text`) | not_affected | 2026-11-29 | — |
| 10 | CVE-2026-9538 | perl (`Archive::Tar`) | not_affected | 2026-11-29 | — |
| 11 | CVE-2025-69720 | ncurses (`infocmp`) | unchanged | 2026-11-29 | — |
| 12 | CVE-2026-85091 | zlib | false_positive | 2026-11-29 | — |
| 13 | CVE-2026-107778 | krb5 | accepted_risk / not_affected per package (unchanged) | 2026-11-29 | — |
| 14 | CVE-2026-107161 | Cyrus SASL | not_affected | 2026-11-29 | — |
| 15 | CVE-2026-102010 | libstdc++ | accepted_risk / not_affected per package (unchanged) | 2026-11-29 | — |
| 16 | CVE-2026-95619 | libstdc++ | accepted_risk / not_affected per package (unchanged) | 2026-11-29 | — |
| 17 | **CVE-2026-84243** (new) | glibc (gettext `LANGUAGE`) | `libc6` accepted_risk; `libc-bin` not_affected (both images) | 2026-11-29 | — |

**No other advisory is approved.** The severity is the scanner's
(**High**) for every record.

**Not excepted:** the Critical CVE-2026-115557 (CPython 3.14.8, Gateway).
- E16-P1: a Critical is never exception-eligible.
- A fix exists (CPython 3.14.10), so it is remediated by **E16-R3**, in the
  same candidate as this decision.

### 2.1 Records

| | Records | Application | Gateway | accepted_risk | not_affected | false_positive |
|---|---|---|---|---|---|---|
| Previous decision (`OWNER-0O-E16-2026-10-16`) | 115 | 60 | 55 | 30 | 83 | 2 |
| Renewed (re-verified, unchanged classification) | 115 | 60 | 55 | 30 | 83 | 2 |
| Retired | 0 | | | | | |
| New (CVE-2026-84243) | 4 | 2 | 2 | 2 | 2 | 0 |
| **Active under this decision** | **119** | **62** | **57** | **32** | **85** | **2** |

**What changed in each renewed record:** only `approved_by`, `created` and
`expires`. Every other field (advisory, image, package, version, status,
reason, compensating control and conditions) is byte-identical to the
previous record.

**What changed in the approval:**
- `decided` and `record` are this decision's;
- `reviewed_digests` are the §1 digests;
- CVE-2026-84243 is added (`max_days` 28, no condition);
- `max_days` stays 28 for every advisory.

### 2.2 CVE-2026-84243 — evidence for the new records

**The advisory.** The glibc gettext functions accept `LANGUAGE` entries
containing `/` or `..` (unless the program is setuid). An attacker who can
set `LANGUAGE` for a gettext-using process can make it load a crafted
message catalog (`.mo`) from anywhere on the filesystem, which may allow
code execution with that process's privileges. Scanner severity High; CVSS
7.0, exploitability 1.1 (local).

**Fix availability.** None. Debian's tracker lists glibc vulnerable in
bookworm, trixie (`2.41-12+deb13u4`), forky and sid, with no fixed version
(Debian bug 1152640, upstream GLIBC-SA-2026-0025). Grype reports
`not-fixed`.

**Evidence on the fresh images (both):**
- The vulnerable code is in `libc.so.6`, owned by `libc6`. **No `libc-bin`
  program imports a gettext function** (`gettext`, `dgettext`, `dcgettext`,
  the `n` variants, `textdomain`, `bindtextdomain`).
- The image ships **no `.mo` message catalog at all** (0 files).
- **No `LANGUAGE`** in either image's configured environment
  (app: `PATH`, `PHP_INI_DIR`, `APP_ENV`, `LOG_*`, `DB_CONNECTION`,
  `APP_MAINTENANCE_*`, `REDIS_CLIENT`; Gateway: `PATH`, `PYTHON_*`,
  `ENVIRONMENT`).
- **No request or user input reaches the process environment.**
  - Application code (`app/`, `config/`, `bootstrap/`, `public/`) calls no
    `putenv()`.
  - The only environment writer is Laravel's dotenv loader, and it writes
    only the deployment's own configuration values.
  - FastCGI request parameters reach `$_SERVER`, never the worker's
    `environ`.
  - The Gateway writes no `os.environ` entry and starts no subprocess.
- **Notable consumers:** `libpq` (app) and `libpython3.14` (Gateway). Both
  run inside processes whose environment is fixed by the deployment.
- **No setuid process** (mount/umount setuid bits removed, every role runs
  with `no-new-privileges`).

**Classification.**
- `libc6`: `accepted_risk`. The code is present and has consumers, but
  exploiting it needs control of the deployment environment plus a
  file-write primitive, which is already a compromise.
- `libc-bin`: `not_affected`. The same shape as the glibc records #5 and #6.

## 3. Conditions and compensating controls

The controls are unchanged from `OWNER-0O-E16-2026-10-16` §3.
- Every role runs non-root, with all capabilities dropped and
  `no-new-privileges`.
- The runtime-hardening conditions (#1, #3, #4, #7, #8) are enforced
  through `runtime-security.json`, `verify-images.sh` (per artifact) and
  `verify-artifact`.
- Each package is either `not_affected` (the vulnerable code is absent),
  `false_positive` (zlib), or `accepted_risk` only where the vulnerable code
  is present but never reached by any role.

## 4. Window

- **Created:** 2026-11-01.
- **Expires:** **2026-11-29** for every record, one common date 28 days
  after creation. This is shorter than the 30-day policy maximum and is the
  decided window.
- **Last passing day:** **2026-11-28**. The evaluator treats
  `expires <= today` as expired, and one expired record invalidates the
  whole file.
- **Hard stop:** from 2026-11-29, the CI `Supply chain` job and
  `publication-check` refuse every candidate.
- **No automatic renewal.** No exception may extend beyond 2026-11-29 under
  this approval. The next successor review should start around
  **2026-11-20**.

## 5. Successor digests

A successor artifact may use this decision only when **all** of these hold:
1. it was built from a `main` commit that carries these records and the
   runtime security contract;
2. its scan, with a fresh database within the scanner-age policy, has
   **exactly** this residual High set (the same 17 advisories, the same
   image, package and version pairs, severity `high`, no fix), **and** no
   Critical and no other blocking finding;
3. its signed `verify-images.json` proves the runtime hardening;
4. `verify-artifact` returns **VERIFIED**.

**Consequence:** the reviewed Gateway digest carries the CPython Critical
and cannot meet condition 2. A Gateway built from a `main` commit that
includes E16-R3 can.

## 6. Re-review triggers (owner-required)

A record is re-reviewed immediately, and coverage ends automatically where
the evaluator enforces it, when any of these changes:
- the installed package version;
- the vulnerability severity;
- fix availability (an exception never covers a fixed High: it blocks as
  `high_fix_available`);
- a Debian security release, or a Debian point release affecting the
  package;
- exposure or reachability:
  - a new role;
  - a role running `ss`, APT, SASL authentication or a GSS-API acceptor;
  - `libsasl2-modules` installed;
  - a new C++ consumer of `libstdc++`;
  - **new for #17:** any `.mo` catalog in an image, any code path that
    sets `LANGUAGE` or writes the process environment from input, or a
    setuid program;
- a compensating control (privileged mode, added capabilities, root, or a
  failing runtime contract, which also withdraws the conditional records);
- the base image.

**Cadence:** weekly review. **Do not wait** for 2026-11-29 when an upstream
or Debian fix appears earlier.

## 7. Not approved

- Any advisory not listed in §2, any Critical, any High with a fix.
- Any package not listed for its image, and any changed package version.
- The non-blocking language advisories (`symfony/mime` Medium,
  `league/flysystem` Low, the npm Moderates). They are not exception
  records, because Medium and Low never block. `symfony/mime` is fixed in
  its own slice after E16. `league/flysystem` (Low, 3.35.3 available) is
  recommended for the same slice.
- Publication, promotion, registry push, production signing or deployment:
  **PUBLISHED = NONE, PROMOTED = NONE**.
- Final E02 (ADR 0058) is not satisfied by this decision. It resolves E16
  only, and only through 2026-11-28.
