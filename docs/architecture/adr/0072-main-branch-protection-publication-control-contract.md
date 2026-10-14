# ADR 0072: Main Branch Protection & Publication Control Contract (E03.0)

- Status: **Accepted — E03.0 contract (2026-10-11). E03.1 CI REPAIR &
  EXACT-SHA GATE IMPLEMENTED (2026-10-11, §28). E03.2 ACTIVATED
  `main-protection` (ruleset 24909660, 2026-10-12, §29). E03.3 CLOSURE
  PROOFS PASSED (2026-10-12, §30): E03 — MAIN PUBLICATION CONTROL PUBLISHED /
  CLOSED.** Production-stage PR + independent approval + CODEOWNERS remain
  planned (§16, §17), not active. **Amended 2026-10-14 (§31): CI sharding
  Option A** — the canonical suite runs in deterministic shards; the
  required context `CI gate` and the ruleset are unchanged.
- Date: 2026-10-11
- Programme: **E03 — Main Branch Protection & Publication Control** (ADR 0058
  register row E03; `MASTER-ROADMAP.md`, Phase 0O parked rows).
- Resolves: the *how* of ADR 0058 row E03 (`main` protected) and ADR 0052
  §3.15's "releasable source is a commit reachable from protected `main`".
- Revises (dated, owner decision): the 2026-09-29 roadmap wording that final
  hardening must "restore the pull-request path" (§16, §23).
- Leaves unchanged:
  - ADR 0052 (release qualification, the trust boundary, the vulnerability
    policy and the E16-P1 amendment). Candidate-branch CI runs are
    publication checks, never release artifacts;
  - E16 (`OWNER-0O-E16-2026-10-11`, current through 2026-11-09);
  - every other ADR 0058 register row.
- Evidence: the *E03 — Main Branch Protection & Publication Control —
  Architecture & Readiness Audit* (2026-10-11, baseline `f36714b`,
  read-only). Re-verified at E03.0 start: `HEAD` = `main` = `origin/main` =
  `f36714b`, clean tree, one worktree.

## 1. Context

### 1.1 E03 was a deliberate deferral, not an oversight

- On **2026-09-29** the owner created ruleset `main` (id `24192267`, target
  `~DEFAULT_BRANCH`, enforcement active). Its rules were:
  - `deletion` (restrict deletion);
  - `non_fast_forward` (block force pushes);
  - `pull_request` with **0** required approvals (merge, squash and rebase
    allowed);
  - **no** required status checks;
  - **no** bypass actors (`current_user_can_bypass: never`).
- The owner **removed it the same day** because the pull-request overhead
  was materially slowing active development.
- The E03 evidence merged by PR #1 (`d5d85bc`) was reverted (`5d8dce3`).
- The roadmap then recorded E03 as **GOVERNANCE_REQUIRED — DEFERRED TO
  FINAL PROJECT / PRODUCTION HARDENING**.

That decision was reasonable for its time and is preserved as history. This
ADR does not recast it as a mistake.

### 1.2 New evidence: the E16-R1 publication-order incident

- `ab8e1ae` reached `origin/main` (08:55:40Z) and `29785e1` reached
  `origin/main` (09:01:31Z) on 2026-10-11 **before** E16-R1's final gates
  had finished.
- The local reflog of `refs/remotes/origin/main` records both as
  **`update by push`**. A `git push` therefore ran **from this clone on this
  machine**; it was not a push made elsewhere and fetched later.
- **The initiating process is unknown.** An editor is attached with
  `git.autofetch: true` and `git.confirmSync: false`, so IDE involvement is
  possible, but it is **unproven**. No root cause is assigned.
- The exact published tree was later fully validated (PHASE-0O-READINESS
  §49–§50).

**Classification: a non-blocking process-control deviation demonstrating
that local publication discipline alone is insufficient.** It is why E03 is
started now instead of at final hardening.

### 1.3 Remote state at audit (public GitHub API, 2026-10-11)

- The repository is **public** and owned by a **personal GitHub user
  account** (owner type `User`).
- `branches/main`: `protected: false`; required status checks enforcement
  `off`.
- `rulesets`: `[]`. `rules/branches/main` (effective rules): `[]`.
- No required status checks exist.
- A direct owner SSH push reaches `main`. Force push and deletion have no
  remote protection.
- **Evidence limitations.** The collaborator/write-actor inventory, deploy
  keys, the protected-branch detail endpoint and Actions permissions return
  HTTP 401 without authenticated owner access, and workflow job logs return
  403. They were not read. All three workflows declare
  `permissions: contents: read`, so Actions cannot push.

### 1.4 CI on `main` has never been green (mandatory premise)

- **239** `CI` push runs on `main` since 2026-08-23: **229 failed**, **10
  cancelled**, **0 succeeded**. Only one pull-request-triggered run exists.
- Passing jobs: supply chain, AI Gateway, shared types and (normally)
  production images.
- Three persistent problem areas:

| Area | Finding | Classification |
|---|---|---|
| **Laravel** | The remote PHP suite never reaches execution. `ci.yml` runs `composer install` **before** `cp .env.example .env`. Composer's `post-autoload-dump` runs `artisan package:discover`, which boots with no `APP_ENV`; the application treats that as production, and `ProductionConfigurationGuard::assertSafe()` (`AppServiceProvider.php:66`) correctly refuses to boot. **Reproduced locally.** (Before about 2026-09-25 the job failed earlier, at "Provision database roles".) | **CI sequencing defect, not an application defect.** The guard is correct. |
| **Flutter** | `flutter analyze` has failed since the first run. The mobile skeleton is already documented as unverified (`apps/mobile/README.md`). | Open; diagnosis owned by E03.1 (§8). |
| **gitleaks** | The full-history secrets scan step has failed in CI since 2026-09-27 (see the correction below). | Reproduced locally: **7 historical `generic-api-key` findings in Phase 0O.8A test fixtures** (§1.4.1). Confirmation from authenticated CI logs is still required before any allowlist change (§10). |

#### 1.4.1 gitleaks — correction of the audit finding (E03.0, 2026-10-11)

The read-only audit reported that a local full-history run of the identical
image, configuration and command found **0 leaks**, and inferred an
environmental cause. **That statement is corrected.** Re-run at E03.0 on the
candidate commit, exactly as `ci.yml` runs it (`git /repo --config
/repo/.gitleaks.toml --redact --exit-code 1`, pinned
`zricethezav/gitleaks:v8.28.0@sha256:cdbb7c95…`):
- **439 commits scanned, `leaks found: 7`, exit 1.**
- All 7 are rule `generic-api-key`, all in commit `2c92af358` (Phase 0O.8A,
  2026-09-27T15:33Z), in the test fixtures
  `tests/Unit/Domains/NetDns2DomainResolverTest.php` (1),
  `tests/Unit/Domains/DnsVerificationTest.php` (1) and
  `tests/Feature/Postgres/SchoolDomainDatabaseInvariantsTest.php` (5):
  literal DNS-verification fixture tokens.
- `15b123c0` (2026-09-27T16:18Z, "compute fixture tokens at run time (no
  secret-shaped literals)") removed the literals from the tree; the history
  still contains them.
- **Correlation with CI (public API):** the secrets-scan job **succeeded** in
  run `36323758622` (2026-09-27T13:49Z, `980ecae`, before `2c92af358`) and
  its gitleaks step **failed** in run `36335458288` (2026-09-27T17:02Z,
  `929571e`, the first run containing `2c92af358`). Every later `main` run
  contains it.
- The `/publish-gate` skill already documents these 7 pre-existing
  findings and scans only the new range for that reason.

**Classification:** the CI gitleaks failure is very probably these 7
historical fixture findings, not an environmental defect. It is not yet
confirmed against the CI log (job logs return 403 unauthenticated). The
values are fixture tokens, not credentials; that, too, is to be confirmed in
E03.1 before any repository-side correction.

**Conclusion: remote protection must not be activated until E03.1 makes the
required gate green.** Requiring a check that can never pass would block all
publication.

### 1.5 Why a push-triggered workflow is not protection

`ci.yml` runs on `push` to `main` and on `pull_request`. A workflow
triggered by a push runs **after** `main` has already moved; it can report a
failure but never prevent it. Only a server-side rule on `main` can refuse
an update.

## 2. Owner decisions (2026-10-11)

| # | Question | Decision |
|---|---|---|
| D1 | Require a pull request before `main` changes? | **No**, during active development. **Yes** at production-stage hardening (§16). |
| D2 | Mandatory human approvals now | **0**. At production stage: **at least 1 independent** approval (§16). |
| D3 | Merge queue | **No**, in the current architecture (§15). |
| D4 | Required remote checks | **One aggregate check, `CI gate`** (§6–§7), sourced from GitHub Actions. |
| D5 | Admin bypass | **No configured bypass actor** (§18). |
| D6 | CODEOWNERS | **Later**, at production stage (§17). Not activated in E03. |
| D7 | Local pre-push / publication guard | **Yes**, as defence in depth, after the remote contract works (§20). It never closes E03. |
| D8 | When to tighten | Before E02 final qualification / first production promotion (§16). |
| D9 | Flutter | Fix in E03.1 if narrow; otherwise an explicit conditional mobile lane, never silently ignored (§8). |
| D10 | Canonical regression | Local canonical regression stays authoritative **until remote parity is proven** (§11). |
| D11 | Architecture | **Option D-remote — exact-SHA integration-branch publication** (§3). |

## 3. Architecture — Option D-remote (exact-SHA integration-branch publication)

**Canonical flow:**
1. Development happens away from protected `main` (a detached candidate or
   an `integration/*` branch). Ordinary work is never committed onto local
   `main`.
2. The final candidate commit is pushed to `integration/*` (CLAUDE.md rule
   82's "normal `integration/*` gate").
3. GitHub Actions validates **that exact commit SHA**.
4. The required aggregate check **`CI gate`** becomes successful for that
   SHA.
5. Only then may **that exact SHA** be fast-forward pushed to protected
   `main`.
6. If `main` moved meanwhile, the fast-forward is refused.
7. The candidate is rebased or recreated on the current `main`.
8. The new SHA must obtain new remote checks.

**No unchecked SHA may become `main`.** Because the pushed commit is the
checked commit, the `main` tip is byte-identical to what CI tested. This is
stronger than a squash or rebase merge, which creates a new SHA at merge
time.

**Revised decision (supersedes the 2026-09-29 roadmap wording, dated, owner
decision): remote pre-`main` validation is mandatory; the pull-request
workflow is deferred until production-stage review hardening.**

## 4. Security invariant (normative)

> No commit may become the protected `main` tip unless the required GitHub
> Actions publication checks have passed for that exact commit SHA, the
> update is a fast-forward, and branch deletion/force-push protection remains
> active.

Supporting invariants:
- **I1.** Checks must originate from the expected GitHub Actions
  integration. A commit status set by any other person, token or app cannot
  satisfy the required context.
- **I2.** Local success alone can never authorize `main` movement.
- **I3.** Checks recorded for another SHA, including a pre-rebase parent of
  the same change, can never authorize publication.
- **I4.** A concurrent `main` advance invalidates the pending publication
  attempt (§14).
- **I5.** Force push to `main` is forbidden.
- **I6.** Deletion of `main` is forbidden.

## 5. Required ruleset contract

| Field | Value |
|---|---|
| Name | `main-protection` |
| Target | branch, `~DEFAULT_BRANCH` |
| Enforcement | `active` |
| Bypass actors | **none** (`[]`) |
| Rules | `deletion`; `non_fast_forward`; `required_linear_history`; `required_status_checks` |
| Pull-request rule | **none** during active development |
| Required context | `CI gate` |
| Context source | GitHub Actions (`integration_id` 15368), never "any source" |

The machine-readable contract below is pinned by
`infrastructure/release/tests/test_main_protection_contract.py`. E03.2
applies it and must **read back** the live ruleset; the documentation alone
is never evidence that protection exists.

<!-- e03-ruleset-contract:begin -->
```json
{
  "name": "main-protection",
  "target": "branch",
  "enforcement": "active",
  "conditions": {
    "ref_name": {
      "include": ["~DEFAULT_BRANCH"],
      "exclude": []
    }
  },
  "bypass_actors": [],
  "rules": [
    {"type": "deletion"},
    {"type": "non_fast_forward"},
    {"type": "required_linear_history"},
    {
      "type": "required_status_checks",
      "parameters": {
        "strict_required_status_checks_policy": false,
        "do_not_enforce_on_create": false,
        "required_status_checks": [
          {"context": "CI gate", "integration_id": 15368}
        ]
      }
    }
  ]
}
```
<!-- e03-ruleset-contract:end -->

Notes:
- **Source pinning.** `integration_id` 15368 is the GitHub Actions app. Its
  purpose is to stop a collaborator or API token from satisfying `CI gate`
  with a fabricated ordinary commit status. E03.2 must confirm from the live
  read-back that the source is GitHub Actions, and E03.3 must prove that a
  fabricated status is not accepted.
- **Strict policy.** `strict_required_status_checks_policy` ("up to date
  before merging") governs pull requests. For a direct push, the
  fast-forward rule already requires the candidate to descend from the
  current `main`, and the checks ran on that exact tree, so it is set to
  `false`. E03.2 records the observed behaviour.
- **Linear history.** GitHub requires the repository to allow squash or
  rebase merging before `required_linear_history` can be enabled; E03.2
  checks that setting. The canonical flow never produces merge commits.
- **Production stage** adds a `pull_request` rule (§16); that is a separate,
  later change to this contract.

## 6. Required check architecture — one aggregate `CI gate`

- Exactly **one** required context: the aggregate job **`CI gate`**.
  Individual jobs are **not** separate branch-rule requirements.
- **Why.** Path-class jobs may legitimately skip, and GitHub leaves a
  required context that never reports as pending forever. One aggregate job
  gives one stable context.
- `CI gate` always runs (`if: always()`), depends on every other job, and
  **passes only if every job required for the candidate's path
  classification succeeded, or was explicitly and legitimately skipped by
  the central classifier**. Cancelled, failed, timed-out or unexpectedly
  skipped required jobs fail it.
- **Individual jobs never decide that their own failure is optional**
  (no `continue-on-error` on a gate job, no job-local "skip if broken").
- **Path classification is centralized** in one classifier job (or one
  versioned script it runs), and the classifier itself is tested. Unknown or
  unclassifiable paths fall into the **strictest** lane (fail closed). The
  diff base is the candidate's parent on the current `main`.

## 7. CI gate contents

| Lane | Runs when | Must include |
|---|---|---|
| **Always / common integrity** | every candidate | gitleaks (full history); supply-chain qualification/audit appropriate to the candidate; production image verification where the classifier says images are affected; exact-SHA / checkpoint integrity; whitespace and repository integrity; shared release/policy validation (release tooling tests) |
| **Backend / code** | `apps/platform/**`, `.github/**`, `infrastructure/**`, lockfiles, unknown paths | the Laravel/PostgreSQL **full application suite**, which already contains the authorization and security suites, with every service it depends on (§11) |
| **Gateway** | `services/ai/**`, contracts it consumes | AI Gateway lint, type checks and tests |
| **Shared types** | `packages/contracts/**`, `packages/shared-types/**` | regeneration + type-check of the generated contracts |
| **Frontend** | `apps/platform/resources/**`, frontend config | `vue-tsc` type-check, ESLint, Prettier, build |
| **Mobile** | `apps/mobile/**` | Flutter analysis (§8) |
| **Docs** | only `docs/**` and other documentation paths | never "run nothing" (§12) |

## 8. Flutter decision

Flutter must **not** keep `CI` permanently red.
- **E03.1 first diagnoses** the existing `flutter analyze` failure.
- **If it is a narrow repository/CI defect:** fix it in E03.1.
- **If fixing it needs real mobile-product implementation beyond E03:**
  - move Flutter validation into an explicitly **conditional mobile lane**;
  - do not include it in `CI gate` for unrelated changes;
  - record mobile verification as still open;
  - a change under `apps/mobile/**` still runs Flutter analysis and **cannot
    obtain a green gate by skipping it**.
- The failure is never silently ignored.

## 9. Laravel decision

- E03.1 fixes the CI bootstrap ordering: the testing environment must exist
  **before Composer scripts execute**.
- The implementation follows existing test/bootstrap conventions (rules
  78–80, `tests/bootstrap.php`, the `PHPUNIT_DB_*` overrides). Candidates:
  - create `.env` first;
  - pass an explicit testing environment to the Composer script step;
  - `--no-scripts` followed by explicit application setup, if that matches
    the repository (rule 87 already builds with `--no-scripts --no-plugins`).
- **`ProductionConfigurationGuard` is not weakened.** It is behaving
  correctly.

## 10. gitleaks decision

- E03.1 confirms the cause **from authenticated workflow logs**: that the
  CI findings are exactly the 7 historical `2c92af358` fixture findings of
  §1.4.1, and nothing else.
- gitleaks is **not** removed from the gate, its scope is not narrowed to
  avoid history, and `--exit-code` is not relaxed.
- Required outcome: CI gitleaks green through **a safe, evidenced
  repository-side correction** — for these findings, exact allowlist entries
  (one path + one value, scoped to the one `generic-api-key` rule, rule 87)
  only after each value is confirmed to be an inert test fixture — **or** a
  proven GitHub/environment defect with its own safe correction.
- **No leak suppression without evidence.** History is not rewritten.

## 11. Canonical regression decision

- The current local canonical regression (`bin/safe-test`,
  `/full-regression`) **remains authoritative until remote parity is
  proven**.
- Known difference: remote CI runs PostgreSQL and Valkey only; it has **no
  MinIO/S3**, which the canonical suite and ADR 0052 §3.15 ("real
  PostgreSQL, Redis and MinIO") depend on.
- **The remote job is not yet authorized to replace the canonical
  regression checkpoint.** E03.1 shall:
  1. identify every material environment/service difference between remote
     CI and `safe-test`;
  2. add missing required services (MinIO/S3 where the suite depends on
     it);
  3. prove the same canonical test selection executes (same counts, same
     only-known skips, environment-specific tests reported separately, rule
     82);
  4. prove the remote run produces equivalent regression evidence.
- **Switchover (once authorized and recorded):**
  - the remote exact-SHA full suite becomes the **publication source of
    truth** for code-lane candidates;
  - local canonical regression remains useful fast / pre-publication
    evidence;
  - duplicate 40-minute local **and** remote canonical runs are not
    required without a specific risk reason.
- The switchover is recorded explicitly (a dated ADR section and a CLAUDE.md
  rule 82 amendment). It does not happen implicitly.

## 12. Docs lane and regression cadence

**The docs lane is never "run nothing".** At minimum:
- gitleaks;
- whitespace/integrity;
- release-tooling tests where security decision files are inputs (the
  exception validator reads `docs/security/release-remediation/*.md`);
- the PHPUnit **repository-reading guard tests** that read documentation
  (at least `Feature/Observability/AlertCatalogTest` and
  `Feature/Configuration/CustomPhpRuntimeGuardTest`). E03.1 inventories them
  exhaustively and tags them as one PHPUnit group.

**Cadence compatibility.**
- The existing `0/5` cadence/checkpoint model (rule 82) is preserved where
  it remains meaningful.
- A documentation commit does **not** consume a full canonical regression.
- The gate distinguishes a candidate that:
  - **requires a new canonical run** (code lane);
  - **may rely on a valid existing checkpoint** under repository rules;
  - **is docs-only but still needs the security/document guards**.
- Exact-tree/checkpoint validation stays **fail-closed**: an unclassifiable
  change is code-lane, and evidence for another tree never counts.

## 13. Supply-chain integration (E16)

- E16 is current through **2026-11-09**; a successor decision is required
  before **2026-11-10**. E16 policy is not reopened.
- The gate includes the supply-chain policy so that:
  - a Critical blocks (E16-P1);
  - a fixable High blocks;
  - an expired or invalid E16 decision blocks;
  - a valid no-fix High decision may pass.
- **Stale-status limitation.** A GitHub check result on a SHA never
  expires. A SHA whose `CI gate` passed on 2026-11-09 still shows green on
  2026-11-11, and GitHub rules cannot express check age.
- **Requirement:** no candidate may use a supply-chain result that was valid
  only before the active decision expired. E03.1 defines the mechanism; at
  minimum the publication wrapper (§20) refuses to fast-forward when the
  exception decision in force at the candidate SHA has expired at
  publication time, or when the candidate's `CI gate` run completed before
  the decision now required for publication took effect, and re-runs the
  gate instead.
- **Residual (recorded):** a raw push that bypasses the wrapper could still
  publish a stale-green SHA. Release qualification (ADR 0052) re-evaluates
  exceptions on its own date regardless, so no stale result can qualify a
  release.

## 14. Fast-forward concurrency model

Candidates A and B both validate. A lands first. B's push to advance `main`
from the old parent is refused as non-fast-forward. B must:
- update onto the current `main`;
- receive a **new SHA**;
- re-run the required remote checks.

This is intentional. B's old check state is never reused. The cost is one CI
cycle per rebase.

## 15. No merge queue

**No merge queue in the current architecture.** The repository is
user-owned, and GitHub's merge queue is available to organization-owned
repositories. If ownership moves to an organization this may be
reconsidered. E03.2 confirms availability against the live repository
settings.

## 16. Reviews and pull requests — staged

| Stage | PR required | Independent approvals | CODEOWNERS |
|---|---|---|---|
| **Active development (now)** | **No** | **0** | not enforced |
| **Production-stage hardening** — before E02 final qualification / first production promotion | **Yes** | **at least 1** | required for designated security/release paths (§17) |

- GitHub does not let an author approve their own pull request, and the
  agent pushes as the owner. A required approval therefore needs a **second,
  independent reviewer identity**, which does not exist yet.
- This staged decision replaces the ambiguous 2026-09-29 roadmap wording.

## 17. Production-stage sensitive paths (planned, not activated)

Stronger review is planned for at least:
- `.github/**`
- `infrastructure/release/**`
- `docs/security/**`

To be assessed for the final CODEOWNERS set: authorization/security
migrations (`apps/platform/database/migrations/**` touching RLS, roles,
capabilities and triggers), `apps/platform/app/Support/Authorization/**`,
`apps/platform/app/Support/Tenancy/**`, `infrastructure/release/vulnerability-exceptions.json`,
`artifact-policy.json`, `.gitleaks.toml`, `CLAUDE.md` and ADRs. **CODEOWNERS
enforcement is not activated in E03.0.**

## 18. Admin bypass

- **No configured bypass actor.** The owner is subject to the same rules.
- The owner's ability to administratively edit or disable a repository
  ruleset is a **platform fact**, not an E03 bypass rule, and is never used
  as a routine publication path.
- Mitigation: ruleset drift monitoring (§19), ruleset history / audit
  evidence, and the E03.2/E03.3 read-back.

## 19. Drift detection (designed in E03.1)

A lightweight remote-protection drift check detects if:
- `main` becomes unprotected;
- the required `CI gate` context disappears or its source changes;
- force-push blocking disappears;
- deletion protection disappears;
- unexpected bypass actors appear.

E03.1 determines what can be checked **publicly** (unauthenticated
`rules/branches/main`, `rulesets`, `branches/main` were readable on
2026-10-11), what needs **authentication** (bypass detail, collaborators,
deploy keys, Actions permissions), and what runs on a **schedule**. Drift
detection is detection only; it is never treated as stronger than the
protection itself.

## 20. Local defence in depth

- **Owner decision:** add a local pre-push/publication guard after the
  remote contract works. It refuses a direct push to `main` when the exact
  candidate SHA has no successful remote `CI gate` (and applies §13's
  freshness rule).
- **Limits, recorded:** a local hook is bypassable; not every clone contains
  it; **it never closes E03**.
- **Standard candidate development:**
  - a detached candidate or an `integration/*` branch;
  - ordinary feature work is never committed directly onto local `main`;
  - a publication wrapper verifies the remote state before the final
    fast-forward.
- **IDE configuration is not changed** by E03.

## 21. GitHub platform semantics (external confirmation, 2026-10-11)

GitHub's documentation (*About protected branches* and *Available rules for
rulesets*, fetched 2026-10-11) states:
- with required status checks, after the checks pass, commits "must either
  be pushed to another branch and then merged or pushed directly to the
  protected branch" — so required checks can restrict updates **without** a
  pull request, and a commit whose checks passed on another ref can be
  pushed directly;
- "you can select an app as the expected source of status updates … If the
  status is set by any other person or integration, merging won't be
  allowed."

**Documentation is not closure proof.** E03.2/E03.3 must prove the exact
behaviour against this real repository (§25).

## 22. Workflow self-modification — accepted development-stage residual risk

During the no-review stage, a candidate may modify `.github/workflows/**`
and therefore change the CI definition that validates it. This is a **real
residual risk**:
- **accepted for active development**;
- **not acceptable for production promotion**: the planned control is the
  production-stage PR + independent review + CODEOWNERS on `.github/**`
  (§16–§17);
- GitHub's "required workflows" control is not available to a user-owned
  repository.

E03 does **not** claim protection against a malicious repository owner
during development. It protects against accidental, premature or unchecked
publication.

## 23. The revised PR-path decision

The 2026-09-29 roadmap mandate that final hardening must "restore the
pull-request path" is **revised by owner decision (2026-10-11)**:
**development stage uses protected exact-SHA direct publication; PR +
independent review is deferred to production-stage hardening.** The
historical text is kept with a dated correction (`MASTER-ROADMAP.md`).

## 24. Slices

### E03.1 — CI repair (implementation order)
1. Obtain authenticated GitHub log access.
2. Confirm the gitleaks cause from the CI log and correct it safely (§1.4.1, §10).
3. Fix Laravel bootstrap ordering (§9).
4. Diagnose and fix, or explicitly condition, Flutter (§8).
5. Add the `integration/**` push trigger.
6. Add the centralized, tested path classifier (§6).
7. Add the aggregate `CI gate` (§6–§7).
8. Align the remote canonical environment, including MinIO/S3 (§11).
9. Add and test the docs lane (§12).
10. Add exact-SHA / checkpoint handling, including §13 freshness.
11. Add the ruleset drift check (§19).
12. Achieve a fully green remote candidate **and** `main` CI state.

Required checks are **not** activated before CI is green.

### E03.2 — Activation
After E03.1 is green: create `main-protection` exactly per §5 (no PR rule,
no bypass actors, deletion blocked, non-fast-forward blocked, linear history
required, `CI gate` required, source pinned to GitHub Actions if supported).
Read back the live ruleset and effective rules. E03 is **not** called
protected merely because the intended JSON exists in documentation.

### E03.3 — Closure proof (§25)

## 25. E03.3 closure proofs (remote, throwaway commits/branches)

1. An unchecked SHA pushed directly to `main` is refused.
2. A candidate whose `CI gate` is red is refused.
3. A green exact SHA is accepted.
4. The resulting `main` tip equals the tested SHA.
5. A force push is refused.
6. Deletion of `main` is refused.
7. The owner receives the same protection (no bypass configured).
8. A fabricated commit status from an unapproved source cannot satisfy
   `CI gate`.
9. Concurrent `main` movement forces a new SHA and new checks.

No destructive history operations are used.

## 26. Status progression

| After | Status |
|---|---|
| E03.0 | **E03 — CONTRACT PUBLISHED / CI REPAIR REQUIRED** |
| E03.1 | E03 — CI GATE GREEN / REMOTE PROTECTION AWAITS ACTIVATION |
| E03.2 | E03 — MAIN PROTECTION ACTIVE / CLOSURE PROOF REQUIRED |
| E03.3 | E03 — MAIN PUBLICATION CONTROL PUBLISHED / CLOSED |

ADR 0058 row E03 keeps register status **GOVERNANCE_REQUIRED** until E03.3
closes it. **E03 cannot be called CLOSED while the intended remote
protection is only documented and not enabled.**

## 27. Alternatives considered

| Option | Summary | Why not now |
|---|---|---|
| **A** — full PR protection with review | PR + ≥ 1 approval + checks | Reintroduces the overhead removed on 2026-09-29; no second reviewer identity exists; merges create a new SHA. Adopted for production stage (§16). |
| **B** — PR, auto-created, 0 reviews, required checks | Keeps a PR record | Merge creates a new SHA (only the tree matches, and only under the strict policy). **Fallback** if E03.2 cannot prove exact-SHA direct publication. |
| **C** — merge queue | Serialized merges | Not available to a user-owned repository (§15). |
| **D (local only)** — restricted direct push via hooks/tooling | Client-side | Bypassable, does not cover every clone or the IDE; cannot close E03. Kept only as defence in depth (§20). |
| **E** — local workflow discipline only | Status quo | Demonstrated insufficient by E16-R1 (§1.2). |

## 28. E03.1 — CI repair & exact-SHA gate (implemented 2026-10-11)

Repository and CI side only. **No ruleset was created; `main` stays
unprotected until E03.2.** Baseline `9e9c12f`; candidates were validated on
`integration/e03-1-ci-gate`.

### 28.1 Laravel bootstrap (§9)
- **Cause (confirmed remotely):** `composer install` ran before `.env`
  existed, so `package:discover` booted production-like and
  `ProductionConfigurationGuard` refused.
- **Fix:**
  1. Both env files `docker-compose.yml` names (`apps/platform/.env`,
     `services/ai/.env`) are copied from their inert committed examples
     first.
  2. Composer installs from the lock with `--no-scripts --no-plugins` (rule
     87), inside the canonical platform image.
  3. `artisan package:discover` and `key:generate` then run explicitly, as
     the production image does.
- **Unchanged:** the guard.
- **A second CI-only defect** was found on the way. On a fresh runner,
  Compose refused to start because `services/ai/.env` was missing; it is now
  created too.

### 28.2 The backend lane runs the canonical runner itself (§11, §18)
The Laravel job no longer re-creates the test environment by hand
(setup-php on the host, `migrate` + `CapabilityAndRoleSeeder` only). It
runs:
- the same Compose stack and platform image (`platform.Dockerfile`);
- `bin/safe-test --reset-db` (`platform:test-db-reset`, full seed set);
- `bin/safe-test`, the full `phpunit.xml` selection under the same
  fail-closed overrides;
- Pint, Larastan and the frontend checks (type-check, ESLint, Prettier,
  build) in the same job.

**The one declared substitution: the S3 server.** MinIO's public images were
withdrawn in 2026-09, so CI uses versitygw v1.8.0 (digest-pinned) through
`infrastructure/ci/compose.s3-versitygw.yml`, which `bin/safe-test` takes via
`SAFE_TEST_COMPOSE_OVERRIDE`. The service names are unchanged. Its region
must match the app's (`ap-south-1`); a mismatch fails every S3 request with
HTTP 400 (found and fixed in E03.1).

### 28.3 Flutter (§8)
- **Root cause, reproduced with Flutter 3.44.0:** one `unused_import`
  warning in `test/widget_test.dart`. `flutter analyze` treats any issue as
  fatal.
- **Classification:** a narrow repository defect.
- **Fix:** the import is removed and CI pins `flutter-version: 3.44.0`.
- **Result:** analyze reports no issues and the widget test passes,
  remotely green on every E03.1 candidate.
- **Lane:** the mobile lane runs for `apps/mobile/**` and strict
  candidates. Unrelated changes skip it by classification only.
- **Readiness unchanged:** this proves the skeleton analyzes and its one
  widget test passes. The mobile product remains unimplemented.

### 28.4 Triggers, classifier and lanes (§6–§7, §12, §22)
- **Triggers:** `push` to `main` and `integration/**`, and `pull_request`.
  The candidate SHA is the SHA that receives the checks.
- **The one classifier:** `infrastructure/ci/lycenza_ci/classify.py`, used
  by the `Classify changes` job, with an ordered path-class table.
  - Both sides of a rename are classified, and deletions too.
  - The base is the merge base with `origin/main` for a candidate branch,
    the previous tip for a `main` push, and the PR base for a PR.
- **Strict** (every lane except the docs lane that the full suite
  subsumes):
  - `.github/**`, `infrastructure/ci/**`, `docker-compose.yml`,
    `.gitleaks.toml` and other `infrastructure/**`;
  - any unknown path;
  - an empty diff, or no usable base.

  A workflow change can never classify itself as docs-only.
- **Tests:** a test requires every tracked path to have a deliberate class.

| Lane (job) | Required when |
|---|---|
| `secrets` (gitleaks, full history) | always |
| `supply_chain` | always |
| `integrity` (whitespace, CI tooling tests) | always |
| `images` | platform, Gateway, image/release inputs, strict |
| `platform` (full suite, Pint, Larastan, frontend) | platform, Gateway, contracts, image/release inputs, strict |
| `docs` (PHPUnit `repository-documents`) | docs or root Markdown, when `platform` is not required |
| `gateway` | `services/ai/**`, strict |
| `shared_types` | `packages/**`, strict |
| `mobile` | `apps/mobile/**`, strict |

### 28.5 The aggregate `CI gate` (§4–§6, §16)
- **What it is:** the job named exactly `CI gate`.
- **How it runs:** it is `if: always()` and needs all ten jobs. It passes
  only when:
  - the classifier succeeded;
  - every lane the classifier requires ended `success`;
  - every other lane ended `skipped`.
- **Fail-closed cases:** a cancelled, failed, missing or unexpectedly run
  job fails it, and so does a classifier error.
- **No self-excusing jobs:** no job sets `continue-on-error`, and the CI
  tooling tests reject a swallowed failure.
- **Source, observed** on `2ac015a`: check run `114518523578`, `app.id`
  **15368**, `app.slug` `github-actions`. Its annotation names the failing
  lanes.
- **Mutation tests** (`infrastructure/ci/tests/test_gate.py`):
  - every lane's `failure`, `cancelled`, `skipped` or missing result fails
    the gate;
  - a mobile skip passes only when mobile is not required;
  - unknown paths require the strict lanes;
  - a classifier failure or malformed output fails the gate;
  - an always-lane can be neither dropped nor invented.

### 28.6 Docs lane (§12)
- **Members, enumerated by inventory:** the PHPUnit tests that read
  `docs/**` are exactly `Feature/Observability/AlertCatalogTest` and
  `Feature/Configuration/CustomPhpRuntimeGuardTest`, now
  `#[Group('repository-documents')]`. A CI tooling test keeps the group equal
  to the set of documentation readers.
- **What else runs:** gitleaks, whitespace and the release tooling tests
  (which read the decision records) run for every candidate.

### 28.7 Canonical regression parity (§11)

| Dimension | Local canonical (`bin/safe-test`) | Remote candidate (CI) |
|---|---|---|
| Runner | `bin/safe-test` | `bin/safe-test` (same script) |
| PHP | 8.3.33 (`platform.Dockerfile`) | 8.3.35 (same Dockerfile, built fresh) |
| Extensions | identical list (incl. `pdo_pgsql`, `pgsql`, `bcmath`, `gd`, `zip`, OPcache; no `intl`) | identical |
| PostgreSQL | 16.14 (`postgres:16-alpine`) | 16.15 (same tag) |
| Valkey | 8.1.9 (`valkey/valkey:8-alpine`) | 8.1.10 (same tag) |
| S3 | MinIO (cached image) or versitygw | versitygw v1.8.0, digest-pinned |
| Timezone / locale | UTC / C.UTF-8 + C | UTC / C.UTF-8 + C |
| Env | developer `.env` + forced `phpunit.xml` + `safe-test` overrides | `.env.example` + the same forced values and overrides |
| Database reset | `platform:test-db-reset --force` (full seed set) | same |
| Parallelism | none (one PHPUnit process) | none |
| Selection | full `phpunit.xml` (Unit + Feature) | same; no filter, group or shard |
| Exclusions / skips | 1 (ESI-12, PAY-L1) | 1 (the same) |
| Storage | `FILESYSTEM_DISK=s3` → `minio:9000`, bucket `school-os-local`, `ap-south-1`, path style | same |

**Evidence on one tree** (JUnit digests: the sorted `class::method` selection
hash plus per-class assertions):

| Run | Tests | Passed / skipped | Selection SHA-256 | Assertions |
|---|---|---|---|---|
| Local, versitygw (tree `c94331b`) | 8,144 | 8,143 / 1 | `f0477023…a601` | 282,644 |
| Local, MinIO (tree `a9f5378`) | 8,144 | 8,143 / 1 | `f0477023…a601` | 282,943 |
| Remote CI, versitygw (`5ad5288`) | 8,144 | 8,143 / 1 | `f0477023…a601` | 285,756 |

The three trees differ only in CI tooling, which no PHPUnit test reads.

> **Amended 2026-10-14 (§31).** The remote column's *Parallelism* and
> *Selection* rows are superseded: the same full `phpunit.xml` selection now
> runs in four deterministic shards on four runners, and the union of the
> shards is proven equal to the canonical inventory on every run. The local
> canonical column is unchanged. The evidence above stays as recorded.

**Identical selection, identical outcomes.** Every class's assertion count is
identical across all three runs **except** the real two-process
`*ConcurrencyTest` races. Their polling loops assert per iteration, so the
count follows timing (for example `CrossHostHandoffConcurrencyTest`
1,539 / 1,809).
- **The S3 server is irrelevant:** no S3-dependent class differs between
  MinIO and versitygw.
- **Residual drift is patch-level only:** PHP 8.3.33 vs 8.3.35, PostgreSQL
  16.14 vs 16.15, Valkey 8.1.9 vs 8.1.10, all from the same floating tags. A
  developer gets the same by rebuilding.

**Parity: PROVEN (2026-10-11).**

### 28.8 Canonical-regression authority — dated switchover (§11, §12)
**From 2026-10-11, the GitHub Actions full backend regression on the exact
integration candidate SHA is canonical publication evidence.**
- A code-lane candidate (the `platform` lane required) obtains it from its
  own `CI gate` run. No duplicate local full run is required by default.
- The local `/full-regression` remains useful fast or pre-publication
  feedback.
- **Cadence (rule 82), how a candidate proves checkpoint eligibility:**
  - A code-lane candidate carries its own exact-SHA full regression. It is
    a checkpoint, and the counter resets to 0/5.
  - A candidate whose classification needs no `platform` lane (docs or
    developer tooling only) changes nothing the suite executes. It relies on
    the checkpoint of its parent `main` commit, which by induction carries
    an exact-SHA full regression or is itself such a candidate. It still
    runs the always lanes and, for docs, the documentation guards.
  - A strict candidate always runs the full suite.
- **Fail closed:** the evidence is tied to the SHA, and the classifier
  decides from the diff, never from a commit message.

### 28.9 E16 freshness (§13)
- **The supply-chain job:** it now runs `qualify audit --fail-on-blocking`.
  It fails on a Critical, a High with a fix, or an invalid or expired
  exception decision. The policy itself and E16 are unchanged.
- **Stale green:** a green check never expires, so
  `infrastructure/ci/lycenza-ci publication-check` decides immediately
  before `main` is advanced. It refuses unless all of the following hold:
  - the newest `CI gate` check run for **exactly** the SHA, from app
    **15368**, is `success`;
  - that run is at most 72 h old;
  - the exception decision at the candidate (its earliest `expires`) is
    still valid today (UTC) and was valid when the gate ran;
  - remote `main` is a strict ancestor of the candidate.
- **Pushing:** `--push` then fast-forwards `main`, never forcing it.
- **Release qualification** keeps its own date checks.
- **Residual:** a raw `git push` can skip the check until E03.2, and the
  ruleset cannot express check age.

### 28.10 Drift check (§19)
- **Workflow:** `.github/workflows/protection-drift.yml` runs daily and on
  dispatch, read-only, with only the run's own token.
- **Command:** `lycenza-ci drift` compares `rules/branches/main`,
  `rulesets` and `rulesets/{id}` with the §5 contract. It checks:
  - the ruleset exists and is active;
  - its target and branch condition;
  - deletion, non-fast-forward and linear history are enforced;
  - `CI gate` is required from integration 15368;
  - the bypass actors.
- **State gating:** `infrastructure/ci/main-protection-state.json` says
  `awaiting-activation`, so the check reports and passes. E03.2 sets it to
  `active`.
- **Limit:** bypass actors that the caller cannot see are reported
  **UNVERIFIED**, never as passing. An admin-scoped read (E03.2 read-back)
  is needed for them.

### 28.11 Workflow permissions
- **Every workflow:** `contents: read` (`sbom-rescan` adds `actions: read`).
- **Never present:** write permissions, `pull_request_target`,
  `workflow_run`, secrets, and any token that could bypass a ruleset.
- **Enforcement:** `SupplyChainGuardTest` now also covers
  `protection-drift.yml`.

### 28.12 Remote proof on `integration/e03-1-ci-gate`
- **Every candidate:** classifier, supply chain, integrity, Gateway, shared
  types, Flutter and production images green.
- **`2ac015a`:** Laravel and gitleaks red, so `CI gate` **red** (red
  candidate proof: "FAIL: secrets-scan …; platform …").
- **`125d603` and later:** Laravel green, with the full suite remotely for
  the first time (8,144 tests, 1 skip). gitleaks was the only red lane, so
  `CI gate` was red.
- **The publication candidate:** this change, with the §28.14 correction.
  Every required lane is green, so its `CI gate` is green on its exact SHA.
  Only then was `main` fast-forwarded to that SHA, with
  `lycenza-ci publication-check --push`. The SHA and run are recorded in the
  E03.1 completion report.

### 28.13 Residual risks
- **Workflow self-modification (§22):** accepted for development, not for
  production promotion.
- **Local checks:** the publication check and any pre-push hook are
  bypassable until E03.2.
- **Floating tags:** the CI service images and the dev platform base use
  floating tags. That is patch drift, recorded in the parity table.
- **Concurrency races:** their assertion counts vary by design. Parity is
  judged on selection and outcomes, plus exact counts for every other class.

### 28.14 gitleaks — cause confirmed and corrected (§10, §1.4.1)

**Confirmed from authenticated CI logs** (owner `gh` login, 2026-10-11). The
CI job runs gitleaks without `--verbose`, so its log carries the summary
only:

| Run | Commit | Commits scanned | Result |
|---|---|---|---|
| `36323758622` (last before `2c92af358`) | `980ecae` | 296 | no leaks found |
| `36335458288` (first containing it) | `929571e` | 300 | **leaks found: 7**, exit 1 |
| E03.1 candidate `5ad5288` | — | 443 | **leaks found: 7**, exit 1 |

No other error appears in the log. The identical image, configuration and
command at `929571e`, over a superset of those commits, finds exactly 7
findings:
- rule `generic-api-key`;
- all in `2c92af358`;
- `DnsVerificationTest.php:23`, `NetDns2DomainResolverTest.php:20` and
  `SchoolDomainDatabaseInvariantsTest.php:25,169,170,172,174`.

Every finding CI could report lies in a commit the local scan covered, and
both report 7, so the sets are identical.

**Cause:** these 7 historical fixture literals. There is no environmental
defect.

**The values are inert:**
- **The token:** one hand-written 43-character DNS-verification test token,
  used with the fake domain `erp.northfield.org`. Production challenge
  tokens are `random_bytes(32)` per domain, generated at runtime
  (`SchoolDomainService::newToken`). They are never committed, and DNS
  verification involves no external provider credential.
- **The other three:** base64 of fake JSON (`{"iv":"test","value":"test"}`,
  `{"new":"token"}`, `{"after":"verify"}`).
- **Where they exist:** only in `2c92af358` and in its removal by
  `15b123c0`. They are in no production configuration, and no production
  environment exists.

**Correction** (`.gitleaks.toml`): three history-only entries. Each is:
- scoped to the one commit `2c92af358`, one anchored path, and the exact
  value(s);
- under the one `generic-api-key` rule, with `condition = "AND"`.

There is no rule change, no path exemption, no narrowing of the
full-history scan and no history rewrite.

**Guards:**
- `infrastructure/release/tests/test_gitleaks_history_allowlist.py` pins:
  - the commit-scoped entries are exactly those three files;
  - each names exactly that commit;
  - every value exists in that commit and nowhere in the current tree.
- `test_canary_proof.py` proves, with the real gitleaks in git mode, that
  the same value committed again and a new value in the same file are both
  still findings.

**Mutation:** deleting the `commits` scopes fails both tests (it would hide
the same value in any other commit). The file was restored byte-for-byte.

**Result:** the full-history scan with the repository configuration reports
"no leaks found" (exit 0).

**Observation, unchanged:** the pre-existing `docs/operations/README.md`
entry's value is no longer in that file. It is left as it was, presumably
for history.

## 29. E03.2 — Activation & read-back (2026-10-12)

**`main` is protected.** One repository ruleset, created exactly from the §5
block and read back. No other GitHub setting was changed. Baseline
`origin/main = 25b08bfc`, whose `main` push run `38181250610` had a green
`CI gate` (check run `114603272869`, app 15368) before activation.

### 29.1 Before activation
- `GET rulesets` → `[]`; `GET rules/branches/main` → `[]`.
- `branches/main`: `protected: false`; classic branch protection: 404.
- Repository merge settings: merge commit, squash and rebase all allowed.

### 29.2 Activation
- **Request:** the §5 JSON, extracted by `lycenza_ci.contract.load_ruleset()`
  and sent unchanged as `POST /repos/mawk-khan/lycenza/rulesets` by the owner
  (`mawk-khan`, repository `admin`).
- **Result:** ruleset **24909660** (`main-protection`), created
  2026-10-12T05:30:52Z, accepted on the first attempt, with no correction.
- **Response:** identical to the request, plus `source_type: Repository`,
  `bypass_actors: []` and `current_user_can_bypass: "never"`.

### 29.3 Live configuration (authenticated owner read-back)

| Property | Live value |
|---|---|
| Ruleset | `24909660`, `main-protection`, `target: branch`, `enforcement: active` |
| Condition | `ref_name.include: ["~DEFAULT_BRANCH"]`, `exclude: []` |
| Bypass actors | `[]` (0); `current_user_can_bypass: "never"` for the owner |
| Rules | `deletion`, `non_fast_forward`, `required_linear_history`, `required_status_checks` |
| Required check | `{"context": "CI gate", "integration_id": 15368}` (GitHub Actions) |
| Strict policy | `strict_required_status_checks_policy: false`; `do_not_enforce_on_create: false` |
| Pull-request / review / CODEOWNERS / merge queue | absent |
| Effective rules on `main` | exactly those four, each from ruleset `24909660` |
| Effective rules on an `integration/**` branch | none |
| `branches/main` | `protected: true` |

**Platform notes:**
- GitHub accepted the §5 JSON unchanged, including the `integration_id` pin.
- **Linear history:** the repository still allows merge commits. That is
  not a contradiction: the rule refuses merge commits on `main` itself, and
  squash and rebase stay allowed, which GitHub's prerequisite needs.
  Merge settings were left unchanged.
- **Strict policy:** it applies to pull requests. A direct push is held to
  the pushed SHA's own checks, and `non_fast_forward` forces descent from
  the current `main`.

### 29.4 Visibility
- **Unauthenticated (public API):** these show the ruleset, its id,
  enforcement, rule types and every rule parameter, including the check's
  `integration_id`:
  - `rulesets`;
  - `rulesets/24909660`;
  - `rules/branches/main`;
  - `branches/main` (`protected: true`).
- **Withheld from the public:** `bypass_actors` and
  `current_user_can_bypass`.
- **Admin-only:** bypass actors are visible only to an admin-scoped read.
  The owner read (§29.3) is the bypass evidence.
- **Drift without admin:** the check without a token reports the bypass
  property as **UNVERIFIED**, never as passing.

### 29.5 Drift
- **State:** `infrastructure/ci/main-protection-state.json` is now `active`.
  It pins:
  - the ruleset id, name, target, condition and enforcement;
  - the rule types, the required check and its app, and the bypass count.
- **Consistency:** `test_drift.py` keeps the state file equal to the §5
  contract.
- **Hardening:** in `active`, `lycenza-ci drift` now also fails on:
  - a different ruleset id (deleted and recreated);
  - changed status-check parameters;
  - any rule type beyond the contract (for example an added `pull_request`
    rule).
  Mutation-tested: removing each of these checks fails a test.
- **Result:**
  - with the owner token: `ok: true`, no findings, nothing unverified;
  - without a token: `ok: true`, with bypass actors UNVERIFIED.

### 29.6 Sanity proofs (the full §25 suite is E03.3)
- **Unchecked SHA refused.**
  - `92613a0` is an empty throwaway commit on `25b08bfc`. It was never pushed
    anywhere else and has no checks.
  - The owner's normal (non-force) push of it to `main` was refused:
    `GH013: Repository rule violations found for refs/heads/main … Required
    status check "CI gate" is expected.`
  - `main` stayed `25b08bfc`. The owner was refused like anyone else
    (no bypass actor).
- **Integration branches stay deletable.** The disposable
  `integration/e03-1-red-proof` and `integration/e03-1-ci-gate` were deleted.
  Their runs and check runs stay readable by id.
  `integration/e03-1-publication` is kept as the published candidate's
  branch, following repository convention.
- **Exact-SHA publication through protection.** This change is the first
  candidate published under the ruleset:
  - pushed to `integration/e03-2-activation`;
  - given a green `CI gate` on its exact SHA;
  - passed `lycenza-ci publication-check`;
  - pushed unchanged to `main` as a fast-forward.

  The candidate SHA, run and check-run ids, and the old and new `main` are
  recorded in the E03.2 completion report.

### 29.7 Unchanged
- **CI:** CI architecture and lanes, and workflow permissions (all
  `contents: read`; no workflow can push `main`).
- **Policies:** the E16 decision `OWNER-0O-E16-2026-10-11` (current through
  2026-11-09), its exception records and the Critical policy.
  `publication-check` still enforces E16 validity immediately before the
  fast-forward.

### 29.8 Residual risks
- **Owner access:** the owner can still edit or delete the ruleset in
  repository administration. This is a platform fact, not a configured
  bypass. Drift detection notices it afterwards; it does not prevent it.
- **Workflow self-modification (§22):** still accepted for development only.
- **Check age:** the ruleset cannot express it. Freshness and E16 validity at
  publication remain `publication-check`'s job, and a raw push of a green but
  stale SHA is not refused by GitHub.

## 30. E03.3 — Closure proofs (2026-10-12)

**E03 is CLOSED.** Every §25 proof ran against the real remote, with no
change to the ruleset and no bypass. Baseline: `origin/main = a159a96b`, and
ruleset 24909660 was read back unchanged (`updated_at`
2026-10-12T05:30:53Z). Every push was an ordinary `git push` by the owner
(`mawk-khan`, repository admin).

GitHub's rule-suite log (`GET /repos/{repo}/rulesets/rule-suites`) records
each push the ruleset evaluated, rule by rule. Each suite also contains a
platform `secret_scanning` (push protection) evaluation, which passed every
time.

### 30.1 Proof matrix (§25)

| # | Proof | Candidate | GitHub result | Rule suite (failing rule) |
|---|---|---|---|---|
| 1 | Unchecked SHA refused | `92613a0` (E03.2, empty, no checks) | GH013 `Required status check "CI gate" is expected.` | `4475254022` (`required_status_checks`) |
| 2 | Red `CI gate` refused | `96919f4` on `integration/e03-3-red-proof`: run `38220632209`; integrity lane failed; `CI gate` check run `114716259270` `failure` | GH013 `Required status check "CI gate" is failing.`; `publication-check` refuses | `4476946959` (`required_status_checks`) |
| 3 | Green exact SHA accepted | `a159a96b` (E03.2); E03.3 candidate A `2b8c4408` and the closure commit (§30.4) | fast-forward accepted | `4475666821`, `4477687747` (all pass) |
| 4 | Resulting `main` equals the tested SHA | §30.4 | `ls-remote` equals the candidate | — |
| 5 | Force push refused | rewind of `main` to `25b08bfc` (`--force-with-lease`) | GH013 `Cannot force-push to this branch` | `4476902352` (`non_fast_forward` only) |
| 6 | Deletion of `main` refused | `git push origin --delete main` | `refusing to delete the current branch: refs/heads/main` | none (see §30.2) |
| 7 | Owner receives the same protection | all of the above, pushed by the owner | refused | `bypass_actors: []`, `current_user_can_bypass: "never"` |
| 8 | A fabricated status cannot satisfy `CI gate` | `90087a9` (§30.3) | GH013 `Required status check "CI gate" was not set by the expected GitHub app.` | `4476896954` (`required_status_checks`) |
| 9 | Concurrent `main` movement forces a new SHA and new checks | §30.4 | stale candidate refused; rebased SHA needed its own `CI gate` | §30.4 |

**Proof 5 is isolated.** `25b08bfc` has a genuine green Actions `CI gate`
(check run `114603272869`). In its suite, `required_status_checks`
**passed** and only `non_fast_forward` failed.

### 30.2 Deletion — precise result
- **What refused it:** GitHub's built-in default-branch guard. It answered
  before the ruleset was evaluated: no rule suite was recorded for that
  push.
- **The second layer:** the ruleset's own `deletion` rule is active and was
  read back (§29.3, §30.5), but it could not be exercised on `main` in
  isolation. That would mean changing the default branch, which E03.3
  forbids.
- **Conclusion:** `main` cannot be deleted by an ordinary Git push. This is
  shown live, by the platform layer. The ruleset rule is the configured
  second layer.
- **Afterwards:** `main` still existed at `a159a96b`, the default branch was
  still `main`, and the ruleset's `updated_at` was unchanged.

### 30.3 Fabricated status
- **The commit:** `90087a9`, an empty commit pushed to
  `proof/e03-3-fake-status`. No workflow triggers on that ref, so no Actions
  `CI gate` could exist (`check-runs` total 0).
- **The forged status:** the owner token created commit status
  `56122346325`:
  - `context: CI gate`, `state: success`;
  - an ordinary status, not a check run, and not from app 15368.
  The combined commit status became `success`.
- **The push to `main` was refused:** `CI gate` "was not set by the expected
  GitHub app" (the `integration_id` 15368 pin, §5).
- **`publication-check` also refused:** "no `CI gate` check run from GitHub
  Actions exists for exactly 90087a9…".

### 30.4 Concurrency, rebase and the exact-SHA invariant (§14)
<!-- e03-3-race:begin -->
Candidates **A** and **B** were both based on `main = a159a96b`, and each
was validated on its own `integration/**` branch:

| Step | SHA | Evidence | Result |
|---|---|---|---|
| A validated | `2b8c4408` (`integration/e03-3-candidate-a`; CI tooling, instructions, E16 boundary) | run `38220992035`, strict lanes, full suite 8,144 tests (1 known skip, selection `f0477023…`); `CI gate` `114734399705` success, app 15368 | green |
| B validated | `dfcb28f2` (`integration/e03-3-candidate-b`; this closure record, docs lane) | run `38221245366`; `CI gate` `114718272491` success, app 15368 | green |
| A published | `2b8c4408` | `publication-check --push`; rule suite `4477687747` pass | `main` = `2b8c4408` |
| B, as validated | `dfcb28f2` | `publication-check`: "not a fast-forward"; plain push: rejected (non-fast-forward); `--force-with-lease`: GH013 "Cannot force-push", suite `4477694008` (`required_status_checks` **passed**, `non_fast_forward` failed) | refused; `main` unchanged |
| B rebased, unchanged | `aba9993d` (same patch id as B, parent `2b8c4408`) | 0 check runs; `publication-check`: no `CI gate` for exactly this SHA; push: GH013 "CI gate is expected", suite `4477703281` (`non_fast_forward` passed, `required_status_checks` failed) | refused: B's green result never transfers |
| B recreated | this commit (B rebased, plus this table) | its own `CI gate` on `integration/e03-3-closure` | published by fast-forward (completion report) |

**Exact-SHA invariant.** For every E03.3 advancement of `main`, the
integration candidate SHA, the `CI gate` head SHA and the resulting `main`
SHA are the same commit. There is no merge commit, squash SHA or
post-CI rebase, and no other SHA's result is reused.
<!-- e03-3-race:end -->

### 30.5 Final read-back and drift
- **Ruleset read-back:** 24909660 was read again after every adversarial
  test. Every contract field is identical to the pre-test read-back:
  - same id, name, `target: branch` and `~DEFAULT_BRANCH`;
  - `enforcement: active`;
  - `bypass_actors: []`, `current_user_can_bypass: "never"`;
  - exactly `deletion`, `non_fast_forward`, `required_linear_history` and
    `required_status_checks` (`CI gate`, `integration_id` 15368,
    `strict_required_status_checks_policy: false`,
    `do_not_enforce_on_create: false`);
  - no pull-request or other rule;
  - `updated_at` unchanged since activation.
- **`lycenza-ci drift` with the owner token:** `ok`, no findings, nothing
  UNVERIFIED.
- **The `Protection drift` workflow** (its own read-only token; dispatched
  run `38211704682`): `ok`, with bypass actors reported UNVERIFIED, never as
  passing. A removed or weakened public rule fails it. *(Corrected
  2026-10-13, E17 slice: this line originally gave the first scheduled run
  as 2026-10-13 04:41 UTC. The first scheduled run actually ran earlier,
  on 2026-10-12 at 12:26 UTC, on `b42cbbc6` (run `38249014396`, `success`).
  Wording only; no E03 control changes.)*
- **Bypass verification:** the owner's admin read-back remains the
  authoritative bypass check. Actions is deliberately not given admin
  access.
- **Mutation tests** (`infrastructure/ci/tests/test_drift.py`): each of
  these fails drift:
  - a wrong ruleset id or inactive enforcement;
  - a missing deletion, non-fast-forward, linear-history or
    status-check rule;
  - `CI gate` not required, or required from a wrong `integration_id`;
  - any bypass actor;
  - a `pull_request` rule or another extra rule type;
  - changed strict or create settings.

### 30.6 Publication instructions and E16 boundary
- **`/publish-gate`** now describes the protected exact-SHA flow:
  `integration/<slice>` → `CI gate` → `publication-check` → `--push`.
  - It also says the full-history gitleaks scan is expected to pass: the 7
    `2c92af358` fixtures are allowlisted narrowly (§28.14), and any new
    equivalent finding still fails.
  - `test_publication_instructions.py` pins this and CLAUDE.md rule 96.
- **E16 date semantics:** the records' `expires` is the first invalid day.
  `OWNER-0O-E16-2026-10-11` is valid through **2026-11-09** and refused
  from **2026-11-10**.
  - `publication-check` refuses when today ≥ `expires`, the same rule as
    `exceptions.py`.
  - It now also prints `e16_last_valid_day`.
  - A test against the real decision file proves the last valid day passes
    and the expiry day refuses. Changing `>=` to `>` fails that test.
- **Unchanged:** the decision and its 115 records.

### 30.7 Linear history and permissions
- **Linear history:** every E03 publication was a fast-forward with no merge
  commit (`git log --merges` over the E03 range is empty).
- **Merge settings:** the repository still allows merge commits in its
  general settings, but the ruleset keeps them off `main`.
- **Permissions:**
  - every workflow is `contents: read` (`sbom-rescan` adds
    `actions: read`);
  - the repository default token is `read`, and Actions cannot approve pull
    requests;
  - there is no `pull_request_target`, `workflow_run` or secret.
- **Who moves `main`:** only an explicit, authenticated user push.

### 30.8 What closure means
**E03 proves:**
- No ordinary push can advance `main` past the required check, whether
  accidental, manual or by an agent, and including the owner's pushes.
- Unchecked or red SHAs cannot enter `main`.
- Force pushes and deletion are refused.
- History stays linear.
- The check is exact-SHA and pinned to its GitHub Actions source.
- A stale candidate needs a new SHA and a new check after `main` moves.
- Protection drift is monitored.

**E03 does not prove, and does not claim:**
- that the owner cannot administratively edit or delete the ruleset. That is
  a platform fact; drift detection notices it afterwards;
- that a malicious owner cannot change CI and publish their own changed
  workflow during active development (§22);
- that independent review exists.

**Deferred to production-stage hardening**, before E02 final qualification
or the first production promotion, and **not active now**:
- a pull-request rule;
- at least one independent approving reviewer;
- CODEOWNERS on security, release and workflow paths (§16, §17).

The development-stage model is closed as **`integration/**` → exact-SHA
`CI gate` → protected fast-forward of `main`**.

## 31. CI sharding — Option A (owner-selected 2026-10-13; implemented 2026-10-14)

An execution change to the canonical regression, not a change of what it
covers. No application, authorization, tenancy, database or runtime behaviour
changes. The required context (`CI gate`, app 15368), the ruleset (24909660)
and `publication-check` are unchanged.

### 31.1 Decision record
- **Problem:** at 8,198 tests the full suite was one 31-minute PHPUnit
  process. With setup, a code-lane candidate waited about 35 minutes for its
  `CI gate` (run `38405856011`), and the `main` push of the same SHA ran it
  all again (48 minutes, run `38410995811`).
- **Owner decision, 2026-10-13, before implementation: Option A.**
  - Keep the full suite on every code-lane candidate, executed in about four
    parallel shards (target 12–15 minutes).
  - Stop re-running the heavy lanes on the `main` push of a SHA its
    integration run already validated.
- **Rejected: Option B,** the full suite only every fifth checkpoint. It would
  weaken the exact-SHA canonical regression (§28.8).
- **Record:** the design was kept in the owner's working notes (2026-10-13)
  and was not committed before this section. This section is now its
  repository record.

### 31.2 Topology
The `platform` lane became three jobs. Every one is a `CI gate` need, and
`gate.JOB_LANES` maps all three to `platform`.

| Job | Runs | Notes |
|---|---|---|
| `platform-static` | frontend type-check, ESLint, Prettier and build; Pint; Larastan | once per candidate |
| `platform` (matrix `shard: [1, 2, 3, 4]`, `fail-fast: false`) | `bin/safe-test --reset-db`, then `bin/safe-test --shard N/4 --log-junit …`, then `lycenza-ci shard verify` | one runner per shard; check name `Laravel (apps/platform) shard N/4` |
| `platform-coverage` (needs `platform`) | `lycenza-ci shard coverage` over every shard's uploaded plan, inventory and JUnit report | publishes the union `PHPUnit digest` |

- **No other lane changed.** The docs lane, Gateway, images, shared types,
  mobile and the always lanes are as in §28.
- **Shards still build the Vite manifest,** because the suite reads it.
  They do not repeat the frontend checks.

### 31.3 Selection and partition
- **The inventory is the canonical selection,** with no filter, group or
  exclusion: `phpunit --list-tests-xml` under the canonical `phpunit.xml`.
  The known ESI-12 skip (PAY-L1) stays a skip inside its shard.
- **Unit:** a test file. There is one class per file; the planner refuses
  any file that declares more than one, and a tooling test checks the tree.
  A file is never split across shards.
- **Algorithm:** longest-processing-time first by test-case count, data-set
  cases included. Files are sorted by (count descending, path ascending), and
  each goes to the shard with the smallest (load, index). Inside a shard the
  inventory order is kept.
- **Deterministic:** the same inventory always gives the same membership.
  There is no timing data and no randomness.
- **The plan identity** (`plan_sha256`) hashes the membership only.
- **Shard count source:** the matrix list in `ci.yml`, and nothing else. The
  tooling tests require it to be exactly `1..TOTAL`. `TOTAL` reaches the
  runner as `strategy.job-total`.
- **The configuration:** `apps/platform/phpunit.shard.xml` is generated and
  git-ignored. It is `phpunit.xml` with only `<testsuites>` replaced; the
  forced `<php>` block, the bootstrap and everything else are verbatim.

### 31.4 Proof that the shards are the canonical suite
1. **Plan:** `partition()` refuses a plan that is not complete and disjoint.
2. **Each shard** (`shard verify`): its JUnit per-class test-case counts must
   equal its plan exactly. A missing case (a dropped data set, a filter), a
   foreign class, a duplicate id, a failure or error, or a missing or
   truncated report (a PHP fatal error) fails the shard.
3. **Every run** (`platform-coverage`):
   - shard outputs `1..TOTAL` are all present, exactly once;
   - all shards planned from one identical inventory (`inventory_sha256`),
     and each one's membership is the recomputed deterministic plan;
   - the union of the executed reports is the inventory, every test case
     exactly once, with no failure or error.
4. **Parity:** the union digest (`junit.digest` over all shards: sorted
   `class::name` ids, summed per-class assertions) has the same form as an
   unsharded run's, so `selection_sha256` is directly comparable (§28.7).

### 31.5 Isolation
- **CI:** each shard is a separate GitHub-hosted runner, so it has its own
  Docker daemon, Compose stack, PostgreSQL volume, Valkey, S3 server,
  filesystem, ports and generated `APP_KEY`.
  - Each shard resets its own database with the full seed set
    (`platform:test-db-reset`).
  - `TestDatabaseGuard`, `platform:env-diagnostic`, the forced `<php>`
    values and the `safe-test` overrides apply unchanged.
  - Nothing mutable is shared, so no shard depends on another's order or
    state.
- **Locally:** one shard at a time per Compose project
  (`SAFE_TEST_ISOLATED=1` gives each worktree its own project). The shards
  of one project share its test database, exactly as two concurrent
  unsharded runs always would.

### 31.6 Memory
The 2 GiB PHPUnit `memory_limit` (2026-10-13) is kept unchanged for every
shard. Each shard's `Time: …, Memory: …` line is published in its `PHPUnit
summary` annotation, so per-shard peaks are visible without log access.

### 31.7 Failure semantics (fail closed)
GitHub reports a matrix job's result as `success` only when every leg
succeeded, and `gate.py` requires `success` for each of the three
`platform` jobs.

| Event | Effect |
|---|---|
| Assertion failure | PHPUnit exits non-zero; `pipefail` fails the step and the shard |
| PHP fatal error | non-zero exit; the JUnit report is missing or truncated, so `shard verify` fails too |
| Shard timeout (60 min) | the leg fails; the matrix result is not `success` |
| Service or setup failure | `safe-test` (`set -e`) or Compose fails the step |
| Shard cancellation | the matrix result is `cancelled` |
| Missing shard output | `upload-artifact` uses `if-no-files-found: error`; `platform-coverage` refuses a missing index |
| Coverage skipped (because a shard failed) | a required lane job is `skipped`, which the gate refuses |

### 31.8 Required checks, publication and concurrency
- **Required context:** `CI gate` is unchanged. Shard legs are never
  required contexts, so there is no ruleset change and no aggregate
  ambiguity: the one gate exists only after every shard and the coverage job
  have finished.
- **`publication-check` is unchanged.** It reads the newest Actions-owned
  `CI gate` of exactly the candidate SHA.
- **Concurrency is unchanged** (`ci-<workflow>-<ref>`, cancel in progress).
  - A newer push to the same `integration/*` ref cancels the older run, and
    that run's gate fails for the OLD SHA only.
  - The active candidate's own run is a different run on a different SHA.
  - A cancelled leg of the active candidate makes its gate red, never green.

### 31.9 `main` push: no duplicate heavy lanes
- **When:** on a push to `main`, the classifier asks the public API for the
  `CI gate` check runs of exactly that SHA and their check suites.
- **Rule:** if the newest completed run from GitHub Actions (app 15368) in an
  `integration/**` check suite succeeded, the run requires only the always
  lanes:
  - gitleaks;
  - supply chain, including E16 freshness;
  - integrity.
- **Anything else uses the ordinary path classification:** no run, a red,
  cancelled or unfinished run, another app or SHA, or any API error.
- **No new permission:** the lookup is anonymous and read-only; the
  repository is public.
- **Why this is safe:** the ruleset already refuses a `main` SHA without a
  green `CI gate` (§29). The integration run of that exact SHA is the
  canonical regression (§28.8), so re-running it on `main` added time and no
  assurance.

### 31.10 Reproduction
```
SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test --reset-db
SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test --shard N/4         # the CI shard, same selection rules
SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test --filter 'Class::method'
```
- **Input checks:** `--shard` refuses an invalid `INDEX/TOTAL` and any
  `--configuration`, `--testsuite` or `--list-tests*` argument.
- **The red shard's annotation** names its reproduction command.

### 31.11 Changing the shard count
- **What to edit:** only the matrix list, which must be exactly `1..TOTAL`
  (at most 32).
- **What follows automatically:** membership, verification and coverage.
- **What does not change:** the ruleset.
- **Evidence for the change:** record the new per-shard timings and the
  union `selection_sha256` against the previous run's.

### 31.12 Evidence (2026-10-14)
**Candidate A `0f6d9728`** on `integration/ci-sharding-option-a`, run
`38531287810`: `CI gate` **success** (check run `115747333901`, app 15368).

| Shard | Files / cases | Result | PHPUnit time | Job time | Peak memory | Assertions |
|---|---|---|---|---|---|---|
| 1/4 | 241 / 2,050 | OK | 4:45 | 6:10 | 359.5 MB | 71,278 |
| 2/4 | 242 / 2,050 | OK | 8:03 | 10:04 | 345.5 MB | 77,931 |
| 3/4 | 242 / 2,049 | OK, 1 skip (ESI-12) | 8:07 | 9:54 | 347.5 MB | 57,077 |
| 4/4 | 242 / 2,049 | OK | 7:15 | 8:57 | 351.5 MB | 79,128 |

- **The plan:** every shard planned from inventory `e9362507…` (967 files,
  8,198 cases), with plan `9848445b…`.
- **Coverage** (`platform-coverage`, check run `115747293101`): 4 shards cover
  the inventory exactly once.
  - 8,198 cases: 8,197 passed and 1 skipped (ESI-12);
  - 285,414 assertions;
  - **union `selection_sha256` `b1ebd531…8182`, identical to the last
    unsharded canonical run** (`9c0e147e`, runs `38405856011` and
    `38410995811`), whose tree runs the same tests.
  - The assertion totals differ (unsharded 285,401 / 286,380) only through
    the timing-dependent `*ConcurrencyTest` polling loops (§28.7).
- **Memory:** the highest per-shard peak is 359.5 MB of the unchanged 2 GiB
  allowance. The last unsharded process exceeded 1 GiB.
- **Static checks:** `platform-static` took 3:29.

**Before and after (wall clock):**

| | Unsharded (`9c0e147e`) | Sharded (`0f6d9728`) |
|---|---|---|
| Integration run, push to `CI gate` | 35:47 | 10:31 |
| Laravel job(s) | 35:25 (PHPUnit 30:56) | slowest shard 10:04 (PHPUnit 8:07); fastest 6:10 (4:45) |
| `main` push of the same SHA | 47:35, everything re-run | always lanes only (§31.9) |

The balance is by test count, not time: shard 1 finished about 3.5 minutes
before the slowest. A deterministic weight (for example a committed
per-file cost table) is a possible later refinement under §31.11, never a
timing-based reshuffle per run.

**Red proof** (throwaway `9d45e36c` on `integration/ci-sharding-red-proof`,
run `38531314875`; never published; branch deleted):
- **Assertion failure:** shard 2/4 failed. Its annotation named the test and
  `apps/platform/bin/safe-test --shard 2/4`.
- **`exit(0)` mid-test:** shard 3/4 failed. PHPUnit reported "Premature end
  of PHP process" with exit code 2; `shard verify` would also have refused
  the missing report.
- **Downstream:** shards 1/4 and 4/4 were green; `platform-coverage` was
  skipped; `CI gate` **failure** ("platform … failure; platform-coverage …
  skipped").

**Local reproduction:** `SAFE_TEST_ISOLATED=1 bin/safe-test --shard 1/4`
reproduced shard 1 exactly: the same plan `9848445b…`, 2,050 cases OK, and
`shard verify` passed (PHP 8.3.33, 7:19, 361.5 MB). `--shard 32/32` ran
256 cases OK.
