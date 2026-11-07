# PAY-15 rollback-guard inventory (HR.R1-C5, 2026-11-06)

The counts below are the HR.R1-C5 measurement of 65 entries. Later migrations add rows
at the end of the table, so the table can hold more than the summary.

This replaces the HR.R1-C4 inventory. Its classes came from a static scan. These come
from **running every real guard** on a production-shaped database.

**What the HR.R1-C4 inventory got wrong.**

- **Its premise.** It said the documented migration role is `NOSUPERUSER
  NOBYPASSRLS`. That line of `production-bootstrap.sql` describes `school_os_app`.
  Three migrations refuse to run without RLS bypass, so a role that applied them all
  sees every row, and those guards were authoritative for it.
- **Its count.** It matched the word "refuse" and found 47. Every `down()` that can
  throw or says "refuse" gives **65** entries, of which 5 are not guards.
- **Its coverage.** The 18 entries it missed include 8 blind guards.

**The defect that remains is real but narrower.** A role WITHOUT bypass, one that lost
it or stopped part way, reads an empty answer from a FORCE RLS table. On `main`'s stock
migrator, such a role destroyed real hidden evidence through **25 real guards**.

**Disposition is one mechanism, not a per-file edit.**
`App\Support\Database\RollbackSafeMigrator` refuses every `down()` before it starts
unless the role sees every row, because a DROP is never filtered, and runs the rest
with `row_security = off`. No published migration changed (ADR 0066 §15.1). Everything
but local and testing refuses the commands outright (ADR 0074 §26.7).

## Summary

| Outcome on `main`'s migrator, no bypass, hidden rows of two Schools | Entries |
|---|---:|
| **Blind: table destroyed with its rows** | 20 |
| **Blind: evidence column destroyed** | 5 |
| Blind, but a constraint stopped the DDL afterwards | 10 |
| Sees its rows without bypass (no FORCE RLS on that table, a per-School loop, FORCE lifted, a CHECK, or a role refusal) | 14 |
| Compares a function body, reads no row | 5 |
| Re-adds a CHECK, validated against every row (not seeded; the same mechanism is measured on `2026_12_21` and `2026_12_24`) | 3 |
| Always refuses (retention fence) | 2 |
| Refuses any role without bypass, with no row condition | 1 |
| Not a guard (one, `2027_01_26`, drops a table with nothing to consult) | 5 |
| **Total** | **65** |

**Reconciliation with the 47.** All 47 are below, plus 18 the old scan missed. The 27 it
flagged as failing open measured: 18 destroyed a table, 5 destroyed columns, 4 blind but
stopped by a constraint. Its other 20 labels held: none of them measured blind.

**On the candidate, the same 65:** with no bypass, all 65 are refused before they start,
with hidden rows or with empty tables, and rows and schema stay unchanged. With bypass, every seeded guard refuses for
itself, except `2027_01_26`, which has no guard. With bypass on empty tables, 63 roll
back at their true position and the 2 fences refuse.

## How this was measured

- **Database.** The real `production-bootstrap.sql`, then all migrations as a real login
  role, `NOSUPERUSER NOCREATEDB NOCREATEROLE`, with `BYPASSRLS` toggled per run.
- **True position.** Each guard ran on a clone that stopped right after its own
  migration, through the real `migrate:rollback`. Out-of-order runs give false results:
  a later migration's dependency stops the DDL after the guard has already passed.
- **Sentinels.** Two Schools' synthetic rows meeting the guard's own condition, written
  by a superuser, compared by content hash. Schema compared by `pg_dump -s`.
- **Runs.** Five per entry: no bypass on `main`'s migrator; no bypass on the candidate,
  with hidden rows and with empty tables; bypass on the candidate; bypass with empty
  tables. Entries with no row condition have no sentinel runs. No run errored.
- **Repeatable part.** `RollbackProductionShapeTest` repeats the full path and 15 real
  guards on every candidate that runs the platform lane. The 65-way run itself was local
  and is not in CI.

**Limits.** A `down()` with no guard has nothing to consult: on a role with bypass it
destroys what it drops, on a disposable database. A guard is not atomic with its DDL
unless it lifts FORCE or holds a lock, so on local and testing a concurrent write can
land after the check. Hardened environments refuse the five commands, so neither is
reachable through them. Arbitrary code run with the admin credentials, through `tinker`
or `psql`, is outside this control. `--path` with `--batch` can run a `down()` behind the fence, on local and testing
only, and the migrator still applies.

**Keys.** Reach: "Fence" means the permanently refusing `2026_12_01_090000` and `090100`
sit after it, so an ordinary rollback stops first. "Open" means it does not.

`tests/Feature/Architecture/RollbackGuardInventoryTest.php` fails if a new guard is not
listed here, or a `down()` swallows an error. It reads source: it follows helpers one
level, and a guard built only in raw SQL with no `throw` or "refuse" is not seen. The
migrator covers such a guard all the same.

| Migration | Reach | Class (measured) | Evidence seeded | Destruction in `down()` | `main`, no bypass | Candidate, no bypass | Candidate, bypass | Bypass, empty | Disposition |
|---|---|---|---|---|---|---|---|---|---|
| `2026_10_18_090000_add_school_lifecycle_constraints` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | school_elevations | drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_10_26_090300_add_credential_reset_elevation_end_reason` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | school_elevations | — | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_10_27_090000_add_manual_payment_recording` | Fence | Blind: a constraint stopped the DDL | payments | drop column, drop constraint, drop function, drop trigger | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_10_28_090000_allow_credentialless_users` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | users | drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_10_31_090300_add_concession_failure_reasons_to_fee_assessment_run_items` | Fence | Re-adds a CHECK, validated against every row | — | — | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_11_05_090000_add_lms_ownership_and_section_audiences` | Fence | Re-adds a CHECK, validated against every row | — | drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_11_09_090000_create_financial_periods` | Fence | Blind: table destroyed | financial_periods | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_09_090100_create_financial_period_baselines` | Fence | Blind: table destroyed | financial_period_account_balances | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_10_090000_add_finance_retention_expiry` | Fence | Blind: table destroyed | financial_period_expiries | drop function, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_12_090000_add_admissions_and_guardian_lifecycle_markers` | Fence | Blind: evidence column destroyed | admission_applications | drop column, drop constraint, drop function, drop trigger | **Columns destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_15_090000_add_payroll_evidence_retention` | Fence | Blind: evidence column destroyed | payroll_runs | drop column, drop function, drop trigger | **Columns destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_16_090000_add_user_minimization` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | users | drop column, drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_11_17_090000_create_leave_foundation` | Fence | Blind: table destroyed | leave_ledger_entries | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_18_090000_add_prospective_leave_year_configuration` | Fence | Blind: table destroyed | leave_year_start_changes | drop column, drop constraint, drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_19_090000_create_leave_requests_and_year_close` | Fence | Blind: table destroyed | leave_requests | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_20_090000_create_staff_attendance` | Fence | Blind: table destroyed | staff_attendance_records | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_21_090000_allow_self_leave_decisions` | Fence | Blind: a constraint stopped the DDL | leave_decisions | drop constraint | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_22_090000_create_payroll_run_hrx_inputs` | Fence | Blind: table destroyed | payroll_run_hrx_inputs | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_11_24_090000_harden_hrx_retention_privileges` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | retention_school_holds | drop table | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_11_27_090000_make_retention_holds_authoritative` | Fence | Sees its rows without bypass (Table has no FORCE RLS) | retention_holds | drop function, drop table | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_11_28_090000_harden_standalone_retention_functions` | Fence | Compares a function body. Reads no row | — | drop function | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_11_29_090000_harden_payroll_lms_retention_units` | Fence | Compares a function body. Reads no row | — | drop function | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_11_30_090000_harden_finance_student_guardian_retention_functions` | Fence | Compares a function body. Reads no row | — | — | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_11_30_090100_guard_retention_eligibility_sources` | Fence | Compares a function body. Reads no row | — | drop column, drop function, drop trigger | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_12_01_090000_fence_retention_eligibility_guards` | Fence | Always refuses (retention fence) | — | — | n/a | Refused by the migrator | n/a | Refused (by design) | Already safe |
| `2026_12_01_090100_anchor_retention_eligibility_clocks` | Fence | Always refuses (retention fence) | — | — | n/a | Refused by the migrator | n/a | Refused (by design) | Already safe |
| `2026_12_05_090100_refuse_library_fine_charges_in_finance_expiry` | Open | Compares a function body. Reads no row | — | — | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_12_11_090000_end_teaching_ownership_with_employment` | Open | Sees its rows without bypass (Per-School loop) | teaching_assignments | drop constraint | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2026_12_12_090000_add_guardian_authorization_scope` | Open | Sees its rows without bypass (Lifts FORCE first) | membership_role_assignments | drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2026_12_14_090000_harden_staff_role_catalogue` | Open | Sees its rows without bypass (Table has no FORCE RLS) | roles | drop column, drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2026_12_15_090000_create_payment_corrections` | Open | Blind: table destroyed | payment_corrections | drop function, drop table | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_12_16_090000_add_overlap_review_to_fee_assessment_run_items` | Open | Re-adds a CHECK, validated against every row | — | drop column, drop constraint | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_12_17_090000_scope_timetable_slot_uniqueness_to_academic_year` | Open | Blind: a constraint stopped the DDL | timetable_entries | — | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2026_12_21_090000_add_campus_scope_to_membership_role_assignments` | Open | Sees its rows without bypass (CHECK, every row) | membership_role_assignments | drop constraint, dropcolumn | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2026_12_24_090000_create_student_number_sequences` | Open | Sees its rows without bypass (CHECK, every row) | student_number_sequences | drop function, drop table, drop trigger, tenantrls_disable | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2026_12_26_090000_add_charge_campus_attribution` | Open | Sees its rows without bypass (Refuses without bypass) | charges | drop column, drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2026_12_30_090000_guard_subledger_journal_reversals` | Open | No guard. "refuse" is only in a function name | — | drop function, drop trigger | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2026_12_31_090000_guard_fee_concession_beneficiary_guardian_decisions` | Open | No guard. "refuse" is only in a function name | — | drop function, drop trigger | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2027_01_01_090000_record_payroll_run_calculations_and_participant_approval` | Open | Blind: table destroyed | payroll_run_calculations | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_02_090000_create_payment_correction_requests` | Open | Blind: table destroyed | payment_correction_requests | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_03_090000_guard_leave_decision_participants` | Open | No guard. "refuse" is only in a function name | — | drop function, drop trigger | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2027_01_04_090000_create_student_lifecycle_events` | Open | Blind: table destroyed | student_lifecycle_events | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_04_090100_create_attendance_record_corrections` | Open | Blind: table destroyed | attendance_record_corrections | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_04_090200_add_reason_code_to_library_fine_voids` | Open | Blind: evidence column destroyed | library_fine_voids | drop column, drop constraint, drop function, drop trigger | **Columns destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_05_090100_record_charge_cancellation_assurance` | Open | Blind: evidence column destroyed | charges | drop column, drop constraint, drop function, drop trigger | **Columns destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_06_090000_add_issue_recipient_and_write_off_to_stock_movements` | Open | Blind: a constraint stopped the DDL | stock_movements | drop column, drop constraint | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_07_090000_add_staff_communication_audience_type` | Open | Blind: a constraint stopped the DDL | communication_announcements | drop constraint | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_08_090000_add_fee_reminder_communications` | Open | Blind: a constraint stopped the DDL | communication_announcements | drop constraint, schema_drop, tenantrls_disable | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_09_090000_create_communication_events` | Open | Blind: table destroyed | communication_events | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_11_090000_placement_start_within_year_and_pre_start_cancellation` | Open | Blind: a constraint stopped the DDL | student_enrollments | drop constraint | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_12_090100_add_structured_campus_profile` | Open | Refuses any role without bypass | — | drop constraint, dropcolumn | n/a | Refused by the migrator | n/a | Rolled back | Already safe |
| `2027_01_14_090000_add_network_scope_to_group_role_assignments` | Open | Sees its rows without bypass (Table has no FORCE RLS) | group_role_assignments | drop column, drop constraint, drop function, drop trigger | Refused | Refused by the migrator | Guard refused | Rolled back | Covered by the migrator |
| `2027_01_21_090000_add_document_compliance` | Open | Blind: table destroyed | applicant_documents | drop constraint, drop function, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_24_090000_extend_student_groups_to_clubs_and_activities` | Open | Blind: a constraint stopped the DDL | student_groups | drop column, drop constraint | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_25_090000_create_hr_workforce_foundation` | Open | No guard in `down()`. Its `throw` belongs to `up()` | — | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | n/a | Refused by the migrator | n/a | Rolled back | Covered by the migrator |
| `2027_01_26_090000_create_reporting_relationships` | Open | No guard in `down()`. Drops the table | reporting_relationships | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Dropped (no guard) | Rolled back | Covered by the migrator |
| `2027_01_27_090000_create_hr_staff_profile_foundation` | Open | Blind: a constraint stopped the DDL | employee_documents | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_28_090000_create_leave_policy_rules_and_eligibility` | Open | Blind: table destroyed | leave_eligibility_runs | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_29_090000_create_leave_accrual_and_opening` | Open | Blind: table destroyed | leave_accrual_runs | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_30_090000_create_hr_separation_cases` | Open | Blind: table destroyed | employment_separation_cases | drop function, drop trigger, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_01_31_090000_create_workforce_tasks` | Open | Blind: table destroyed | workforce_tasks | drop function, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_02_01_090000_bind_payroll_approval_to_reviewed_evidence` | Open | Blind: evidence column destroyed | payroll_runs | drop function, drop trigger, dropcolumn | **Columns destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_02_02_090000_record_payroll_compensation_sources` | Open | Blind: table destroyed | payroll_run_calculation_sources | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | **Table destroyed** | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_02_03_090000_harden_employment_lifecycle_admission` | Open | Blind: a constraint stopped the DDL | employee_assignments | drop constraint, drop function, drop trigger | Constraint stopped it | Refused by the migrator | Guard refused | Rolled back | Corrected by the migrator |
| `2027_02_06_090000_create_payroll_posting_attestations` | Open | Sees its rows without bypass (Lifts FORCE first) | payroll_posting_attestations | drop function, drop trigger, schema_drop, tenantrls_disable | Refused | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2027_02_07_090000_create_payroll_reversal_reconciliations` | Open | Sees its rows without bypass (Lifts FORCE first) | payroll_reversal_reconciliations | drop function, drop trigger, schema_drop, tenantrls_disable | n/a (added by HR.R1-C6) | Refused by the migrator | Guard refused | Rolled back | Already safe |
| `2027_02_08_090000_create_employee_account_links` | Open | Sees its rows without bypass (Lifts FORCE first) | employee_account_links | drop constraint, drop function, drop trigger, schema_drop, tenantrls_disable | n/a (added by HR.R1-C7) | Refused by the migrator | Guard refused | Rolled back | Already safe. Refuses any recorded, ended or corrected row; a cutover-only table rolls back |
| `2027_02_09_090000_materialize_payroll_reversal_discovery` | Open | No guard in `down()`. Replaces one function body | — | none | n/a (added by HR.R1-C7-R1) | No destruction | No destruction | Rolled back | Already safe |
| `2027_02_10_090000_refuse_corrections_of_reversed_runs` | Open | No guard in `down()`. Replaces function bodies | — | drop function, drop trigger | n/a (added by HR.R1-C8) | No destruction | No destruction | Rolled back | Already safe |
| `2027_02_10_090100_require_two_people_to_correct_an_account_link` | Open | Sees its rows without bypass (Lifts FORCE first) | employee_account_link_corrections | drop function, drop trigger, schema_drop, tenantrls_disable | n/a (added by HR.R1-C8) | Refused by the migrator | Guard refused | Rolled back | Already safe. Refuses any correction row |
| `2027_02_10_090200_ask_reversal_discovery_about_one_run` | Open | No guard in `down()`. Replaces function bodies | — | drop function | n/a (added by HR.R1-C8) | No destruction | No destruction | Rolled back | Already safe |
