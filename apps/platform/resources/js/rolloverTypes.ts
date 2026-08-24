// Shared literal-union types for the rollover status vocabularies --
// mirrors the backend's fixed enums exactly (see rolloverStatuses.ts
// for the paired display-label lists). Kept as types only (no runtime
// code) so every rollover Vue page/component types `status`/
// `validationResult`/`executionStatus` props consistently, matching
// what StatusBadge.vue itself accepts.
export type RolloverPlanStatus =
    'draft' | 'validated' | 'executing' | 'completed' | 'completed_with_errors' | 'cancelled';

export type RolloverValidationResult =
    'ready' | 'excluded' | 'already_enrolled' | 'review' | 'blocked';

export type RolloverExecutionStatus = 'succeeded' | 'reconciled' | 'skipped' | 'failed';

export type RolloverDecision = 'undecided' | 'promote' | 'repeat' | 'exclude' | 'manual_review';

export type RolloverRollNumberStrategy = 'explicit' | 'preserve_source';
