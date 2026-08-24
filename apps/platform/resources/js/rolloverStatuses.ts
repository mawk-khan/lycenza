// Mirrors the backend's fixed enum (enrollment_rollover_plans.status --
// App\Domain\Students\Infrastructure\EnrollmentRolloverPlan) -- display
// label metadata only, not a parallel source of truth: any value
// submitted still goes through the same Rule::in() validation
// server-side regardless of what this list offers.
export const ROLLOVER_PLAN_STATUSES: Array<{ value: string; label: string }> = [
    { value: 'draft', label: 'Draft' },
    { value: 'validated', label: 'Validated' },
    { value: 'executing', label: 'Executing' },
    { value: 'completed', label: 'Completed' },
    { value: 'completed_with_errors', label: 'Completed with errors' },
    { value: 'cancelled', label: 'Cancelled' },
];

// Mirrors enrollment_rollover_items.validation_result.
export const ROLLOVER_VALIDATION_RESULTS: Array<{ value: string; label: string }> = [
    { value: 'ready', label: 'Ready' },
    { value: 'excluded', label: 'Excluded' },
    { value: 'already_enrolled', label: 'Already enrolled' },
    { value: 'review', label: 'Needs review' },
    { value: 'blocked', label: 'Blocked' },
];

// Mirrors enrollment_rollover_items.execution_status.
export const ROLLOVER_EXECUTION_STATUSES: Array<{ value: string; label: string }> = [
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'reconciled', label: 'Reconciled' },
    { value: 'skipped', label: 'Skipped' },
    { value: 'failed', label: 'Failed' },
];

// Mirrors enrollment_rollover_items.decision -- terminology intentionally
// stays neutral/administrative (Repeat/Exclude never imply a Student
// outcome like "detained"/"failed"/"withdrawn").
export const ROLLOVER_ITEM_DECISIONS: Array<{ value: string; label: string }> = [
    { value: 'undecided', label: 'Undecided' },
    { value: 'promote', label: 'Promote' },
    { value: 'repeat', label: 'Repeat (same Grade)' },
    { value: 'exclude', label: 'Exclude from this Plan' },
    { value: 'manual_review', label: 'Needs manual review' },
];

// Mirrors enrollment_rollover_items.roll_number_strategy -- exactly
// these two values exist; no auto-generation strategy exists anywhere
// in the domain.
export const ROLLOVER_ROLL_NUMBER_STRATEGIES: Array<{ value: string; label: string }> = [
    { value: 'preserve_source', label: 'Keep current Roll Number' },
    { value: 'explicit', label: 'Set a new Roll Number' },
];
