/**
 * HRX.3 (ADR 0065 §24) — shared Staff Attendance labels for the
 * administration pages. Only `present` / `absent` are ever stored per half;
 * leave, holidays and weekly offs are derived and shown as overlays. There
 * is no free text, no clock time and nothing health-related.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** The command refusal of the last Staff Attendance form post (the server's `attendance` error), if any. */
export function useAttendanceError(): ComputedRef<string | null> {
    const page = usePage();
    return computed(
        () => (page.props.errors as Record<string, string | undefined>).attendance ?? null,
    );
}

export type HalfStatus = 'present' | 'absent' | null;

export interface HalfView {
    state: 'present' | 'absent' | 'leave' | 'holiday' | 'off_day' | 'unrecorded';
    recorded: HalfStatus;
    calendar: 'working' | 'holiday' | 'off' | null;
    onLeave: boolean;
    leave: { leaveRequestId: string; leaveTypeName: string } | null;
}

export interface AttendanceDay {
    date: string;
    record: { id: string; version: number } | null;
    firstHalf: HalfView;
    secondHalf: HalfView;
    summary: string;
}

export interface EmploymentLabel {
    employmentRecordId: string;
    employeeId: string;
    employeeNumber: string | null;
    fullName: string | null;
    status: string;
    recordable: boolean;
}

export interface Correction {
    id: string;
    fromVersion: number;
    toVersion: number;
    before: { firstHalf: HalfStatus; secondHalf: HalfStatus };
    after: { firstHalf: HalfStatus; secondHalf: HalfStatus };
    reasonCode: string;
    correctedAt: string;
}

export const stateLabels: Record<string, string> = {
    present: 'Present',
    absent: 'Absent',
    leave: 'On leave',
    holiday: 'Holiday',
    off_day: 'Off',
    unrecorded: 'Not recorded',
};

export const summaryLabels: Record<string, string> = {
    present: 'Present',
    absent: 'Absent',
    half_day_absent: 'Half-day absent',
    on_leave: 'On leave',
    holiday: 'Holiday',
    off_day: 'Off',
    unrecorded: 'Not recorded',
    partially_recorded: 'Partly recorded',
    mixed: 'Leave and attendance',
};

export const reasonLabels: Record<string, string> = {
    entered_in_error: 'Entered in error',
    late_information: 'Information received later',
    administrative_review: 'Administrative review',
    other: 'Other',
};

/** "—" for no evidence; otherwise the stored value's label. */
export function halfStatusLabel(value: HalfStatus): string {
    return value === null ? '—' : (stateLabels[value] ?? value);
}

/** The effective state, plus what lies underneath when it differs (evidence is never hidden). */
export function halfLabel(half: HalfView): string {
    let label = stateLabels[half.state] ?? half.state;
    if (half.state === 'leave' && half.leave) {
        label += ` · ${half.leave.leaveTypeName}`;
    }
    if (half.state === 'leave' && half.recorded !== null) {
        label += ` (recorded ${halfStatusLabel(half.recorded).toLowerCase()})`;
    }
    if (half.recorded !== null && half.calendar !== null && half.calendar !== 'working') {
        label += ` (now ${half.calendar === 'off' ? 'off' : 'a holiday'})`;
    }
    return label;
}

/** A half can take NEW evidence only on working time without approved leave. */
export function halfOpen(half: HalfView): boolean {
    return half.calendar === 'working' && !half.onLeave;
}

export function employmentName(e: EmploymentLabel): string {
    return `${e.fullName ?? '—'} (${e.employeeNumber ?? '—'})`;
}
