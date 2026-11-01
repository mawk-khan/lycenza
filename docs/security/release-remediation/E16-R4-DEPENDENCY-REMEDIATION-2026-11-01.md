# E16-R4 — `symfony/mime` and `league/flysystem` dependency remediation (2026-11-01)

**Status: REMEDIATED (dependency-only).**
- **What:** the two non-blocking Composer advisories recorded by the E16-S2
  review move to the smallest fixed releases.
- **Why now:** the owner deferred them to their own slice on 2026-11-01
  ([E16-S2 decision](0O-E16-2026-11-01-owner-security-decision.md) §7).

**Naming.** `E16-R<n>` is the repository's remediation sequence:
- R1, 2026-10-11 — supply-chain remediation;
- R2 — nginx, held, then retired on 2026-10-26;
- R3, 2026-11-01 — Gateway CPython 3.14.10.

This slice is therefore **E16-R4**.

**Separate from the High exception set.** E16-R4 does not touch
`vulnerability-exceptions.json`:
- `OWNER-0O-E16-2026-11-01` keeps its 17 advisories and 119 records;
- it stays valid **through 2026-11-28**, with the hard stop on
  **2026-11-29**;
- the next successor review is still planned for **about 2026-11-20**.

The two advisories here were never exception records: Medium and Low never
block.

## 1. Baseline

**Repository**
- `main` = `origin/main` = HEAD = `af1ca5fa` (E16-S2). The tree was clean,
  with one worktree. The historical stash is untouched.
- The `main` CI run 41296901830 was green.
- `ddev env-check`: PASS.

**Lockfile**
- `composer validate --strict`: valid.
- `composer audit --locked` reported exactly the two advisories below.

## 2. Findings, reproduced on `af1ca5fa`

| Advisory | Package | Installed | Affected | Fixed (smallest) | Severity |
|---|---|---|---|---|---|
| CVE-2026-45070 (PKSA-wtxr-p26d-nn42): email header injection via non-token characters | `symfony/mime` | v7.4.16 | 7.4.x `<7.4.22` (and older trains) | **v7.4.22** | Medium |
| CVE-2026-102601 (PKSA-w9tt-7782-78jx): `WhitespacePathNormalizer` control characters (`CorruptedPath`) | `league/flysystem` | 3.35.2 | `<=3.35.2` | **3.35.3** | Low |

## 3. Dependency graph

Both packages are **transitive and framework-managed**; neither is in
`composer.json`.

| Package | Required by | Constraint |
|---|---|---|
| `symfony/mime` | `laravel/framework` v13.30.0 | `^7.4.0 \|\| ^8.0.0` |
| | `symfony/mailer` v7.4.15 | `^7.2\|^8.0` (conflicts `<6.4`) |
| `league/flysystem` | `laravel/framework` v13.30.0 | `^3.25.1` |
| | `league/flysystem-aws-s3-v3` 3.35.2 (direct, `^3.0`) | `^3.10.0` |
| | `league/flysystem-local` 3.31.0 | `^3.0.0` |

## 4. Change

**Command:**
`composer update symfony/mime league/flysystem --with=symfony/mime:7.4.22 --with=league/flysystem:3.35.3 --no-scripts --no-plugins`.

The dry run and the real run both showed exactly **2 lock operations**.

| Package | Before | After | Why changed |
|---|---|---|---|
| `symfony/mime` | v7.4.16 | **v7.4.22** | CVE-2026-45070 |
| `league/flysystem` | 3.35.2 | **3.35.3** | CVE-2026-102601 |

**What did not change:**
- every other locked package;
- `composer.json`, and the lock `content-hash`;
- the adapters: `flysystem-aws-s3-v3` stays 3.35.2 and `flysystem-local`
  3.31.0;
- nothing was downgraded, and no Symfony train changed (the 7.4 line
  stays).

**Upstream changes reviewed** (repository compare views):
- **`symfony/mime` v7.4.16 → v7.4.22: hardening and bug fixes only.**
  - It rejects header names and parameter names with non-token,
    non-printable or non-ASCII characters, and never emits a raw control
    character in a header.
  - It escapes quotes and backslashes in quoted parameter values and in
    `Address::getEncodedName()`.
  - It rejects an unquoted `@` in an address's local part.
  - It fixes unserialize and serialize handling of parts, and no longer
    drops the last line of a resource-backed message.
- **`league/flysystem` 3.35.2 → 3.35.3:** in the core package,
  `WhitespacePathNormalizer` reports control characters and broken UTF-8
  as a corrupted path. The adapter changes in the same monorepo tag ship
  in the adapter packages, which were not updated.

## 5. Surface audit

### 5.1 MIME and mail

**Construction only.** Lycenza **builds** MIME messages only, in
`App\Support\Email\Providers\SmtpEmailProvider`. Nothing parses inbound
MIME: provider events are JSON webhooks, and no `RawMessage` or parser is
used.

**Headers** (all server-built):

| Header | Source |
|---|---|
| From | `notifications@MAIL_SENDING_DOMAIN`, display name through `HeaderValue::displayName()` (control and line-separator characters replaced, length-capped, `<>@",;\` removed from the fallback) |
| To | the canonical recipient address (`EmailNormalizer`) |
| Subject | `HeaderValue::subject()` (same control-character stripping) |
| Message-ID | server-generated |
| `Auto-Submitted: auto-generated` | fixed; the only additional header, critical mail only |

No request field becomes a raw header. There is no Reply-To, Cc or Bcc (ADR
0055).

**Attachments** (Communications email deliveries):
- the storage path is server-built (`TenantStoragePath::for()` plus a
  UUIDv7 key);
- the display name is `safe_display_name` (basename only, sanitized);
- the content type is **sniffed server-side** (`UploadedFile::getMimeType()`,
  Symfony's `MimeTypes`/finfo guesser) and checked against an allow-list,
  never the client's claim.

**The other `symfony/mime` path** is that MIME-type guesser, used by
`DocumentService` and `CommunicationAttachmentService` uploads and by the
CSV import `mimetypes:` validation.

### 5.2 Flysystem and storage

**Consumers:**
- Documents: Student and Applicant documents, issued certificates and the
  generic owners (`DocumentService`, `DocumentReadService`);
- Communications attachments (staff and the Guardian portal);
- email attachment streams (SMTP and SendGrid providers);
- retention: `ObjectDeletion`, `StorageOrphanReaper`;
- operations: `StorageInspector`, `RestoreValidator`, readiness probing.

**Paths (re-proven).** Every written path is `TenantStoragePath::for($school,
…)`, which:
- prefixes `schools/{id}/`;
- refuses an empty or absolute path, empty, `.` and `..` segments, NUL and
  URL-decoded `..`.

The fragments are built server-side:
- Documents: `documents/{owner type}/{owner id}/{UUIDv7}.{sniffed
  extension}`;
- Communications: `{prefix}/{key}.{extension}`.

A raw upload file name never reaches a path.

**The one caller-supplied fragment:** the HR Employee document **metadata**
endpoints (`storage_path`, validated by `TenantStoragePath`).
- This audit found **no Flysystem read, write or delete** of
  `employee_documents.storage_path`. It is compared only in the orphan
  reaper's database query.
- `TenantStoragePath` does not refuse control characters other than NUL.
  With 3.35.3, Flysystem itself now refuses them (`CorruptedPathDetected`)
  if such a value ever reached a storage call.
- **Recorded as an observation, not changed here** (no product change in
  this slice).

## 6. Security scans of the candidate

| Gate | Result |
|---|---|
| `composer audit --locked` | **No security vulnerability advisories found** (both target advisories gone, no new advisory) |
| `composer validate --strict` | valid |
| App image (built from `f8765b46` with the canonical fresh builder; OCI archive) | SBOM: `symfony/mime v7.4.22`, `league/flysystem 3.35.3` |
| Grype 0.100.0 (database 2026-11-01T06:20Z) on that image | **0 Composer-package matches**, 0 Critical. Evaluator against the unchanged E16-S2 file: **PASS** (62 excepted, 0 unused, 0 blocking) |
| npm | unchanged: 16 Moderate, ESLint dev toolchain only (E16-S2 §7). Not part of this slice |
| Gateway | unchanged: no Composer dependency; pip-audit clean at E16-S2 |
| gitleaks | see the completion report and the CI `Secrets scan` |

**Local limitation.** The local `qualify build` stops at
`loaded_image_mismatch` (Docker Desktop's containerd image IDs) after it has
written the OCI archive, which is what was scanned. One retry was needed
after a transient TLS error downloading the PHP source from php.net. The
authoritative image check is the candidate's CI `Production images` lane and
the post-publication *Release qualification*.

## 7. Regression evidence

| Suite (DDEV unless noted) | Result |
|---|---|
| Email | 154 passed |
| Identity (invitation, activation, account recovery) | 199 passed |
| Communications (attachments, email channel, portal) | 506 passed |
| Recovery | 13 passed |
| Documents | 218 passed, **7 skipped locally** (`RequiresObjectStorage`: no S3 server inside DDEV) |
| **Real object storage** (`bin/safe-test`, `SAFE_TEST_ISOLATED=1` + CI's versitygw override): the 8 `RequiresObjectStorage` files across Documents and Retention | **56 passed, 0 skipped** (709 assertions) |
| Admissions | 165 passed |
| Students (incl. Student documents, certificates, compliance) | 522 passed |
| Further suites and CI | see the completion report |

**Object-storage rule (SIS 11.H1).** These tests still skip explicitly
when no server is reachable locally, and they still run in CI. They were
not mocked.

## 8. Post-publication qualification of E16-S2 (historical evidence)

**Recorded here because it postdates the E16-S2 publication.** It was
**not** produced by E16-R4. *Release qualification* run **41296953625**,
on protected `main` `af1ca5fa` (E16-S2), 2026-11-01:

| Image | Digest | Result |
|---|---|---|
| app | `sha256:a94907f19d54b8f70538d9dac8de8a52d039e06b83f4d1d014324183274ccc4f` | **VERIFIED**: 16/16 checks, 62 excepted, 0 blocking |
| ai-gateway | `sha256:e748dfdae3498f1340eff86e330443164c2d19f7af4a29f2f0705e2fc372cc03` | **VERIFIED**: 16/16 checks, 57 excepted, 0 blocking, 0 Critical (CPython 3.14.10) |

**The rest of the run:**
- same-run complete regression passed;
- `verify-images.sh` passed every check, exit 0;
- gitleaks found 0 in the source and 0 in each image;
- Grype database built 2026-11-01T06:20:12Z.

## 9. Scope boundaries

E16-R4 changes no schema, capability, route, UI, frontend dependency,
Gateway, exception record, E16-S2 date or staging/AWS/production resource.
