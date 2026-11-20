# HR.R1-C35: development DDEV forward migration, 398 → 402 (D-RF2-22; 2026-11-20)

**An operator record. It changes no code, migration, test or document contract.** The ordinary development database was brought to the published migration state after WO-24 and WO-25, exactly as RF2 §9 prescribes. HR.R1 stays **OPEN**; no baseline is selected.

| | |
|---|---|
| Authority | D-RF2-22 (owner, 2026-11-20: "approved, to run after WO-24 publishes"), and the owner's go-ahead for this step on 2026-11-20 |
| Source | `fe309c22` = `main` = `origin/main` (WO-25 evidence; WO-25 code `5dcc35ef`, test-only, no migration). Clean tree, one worktree; the stash and `integration/e16-r2` preserved. 402 migration files; none newer than `2027_02_27` |
| Not done | No reset, `migrate:fresh`, rollback, seed or `demo-reset`; no ad-hoc SQL in place of a migration; no edit of a migration file; no ledger edit; no synthetic UAT School; no other DDEV project touched |

## 1. Target

- **Project** `lycenza` (`~/sites/lycenza`, DDEV v1.25.4, Docker Desktop). It was stopped and was started with `ddev start` alone.
  - The configuration has no start hooks and no web entrypoint scripts, so starting it ran nothing.
  - The only other running project (`jurimatterlaw`) was not touched.
- **Database** `db` on PostgreSQL 16.14 (Debian 16.14-1.pgdg13+1), schema `public`.
  - **Migration connection:** `pgsql_admin`, role `db` (superuser, BYPASSRLS, as rule 143 requires of the migration role).
  - **Runtime roles:** `school_os_app` and `school_os_retention` are neither superuser nor BYPASSRLS.
  - **The app** resolves environment `local` and database `db`.
- **Ledger before:** 398 rows, last batch 9, last `2027_02_23_090000_bind_separation_retained_access_to_grant_set`. Compared file by file with `database/migrations`: exactly four pending, and nothing applied that is not in the repository.

## 2. The four migrations (read in full first)

| Migration | Source | Effect on existing rows | Schema |
|---|---|---|---|
| `2027_02_24_090000_record_separation_clearance_staff_type_basis` | WO-21, `f03aa6aa` | None: two nullable columns (NULL satisfies the shape CHECK) | `clearance_staff_type`, `clearance_skipped_requirement_ids`, `employment_separation_cases_clearance_basis_shape`; two guard functions and triggers on future writes |
| `2027_02_25_090000_refuse_known_same_person_task_review` | WO-22, `5b78154c` | None | patches `hr_workforce_tasks_guard` and `hr_workforce_task_events_guard` (live definitions) |
| `2027_02_26_090000_bind_task_events_to_task_version` | WO-23, `d11a4537` | None: `task_version` NULL on every event (nothing back-filled); the partial unique index covers non-NULL values only | the column, its CHECK, `workforce_task_events_one_per_version`, two guard patches, the deferred `trg_workforce_tasks_transition_evidence` (status UPDATEs only) |
| `2027_02_27_090000_bind_separation_access_to_the_person` | WO-24, `e7d7ee02` | None | patches `hr_separation_retained_grants_guard` (person-level set; completion backstop on the transition only) |

No grant, revocation, RLS change, data transformation or long lock is involved. The anchors depend on the published chain in this order; the full chain migrates on the isolated test database in every CI run.

## 3. Before

**Snapshot.** For 35 tables, `count(*)` and an md5 over every row as `jsonb`, sorted, excluding the three columns these migrations add. The tables cover:
- Schools, Campuses, Users, Employees, employments, account links and corrections, memberships and role grants;
- separation cases, events, requirements and items; tasks, events, templates and versions;
- Payroll runs, postings, calculations, results, attestations and reconciliations, and compensation assignments;
- Leave policies, requests, decisions and ledger;
- Staff Attendance records, corrections, locked corrections and month locks;
- School and platform audit events; retention holds.

**Also captured:**
- the October 2026 Staff Attendance month lock row (md5 `851693a2c1a63b4b81b88d75d0a63b63`);
- WO-20 kept-access records (0);
- forced-RLS tables (256);
- runtime-role attributes;
- the md5 of `school_os_app`'s 934 table grants.

**Counts.** schools 10, campuses 13, users 33, employees 13, employments 13, account links 1, memberships 25, role grants 30, separation cases 0, tasks 0, task events 0, payroll runs 2, postings 1, compensation assignments 8, leave ledger 0, attendance records 1, month locks 1, School audit events 869, platform audit events 83.

**Recovery point.** `ddev snapshot --name lycenza-pre-wo21-wo24-402`: 22 MB, in `.ddev/db_snapshots` (git-ignored), 532 GB free. It was never restored. RF2 §9 item 6: a correction would be forward only.

**Verifier before:** `failed=6`, every failure an object of the four pending migrations (`hr_task_invariants`, `hr_separation_invariants`, `hr_family_trigger_functions`, `known_same_person_guards`, `hr_separation_retained_access_binding`, `hr_separation_clearance_basis`). This confirms the code expects exactly those four.

## 4. Execution

**GO** recorded after the checks above. `ddev exec php artisan migrate --database=pgsql_admin` (rule 54), no flags:
- `2027_02_24` 7.97 ms;
- `2027_02_25` 3.07 ms;
- `2027_02_26` 9.16 ms;
- `2027_02_27` 2.52 ms.

All DONE, exit 0, no retry.

## 5. After

- **Ledger, read from the table:** 402 rows; the four in batch 10, in order. Compared with the repository: 0 pending, 0 unknown.
- **Snapshot:** **identical** to the one before, all 41 lines:
  - every count and row hash of the 35 tables;
  - the October 2026 month lock byte-identical (`851693a2…`);
  - WO-20 evidence (none);
  - 256 forced-RLS tables;
  - runtime-role attributes;
  - `school_os_app`'s grants, so no new runtime privilege.

  The added columns are NULL (no rows to populate).
- **`platform:verify-database`: `failed=0`.** The one operator-evidence row is `runtime_connection_encrypted`, expected outside production. PASS includes:
  - `hr_task_invariants`, `hr_separation_invariants`, `hr_family_trigger_functions` (each trigger through `tgfoid`, mode and `search_path`);
  - `known_same_person_guards`, `hr_separation_retained_access_binding`, `hr_separation_clearance_basis`;
  - `hr_family_runtime_privileges`, `tenant_tables_force_rls`, `staff_attendance_month_lock_invariants`, `staff_calendar_history_invariants`;
  - `runtime_role_attributes` and `runtime_role_memberships_none`.

  The guard bodies therefore match the pinned definitions of WO-21 to WO-24.
- **The DDEV app** answers `/api/health/live` and `/api/health/ready` with 200.

## 6. Contract checks (isolated only)

Negative writes were never run against DDEV. On the isolated test stack at the same source:
- `tests/Feature/HR/Separation`: 120 tests, green;
- `tests/Feature/WorkforceTasks`: 59 tests, green.

Together they exercise, as `school_os_app`:
- WO-21's recorded basis;
- WO-22's known-same-person review refusal;
- WO-23's commit-time task evidence;
- WO-24's person-level access and completion backstop, with their positive controls.

The full canonical suite was **not** rerun for this step (its last run is WO-25's, `44781452652`, 11,998 tests); it is an environment operation, not a code change.

## 7. Verdict

**DDEV MIGRATION COMPLETE — 402/402 DEVELOPMENT DATABASE VERIFIED.** The development database now agrees with the published migration state, with no business data changed.

**Unchanged:**
- RF2's 81 decisions, the 61 audit identifiers and the 361 capability rows;
- WO24-N1..N3 and WO25-N1/N2;
- RG-ID-1 (open), R1-02 (PARTIAL), C33-H1..H5 and the Legal/DPO gates;
- staging PAUSED and production NO-GO.

**Next:** the synthetic UAT School (D-RF2-23, RF2 §10.2), on its own authorization; then HR.R1-C36. The database dimension is now eligible for consideration at C36 but is not selected.
