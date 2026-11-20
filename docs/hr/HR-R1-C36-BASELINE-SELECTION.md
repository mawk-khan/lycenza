# HR.R1-C36: the five development-closure baselines, selected (2026-11-20)

**A selection record. It changes no code, migration, test or data contract.** The HR owner formally selected all five HR.R1 closure baselines on 2026-11-20, in the C36 decision dialog, after an independent review. Selection is not the closure verdict. **HR.R1 stays OPEN** until the closure regression (C27 §9) and the formal HR.R1 assessment (closure contract §3, §4). UAT is not signed off. Staging stays PAUSED and production NO-GO.

| | |
|---|---|
| Decision owner | The HR owner (C27 §9: "HR owner" for every dimension; Legal decides its own gates, none of which this selects) |
| Authority | The owner's C36 dispatch (2026-11-20): read-only inspection, candidate preparation, independent review, formal presentation and recording of the owner's selections. Not authorized and not done: application or schema change, data replacement, synthetic School reset, new business fixtures, UAT sign-off, Legal/DPO approval, staging, production |
| Decisions | Given by the owner in this session on 2026-11-20 (UTC), individually per dimension, as recorded in §3. No decision is inferred from a green run |
| Git at selection | `main` = `origin/main` = `eb3d6dde`; clean tree, one worktree; the stash and `integration/e16-r2` preserved; ruleset 24909660 active, no bypass actor; no publication after `eb3d6dde` |
| Evidence pinned at `eb3d6dde` (identical blobs at `5dcc35ef`) | gate register `8f8d227fc03b`, `vulnerability-exceptions.json` `23688c539c3c`, RF2 record `c6a3cb41a804`, capability map `c4f33615c813`, audit register `adc64b547fa8`, domain ownership map `d4b7c4f525b4` |

## 1. Findings before selection

**Development MAJOR inventory: none open.**
- WO22-N1, RF1-N1 and WO23-N1 are closed for development by WO-23 (`8ce263c5`).
- WO20-N1, RF1-N2, WO20-N7 and WO18-N1 are closed by WO-24 (`e7d7ee02`).
- WO22-N5 and C26-N2 are closed by WO-25 (`5dcc35ef`).
- Each record reports no open HIGH or MEDIUM, and the C36 review found none.

**Post-RF2 residuals**, dispositioned by the owner in this session (2026-11-20). They are outside RF2's 81 approvals, which are unchanged:

| Id | Severity | Finding (source) | Decision | Owner | Re-review trigger |
|---|---|---|---|---|---|
| WO24-N1 | LOW | A link-correction approval of a CURRENT link refuses a reviewer known to be the Employee through another account; of an ENDED link it does not (WO-24 record §5, W24-R1) | **ACCEPTED** (fails closed; no unauthorized effect) | HR, Identity | Any change to rule 145's correction contract |
| WO24-N2 | LOW | The person-level unlink refusal has no database half; `ended_by_user_id` comes from a caller-set session value a raw writer may omit (W24-R3) | **ACCEPTED under rule 102**: the check concerns only the acting User's identity, which rule 102 keeps an application boundary | HR, Identity | A database-recorded actor for link changes |
| WO24-N3 | LOW | An alias-only account (same person through ANOTHER Employee) is refused as this Employee but its roles are not read for this Employee's separation access (W24-R4) | **ACCEPTED** (matches RF2 §5.3's approved wording) | HR, Identity | A duplicate HR identity case, or RG-ID-1 |
| WO25-N1 | LOW | The simultaneous-INSERT idempotency-claim branch has no deterministic test (WO-25 record §6) | **ACCEPTED, routed** to Platform: a deterministic claim-race test before staging | Platform | Any change to `IdempotencyGuard` |
| WO25-N2 | INFO | The platform image has no `pkill`/`ps` (WO-25 record §6) | **ACCEPTED, routed** to Platform | Platform | A test relying on either |

**Registers unchanged** (recounted at both SHAs):
- capability map: **361** rows (IMP 163, GATE 87, DEF 68, ALT 22, PART 8, XDOM 7, NR 6);
- audit register: **61** rows;
- RF2: **81** dispositions, the record unchanged since `33166cd3`.

**Gates, visible and unchanged.** Selecting the Legal/Security baseline opens none of them.

| Gate or handoff | Development closure | UAT sign-off | Staging | Production / real data |
|---|---|---|---|---|
| RG-ID-1 (School's one-account-per-person assertion) | Not required | Not required | Required before real Payroll | Required |
| R1-02 (X-1, 24 non-Payroll files) | PARTIAL, recorded; assessed at the HR.R1 verdict | — | Before staging | Required |
| C33-H1..H5 (Fees, Payments, Communications, Examinations, Erasure) | Handoffs, not failing | — | Their owners | Their owners |
| XRP-3 (TeachingAssignments) | Deferred | — | Re-review at staging or above 1,000 staff | — |
| Legal/DPO (E21 incl. D9, E45/ESI-12, E47, HRX-L1..L4, S-4, X-1, ADR 0036, ADR 0040, D-11) | Recorded, shut, not failing (C27-C) | — | Shut | Shut |
| E16 (`OWNER-0O-E16-2026-11-01`) | Valid through 2026-11-28 | — | A successor before any publication from 2026-11-29 | — |

## 2. Candidates and evidence

### 2.1 Source

- **`5dcc35ef9539eb94743af7fc6e402c40008f8a72`**, the WO-25 code: the last code-lane SHA, an ancestor of `main`.
- **Work orders in its ancestry:** WO-19 `3786968e`, WO-20 `2c66460c`, WO-21 `f03aa6aa`, WO-22 `5b78154c`, WO-23 `8ce263c5`, WO-24 `e7d7ee02`, WO-25 `5dcc35ef`.
- **Runtime-identical to `main`:** `5dcc35ef..eb3d6dde` changes four documents under `docs/hr` only.
- **CI:**
  - **Full suite on this exact SHA:** run **44781452652**, attempt 1, every required job green: four shards, coverage union, static and frontend, full-history secrets, supply chain, integrity, production images. 11,998 tests, 468,263 assertions, 0 failures, 1 skip (ESI-12); union sha256 `0d8fd41c9fef…`, 1,419 files.
  - **Later commits:** `fe309c22`, `58ae0236` and `eb3d6dde` ran the documentation lane (the `repository-documents` PHPUnit group and the static, secrets and integrity jobs), not the full suite. They are evidence publications, not regressions.
- **Why not `eb3d6dde`:** it has no full suite on its own SHA, and a new run would have to finish by 2026-11-28 (E16).

### 2.2 Database

The baseline is the **schema and migration state of the development database, with its verifier and preservation evidence**. It is not a frozen data snapshot: the queue worker and scheduler keep writing heartbeats and outbox state, and UAT will add synthetic events. Those are test-execution effects, tracked as such.

- **Target:**
  - DDEV `lycenza`, database `db`, PostgreSQL 16.14;
  - migration role `db` (superuser, BYPASSRLS, as rule 143 requires);
  - runtime roles `school_os_app` and `school_os_retention`, neither superuser nor BYPASSRLS.
- **Ledger:** **402/402**, batch 10 last (`2027_02_27_090000_bind_separation_access_to_the_person`), 0 pending against the 402 files at `5dcc35ef`.
- **`platform:verify-database`:** **failed=0**; one operator-evidence row, connection encryption, expected outside production.
- **Forced RLS:** 256 tables.
- **Runtime grants:** `school_os_app` holds 934 table grants. md5 `71668564a2a3f67e81f35eb1997ff2d8` over `string_agg(table_name || ':' || privilege_type, ',' ORDER BY table_name, privilege_type)` of `information_schema.role_table_grants WHERE grantee = 'school_os_app'`.
- **Schema fingerprint:** SHA-256 **`2d581b7042d1f75ca63bda0a4a3eda94c38b8ebc99d63c74bcfdda2ef143877b`** (md5 `6a994dc267c84041e017676346167243`). Recipe, so it can be reproduced:
  1. `pg_dump --schema-only --no-owner --no-privileges --schema=public`, run in the DDEV `db` container (pg_dump 16.14) as role `db` on database `db`.
  2. Remove every line beginning `--`.
  3. Remove the `\restrict` / `\unrestrict` lines (their key is random per run since pg_dump 16.10).
  4. Remove lines beginning `SET ` and `SELECT pg_catalog.set_config`.
  5. Remove empty lines, then SHA-256 the result.
- **Compatibility with the source.**
  - The same recipe on the isolated test database, freshly migrated from `5dcc35ef`'s tree (pg_dump 16.15), gives an identical md5 except for one line, `COMMENT ON SCHEMA public IS 'standard public schema';`. That is a database-creation default, not written by any migration.
  - The migrated schema is therefore exactly the source's.
- **Preservation.** A hash of every row of every public table before UAT0 compared with now gives:
  - **0 pre-existing rows changed** (excluding scheduler heartbeats);
  - since UAT0's final snapshot, only 15 scheduler heartbeats and one synthetic-School outbox row (background dispatch) changed;
  - the October 2026 month lock unchanged (md5 `851693a2c1a63b4b81b88d75d0a63b63`).

### 2.3 Reference pack

- **Fingerprint:** C16 `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3`, recomputed today from the owner-held pack (602 PNG, no sub-folders) with the convention in `HR-REFERENCE-CAPABILITY-MAP.md` §1. Exact match; the C36 reviewer recomputed it independently.
- **`HR.zip`:** SHA-256 `9c44d851d8a3aacfd13ab9b1ee5b985d2897a1dd2b8c716bd2fa604753c5f73b`, as recorded.
- **Scope and deviations:** the capability map at blob `c4f33615c813`: 163 implemented; deferred, gated, alternative, partial and cross-domain rows unchanged, none reclassified. The pack itself is never edited.

### 2.4 Legal/Security evidence

The decision and gate set as published at `eb3d6dde` (the blobs above, identical at `5dcc35ef`) **plus §1 of this record**. It is an evidence snapshot: it opens no gate, approves no real data, real Payroll, staging or production, and creates no RG-ID-1 assertion (the synthetic UAT School is never one).

### 2.5 Local environment

- **Stack:** DDEV v1.25.4 (Docker Desktop):
  - web image `ddev/ddev-webserver:v1.25.4` (local build `sha256:668b4b656068…`): PHP 8.3.33, Laravel 13.30.0 (vendor matches `composer.lock`), Node 22;
  - db image `postgres:16` (local build `sha256:65eee3c46ada…`): PostgreSQL 16.14;
  - Mailpit as the only mail sink (SMTP `127.0.0.1:1025`);
  - queue worker and scheduler daemons;
  - School timezone Asia/Kolkata (its "today" runs 5 h 30 min ahead of UTC).
- **Frontend rebuilt (C36 review F1, owner-approved):** DDEV was serving a bundle built 2026-11-12, older than WO-19 to WO-21. Strings added by WO-20/21 occurred 0 times in it.
  - Rebuilt with `ddev exec npm run build` from a tree byte-identical to `5dcc35ef` under `apps/platform`. No install: the installed packages match the lock; the only difference is 48 optional platform binaries.
  - Output is git-ignored. `public/build/manifest.json` SHA-256 `7b9e6f1bba440e2800adf9da385772bb348d8f68f046f95c42d7f66dc7990fce`.
  - The three strings now occur once each. The read-only smoke passed again: 8 HR pages 200 for the officer, 403 for staff self-service.
- **Named snapshot:** `lycenza-c36-environment-baseline` (`.ddev/db_snapshots`, git-ignored), SHA-256 `bc4e0855298d3ab8f5b9452baa28c7565a7ab2c5deedbea48308ac3eb7e3a50d`, taken after the rebuild and before any UAT (review F6). It is the restore point for UAT's destructive steps (the E12 death record, the October lock, off-boarding). The pre-migration snapshot `lycenza-pre-wo21-wo24-402` is preserved.
- **Synthetic School `UAT-HR-SYN`:** id `01a1faac-070d-7323-b814-9cee47e7d59f`, `active`; modules finance, leave, payroll and staff_attendance. Recomputed today:
  - 15 accounts, 15 memberships, 20 active grants (21 granted, 1 revoked), 11 links;
  - 13 Employees and employments, 8 cases;
  - 16 tasks and 22 task events;
  - 1 leave year, 2 policies, 5 accrual runs.
- **Fixture provenance:** the private operator scripts (git-ignored, never committed), with full SHA-256:

  | Script | SHA-256 |
  |---|---|
  | `lib.php` | `8334c6fa8cbe6125f1388792d3603dc504db874e1b83bc141f0da02b69b00cc9` |
  | `phase0_platform_mfa.php` | `769ef45f8bbb00bbbd32af0198534ea8f9cd0de3bc0be1dec02f1ec1ffdcbc88` |
  | `phase1_school.php` | `c32438ad422494411daba259e5915f71f62fd445e3c4358a26078c806df6b72b` |
  | `phase2_accounts.php` | `409a11be2c9f7bd70cc8e0ab7838bb154875e4681c03f0db75735e9b411c4de9` |
  | `phase3_mfa.php` | `28b54fefe271bdfad5d6b5808964fd5fa6e2fb6cf44367150c678b4bb3c62a62` |
  | `phase4_hr.php` | `7e583be719d148460cda48bc325702eded19c320108006f35c1a896409df5051` |
  | `phase5_leave.php` | `f4c4929c77f60b03397b3bbcbaf163ac1961e77d18ae27bbd2bddc8a7a80e9b2` |
  | `phase6_separation.php` | `29a1f86a904864d0169eefb3c3a8dfa18ffd653281171dc8011a72378f4afd33` |
  | `phase7_tasks.php` | `befa40e3533551e703aff2d09023dbb092994729f114a782bcda53b4186b865f` |
  | `phase8_identity.php` | `7853507331c68c0c5e7187796acfa75abeea9aeb67e2340b4fbed3eb7e8de200` |
  | `verify_states.php` | `d92633f3e7f5cb8b35f10eba75b085c1bf1d6b6c4ab6d7350cd96fa6d6532951` |

- **Reproducibility:** the retained private scripts, their hashes, the UAT0 manifest and the named snapshot. This is not an independently repeatable published procedure: the owner's interactive School-administrator provisioning and the platform-admin TOTP enrolment cannot be replayed, and the contracts require none (C27 §9: "selection only"). The snapshot is the reproducible state.
- **Secrets:** `secrets.json` is mode 0600, git-ignored, and not in any record, log or screenshot; `operator.log` holds no secret values. It sits under the `local` disk root, where `git clean -X` would remove it: keep an offline copy (review F10).
- **School administrator:** **0 MFA factors.** Enrolling one through Account security is the owner's UAT prerequisite (Staff-accounts changes and off-boarding need it). It was not enrolled on the owner's behalf.
- **UAT status:**
  - The 20-row manifest (UAT0 §4) is unchanged.
  - Browser walkthroughs: **not run**. Smoke page loads are not UAT.
  - axe and keyboard: **not run**.
  - Manual screen reader (C18-A12, WO-5 pages): **not run**; it needs a named human reviewer.
  - The UAT0 "Not configured" fixtures stay as recorded.

  None of this blocks selection: D-RF2-23 makes it a UAT sign-off prerequisite.

## 3. Owner decisions (2026-11-20, the HR owner, C36 decision dialog)

| Dimension | Exact baseline | Decision | Conditions settled |
|---|---|---|---|
| Source | `5dcc35ef9539eb94743af7fc6e402c40008f8a72` | **SELECTED** | **CI run 44781452652 is accepted as D-RF2-3's clean complete CI run** on the selected SHA (RF2 §8 items 1–5; first attempt, every required job, no rerun). The C27 §9 local closure regression and the R1-04/05/07/08 re-runs (RF2 §8 item 7) still follow |
| Database | DDEV `db` schema state: 402/402, schema SHA-256 `2d581b70…877b` (recipe §2.2), verifier failed=0, 256 forced RLS, runtime grants md5 `71668564…` | **SELECTED** | Recipe and grants hash recorded here (review F4) |
| Reference pack | C16 `40ed6a836f5a99c65694118c0481bc468b1fb6ebb379a0275cc33839fa7a1ac3` | **SELECTED** | — |
| Legal/Security evidence | The blobs at `eb3d6dde` listed in the header, plus §1 of this record | **SELECTED** | Today's five dispositions recorded in §1 (review F5). Selecting it opens no gate |
| Local environment | DDEV as in §2.5: snapshot `lycenza-c36-environment-baseline` (`bc4e0855…a50d`), frontend manifest `7b9e6f1b…0fce`, images and versions as listed, School `UAT-HR-SYN` | **SELECTED** | Frontend rebuilt from the source and re-smoked, then snapshotted (owner's choice, review F1/F6/F7) |

**Compatibility:**
- the source holds exactly the 402 migrations the database has applied;
- the database schema equals a fresh migration of the source;
- the frontend bundle is built from the source;
- the fixtures were written by the source's services under that schema;
- the Legal/Security blobs are identical at the source SHA and at `eb3d6dde`;
- the reference pack's mapped scope is the capability map pinned there.

**Controlled drift after selection:** scheduler heartbeats, outbox dispatch, and the synthetic events UAT will create. Each is recorded by the UAT record; none rewrites this selection. A change to source, migrations, frontend build or reference pack is a new selection.

## 4. Independent review

An independent reviewer read the candidates, read only. It recomputed the pack fingerprint, the register counts and the blobs, and checked run 44781452652's jobs and coverage log. It is a review, not the owner's approval. **No open development HIGH or MEDIUM.**

| # | Rating | Finding | Treatment |
|---|---|---|---|
| F1 | HIGH (environment only) | DDEV served a frontend older than WO-19 to WO-21 | **Corrected:** rebuilt from the source, verified, re-smoked, snapshotted (§2.5) |
| F2 | MEDIUM | Was run 44781452652 the D-RF2-3 run? The WO-25 record calls it a publication regression | **Decided by the owner:** accepted as the CI leg; the C27 §9 local closure regression still follows (§3) |
| F3 | MEDIUM | E16 expires: a new CI run on `5dcc35ef` from 2026-11-29 would fail supply chain | Resolved by F2's decision; any later CI leg needs a successor first |
| F4 | MEDIUM | Schema fingerprint and grants hash not reproducible as described | **Corrected:** exact recipe, pg_dump versions and the one-line difference recorded (§2.2) |
| F5 | LOW | Today's WO24/WO25 dispositions absent from the repository | **Corrected:** §1 |
| F6 | LOW | No snapshot after UAT0; 64-bit script hash prefixes | **Corrected:** named snapshot and full hashes (§2.5) |
| F7 | LOW | Environment patch versions float | **Corrected:** versions and image digests recorded |
| F8 | LOW | "Ran no test suite" for the documentation lane was inaccurate | **Corrected** in §2.1 |
| F9 | INFO | Earlier records name an unrelated local project | Not repeated here |
| F10 | INFO | `git clean -X` would remove `secrets.json` | Recorded (§2.5) |

**Confirmed by the reviewer:**
- the six work orders are ancestors of the source, and the source is runtime-identical to `main`;
- 402 migration files;
- run 44781452652's jobs and its coverage line;
- the fingerprint and `HR.zip`, and the 361 and 61 counts at both SHAs;
- every blob identical at both SHAs;
- the script hashes and secret handling; vendor matches the lock;
- nothing in the contracts requires UAT, fixture reproducibility, administrator MFA or the closure regression BEFORE selection.

## 5. Verdict and next

**C36 COMPLETE — FIVE CLOSURE BASELINES FORMALLY SELECTED.** Each dimension has an exact identifier, verified compatibility, an independent review and the owner's selection.

**Next: the HR.R1 closure regression on the selected baselines (C27 §9, RF2 §8 item 7).** Not started; it needs the owner's go-ahead.
- The canonical suite from a clean `SAFE_TEST_ISOLATED=1` snapshot of `5dcc35ef`.
- The R1-04, R1-05, R1-07 and R1-08 re-runs of the docket §12.
- It never resets DDEV or the synthetic School.
- Run 44781452652 already stands as its CI leg, so no new remote run is needed. Any further CI publication from 2026-11-29 needs an E16 successor first.

Then the formal HR.R1 assessment (closure contract §3, §4), separately from UAT sign-off, staging and production.
