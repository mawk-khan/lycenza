---
name: full-regression
description: Run the canonical full Lycenza regression (reset test DB + full PHPUnit suite, SAFE_TEST_ISOLATED) on the current candidate tree from a throwaway worktree snapshot, so the main tree stays editable during the ~40 minute run. Use when a slice needs its rule 82 full regression or a closure checkpoint, or when the user asks to run the full suite.
---

# Full regression from a worktree snapshot

**Why.** The isolated test container bind-mounts `apps/platform` of the worktree
that runs `bin/safe-test`. Editing that tree during a ~40 minute run silently
mixes old and new code (this happened during OPF.5). Running from a snapshot
removes the problem and lets work continue.

**What `run.sh` does:**
1. Computes the candidate tree id: tracked changes plus untracked, non-ignored
   files, built in a throwaway index. The real index is untouched.
2. Creates a detached worktree of exactly that tree at
   `.claude/regression/snapshot` (gitignored). It hardlinks `vendor/` and
   `public/build/` (instant, no extra disk) and copies every env file
   `docker-compose.yml` names (`apps/platform/.env`, `services/ai/.env`).
   These are the gitignored inputs the suite needs. Without the Vite manifest,
   every Inertia page test fails with a 500 (seen: 669 failures). If the
   candidate changes `resources/js`, run `npm run build` in the main tree first.
3. Runs `SAFE_TEST_ISOLATED=1 bin/safe-test --reset-db`, then the full suite,
   from the snapshot. The snapshot path always gives the same isolated Compose
   project, separate from the main tree's (rule 81).
4. Writes `.claude/regression/last-result`: `tree`, `base`, `scope`, `status`,
   `duration_seconds`, `summary`, `log`. Then it removes the worktree and keeps
   the Compose project for reuse.

## How to use

```bash
.claude/skills/full-regression/run.sh            # full suite (scope=full)
.claude/skills/full-regression/run.sh --filter=X # forwarded; recorded as partial
```

- **Run it in the background** (Bash `run_in_background: true`). Keep working;
  the main tree may change freely.
- **When it finishes, read `last-result`.** Report tests, assertions, failures,
  errors, skips and duration from `summary` and the log. The one accepted skip
  is the ESI-12 legal deferral.
- **What it proves:** the tree id ties the run to what is published.
  `/publish-gate` checks that the candidate still has the same tree. If you edit
  after the run, the evidence no longer matches, and you must say so or re-run.
- **Investigate every failure** (rule 82). The WSL2 host clock can step and
  flake database-time tests. Re-run the failing class before calling it a
  flake, and report it.
- **Never** run a second full regression at the same time from the same
  snapshot path.

## Cadence (rule 82)

- Run after every 4–5 completed units, sooner after a major cross-domain
  integration, and at every programme closure.
- Record which unit this is since the last checkpoint in the memory
  `regression-counter`.
