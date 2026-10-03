/**
 * HRX.2 (ADR 0065 §23) — shared Leave labels for the administration pages.
 * Every value is from a closed server-side list; there is no free text and
 * nothing health-related. Units are integer half-days (2 = one full day).
 */
import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** The command refusal of the last Leave form post (the server's `leave` error), if any. */
export function useLeaveError(): ComputedRef<string | null> {
    const page = usePage();
    return computed(() => (page.props.errors as Record<string, string | undefined>).leave ?? null);
}

export type DayPortion = 'full' | 'first_half' | 'second_half';

export const portionLabels: Record<string, string> = {
    full: 'Full day',
    first_half: 'First half',
    second_half: 'Second half',
    off: 'Off',
};

export const requestStatusLabels: Record<string, string> = {
    submitted: 'Submitted',
    approved: 'Approved',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    cancelled: 'Cancelled',
};

export const reasonLabels: Record<string, string> = {
    personal: 'Personal',
    family: 'Family',
    official_duty: 'Official duty',
    other: 'Other',
    staffing_need: 'Staffing need',
    policy_not_met: 'Policy not met',
    duplicate_request: 'Duplicate request',
    entered_in_error: 'Entered in error',
    plans_changed: 'Plans changed',
    administrative_correction: 'Administrative correction',
    entitlement_change: 'Entitlement change',
    allocation_correction: 'Allocation correction',
};

export const ledgerKindLabels: Record<string, string> = {
    allocation: 'Allocation',
    adjustment: 'Adjustment',
    consumption: 'Consumption',
    reversal: 'Reversal',
    carry_forward_in: 'Carried in',
    carry_forward_out: 'Carried out',
    expiry: 'Lapsed',
};

export const blockerLabels: Record<string, string> = {
    LEAVE_YEAR_ALREADY_CLOSED: 'This leave year is already closed.',
    LEAVE_YEAR_NOT_ENDED: 'The leave year has not ended yet.',
    LEAVE_YEAR_CLOSE_ORDER: 'Close the previous leave year first.',
    LEAVE_NEXT_YEAR_NOT_OPEN: 'Open the next leave year first.',
    LEAVE_YEAR_CLOSE_PENDING_REQUESTS:
        'Decide or withdraw every submitted request in this year first.',
};

/** "6 units (3 days)" — always exact, never a decimal. */
export function units(value: number): string {
    const days = Math.floor(value / 2);
    const half = value % 2 === 1;
    const dayText = days === 0 ? '' : `${days} day${days === 1 ? '' : 's'}`;
    return `${value} unit${value === 1 ? '' : 's'} (${[dayText, half ? 'half a day' : ''].filter(Boolean).join(' and ') || '0 days'})`;
}

export interface EmployeeLabel {
    employmentRecordId: string;
    employeeNumber: string | null;
    fullName: string | null;
    status: string;
}

export function employeeName(employees: Record<string, EmployeeLabel>, id: string): string {
    const e = employees[id];
    return e ? `${e.fullName ?? '—'} (${e.employeeNumber ?? '—'})` : 'Unknown employee';
}

export interface LeaveTypeRow {
    id: string;
    code: string;
    name: string;
    isPaid?: boolean;
    tracksBalance?: boolean;
    allowsHalfDay?: boolean;
    status?: string;
}

export function typeName(types: LeaveTypeRow[], id: string): string {
    const t = types.find((x) => x.id === id);
    return t ? `${t.name} (${t.code})` : '—';
}
