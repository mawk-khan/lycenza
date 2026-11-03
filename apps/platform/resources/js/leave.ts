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

/** HR.9 (ADR 0065 §28) — one policy version as the server presents it. */
export interface EligibilityRuleRow {
    id: string | null;
    staffType: string | null;
    employeeCategoryId: string | null;
    departmentId: string | null;
}

export interface LeavePolicyRow {
    id: string;
    leaveTypeId: string;
    name: string;
    annualAllocationUnits: number;
    carryForwardAllowed: boolean;
    carryForwardCapUnits: number | null;
    carryForwardExpiryDays: number | null;
    status: string;
    supersedesPolicyId: string | null;
    minNoticeDays: number | null;
    maxBackdateDays: number | null;
    maxUnitsPerRequest: number | null;
    eligibilityRules: EligibilityRuleRow[];
    createdAt: string | null;
    retiredAt: string | null;
}

export interface Option {
    value: string;
    label: string;
}

export interface ReferenceOption {
    id: string;
    code: string;
    name: string;
}

/** HR.9 — one employment's eligibility answer (LeaveEligibilityRow::toArray()). */
export interface EligibilityRow {
    employmentRecordId: string;
    employeeId: string;
    employeeNumber: string | null;
    fullName: string | null;
    employmentStatus: string;
    facts: {
        staffType: string | null;
        employeeCategoryId: string | null;
        departmentId: string | null;
    };
    outcome: string;
    policyId: string | null;
    ruleId: string | null;
    matchingPolicyIds: string[];
    assignmentId: string | null;
    assignedPolicyId: string | null;
    assignedMatchesRules: boolean | null;
    assignmentEndsOn: string | null;
}

export interface EligibilityResult {
    leaveTypeId: string;
    date: string;
    versions: {
        policyId: string;
        name: string;
        annualAllocationUnits: number;
        rules: EligibilityRuleRow[];
    }[];
    rows: EligibilityRow[];
    counts: Record<string, number>;
    overlappingPolicyId?: string | null;
}

export const outcomeLabels: Record<string, string> = {
    assigned: 'Assigned',
    eligible: 'Eligible',
    ambiguous: 'Ambiguous',
    no_matching_rule: 'No matching rule',
    employment_not_eligible: 'Employment not eligible',
    own_employment: 'Your own employment',
};

export const staffTypeLabels: Record<string, string> = {
    teaching: 'Teaching',
    non_teaching: 'Non-teaching',
    outsourced: 'Outsourced',
};

/** A rule as words: "Teaching · Category X · any Department"; an all-any rule is "Every employment". */
export function ruleText(
    rule: EligibilityRuleRow,
    categories: Record<string, string>,
    departments: Record<string, string>,
): string {
    const parts = [
        rule.staffType === null ? null : (staffTypeLabels[rule.staffType] ?? rule.staffType),
        rule.employeeCategoryId === null
            ? null
            : `Category: ${categories[rule.employeeCategoryId] ?? 'unknown'}`,
        rule.departmentId === null
            ? null
            : `Department: ${departments[rule.departmentId] ?? 'unknown'}`,
    ].filter((p): p is string => p !== null);
    return parts.length === 0
        ? 'Every employment (any staff type, category, Department)'
        : parts.join(' · ');
}

/** Request-timing rules as words; null means the version sets no such rule. */
export function timingText(p: LeavePolicyRow): string {
    const parts = [
        p.minNoticeDays === null ? null : `${p.minNoticeDays} calendar day(s) notice`,
        p.maxBackdateDays === null
            ? null
            : p.maxBackdateDays === 0
              ? 'no past dates'
              : `past dates up to ${p.maxBackdateDays} calendar day(s) back`,
        p.maxUnitsPerRequest === null ? null : `at most ${units(p.maxUnitsPerRequest)} per request`,
    ].filter((x): x is string => x !== null);
    return parts.length === 0 ? 'no request-timing rules' : parts.join(' · ');
}
