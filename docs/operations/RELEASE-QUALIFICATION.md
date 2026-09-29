# Release qualification, promotion and rollback (ADR 0052, Phase 0O.6A)

> **NO PRODUCTION REGISTRY IS CONFIGURED. NO REAL SIGNING IDENTITY/KEY IS
> CONFIGURED. NO PRODUCTION IMAGE HAS BEEN PUSHED. NO PRODUCTION IMAGE HAS
> BEEN PROMOTED.** Steps marked *deploy-gated* need an operator decision and
> infrastructure that does not exist yet; nothing in the repository performs
> them.

Tooling reference: `infrastructure/release/README.md`.

> **O1 (ADR 0058, 2026-09-28).** "Protected `main`" is not yet true: GitHub
> reported `main` as unprotected, with no rulesets. The lineage check
> (`source_not_on_protected_main`) proves ancestry of `origin/main`, never
> branch protection. Before the first production publication or promotion:
> - `main` is protected in reality (a branch protection rule or ruleset;
>   force-push and deletion refused);
> - a **fresh** qualification runs from that protected boundary.
>
> A VERIFIED digest from before protection (such as `c4b1b6c` or the
> current latest, `be69b55`, `PHASE-0O-READINESS.md` §48) is progress
> evidence, not the release to promote. Exceptions must be valid on the
> qualification **and** promotion dates. They are never renewed
> automatically.
>
> **E03 (2026-09-29).** `main` is now protected by the active repository
> ruleset `main` (id `24192267`): deletion and force-push refused, a pull
> request required, no bypass actors (`PHASE-0O-READINESS.md` §49). The
> first bullet above is met. `be69b55` and earlier were qualified before
> protection, so a **fresh** qualification from protected `main` is still
> required. Changes reach `main` only by pull request; never push to it
> directly.
>
> A local `qualify` run needs Docker's **classic** image store. With the
> containerd image store, `docker load` reports the manifest digest and the
> build stage fails with `loaded_image_mismatch`
> (`PHASE-0O-READINESS.md` §40).

## Release order (amends ADR 0050 §14; ADR 0052 §3.19)

1. **Qualify the candidate — BUILT → VERIFIED.** Dispatch the *Release
   qualification* workflow on `main` (or run `qualify all` locally on a clean
   checkout of a commit on `main`). One run: the complete regression, lockfile
   integrity, language audits, source secret scan, fresh-builder OCI builds,
   `verify-images.sh`, SBOM, fresh-database vulnerability scan, image
   secret/history scan, provenance, the signed evidence bundle and
   `verify-artifact`. Both images must end **VERIFIED**. Any new commit —
   including documentation only — re-qualifies.
2. **Verify the supply-chain evidence** you are about to act on:
   `infrastructure/release/verify-artifact --image … --digest … --evidence …`
   against the downloaded evidence artifact (with the configured custody
   once one exists). Missing, malformed, unsigned, mismatched, expired or
   failing → **FAIL**; there is no warn-and-continue.
3. *Deploy-gated:* **publish** — a digest-preserving copy of the OCI archive
   to the authorized registry, signed by the configured identity, with the
   SBOM and provenance attached as attestations (→ PUBLISHED). Pushed is not
   approved.
4. *Deploy-gated:* **promote** — explicit, recorded approval of that digest
   for production (→ PROMOTED). Add it to
   `infrastructure/release/retained-releases.json` (role `current`, the
   previous release becoming a `rollback-candidate`) so the daily re-scan
   covers it.
5. **Only then** open the maintenance window
   (`MAINTENANCE-WINDOW-RELEASE.md`) and deploy the **exact promoted
   digest** (by digest, never by tag).
6. Post-deployment verification (the maintenance-window runbook's step 12).

No maintenance window is ever opened to discover an unsigned or failing
image.

## When qualification fails

`verify-artifact` names the failing check with a bounded code; details are in
the run's `state/<image>.verification.json` (Confidential).

| Code | Meaning / action |
|---|---|
| `vulnerability_policy_blocking_findings` | A Critical, a High with a fix, or a High without a fix and without a valid exception. Update the base digest or dependency in a reviewed change (never `audit fix` in CI), or record a time-bounded exception with a human security approval. |
| `scanner_db_stale` | The Grype database was more than 24 h old at scan time — re-run qualification. |
| `source_not_on_protected_main` | The commit is not reachable from `origin/main`; only protected `main` releases. |
| `test_linkage_mismatch`, `build_linkage_mismatch`, `complete_regression_not_passed` | Tests, build and evidence did not come from one passing run on one commit — re-run the whole qualification. |
| `signature_invalid`, `bundle_file_modified`, `bundle_file_set_mismatch`, `digest_mismatch`, `archive_digest_mismatch` | The evidence or artifact is not what was qualified — treat as a potential incident (`SUPPLY-CHAIN-INCIDENTS.md`). |
| `source_secret_scan_findings`, `image_secret_scan_findings`, `image_config_or_filesystem_findings` | A secret-shaped value in source or in the image — stop; if real, handle as a leaked credential (rotate first). |
| `signing_identity_not_configured` | No custody configured and no test key supplied — expected until a deployment chooses custody. |
| `exceptions_invalid` | The exception file is malformed, an entry expired, or an entry goes beyond its approval (unknown approval reference, missing decision record, unapproved advisory/package, a status other than the approved one, a longer window, different conditions). Renew through a new human decision or remove the entry — never extend a date by hand. |
| `runtime_hardening_evidence_missing`, `runtime_hardening_verification_failed`, `runtime_hardening_evidence_not_for_artifact`, `runtime_hardening_check_missing`, `runtime_security_contract_invalid` | A conditional (`runtime-hardening`) exception without proof, for this exact artifact, that `verify-images.sh` passed every check `runtime-security.json` requires — the conditional exceptions are withdrawn. Fix the image or the contract and re-qualify; never drop the condition. |

## Vulnerability exceptions

Edit `infrastructure/release/vulnerability-exceptions.json` in a reviewed
change: exact advisory id (or alias), image, package, **exact** version,
the severity as reported, reason, compensating control, `approved_by` (the
security approval record), `created`, `expires` (Critical/High ≤ 30 days,
others ≤ 90). The tooling validates the record; the risk decision is human.
Expired entries fail every qualification until removed or renewed.

Since Phase 0O.6F:
- `approved_by` must name an entry of the file's `approvals`. Its decision
  record, committed under `docs/security/`, must state the reference and each
  advisory.
- The approval lists, per advisory, the maximum days, any conditions, and per
  image the exact status of each package.
- `accepted_risk` is only for packages that actually contain the vulnerable
  code; every other matched package is `not_affected`, or `false_positive`
  where the advisory does not apply to the version at all.
- A new advisory, a new package version, a raised severity, or a fix
  becoming available is **not** covered. It blocks and goes to a separate
  review; it is never folded into an existing approval.
- **Current approval (since 2026-09-29):** `OWNER-0O-E16-2026-09-29`
  (`docs/security/release-remediation/0O-E16-2026-09-29-owner-security-decision.md`).
  - It is a new decision after a fresh scan, with the same 12 advisories
    and 97 records.
  - All records expire **2026-10-29**, so the last passing day is
    2026-10-28.
  - The superseded `OWNER-0O6E-2026-09-26` stays history and is no longer
    in `approvals`.
- **Whole-file expiry.** One expired record invalidates the whole file,
  so a replacement decision must land **before** the common expiry.

## Rollback

Rollback redeploys a previously VERIFIED/PROMOTED digest — **never a rebuild
of an old commit**:

1. Take the rollback candidate's retained evidence and run
   `verify-artifact` for its digest (optionally `--rescan-report` with a fresh
   Grype report of its SBOM: `qualify rescan --sbom app=…/sbom.spdx.json`).
2. If the fresh scan shows a new blocking finding
   (`rescan_blocking_findings_require_judgement`), an operator/security
   decision is needed — rolling back to a known-vulnerable digest may still be
   the lesser risk, but it is recorded as an exception, never assumed.
3. Deploy the digest through the maintenance-window runbook. There is **no
   automatic production rollback**.

## Retention (ADR 0052 §3.17)

Keep, at minimum, the current release, the approved rollback candidates and
their complete evidence directories (SBOM, provenance, scan reports,
signature, verification records) for the life of the deployed artifact plus
the incident-response window; the exact period is deployment policy. CI keeps
qualification evidence for 90 days (`retention-days`) — a promoted release's
evidence must be copied to the deployment's evidence store before that. Release
evidence is Confidential operational evidence: never School audit records,
never Platform audit metadata, never a runtime metric label.

## Scheduled re-scan

`.github/workflows/sbom-rescan.yml` runs daily: for each entry in
`retained-releases.json` it downloads that qualification run's evidence,
checks the SBOM describes the listed digest, re-scans it with a fresh
database and reports (`qualify rescan`). It never rebuilds or redeploys. A
blocking result opens the *Critical CVE after deployment* runbook.
