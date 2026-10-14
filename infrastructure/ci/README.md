# CI publication control (ADR 0072, E03.1)

Repository-side tooling for **exact-SHA publication**. A candidate is pushed to
`integration/**`, the `CI` workflow validates that exact SHA, and only that SHA
is then fast-forwarded to `main`. Standard-library Python, no dependencies,
nothing here pushes on its own, configures GitHub or holds a credential.

| Command | What it does |
|---|---|
| `lycenza-ci classify` | The one central path classifier: which lanes a candidate requires. Used by the `Classify changes` job. |
| `lycenza-ci gate` | The aggregate `CI gate` decision from the workflow's `needs` (env `NEEDS_JSON`). |
| `lycenza-ci publication-check [--candidate SHA] [--push]` | May this exact SHA become `main` now? Prints a JSON verdict; `--push` then fast-forwards `origin/main` to it (never forced). |
| `lycenza-ci drift [--state active]` | Compares GitHub's live `main` protection with the contract (scheduled by `.github/workflows/protection-drift.yml`). |
| `lycenza-ci junit-digest REPORT` | PHPUnit selection digest + per-class assertions (canonical-regression parity evidence). |
| `lycenza-ci shard plan\|verify\|coverage` | Deterministic PHPUnit sharding (ADR 0072 §31): a shard's membership from the canonical inventory, the proof that a shard ran exactly its plan, and the proof that one run's shards cover the inventory exactly once. |

## Lanes

| Lane | Job | Required when |
|---|---|---|
| `secrets` | Secrets scan (gitleaks, full history) | always |
| `supply_chain` | Supply chain (lockfiles, audits, release tooling) | always |
| `integrity` | Repository integrity (whitespace, CI tooling tests) | always |
| `images` | Production images | platform, Gateway, image/release inputs, strict |
| `platform` | Three jobs: `platform-static` (frontend, Pint, Larastan); `platform`, the **full suite via `bin/safe-test --shard N/4`** on four runners; `platform-coverage` (union of the shards = the canonical inventory) | platform, Gateway, contracts, image/release inputs, strict |
| `docs` | Documentation guards (PHPUnit `repository-documents`) | `docs/**` or root Markdown, when `platform` is not required |
| `gateway` | AI Gateway | `services/ai/**`, strict |
| `shared_types` | Contracts -> shared TypeScript types | `packages/**`, strict |
| `mobile` | Flutter (apps/mobile) | `apps/mobile/**`, strict |

**Strict** (every lane except the subsumed docs lane): `.github/**`,
`infrastructure/ci/**`, `docker-compose.yml`, `.gitleaks.toml`, any other
`infrastructure/**`, any unknown path, an empty diff, or no usable base. A
workflow change can never classify itself as docs-only. Developer/agent
tooling (`.claude/**`, `.ddev/**`, `docker-compose.phase*.yml`, `.gitignore`)
needs only the always lanes.

The base is the merge base with `origin/main` for a candidate branch, the
previous tip for a `main` push, and the PR base for a pull request.

**A `main` push re-runs only the always lanes** when the newest completed
GitHub Actions `CI gate` run for exactly that SHA from an `integration/**`
check suite succeeded (CI sharding Option A, ADR 0072 §31). The lookup is
read-only and unauthenticated (the repository is public). Any lookup problem
falls back to the ordinary classification, so it can cost time but never skip
a lane. The ruleset already refuses a `main` SHA without a green `CI gate`.

## Sharded suite (Option A, ADR 0072 §31)

- **Shard count:** the matrix list in `ci.yml` (`shard: [1, 2, 3, 4]`), and
  nothing else. The tooling tests require it to be exactly `1..TOTAL`.
- **Membership:** deterministic, by test file, longest-processing-time first
  by test-case count (`lycenza_ci/shard.py`).
- **Proof:** each shard checks its JUnit report against its plan;
  `platform-coverage` checks all shards planned from one inventory and ran it
  exactly once. Its `PHPUnit digest` annotation (the union) is comparable with
  an unsharded run's.
- **Reproduce a red shard:** `apps/platform/bin/safe-test --shard N/4`; see
  `docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md` ("CI sharding").

## Publishing (enforced on `main` by ruleset `main-protection` since E03.2, 2026-10-12)

```bash
git push origin <candidate-sha>:refs/heads/integration/<slice>   # CI runs on the exact SHA
infrastructure/ci/lycenza-ci publication-check --candidate <candidate-sha>          # JSON verdict
infrastructure/ci/lycenza-ci publication-check --candidate <candidate-sha> --push   # fast-forward main
```

`publication-check` refuses unless: a `CI gate` check run from **GitHub
Actions (app 15368)** exists for exactly that SHA and its newest run is
`success`; it completed within 72 h (`--max-age-hours`); the E16
vulnerability-exception decision at the candidate is still valid today (UTC)
and was valid when the gate ran; and remote `main` is a strict ancestor of the
candidate (a fast-forward). A `CI gate` from any other app is reported and
never counted. Set `GH_TOKEN` for a higher API rate limit (read-only use).

This check can be skipped by anyone with push access; the remote ruleset
cannot. Since E03.2 the ruleset refuses any SHA without a green `CI gate`
from app 15368, any non-fast-forward and any deletion of `main` (ADR 0072
§29). Check age and E16 validity remain this command's job only, because a
ruleset cannot express them. It is defence in depth, never E03's closure.

`lycenza-ci drift` reads `main-protection-state.json` (`active`, pinned
ruleset id `24909660`). Run it with an admin token (`GH_TOKEN=$(gh auth
token)`) to verify bypass actors. Without admin access that property is
UNVERIFIED, never passed.
