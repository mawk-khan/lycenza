---
name: rollback-proof
description: Prove that the most recent N Lycenza migrations roll back and re-apply cleanly (rule 10) on this worktree's isolated TEST database only, with a byte-identical schema-only dump before and after, plus platform:verify-database. Use after adding or changing any migration (including its down()).
---

# Migration rollback proof

**Why.** Rule 10 requires a working `down()`, and every slice that ships a
migration records a rollback and re-apply proof. Doing it by hand risks
running a destructive command against the wrong database (rules 50–54, the
Phase 0C.3 incident). This script refuses unless the target is provably the
test database.

## How to use

```bash
.claude/skills/rollback-proof/run.sh <steps>   # e.g. 2 for a slice that added two migrations
```

**Safety, fail-closed:**
- **Identity checks first.** It runs `platform:env-diagnostic` (the
  fail-closed testing contract), then requires the admin connection's
  `current_database()` to be exactly `school_os_test`. It refuses otherwise.
- **Scope.** Only this worktree's isolated Compose project (`bin/safe-test`
  naming), on `--database=pgsql_admin` (rule 54). It never touches the
  development database.

**The proof:**
1. A schema-only `pg_dump` before.
2. `migrate:rollback --step=<steps>`, then a dump.
3. `migrate`, then a dump.
4. **IDENTICAL** is required between before and after.
5. It reports how many schema lines the rollback removed (a sanity check that
   something was actually rolled back), and runs `platform:verify-database`
   (`failed=0` is required; the operator-evidence row for connection
   encryption is expected outside production).

**Before you run it, confirm that the last `<steps>` migrations are exactly the
slice's.** The script prints them; stop if they are not. A migration whose
`down()` deliberately throws (an irreversible fence, e.g. E21-RH.7) cannot be
proven this way; say so instead.

**Afterwards** the test database is migrated but no longer freshly seeded.
`/full-regression` (or `bin/safe-test --reset-db`) resets it.
