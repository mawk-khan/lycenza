<?php

namespace App\Support\Operations;

use App\Support\Configuration\EnvironmentPolicy;
use App\Support\Database\RollbackSafeMigrator;
use App\Support\Retention\RetentionAnchors;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 0O.4A (ADR 0050 sections 6-7): read-only verification of the
 * production database role model, with PostgreSQL itself as the source of
 * truth. Runs on the RUNTIME connection (no admin credential needed) and
 * mutates nothing. Results are codes and statuses only.
 */
class DatabaseRoleVerifier
{
    public const RUNTIME_ROLE = 'school_os_app';

    /** E21-RH.2 (ADR 0066 §3.1): the dedicated retention identity. */
    public const RETENTION_ROLE = 'school_os_retention';

    /** E21-RH.2: the connection authenticated as RETENTION_ROLE (checked only where its credential is configured). */
    public const RETENTION_CONNECTION = 'pgsql_retention';

    public const MINIMUM_SERVER_VERSION = 160000;

    /**
     * The documented platform-resolvable bootstrap/platform records that
     * carry a school_id WITHOUT row-level security (TENANCY.md, ADR 0049/0050).
     * Any other table with a school_id must have forced RLS.
     */
    public const NON_RLS_SCHOOL_TABLES = [
        'api_client_credentials', 'api_clients', 'domain_event_outbox', 'email_provider_references', 'event_consumer_receipts',
        'school_domains', 'school_elevations', 'school_group_members', 'school_memberships',
        // E21.2F: platform compliance cases (operator console; scoped by School in code).
        'erasure_cases',
        // E21-RH.3: the authoritative retention holds; the runtime role has no privilege on them at all.
        'retention_holds',
    ];

    /**
     * HR.R1-C26 (R1-01 point 4, review M3): an allowlist, not a denylist. Every table of the HR family (HR, Leave,
     * Staff Attendance, WorkforceTasks) with exactly the runtime privileges its named operations need:
     * S = SELECT, I = INSERT, U = UPDATE. None holds DELETE or TRUNCATE; their rows leave only with their School
     * or through the retention identity. A new table of the family gets DELETE from the schema's default
     * privileges, so `HrWriterBoundaryTest` fails until it is classified here, and this check fails wherever a
     * privilege differs. Changing an entry is an R1-01 point 4 decision, never a fix to make a check pass.
     */
    public const HR_FAMILY_RUNTIME_PRIVILEGES = [
        // HR
        'employee_account_link_corrections' => 'SIU', 'employee_account_links' => 'S', 'employee_addresses' => 'SIU',
        'employee_assignments' => 'SIU', 'employee_categories' => 'SIU', 'employee_certifications' => 'SIU',
        // HR.R1-C29 (C14-N5, C29-B): verification decisions are inserted, never changed or deleted at runtime.
        'employee_credential_verifications' => 'SI',
        'employee_documents' => 'SIU', 'employee_emergency_contacts' => 'SIU', 'employee_experience_records' => 'SIU',
        'employee_notes' => 'SIU', 'employee_personal_details' => 'SIU', 'employee_placement_changes' => 'SI',
        'employee_qualifications' => 'SIU', 'employee_reference_codes' => 'SIU', 'employees' => 'SIU',
        'employment_records' => 'SIU', 'employment_separation_cases' => 'SIU', 'employment_separation_events' => 'SI',
        'employment_terms' => 'SI', 'hr_departments' => 'SIU', 'hr_employee_number_counters' => 'SIU',
        'positions' => 'SIU', 'reporting_relationships' => 'SIU', 'separation_clearance_items' => 'SIU',
        'separation_clearance_requirements' => 'SIU',
        // Leave, including the staff calendar (rule 149)
        'leave_accrual_run_items' => 'SI', 'leave_accrual_runs' => 'SIU', 'leave_allocation_runs' => 'SI',
        'leave_decisions' => 'SI', 'leave_eligibility_rules' => 'SI', 'leave_eligibility_runs' => 'SI',
        'leave_ledger_entries' => 'SI', 'leave_policies' => 'SIU', 'leave_policy_assignments' => 'SIU',
        'leave_request_days' => 'SI', 'leave_requests' => 'SIU', 'leave_settings' => 'SIU', 'leave_types' => 'SIU',
        'leave_year_close_items' => 'SI', 'leave_year_close_reconciliations' => 'SI', 'leave_year_closes' => 'SI',
        'leave_year_start_changes' => 'SI', 'leave_years' => 'SI', 'staff_holidays' => 'SIU',
        'staff_working_week_versions' => 'SIU', 'staff_working_weekdays' => 'S',
        // Staff Attendance (rule 147)
        'staff_attendance_corrections' => 'SI', 'staff_attendance_locked_corrections' => 'SIU',
        'staff_attendance_month_locks' => 'SI', 'staff_attendance_records' => 'SIU',
        // WorkforceTasks (rule 139)
        'workforce_task_categories' => 'SIU', 'workforce_task_events' => 'SI', 'workforce_task_generation_runs' => 'SIU',
        'workforce_task_template_versions' => 'SI', 'workforce_task_templates' => 'SIU', 'workforce_tasks' => 'SIU',
    ];

    /**
     * HR.R1-C28 (WO-13, C22-N1): every trigger on an HR-family table, bound through `pg_trigger.tgfoid` to exactly
     * this function, with the md5 of its whole `pg_get_triggerdef` (timing, events, `UPDATE OF` columns, `WHEN`,
     * row or statement, arguments, deferral). A family table not listed has no trigger. A new, dropped, re-pointed
     * or redefined trigger fails `hr_family_trigger_functions` until it is classified here.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const HR_FAMILY_TRIGGERS = [
        'employee_account_link_corrections' => ['trg_employee_account_link_corrections_apply' => ['employee_account_link_corrections_apply', 'ffdc25fefce189b9c5279f307901bc2d'], 'trg_employee_account_link_corrections_guard' => ['employee_account_link_corrections_guard', '9b0c5c4796eee271713cef0a69fe67ed']],
        'employee_addresses' => ['trg_employee_addresses_archive_guard' => ['hr_profile_record_archive_guard', '61f9a03187257a73bd1b7f81e8e42a07'], 'trg_retention_guard_employee_addresses' => ['retention_guard_retention_delete', '8def060bc737047643310b0753ef63d5']],
        'employee_assignments' => ['trg_employee_assignments_never_effective' => ['hr_employee_assignments_never_effective', '2661dc5957cce8abd37e92459399373d'], 'trg_employee_assignments_placement_identity' => ['hr_employee_assignments_placement_identity', '545d5627360416f888bb32fa1dc25c38'], 'trg_employee_assignments_primary_no_overlap' => ['hr_employee_assignments_primary_no_overlap', '3b00c5e4881f6f25dc11fd88c160d56d'], 'trg_retention_guard_employee_assignments' => ['retention_guard_retention_delete', 'd61b64f098a5da2872fd93c85aefa682'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '1a3eaf2f7f38ae5f2715c745019e2973']],
        'employee_certifications' => ['trg_employee_certifications_archive_guard' => ['hr_profile_record_archive_guard', '9dddf0a90994aecb3ab24886f88984a8'], 'trg_employee_certifications_revision_guard' => [
            'hr_credential_revision_guard',
            '5a57dbcf34cd0765d201027dd93cade5',
        ], 'trg_retention_guard_employee_certifications' => ['retention_guard_retention_delete', '79c1ee092934cad9eb02f2c59b7823d9']],
        'employee_credential_verifications' => ['trg_employee_credential_verifications_apply' => [
            'employee_credential_verifications_apply',
            '497859c03a3491730c9fdf2f7419878f',
        ], 'trg_employee_credential_verifications_guard' => [
            'employee_credential_verifications_guard',
            '75075a81fa8a760a77304eba5c69606f',
        ], 'trg_employee_credential_verifications_immutable' => ['hr_history_row_immutable', '3ee96f192e066ec847b19fe58f9ddca5']],
        'employee_documents' => ['trg_employee_documents_guard' => ['hr_employee_documents_guard', 'a4871a51a716ee37b17e542068532f5b'], 'trg_retention_guard_employee_documents' => ['retention_guard_retention_delete', '0468012607415c852be6ee0ebf9403f2'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'bd4ee16d0f77e0dd202ef0ce2d6e1a75']],
        'employee_emergency_contacts' => ['trg_employee_emergency_contacts_archive_guard' => ['hr_profile_record_archive_guard', '72d689eeae02dc4a61b6df50f4fe7481'], 'trg_retention_guard_employee_emergency_contacts' => ['retention_guard_retention_delete', '4fa101d2f8e369cf26f5347603a1232c']],
        'employee_experience_records' => ['trg_employee_experience_records_archive_guard' => ['hr_profile_record_archive_guard', 'dfd7609e4729471cba96a159c0566c03'], 'trg_retention_guard_employee_experience_records' => ['retention_guard_retention_delete', '361c096b239f60a4cf3a387f1b017321']],
        'employee_notes' => ['trg_employee_notes_archive_guard' => ['hr_profile_record_archive_guard', '4903a61349d030a47542fcd2130c4baf'], 'trg_retention_guard_employee_notes' => ['retention_guard_retention_delete', 'ba7a5b08c7c98959a972fba876eb3580']],
        'employee_personal_details' => ['trg_retention_guard_employee_personal_details' => ['retention_guard_retention_delete', '6b1c7c8be05881933269dd9ba2405996'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '8f1b7f8d11fb99eb4968debb6b0cf5d0']],
        'employee_placement_changes' => ['trg_employee_placement_changes_guard' => ['hr_employee_placement_changes_guard', '4f4c6cbaec6ced377185ef8924480a8f'], 'trg_employee_placement_changes_immutable' => ['hr_history_row_immutable', 'e19758909ec27bdfeb9c56c99469afc8']],
        'employee_qualifications' => ['trg_employee_qualifications_archive_guard' => ['hr_profile_record_archive_guard', 'b4dc08ab178a296ed2007eb1f69e78f5'], 'trg_employee_qualifications_revision_guard' => [
            'hr_credential_revision_guard',
            '60cd649a3094d88c94bb84c48bf5aa9e',
        ], 'trg_retention_guard_employee_qualifications' => ['retention_guard_retention_delete', '1d9e38531578880afa7b80c6a07251cc']],
        'employee_reference_codes' => ['trg_employee_reference_codes_guard' => ['hr_employee_reference_codes_guard', '0b7ab1eb7d86f23c9eded153220b598b']],
        'employees' => ['trg_employees_identity_guard' => ['hr_employees_identity_guard', 'f5642bb32d72676e5d42426b4516d320'], 'trg_employees_not_minimized' => ['users_guard_principal_column', '4faf68edf809c425f443299f009392cc'], 'trg_employees_record_account_link_insert' => ['employees_record_account_link', '6bbe0f32a9c1704a7e993f7ae5e2e1a3'], 'trg_employees_record_account_link_update' => ['employees_record_account_link', 'aff1effce0fa7ba3860353ce2406af27'], 'trg_employees_user_link_payroll_lock' => ['employees_user_link_payroll_lock', 'cf1a0d346147b02d4dd45353f27df815'], 'trg_retention_guard_employees' => ['retention_guard_retention_delete', 'c071ab3f981d06fa2b0f63948707bd62'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'eb0280bc1a8f394efcfdcf52bbb846d9']],
        'employment_records' => ['trg_employment_records_after_death' => ['hr_employment_records_after_death', '2fb7bab266e160e323492b7cf842c802'], 'trg_employment_records_archived_identity' => ['hr_employment_records_archived_identity', '158d87b2b217a1b2ffd66c55298deba8'], 'trg_employment_records_eligibility_guard' => ['retention_guard_end_record', 'bcae3e03439b160b6577916aa07fd514'], 'trg_employment_records_initial_terms' => ['hr_employment_records_initial_terms', '45ea7bbe6be0f56ae3d49aabf8418761'], 'trg_employment_records_no_overlap' => ['hr_employment_records_no_overlap', 'a392e50be9187b953a4cdea7e1389d2a'], 'trg_employment_records_reporting_bounds' => ['hr_employment_records_reporting_bounds', 'd514b90a50e45c732cddad5446050d26'], 'trg_employment_records_separation_notice' => ['hr_employment_records_separation_notice', '4a662a357cceebd720ec0b5af865fefb'], 'trg_employment_records_terms_projection_guard' => ['hr_employment_records_terms_projection_guard', '2ff452333404c3bf147071baaef34869'], 'trg_retention_guard_employment_records' => ['retention_guard_retention_delete', '729dad82a8f562daee589288ce8ec1e4'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'ec6d4769d0a303bb874fa0e854020758']],
        'employment_separation_cases' => [
            'trg_employment_separation_cases_guard' => ['hr_employment_separation_cases_guard', 'ffd7a3dd40a02c10c40427566d1821e8'],
            // HR.R1-C35 WO-20 (HRC-4): kept access binds exactly the live School grant set, immutable, never back-filled.
            'trg_employment_separation_cases_retained_grants' => ['hr_separation_retained_grants_guard', 'f6bcae75b805af8bc10f58b9c22ddc9f'],
            // HR.R1-C35 WO-21 (HRC-8): the approval records its checklist's staff type and the requirements it did not apply.
            'trg_employment_separation_cases_staff_type_basis' => ['hr_separation_clearance_basis_guard', '15f067ddee77fdf1499568ec83dd1397'],
        ],
        'employment_terms' => ['trg_employment_terms_append_guard' => ['hr_employment_terms_append_guard', '9a11a1bca26e5540a63a4f62300a4864'], 'trg_employment_terms_immutable' => ['hr_history_row_immutable', 'c12c92b4e208b4a9904ec096b1b5234e'], 'trg_employment_terms_project' => ['hr_employment_terms_project', 'bf3d9dbf6a507ea1c7dd66b60679d454']],
        'hr_departments' => ['trg_hr_departments_no_cycle' => ['hr_departments_no_cycle', 'd4b31242d9290f08f32c92f8df989ca5']],
        'leave_accrual_run_items' => ['trg_leave_accrual_run_items_guard' => ['leave_accrual_run_items_guard', '94eafef88296a178a41e2dbe9bb509ef']],
        'leave_accrual_runs' => ['trg_leave_accrual_runs_guard' => ['leave_accrual_runs_guard', 'e38187ee4f511f9c57073459d30fc751']],
        'leave_decisions' => ['trg_leave_decisions_guard' => ['leave_decisions_guard', 'c827aa6abf87b8ada41cd6ca1ebe2f59'], 'trg_leave_decisions_participant_guard' => ['leave_decisions_refuse_submitter', 'd9c14d4d59a6aca53ed3ac4dc4d980ce'], 'trg_retention_guard_leave_decisions' => ['retention_guard_retention_delete', '8e5de7f47651ebb2fd71c310669c6f8c'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '1e559df84152b1ba72a2780a32153a23']],
        'leave_eligibility_rules' => ['trg_leave_eligibility_rules_guard' => ['leave_eligibility_rules_guard', 'c7b189cf7734f5c2aabefa18a3ad4746']],
        'leave_eligibility_runs' => ['trg_leave_eligibility_runs_immutable' => ['leave_eligibility_runs_immutable', '033af45deec9771b5881af8e813c18f8']],
        'leave_ledger_entries' => ['trg_leave_ledger_guard' => ['leave_ledger_guard', 'da362ae2fd096726545795c699bda688'], 'trg_retention_guard_leave_ledger_entries' => ['retention_guard_retention_delete', '1419c21767a8e402a1ecd82018b58862'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'd550aec5dca759d58f6d9e5d2adf5587']],
        'leave_policies' => ['trg_leave_policies_immutable' => ['leave_policies_immutable', '5bbe5cbd25094ae8eff70a38720bed57'], 'trg_leave_policies_rules_complete' => ['leave_policies_rules_complete', '11d6e95357f0f068e31cda8c2d95dd2f']],
        'leave_policy_assignments' => ['trg_leave_policy_assignments_accrual_bounds' => ['leave_policy_assignments_accrual_bounds', 'db1e66783f770bc2bd9ad83f665af090'], 'trg_leave_policy_assignments_guard' => ['leave_policy_assignments_guard', '7147300d9ddfc8c1e5cfc2dd73f2dfff'], 'trg_retention_guard_leave_policy_assignments' => ['retention_guard_retention_delete', '1cc1f3f3009dfdac1deaa168d6bf0b55'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '24f1ab8ec5c428a30b970bde3004b5fa']],
        'leave_request_days' => ['trg_leave_request_days_guard' => ['leave_request_days_guard', '57d9b0b4b915f0a969a12e5aa8fe48bc'], 'trg_retention_guard_leave_request_days' => ['retention_guard_retention_delete', '1abb2a20d6eed35682611ba4eca9c252'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'ea3ad2c318190100acdd66ce94dd7245']],
        'leave_requests' => ['trg_leave_requests_guard' => ['leave_requests_guard', 'fbcae3257c574ffbd8276c1a27fc8055'], 'trg_retention_guard_leave_requests' => ['retention_guard_retention_delete', '9c0e3aedec2e5dc4c4dd8ec7ef17c2a8'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '2fe36e156ded2098abe66275015d3f63']],
        'leave_settings' => ['trg_leave_settings_lock' => ['leave_settings_lock', '7711c5d4dedb982f1ae8ed79ef90b116']],
        'leave_types' => ['trg_leave_types_freeze' => ['leave_types_freeze', 'cc60dced480d1da676eac8e122ca61f0']],
        'leave_year_close_items' => ['trg_retention_guard_leave_year_close_items' => ['retention_guard_retention_delete', '2a79abdca35d19f66209d2c437beefdd'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'dee42d831aefb0cb844f9b275ec487c5']],
        'leave_year_close_reconciliations' => ['trg_retention_guard_leave_year_close_reconciliations' => ['retention_guard_retention_delete', '28ed95f131ea9e83ae3f50af2be32174'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'fac963394a49ade228ce52a2701d2483']],
        'leave_year_start_changes' => ['trg_leave_year_start_changes_guard' => ['leave_year_start_changes_guard', '04c75ba2e4d62433986e7319ed0aa605']],
        'leave_years' => ['trg_leave_years_guard' => ['leave_years_guard', '7d4d630eeae2cd45cbab4f47dee4963a']],
        'reporting_relationships' => ['trg_reporting_relationships_guard' => ['hr_reporting_relationships_guard', '8647badeab0f5c7d9299bb938b1612c6']],
        'separation_clearance_items' => [
            'trg_separation_clearance_items_guard' => ['hr_separation_clearance_items_guard', 'cc68ae994237e4ba8bb07a58f2ca9352'],
            // HR.R1-C35 WO-21 (HRC-8, review LOW 3): an item belongs to the staff type its checklist recorded.
            'trg_separation_clearance_items_staff_type_basis' => ['hr_separation_clearance_item_basis_guard', '04aa9a4e01a6f461b753dd4deac58ade'],
        ],
        'separation_clearance_requirements' => ['trg_separation_clearance_requirements_guard' => ['hr_separation_clearance_requirements_guard', 'c3685ff63658c8e7f00522c111302b08']],
        'staff_attendance_corrections' => ['trg_retention_guard_staff_attendance_corrections' => ['retention_guard_retention_delete', '598f49e04e741a18e26224ffefe86993'], 'trg_staff_attendance_corrections_applied' => ['staff_attendance_corrections_applied', '481da3ea852419b2f4e17afee9d20969'], 'trg_staff_attendance_corrections_guard' => ['staff_attendance_corrections_guard', 'c55cc865210e86f6514ef6ace252839e'], 'trg_staff_attendance_corrections_month_guard' => ['staff_attendance_corrections_month_guard', '4f484fa568d9014d4b34db43542ca84c'], 'zzz_retention_anchor' => ['retention_stamp_anchor', 'f5a3752ad0aa7339113b3823a1312a81']],
        'staff_attendance_locked_corrections' => ['trg_retention_guard_staff_attendance_locked_corrections' => ['retention_guard_retention_delete', 'a06244edd1d6dedd72553f33fd0797e9'], 'trg_staff_attendance_locked_corrections_apply' => ['staff_attendance_locked_corrections_apply', 'a77fdb5753f1c08a7f4ec612723b5a45'], 'trg_staff_attendance_locked_corrections_guard' => ['staff_attendance_locked_corrections_guard', 'a5286fbc424f1805f5b27e9c775514f5'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '20bc7aea6456832c67245ba3de83c163']],
        'staff_attendance_month_locks' => ['trg_staff_attendance_month_locks_guard' => ['staff_attendance_month_locks_guard', 'bc94e1314db90e7f11672f2c2d606294']],
        'staff_attendance_records' => ['trg_retention_guard_staff_attendance_records' => ['retention_guard_retention_delete', 'bc00a1bc482d7c33d5f35668434aca05'], 'trg_staff_attendance_records_guard' => ['staff_attendance_records_guard', '4e95458c8c4b0a0f12b78730114ad329'], 'trg_staff_attendance_records_month_guard' => ['staff_attendance_records_month_guard', '91f69bcd18f21f92063b38689634ea34'], 'zzz_retention_anchor' => ['retention_stamp_anchor', '2c9059c13af6ed766b75bf3c25d336d3']],
        'staff_holidays' => ['trg_staff_attendance_holiday_month_guard' => ['staff_attendance_calendar_month_guard', '8b965ccf99d1621e1ba412d0d21dd8a7'], 'trg_staff_holidays_history_guard' => ['staff_holidays_history_guard', '816645bc2eb5e6fb8b0aa0844be12589']],
        'staff_working_week_versions' => ['trg_staff_attendance_calendar_month_guard' => ['staff_attendance_calendar_month_guard', 'd65201ced651b50e7f35a8de4d7e4c7a'], 'trg_staff_working_week_versions_guard' => ['staff_working_week_versions_guard', 'bf387aea8c71ff42886059f97b1558ac']],
        'workforce_task_events' => ['trg_workforce_task_events_guard' => ['hr_workforce_task_events_guard', '487a2ddbec8a2fff22cc7e8d5e5f5bab']],
        'workforce_tasks' => ['trg_workforce_tasks_guard' => ['hr_workforce_tasks_guard', 'af7cd30948efee3d8c0146bc8b5efaec']],
    ];

    /**
     * HR.R1-C28 (WO-13): the only SECURITY DEFINER functions behind an HR-family trigger, with their pinned
     * `search_path`: the retention delete guard (E21), the account-link history and its approved correction
     * (rule 145), and the approved locked-month correction (rule 147). Every other family trigger function is
     * SECURITY INVOKER with `search_path` pinned and `pg_temp` last (C28-N2).
     */
    public const HR_FAMILY_DEFINER_FUNCTIONS = [
        'retention_guard_retention_delete' => 'pg_catalog, pg_temp',
        'employees_record_account_link' => 'pg_catalog, public, pg_temp',
        'employee_account_link_corrections_apply' => 'pg_catalog, public, pg_temp',
        'staff_attendance_locked_corrections_apply' => 'pg_catalog, public, pg_temp',
    ];

    /** HR.R1-C28 (C28-N2): an INVOKER guard's path names only these schemas and puts the temporary schema last. */
    public const HR_FAMILY_INVOKER_SEARCH_PATHS = ['pg_catalog, pg_temp', 'pg_catalog, public, pg_temp'];

    /** Tables whose history the runtime role must never delete. */
    public const NO_RUNTIME_DELETE = [
        // HR.R1-C20 (WO-6, rule 147): a month lock never goes, and a locked-month request leaves only by retention.
        'staff_attendance_month_locks', 'staff_attendance_locked_corrections',
        // HR.R1-C22 (WO-5, S-16): the staff calendar's history is withdrawn or superseded, never deleted; the retired week is read-only.
        'staff_working_week_versions', 'staff_holidays', 'staff_working_weekdays',
        // HR.R1-C26 (WO-11, C12-N2, R1-01 point 4): HR configuration is deactivated, never deleted; it leaves only with its School.
        'hr_departments', 'positions', 'employee_categories', 'hr_employee_number_counters',
        'schools', 'platform_role_assignments', 'school_groups', 'api_clients', 'api_client_credentials',
        'platform_audit_events', 'school_audit_events', 'email_suppressions',
        'membership_role_assignments',
        // E21.2B: authority history expired only through the retention functions.
        'teaching_assignments', 'group_role_assignments', 'school_elevations',
        // TCH-E (ADR 0063 section 45): elective teaching ownership, the same authority history.
        'elective_teaching_assignments',
        // ORG.9D-3 (ADR 0076 §75): School calendar Events -- published history; expired only by their retention functions.
        'communication_events',
        // E21.2F: erasure cases expire only through their retention function.
        'erasure_cases',
        // E21.3A (ADR 0064): financial periods and their close baselines.
        'financial_periods', 'financial_period_account_balances', 'financial_period_charge_states',
        // E21.3A2: Finance ledgers lose detail only through retention_expire_finance_unit.
        'financial_period_expiries', 'journal_entries', 'journal_lines', 'charges', 'payments', 'payment_allocations', 'payment_receipts',
        // UAT-C3: payment corrections are append-only Finance history.
        'payment_corrections', 'payment_correction_lines',
        // ORG.8C (ADR 0076 §61): correction requests are approval history; unexecuted ones leave only with their Payment.
        'payment_correction_requests',
        // ORG.8E (ADR 0076 §63): lifecycle and attendance-correction evidence is append-only; it leaves only with its parent.
        'student_lifecycle_events', 'attendance_record_corrections',
        // HR.1A: employment terms and named placement changes are append-only; they go only with their employment.
        'employment_terms', 'employee_placement_changes',
        // HR.3A: reporting lines are ended or voided, never deleted; they go only with their subordinate employment.
        'reporting_relationships',
        // HR.12: separation cases, their timeline and clearance go only with their employment; requirements are retired.
        'employment_separation_cases', 'employment_separation_events', 'separation_clearance_items', 'separation_clearance_requirements',
        // HR.13: staff tasks, their timeline and configuration are never deleted at runtime.
        'workforce_task_categories', 'workforce_task_templates', 'workforce_task_template_versions', 'workforce_tasks', 'workforce_task_events', 'workforce_task_generation_runs',
        // HR.2A (S-16, OD-HR-13): ancillary profile records are archived, never deleted; HR document
        // records are archived; reference codes are retired. Only retention purges any of them.
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications',
        'employee_experience_records', 'employee_certifications', 'employee_documents', 'employee_reference_codes',
        // HR.R1-C29 (C14-N5): verification decisions leave only with their credential (FK cascade).
        'employee_credential_verifications',
        // E21.3B: Student consent evidence leaves only with its core record, through its retention function.
        'communication_domain_consent_events',
        // E21.3F: posted payroll adjustments and postings leave only through the payroll retention functions.
        'payroll_adjustments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        // E21-RH.1: posted LWF annual charges leave only through retention_expire_payroll_employee_evidence.
        'payroll_lwf_annual_charges',
        // ORG.8B (ADR 0076 §60): who calculated a run is approval provenance; it leaves only with its run.
        'payroll_run_calculations',
        // HR.R1-C1: the compensation a calculation rested on is approval provenance; it leaves only with its calculation.
        'payroll_run_calculation_sources',
        // HR.R1-C4: a posting attestation is approval and posting evidence; it leaves with its posting.
        'payroll_posting_attestations',
        // HR.R1-C6: reconciliation evidence leaves with its run.
        'payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews',
        // HR.R1-C7/C8: account link history and its correction decisions leave with their Employee (FK cascade).
        'employee_account_links', 'employee_account_link_corrections',
        // E21.4 (F1): a User is never hard-deleted; a raw delete would null audit/grant actors and cascade memberships.
        'users',
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only Finance evidence.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
        // OPF.3 (ADR 0067 §29): and Admissions'.
        'admission_fee_selections',
        // OPF.4 (ADR 0067 §30): Library fine evidence, its voids and the immutable policy versions.
        'library_fines', 'library_fine_voids', 'library_fine_policies',
        // RES.2 (ADR 0068 §6.1, §19.3 a): StudentMark is never runtime-deleted; its value history is insert-only.
        'student_marks', 'student_mark_revisions',
        // RES.3 (ADR 0068 §21): the per-paper marks lock and the append-only correction requests.
        'examination_paper_mark_states', 'student_mark_corrections',
        // ORG.7H-1R (ADR 0076 §55): Inventory Location-use evidence, so a used Location never looks unused again.
        'stock_movements', 'inventory_stock_balances', 'canteen_orders',
    ];

    /**
     * E21-RH.1: tables whose rows the runtime role must never rewrite. The
     * destructive-privilege check covers both lists (privilege => tables);
     * a table joins this list only with evidence that no runtime path updates
     * it.
     */
    public const NO_RUNTIME_UPDATE = [
        // HR.R1-C20 (WO-6, rule 147): a month lock is never moved or reopened.
        'staff_attendance_month_locks',
        // HR.R1-C22 (WO-5): the retired week is read-only for the runtime role.
        'staff_working_weekdays',
        // E21-RH.1: posted LWF annual charges are insert-once evidence.
        'payroll_lwf_annual_charges',
        // ORG.8B (ADR 0076 §60): a recorded calculation never changes.
        'payroll_run_calculations',
        // HR.R1-C1: recorded compensation provenance never changes.
        'payroll_run_calculation_sources',
        // HR.R1-C4: a recorded attestation never changes.
        'payroll_posting_attestations',
        // HR.R1-C6: an obligation and its review never change; there is no status to update.
        'payroll_reversal_reconciliations', 'payroll_reversal_reconciliation_reviews',
        // HR.R1-C7: written only by the trigger on employees.user_id and the correction trigger.
        'employee_account_links',
        // ORG.8E (ADR 0076 §63): evidence rows are never rewritten.
        'student_lifecycle_events', 'attendance_record_corrections',
        // OPF.1 (ADR 0067 §12): Transport's fee-selection provenance is insert-only.
        'transport_fee_selections',
        // OPF.2 (ADR 0067 §28): so is Hostel's.
        'hostel_fee_selections',
        // OPF.3 (ADR 0067 §29): and Admissions'.
        'admission_fee_selections',
        // OPF.4 (ADR 0067 §30): Library fine evidence, its voids and the immutable policy versions.
        'library_fines', 'library_fine_voids', 'library_fine_policies',
        // RES.2 (ADR 0068 §19.3 a): StudentMark value history is never rewritten.
        'student_mark_revisions',
        // ORG.7H-1R (ADR 0076 §55): the Inventory movement ledger is append-only.
        'stock_movements',
        // HR.1A: an employment's terms and its named placement changes are never rewritten.
        'employment_terms', 'employee_placement_changes',
    ];

    /**
     * E21.3A (ADR 0064 §3): the one narrow Finance definer function, the
     * backfill's NULL -> containing-open-period assignment. Same shape rules
     * as the retention functions.
     */
    public const FINANCE_FUNCTIONS = ['finance_assign_journal_entry_period'];

    /**
     * E21-RH.5: the Payroll and LMS unit functions, executable only by the
     * retention identity and called inside its whole-unit transaction. Each
     * refuses any other session user and any active platform or School hold
     * (dry runs included). `retention_expire_lms_resource` is the whole
     * destructive LMS unit (its Documents, then the LMS function).
     */
    public const UNIT_RETENTION_FUNCTIONS = [
        // E21.3F (E21-D9): one Employee's posted payroll evidence, separation-floored; one emptied payroll run
        'retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run',
        // E21.3D (E21.2G A1): one LMS resource with its audiences, year- and D6-floored; the unit with its Documents
        'retention_expire_learning_content', 'retention_expire_assignment', 'retention_expire_lms_resource',
        // E21-RH.6: one settled Finance unit (D8); one Student's core evidence (D7); one Guardian's consent events
        'retention_expire_finance_unit', 'retention_expire_student_processing_authorizations', 'retention_expire_student_consent_events',
        'retention_expire_guardian_consent_events',
        // E21-RH.6: the one UPDATE the Communications purge needs (break the announcement <-> message cycle)
        'retention_unlink_announcement_message',
    ];

    /**
     * E21-RH.6 (ADR 0066 §14): the tables the PHP retention units delete
     * from as the retention identity. The role holds DELETE (and SELECT) on
     * exactly these, never INSERT or UPDATE, and each carries the hold guard
     * `retention_guard_retention_delete` (a retention-session delete of a
     * held School or under a platform hold is refused in PostgreSQL).
     */
    public const RETENTION_DELETES = [
        'students', 'student_enrollments', 'student_subject_enrollments', 'student_guardian_relationships', 'student_guardian_account_links',
        'attendance_records', 'enrollment_rollover_items', 'library_loans', 'transport_student_assignments', 'hostel_residency_assignments',
        'admission_applications', 'applicants', 'communication_domain_preferences', 'documents',
        'guardians', 'guardian_contacts',
        'employees', 'employment_records', 'employee_assignments', 'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications', 'employee_personal_details', 'employee_documents',
        'employee_compensation_assignments', 'employee_statutory_identifiers', 'employee_tax_profile', 'employee_pf_status', 'employee_esi_coverage',
        'curriculum_deliveries', 'attendance_sessions', 'timetable_entries', 'transport_route_assignments', 'automation_executions',
        'visitors', 'visitor_visits', 'identity_account_invitations',
        'communication_deliveries', 'communication_messages', 'communication_announcements', 'communication_threads',
        'email_messages', 'email_provider_references', 'email_events', 'domain_event_outbox', 'event_consumer_receipts', 'webhook_deliveries', 'failed_jobs',
    ];

    /** E21-RH.6: where the product (or the framework) deletes too, so the runtime role keeps DELETE. */
    public const RUNTIME_PRODUCT_DELETES = [
        // HR.2A (S-16): the six HR profile tables left this list -- their records are archived, never deleted.
        'student_guardian_relationships', 'failed_jobs',
    ];

    /** E21-RH.6: tables whose rows only retention removes, in the database since E21-RH.5 (runtime DELETE revoked). */
    public const RUNTIME_DELETES_REVOKED_EXTRA = ['learning_content', 'assignments', 'student_processing_authorizations'];

    /** E21-RH.6: read-only tables the moved units read whole (production readers); table-level SELECT only. */
    public const RETENTION_TABLE_READS = [
        'schools', 'academic_years', 'subject_offerings', 'sections', 'school_memberships', 'school_settings',
        'student_processing_authorizations', 'communication_domain_consent_events',
        'financial_periods', 'financial_period_account_balances', 'financial_period_expiries', 'financial_period_charge_states',
        'journal_entries', 'journal_lines', 'ledger_accounts', 'charges', 'payments', 'payment_allocations', 'payment_receipt_counters',
        // UAT-C3: the charge-state read the finance retention unit runs includes payment corrections.
        'payment_corrections', 'payment_correction_lines',
        'late_fee_assessments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        'fee_adjustments', 'fee_assessments', 'fee_heads', 'fee_structure_installments',
        'communication_approval_requests', 'communication_attachments',
        // The email prune keeps an event a current suppression still rests on (it reads only whether one exists)
        'email_suppressions',
        // SIS 11.5: which Documents an expiring application's pre-admission records name.
        'applicant_documents',
    ];

    /**
     * HR.1A: the database backstops of the effective-dated workforce model --
     * overlap (employments, primary assignments), append-only terms and
     * placement history, the terms projection, Employee identity and the
     * Department hierarchy. trigger => table; each must exist and be enabled.
     */
    public const HR_WORKFORCE_GUARDS = [
        'trg_employment_records_no_overlap' => 'employment_records',
        'trg_employment_records_initial_terms' => 'employment_records',
        'trg_employment_records_terms_projection_guard' => 'employment_records',
        'trg_employee_assignments_primary_no_overlap' => 'employee_assignments',
        'trg_employment_terms_append_guard' => 'employment_terms',
        'trg_employment_terms_immutable' => 'employment_terms',
        'trg_employment_terms_project' => 'employment_terms',
        'trg_employee_placement_changes_guard' => 'employee_placement_changes',
        'trg_employee_placement_changes_immutable' => 'employee_placement_changes',
        'trg_employees_identity_guard' => 'employees',
        'trg_hr_departments_no_cycle' => 'hr_departments',
    ];

    /**
     * HR.3A: the reporting-line backstops -- one line per subordinate per day,
     * never oneself, within both employments, no loop on any day, immutable
     * parties and start, the end only narrows; and an employment never ends
     * while a live line outlives it. trigger => table.
     */
    public const HR_REPORTING_GUARDS = [
        'trg_reporting_relationships_guard' => 'reporting_relationships',
        'trg_employment_records_reporting_bounds' => 'employment_records',
    ];

    /**
     * HR.12: the separation backstops -- the case graph, evidence, maker/checker
     * and "never the subject"; clearance snapshots cleared once; requirements
     * retired, never edited; notice_period only under an approved case.
     * trigger => table.
     */
    public const HR_SEPARATION_GUARDS = [
        'trg_employment_separation_cases_guard' => 'employment_separation_cases',
        'trg_employment_separation_cases_retained_grants' => 'employment_separation_cases',
        'trg_employment_separation_cases_staff_type_basis' => 'employment_separation_cases',
        'trg_separation_clearance_items_staff_type_basis' => 'separation_clearance_items',
        'trg_separation_clearance_items_guard' => 'separation_clearance_items',
        'trg_separation_clearance_requirements_guard' => 'separation_clearance_requirements',
        'trg_employment_records_separation_notice' => 'employment_records',
    ];

    /** HR.12: the tenant-owned separation tables (forced RLS each). */
    public const HR_SEPARATION_TABLES = ['employment_separation_cases', 'employment_separation_events', 'separation_clearance_requirements', 'separation_clearance_items'];

    /**
     * HR.9 (ADR 0065 §28): the leave policy backstops -- a version's
     * eligibility rules are immutable, never beyond its declared count and
     * never ambiguous against another active version; the declared count is
     * complete at commit; an eligibility-run header never changes.
     * trigger => table.
     */
    public const LEAVE_POLICY_GUARDS = [
        'trg_leave_eligibility_rules_guard' => 'leave_eligibility_rules',
        'trg_leave_policies_rules_complete' => 'leave_policies',
        'trg_leave_eligibility_runs_immutable' => 'leave_eligibility_runs',
        'trg_leave_policies_immutable' => 'leave_policies',
        // HR.10 (ADR 0065 §29): the accrual and opening checks live in the ledger guard; runs move forward only; run evidence is append-only.
        'trg_leave_ledger_guard' => 'leave_ledger_entries',
        'trg_leave_accrual_runs_guard' => 'leave_accrual_runs',
        'trg_leave_accrual_run_items_guard' => 'leave_accrual_run_items',
        'trg_leave_policy_assignments_accrual_bounds' => 'leave_policy_assignments',
    ];

    /**
     * HR.2A: the staff-profile backstops -- the archive guard on each ancillary
     * record table, reference codes immutable and never reused, HR document
     * records bound to a governed Document. trigger => table.
     */
    public const HR_PROFILE_GUARDS = [
        'trg_employee_addresses_archive_guard' => 'employee_addresses',
        'trg_employee_emergency_contacts_archive_guard' => 'employee_emergency_contacts',
        'trg_employee_notes_archive_guard' => 'employee_notes',
        'trg_employee_qualifications_archive_guard' => 'employee_qualifications',
        'trg_employee_experience_records_archive_guard' => 'employee_experience_records',
        'trg_employee_certifications_archive_guard' => 'employee_certifications',
        'trg_employee_reference_codes_guard' => 'employee_reference_codes',
        'trg_employee_documents_guard' => 'employee_documents',
    ];

    /**
     * HR.R1-C20 (WO-6, rule 147): the Staff Attendance month-lock backstops. trigger => [table, function].
     * The four guards take the School-month key and refuse any isolation but READ COMMITTED; the apply
     * trigger is the only path that changes a locked month.
     */
    public const STAFF_ATTENDANCE_MONTH_LOCK_GUARDS = [
        'trg_staff_attendance_month_locks_guard' => ['staff_attendance_month_locks', 'staff_attendance_month_locks_guard'],
        'trg_staff_attendance_records_month_guard' => ['staff_attendance_records', 'staff_attendance_records_month_guard'],
        'trg_staff_attendance_corrections_month_guard' => ['staff_attendance_corrections', 'staff_attendance_corrections_month_guard'],
        'trg_staff_attendance_locked_corrections_guard' => ['staff_attendance_locked_corrections', 'staff_attendance_locked_corrections_guard'],
        'trg_staff_attendance_locked_corrections_apply' => ['staff_attendance_locked_corrections', 'staff_attendance_locked_corrections_apply'],
    ];

    /** HR.3A: the indexes the reporting readers rely on (bounded lookups by subordinate, manager and date). */
    public const HR_REPORTING_INDEXES = [
        'reporting_relationships_subordinate_idx', 'reporting_relationships_manager_idx',
        'reporting_relationships_school_date_idx', 'reporting_relationships_open_idx',
    ];

    /** E21-RH.6: the database guards against manufactured retention eligibility and the User-erasure hold boundary. */
    public const ELIGIBILITY_GUARDS = [
        'employment_records' => 'trg_employment_records_eligibility_guard',
        'student_enrollments' => 'trg_student_enrollments_eligibility_guard',
        'erasure_cases' => 'trg_erasure_cases_guard_transition',
        'users' => 'users_guard_minimization_hold',
    ];

    /**
     * E21-RH.2 / RH.5: the exact column-level SELECTs of the retention
     * identity -- what its whole-unit PHP reads need, nothing else (no
     * table-level grant, no write). table => sorted columns.
     */
    /** E21-RH.7: the irreversible security migrations a rollback must never have passed. */
    public const RETENTION_FENCES = [
        '2026_12_01_090000_fence_retention_eligibility_guards',
        '2026_12_01_090100_anchor_retention_eligibility_clocks',
    ];

    public const RETENTION_SELECTS = [
        // E21-RH.2: the HRX units
        'employees' => ['id', 'school_id'],
        'employment_records' => ['employee_id', 'ends_on', 'id', 'status'],
        'leave_ledger_entries' => ['employment_record_id'],
        'leave_policy_assignments' => ['employment_record_id'],
        'leave_requests' => ['employee_id'],
        'leave_year_close_items' => ['employment_record_id'],
        'staff_attendance_records' => ['employee_id'],
        // E21-RH.5: the Payroll units
        'payroll_adjustments' => ['employment_record_id'],
        'payroll_lwf_annual_charges' => ['employment_record_id'],
        'payroll_run_results' => ['employee_id', 'payroll_run_id'],
        'payroll_runs' => ['id', 'posted_at', 'results_expired_at', 'run_kind', 'school_id', 'status'],
        // E21-RH.5: the LMS units
        'academic_years' => ['ends_on', 'id', 'school_id'],
        'assignment_section_audiences' => ['assignment_id', 'school_id', 'section_id'],
        'assignments' => ['id', 'owner_employee_id', 'school_id', 'subject_offering_id'],
        'documents' => ['assignment_id', 'id', 'learning_content_id'],
        'learning_content' => ['id', 'owner_employee_id', 'school_id', 'subject_offering_id'],
        'learning_content_section_audiences' => ['learning_content_id', 'school_id', 'section_id'],
        // E21-RH.7: + the database-recorded anchors the PHP LMS checks read.
        'subject_offerings' => ['academic_year_id', 'id', 'retention_recorded_at', 'school_id'],
        'teaching_assignments' => ['employee_id', 'ends_on', 'retention_recorded_at', 'school_id', 'section_id', 'subject_offering_id'],
    ];

    /**
     * E21-RH.4: the standalone legacy functions now executable only by the
     * retention identity. Each refuses any other session user
     * (`retention_assert_retention_identity()`) and, destructively, any
     * active platform hold -- and, School-scoped, its School's hold
     * (`retention_assert_not_held()`).
     */
    public const STANDALONE_RETENTION_FUNCTIONS = [
        // School-scoped
        'retention_expire_school_audit_events', 'retention_expire_membership_role_assignments',
        'retention_expire_teaching_assignments', 'retention_expire_school_elevations',
        'retention_expire_communication_delivery_policy_decisions', 'retention_expire_api_client_credentials',
        // TCH-E (ADR 0063 section 45): created with the RH.4 prologue already in place.
        'retention_expire_elective_teaching_assignments',
        // ORG.9D-3 (ADR 0076 §75.12): School calendar Events, created in the same shape.
        'retention_expire_communication_events', 'retention_expire_cancelled_communication_events',
        // School-less
        'retention_expire_platform_audit_events', 'retention_expire_released_email_suppressions',
        'retention_expire_group_role_assignments', 'retention_expire_platform_role_assignments',
        'retention_expire_erasure_cases',
    ];

    /**
     * HRX.6 hardening / E21-RH.2: destructive retention functions the
     * runtime role must NOT execute -- the APPROVED set for the dedicated
     * retention identity, which must hold EXECUTE on exactly these and on no
     * other destructive retention function. SECURITY DEFINER, `search_path`
     * pinned, not owned by the runtime role, never PUBLIC; they refuse any
     * other session user and any held School themselves. Later E21-RH slices
     * extend this list (and move entries out of RETENTION_FUNCTIONS).
     */
    public const PRIVILEGED_RETENTION_FUNCTIONS = [
        // One Employee's Leave / Staff Attendance evidence, separation-floored and hold-checked
        'retention_expire_leave_employee_evidence', 'retention_expire_staff_attendance_employee_evidence',
    ];

    /**
     * E21-RH.3: read-only functions the retention identity also executes:
     * the active hold scopes (no history, no attribution) its PHP side reads.
     */
    public const RETENTION_READ_FUNCTIONS = [
        'retention_hold_active_scopes',
        // E21-RH.6: the lock-only row locker and the read-only dependency probe (identity-checked, never mutate)
        'retention_lock_rows', 'retention_first_reference',
    ];

    /** E21-RH.3: the hold writers -- owner (operator maintenance) only, never PUBLIC, runtime or retention. */
    public const HOLD_MAINTENANCE_FUNCTIONS = ['retention_hold_place', 'retention_hold_release', 'retention_assert_not_held', 'retention_assert_retention_identity'];

    /**
     * @return list<CheckResult>
     */
    public function verify(): array
    {
        try {
            return $this->checks();
        } catch (Throwable) {
            return [CheckResult::of('database_reachable', false)];
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function checks(): array
    {
        $role = self::RUNTIME_ROLE;
        $results = [];

        $version = (int) DB::selectOne('show server_version_num')->server_version_num;
        $results[] = CheckResult::of('postgres_version_supported', $version >= self::MINIMUM_SERVER_VERSION, (string) intdiv($version, 10000));

        $results[] = CheckResult::of('runtime_connection_uses_runtime_role', DB::selectOne('select current_user as u')->u === $role);

        // ADR 0050 section 7: TLS on the connection. Production refuses to
        // boot without sslmode >= require; this proves the server agreed.
        // PPR.1 (ADR 0074 section 26): a FAIL in every hardened environment.
        $ssl = (bool) (DB::selectOne('select ssl from pg_stat_ssl where pid = pg_backend_pid()')->ssl ?? false);
        $results[] = EnvironmentPolicy::isHardened() || $ssl
            ? CheckResult::of('runtime_connection_encrypted', $ssl)
            : new CheckResult('runtime_connection_encrypted', CheckResult::EVIDENCE, 'not encrypted outside a hardened environment');

        $attrs = DB::selectOne('select rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [$role]);
        $results[] = CheckResult::of('runtime_role_exists', $attrs !== null);
        $results[] = CheckResult::of('runtime_role_attributes', $attrs !== null
            && $attrs->rolcanlogin && ! $attrs->rolsuper && ! $attrs->rolbypassrls && ! $attrs->rolcreatedb
            && ! $attrs->rolcreaterole && ! $attrs->rolinherit && ! $attrs->rolreplication);

        $owner = DB::selectOne("select relowner::regrole::text as owner from pg_class where oid = 'platform_role_assignments'::regclass")->owner;
        $member = DB::selectOne('select pg_has_role(?, ?, ?) as m', [$role, $owner, 'MEMBER'])->m;
        // HR.R1-C5 (PAY-15, ADR 0021): the role that owns the schema must see every row, or a migration or rollback guard is
        // reading a filtered table; and rollback must run through the fail-closed migrator.
        $schemaOwner = DB::selectOne("select r.rolsuper or r.rolbypassrls as sees_all from pg_class c join pg_roles r on r.oid = c.relowner where c.oid = to_regclass('public.migrations')");
        $results[] = CheckResult::of('migration_owner_sees_every_row', $schemaOwner !== null && (bool) $schemaOwner->sees_all);
        $results[] = CheckResult::of('rollback_guards_fail_closed', app('migrator')::class === RollbackSafeMigrator::class);
        $results[] = CheckResult::of('runtime_role_not_owner_member', ! $member && $owner !== $role);

        $schema = DB::selectOne("select has_database_privilege(?, current_database(), 'CONNECT') as connect, has_schema_privilege(?, 'public', 'USAGE') as usage, has_schema_privilege(?, 'public', 'CREATE') as create_", [$role, $role, $role]);
        $results[] = CheckResult::of('runtime_schema_access', $schema->connect && $schema->usage && ! $schema->create_);

        $tables = DB::selectOne("select has_table_privilege(?, 'schools', 'SELECT') and has_table_privilege(?, 'campuses', 'SELECT,INSERT,UPDATE') and has_table_privilege(?, 'domain_event_outbox', 'SELECT,INSERT,UPDATE') as ok", [$role, $role, $role]);
        $results[] = CheckResult::of('runtime_table_privileges', (bool) $tables->ok);

        $granted = [];
        foreach (['DELETE' => self::NO_RUNTIME_DELETE, 'UPDATE' => self::NO_RUNTIME_UPDATE] as $privilege => $tables) {
            foreach ($tables as $table) {
                if (DB::selectOne('select has_table_privilege(?, ?, ?) as d', [$role, $table, $privilege])->d) {
                    $granted[] = "{$table}:{$privilege}";
                }
            }
        }
        $results[] = CheckResult::of('runtime_destructive_privileges_restricted', $granted === [], $granted === [] ? '' : count($granted).' grant(s)');

        // HR.1A: the workforce backstops are present and enabled (a disabled
        // trigger would let the runtime role write overlapping or rewritten history).
        $missingHr = array_filter(self::HR_WORKFORCE_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH);
        $results[] = CheckResult::of('hr_workforce_invariants', $missingHr === [], $missingHr === [] ? '' : count($missingHr).' missing or disabled');

        // HR.3A: the reporting-line backstops, the table's forced RLS and its indexes.
        $missingReporting = array_keys(array_filter(self::HR_REPORTING_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        $rls = DB::selectOne("select relrowsecurity and relforcerowsecurity as x from pg_class where oid = 'public.reporting_relationships'::regclass");
        if ($rls === null || ! $rls->x) {
            $missingReporting[] = 'rls';
        }
        foreach (self::HR_REPORTING_INDEXES as $index) {
            if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and tablename = 'reporting_relationships' and indexname = ?) as x", [$index])->x) {
                $missingReporting[] = $index;
            }
        }
        // HR.2A: the staff-profile backstops, forced RLS on reference codes and the closed checks.
        $missingProfile = array_keys(array_filter(self::HR_PROFILE_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        $codesRls = DB::selectOne("select relrowsecurity and relforcerowsecurity as x from pg_class where oid = 'public.employee_reference_codes'::regclass");
        if ($codesRls === null || ! $codesRls->x) {
            $missingProfile[] = 'rls';
        }
        foreach (['employee_documents_category_check', 'employee_documents_storage_shape_check', 'employee_reference_codes_kind_check', 'employee_reference_codes_value_unique'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingProfile[] = $constraint;
            }
        }
        $results[] = CheckResult::of('hr_profile_invariants', $missingProfile === [], $missingProfile === [] ? '' : count($missingProfile).' missing or disabled');

        $results[] = CheckResult::of('hr_reporting_invariants', $missingReporting === [], $missingReporting === [] ? '' : count($missingReporting).' missing or disabled');

        // HR.12: the separation backstops, maker/checker, one live case, forced RLS.
        $missingSeparation = array_keys(array_filter(self::HR_SEPARATION_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        foreach (self::HR_SEPARATION_TABLES as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missingSeparation[] = "{$table}:rls";
            }
        }
        foreach (['employment_separation_cases_sod_check', 'employment_separation_cases_dates_check', 'separation_clearance_items_status_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingSeparation[] = $constraint;
            }
        }
        if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and indexname = 'employment_separation_cases_one_live') as x")->x) {
            $missingSeparation[] = 'employment_separation_cases_one_live';
        }
        // HR.13: the staff task backstops, idempotent occurrences, forced RLS.
        $missingTasks = [];
        if (! DB::selectOne("select exists (select 1 from pg_trigger where tgrelid = 'public.workforce_tasks'::regclass and tgname = 'trg_workforce_tasks_guard' and tgenabled = 'O') as x")->x) {
            $missingTasks[] = 'trg_workforce_tasks_guard';
        }
        if (! DB::selectOne("select exists (select 1 from pg_trigger where tgrelid = 'public.workforce_task_events'::regclass and tgname = 'trg_workforce_task_events_guard' and tgenabled = 'O') as x")->x) {
            $missingTasks[] = 'trg_workforce_task_events_guard';
        }
        foreach (['workforce_tasks_one_occurrence'] as $index) {
            if (! DB::selectOne("select exists (select 1 from pg_indexes where schemaname = 'public' and indexname = ?) as x", [$index])->x) {
                $missingTasks[] = $index;
            }
        }
        foreach (['workforce_tasks_path_check', 'workforce_tasks_outcome_check', 'workforce_task_template_versions_schedule_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingTasks[] = $constraint;
            }
        }
        foreach (['workforce_task_categories', 'workforce_task_templates', 'workforce_task_template_versions', 'workforce_tasks', 'workforce_task_events', 'workforce_task_generation_runs'] as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missingTasks[] = "{$table}:rls";
            }
        }
        $results[] = CheckResult::of('hr_task_invariants', $missingTasks === [], $missingTasks === [] ? '' : count($missingTasks).' missing or disabled');

        $results[] = CheckResult::of('hr_separation_invariants', $missingSeparation === [], $missingSeparation === [] ? '' : count($missingSeparation).' missing or disabled');

        // HR.9: the leave policy backstops, forced RLS on the two new tables and their closed checks.
        $missingLeavePolicy = array_keys(array_filter(self::LEAVE_POLICY_GUARDS, fn (string $table, string $trigger) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH));
        // HR.R1-C11 (C10-N1): a request puts a leave type in use, so its paid, balance and half-day rules freeze.
        $freeze = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'leave_types_freeze' and p.pronamespace = 'public'::regnamespace");
        if ($freeze === null || ! str_contains($freeze->src, 'leave_requests')) {
            $missingLeavePolicy[] = 'leave_types_freeze:requests';
        }
        foreach (['leave_eligibility_rules', 'leave_eligibility_runs', 'leave_accrual_runs', 'leave_accrual_run_items'] as $table) {
            $rls = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (\'public.\'||?)::regclass', [$table]);
            if ($rls === null || ! $rls->x) {
                $missingLeavePolicy[] = "{$table}:rls";
            }
        }
        foreach (['leave_policies_rules_check', 'leave_eligibility_rules_staff_type_check', 'leave_policy_assignments_provenance_check', 'leave_eligibility_runs_count_check',
            'leave_policies_entitlement_check', 'leave_accrual_runs_shape_check', 'leave_accrual_run_items_shape_check', 'leave_ledger_entries_shape_check'] as $constraint) {
            if (! DB::selectOne('select exists (select 1 from pg_constraint where conname = ?) as x', [$constraint])->x) {
                $missingLeavePolicy[] = $constraint;
            }
        }
        $results[] = CheckResult::of('leave_policy_invariants', $missingLeavePolicy === [], $missingLeavePolicy === [] ? '' : count($missingLeavePolicy).' missing or disabled');

        $defaults = DB::selectOne(
            "select bool_or(defaclobjtype = 'r' and defaclacl::text like '%' || ? || '=arwd/%') as tables,
                    bool_or(defaclobjtype = 'S' and defaclacl::text like '%' || ? || '=rU/%') as sequences
             from pg_default_acl where defaclrole = ?::regrole and (defaclnamespace = 'public'::regnamespace or defaclnamespace = 0)",
            [$role, $role, $owner],
        );
        $results[] = CheckResult::of('default_privileges_for_migration_role', (bool) $defaults->tables && (bool) $defaults->sequences);

        $unprotected = DB::select(
            "select c.relname from information_schema.columns col
             join pg_class c on c.relname = col.table_name and c.relnamespace = 'public'::regnamespace and c.relkind = 'r'
             where col.table_schema = 'public' and col.column_name = 'school_id' and not (c.relrowsecurity and c.relforcerowsecurity)",
        );
        $unexpected = array_diff(array_map(fn ($r) => $r->relname, $unprotected), self::NON_RLS_SCHOOL_TABLES);
        $results[] = CheckResult::of('tenant_tables_force_rls', $unexpected === [], $unexpected === [] ? '' : count($unexpected).' table(s)');

        $boundary = DB::selectOne(
            "select t.tgenabled = 'O' as enabled, position('pg_has_role' in p.prosrc) > 0 as owner_check
             from pg_trigger t join pg_proc p on p.oid = t.tgfoid where t.tgname = 'trg_platform_role_assignments_governance'",
        );
        $results[] = CheckResult::of('platform_root_boundary', $boundary !== null && $boundary->enabled && $boundary->owner_check);

        $results = [...$results, ...$this->roleCatalogueChecks($role)];

        $functions = DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, p.prosrc as src,
                    pg_get_userbyid(p.proowner) as owner, has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                    (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_%'",
            [$role],
        );
        // E21-RH.6 (H1 closed): the runtime role executes NO retention_* function -- every destructive one
        // belongs to the retention identity, the helpers to their owner or the retention identity.
        $unexpected = array_filter($functions, fn ($f) => $f->runtime_exec);
        $results[] = CheckResult::of('retention_functions_narrow', $unexpected === [], $unexpected === [] ? '' : count($unexpected).' runtime-executable');
        $privileged = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS];
        $prologued = [...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS];
        $closed = array_filter($functions, fn ($f) => in_array($f->proname, $privileged, true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && $f->owner !== $role && ! $f->runtime_exec && ! $f->public_exec
            && (! in_array($f->proname, $prologued, true) || (str_contains($f->src, 'retention_assert_retention_identity()') && str_contains($f->src, 'retention_assert_not_held('))));
        $holds = DB::selectOne("select has_table_privilege(?, 'retention_holds', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE') as any", [$role]);
        $results[] = CheckResult::of('privileged_retention_functions_closed', count($closed) === count($privileged) && ! $holds->any);
        // E21-RH.6: the retention identity's read helpers are definers, pinned, identity-checked, never runtime/PUBLIC.
        $helpers = array_filter($functions, fn ($f) => in_array($f->proname, ['retention_lock_rows', 'retention_first_reference'], true)
            && $f->prosecdef && str_contains($f->config, 'search_path=') && ! $f->runtime_exec && ! $f->public_exec
            && str_contains($f->src, 'retention_assert_retention_identity()'));
        $results[] = CheckResult::of('retention_read_helpers_closed', count($helpers) === 2);
        $results = [...$results, ...$this->retentionBoundaryChecks($role)];
        $results = [...$results, ...$this->retentionIdentityChecks()];

        $finance = DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                    pg_get_userbyid(p.proowner) as owner, has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                    (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.prosecdef and p.proname like 'finance\\_%'",
            [$role],
        );
        $narrowFinance = array_filter($finance, fn ($f) => in_array($f->proname, self::FINANCE_FUNCTIONS, true)
            && str_contains($f->config, 'search_path=') && $f->owner !== $role && $f->runtime_exec && ! $f->public_exec);
        $results[] = CheckResult::of('finance_period_functions_narrow', count($narrowFinance) === count(self::FINANCE_FUNCTIONS) && count($finance) === count(self::FINANCE_FUNCTIONS));
        $results[] = $this->payrollApprovalProvenanceCheck();
        $results[] = $this->payrollReconciliationCheck();
        $results[] = $this->hrFamilyRuntimePrivilegesCheck();
        $results[] = $this->hrFamilyTriggerFunctionsCheck();
        $results[] = $this->runtimeRoleMembershipCheck();
        $results[] = $this->employeeAccountLinkCheck();
        $results[] = $this->employmentLifecycleAdmissionCheck();
        $results[] = $this->staffAttendanceMonthLockCheck();
        $results[] = $this->staffCalendarHistoryCheck();
        $results[] = $this->credentialVerificationCheck();
        $results[] = $this->knownSamePersonGuardsCheck();
        $results[] = $this->separationRetainedAccessCheck();
        $results[] = $this->separationClearanceBasisCheck();

        return $results;
    }

    /**
     * HR.R1-C29 (C14-N5): md5 of each credential-verification function's body, comments stripped and whitespace collapsed.
     * Each pair is [function, hash], the hash on its own line: gitleaks' generic-api-key rule reads "credential" in a
     * name as a key word, and would otherwise flag the hash beside it.
     */
    private const CREDENTIAL_VERIFICATION_BODIES = [
        [
            'hr_credential_revision_guard',
            '6553c41f80a26f6140c7495c9aa55a78',
        ],
        [
            'employee_credential_verifications_guard',
            '049dd4561192f2b2d15c400f8d381cf6',
        ],
        [
            'employee_credential_verifications_apply',
            '21ecb390914a6a297aa9decfe80c7ce4',
        ],
    ];

    /** HR.R1-C29: md5 of each credential-verification CHECK's `pg_get_constraintdef`. */
    private const CREDENTIAL_VERIFICATION_CHECKS = [
        'employee_qualifications_revision_check' => 'f08b1255b0651119b0dd2c4d8a18bf83',
        'employee_qualifications_verification_revision_check' => 'c2fa0981d64133e8a0d31f164827ffdb',
        'employee_qualifications_verification_status_check' => '8ae736350b03d2539d4be66c1a33c5b2',
        'employee_certifications_revision_check' => 'f08b1255b0651119b0dd2c4d8a18bf83',
        'employee_certifications_verification_revision_check' => 'c2fa0981d64133e8a0d31f164827ffdb',
        'employee_certifications_verification_status_check' => '8ae736350b03d2539d4be66c1a33c5b2',
        'employee_credential_verifications_decision_check' => '66b76431a2d39dd298028fd22db82369',
        'employee_credential_verifications_kind_check' => 'ac590783c51a57bbacdfc55cc04538c0',
        'employee_credential_verifications_revision_check' => 'f08b1255b0651119b0dd2c4d8a18bf83',
    ];

    /**
     * HR.R1-C29 (C14-N5, owner decisions C29-A/B, rule 132): a credential's verification names a revision only the
     * database moves, and only a recorded decision on that revision sets it. The trigger bindings, modes, owners
     * and `search_path` are `hr_family_trigger_functions`; this pins what those functions do (whole bodies, as
     * C28-N3 asks of new guards), the CHECKs, the two same-Employee, same-School keys that cascade with their
     * credential, the decision order (an identity column whose value the guard assigns after the credential's row lock,
     * discarding any a writer supplied, review MEDIUM-1) and forced RLS.
     */
    private function credentialVerificationCheck(): CheckResult
    {
        $strip = fn (string $src): string => (string) preg_replace(['/--[^\n]*/', '#/\*.*?\*/#s'], '', $src);
        $missing = [];
        foreach (self::CREDENTIAL_VERIFICATION_BODIES as [$function, $hash]) {
            $src = (string) (DB::selectOne("select prosrc as src from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$function])->src ?? '');
            if (md5(trim((string) preg_replace('/\s+/', ' ', $strip($src)))) !== $hash) {
                $missing[] = "{$function}:shipped";
            }
        }
        foreach (self::CREDENTIAL_VERIFICATION_CHECKS as $constraint => $hash) {
            $definition = DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = ? and contype = 'c' and convalidated", [$constraint])->d ?? null;
            if ($definition === null || md5((string) $definition) !== $hash) {
                $missing[] = "{$constraint}:shipped";
            }
        }
        foreach (['qualification' => 'employee_qualifications', 'certification' => 'employee_certifications'] as $kind => $table) {
            $key = DB::selectOne(
                "select pg_get_constraintdef(oid) as d, confdeltype as del from pg_constraint where conname = ? and contype = 'f' and convalidated
                 and conrelid = 'public.employee_credential_verifications'::regclass",
                ["employee_credential_verifications_{$kind}_fk"],
            );
            if ($key === null || $key->del !== 'c' || $key->d !== "FOREIGN KEY ({$kind}_id, employee_id, school_id) REFERENCES {$table}(id, employee_id, school_id) ON DELETE CASCADE") {
                $missing[] = "employee_credential_verifications_{$kind}_fk";
            }
        }
        $sequence = DB::selectOne("select attidentity as i from pg_attribute where attrelid = 'public.employee_credential_verifications'::regclass and attname = 'sequence' and not attisdropped");
        if ($sequence === null || $sequence->i !== 'a') {
            $missing[] = 'employee_credential_verifications:sequence';
        }
        // Delta review INFO-B: the guard takes the order with nextval (USAGE), and nobody at runtime may rewind it (setval).
        $sequencePrivileges = DB::selectOne(
            "select has_sequence_privilege(?, pg_get_serial_sequence('public.employee_credential_verifications', 'sequence'), 'USAGE') as usage,
                    has_sequence_privilege(?, pg_get_serial_sequence('public.employee_credential_verifications', 'sequence'), 'UPDATE') as rewind",
            [self::RUNTIME_ROLE, self::RUNTIME_ROLE],
        );
        if ($sequencePrivileges === null || ! $sequencePrivileges->usage || $sequencePrivileges->rewind) {
            $missing[] = 'employee_credential_verifications:sequence_privileges';
        }
        $forced = DB::selectOne("select relrowsecurity and relforcerowsecurity as x from pg_class where oid = 'public.employee_credential_verifications'::regclass");
        if ($forced === null || ! $forced->x) {
            $missing[] = 'employee_credential_verifications:rls';
        }

        return CheckResult::of('hr_credential_verification_invariants', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    /**
     * HR.R1-C22 (WO-5, D-6, S-16): the staff calendar's history backstops. Each trigger is defined exactly as shipped; the
     * guards (comments stripped) check isolation, the history start, the reason for a past date, the migration-only
     * cutover and replacement-only supersession; Staff Attendance's guard takes each reached month's key shared and
     * refuses a locked one; the helpers do what the guards rely on; forced RLS; one active version per date and one
     * active holiday per date; the runtime role cannot insert into the retired week (DELETE and UPDATE are in the
     * destructive-privileges check).
     */
    /** HR.R1-C22 (C22 review L-3): md5 of each calendar guard's body, comments stripped and whitespace collapsed. */
    private const STAFF_CALENDAR_GUARD_BODIES = [
        'staff_working_week_versions_guard' => '49e359b6a086d08ccc6e5b27a4a6f9ee',
        'staff_holidays_history_guard' => 'b262ecf991c69295e7dd090b05f1aba1',
        'staff_attendance_calendar_month_guard' => '8e42646f24a593e676a821a4e120abf4',
    ];

    /** HR.R1-C22: md5 of each calendar CHECK constraint's `pg_get_constraintdef`. */
    private const STAFF_CALENDAR_CHECKS = [
        'staff_holidays_check' => '44c2a689f8e88c4e1c36695e5e0e6cb5',
        'staff_holidays_reason_check' => '6cde663cbcbb8ddd8bdddf4ef3284a5f',
        'staff_holidays_status_check' => '6d4e1dd9fe92120e7d778bebe4e9e9da',
        'staff_working_week_versions_portions_check' => '1d4299fac7ae505ab6ea6216b3c6163e',
        'staff_working_week_versions_reason_check' => '638c03ae7a0dd3faca9a9c8ed65d23af',
        'staff_working_week_versions_recorder_check' => '142a4d316b683354aedbaa273ab12f45',
        'staff_working_week_versions_status_change_check' => '75ac8f0200a6175143c44391fe4df128',
        'staff_working_week_versions_status_check' => 'e38c4437f380700986e8e64f15336d76',
    ];

    private function staffCalendarHistoryCheck(): CheckResult
    {
        $strip = fn (string $src): string => (string) preg_replace(['/--[^\n]*/', '#/\*.*?\*/#s'], '', $src);
        $triggers = [
            'trg_staff_working_week_versions_guard' => ['staff_working_week_versions', 'BEFORE INSERT OR UPDATE ON public.staff_working_week_versions FOR EACH ROW EXECUTE FUNCTION staff_working_week_versions_guard()',
                ['PERFORM public.staff_calendar_require_read_committed();', 'public.staff_calendar_history_start(NEW.school_id)', 'staff_calendar_reason_required', 'staff_calendar_cutover', 'pg_catalog.pg_trigger_depth()', 'staff_calendar_immutable']],
            'trg_staff_holidays_history_guard' => ['staff_holidays', 'BEFORE INSERT OR UPDATE ON public.staff_holidays FOR EACH ROW EXECUTE FUNCTION staff_holidays_history_guard()',
                ['PERFORM public.staff_calendar_require_read_committed();', 'public.staff_calendar_history_start(NEW.school_id)', 'staff_calendar_reason_required', 'staff_calendar_immutable']],
            'trg_staff_attendance_calendar_month_guard' => ['staff_working_week_versions', 'BEFORE INSERT OR UPDATE ON public.staff_working_week_versions FOR EACH ROW EXECUTE FUNCTION staff_attendance_calendar_month_guard()',
                ['pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, v_month))', 'public.staff_attendance_month_is_locked(NEW.school_id, v_month)']],
            'trg_staff_attendance_holiday_month_guard' => ['staff_holidays', 'BEFORE INSERT OR UPDATE ON public.staff_holidays FOR EACH ROW EXECUTE FUNCTION staff_attendance_calendar_month_guard()',
                ['pg_catalog.pg_advisory_xact_lock_shared(public.staff_attendance_month_key(NEW.school_id, v_month))', 'public.staff_attendance_month_is_locked(NEW.school_id, v_month)']],
        ];
        $missing = [];
        foreach ($triggers as $trigger => [$table, $definition, $needles]) {
            $row = DB::selectOne(
                "select p.prosrc as src, pg_get_triggerdef(t.oid) as def from pg_trigger t join pg_proc p on p.oid = t.tgfoid
                 where t.tgrelid = ('public.'||?)::regclass and t.tgname = ? and t.tgenabled = 'O' and not t.tgisinternal",
                [$table, $trigger],
            );
            if ($row === null || ! str_ends_with((string) $row->def, ' '.$definition)) {
                $missing[] = $trigger;

                continue;
            }
            $src = $strip((string) $row->src);
            foreach ($needles as $needle) {
                if (! str_contains($src, $needle)) {
                    $missing[] = "{$trigger}:body";

                    break;
                }
            }
        }
        // C22 review L-3: a needle proves a fragment is present, not that the logic around it is intact. Each guard's
        // whole body (comments stripped, whitespace collapsed) and each CHECK is pinned exactly as shipped.
        foreach (self::STAFF_CALENDAR_GUARD_BODIES as $function => $hash) {
            $src = (string) (DB::selectOne("select prosrc as src from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$function])->src ?? '');
            if (md5(trim((string) preg_replace('/\s+/', ' ', $strip($src)))) !== $hash) {
                $missing[] = "{$function}:shipped";
            }
        }
        foreach (self::STAFF_CALENDAR_CHECKS as $constraint => $hash) {
            $definition = DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = ? and contype = 'c' and convalidated", [$constraint])->d ?? null;
            if ($definition === null || md5((string) $definition) !== $hash) {
                $missing[] = "{$constraint}:shipped";
            }
        }
        $helpers = [
            'staff_calendar_require_read_committed' => "IF pg_catalog.current_setting('transaction_isolation') <> 'read committed' THEN",
            'staff_calendar_local_today' => 'SELECT (pg_catalog.now() AT TIME ZONE s.timezone)::date FROM public.schools s WHERE s.id = p_school',
            'staff_calendar_history_start' => "SELECT min(effective_from) FROM public.staff_working_week_versions WHERE school_id = p_school AND status IN ('active', 'superseded')",
        ];
        foreach ($helpers as $function => $needle) {
            if (! str_contains($strip((string) (DB::selectOne("select prosrc as src from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$function])->src ?? '')), $needle)) {
                $missing[] = "{$function}:body";
            }
        }
        foreach (['staff_working_week_versions', 'staff_holidays'] as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missing[] = "{$table}:rls";
            }
        }
        $active = "((status)::text = 'active'::text)";
        foreach ([['staff_working_week_versions', 'school_id,effective_from'], ['staff_holidays', 'school_id,holiday_on']] as [$table, $columns]) {
            $unique = (bool) DB::selectOne(
                "select exists (select 1 from pg_index i where i.indrelid = (?)::regclass and i.indisunique and i.indisvalid
                    and (select string_agg(a.attname, ',' order by k.ord) from unnest(i.indkey) with ordinality k(attnum, ord)
                         join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum) = ?
                    and pg_get_expr(i.indpred, i.indrelid) is not distinct from ?) as x",
                ["public.{$table}", $columns, $active],
            )->x;
            if (! $unique) {
                $missing[] = "{$table}:one_active";
            }
        }
        if (DB::selectOne("select has_table_privilege(?, 'staff_working_weekdays', 'INSERT') as x", [self::RUNTIME_ROLE])->x) {
            $missing[] = 'staff_working_weekdays:insert';
        }

        return CheckResult::of('staff_calendar_history_invariants', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    /**
     * HR.R1-C20 (WO-6, C18-A7): each month-lock trigger is present, enabled and defined exactly as
     * shipped (timing, events, table, function, and the apply trigger's WHEN); each guard, with its
     * comments stripped, refuses any isolation but READ COMMITTED and takes the School-month key in its
     * own mode (exclusive to lock, shared to write); the three helpers still do what the guards rely on;
     * the apply function is a pinned definer the runtime role cannot call; both tables force RLS; the
     * one-lock-per-month and apply-once unique indexes keep their predicates. Privileges are in the
     * destructive-privileges check.
     */
    private function staffAttendanceMonthLockCheck(): CheckResult
    {
        $strip = fn (string $src): string => (string) preg_replace(['/--[^\n]*/', '#/\*.*?\*/#s'], '', $src);
        $timing = [
            'trg_staff_attendance_month_locks_guard' => 'BEFORE INSERT ON public.staff_attendance_month_locks FOR EACH ROW EXECUTE FUNCTION staff_attendance_month_locks_guard()',
            'trg_staff_attendance_records_month_guard' => 'BEFORE INSERT OR UPDATE ON public.staff_attendance_records FOR EACH ROW EXECUTE FUNCTION staff_attendance_records_month_guard()',
            'trg_staff_attendance_corrections_month_guard' => 'BEFORE INSERT ON public.staff_attendance_corrections FOR EACH ROW EXECUTE FUNCTION staff_attendance_corrections_month_guard()',
            'trg_staff_attendance_locked_corrections_guard' => 'BEFORE INSERT OR UPDATE ON public.staff_attendance_locked_corrections FOR EACH ROW EXECUTE FUNCTION staff_attendance_locked_corrections_guard()',
            'trg_staff_attendance_locked_corrections_apply' => "AFTER UPDATE ON public.staff_attendance_locked_corrections FOR EACH ROW WHEN ((((new.status)::text = 'approved'::text) AND ((old.status)::text = 'requested'::text))) EXECUTE FUNCTION staff_attendance_locked_corrections_apply()",
        ];
        $missing = [];
        foreach (self::STAFF_ATTENDANCE_MONTH_LOCK_GUARDS as $trigger => [$table, $function]) {
            $row = DB::selectOne(
                "select p.proname, p.prosrc as src, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                        has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec, pg_get_triggerdef(t.oid) as def
                 from pg_trigger t join pg_proc p on p.oid = t.tgfoid
                 where t.tgrelid = ('public.'||?)::regclass and t.tgname = ? and t.tgenabled = 'O' and not t.tgisinternal",
                [self::RUNTIME_ROLE, $table, $trigger],
            );
            if ($row === null || $row->proname !== $function || ! str_ends_with((string) $row->def, ' '.$timing[$trigger])) {
                $missing[] = $trigger;

                continue;
            }
            $src = $strip((string) $row->src);
            $lock = $trigger === 'trg_staff_attendance_month_locks_guard' ? 'pg_advisory_xact_lock(' : 'pg_advisory_xact_lock_shared(';
            $ok = str_ends_with($trigger, '_apply')
                ? $row->prosecdef && str_contains($row->config, 'search_path=') && ! $row->runtime_exec
                : str_contains($src, 'PERFORM public.staff_attendance_require_read_committed();')
                    && str_contains($src, "PERFORM pg_catalog.{$lock}public.staff_attendance_month_key(NEW.school_id, ");
            if (! $ok) {
                $missing[] = "{$trigger}:body";
            }
        }
        $helpers = [
            'staff_attendance_require_read_committed' => ["IF pg_catalog.current_setting('transaction_isolation') <> 'read committed' THEN", 'RAISE EXCEPTION'],
            'staff_attendance_month_key' => ["pg_catalog.hashtextextended('hr.staff_month:' || p_school::text || ':' || pg_catalog.to_char(p_month, 'YYYY-MM'), 0)"],
            'staff_attendance_month_is_locked' => ['FROM public.staff_attendance_month_locks WHERE school_id = p_school AND month = p_month'],
        ];
        foreach ($helpers as $function => $needles) {
            $src = $strip((string) (DB::selectOne("select prosrc as src from pg_proc where proname = ? and pronamespace = 'public'::regnamespace", [$function])->src ?? ''));
            foreach ($needles as $needle) {
                if (! str_contains($src, $needle)) {
                    $missing[] = "{$function}:body";

                    break;
                }
            }
        }
        foreach (['staff_attendance_month_locks', 'staff_attendance_locked_corrections'] as $table) {
            $forced = DB::selectOne('select relrowsecurity and relforcerowsecurity as x from pg_class where oid = (?)::regclass', ["public.{$table}"]);
            if ($forced === null || ! $forced->x) {
                $missing[] = "{$table}:rls";
            }
        }
        $unique = fn (string $table, string $columns, ?string $predicate) => (bool) DB::selectOne(
            "select exists (select 1 from pg_index i where i.indrelid = (?)::regclass and i.indisunique and i.indisvalid
                and (select string_agg(a.attname, ',' order by k.ord) from unnest(i.indkey) with ordinality k(attnum, ord)
                     join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum) = ?
                and pg_get_expr(i.indpred, i.indrelid) is not distinct from ?) as x",
            ["public.{$table}", $columns, $predicate],
        )->x;
        if (! $unique('staff_attendance_month_locks', 'school_id,month', null)) {
            $missing[] = 'staff_attendance_month_locks:unique';
        }
        if (! $unique('staff_attendance_corrections', 'locked_correction_id', '(locked_correction_id IS NOT NULL)')) {
            $missing[] = 'staff_attendance_corrections:apply_once';
        }

        return CheckResult::of('staff_attendance_month_lock_invariants', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    /**
     * HR.R1-C2 (HRC-1, HRC-2): an employment never begins with a planned end
     * (the eligibility guard's INSERT branch), and only an employment end
     * records a placement as never effective (the empty-interval trigger).
     * HR.R1-C9: an archived identity receives no employment (and the guard holds
     * the Employee row, so it serializes with an archive in flight), and a
     * placement stays with its employment (its trigger must watch BOTH columns).
     */
    private function employmentLifecycleAdmissionCheck(): CheckResult
    {
        $guard = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'retention_guard_end_record' and p.pronamespace = 'public'::regnamespace");
        // The placement guard must watch BOTH dates (tgattr holds two columns): the CHECK admits the empty interval however it arises.
        $placement = DB::selectOne("select array_length(string_to_array(t.tgattr::text, ' '), 1) as columns, p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and t.tgenabled = 'O' and t.tgname = 'trg_employee_assignments_never_effective'");
        $afterDeath = (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname = 'trg_employment_records_after_death'")->c;

        $identity = DB::selectOne("select array_length(string_to_array(t.tgattr::text, ' '), 1) as columns, p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and t.tgenabled = 'O' and t.tgname = 'trg_employee_assignments_placement_identity' and t.tgrelid = 'public.employee_assignments'::regclass and pg_get_triggerdef(t.oid) like '%BEFORE UPDATE OF employment_record_id, is_primary%'");
        $archived = DB::selectOne("select p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid where not t.tgisinternal and t.tgenabled = 'O' and t.tgname = 'trg_employment_records_archived_identity' and t.tgrelid = 'public.employment_records'::regclass and pg_get_triggerdef(t.oid) like '%BEFORE INSERT%'");

        return CheckResult::of('employment_lifecycle_admission_guarded', $identity !== null && (int) $identity->columns === 2
            && str_contains($identity->src, 'belongs to its employment') && str_contains($identity->src, 'never took effect is final') && str_contains($identity->src, 'stays primary history') && str_contains($identity->src, 'keeps its placement history') && str_contains($identity->src, 'FOR KEY SHARE')
            && $archived !== null && str_contains($archived->src, 'FOR SHARE')
            && $guard !== null && str_contains($guard->src, 'never begins with a planned end')
            && $placement !== null && (int) $placement->columns === 2 && str_contains($placement->src, 'never took effect is final') && str_contains($placement->src, 'past its end')
            && $afterDeath === 1);
    }

    /**
     * HR.R1-C26 (R1-01 point 4): the runtime role's effective privileges on every HR-family table equal the
     * allowlist exactly. The effective privilege is read, so a grant to PUBLIC counts too; SELECT, INSERT, UPDATE
     * and (since HR.R1-C28, C27-N1) REFERENCES also count when granted on a single column
     * (`has_any_column_privilege`), and REFERENCES or TRIGGER fail. A missing table fails rather than passing
     * unseen. A privilege reachable only through another role is `runtimeRoleMembershipCheck()`'s concern (C26-N1).
     */
    private function hrFamilyRuntimePrivilegesCheck(): CheckResult
    {
        $differ = [];
        foreach (self::HR_FAMILY_RUNTIME_PRIVILEGES as $table => $expected) {
            if (DB::selectOne('select to_regclass(?) as t', ["public.{$table}"])->t === null) {
                $differ[] = "{$table}:missing";

                continue;
            }
            $held = '';
            foreach (['S' => 'SELECT', 'I' => 'INSERT', 'U' => 'UPDATE'] as $letter => $privilege) {
                $held .= DB::selectOne('select has_any_column_privilege(?, ?, ?) as p', [self::RUNTIME_ROLE, "public.{$table}", $privilege])->p ? $letter : '';
            }
            foreach (['D' => 'DELETE', 'T' => 'TRUNCATE'] as $letter => $privilege) {
                $held .= DB::selectOne('select has_table_privilege(?, ?, ?) as p', [self::RUNTIME_ROLE, "public.{$table}", $privilege])->p ? $letter : '';
            }
            // HR.R1-C28 (C27-N1): REFERENCES can be granted on one column, so it is read per column too; TRIGGER is table-level only.
            $held .= DB::selectOne('select has_any_column_privilege(?, ?, ?) as p', [self::RUNTIME_ROLE, "public.{$table}", 'REFERENCES'])->p ? 'R' : '';
            foreach (['X' => 'TRIGGER'] as $letter => $privilege) {
                $held .= DB::selectOne('select has_table_privilege(?, ?, ?) as p', [self::RUNTIME_ROLE, "public.{$table}", $privilege])->p ? $letter : '';
            }
            if ($held !== $expected) {
                $differ[] = "{$table}:{$held}";
            }
        }

        return CheckResult::of('hr_family_runtime_privileges', $differ === [], $differ === [] ? '' : implode(', ', $differ));
    }

    /**
     * HR.R1-C28 (WO-13, C22-N1, C28-N2): each HR-family trigger is enabled and bound, through `tgfoid`, to its pinned
     * function with its pinned definition, and no other trigger sits on a family table. Each bound function is in
     * `public`, takes no arguments and is the only function of its name (so a body hash by name is a hash of the
     * bound function), is owned by the schema owner, and is SECURITY DEFINER exactly when listed in
     * `HR_FAMILY_DEFINER_FUNCTIONS`, with that pinned path and no EXECUTE for the runtime role, the retention
     * identity or PUBLIC. Every other one is SECURITY INVOKER with a pinned path that puts `pg_temp` last.
     */
    private function hrFamilyTriggerFunctionsCheck(): CheckResult
    {
        $owner = (string) DB::selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'public.migrations'::regclass")->o;
        $rows = DB::select(
            "select c.relname as tbl, t.tgname, t.tgenabled, p.proname, n.nspname, p.prosecdef,
                    pg_get_function_identity_arguments(p.oid) as args, pg_get_userbyid(p.proowner) as owner, md5(pg_get_triggerdef(t.oid)) as def,
                    coalesce(array_to_string(p.proconfig, '|'), '') as config,
                    (select count(*) from pg_proc q where q.proname = p.proname and q.pronamespace = 'public'::regnamespace) as same_name,
                    has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                    has_function_privilege(?, p.oid, 'EXECUTE') as retention_exec,
                    exists (select 1 from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a where a.grantee = 0 and a.privilege_type = 'EXECUTE') as public_exec
             from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid join pg_namespace n on n.oid = p.pronamespace
             where not t.tgisinternal and c.relnamespace = 'public'::regnamespace and c.relname = any (?::text[])",
            [self::RUNTIME_ROLE, self::RETENTION_ROLE, '{'.implode(',', array_keys(self::HR_FAMILY_RUNTIME_PRIVILEGES)).'}'],
        );

        $differ = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = "{$row->tbl}.{$row->tgname}";
            $seen[$key] = true;
            $expected = self::HR_FAMILY_TRIGGERS[$row->tbl][$row->tgname] ?? null;
            if ($expected === null) {
                $differ[] = "{$key}:unexpected";

                continue;
            }
            [$function, $definition] = $expected;
            $definer = self::HR_FAMILY_DEFINER_FUNCTIONS[$function] ?? null;
            $path = str_starts_with((string) $row->config, 'search_path=') && ! str_contains((string) $row->config, '|')
                ? substr((string) $row->config, strlen('search_path='))
                : null;
            $problems = array_keys(array_filter([
                'disabled' => $row->tgenabled !== 'O',
                'binding' => $row->proname !== $function || $row->nspname !== 'public' || $row->args !== '' || (int) $row->same_name !== 1,
                'definition' => $row->def !== $definition,
                'owner' => $row->owner !== $owner,
                'mode' => (bool) $row->prosecdef !== ($definer !== null),
                'search_path' => $definer !== null ? $path !== $definer : ! in_array($path, self::HR_FAMILY_INVOKER_SEARCH_PATHS, true),
                'execute' => $definer !== null && ($row->runtime_exec || $row->retention_exec || $row->public_exec),
            ]));
            if ($problems !== []) {
                $differ[] = "{$key}:".implode('+', $problems);
            }
        }
        foreach (self::HR_FAMILY_TRIGGERS as $table => $triggers) {
            foreach (array_keys($triggers) as $trigger) {
                if (! isset($seen["{$table}.{$trigger}"])) {
                    $differ[] = "{$table}.{$trigger}:missing";
                }
            }
        }

        return CheckResult::of('hr_family_trigger_functions', $differ === [], $differ === [] ? '' : implode(', ', $differ));
    }

    /**
     * HR.R1-C28 (C26-N1): the runtime role is a member of no role, directly or through another (any grant row counts:
     * ADMIN on another role would let it grant that role to itself), and no role holds it, with one exception: the
     * schema owner may hold it WITH ADMIN only (`set_option` and `inherit_option` false). On PostgreSQL 16 a
     * non-superuser with CREATEROLE that creates the role holds exactly that and cannot revoke it (managed
     * PostgreSQL, `production-bootstrap.sql`). ADMIN confers no privilege directly, but its holder could grant
     * itself SET or INHERIT, which this check then reports; the schema owner already owns every object. A
     * non-empty `createrole_self_grant` would give the creator SET or INHERIT and fail this check, correctly.
     * It is NOINHERIT, so `has_table_privilege` reports only its own grants, but a member may
     * `SET ROLE` to any role it belongs to and act with that role's privileges; with no membership there is none
     * to reach, which is what makes the allowlist above the whole answer. `$connection` lets a test read inside
     * its own uncommitted transaction, because roles are cluster-wide and a probe must never be visible to
     * another database's sessions.
     */
    public function runtimeRoleMembershipCheck(?ConnectionInterface $connection = null): CheckResult
    {
        $db = $connection ?? DB::connection();
        $row = $db->selectOne(
            "select (select count(*) from pg_auth_members m where m.member = r.oid) as member_of,
                    (select count(*) from pg_auth_members m where m.roleid = r.oid
                       and (m.set_option or m.inherit_option or m.member <> (select relowner from pg_class where oid = 'public.migrations'::regclass))) as members,
                    (select count(*) from pg_roles o where o.oid <> r.oid and pg_has_role(r.oid, o.oid, 'MEMBER')) as reachable
             from pg_roles r where r.rolname = ?",
            [self::RUNTIME_ROLE],
        );
        $ok = $row !== null && (int) $row->member_of === 0 && (int) $row->members === 0 && (int) $row->reachable === 0;

        return CheckResult::of('runtime_role_memberships_none', $ok, $ok ? '' : ($row === null ? 'runtime role missing' : "member of {$row->member_of}, members {$row->members}, reachable {$row->reachable}"));
    }

    /**
     * HR.R1-C1 (PAY-2/3/4/5): the payroll approval guard enforces recorded
     * provenance, freshness, first-paid author exclusion and reconciliation;
     * compensation writes take the School's compensation lock; calculated
     * results and lines are never rewritten.
     */
    /**
     * HR.R1-C34 (WO-16, C33): every decision the owner put under the known-same-person rule refuses it in the
     * database: trigger => [table, bound function, the relation its body must call, md5 of the whole
     * `pg_get_triggerdef` (timing, events, `UPDATE OF`, `WHEN`; C34-N4, WO-17)]. Two accounts with
     * uncorrected links to one Employee are one person; the relation is SECURITY INVOKER (RLS applies), STABLE,
     * owned by the schema owner, `pg_temp` last, executable by the runtime role and never by PUBLIC.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const KNOWN_SAME_PERSON_GUARDS = [
        'payroll_runs_participant_approval_guard' => ['payroll_runs', 'payroll_runs_refuse_participant_approval', 'employee_account_link_same_person_accounts', '381845c0503adeb90c1f4776346b6e50'],
        'trg_payroll_posting_attestations_guard' => ['payroll_posting_attestations', 'payroll_posting_attestations_guard', 'employee_account_link_same_person_accounts', '8864479ca38e3c3945b8947c79b0eb35'],
        'trg_payroll_reversal_reconciliation_reviews_guard' => ['payroll_reversal_reconciliation_reviews', 'payroll_reversal_reconciliation_reviews_guard', 'employee_account_link_same_person_accounts', '4e1e2fbd387f097e41785401886c83f5'],
        // WO-17 (C34-N1): the self-beneficiary guards of approval and attestation (the review's is the reviews guard above).
        'trg_payroll_runs_self_beneficiary' => ['payroll_runs', 'payroll_runs_self_beneficiary_guard', 'employee_account_link_same_person_accounts', '910f44909e3d3a8cca882c8061c0fcc3'],
        'trg_payroll_posting_attestations_self_beneficiary' => ['payroll_posting_attestations', 'payroll_posting_attestations_self_beneficiary_guard', 'employee_account_link_same_person_accounts', 'a08d1181b3cbeeedbad1137ad3382976'],
        'trg_leave_decisions_participant_guard' => ['leave_decisions', 'leave_decisions_refuse_submitter', 'employee_account_link_same_person_accounts', 'd9c14d4d59a6aca53ed3ac4dc4d980ce'],
        'trg_employment_separation_cases_guard' => ['employment_separation_cases', 'hr_employment_separation_cases_guard', 'employee_account_link_same_person_accounts', 'ffd7a3dd40a02c10c40427566d1821e8'],
        // WO-18 (C34-N3): whoever clears a clearance item is never the separating person through any known account.
        'trg_separation_clearance_items_guard' => ['separation_clearance_items', 'hr_separation_clearance_items_guard', 'employee_account_link_same_person_accounts', 'cc68ae994237e4ba8bb07a58f2ca9352'],
        'trg_employee_account_link_corrections_guard' => ['employee_account_link_corrections', 'employee_account_link_corrections_guard', 'employee_account_link_correction_same_person', '9b0c5c4796eee271713cef0a69fe67ed'],
        'trg_staff_attendance_locked_corrections_guard' => ['staff_attendance_locked_corrections', 'staff_attendance_locked_corrections_guard', 'employee_account_link_correction_same_person', 'a5286fbc424f1805f5b27e9c775514f5'],
    ];

    /**
     * HR.R1-C34 (C34 review L-1): md5 of each function's whole body (`prosrc`), so a later redefinition that
     * keeps the call but disables it (a comment, a dead branch) fails this check. A deliberate backstop change
     * updates its pin in the same change.
     *
     * @var array<string, string>
     */
    public const KNOWN_SAME_PERSON_BODIES = [
        'employee_account_link_same_person_accounts' => '852951d0ba4932c3f839aea54b1a7ff7',
        'employee_account_link_correction_same_person' => '6af30c7d4b3f86e757f22467159c9647',
        'payroll_runs_refuse_participant_approval' => '3eb3d5d5495a4b21dc21daca99b97b28',
        'payroll_posting_attestations_guard' => '91407209205c35dd6459776260f257e2',
        // WO-17: its subject check also matches an account known to be the same person (was ae7a3cce...).
        'payroll_reversal_reconciliation_reviews_guard' => 'ad3751c777e3b97a875f679d89d871ee',
        'payroll_runs_self_beneficiary_guard' => '082cd4318c5c05b7bbc421d09c3897d6',
        // WO17-N1 Option A (D-WO18-1) and the author side (D-WO17N1-1): after a decision, an approval by an account known
        // to be the Employee, or the change's author, neither discharges it nor keeps the run independent; each asks the
        // relation once per distinct approver (review M-1).
        'payroll_run_first_paid_compensation' => '57d36242a89cb269135aa35c6be67cf9',
        'payroll_run_independence_exception' => '2b7352ba011b11ca26e8698f9604e819',
        // An overloaded name is pinned by its exact signature (WO17-N1 review L-1).
        'payroll_reversal_affected_runs(uuid,uuid,uuid)' => 'a2632c248a365135bce05c875c24b061',
        'payroll_posting_attestations_self_beneficiary_guard' => '00fe114944f09eaeeef69f898c3b7e62',
        // WO-18: administrative withdraw/cancel of one's own leave (was 3823d9e8...).
        'leave_decisions_refuse_submitter' => '3e7a2304d3db03c105f25bb8b29c6008',
        // WO-18: every separation write, person-level (was 0d361816...).
        'hr_employment_separation_cases_guard' => 'f666844c83972d5539fd2c5f37e0301b',
        'hr_separation_clearance_items_guard' => 'ace7fa43cb6eb2ad7238460e0ced5a04',
        'employee_account_link_corrections_guard' => 'db128a19cb8591f854679f691a507234',
        'staff_attendance_locked_corrections_guard' => 'e4e5c7af3e955ef0c31875efe62b87f4',
    ];

    /**
     * HR.R1-C35 WO-20 (HRC-4): the kept-access grant set's backstop -- its column, shape and pair CHECKs, and the guard
     * function's whole body (md5 of `prosrc`), INVOKER mode and pinned path. The trigger's binding and definition
     * are `hr_family_trigger_functions`'s (HR_FAMILY_TRIGGERS). A deliberate change updates the pin.
     */
    public const SEPARATION_RETAINED_GRANTS_GUARD_BODY = 'de19aa79178a939cb5c01d0f7416201c';

    /** constraint => md5(pg_get_constraintdef) */
    public const SEPARATION_RETAINED_GRANTS_CHECKS = [
        'employment_separation_cases_retained_grants_shape' => 'a0226983057006227c98ba6284950f98',
        'employment_separation_cases_retained_pair' => 'f803a530f4444d11b148c3e9aec17bcb',
    ];

    private function separationRetainedAccessCheck(): CheckResult
    {
        $missing = [];
        $column = DB::selectOne("select format_type(a.atttypid, a.atttypmod) as t from pg_attribute a
            where a.attrelid = 'public.employment_separation_cases'::regclass and a.attname = 'access_retained_grant_ids' and not a.attisdropped");
        if ($column === null || $column->t !== 'uuid[]') {
            $missing[] = 'access_retained_grant_ids';
        }
        // The CHECKs by table and whole definition, not only by name (delta review L-B).
        foreach (self::SEPARATION_RETAINED_GRANTS_CHECKS as $constraint => $definition) {
            if (! DB::selectOne("select exists (select 1 from pg_constraint where conname = ? and convalidated
                and conrelid = 'public.employment_separation_cases'::regclass and md5(pg_get_constraintdef(oid)) = ?) as x", [$constraint, $definition])->x) {
                $missing[] = $constraint;
            }
        }
        $fn = DB::select("select md5(prosrc) as h, prosecdef, coalesce(array_to_string(proconfig, '|'), '') as config
            from pg_proc where pronamespace = 'public'::regnamespace and proname = 'hr_separation_retained_grants_guard'");
        if (count($fn) !== 1 || $fn[0]->h !== self::SEPARATION_RETAINED_GRANTS_GUARD_BODY) {
            $missing[] = 'hr_separation_retained_grants_guard:body';
        } elseif ($fn[0]->prosecdef || $fn[0]->config !== 'search_path=pg_catalog, public, pg_temp') {
            $missing[] = 'hr_separation_retained_grants_guard:mode';
        }

        return CheckResult::of('hr_separation_retained_access_binding', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    /** HR.R1-C35 WO-21 (HRC-8): md5 of the guard's `prosrc` and of its CHECK's definition. */
    public const SEPARATION_CLEARANCE_BASIS_GUARD_BODY = '5f83023da127506b419b7d814750e648';

    public const SEPARATION_CLEARANCE_BASIS_CHECK = '7b3e291ec5780770dc59290e439c9be4';

    public const SEPARATION_CLEARANCE_ITEM_BASIS_GUARD_BODY = '2cc0f7a63172da71988b749d08a8341d';

    /**
     * HR.R1-C35 WO-21 (HRC-8): the clearance basis backstop -- both columns, the shape CHECK by table and whole
     * definition, and the guard's whole body, INVOKER mode and pinned path. The trigger's binding and definition
     * are `hr_family_trigger_functions`'s. A deliberate change updates the pins.
     */
    private function separationClearanceBasisCheck(): CheckResult
    {
        $missing = [];
        foreach (['clearance_staff_type' => 'character varying(20)', 'clearance_skipped_requirement_ids' => 'uuid[]'] as $column => $type) {
            $row = DB::selectOne("select format_type(a.atttypid, a.atttypmod) as t from pg_attribute a
                where a.attrelid = 'public.employment_separation_cases'::regclass and a.attname = ? and not a.attisdropped", [$column]);
            if ($row === null || $row->t !== $type) {
                $missing[] = $column;
            }
        }
        if (! DB::selectOne("select exists (select 1 from pg_constraint where conname = 'employment_separation_cases_clearance_basis_shape' and convalidated
            and conrelid = 'public.employment_separation_cases'::regclass and md5(pg_get_constraintdef(oid)) = ?) as x", [self::SEPARATION_CLEARANCE_BASIS_CHECK])->x) {
            $missing[] = 'employment_separation_cases_clearance_basis_shape';
        }
        foreach (['hr_separation_clearance_basis_guard' => self::SEPARATION_CLEARANCE_BASIS_GUARD_BODY, 'hr_separation_clearance_item_basis_guard' => self::SEPARATION_CLEARANCE_ITEM_BASIS_GUARD_BODY] as $function => $body) {
            $fn = DB::select("select md5(prosrc) as h, prosecdef, coalesce(array_to_string(proconfig, '|'), '') as config
                from pg_proc where pronamespace = 'public'::regnamespace and proname = ?", [$function]);
            if (count($fn) !== 1 || $fn[0]->h !== $body) {
                $missing[] = "{$function}:body";
            } elseif ($fn[0]->prosecdef || $fn[0]->config !== 'search_path=pg_catalog, public, pg_temp') {
                $missing[] = "{$function}:mode";
            }
        }

        return CheckResult::of('hr_separation_clearance_basis', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    private function knownSamePersonGuardsCheck(): CheckResult
    {
        $owner = (string) DB::selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'public.migrations'::regclass")->o;
        $missing = [];
        foreach (['employee_account_link_same_person_accounts' => 'p_school uuid, p_user uuid', 'employee_account_link_correction_same_person' => 'p_school uuid, p_first uuid, p_second uuid'] as $relation => $args) {
            $fn = DB::selectOne(
                "select p.prosecdef, p.provolatile, coalesce(array_to_string(p.proconfig, '|'), '') as config, pg_get_userbyid(p.proowner) as owner,
                        has_function_privilege(?, p.oid, 'EXECUTE') as runtime_exec,
                        exists (select 1 from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a where a.grantee = 0 and a.privilege_type = 'EXECUTE') as public_exec
                 from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = ? and pg_get_function_identity_arguments(p.oid) = ?",
                [self::RUNTIME_ROLE, $relation, $args],
            );
            if ($fn === null || $fn->prosecdef || $fn->provolatile !== 's' || $fn->config !== 'search_path=pg_catalog, public, pg_temp'
                || $fn->owner !== $owner || ! $fn->runtime_exec || $fn->public_exec) {
                $missing[] = $relation;
            }
        }
        foreach (self::KNOWN_SAME_PERSON_GUARDS as $trigger => [$table, $function, $relation, $definition]) {
            $row = DB::selectOne(
                "select t.tgenabled, p.proname, p.prosrc, md5(pg_get_triggerdef(t.oid)) as def from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
                 where not t.tgisinternal and c.relnamespace = 'public'::regnamespace and c.relname = ? and t.tgname = ?",
                [$table, $trigger],
            );
            if ($row === null || $row->tgenabled !== 'O' || $row->proname !== $function || ! str_contains((string) $row->prosrc, 'public.'.$relation.'(')) {
                $missing[] = $trigger;
            } elseif ($row->def !== $definition) {
                $missing[] = $trigger.':definition';
            }
        }

        foreach (self::KNOWN_SAME_PERSON_BODIES as $function => $hash) {
            // WO-17 review L-2: the body, and the mode and path `prosrc` does not carry (INVOKER, `pg_temp` last).
            $bodies = str_contains($function, '(')
                ? DB::select("select md5(prosrc) as h, prosecdef, coalesce(array_to_string(proconfig, '|'), '') as config
                    from pg_proc where oid = to_regprocedure(?)", ['public.'.$function])
                : DB::select("select md5(prosrc) as h, prosecdef, coalesce(array_to_string(proconfig, '|'), '') as config
                    from pg_proc where pronamespace = 'public'::regnamespace and proname = ?", [$function]);
            if (count($bodies) !== 1 || $bodies[0]->h !== $hash) {
                $missing[] = $function.':body';
            } elseif ($bodies[0]->prosecdef || $bodies[0]->config !== 'search_path=pg_catalog, public, pg_temp') {
                $missing[] = $function.':mode';
            }
        }

        return CheckResult::of('known_same_person_guards', $missing === [], $missing === [] ? '' : implode(', ', $missing));
    }

    /**
     * HR.R1-C7 (OD-HR-25): no link change without history, and the runtime role reads it only.
     */
    private function employeeAccountLinkCheck(): CheckResult
    {
        $triggers = (int) DB::selectOne("select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in ('trg_employees_record_account_link_insert', 'trg_employees_record_account_link_update', 'trg_employee_account_link_corrections_guard', 'trg_employee_account_link_corrections_apply')")->c;
        $definer = (int) DB::selectOne("select count(*) as c from pg_proc where pronamespace = 'public'::regnamespace and proname in ('employees_record_account_link', 'employee_account_link_corrections_apply') and prosecdef and proconfig is not null")->c;
        // HR.R1-C8: nothing but an approved correction voids a link, so no other way to do it may exist.
        $direct = (int) DB::selectOne("select count(*) as c from pg_proc where pronamespace = 'public'::regnamespace and proname = 'employee_account_link_void'")->c;
        $writes = (bool) DB::selectOne("select has_table_privilege('school_os_app', 'employee_account_links', 'INSERT') or has_table_privilege('school_os_app', 'employee_account_links', 'UPDATE') or has_table_privilege('school_os_app', 'employee_account_links', 'DELETE') or has_table_privilege('school_os_app', 'employee_account_link_corrections', 'DELETE') as w")->w;
        $guard = (string) DB::selectOne("select coalesce((select prosrc from pg_proc where pronamespace = 'public'::regnamespace and proname = 'employee_account_link_corrections_guard'), '') as src")->src;
        $pinned = str_contains($guard, 'pg_advisory_xact_lock') && str_contains($guard, 'known to be one person') && str_contains($guard, 'known to be the corrected account') && str_contains($guard, 'an approval binds the current history');

        return CheckResult::of('employee_account_links_guarded', $triggers === 4 && $definer === 2 && $direct === 0 && ! $writes && $pinned);
    }

    /**
     * HR.R1-C6 (PAY-18, PAY-19): a reversal cannot commit without its obligation, a review
     * stays independent, nobody decides on their own first-paid change, and a link change is
     * ordered against every decision. Every trigger enabled, and the deferred one still deferred.
     */
    private function payrollReconciliationCheck(): CheckResult
    {
        $enabled = (int) DB::selectOne(
            "select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in
             ('trg_payroll_reversal_reconciliations_guard', 'trg_payroll_run_postings_reconciliation_required', 'trg_payroll_reversal_reconciliation_reviews_guard',
              'trg_payroll_runs_self_beneficiary', 'trg_payroll_posting_attestations_self_beneficiary', 'trg_employees_user_link_payroll_lock')"
        )->c;
        $deferred = (int) DB::selectOne("select count(*) as c from pg_trigger where tgname = 'trg_payroll_run_postings_reconciliation_required' and tgdeferrable and tginitdeferred")->c;

        return CheckResult::of('payroll_reconciliation_guarded', $enabled === 6 && $deferred === 1);
    }

    private function payrollApprovalProvenanceCheck(): CheckResult
    {
        $guard = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'payroll_runs_approval_evidence_guard' and p.pronamespace = 'public'::regnamespace");
        $guarded = $guard !== null
            && str_contains($guard->src, 'payroll_calculation_compensation_fresh(')
            && str_contains($guard->src, 'payroll_run_first_paid_compensation(')
            && str_contains($guard->src, 'payroll_run_results_reconcile(')
            && str_contains($guard->src, 'compensation_sources_recorded')
            && str_contains($guard->src, 'pg_advisory_xact_lock_shared');
        // HR.R1-C3 (PAY-13): a reversed run, or one its author approved, never discharges the attribution.
        $firstPaid = DB::selectOne("select p.prosrc as src from pg_proc p where p.proname = 'payroll_run_first_paid_compensation' and p.pronamespace = 'public'::regnamespace");
        $guarded = $guarded && $firstPaid !== null && str_contains($firstPaid->src, "posting_kind = 'reversal'") && str_contains($firstPaid->src, 'o.approved_by_user_id IS DISTINCT FROM a.assigned_by_user_id') && str_contains($firstPaid->src, 'employee_account_links') && str_contains($firstPaid->src, 'voided_at IS NULL');
        $enabled = (int) DB::selectOne(
            "select count(*) as c from pg_trigger where not tgisinternal and tgenabled = 'O' and tgname in
             ('payroll_runs_approval_evidence_guard', 'trg_payroll_runs_posting_independence', 'trg_payroll_posting_attestations_guard', 'trg_payroll_runs_identity', 'trg_payroll_run_postings_reversal_lock', 'trg_employee_compensation_assignments_identity', 'trg_employee_compensation_assignments_payroll_lock', 'trg_payroll_run_results_reject_rewrite', 'trg_payroll_run_result_lines_reject_rewrite', 'trg_payroll_run_calculation_sources_guard')",
        )->c;

        $decided = (int) DB::selectOne("select count(*) as c from pg_constraint where conname = 'payroll_runs_decided_has_approver' and convalidated")->c;

        return CheckResult::of('payroll_approval_provenance_guarded', $guarded && $enabled === 10 && $decided === 1);
    }

    /**
     * SR.1 (ADR 0071 §11): the staff role catalogue's database foundation --
     * the runtime role cannot write the catalogue, a role cannot be deleted
     * out from under its grant history, role identity is immutable, the
     * retirement and grant-right columns exist, every new School-role grant
     * passes the grantor-coverage trigger (with its administrative-boundary
     * check), and the local/testing fixture seam exists only where it may.
     *
     * @return list<CheckResult>
     */
    private function roleCatalogueChecks(string $runtime): array
    {
        $writable = [];
        foreach (['roles', 'role_capabilities', 'capabilities'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                if (DB::selectOne('select has_table_privilege(?, ?, ?) as p', [$runtime, $table, $privilege])->p) {
                    $writable[] = "{$table}:{$privilege}";
                }
            }
        }
        $results = [CheckResult::of('role_catalogue_runtime_read_only', $writable === [], $writable === [] ? '' : count($writable).' grant(s)')];

        $fk = DB::selectOne("select confdeltype from pg_constraint where conname = 'membership_role_assignments_role_id_foreign'");
        $results[] = CheckResult::of('role_grant_history_restricted', $fk !== null && in_array($fk->confdeltype, ['r', 'a'], true));

        $triggers = DB::select(
            "select t.tgname, t.tgenabled = 'O' as enabled, p.prosrc as src from pg_trigger t join pg_proc p on p.oid = t.tgfoid
             where t.tgname in ('trg_roles_identity', 'trg_membership_role_assignments_grantor', 'trg_capabilities_grant_right')",
        );
        $byName = collect($triggers)->keyBy('tgname');
        $identity = $byName->get('trg_roles_identity');
        $grantor = $byName->get('trg_membership_role_assignments_grantor');
        $results[] = CheckResult::of('role_identity_immutable', $identity !== null && $identity->enabled && str_contains($identity->src, 'NEW.key IS DISTINCT FROM OLD.key'));
        $results[] = CheckResult::of('role_grantor_coverage_enforced', $grantor !== null && $grantor->enabled
            && str_contains($grantor->src, 'pg_has_role') && str_contains($grantor->src, 'retired_at') && str_contains($grantor->src, 'grant_right')
            && $byName->has('trg_capabilities_grant_right'));

        $columns = DB::selectOne(
            "select exists (select 1 from information_schema.columns where table_name = 'roles' and column_name = 'retired_at') as retired,
                    exists (select 1 from information_schema.columns where table_name = 'capabilities' and column_name = 'grant_right') as grant_right",
        );
        $results[] = CheckResult::of('role_lifecycle_columns', (bool) $columns->retired && (bool) $columns->grant_right);

        $seam = (bool) DB::selectOne("select exists (select 1 from pg_namespace where nspname = 'local_fixtures') as present")->present;
        $results[] = CheckResult::of('local_fixture_seam_absent_outside_development', ! $seam || app()->environment(['local', 'testing']));

        return $results;
    }

    /**
     * E21-RH.2 (ADR 0066 §3.1): the dedicated retention identity is narrow,
     * shares no role with the runtime or owner role, owns nothing, writes
     * nothing directly, executes exactly the approved destructive retention
     * functions -- and, where this process holds its credential, the
     * retention connection really authenticates as it.
     *
     * @return list<CheckResult>
     */
    /**
     * E21-RH.6 (ADR 0066 §14): the retention-delete hold boundary, the end of
     * runtime retention-only DELETE, and the eligibility-source guards.
     *
     * @return list<CheckResult>
     */
    private function retentionBoundaryChecks(string $runtime): array
    {
        $guarded = DB::select(
            "select c.relname from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
              where p.proname = 'retention_guard_retention_delete' and t.tgenabled = 'O' and c.relnamespace = 'public'::regnamespace",
        );
        $guardedTables = array_column($guarded, 'relname');
        $guardFn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config,
                (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_guard_retention_delete'");
        // E21-RH.7: every anchored table a retention path deletes from is guarded, and the guard enforces the recorded anchor.
        $mustGuard = [...self::RETENTION_DELETES, ...array_diff(array_keys(RetentionAnchors::TABLES), RetentionAnchors::INPUTS)];
        $guardBody = (string) DB::selectOne("select p.prosrc from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_guard_retention_delete'")?->prosrc;
        $results = [CheckResult::of('retention_deletes_guarded', $guardFn !== null && $guardFn->prosecdef && str_contains($guardFn->config, 'search_path=') && ! $guardFn->public_exec
            && str_contains($guardBody, 'retention_anchor') && array_diff($mustGuard, $guardedTables) === [])];

        $revoked = array_values(array_diff([...self::RETENTION_DELETES, ...self::RUNTIME_DELETES_REVOKED_EXTRA], self::RUNTIME_PRODUCT_DELETES));
        $stillDeletable = array_filter($revoked, fn (string $table) => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as p', [$runtime, 'public.'.$table, 'DELETE'])->p);
        $results[] = CheckResult::of('runtime_retention_deletes_revoked', $stillDeletable === [], $stillDeletable === [] ? '' : count($stillDeletable).' table(s)');

        $missing = array_filter(self::ELIGIBILITY_GUARDS, fn (string $trigger, string $table) => DB::selectOne(
            "select exists (select 1 from pg_trigger where tgrelid = ('public.'||?)::regclass and tgname = ? and tgenabled = 'O') as x", [$table, $trigger],
        )->x === false, ARRAY_FILTER_USE_BOTH);
        $results[] = CheckResult::of('retention_eligibility_guards', $missing === [], $missing === [] ? '' : count($missing).' missing or disabled');

        return [...$results, ...$this->retentionAnchorChecks()];
    }

    /**
     * E21-RH.7 (ADR 0066 §15): every retention-relevant table carries the
     * database-recorded anchor, stamped by the one enabled trigger with
     * exactly its tracked columns; every expiry function declares its
     * cutoff; and the security fences against rollback are recorded.
     *
     * @return list<CheckResult>
     */
    private function retentionAnchorChecks(): array
    {
        $fn = DB::selectOne("select p.oid, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, p.prosrc,
                (p.proacl is null or exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0 and a.privilege_type = 'EXECUTE')) as public_exec
             from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = 'retention_stamp_anchor'");
        $rows = DB::select(
            "select c.relname, a.attnotnull, format_type(a.atttypid, a.atttypmod) as type, t.tgenabled, t.tgfoid,
                    case when t.oid is null then null else pg_get_triggerdef(t.oid) end as def
               from pg_class c
               left join pg_attribute a on a.attrelid = c.oid and a.attname = ? and not a.attisdropped
               left join pg_trigger t on t.tgrelid = c.oid and t.tgname = ? and not t.tgisinternal
              where c.relnamespace = 'public'::regnamespace and c.relkind = 'r'",
            [RetentionAnchors::COLUMN, RetentionAnchors::TRIGGER],
        );
        $live = [];
        foreach ($rows as $row) {
            $live[$row->relname] = $row;
        }
        $broken = [];
        foreach (RetentionAnchors::TABLES as $table => $tracked) {
            $row = $live[$table] ?? null;
            if ($row === null || $row->attnotnull !== true || $row->type !== 'timestamp without time zone'
                || ! in_array($row->tgenabled, ['O', 'A'], true) || $fn === null || (int) $row->tgfoid !== (int) $fn->oid
                || ! str_ends_with((string) $row->def, 'retention_stamp_anchor('.implode(', ', array_map(fn (string $c) => "'{$c}'", $tracked)).')')) {
                $broken[] = $table;
            }
        }
        $results = [CheckResult::of('retention_anchors_recorded', $fn !== null && ! $fn->prosecdef && str_contains($fn->config, 'search_path=') && ! $fn->public_exec
            && str_contains($fn->prosrc, 'session_user') && $broken === [], $broken === [] ? '' : count($broken).' table(s)')];

        $undeclared = DB::select("select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_expire\\_%'
            and p.prosrc not like '%app.retention_anchor_cutoff%'");
        $results[] = CheckResult::of('retention_functions_declare_cutoff', $undeclared === [], $undeclared === [] ? '' : count($undeclared).' function(s)');

        $fenced = DB::table('migrations')->whereIn('migration', self::RETENTION_FENCES)->count();
        $results[] = CheckResult::of('retention_rollback_fences', $fenced === count(self::RETENTION_FENCES), $fenced === count(self::RETENTION_FENCES) ? '' : 'a fence is not recorded');

        return $results;
    }

    private function retentionIdentityChecks(): array
    {
        $role = self::RETENTION_ROLE;
        $attrs = DB::selectOne('select oid, rolcanlogin, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolinherit, rolreplication from pg_roles where rolname = ?', [$role]);
        if ($attrs === null) {
            return [CheckResult::of('retention_role_narrow', false, 'missing')];
        }

        $owner = DB::selectOne("select relowner::regrole::text as owner from pg_class where oid = 'public.employees'::regclass")->owner;
        $shared = DB::selectOne(
            'select exists (select 1 from pg_auth_members m where m.member = ? or m.roleid = ?) as any,
                    pg_has_role(?, ?, ?) or pg_has_role(?, ?, ?) or pg_has_role(?, ?, ?) as reach',
            [$attrs->oid, $attrs->oid, $role, $owner, 'MEMBER', $role, self::RUNTIME_ROLE, 'MEMBER', self::RUNTIME_ROLE, $role, 'MEMBER'],
        );
        $owns = DB::selectOne(
            'select (select count(*) from pg_class where relowner = ?) + (select count(*) from pg_proc where proowner = ?)
                  + (select count(*) from pg_namespace where nspowner = ?) as n',
            [$attrs->oid, $attrs->oid, $attrs->oid],
        )->n;
        $results = [CheckResult::of('retention_role_narrow', $attrs->rolcanlogin && ! $attrs->rolsuper && ! $attrs->rolbypassrls && ! $attrs->rolcreatedb
            && ! $attrs->rolcreaterole && ! $attrs->rolinherit && ! $attrs->rolreplication && ! $shared->any && ! $shared->reach && (int) $owns === 0)];

        // E21-RH.6: no write privilege except DELETE on exactly RETENTION_DELETES (never INSERT, UPDATE,
        // TRUNCATE, REFERENCES or TRIGGER; no column-level write).
        $deletes = array_column(DB::select(
            "select c.relname from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type = 'DELETE' order by 1",
            [$attrs->oid],
        ), 'relname');
        $approvedDeletes = self::RETENTION_DELETES;
        sort($approvedDeletes);
        $writes = DB::selectOne(
            "select (select count(*) from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type not in ('SELECT', 'DELETE'))
                  + (select count(*) from pg_attribute t cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type <> 'SELECT') as n",
            [$attrs->oid, $attrs->oid],
        )->n + ($deletes === $approvedDeletes ? 0 : 1);
        $results[] = CheckResult::of('retention_role_writes_exact', (int) $writes === 0, (int) $writes === 0 ? '' : $writes.' unexpected write grant(s)');

        // E21-RH.5: and its reads are exactly the approved columns (no table-level SELECT at all).
        $selects = [];
        foreach (DB::select(
            "select c.relname as tbl, t.attname as col from pg_attribute t join pg_class c on c.oid = t.attrelid
               cross join lateral aclexplode(t.attacl) a where a.grantee = ? and a.privilege_type = 'SELECT' and c.relnamespace = 'public'::regnamespace
              union all
             select c.relname, '*' from pg_class c cross join lateral aclexplode(c.relacl) a where a.grantee = ? and a.privilege_type = 'SELECT'",
            [$attrs->oid, $attrs->oid],
        ) as $row) {
            $selects[$row->tbl][] = $row->col;
        }
        ksort($selects);
        $selects = array_map(function (array $columns): array {
            sort($columns);

            return $columns;
        }, $selects);
        $approvedSelects = self::RETENTION_SELECTS;
        foreach ([...self::RETENTION_DELETES, ...self::RETENTION_TABLE_READS] as $table) {
            $approvedSelects[$table] = [...($approvedSelects[$table] ?? []), '*'];
        }
        $approvedSelects = array_map(function (array $columns): array {
            sort($columns);

            return $columns;
        }, $approvedSelects);
        ksort($approvedSelects);
        $results[] = CheckResult::of('retention_role_selects_exact', $selects === $approvedSelects, $selects === $approvedSelects ? '' : 'differs from the approved read set');

        // EXECUTE on exactly the approved destructive retention functions; never a legacy one.
        $executable = array_column(DB::select(
            "select p.proname from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname like 'retention\\_%'
                and has_function_privilege(?, p.oid, 'EXECUTE')",
            [$role],
        ), 'proname');
        sort($executable);
        $approved = [...self::PRIVILEGED_RETENTION_FUNCTIONS, ...self::STANDALONE_RETENTION_FUNCTIONS, ...self::UNIT_RETENTION_FUNCTIONS, ...self::RETENTION_READ_FUNCTIONS];
        sort($approved);
        $results[] = CheckResult::of('retention_role_functions_exact', $executable === $approved, $executable === $approved ? '' : count(array_diff($executable, $approved)).' unexpected, '.count(array_diff($approved, $executable)).' missing');

        $results[] = $this->authoritativeHoldsCheck($attrs->oid, (string) $owner);

        // Where this process holds the credential: the connection is really the retention login, unelevated.
        $username = config('database.connections.'.self::RETENTION_CONNECTION.'.username');
        if (! is_string($username) || trim($username) === '') {
            $results[] = new CheckResult('retention_connection_identity', CheckResult::EVIDENCE, 'not configured in this process');
        } else {
            try {
                $identity = DB::connection(self::RETENTION_CONNECTION)->selectOne(
                    'select session_user::text as login, r.rolsuper or r.rolbypassrls as elevated from pg_roles r where r.rolname = session_user',
                );
                $results[] = CheckResult::of('retention_connection_identity', $identity !== null && $identity->login === $role && ! $identity->elevated);
            } catch (Throwable) {
                $results[] = CheckResult::of('retention_connection_identity', false, 'unreachable');
            } finally {
                DB::purge(self::RETENTION_CONNECTION);
            }
        }

        return $results;
    }

    /**
     * E21-RH.3 (ADR 0066 §6): the authoritative hold store is owned by the
     * schema owner, granted to nobody (no PUBLIC, runtime or retention
     * privilege), its history guard is enabled, the writers and the assert
     * helper are owner-only invokers, and the retention identity's only hold
     * access is the read-only active-scopes definer.
     */
    private function authoritativeHoldsCheck(string $retentionOid, string $owner): CheckResult
    {
        $table = DB::selectOne(
            "select relowner::regrole::text as owner, exists (select 1 from aclexplode(relacl) a where a.grantee <> relowner) as granted,
                    (select count(*) from pg_trigger where tgrelid = c.oid and tgname in ('trg_retention_holds_guard', 'trg_retention_holds_no_truncate') and tgenabled = 'O') as guards
               from pg_class c where oid = to_regclass('public.retention_holds')",
        );
        if ($table === null) {
            return CheckResult::of('retention_holds_authoritative', false, 'missing');
        }

        $functions = collect(DB::select(
            "select p.proname, p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, pg_get_userbyid(p.proowner) as owner,
                    has_function_privilege(?, p.oid, 'EXECUTE') as runtime, has_function_privilege(?::oid, p.oid, 'EXECUTE') as retention,
                    exists (select 1 from aclexplode(p.proacl) a where a.grantee = 0) as public
               from pg_proc p where p.pronamespace = 'public'::regnamespace and p.proname = any (?::text[])",
            [self::RUNTIME_ROLE, $retentionOid, '{'.implode(',', [...self::HOLD_MAINTENANCE_FUNCTIONS, ...self::RETENTION_READ_FUNCTIONS]).'}'],
        ))->keyBy('proname');

        $ok = $table->owner === $owner && ! $table->granted && (int) $table->guards === 2
            && $functions->count() === count(self::HOLD_MAINTENANCE_FUNCTIONS) + count(self::RETENTION_READ_FUNCTIONS);
        foreach ($functions as $name => $f) {
            $read = in_array($name, self::RETENTION_READ_FUNCTIONS, true);
            $ok = $ok && $f->owner === $owner && str_contains($f->config, 'search_path=') && ! $f->public && ! $f->runtime
                && (bool) $f->prosecdef === $read && (bool) $f->retention === $read;
        }

        return CheckResult::of('retention_holds_authoritative', $ok);
    }
}
