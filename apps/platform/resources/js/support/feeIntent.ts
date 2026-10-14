// SWC.3 (ADR 0073 section 19): display labels for the OPF fee-intent
// vocabulary recorded by the backend (TransportFeeSelection,
// HostelFeeSelection, AdmissionFeeSelection, FeeSourceSelectionResult).
// Labels only -- no rule, no amount, no decision is made in the browser.

export interface FeeHeadLabel {
    id: string;
    code: string;
    name: string;
    active?: boolean;
}

export interface FeeIntentRow {
    academicYear: string | null;
    feeHead: FeeHeadLabel | null;
    mappingScope: string | null;
    linkReason: string;
    selectionOutcome: string;
    recordedAt: string | null;
}

const outcomes: Record<string, string> = {
    created: 'Optional fee selected',
    reused: 'Already selected (reused)',
};

const reasons: Record<string, string> = {
    assignment: 'Transport assignment started',
    residency: 'Residency started',
    carry_forward: 'Carried forward',
};

const scopes: Record<string, string> = {
    room: 'Room fee head',
    hostel: 'Hostel fee head',
};

export const outcomeLabel = (value: string): string => outcomes[value] ?? value;
export const reasonLabel = (value: string): string => reasons[value] ?? value;
export const scopeLabel = (value: string | null): string => (value ? (scopes[value] ?? value) : '');
export const feeHeadText = (head: FeeHeadLabel | null): string =>
    head === null
        ? 'No fee head'
        : `${head.name} (${head.code})${head.active === false ? ' — inactive' : ''}`;
