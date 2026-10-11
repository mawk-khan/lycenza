# ADR 0072: Main Branch Protection & Publication Control Contract (E03.0)

- Status: **Accepted — E03.0 contract (2026-10-11). E03 — CONTRACT
  PUBLISHED / CI REPAIR REQUIRED.** Nothing on GitHub is configured by this
  ADR: no ruleset, branch rule, workflow, hook or repository setting is
  created or changed.
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
