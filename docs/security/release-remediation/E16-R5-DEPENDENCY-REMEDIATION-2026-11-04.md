# E16-R5 — Symfony HTTP/routing/filesystem dependency remediation (2026-11-04)

**Status: REMEDIATED (dependency-only).**
- **What:** four Symfony packages move to the smallest fixed patch release,
  7.4.23, closing seven HIGH upstream advisories that each have a fix.
- **Why now:** the CI supply-chain audit (`qualify audit --fail-on-blocking`)
  blocked every candidate from 2026-11-04 (first seen on the HR.15
  candidate `1cfdcfec`, CI run 41913809494, job "Supply chain"). A HIGH with
  a fix available is never exception-eligible (`evaluate.py`,
  `high_fix_available`), so remediation is the only path. Owner authorized
  this slice in session on 2026-11-04 ("bump first, then HR.15"), following
  the E16-R4 precedent (dependency remediation as its own slice).

**Separate from the High exception set.** `vulnerability-exceptions.json` is
untouched: `OWNER-0O-E16-2026-11-01` keeps its records, valid **through
2026-11-28** (hard stop 2026-11-29; next successor review about 2026-11-20).

## 1. Baseline

- `main` = `origin/main` = HEAD = `d5ea8a93` (HR.12). Clean tree, one
  worktree, historical stash untouched; `main` CI run 41884445789 green.

## 2. Findings (composer audit --locked on `d5ea8a93`)

| Advisory | Package | Installed | Fixed | Note |
|---|---|---|---|---|
| CVE-2026-118514 (PKSA-24ph-xkrh-y79j) | symfony/http-foundation | v7.4.21 | v7.4.23 | HEAD requests bypass `security.access_control` methods (incomplete fix of CVE-2026-45075) |
| PKSA-pr6q-2w5s-kzzf | symfony/http-foundation | v7.4.21 | v7.4.23 | path rules in front of PHP bypassed via script name |
| PKSA-nxjs-s6hn-vb6m | symfony/http-foundation | v7.4.21 | v7.4.23 | `private_ranges` trusted-proxy shortcut trusts mapped addresses |
| PKSA-b2gg-vk1g-6d5q | symfony/http-foundation | v7.4.21 | v7.4.23 | generated URLs can point to a client-chosen host |
| CVE-2026-118516 (PKSA-y8c5-1b7f-r1gj) | symfony/routing | v7.4.15 | v7.4.23 | host placeholders can match the start of the path |
| PKSA-3gxv-7xhq-mgmz | symfony/http-kernel | v7.4.16 | v7.4.23 | inline fragments let forwarded headers set the main request's URL host |
| PKSA-g499-cm4q-v35y | symfony/filesystem | v7.4.15 | v7.4.23 | `Path` treats any `prefix://` as a root |

## 3. Change

`composer update symfony/http-foundation symfony/routing symfony/http-kernel
symfony/filesystem --with=<pkg>:7.4.23 … --no-scripts --no-plugins` (rule 87:
lock-only, no scripts or plugins): **0 installs, 4 updates, 0 removals**, only
`apps/platform/composer.lock`. Patch releases within the 7.4 line; no
application code changed.

## 4. Proof

- `composer audit --locked`: no advisories.
- Local smoke: `tests/Feature/Http`, `Routing`, `Auth` — 154 passed.
- The exact-SHA `CI gate` (full sharded suite + supply chain) is the
  canonical regression for publication (ADR 0072 §28.8).
