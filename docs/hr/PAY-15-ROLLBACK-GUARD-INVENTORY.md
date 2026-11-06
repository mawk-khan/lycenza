# PAY-15 rollback-guard inventory (HR.R1-C4, 2026-11-06)

**What this is.** A scan of every migration whose `down()` refuses to roll back,
checked against the tables that actually have FORCE ROW LEVEL SECURITY
(248 tables on the test database, 2026-11-06). Nothing here was fixed.

**Why it matters.** `infrastructure/postgres/production-bootstrap.sql` creates the
migration role `NOSUPERUSER NOBYPASSRLS`, and FORCE RLS binds a table's owner. With
no tenant context, a guard that reads such a table sees no rows, passes, and lets
the rollback destroy what it was written to protect. Local DDEV and Docker are safe
only because their migration role is a superuser.

**Method and limits.** This is a static scan of each `down()` body: a guard is
counted when it says it refuses, and classified by the tables it names. It does not
follow helpers, and a guard reading a table through raw SQL the scan cannot parse
shows as "not determined". Treat "fails open" as needing a fix and every other class
as needing confirmation in the remediation checkpoint.

**Proven fix pattern.** `2027_02_06_090000_create_payroll_posting_attestations.php`
runs `ALTER TABLE ... NO FORCE ROW LEVEL SECURITY` inside the migration's own
transaction before it reads. `PayrollAttestationRollbackGuardTest` runs that real
`down()` as an owner without `BYPASSRLS`, against a real row, and it refuses.

| Result | Migrations |
|---|---:|
| FAILS OPEN on a NOBYPASSRLS role | 27 |
| No table read in the guard | 12 |
| Reads only non-RLS tables (safe) | 6 |
| Lifts FORCE first (safe) | 2 |
| **Total** | **47** |

| Migration | Result | Tables named in the guard |
|---|---|---|
| `2026_10_18_090000_add_school_lifecycle_constraints.php` | Reads only non-RLS tables (safe) | school_elevations |
| `2026_10_31_090300_add_concession_failure_reasons_to_fee_assessment_run_items.php` | No table read in the guard | — |
| `2026_11_05_090000_add_lms_ownership_and_section_audiences.php` | No table read in the guard | — |
| `2026_11_09_090000_create_financial_periods.php` | FAILS OPEN on a NOBYPASSRLS role | financial_periods |
| `2026_11_09_090100_create_financial_period_baselines.php` | FAILS OPEN on a NOBYPASSRLS role | financial_period_account_balances, financial_period_charge_states |
| `2026_11_10_090000_add_finance_retention_expiry.php` | FAILS OPEN on a NOBYPASSRLS role | financial_period_expiries |
| `2026_11_12_090000_add_admissions_and_guardian_lifecycle_markers.php` | FAILS OPEN on a NOBYPASSRLS role | admission_applications, guardians |
| `2026_11_15_090000_add_payroll_evidence_retention.php` | FAILS OPEN on a NOBYPASSRLS role | payroll_runs |
| `2026_11_16_090000_add_user_minimization.php` | Reads only non-RLS tables (safe) | users |
| `2026_11_17_090000_create_leave_foundation.php` | FAILS OPEN on a NOBYPASSRLS role | leave_ledger_entries, leave_policy_assignments |
| `2026_11_18_090000_add_prospective_leave_year_configuration.php` | FAILS OPEN on a NOBYPASSRLS role | OLD, leave_year_start_changes, leave_years, starts_on |
| `2026_11_19_090000_create_leave_requests_and_year_close.php` | FAILS OPEN on a NOBYPASSRLS role | leave_ledger_entries, leave_requests, leave_year_closes |
| `2026_11_20_090000_create_staff_attendance.php` | FAILS OPEN on a NOBYPASSRLS role | staff_attendance_records |
| `2026_11_21_090000_allow_self_leave_decisions.php` | FAILS OPEN on a NOBYPASSRLS role | leave_decisions |
| `2026_11_22_090000_create_payroll_run_hrx_inputs.php` | FAILS OPEN on a NOBYPASSRLS role | payroll_run_hrx_inputs |
| `2026_11_24_090000_harden_hrx_retention_privileges.php` | Reads only non-RLS tables (safe) | PUBLIC, retention_school_holds |
| `2026_11_27_090000_make_retention_holds_authoritative.php` | Reads only non-RLS tables (safe) | PUBLIC, retention_holds, school_os_app |
| `2026_11_28_090000_harden_standalone_retention_functions.php` | No table read in the guard | — |
| `2026_11_29_090000_harden_payroll_lms_retention_units.php` | No table read in the guard | — |
| `2026_11_30_090000_harden_finance_student_guardian_retention_functions.php` | No table read in the guard | — |
| `2026_12_11_090000_end_teaching_ownership_with_employment.php` | Reads only non-RLS tables (safe) | OLD, PUBLIC, schools |
| `2026_12_12_090000_add_guardian_authorization_scope.php` | Lifts FORCE first (safe) | capabilities, membership_role_assignments, role_capabilities, roles |
| `2026_12_14_090000_harden_staff_role_catalogue.php` | Reads only non-RLS tables (safe) | capabilities, roles |
| `2026_12_16_090000_add_overlap_review_to_fee_assessment_run_items.php` | No table read in the guard | — |
| `2026_12_26_090000_add_charge_campus_attribution.php` | No table read in the guard | — |
| `2026_12_30_090000_guard_subledger_journal_reversals.php` | No table read in the guard | — |
| `2026_12_31_090000_guard_fee_concession_beneficiary_guardian_decisions.php` | No table read in the guard | — |
| `2027_01_01_090000_record_payroll_run_calculations_and_participant_approval.php` | FAILS OPEN on a NOBYPASSRLS role | payroll_run_calculations |
| `2027_01_02_090000_create_payment_correction_requests.php` | FAILS OPEN on a NOBYPASSRLS role | payment_correction_requests |
| `2027_01_03_090000_guard_leave_decision_participants.php` | No table read in the guard | — |
| `2027_01_04_090000_create_student_lifecycle_events.php` | FAILS OPEN on a NOBYPASSRLS role | student_lifecycle_events |
| `2027_01_04_090100_create_attendance_record_corrections.php` | FAILS OPEN on a NOBYPASSRLS role | attendance_record_corrections |
| `2027_01_04_090200_add_reason_code_to_library_fine_voids.php` | FAILS OPEN on a NOBYPASSRLS role | library_fine_voids |
| `2027_01_05_090100_record_charge_cancellation_assurance.php` | FAILS OPEN on a NOBYPASSRLS role | charges |
| `2027_01_11_090000_placement_start_within_year_and_pre_start_cancellation.php` | FAILS OPEN on a NOBYPASSRLS role | student_enrollments |
| `2027_01_21_090000_add_document_compliance.php` | FAILS OPEN on a NOBYPASSRLS role | applicant_documents, documents, school_os_retention, student_documents |
| `2027_01_25_090000_create_hr_workforce_foundation.php` | No table read in the guard | — |
| `2027_01_26_090000_create_reporting_relationships.php` | No table read in the guard | reporting_relationships |
| `2027_01_27_090000_create_hr_staff_profile_foundation.php` | FAILS OPEN on a NOBYPASSRLS role | employee_documents |
| `2027_01_28_090000_create_leave_policy_rules_and_eligibility.php` | FAILS OPEN on a NOBYPASSRLS role | leave_eligibility_rules, leave_eligibility_runs, leave_policies, leave_policy_assignments |
| `2027_01_29_090000_create_leave_accrual_and_opening.php` | FAILS OPEN on a NOBYPASSRLS role | leave_accrual_runs, leave_ledger_entries, leave_policies |
| `2027_01_30_090000_create_hr_separation_cases.php` | FAILS OPEN on a NOBYPASSRLS role | employment_separation_cases |
| `2027_01_31_090000_create_workforce_tasks.php` | FAILS OPEN on a NOBYPASSRLS role | workforce_tasks |
| `2027_02_01_090000_bind_payroll_approval_to_reviewed_evidence.php` | FAILS OPEN on a NOBYPASSRLS role | employee_compensation_assignments, payroll_runs |
| `2027_02_02_090000_record_payroll_compensation_sources.php` | FAILS OPEN on a NOBYPASSRLS role | OLD, payroll_run_calculation_sources, payroll_run_calculations, payroll_run_results, v_latest |
| `2027_02_03_090000_harden_employment_lifecycle_admission.php` | FAILS OPEN on a NOBYPASSRLS role | employee_assignments |
| `2027_02_06_090000_create_payroll_posting_attestations.php` | Lifts FORCE first (safe) | payroll_posting_attestations |
