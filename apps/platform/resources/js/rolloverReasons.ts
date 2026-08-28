// Translates the stable, machine-readable reason codes
// EnrollmentRolloverDryRunService/EnrollmentRolloverItemExecutionService
// persist into staff-facing text -- display only, never a different
// business interpretation than the server's own classification. The
// original machine code is always still available in the Item's own
// `validationReason` prop alongside this label.
const ROLLOVER_REASON_LABELS: Record<string, string> = {
    // Dry-run (EnrollmentRolloverDryRunService).
    multiple_source_candidates:
        'This Student has more than one possible source Enrollment -- resolve manually.',
    source_status_ineligible: 'The source Enrollment is not active or completed.',
    terminal_grade: 'No mapping exists for this Grade -- likely a terminal/graduating Grade.',
    undecided: 'No decision has been set for this Student yet.',
    missing_mapping: 'No Grade/Section mapping applies to this Student.',
    target_grade_mismatch: 'The decision (Promote/Repeat) does not match the configured mapping.',
    missing_target_section: 'No target Section is configured for this Student.',
    target_wrong_academic_year:
        'The configured target Section does not belong to the target Academic Year.',
    already_enrolled_conflict:
        'A different target-year Enrollment already exists for this Student.',
    already_enrolled_match:
        'This Student is already enrolled exactly as proposed -- no action needed.',
    roll_number_conflict_in_plan:
        'Another Student in this Plan is proposed for the same Section and Roll Number.',
    roll_number_conflict_existing:
        'Another Student already holds this Roll Number in the target Section.',
    invalid_roll_number: 'The Roll Number is missing or blank.',
    // Execution-time drift (EnrollmentRolloverItemExecutionService).
    source_changed_since_validation:
        'The source Enrollment changed since this Plan was last validated -- revalidate.',
    target_section_changed_since_validation:
        'The target Section changed since this Plan was last validated -- revalidate.',
    roll_number_conflict_detected_at_execution:
        'The Roll Number was claimed by another Student during execution -- revalidate.',
    existing_target_enrollment_conflict_at_execution:
        'A conflicting target Enrollment appeared during execution -- revalidate.',
    already_enrolled_match_no_longer_valid:
        'The previously-matching target Enrollment no longer exists -- revalidate.',
    // Phase 1G.2/1G.3: elective subject-rollover validation/execution.
    missing_subject_mapping:
        'This Student has an active elective with no subject mapping configured -- add one below.',
    legacy_source_anchor_ambiguous:
        "This elective cannot be confidently attributed to this Student's source Enrollment -- resolve manually.",
    elective_target_inactive: 'The mapped target elective is currently inactive.',
    elective_target_required:
        'The mapped target Offering is now a required subject -- it cannot be used as an elective target.',
    elective_target_context_mismatch:
        "The mapped target elective does not match this Student's target School/Year/Campus/Grade.",
    elective_target_group_conflict:
        'Two mapped target electives in this Plan belong to the same elective group -- resolve the mapping.',
    elective_target_existing_conflict:
        "The Student already has a different active elective in the mapped target's elective group.",
    elective_target_duplicate_mapping:
        'Two different source electives map to the same target elective -- resolve the mapping.',
    elective_already_enrolled_match:
        'The Student is already enrolled in the mapped target elective -- no action needed.',
    elective_explicitly_omitted:
        'This elective was explicitly marked Omit -- it will not be carried forward.',
};

export function rolloverReasonLabel(reason: string | null): string | null {
    if (reason === null) return null;

    return ROLLOVER_REASON_LABELS[reason] ?? reason;
}
