---
name: closure-audit
description: Checklist and method for a Lycenza programme or phase closure audit (e.g. FEE.5, OPF.5, a future RES.5) - architecture direction, seams, schema/RLS, retention, authorization, legal gates, docs drift, deferred scope, regression - ending in PASS / PASS WITH CORRECTION / BLOCKED. Use when the user asks to close, audit or declare a programme complete.
---

# Closure audit

A closure audit verifies the repository against its ADR. It does not trust
earlier completion reports. It starts **read-only**. Correct something only
when it restores an already-adopted invariant without new scope, an owner
decision or a legal conclusion; otherwise report it as a blocker.

## 1. Preflight
- `git fetch`; record the fetched SHA.
- Check `HEAD` / `main` / `origin/main`, a clean tree, the worktrees, the
  stashes and any unmerged branches.
- Leave unrelated state untouched.

## 2. Fan out (parallel, read-only agents)
Give each agent exact files and ask for `file:line` evidence, marked
OK / DEFECT / SILENT:

| Agent | Covers |
|---|---|
| Database / RLS / retention | every table: PK, unique keys, composite same-School foreign keys (RESTRICT/CASCADE), `TenantRls::enable`, `makeAppendOnly`, runtime privilege revokes and the verifier lists, triggers, `down()`; the pinned forced-RLS count; `TenantRetentionCatalog` category; `RetentionAnchors::TABLES`; Student classification; dependency_blocked behaviour; retention-function amendments; `UserReferenceCatalog` for every user foreign key |
| Authorization / API | routes, middleware (`capability:`, `idempotent`, `mfa`), controller checks, thin controllers, OpenAPI and shared-types parity, seeded role grants, capability-catalogue tests; allow / deny / cross-School tests for **every** endpoint (rule 13) |
| Docs / legal / deferred scope | ADR vs roadmap, module docs and DOMAIN-MAP (stale "not implemented", wrong capability names, layer-rule exceptions not recorded); the ADR 0058 register rows; any wording that claims legal clearance; deferred scope not partially built |
| Tests / concurrency / idempotency | test inventory; that each concurrency test uses real processes with an observed lock wait; the idempotency matrix (database-enforced, not convention); what the architecture guard really scans; contracts with no direct test |

Verify the agents' load-bearing claims yourself. Check documentation-drift
claims with grep before acting on them.

## 3. Checks that recur, and gaps found before
- **Architecture:** the guard's allow-lists match exactly; no reverse
  dependency (consumer to source module); the layer rule is respected, and any
  exception is recorded in the ADR and DOMAIN-MAP (rule 15).
- **Rule 28:** a raw-SQL RLS isolation test exists for every new tenant table
  (`Tests\Concerns\AssertsTenantRlsIsolation`), not only Eloquent scoping.
- **Rule 18:** `TenantRls::disable()` is called in `down()`.
- **Retention:** every table is catalogued; links to evidence are anchored;
  any table referencing `charges` amends the Finance expiry function.
- **Legal:** each gate stays as recorded. Development closure is never
  production clearance.
- **Deferred scope:** grep the slice's commits (`git diff --stat <base>..<head>`)
  for partial implementations.

## 4. Gates and publication
- `/rollback-proof <n>` for the programme's migrations, if any changed.
- `/full-regression`, which is mandatory at closure: 0 failures, and only
  known skips.
- `/publish-gate --require-regression`.
- Closure docs:
  - the ADR status, plus a dated closure section that keeps history;
  - the roadmap row and checkpoints;
  - an ADR 0058 note.
- Commit, push and verify. Update the memory regression counter and the
  programme memory.

## 5. Report
The verdict is one of: **PASS** / **PASS WITH CORRECTION** / **BLOCKED**.

Include:
- the conformance per decision;
- the final matrices (architecture, authorization, database, retention,
  idempotency, concurrency, API);
- the corrections, each with root cause and proof;
- the regression counts;
- the legal and production gates;
- the next phase (never started automatically).
