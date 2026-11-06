# PAY-15 rollback-guard inventory (HR.R1-C5, 2026-11-06)

This replaces the HR.R1-C4 inventory, which was wrong in two ways.

- **Its premise.** It said the documented migration role is `NOSUPERUSER
  NOBYPASSRLS`. That line of `production-bootstrap.sql` describes `school_os_app`.
  Six migrations refuse to run without RLS bypass, so a role that applied them all
  sees every row, and those guards were authoritative for it.
- **Its count.** It matched the word "refuse" and found 47. Scanning every `down()`
  that can throw finds **65**.

**The defect that remains is real but narrower.** A role WITHOUT bypass, one that
lost it or stopped part way, reads an empty answer from a FORCE RLS table. Proven on
a production-shaped database before the fix: the same hidden row was refused with
bypass, and destroyed without it.

**Disposition of every entry is the same mechanism, not a per-file edit.**
`App\Support\Database\RollbackSafeMigrator` runs every `down()` with `row_security =
off`, so PostgreSQL raises an error instead of filtering, and the migration is
refused. No published migration was changed (ADR 0066 §15.1). The classes below
describe each guard; none of them is what makes it safe, so a guard this scan
misclassifies or misses is covered all the same.

**Method and limits.** A static scan of each `down()` and the private helpers it
calls, against the 248 tables with FORCE RLS. It can misclassify: raw SQL it cannot
parse shows as reading nothing, and a `throw` may sit in a helper it does not follow.
`tests/Feature/Architecture/RollbackGuardInventoryTest.php` fails if a new guard is
not listed here, or a `down()` swallows an error.

"Behind the fence" means the permanently refusing `2026_12_01_090000` and `090100`
sit after it, so a step or batch rollback cannot reach it.

| Class | Guards |
|---|---:|
| Reads FORCE-RLS rows. Authoritative for a role with bypass; a role without it is refused | 37 |
| Reads tables without FORCE RLS or catalogs. Not RLS-dependent | 11 |
| Reads no row in `down()`. Not RLS-dependent | 9 |
| Always refuses (retention fence) | 2 |
| Lifts FORCE in its own transaction first. Already safe | 2 |
| Uses a CHECK constraint, validated against every row whatever RLS says. Already safe | 2 |
| Refuses any role without bypass. Already safe | 2 |
| **Total** | **65** |

| Migration | Reach | Class | Tables the guard names | Destruction in `down()` | Disposition |
|---|---|---|---|---|---|
| `2026_10_18_090000_add_school_lifecycle_constraints` | Behind the fence | Not RLS-dependent | school_elevations | drop constraint, drop function, drop trigger | Covered by the migrator |
| `2026_10_26_090300_add_credential_reset_elevation_end_reason` | Behind the fence | Not RLS-dependent | school_elevations | — | Covered by the migrator |
| `2026_10_27_090000_add_manual_payment_recording` | Behind the fence | Reads FORCE-RLS rows | payments | drop column, drop constraint, drop function, drop trigger | Refused without bypass (migrator) |
| `2026_10_28_090000_allow_credentialless_users` | Behind the fence | Not RLS-dependent | — | drop constraint, drop function, drop trigger | Covered by the migrator |
| `2026_10_31_090300_add_concession_failure_reasons_to_fee_assessment_run_items` | Behind the fence | Reads no row | — | — | Covered by the migrator |
| `2026_11_05_090000_add_lms_ownership_and_section_audiences` | Behind the fence | Reads no row | — | drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Covered by the migrator |
| `2026_11_09_090000_create_financial_periods` | Behind the fence | Reads FORCE-RLS rows | financial_periods | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_09_090100_create_financial_period_baselines` | Behind the fence | Reads FORCE-RLS rows | financial_period_account_balances, financial_period_charge_states | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_10_090000_add_finance_retention_expiry` | Behind the fence | Reads FORCE-RLS rows | financial_period_expiries | drop function, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_12_090000_add_admissions_and_guardian_lifecycle_markers` | Behind the fence | Reads FORCE-RLS rows | admission_applications, guardians | drop column, drop constraint, drop function, drop trigger | Refused without bypass (migrator) |
| `2026_11_15_090000_add_payroll_evidence_retention` | Behind the fence | Reads FORCE-RLS rows | payroll_run_results, payroll_runs | drop column, drop function, drop trigger | Refused without bypass (migrator) |
| `2026_11_16_090000_add_user_minimization` | Behind the fence | Not RLS-dependent | — | drop column, drop constraint, drop function, drop trigger | Covered by the migrator |
| `2026_11_17_090000_create_leave_foundation` | Behind the fence | Reads FORCE-RLS rows | leave_ledger_entries, leave_policy_assignments | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_18_090000_add_prospective_leave_year_configuration` | Behind the fence | Reads FORCE-RLS rows | leave_year_start_changes, leave_years, starts_on | drop column, drop constraint, drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_19_090000_create_leave_requests_and_year_close` | Behind the fence | Reads FORCE-RLS rows | leave_ledger_entries, leave_requests, leave_year_closes | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_20_090000_create_staff_attendance` | Behind the fence | Reads FORCE-RLS rows | staff_attendance_records | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_21_090000_allow_self_leave_decisions` | Behind the fence | Reads FORCE-RLS rows | leave_decisions | drop constraint | Refused without bypass (migrator) |
| `2026_11_22_090000_create_payroll_run_hrx_inputs` | Behind the fence | Reads FORCE-RLS rows | payroll_run_hrx_inputs | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2026_11_24_090000_harden_hrx_retention_privileges` | Behind the fence | Reads FORCE-RLS rows | employment_records, retention_school_holds | drop table | Refused without bypass (migrator) |
| `2026_11_27_090000_make_retention_holds_authoritative` | Behind the fence | Reads FORCE-RLS rows | employment_records, retention_holds, retention_school_holds, school_os_app | drop function, drop table | Refused without bypass (migrator) |
| `2026_11_28_090000_harden_standalone_retention_functions` | Behind the fence | Not RLS-dependent | — | drop function | Covered by the migrator |
| `2026_11_29_090000_harden_payroll_lms_retention_units` | Behind the fence | Not RLS-dependent | — | drop function | Covered by the migrator |
| `2026_11_30_090000_harden_finance_student_guardian_retention_functions` | Behind the fence | Not RLS-dependent | — | — | Covered by the migrator |
| `2026_11_30_090100_guard_retention_eligibility_sources` | Behind the fence | Not RLS-dependent | — | drop column, drop function, drop trigger | Covered by the migrator |
| `2026_12_01_090000_fence_retention_eligibility_guards` | Behind the fence | Always refuses | — | — | Already safe |
| `2026_12_01_090100_anchor_retention_eligibility_clocks` | Behind the fence | Always refuses | — | — | Already safe |
| `2026_12_05_090100_refuse_library_fine_charges_in_finance_expiry` | Reachable | Not RLS-dependent | — | — | Covered by the migrator |
| `2026_12_11_090000_end_teaching_ownership_with_employment` | Reachable | Reads FORCE-RLS rows | subject_offerings | drop constraint | Refused without bypass (migrator) |
| `2026_12_12_090000_add_guardian_authorization_scope` | Reachable | Lifts FORCE | membership_role_assignments, role_capabilities | drop constraint, drop function, drop trigger | Already safe |
| `2026_12_14_090000_harden_staff_role_catalogue` | Reachable | Not RLS-dependent | — | drop column, drop constraint, drop function, drop trigger | Covered by the migrator |
| `2026_12_15_090000_create_payment_corrections` | Reachable | Reads FORCE-RLS rows | charges, fee_adjustments, payment_allocations, payment_corrections, school_os_retention | drop function, drop table | Refused without bypass (migrator) |
| `2026_12_16_090000_add_overlap_review_to_fee_assessment_run_items` | Reachable | Reads no row | fee_assessment_runs | drop column, drop constraint | Covered by the migrator |
| `2026_12_17_090000_scope_timetable_slot_uniqueness_to_academic_year` | Reachable | Reads no row | timetable_entries | — | Covered by the migrator |
| `2026_12_21_090000_add_campus_scope_to_membership_role_assignments` | Reachable | CHECK constraint | membership_role_assignments | drop constraint, dropcolumn | Already safe |
| `2026_12_24_090000_create_student_number_sequences` | Reachable | CHECK constraint | student_number_sequences | drop function, drop table, drop trigger, tenantrls_disable | Already safe |
| `2026_12_26_090000_add_charge_campus_attribution` | Reachable | Refuses without bypass | charges, journal_entries, student_enrollments | drop column, drop constraint, drop function, drop trigger | Already safe |
| `2026_12_30_090000_guard_subledger_journal_reversals` | Reachable | Reads no row | — | drop function, drop trigger | Covered by the migrator |
| `2026_12_31_090000_guard_fee_concession_beneficiary_guardian_decisions` | Reachable | Reads no row | — | drop function, drop trigger | Covered by the migrator |
| `2027_01_01_090000_record_payroll_run_calculations_and_participant_approval` | Reachable | Reads FORCE-RLS rows | payroll_run_calculations | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_02_090000_create_payment_correction_requests` | Reachable | Reads FORCE-RLS rows | payment_correction_requests | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_03_090000_guard_leave_decision_participants` | Reachable | Reads no row | — | drop function, drop trigger | Covered by the migrator |
| `2027_01_04_090000_create_student_lifecycle_events` | Reachable | Reads FORCE-RLS rows | student_lifecycle_events | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_04_090100_create_attendance_record_corrections` | Reachable | Reads FORCE-RLS rows | attendance_record_corrections | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_04_090200_add_reason_code_to_library_fine_voids` | Reachable | Reads FORCE-RLS rows | library_fine_voids | drop column, drop constraint, drop function, drop trigger | Refused without bypass (migrator) |
| `2027_01_05_090100_record_charge_cancellation_assurance` | Reachable | Reads FORCE-RLS rows | charges | drop column, drop constraint, drop function, drop trigger | Refused without bypass (migrator) |
| `2027_01_06_090000_add_issue_recipient_and_write_off_to_stock_movements` | Reachable | Reads FORCE-RLS rows | stock_movements | drop column, drop constraint | Refused without bypass (migrator) |
| `2027_01_07_090000_add_staff_communication_audience_type` | Reachable | Reads FORCE-RLS rows | communication_announcements | drop constraint | Refused without bypass (migrator) |
| `2027_01_08_090000_add_fee_reminder_communications` | Reachable | Reads FORCE-RLS rows | communication_announcements | drop constraint, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_09_090000_create_communication_events` | Reachable | Reads FORCE-RLS rows | communication_approval_requests, communication_events | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_11_090000_placement_start_within_year_and_pre_start_cancellation` | Reachable | Reads FORCE-RLS rows | academic_years, student_enrollments | drop constraint | Refused without bypass (migrator) |
| `2027_01_12_090100_add_structured_campus_profile` | Reachable | Refuses without bypass | — | drop constraint, dropcolumn | Already safe |
| `2027_01_14_090000_add_network_scope_to_group_role_assignments` | Reachable | Not RLS-dependent | group_role_assignments | drop column, drop constraint, drop function, drop trigger | Covered by the migrator |
| `2027_01_21_090000_add_document_compliance` | Reachable | Reads FORCE-RLS rows | applicant_documents, documents, school_os_retention, student_documents | drop constraint, drop function, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_24_090000_extend_student_groups_to_clubs_and_activities` | Reachable | Reads FORCE-RLS rows | student_group_memberships, student_groups | drop column, drop constraint | Refused without bypass (migrator) |
| `2027_01_25_090000_create_hr_workforce_foundation` | Reachable | Reads no row | — | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Covered by the migrator |
| `2027_01_26_090000_create_reporting_relationships` | Reachable | Reads no row | employee_assignments, reporting_relationships | drop function, drop trigger, schema_drop, tenantrls_disable | Covered by the migrator |
| `2027_01_27_090000_create_hr_staff_profile_foundation` | Reachable | Reads FORCE-RLS rows | employee_documents | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_28_090000_create_leave_policy_rules_and_eligibility` | Reachable | Reads FORCE-RLS rows | leave_eligibility_rules, leave_eligibility_runs, leave_policies, leave_policy_assignments | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_29_090000_create_leave_accrual_and_opening` | Reachable | Reads FORCE-RLS rows | accrual_period, leave_accrual_runs, leave_ledger_entries, leave_policies, leave_policy_assignments, leave_request_days, leave_requests, leave_year_closes, leave_years | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_30_090000_create_hr_separation_cases` | Reachable | Reads FORCE-RLS rows | employment_separation_cases | drop function, drop trigger, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_01_31_090000_create_workforce_tasks` | Reachable | Reads FORCE-RLS rows | workforce_tasks | drop function, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_02_01_090000_bind_payroll_approval_to_reviewed_evidence` | Reachable | Reads FORCE-RLS rows | employee_compensation_assignments, payroll_runs | drop function, drop trigger, dropcolumn | Refused without bypass (migrator) |
| `2027_02_02_090000_record_payroll_compensation_sources` | Reachable | Reads FORCE-RLS rows | payroll_run_calculation_sources, payroll_run_calculations, payroll_run_results, v_latest | drop constraint, drop function, drop trigger, dropcolumn, schema_drop, tenantrls_disable | Refused without bypass (migrator) |
| `2027_02_03_090000_harden_employment_lifecycle_admission` | Reachable | Reads FORCE-RLS rows | employee_assignments, to_jsonb | drop constraint, drop function, drop trigger | Refused without bypass (migrator) |
| `2027_02_06_090000_create_payroll_posting_attestations` | Reachable | Lifts FORCE | payroll_posting_attestations | drop function, drop trigger, schema_drop, tenantrls_disable | Already safe |
