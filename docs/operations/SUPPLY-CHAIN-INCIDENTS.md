# Supply-chain incident runbooks (ADR 0052 §3.20, Phase 0O.6A)

Common rules for every case below:

- **No automatic production rollback.** Every production change is an
  operator decision through the maintenance-window runbook, deploying a
  re-verified digest.
- Stop promotion first: no PUBLISHED digest is promoted and no maintenance
  window opens while an incident is open.
- Evidence (SBOMs, scans, provenance, verification records) is Confidential;
  share it on a need-to-know basis. Never paste a secret, key or exploit
  payload into a ticket, exception record, log or commit.
- Record the incident, each decision and its approver outside the
  repository (the deployment's incident record); commit only the resulting
  reviewed changes.

## 1. Compromised dependency (a malicious or hijacked Composer, npm or Python package — or an upstream PHP/curl/libxml2 release of the custom PHP runtime)

1. **Identify exposure** from the retained SBOMs: which digests contain the
   package/version (`jq` over `images/*/sbom.spdx.json` of the current and
   rollback-candidate evidence). The lockfiles show which commits pinned it.
2. **Stop promotion** of every digest containing it.
3. **Assess**: install-time code does not run in our builds (no Composer
   plugins or scripts, npm `ignore-scripts`, Python wheels only), so the
   risk is the package's runtime code (or, for npm build tools, the bundled
   assets). Treat a runtime-reachable malicious version as a production
   compromise: rotate every credential the affected process could read
   (ADR 0050 secret inventory).
4. **Fix forward in a reviewed change**: pin a known-good version (update
   the lock; for Python regenerate `requirements.lock` with pip-compile and
   hashes; for PHP/curl/libxml2 follow `CUSTOM-PHP-RUNTIME.md` — new pinned
   version, checksum and signature), never `audit fix` in CI. Re-qualify (complete regression +
   evidence) and promote the new digest.
5. If a rollback candidate predates the compromise, it may be deployed after
   re-verification (`RELEASE-QUALIFICATION.md` → Rollback).

## 2. Compromised CI action

1. **Freeze** the release-qualification workflow (disable it) and stop
   promotion.
2. Every action is pinned to a commit SHA: identify whether the malicious
   commit is one of the pinned SHAs (`git log -p .github/workflows`). A
   compromised tag does not affect a SHA pin; a compromised pinned commit
   does.
3. If a pinned SHA is affected: treat every artifact qualified by a run that
   used it as untrusted — do not promote it; re-verify deployed digests from
   evidence produced before the pin changed. CI holds no registry credential,
   no signing identity and no repository secret, which bounds what a
   compromised action could take; check the run logs for exfiltration
   attempts anyway.
4. Pin a known-good SHA (or remove the action) in a reviewed change;
   security-sensitive action changes are never auto-merged. Re-qualify.

## 3. Leaked signing key or compromised signing identity

*(No production custody exists yet; this runbook applies once a deployment
configures one. The per-run test key is destroyed after each run and is
never trusted for promotion.)*

**External key (KMS/HSM/offline):**
1. Disable the key version in the KMS (or revoke the offline key); stop
   promotion.
2. Create a new key; update `artifact-policy.json` → `signing.public_key_ref`
   in a reviewed change.
3. **Re-sign** the current release and rollback candidates: re-verify each
   digest's evidence first (the evidence must still pass with everything but
   the old signature), then sign with the new key; re-publish signatures and
   attestations.
4. Audit the registry and deployment logs for any digest signed by the old
   key after the suspected leak; treat those digests as malicious (runbook 4).

**Keyless (OIDC identity):**
1. Review which certificate identities signed what (transparency-log search
   for the policy's `certificate_identity`); stop promotion.
2. Tighten the expected identity (exact workflow path and ref) and issuer in
   `artifact-policy.json`; fix the protected-environment reviewers or branch
   protection that allowed the misuse.
3. Treat digests signed by an unexpected identity as malicious (runbook 4);
   re-sign legitimate releases from a clean, reviewed run.

## 4. Malicious or incorrect artifact

1. **Stop promotion** of the digest; if it is deployed, decide (operator)
   whether to redeploy the **last good digest** — re-verified — through the
   maintenance-window runbook. Never rebuild an old commit as a "rollback".
2. Preserve the artifact, its evidence and the registry audit trail.
3. Compare the artifact's evidence with the source: `verify-artifact`
   (digest, signature, provenance, lineage, base digests against the
   Dockerfile at the commit). A mismatch means the artifact did not come from
   the qualified run — escalate as a compromise (rotate runtime secrets the
   image could read).
4. Fix the cause in a reviewed change and re-qualify.

## 5. Critical CVE after deployment

1. The daily re-scan (or an advisory) reports a new blocking finding for a
   deployed digest. Confirm reachability: which image, package, process; is
   a fix available (a newer base digest, a dependency update)?
2. **Decide** (operator + security):
   - **rebuild with the fix** — a reviewed base-digest or dependency update,
     full re-qualification, promotion, maintenance-window deployment; or
   - **accept temporarily** — a time-bounded exception (≤ 30 days for
     Critical/High) with a compensating control and an approval reference,
     until the fix exists. Only while **no fix** exists: once a fix appears
     the evaluator blocks the finding again (`high_fix_available`,
     `superseded_exception`), and the answer is the rebuild.
3. Rollback candidates with the same finding are marked accordingly in the
   incident record; a rollback to them needs the same judgement.
4. Never silence a finding by editing a scanner report: reports are kept
   unmodified and exceptions are applied by the evaluator.
5. A genuine CRITICAL is never risk-accepted (owner decision R5, Phase
   0O.6B): only an evidence-backed `false_positive`/`not_affected` record
   (component absent, or vulnerable functionality genuinely unavailable) may
   stop it blocking. When the vulnerable library is linked by a required
   runtime (as libcurl/libxml2 are by the official PHP binary) and the
   distribution has no fix, the options are waiting for the distribution,
   building the library from fixed upstream sources, or a base-family change
   — each an owner decision (`docs/security/release-remediation/`).
