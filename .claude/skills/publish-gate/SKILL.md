---
name: publish-gate
description: Run Lycenza's pre-publication gates on the current candidate tree (Pint, Larastan, whitespace incl. untracked files, gitleaks on changed files with the pinned image and repository allowlist, shared-types drift when contracts changed, and whether the last green full regression ran on exactly this tree). Use before committing and pushing a slice to main.
---

# Publish gate

**Why.** Slices are committed and pushed directly to `main` (owner workflow).
These checks used to be reconstructed by hand every time. `run.sh` runs them
the same way each time and never commits or pushes.

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

## Then publish (the slice's own instructions decide)

1. **Use `--require-regression`** when the slice needs a full regression
   (rule 82 cadence, closure, or the user asked for one).
2. **Run `/security-review`** first for any slice that touches Highly
   Sensitive data, authorization, RLS or money.
3. **Fetch first:** `git fetch origin`, and confirm `origin/main` has not moved
   unexpectedly.
4. **Commit and push to `main`.** The message carries the proof (tests,
   assertions, failures, errors, skips, duration) and the attribution line.
5. **Verify:** `HEAD = main = origin/main` and the tree is clean. Optionally
   scan the commit itself:

   ```bash
   docker run --rm -v "$PWD:/repo:ro" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
     zricethezav/gitleaks:v8.28.0@sha256:cdbb7c955abce02001a9f6c9f602fb195b7fadc1e812065883f695d1eeaba854 \
     git /repo --config /repo/.gitleaks.toml --no-banner --redact --exit-code 1 --log-opts="<base>..HEAD"
   ```

   A full-history gitleaks scan reports 7 **pre-existing** findings in
   `2c92af358` (Phase 0O.8A test fixtures, no longer in the tree). Scan only the
   new range.

**Do not** use this gate to justify skipping a test the slice requires. The
frontend gates (vue-tsc, ESLint, Prettier, build) are not included; run them
when `resources/js` changed (rule 82).
