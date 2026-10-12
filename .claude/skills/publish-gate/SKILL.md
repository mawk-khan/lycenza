---
name: publish-gate
description: Run Lycenza's pre-publication gates on the current candidate tree (Pint, Larastan, whitespace incl. untracked files, gitleaks on changed files with the pinned image and repository allowlist, shared-types drift when contracts changed, and whether the last green full regression ran on exactly this tree). Use before committing a slice's candidate and pushing it to integration/** for the exact-SHA `CI gate` (protected main, ADR 0072).
---

# Publish gate

**Why.** These local checks used to be reconstructed by hand every time.
`run.sh` runs them the same way each time and never commits or pushes. They
are pre-publication feedback, not the gate. `main` is protected by the
`main-protection` ruleset (ADR 0072 §29–§30, CLAUDE.md rule 96). Only a SHA
whose GitHub Actions `CI gate` is green can reach it, and only by
fast-forward.

## How to use

```bash
.claude/skills/publish-gate/run.sh                       # every gate; regression evidence reported
.claude/skills/publish-gate/run.sh --require-regression  # also fail unless a green FULL regression ran on this exact tree
```

| Gate | How |
|---|---|
| Pint | `vendor/bin/pint --test` in this worktree's isolated container |
| Larastan | `phpstan analyse` with a scratch `tmpDir` (the repository config otherwise writes `storage/phpstan`) |
| Whitespace | `git diff --cached --check HEAD` over the whole candidate, untracked files included, in a throwaway index |
| Secrets | gitleaks (the pinned CI image) on the changed files, mounted at `/repo` so `.gitleaks.toml`'s path allowlists match |
| Contracts | when `packages/contracts` changed: shared-types regenerate (no drift) and type-check |
| Regression evidence | `.claude/regression/last-result` names this exact tree with `status=0`, `scope=full` |

## Then publish: the protected exact-SHA flow (CLAUDE.md rule 96)

Before you start:
- Run `/security-review` first for any slice that touches Highly Sensitive
  data, authorization, RLS or money.
- `--require-regression` is needed only when the slice's own instructions
  ask for a local full run. Since 2026-10-11 the remote exact-SHA full suite
  in `CI gate` is the canonical regression for a code-lane candidate (ADR
  0072 §28.8).

Steps:
1. **Prepare the candidate away from `main`:** commit on a detached HEAD or
   an `integration/*` branch, never on local `main`. `git fetch origin` first
   and base it on the current `origin/main`. The message carries the proof
   and the attribution line.
2. **Push that exact SHA** to `integration/<slice>`:
   `git push origin <sha>:refs/heads/integration/<slice>`.
3. **Wait for its `CI gate`** (GitHub Actions, app 15368) to be green on
   that exact SHA. If `main` moves meanwhile, rebase. The new SHA needs a new
   `CI gate`; another SHA's result is never reused.
4. **Check publication:** `infrastructure/ci/lycenza-ci publication-check
   --candidate <sha>`. It requires:
   - exact SHA, Actions source, success, at most 72 h old;
   - fast-forward;
   - the E16 decision at the candidate still valid today (its last valid
     day is the day before `e16_earliest_expiry`; see
     `e16_last_valid_day`).
5. **Publish:** `infrastructure/ci/lycenza-ci publication-check --candidate
   <sha> --push`. This is a fast-forward of exactly that SHA, never forced.
   The ruleset refuses anything else (GH013).
6. **Verify:** `git ls-remote origin refs/heads/main` equals the candidate,
   then fast-forward local `main` (`git fetch . <sha>:main`) and watch the
   `main` CI run.

Optionally scan the candidate's own range:

```bash
docker run --rm -v "$PWD:/repo:ro" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
  zricethezav/gitleaks:v8.28.0@sha256:cdbb7c955abce02001a9f6c9f602fb195b7fadc1e812065883f695d1eeaba854 \
  git /repo --config /repo/.gitleaks.toml --no-banner --redact --exit-code 1 --log-opts="<base>..<sha>"
```

The full-history gitleaks scan is expected to **pass**. `2c92af358` holds 7
historical, inert `generic-api-key` test-fixture literals (Phase 0O.8A). They
are allowlisted narrowly: only in that commit, at those exact paths and
values (`.gitleaks.toml`, ADR 0072 §28.14). The same value in any other
commit, or any new equivalent finding, still fails the CI `Secrets scan`.

**Do not** use this gate to justify skipping a test the slice requires. The
frontend gates (vue-tsc, ESLint, Prettier, build) are not included; run them
when `resources/js` changed (rule 82).
