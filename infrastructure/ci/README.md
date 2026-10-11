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

## Lanes

| Lane | Job | Required when |
|---|---|---|
| `secrets` | Secrets scan (gitleaks, full history) | always |
| `supply_chain` | Supply chain (lockfiles, audits, release tooling) | always |
| `integrity` | Repository integrity (whitespace, CI tooling tests) | always |
| `images` | Production images | platform, Gateway, image/release inputs, strict |
| `platform` | Laravel (apps/platform): Pint, Larastan, frontend, **full suite via `bin/safe-test`** | platform, Gateway, contracts, image/release inputs, strict |
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

## Publishing (until E03.2 activates the ruleset this is discipline, not enforcement)

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
(E03.2) cannot. It is defence in depth, never E03's closure.
