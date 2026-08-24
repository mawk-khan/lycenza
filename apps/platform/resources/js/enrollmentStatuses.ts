// Mirrors the backend's fixed enum (student_enrollments.status --
// App\Domain\Students\Infrastructure\StudentEnrollment) -- this is
// display-label metadata only, not a parallel source of truth: any
// value submitted still goes through the same Rule::in() validation
// server-side regardless of what this list offers.
export const ENROLLMENT_STATUSES: Array<{ value: string; label: string }> = [
    { value: 'active', label: 'Active' },
    { value: 'completed', label: 'Completed' },
    { value: 'withdrawn', label: 'Withdrawn' },
    { value: 'transferred', label: 'Transferred' },
    { value: 'cancelled', label: 'Cancelled' },
];
