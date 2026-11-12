# HR.R1-C28: WO-13 verifier, runtime role membership, column REFERENCES, register scan (2026-11-12)

**HR.R1 stays OPEN, and this is not the closure audit.** C28 delivers the mandatory verifier checkpoint of C27-D: WO-13 (C22-N1), C26-N1, C27-N1 and C27-N3, plus one finding found while inventorying WO-13 (C28-N2), which the owner chose to fix here. Publication evidence follows in a separate evidence commit.

| | |
|---|---|
| Baseline | `154113c3` = `main` = `origin/main`; `3efcfcdb` (last code) an ancestor; clean tree, one worktree, the historical stash and `integration/e16-r2` preserved |
| Last full suite before C28 | `3efcfcdb`: 11,685 cases, 0 failures, 1 skip (ESI-12); counter 0/5 |
| Authorized | Owner, 2026-11-12: implement and publish WO-13 + C26-N1 + C27-N1 + C27-N3; fix C28-N2 in C28 with a forward migration |
| Unchanged | C27-A to C27-D; every business rule, writer, money path, lifecycle, retention duration; the shared runtime role; no new database identity |

## 1. The four findings and C28-N2

| Finding | Exact source | Current behaviour (before) | Required invariant | Correction | Owner |
|---|---|---|---|---|---|
| **WO-13 / C22-N1** | `HR-R1-C22-WO5-CALENDAR-HISTORY.md` §8, widened by C27 §5 to every HR-family guard | Function bodies hashed by name; no check of the trigger's actual function, its mode, owner, `search_path` or EXECUTE | Each family trigger enabled, bound through `tgfoid` to its pinned function, with the md5 of its whole `pg_get_triggerdef` (timing, events, `UPDATE OF`, `WHEN`, arguments, deferral); each function in `public`, argument-free, unique by name, owned by the schema owner, DEFINER only where approved, `search_path` pinned with `pg_temp` last, no EXECUTE on a DEFINER for the runtime role, the retention identity or PUBLIC; no unclassified trigger | New check `hr_family_trigger_functions`, constants `HR_FAMILY_TRIGGERS` (103), `HR_FAMILY_DEFINER_FUNCTIONS` (4), `HR_FAMILY_INVOKER_SEARCH_PATHS` | Platform, Security |
| **C26-N1** | Audit register: "a role it could `SET ROLE` into is checked only for the schema owner" | Only membership in the schema owner checked | `school_os_app` a member of no role, directly or indirectly (any grant row, ADMIN included), and held by no role, except the schema owner WITH ADMIN only | New check `runtime_role_memberships_none` (`pg_auth_members` both ways + `pg_has_role(…, 'MEMBER')` over every role). The exception exists because on PostgreSQL 16 a non-superuser CREATEROLE creator holds exactly that and cannot revoke it (managed PostgreSQL). ADMIN confers no privilege directly; its holder could grant itself SET or INHERIT, which the check then reports, and the schema owner already owns everything | Platform |
| **C27-N1** | "`hr_family_runtime_privileges` reads REFERENCES with `has_table_privilege`, so a column-level REFERENCES grant passes" | Reproduced: with `GRANT REFERENCES (id) ON employees`, `has_table_privilege` is false, `has_any_column_privilege` true | REFERENCES read per column | REFERENCES moved to `has_any_column_privilege` | Platform |
| **C27-N3** | "The Payroll register's `lazy` lookbehind also skips `$this->employmentRecord->...` inside a Payroll model" | Reproduced: a planted `$this->employmentRecord->employee_id` in `PayrollRunResult` passed HEAD's register (6/6 green) | A relation hop from `$this` counts | Only `$this->employees` (the injected HR retention reader) is excluded | Payroll, HR |
| **C28-N2** (new) | Found in the WO-13 inventory | 40 INVOKER guard functions of the family set no `search_path` and name tables without a schema; `school_os_app` holds TEMP (the database's default grant to PUBLIC); PostgreSQL searches `pg_temp` first. **Reproduced:** as the runtime role, three empty temporary tables named `leave_requests`, `leave_policies`, `leave_ledger_entries` let `leave_types_freeze` allow a paid type in use to become unpaid | A guard reads the real tables whatever the caller's session holds | Migration `2027_02_17_090000`: `ALTER FUNCTION … SET search_path = pg_catalog, public, pg_temp` on exactly the 40 (no body, mode, owner or grant change); pinned by WO-13's check | HR, Platform |

**Severity of C28-N2: LOW**, by precedent (PAY-14: a runtime-role raw-SQL route around a guard, with no application path, was LOW). The application never creates a temporary table; the route needs raw SQL as `school_os_app`. It is still a real defeat of database backstops that are meant to hold against exactly that, which is why it is fixed now.

## 2. The trigger inventory (live catalogue, test database)

103 triggers on 44 of the 56 family tables (the other 12 have none), bound to 61 functions; all enabled (`O`) and owned by the schema owner.

| Kind | Functions | Triggers | Mode and path |
|---|---:|---:|---|
| Retention delete guard (E21) | 1 (`retention_guard_retention_delete`) | 21 | DEFINER, `pg_catalog, pg_temp`, no EXECUTE |
| Account-link history and its approved correction (rule 145) | 2 (`employees_record_account_link`, `employee_account_link_corrections_apply`) | 3 | DEFINER, `pg_catalog, public, pg_temp`, no EXECUTE |
| Approved locked-month correction (rule 147) | 1 (`staff_attendance_locked_corrections_apply`) | 1 | DEFINER, `pg_catalog, public, pg_temp`, no EXECUTE |
| INVOKER guards already pinned (earlier checkpoints, among them C2, C9, C18, C20, C22 and the E21 retention guards) | 17 | 32 | INVOKER, `pg_catalog, pg_temp` or `pg_catalog, public, pg_temp` |
| INVOKER guards pinned by C28 (C28-N2) | 40 | 46 | INVOKER, now `pg_catalog, public, pg_temp` |

EXECUTE on an INVOKER trigger function is inert (a trigger function cannot be called outside a trigger, and an INVOKER one runs with the caller's rights anyway), so the check constrains EXECUTE on the DEFINER functions only, for the runtime role, the retention identity and PUBLIC. That matters: the runtime role may own a temporary table and create a trigger on it, so EXECUTE on a DEFINER trigger function would let it run that function with its owner's rights. Existing body hashes (`prosrc`, rules 147/149) are unaffected: `ALTER FUNCTION … SET` changes no body. Because each bound function is now proven unique by name and bound through `tgfoid`, those name-based hashes are hashes of the bound function (C22-N1's "hash via `tgfoid`").

## 3. Negative proofs

All on the isolated test database; every break committed through the owner connection and restored in `finally`; the check passes again after each; roles probed only inside one uncommitted owner transaction (roles are cluster-wide, and DDEV's runtime role serves its development database too).

| Break | Check | Named |
|---|---|---|
| `leave_types_freeze` made SECURITY DEFINER | FAIL | `trg_leave_types_freeze:mode` |
| `staff_attendance_locked_corrections_apply` made INVOKER | FAIL | `…_apply:mode` |
| Trigger re-pointed to `leave_years_guard()` (the old function still exists) | FAIL | `:binding` |
| Same function, AFTER instead of BEFORE; `UPDATE OF name`; `WHEN (false)` | FAIL | `:definition` |
| Trigger disabled / dropped / an unclassified trigger added | FAIL | `:disabled` / `:missing` / `:unexpected` |
| An overload `leave_types_freeze(integer)` created | FAIL | `:binding` |
| Function owner changed | FAIL | `:owner` |
| `search_path` reset, or `pg_temp, public` | FAIL | `:search_path` |
| A DEFINER's pinned path changed | FAIL | `:search_path` |
| EXECUTE on a DEFINER to the runtime role, or to PUBLIC | FAIL | `:execute` |
| Runtime role granted a role (direct), a role holding another (indirect), or granted to another role WITH SET | FAIL | `member of 1` / `reachable 2` / `members 1` |
| Runtime role held by the schema owner WITH ADMIN only (the PostgreSQL 16 creator's grant) | PASS | — |
| Runtime role held WITH ADMIN only by any other role, or by the owner WITH SET | FAIL | — |
| REFERENCES on one column, to the runtime role or PUBLIC | FAIL | `employees:SIUR` / `leave_types:SIUR` |
| Temporary-table shadow of a guard's tables | Refused after C28 (`leave_type_in_use`); allowed before | `GuardSearchPathShadowTest` |
| C27-N3 single-hop `$this->employmentRecord->…` planted in a Payroll model | Register FAIL after; pass before | `PayrollRunResult.php … differs from the register` |
| The 12 C26 source probes (foreign, controller, unnamed and second writers, variable and chain writes, lazy, array, type, unapproved reference) | All still FAIL | as in C26 §5.6 |

Every planted source change was restored byte-identical (checksums); no probe role, grant, trigger or setting remains.

## 4. Preserved

- D-2: 22/26/12, 9 keys, 8 functions; register counts unchanged (`lazy` gained no new site).
- R1-01: `WRITERS`, the 56-table allowlist, the four WO-11 DELETE denials, `staff_account_invitations`' Identity exception.
- Retention: `school_os_retention` keeps EXECUTE on its functions; none on the family's DEFINER trigger functions (unchanged). Migration role unchanged.
- Rollback: `/rollback-proof 1` IDENTICAL (40 schema lines, the 40 `SET search_path`), verifier failed=0; a rolled-back schema fails `hr_family_trigger_functions`.

## 5. Limits, stated

- The trigger pin covers the HR family only. **C28-N1 (new, LOW, Platform):** 115 more INVOKER trigger functions elsewhere in the schema set no `search_path` (17 on Payroll tables); the same shadowing route applies wherever their bodies name unqualified tables. Not changed here (other domains' owners); revoking TEMP from PUBLIC is the alternative Platform fix.
- The membership check proves the runtime role reaches no other role; it does not judge other roles' memberships.
- Source scans still miss dynamic SQL, names built at run time, unnamed variables and relations (C26 §5.7).
- The bodies of the 40 newly pinned functions (and of most other family guards) are not hashed: a `CREATE OR REPLACE` that keeps the `SET search_path` clause but weakens the body passes this check. Body hashes exist for the calendar and month-lock guards (rules 147, 149). Recorded as **C28-N3 (LOW, Platform/HR)**.
- EXECUTE on SECURITY DEFINER trigger functions outside the HR family is not checked here (part of C28-N1).
- **C28-N4 (LOW, Platform):** `retention_role_narrow` counts every `pg_auth_members` row on `school_os_retention`, so on managed PostgreSQL 16 the creator's ADMIN-only grant would fail it on a correct database: the same exception, for the schema owner only, is needed before staging. Not changed here (outside C27-D). **Both allowances assume the role that creates `school_os_app` and `school_os_retention` is the schema owner (the migration role).** If a managed database's master creates them and a separate role migrates, both checks fail closed on a correct database: run `production-bootstrap.sql` as the migration role, or remove the master's grant, before staging.

## 5a. Independent final-state review

A read-only reviewer examined the candidate (migration, verifier SQL, tests, register regex, documents; catalogue facts from the migrations, database not reachable to it). **No HIGH, no MEDIUM.** Corrected before commit:

| Id | Finding | Fix |
|---|---|---|
| F-1 | LOW, a production blocker at staging: on managed PostgreSQL 16 the CREATEROLE creator's automatic ADMIN-only grant of `school_os_app` would fail the membership check on a correct database | An ADMIN-only row on the runtime role is allowed for the schema owner only (narrowed after the delta review §5b); tests prove the owner's ADMIN-only grant passes and any other holder, or SET, fails |
| F-2 | LOW: the pin missed `UPDATE OF`, `WHEN`, arguments, deferral | The md5 of each trigger's `pg_get_triggerdef` is pinned; two more breaks added. Function bodies not hashed: C28-N3 |
| F-3 | INFO: a hard-coded retention role name | `self::RETENTION_ROLE` |
| F-4 | INFO: ADR wording overstated the EXECUTE check | Corrected, with the reason it matters |
| F-5 | INFO: the "C18, C20, C22" label; breaks applied before `try` | Label corrected; the break is one atomic statement, a reset clears a killed run |

The reviewer re-derived every count from the constants (103, 44, 61, 40/46, 21, 17/32) and confirmed the 40 bodies use no dynamic SQL, temporary table, other schema or `regclass`, so the path change is behaviour-neutral.

## 5b. Delta review of the corrections

A second read-only reviewer checked F-1 to F-5 as applied. **No HIGH, no MEDIUM.** `md5(pg_get_triggerdef)` is stable across environments: the table is always schema-qualified and the function renders unqualified on the runtime connection's `search_path` (`public`), as in the generating sessions. It contains no OIDs (confirmed: the Compose test database, migrated by a different owner role, passes the check, `/rollback-proof` verify failed=0). A PostgreSQL major-version deparse change or a role `search_path` without `public` would fail closed. Its LOW, that any role's ADMIN-only grant passed although ADMIN lets a holder grant itself SET, was **corrected**: only the schema owner may hold it. The wording "confers nothing" was corrected everywhere. New: **C28-N4 (LOW, Platform)** below. A third, targeted read-only recheck of the narrowing found no false PASS and no HIGH or MEDIUM; its two LOWs were documentation (the creator must be the schema-owner role; one malformed table row), both corrected.

## 6. Evidence

See §9 of the evidence commit for the suites, the full exact-SHA regression and publication.

## 7. Status

| Item | Status |
|---|---|
| WO-13 / C22-N1 | **CLOSED for development** on publication |
| C26-N1 | **CLOSED for development** on publication |
| C27-N1 | **CLOSED for development** on publication |
| C27-N3 | **CLOSED for development** on publication |
| C28-N2 | **CLOSED for development** on publication (HR family) |
| C28-N1 | **OPEN**, LOW, Platform |
| C28-N3 | **OPEN**, LOW, Platform with HR (guard bodies not hashed) |
| C28-N4 | **OPEN**, LOW, Platform (`retention_role_narrow` on managed PostgreSQL 16; before staging) |
| Blocking under C27-C | C14-N2, C14-N5, LVA-1/TRR-2 (D-3), person-level Identity (unchanged) |
| HR.R1 | **OPEN**, not eligible; baselines unselected |
| Production | **NO-GO** |

**Next (recommended).** C14-N5, credential verification binding a version (MEDIUM, HR with Security). Never auto-started.
