// The closed Student lifecycle reason codes (StudentLifecycleReason::CODES,
// ORG.8E) and their labels -- shared by the Student page and (SIS.EXP-2) the
// movement register. A code without a label falls back to the code.
export const LIFECYCLE_REASON_LABELS: Record<string, string> = {
    left_school: 'Left the School',
    transferred_out: 'Transferred to another school',
    completed_studies: 'Completed studies',
    duplicate_record: 'Duplicate record',
    entered_in_error: 'Entered in error',
    returned_to_school: 'Returned to the School',
    deactivated_in_error: 'Deactivated in error',
    guardian_request: 'Guardian request',
    did_not_join: 'Did not join',
    duplicate_placement: 'Duplicate placement',
};
