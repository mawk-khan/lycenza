// V11.5: the Data Health severity and kind vocabulary (ADR 0075 section 10),
// shared by the overview and the finding list.
export type Severity = 'blocking' | 'action_required' | 'review' | 'advisory';
export type Kind = 'invalid' | 'inconsistent' | 'incomplete' | 'review_recommended';

export interface CheckDefinition {
    code: string;
    area: string;
    title: string;
    condition: string;
    whyItMatters: string;
    severity: Severity;
    kind: Kind;
    repair: string;
    automaticRepair: boolean;
}

export const severityOrder: Severity[] = ['blocking', 'action_required', 'review', 'advisory'];

export const severityLabels: Record<Severity, string> = {
    blocking: 'Blocking',
    action_required: 'Action required',
    review: 'Review',
    advisory: 'Advisory',
};

export const severityHelp: Record<Severity, string> = {
    blocking: 'Day-to-day work cannot go ahead until this is fixed.',
    action_required: 'Records disagree with each other and should be corrected.',
    review: 'Probably unintended; someone should look and decide.',
    advisory: 'Optional information that would help, not a fault.',
};

export const severityClasses: Record<Severity, string> = {
    // DS-4h: a severity is a status family (OD-DS-1), the strongest mono one.
    blocking: 'bg-accent-900 text-ground ring-accent-900',
    action_required: 'bg-accent-100 text-ink ring-accent-300',
    review: 'bg-accent-100 text-accent-strong ring-accent-300',
    advisory: 'bg-graphite-200 text-ink ring-line',
};

export const kindLabels: Record<Kind, string> = {
    invalid: 'Breaks a rule',
    inconsistent: 'Records disagree',
    incomplete: 'Required setup missing',
    review_recommended: 'Worth reviewing',
};
