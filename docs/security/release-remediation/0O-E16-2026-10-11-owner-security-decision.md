# Owner security decision OWNER-0O-E16-2026-10-11 — residual HIGH findings after E16-R1 remediation

**Reference:** `OWNER-0O-E16-2026-10-11`

**Decided:** 2026-10-11, by owner/security: the project owner acting as the
security decision-maker, as for `OWNER-0O-E16-2026-09-29`. The owner
authorized this decision in E16-D1. No external DPO, legal or security-counsel
approval is claimed.

**Recorded and activated:** Phase 0O / E16-D1, 2026-10-11.

**Machine-readable form:** `approvals.OWNER-0O-E16-2026-10-11` in
[`infrastructure/release/vulnerability-exceptions.json`](../../../infrastructure/release/vulnerability-exceptions.json).
Every active record names this reference in `approved_by`.

**This is a new decision.** It follows a fresh scan and is **not** an
extension of `OWNER-0O-E16-2026-09-29`:
- That decision
  ([`0O-E16-2026-09-29-owner-security-decision.md`](0O-E16-2026-09-29-owner-security-decision.md))
  stays historical truth for its own dates. Its record is unchanged.
  Qualifications made under it, such as `be69b55`, keep citing it.
- This decision **supersedes it for current qualification**. Its approval
  is removed from the active `approvals` block, so no record can be created
  under it again.
- It applies only to the exact residual set and baseline in §1.

## 1. Why remediation came first, and the evidence reviewed

- **Remediation first.** The E16 fresh scan of `ef575bf` (2026-10-11) found
  2 Critical and 12 High-with-a-fix advisories. Policy never lets an
  exception cover those.
  - E16-R1 (`ab8e1ae`, documented in `29785e1`) removed all of them by
    remediation; see
    [`E16-R1-SUPPLY-CHAIN-REMEDIATION-2026-10-11.md`](E16-R1-SUPPLY-CHAIN-REMEDIATION-2026-10-11.md).
  - Only findings with **no fix** are decided here.
- **Qualified executable source:** `29785e13e15ee7cff51e8078f52c35acbd2a874b`.
  It is the E16-R1 tree, and the published `main` at decision time.
- **Fresh qualification scan:** run `local-20261011T102807Z-af2b8e4f`
  (started 2026-10-11T10:28:07Z; `on_protected_main_at_start: true`).
  - It ran the canonical stages `init`, `inputs`, `source-scan`, `audit`,
    `build` (fresh builder, no cache), `verify-images` and `scan`.
  - The complete regression of this exact tree was already green and was
    not re-run here: tree `597e3601`, 8,144 tests, 0 failures, only the
    ESI-12 skip.
- **Reviewed artifacts:**
  - application `sha256:17ba265968b539f8a73e0163f766563ec319ddb216184c55592b320dc4ad4db8`;
  - Gateway `sha256:6347ada75c76eae600cccda7199bca31d3351ace06fc5c5f7eb6ee710a62fcba`.
- **Tools:** Syft 1.33.0 and Grype 0.100.0. The Grype database was built
  2026-10-11T06:30:42Z, within the 24 h scanner-age policy.
- **SBOM sha256:**
  - app `845129e944e3193f6b2da4becb38d0f16268327d8228f88e8445769582050655`;
  - Gateway `bc375d0c975aad31c3ed25465ccb6f28833072c0993641a75d880de9f619b177`.
- **Grype report sha256:**
  - app `58716a2e332c869c1e258f64eeab2ad2ac0fd7d567ef8dce01bf4c7a32c5a156`;
  - Gateway `17e4987fb73cdc4c4034b5628fb59bcc6b6ca2f72435e5d3548acd281aec0c01`.
- **Precondition (evaluator):** **0 Critical** and **0 High with a fix**.
- **Residual High findings: 16 advisories, 115 findings** (60 application +
  55 Gateway):
  - the 97 findings of the previous decision, unchanged: same advisory,
    image, package, version and severity, still with no fix;
  - 18 new findings of 4 advisories.
- **Other evidence:**
  - Language audits: 0 blocking. Three non-blocking advisories remain:
    laravel/framework Low, league/flysystem Low, postcss-selector-parser
    Moderate.
  - Secret scans: 0 in source and in each image.
  - `verify-images.sh`: 133/133.
- **Fix availability (Debian), checked 2026-10-11:**
  - The apt metadata of both images was refreshed against Debian 13
    (`trixie`, `trixie-updates`, `trixie-security`). The only upgradable
    package in either image is `liblzma5` (5.8.1-1+deb13u1 → deb13u2). It
    has no Grype match and is not a governed finding, so no residual package
    has a newer version.
  - Grype reports `wont-fix` or `not-fixed` for every residual finding.
- **External evidence:** stored outside the repository, in
  `~/lycenza-release-evidence/e16-2026-10-11/d1-fresh-scan/`.

## 2. Decisions

| # | Advisory | Family | Decision | Approved to | Condition |
|---|---|---|---|---|---|
| 1 | CVE-2026-76642 | util-linux (mount helper exit status / post-mount hooks) | accepted_risk | 2026-11-10 | runtime hardening |
| 2 | CVE-2026-78408 | util-linux (nsenter `--join-cgroup`) | accepted_risk | 2026-11-10 | — |
| 3 | CVE-2026-78409 | util-linux (libmount `X-mount.subdir`) | accepted_risk | 2026-11-10 | runtime hardening |
| 4 | CVE-2026-78410 | util-linux (restricted fstab bind mounts) | accepted_risk | 2026-11-10 | runtime hardening |
| 5 | CVE-2026-19499 | glibc (`strfmon`/`strfmon_l`) | accepted_risk | 2026-11-10 | — |
| 6 | CVE-2026-5435 | glibc (`ns_printrr*`/`fp_nquery`) | accepted_risk | 2026-11-10 | — |
| 7 | CVE-2026-54369 | acl (libacl pathname functions, symlink traversal) | accepted_risk | 2026-11-10 | runtime hardening |
| 8 | CVE-2026-54370 | acl (TOCTOU privilege escalation) | accepted_risk | 2026-11-10 | runtime hardening |
| 9 | CVE-2026-82560 | perl (Pod::Text) | not_affected | 2026-11-10 | — |
| 10 | CVE-2026-9538 | perl (Archive::Tar) | not_affected | 2026-11-10 | — |
| 11 | CVE-2025-69720 | ncurses (`infocmp`) | accepted_risk | 2026-11-10 | — |
| 12 | CVE-2026-85091 | zlib (`gz_vacate`) | false_positive | 2026-11-10 | — |
| 13 | **CVE-2026-107778** | krb5 (`make_cred_list()` NULL dereference, GSS-API acceptor) | accepted_risk / not_affected per package | 2026-11-10 | — |
| 14 | **CVE-2026-107161** | Cyrus SASL (DIGEST-MD5 plugin `add_to_challenge()`) | not_affected | 2026-11-10 | — |
| 15 | **CVE-2026-102010** | libstdc++ (priority-queue `erase_if` use-after-free) | accepted_risk / not_affected per package | 2026-11-10 | — |
| 16 | **CVE-2026-95619** | libstdc++ (aligned `operator new` integer overflow) | accepted_risk / not_affected per package | 2026-11-10 | — |

**No other advisory is approved.** The severity is the scanner's
(**High**) for every record. The classification describes the advisory's
exposure in this product; it never lowers the severity.

### 2.1 Historical advisories #1–#12 (reassessed, not carried forward)

Each record was re-checked against the remediated images.

**Unchanged facts.** The package, version, severity and fix state are all
unchanged. No fix exists in Debian 13. The status and the runtime-hardening
condition carry over unchanged.

**Rationale, re-verified on 2026-10-11** by scanning every ELF object's
dynamic imports and the package contents of both images:
- **glibc (#5, #6).** No ELF object imports `strfmon`, `strfmon_l`,
  `ns_printrr`, `ns_printrrf` or `fp_nquery`. In the Gateway, libresolv is
  needed only by `libnss_hesiod`, and hesiod is not configured in
  `nsswitch.conf`.
- **acl (#7, #8).** The acl tools are not installed. libacl is linked only
  by Essential utilities: coreutils `cp`/`mv`/`install`, `tar`, `sed`, and
  `useradd`/`usermod`. Neither the `lycenza` entrypoint nor any other script
  in `/usr/local/bin` invokes them. The runtime-hardening proof still
  passes (`verify-images.sh` 133/133).
- **util-linux (#1–#4).** libmount is needed only by util-linux tools.
  `nsenter` is shipped by `util-linux`. `mount` and `umount` carry no
  setuid bit.
- **perl (#9, #10).** `Pod/Text.pm` and `Archive/Tar.pm` are absent.
- **ncurses (#11).** `infocmp` is shipped by `ncurses-bin` only.
- **zlib (#12).** The version is unchanged.

**Exact per-package statuses:** as in the previous decision's §2 table,
unchanged.

### 2.2 New advisories #13–#16: per-package evidence

The rule (`RELEASE-QUALIFICATION.md`) is that `accepted_risk` applies only
to packages that actually contain the vulnerable code. Every other matched
package is `not_affected`.

The evidence comes from symbols and package files read on 2026-10-11 from
the reviewed images, using host `nm` and `readelf` on the extracted
libraries and `dpkg -L`.

**CVE-2026-107778 (krb5 1.21.3-5+deb13u1, application only).**
- **Where the code is.** The flaw is in `make_cred_list()`, behind
  `krb5_rd_cred()`, which is defined only in `libkrb5.so.3`. Its only
  importer is `libgssapi_krb5.so.2`, through the GSS-API acceptor
  (`gss_accept_sec_context`).
- **Who could reach it.**
  - The only importer of `gss_accept_sec_context` is `libtirpc`.
  - libtirpc's only consumer is `/usr/bin/ss` (iproute2). It imports only
    client RPC calls (`clnt_vc_create`, `getrpcbynumber`), and no role
    starts it.
  - libpq (PHP `pgsql`/`pdo_pgsql`) imports `gss_init_sec_context` and
    never the acceptor, so the application only ever initiates.
- **Statuses:**
  - `libkrb5-3`: **accepted_risk** (contains the defective function);
  - `libgssapi-krb5-2`: **accepted_risk** (contains the acceptor path that
    feeds it);
  - `libk5crypto3`: **not_affected** (neither defines nor imports
    `krb5_rd_cred`);
  - `libkrb5support0`: **not_affected** (neither defines nor imports it).

**CVE-2026-107161 (Cyrus SASL 2.1.28+dfsg1-9, application only).**
- **Where the code is.** The flaw is in the DIGEST-MD5 plugin, which ships
  in `libsasl2-modules`. That package is **not installed**.
- **What is installed:**
  - the plugin directory holds only `libsasldb`;
  - no `libdigestmd5` exists anywhere in the image;
  - `libsasl2.so.2` and `libsasldb.so.2` contain no DIGEST-MD5 code (no
    `DIGEST-MD5` or `digest-uri` strings).
- **Statuses:** `libsasl2-2` and `libsasl2-modules-db` are **not_affected**.
  The version is in the affected range, so `false_positive` would be
  wrong.

**CVE-2026-95619 (libstdc++ 14.2.0-19, both images).**
- **Where the code is.** The aligned `operator new`
  (`_ZnwmSt11align_val_t`) is defined in `libstdc++.so.6`.
- **Who links it.** In both images, `libstdc++.so.6` is linked only by APT
  (`apt*`, `libapt-pkg`, `libapt-private`). No PHP extension, Python wheel,
  service binary or process role links or runs it.
- **Statuses:**
  - `libstdc++6`: **accepted_risk**;
  - `libgcc-s1`: **not_affected** (`libgcc_s.so.1` exports no C++
    standard-library symbol);
  - `gcc-14-base`: **not_affected** (it ships no executable code, only
    documentation and copyright files).

**CVE-2026-102010 (libstdc++ 14.2.0-19, both images).**
- **The same linkage evidence** as CVE-2026-95619.
- **Where the code is.** `libstdc++.so.6` exports no priority-queue or
  `erase_if` symbol. That template code is instantiated from headers, and
  the headers are not installed.
- **Why `accepted_risk` anyway.** It cannot be proven that the library
  makes no internal use of the code, so `libstdc++6` is **accepted_risk**,
  conservatively.
- **Other packages:** `libgcc-s1` and `gcc-14-base` are **not_affected**,
  as above.

**Compensating controls (all records).**
- Every role runs non-root, with all capabilities dropped and
  `no-new-privileges`. This is proven per artifact by
  `verify-images.sh`.
- Re-evaluation happens at every Debian 13 point or security release and
  by the daily SBOM re-scan.

### 2.3 Records

| | Records | Application | Gateway | accepted_risk | not_affected | false_positive |
|---|---|---|---|---|---|---|
| Previous decision (`OWNER-0O-E16-2026-09-29`) | 97 | 48 | 49 | 24 | 71 | 2 |
| Renewed (re-verified, unchanged classification) | 97 | 48 | 49 | 24 | 71 | 2 |
| Retired | 0 | | | | | |
| New (#13–#16) | 18 | 12 | 6 | 6 | 12 | 0 |
| **Active under this decision** | **115** | **60** | **55** | **30** | **83** | **2** |

- **Reconciliation:** 97 = 97 renewed + 0 retired; and 97 renewed + 18 new
  = 115 active.
- **How the 18 new records break down:**
  - CVE-2026-107778: 4 application packages;
  - CVE-2026-107161: 2 application packages;
  - CVE-2026-102010: 3 packages × 2 images;
  - CVE-2026-95619: 3 packages × 2 images.
- **What changed in the renewed records.** Only `approved_by`, `created`
  and `expires`. Every other field (advisory, image, package, version,
  status, reason, compensating control and conditions) is byte-identical to
  the previous record.

## 3. Conditions: runtime hardening (#1, #3, #4, #7, #8)

These are unchanged from the previous decision's §3:
- not privileged;
- all capabilities dropped;
- `no-new-privileges`;
- the non-root user;
- no setuid `mount`/`umount`;
- no user-mountable fstab entry;
- no root process in any role.

**How they are enforced:**
- **Contract:** `runtime-security.json`.
- **Proof:** `verify-images.sh`, per artifact.
- **Gate:** `verify-artifact` withdraws these records without that proof.
- **Deployment:** evidence that a real platform applies the contract is
  still outstanding.

## 4. Window

- **Created:** 2026-10-11.
- **Expires:** **2026-11-10** for every record. This is one common date,
  30 days after creation: the policy maximum for High, and the repository's
  normal interval.
- **Last passing day:** **2026-11-09**. The evaluator treats
  `expires <= today` as expired, and one expired record invalidates the
  whole file.
- **No automatic renewal.**

## 5. Reviewed digests and successor digests

- **Reviewed:** the `29785e1` manifests in §1.
- **Successor rule.** A successor artifact may use this decision only when
  **all** of these hold:
  1. it was built from a `main` commit that carries these records and the
     runtime security contract;
  2. its scan, with a fresh database within the scanner-age policy, has
     **exactly** this residual set: the same 16 advisories, the same
     (image, package, version) pairs, severity `high`, and no fix;
  3. its signed `verify-images.json` proves the runtime hardening;
  4. `verify-artifact` returns **VERIFIED**.

## 6. Automatic invalidation and early re-review triggers

Coverage ends automatically, and the finding blocks and returns for a new
decision, when:
- **a fix becomes available** (the evaluator blocks it as
  `high_fix_available`; an exception never covers a fixed High);
- **a package version changes**, including any base-image digest refresh or
  Debian 13 point or security release that changes an affected package;
- **a severity changes** in the scanner or advisory data;
- **the residual set changes** in any way (a new advisory, package or
  version), or a new ungoverned High or any Critical appears;
- **runtime exposure changes:**
  - a new process role;
  - a role that runs `ss`, APT, SASL authentication or a GSS-API acceptor
    (for example, PostgreSQL GSSAPI or Kerberos deployment-side
    configuration that makes the application an acceptor);
  - installing `libsasl2-modules`;
  - any new C++ consumer of `libstdc++`;
- **deployment topology changes** that bear on exploitability, such as
  running a container privileged, with added capabilities, as root, or
  without the runtime security contract;
- the runtime-hardening conditions fail, or `verify-images` fails (the
  conditional records are withdrawn).

**No manual override exists.**

## 7. Not approved

- Any advisory not listed in §2, any Critical, any High with a fix.
- Any package not listed for its image.
- Publication, promotion, registry push, production signing or deployment:
  **PUBLISHED = NONE, PROMOTED = NONE**.
- Final E02 (ADR 0058) is not satisfied by this decision. It resolves E16
  only, and only through 2026-11-09.
